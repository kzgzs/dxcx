<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 微信模板/订阅消息发送中枢（批次7-1 任务209）
 *
 * 消费统一发送队列 wechat_send_tmpl_data（对标参考系统 Wechat::createSendTmplData + 定时消费）：
 * - 队列行 status：0 待发送 / 1 已发送 / 2 发送失败（errlog 留痕）
 * - 幂等：事务内行锁 + status=0 条件更新认领，重复消费直接跳过
 * - 平台路由：type=sendwxtmpl|sendhtwxtmpl|wxtmpl → 小程序 wx_tmplset（_new 为订阅消息模板ID）
 *   走 /cgi-bin/message/subscribe/send；其余默认公众号 mp_tmplset（tmpl_content_new 非空且
 *   mp_tmplset_new 已配模板ID 时走新版模板），走 /cgi-bin/message/template/send
 * - 跳转链接：mp_tmplset(.tmpl_message_link)=wx 时模板消息挂 miniprogram{appid,pagepath}，
 *   否则 url=完整落地页；wx 订阅消息 page=m_url（小程序页面路径）
 * - mock 兼容：OPEN_PLATFORM mock 下直出成功并落 sendlog，不调微信接口
 * - token：BindingTokenService 双模式（authtype=1 开放平台代授权 / authtype=0 cgi-bin/token）
 */
class WechatTmplService
{
    /** 小程序订阅消息队列类型标记 */
    private const WX_TYPES = ['sendwxtmpl', 'sendhtwxtmpl', 'wxtmpl', 'subscribe'];

    /** 队列行状态 */
    public const STATUS_PENDING = 0;
    public const STATUS_SENT    = 1;
    public const STATUS_FAILED  = 2;

    /**
     * tmpl_name 别名 → mp_tmplset/wx_tmplset 字段名
     * （生产者可直接写字段名如 tmpl_fenxiaosuccess，也可写别名如 levelup）
     *
     * @var array<string, string>
     */
    private const TMPL_ALIASES = [
        'levelup'        => 'tmpl_uplv',
        'uplv'           => 'tmpl_uplv',
        'fenxiao'        => 'tmpl_fenxiaosuccess',
        'moneychange'    => 'tmpl_moneychange',
        'orderconfirm'   => 'tmpl_orderconfirm',
        'orderpay'       => 'tmpl_orderpay',
        'orderfahuo'     => 'tmpl_orderfahuo',
        'ordershouhuo'   => 'tmpl_ordershouhuo',
        'ordertui'       => 'tmpl_ordertui',
        'tuisuccess'     => 'tmpl_tuisuccess',
        'tixiansuccess'  => 'tmpl_tixiansuccess',
        'tixianerror'    => 'tmpl_tixianerror',
        'collagesuccess' => 'tmpl_collagesuccess',
        'kehuzixun'      => 'tmpl_kehuzixun',
        'shenhe'         => 'tmpl_shenhe',
        'prize'          => 'tmpl_prize',
        'fenhong'        => 'tmpl_fenhong',
        'coupon_expire'  => 'tmpl_coupon_expire',
        'booking'        => 'tmpl_restaurant_booking',
    ];

    public function __construct(
        protected OpenPlatformService $open,
        protected WxApiClient $api,
        protected BindingTokenService $bindingToken,
    ) {
    }

    /* ==================== 队列消费 ==================== */

    /**
     * 消费待发送队列（单次上限防长时占用，由 schedule:run 每分钟驱动）
     *
     * @param int $limit 单次最多处理行数（1~500）
     * @return array{scanned: int, sent: int, failed: int, skipped: int}
     */
    public function consumeQueue(int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        $rows  = Db::name('wechat_send_tmpl_data')
            ->where('status', self::STATUS_PENDING)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $stats = ['scanned' => count($rows), 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $rs = $this->sendRow((array) $row);

            if (1 === (int) ($rs['status'] ?? 0)) {
                ++$stats['sent'];
            } elseif ('skipped' === ($rs['tag'] ?? '')) {
                ++$stats['skipped'];
            } else {
                ++$stats['failed'];
            }
        }

        return $stats;
    }

    /**
     * 发送单行队列（幂等认领 + 失败留痕）
     *
     * @param array<string, mixed> $row wechat_send_tmpl_data 行
     * @return array{status: int, msg: string, tag?: string}
     */
    public function sendRow(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 0, 'msg' => '队列行无效'];
        }

        // 幂等认领：事务内行锁 + status=0 条件更新（并发消费者只有一方能拿到）
        $claimed = Db::transaction(function () use ($id): int {
            $lock = Db::name('wechat_send_tmpl_data')
                ->where('id', $id)
                ->where('status', self::STATUS_PENDING)
                ->lock(true)
                ->find();

            if (empty($lock)) {
                return 0;
            }

            return Db::name('wechat_send_tmpl_data')
                ->where('id', $id)
                ->where('status', self::STATUS_PENDING)
                ->update(['status' => self::STATUS_SENT, 'updatetime' => time()]);
        });

        if (!$claimed) {
            return ['status' => 0, 'msg' => '已发送或已被认领，幂等跳过', 'tag' => 'skipped'];
        }

        try {
            $rs = $this->dispatch($row);
            $this->writeSendlog($row, $rs);

            return ['status' => 1, 'msg' => '发送成功'];
        } catch (\Throwable $e) {
            Db::name('wechat_send_tmpl_data')
                ->where('id', $id)
                ->update(['status' => self::STATUS_FAILED, 'updatetime' => time()]);
            $this->writeErrlog($row, $e->getMessage());

            return ['status' => 0, 'msg' => $e->getMessage()];
        }
    }

    /* ==================== 生产者便捷入队 ==================== */

    /**
     * 写入待发送队列（对标参考系统 Wechat::createSendTmplData，供业务侧统一入队）
     *
     * @param array|string $content    模板数据（数组）或纯文本（自动包 first/remark）
     * @param array|string $contentNew 订阅/新版消息数据（可空）
     * @return int 队列行 id
     */
    public function enqueue(int $aid, int $mid, string $tmplName, array|string $content, string $mUrl = '', array|string $contentNew = '', string $type = '', int $bid = 0, int $mdid = 0): int
    {
        return (int) Db::name('wechat_send_tmpl_data')->insertGetId([
            'aid'              => $aid,
            'bid'              => $bid,
            'mid'              => $mid,
            'tmpl_name'        => $tmplName,
            'tmpl_content'     => is_array($content) ? (string) json_encode($content, JSON_UNESCAPED_UNICODE) : $content,
            'm_url'            => $mUrl,
            'mdid'             => $mdid,
            'tmpl_content_new' => is_array($contentNew) ? (string) json_encode($contentNew, JSON_UNESCAPED_UNICODE) : $contentNew,
            'status'           => self::STATUS_PENDING,
            'type'             => $type,
            'createtime'       => time(),
            'updatetime'       => time(),
        ]);
    }

    /* ==================== 分发（平台路由 + 模板解析 + 接口调用） ==================== */

    /**
     * 按队列行分发发送（成功返回含 template_id/openid/url 的结果数组，失败抛异常）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed> {platform, template_id, openid, url, mock}
     */
    protected function dispatch(array $row): array
    {
        $aid      = (int) ($row['aid'] ?? 0);
        $mid      = $row['mid'] ?? 0;
        $platform = $this->platformOfRow((string) ($row['type'] ?? ''));
        $field    = $this->tmplField((string) ($row['tmpl_name'] ?? ''));

        if ('' === $field) {
            throw new BusinessException('tmpl_name 为空，无法定位模板字段');
        }

        return 'wx' === $platform
            ? $this->sendWxSubscribe($aid, $mid, $field, $row)
            : $this->sendMpTemplate($aid, $mid, $field, $row);
    }

    /**
     * 公众号模板消息（message/template/send；_new 组同样走该接口）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function sendMpTemplate(int $aid, $mid, string $field, array $row): array
    {
        $openid = $this->openidOfMember($aid, $mid, 'mpopenid');

        $set    = Db::name('mp_tmplset')->where('aid', $aid)->find();
        $setNew = Db::name('mp_tmplset_new')->where('aid', $aid)->find();

        $contentNew = $this->parseContent((string) ($row['tmpl_content_new'] ?? ''));
        $tmplIdNew  = trim((string) ($setNew[$field] ?? ''));
        $useNew     = !empty($contentNew) && '' !== $tmplIdNew;

        $tmplId = $useNew ? $tmplIdNew : trim((string) ($set[$field] ?? ''));
        if ('' === $tmplId) {
            throw new BusinessException('未配置模板ID：' . $field);
        }

        $messageLink = (string) (($useNew ? $setNew : $set)['tmpl_message_link'] ?? 'mp');
        $tourl       = trim((string) ($row['m_url'] ?? ''));

        // 落地页拼装：tmpl_message_link=wx → miniprogram{appid,pagepath}；mp → 完整 url
        $url         = '';
        $miniprogram = [];
        if ('wx' === $messageLink) {
            $wxAppid = (string) ScopeService::query('admin_setapp_wx', $aid)->value('appid');
            if ('' !== $wxAppid) {
                $miniprogram = [
                    'appid'    => $wxAppid,
                    'pagepath' => '' !== $tourl ? $tourl : 'pages/index/index',
                ];
            }
        } else {
            $url = $this->fullUrl($tourl);
        }

        $content = $useNew ? $contentNew : $this->parseContent((string) ($row['tmpl_content'] ?? ''));
        $member  = $this->memberOf($aid, (int) $mid);
        $data    = $this->formatTemplateData($content, $useNew, (string) ($member['nickname'] ?? ''));

        // mock 直出成功
        if ($this->open->isMock()) {
            return ['platform' => 'mp', 'template_id' => $tmplId, 'openid' => $openid, 'url' => $url, 'mock' => true, 'data' => $data];
        }

        $token   = $this->bindingToken->accessToken('mp', $aid);
        $payload = [
            'touser'      => $openid,
            'template_id' => $tmplId,
            'data'        => $data,
        ];
        if ('' !== $url) {
            $payload['url'] = $url;
        }
        if (!empty($miniprogram)) {
            $payload['miniprogram'] = $miniprogram;
        }

        $rs = $this->api->templateSend($token, $payload);
        $this->assertOk($rs, $token, 'mp', $aid, fn (string $t): array => $this->api->templateSend($t, $payload));

        return ['platform' => 'mp', 'template_id' => $tmplId, 'openid' => $openid, 'url' => $url, 'mock' => false, 'data' => $data];
    }

    /**
     * 小程序订阅消息（subscribe/send；wx_tmplset 的 _new 字段为订阅消息模板ID，优先使用）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function sendWxSubscribe(int $aid, $mid, string $field, array $row): array
    {
        $openid = $this->openidOfMember($aid, $mid, 'wxopenid');

        $set    = Db::name('wx_tmplset')->where('aid', $aid)->find();
        $newKey = $field . '_new';
        $tmplId = trim((string) ($set[$newKey] ?? ''));
        $useNew = '' !== $tmplId;
        if (!$useNew) {
            $tmplId = trim((string) ($set[$field] ?? ''));
        }
        if ('' === $tmplId) {
            throw new BusinessException('未配置订阅消息模板ID：' . $newKey);
        }

        $content = $useNew
            ? ($this->parseContent((string) ($row['tmpl_content_new'] ?? '')) ?: $this->parseContent((string) ($row['tmpl_content'] ?? '')))
            : $this->parseContent((string) ($row['tmpl_content'] ?? ''));

        $page = trim((string) ($row['m_url'] ?? ''));
        $data = $this->formatSubscribeData($content);

        if ($this->open->isMock()) {
            return ['platform' => 'wx', 'template_id' => $tmplId, 'openid' => $openid, 'url' => $page, 'mock' => true, 'data' => $data];
        }

        $token   = $this->bindingToken->accessToken('wx', $aid);
        $payload = [
            'touser'      => $openid,
            'template_id' => $tmplId,
            'data'        => $data,
        ];
        if ('' !== $page) {
            $payload['page'] = $page;
        }

        $rs = $this->api->subscribeSend($token, $payload);
        $this->assertOk($rs, $token, 'wx', $aid, fn (string $t): array => $this->api->subscribeSend($t, $payload));

        return ['platform' => 'wx', 'template_id' => $tmplId, 'openid' => $openid, 'url' => $page, 'mock' => false, 'data' => $data];
    }

    /**
     * errcode 断言（40001/42001/40014 强刷 token 重试一次）
     *
     * @param array<string, mixed>      $rs
     * @param callable(string): array   $retry
     */
    protected function assertOk(array $rs, string $token, string $platform, int $aid, callable $retry): void
    {
        $errcode = (int) ($rs['errcode'] ?? 0);

        if (in_array($errcode, [40001, 42001, 40014], true)) {
            $token = $this->bindingToken->accessToken($platform, $aid);
            $rs    = $retry($token);
            $errcode = (int) ($rs['errcode'] ?? 0);
        }

        if (0 !== $errcode) {
            throw new BusinessException('微信接口返回错误[' . $errcode . '] ' . (string) ($rs['errmsg'] ?? ''));
        }
    }

    /* ==================== 日志落库 ==================== */

    /**
     * 成功落 mp_tmpl_sendlog（单发一行一条，to_type 1=公众号 2=小程序）
     *
     * @param array<string, mixed> $row 队列行
     * @param array<string, mixed> $rs  dispatch 结果
     */
    protected function writeSendlog(array $row, array $rs): void
    {
        Db::name('mp_tmpl_sendlog')->insert([
            'aid'          => (int) ($row['aid'] ?? 0),
            'title'        => (string) ($row['tmpl_name'] ?? ''),
            'template_id'  => (string) ($rs['template_id'] ?? ''),
            'data'         => (string) json_encode($rs['data'] ?? [], JSON_UNESCAPED_UNICODE),
            'url'          => (string) ($rs['url'] ?? ''),
            'to_type'      => 'wx' === ($rs['platform'] ?? 'mp') ? 2 : 1,
            'tagid'        => 0,
            'openids'      => (string) ($rs['openid'] ?? ''),
            'createtime'   => time(),
            'endtime'      => time(),
            'sendcount'    => 1,
            'successcount' => 1,
            'errorcount'   => 0,
            'isdel'        => 0,
        ]);
    }

    /**
     * 失败落 mp_tmpl_sendlog_errlog（logid 存队列行 id 便于回溯）
     *
     * @param array<string, mixed> $row 队列行
     */
    protected function writeErrlog(array $row, string $msg): void
    {
        $openid = '';
        try {
            $platform = $this->platformOfRow((string) ($row['type'] ?? ''));
            $member   = $this->memberOf((int) ($row['aid'] ?? 0), (int) ($row['mid'] ?? 0));
            $openid   = (string) ($member['wx' === $platform ? 'wxopenid' : 'mpopenid'] ?? '');
        } catch (\Throwable) {
            // openid 取不到不影响错误留痕
        }

        Db::name('mp_tmpl_sendlog_errlog')->insert([
            'aid'        => (int) ($row['aid'] ?? 0),
            'logid'      => (int) ($row['id'] ?? 0),
            'openid'     => $openid,
            'errcode'    => '',
            'errmsg'     => mb_substr($msg, 0, 200),
            'createtime' => time(),
        ]);
    }

    /* ==================== 辅助 ==================== */

    /** 队列行 type → 平台（sendwxtmpl/sendhtwxtmpl/wxtmpl/subscribe → wx，其余 mp） */
    public function platformOfRow(string $type): string
    {
        return in_array(strtolower($type), self::WX_TYPES, true) ? 'wx' : 'mp';
    }

    /** tmpl_name 归一为 tmpl_* 字段名（字段名直通，别名映射，其余补 tmpl_ 前缀） */
    public function tmplField(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            return '';
        }
        if (str_starts_with($name, 'tmpl_')) {
            return $name;
        }

        return self::TMPL_ALIASES[$name] ?? ('tmpl_' . $name);
    }

    /**
     * 取会员行（mid 非数字视为直传 openid 场景，返回空数组）
     *
     * @return array<string, mixed>
     */
    protected function memberOf(int $aid, $mid): array
    {
        if (!is_numeric($mid) || (int) $mid <= 0) {
            return [];
        }

        $member = Db::name('member')->where('id', (int) $mid)->field('id,nickname,mpopenid,wxopenid')->find();

        return $member ?: [];
    }

    /** 取会员平台 openid（mid 非数字时视为直传 openid） */
    protected function openidOfMember(int $aid, $mid, string $field): string
    {
        if (!is_numeric($mid)) {
            $openid = (string) $mid;
        } else {
            $openid = (string) ($this->memberOf($aid, (int) $mid)[$field] ?? '');
        }

        if ('' === $openid) {
            throw new BusinessException('openid为空（' . $field . '）');
        }

        return $openid;
    }

    /**
     * tmpl_content 解析：JSON 数组直通；纯文本包 first/remark；空返回 []
     *
     * @return array<string, mixed>
     */
    protected function parseContent(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded)) {
            return $decoded;
        }

        return ['first' => $raw, 'remark' => ''];
    }

    /**
     * 公众号模板 data 格式化（值统一 {value}，[nickname] 占位替换；
     * $useNew 时对订阅风格键名做长度约束，对标参考系统 formatTmplParam）
     *
     * @param array<string, mixed> $content
     * @return array<string, array{value: string}>
     */
    protected function formatTemplateData(array $content, bool $useNew, string $nickname): array
    {
        $data = [];

        foreach ($content as $k => $v) {
            if (is_array($v)) {
                $data[(string) $k] = $v;
                continue;
            }

            $v = (string) $v;
            if ('[nickname]' === $v && '' !== $nickname) {
                $v = $nickname;
            }

            $data[(string) $k] = ['value' => $useNew ? $this->formatSubscribeParam((string) $k, $v) : $v];
        }

        return $data;
    }

    /**
     * 小程序订阅消息 data 格式化（键名带类型后缀，按微信规则约束长度）
     *
     * @param array<string, mixed> $content
     * @return array<string, array{value: string}>
     */
    protected function formatSubscribeData(array $content): array
    {
        $data = [];

        foreach ($content as $k => $v) {
            if (is_array($v)) {
                $data[(string) $k] = $v;
                continue;
            }

            $key = (string) $k;
            $val = (string) $v;
            if (str_starts_with($key, 'thing') && '' === $val) {
                $val = '暂无';
            }

            $data[$key] = ['value' => $this->formatSubscribeParam($key, $val)];
        }

        return $data;
    }

    /**
     * 订阅消息参数类型约束（对标参考系统 Wechat::formatTmplParam）
     */
    protected function formatSubscribeParam(string $type, string $value): string
    {
        if (str_contains($type, 'thing')) {
            // 事物：20 个以内字符
            return '' === $value ? '无' : mb_substr($value, 0, 20, 'UTF-8');
        }

        if (str_contains($type, 'phone_number')) {
            // 电话：17 位以内
            return '' === $value ? '无' : mb_substr($value, 0, 17, 'UTF-8');
        }

        if (str_contains($type, 'car_number')) {
            // 车牌：8 位以内
            return '' === $value ? '无' : mb_substr($value, 0, 8, 'UTF-8');
        }

        if (str_contains($type, 'number')) {
            // 数字：32 位以内
            if ('' === $value) {
                return '';
            }
            if (!is_numeric($value)) {
                $value = mb_strlen($value, 'UTF-8') > 32 ? mb_substr($value, 0, 32, 'UTF-8') : $value;

                return (string) (float) $value;
            }

            return $value;
        }

        if (str_contains($type, 'character_string')) {
            // 字符串：32 位以内
            return '' === $value ? '无' : mb_substr($value, 0, 32, 'UTF-8');
        }

        if (str_contains($type, 'amount')) {
            // 金额：12 位以内
            return '' === $value ? '0' : mb_substr($value, 0, 12, 'UTF-8');
        }

        if (str_contains($type, 'name')) {
            // 姓名：10 个以内
            return '' === $value ? '无' : mb_substr($value, 0, 10, 'UTF-8');
        }

        if (str_contains($type, 'phrase')) {
            // 汉字：5 个以内纯汉字
            if ('' === $value) {
                return '无';
            }

            return preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $value)
                ? mb_substr($value, 0, 5, 'UTF-8')
                : '非汉字昵称';
        }

        return $value;
    }

    /**
     * 相对链接补全为完整 URL（http 直通；相对路径拼当前域名；CLI 无域名时原样返回）
     */
    protected function fullUrl(string $mUrl): string
    {
        if ('' === $mUrl) {
            return '';
        }
        if (str_starts_with($mUrl, 'http://') || str_starts_with($mUrl, 'https://')) {
            return $mUrl;
        }

        try {
            $domain = rtrim(request()->domain(), '/');
        } catch (\Throwable) {
            $domain = '';
        }

        return '' === $domain ? $mUrl : $domain . '/' . ltrim($mUrl, '/');
    }
}
