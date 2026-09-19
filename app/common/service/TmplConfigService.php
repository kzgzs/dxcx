<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 模板/订阅消息配置服务（批次7-3 任务211）
 *
 * 对标 canzhao\app\controller\Mptmpl.php 与 Wxtmpl.php，重写为纯 API：
 * - mp_tmplset / mp_tmplset_new / wx_tmplset 三表配置读写（租户 aid 维度 upsert）
 * - 已添加模板聚合列表（两张公众号配置表非空字段聚合）与删除（清空字段）
 * - 添加模板：
 *   - 公众号行业模板 apiAddTemplate / 类目模板 apiAddTemplateNew（附 keyword_name_list，
 *     添加前自动确保行业含「互联网|电子商务」，对标 canzhao mytmpl 行业逻辑）
 *   - 小程序订阅消息 newTmplAddTemplate（tid+kidList）
 * - mock 兼容（OPEN_PLATFORM_MOCK=1）：直出模拟 template_id，不调微信接口；
 *   模板编号传 ERR<code>（如 ERR45026/ERR48001/ERR200012）可模拟微信错误码分支
 * - token：BindingTokenService::accessToken('mp'|'wx', aid) 双模式托管
 */
class TmplConfigService
{
    /** 模板消息行业字典（对标 canzhao Mptmpl::mytmpl） @var array<string, int> */
    private const INDUSTRY_LIST = [
        'IT科技/互联网|电子商务' => 1, 'IT科技/IT软件与服务' => 2, 'IT科技/IT硬件与设备' => 3,
        'IT科技/电子技术' => 4, 'IT科技/通信与运营商' => 5, 'IT科技/网络游戏' => 6,
        '金融业/银行' => 7, '金融业/基金理财信托' => 8, '金融业/保险' => 9,
        '餐饮/餐饮' => 10, '酒店旅游/酒店' => 11, '酒店旅游/旅游' => 12,
        '运输与仓储/快递' => 13, '运输与仓储/物流' => 14, '运输与仓储/仓储' => 15,
        '教育/培训' => 16, '教育/院校' => 17, '政府与公共事业/学术科研' => 18,
        '政府与公共事业/交警' => 19, '政府与公共事业/博物馆' => 20,
        '政府与公共事业/公共事业非盈利机构' => 21, '医药护理/医药医疗' => 22,
        '医药护理/护理美容' => 23, '医药护理/保健与卫生' => 24, '交通工具/汽车相关' => 25,
        '交通工具/摩托车相关' => 26, '交通工具/火车相关' => 27, '交通工具/飞机相关' => 28,
        '房地产/建筑' => 29, '房地产/物业' => 30, '消费品/消费品' => 31,
        '商业服务/法律' => 32, '商业服务/会展' => 33, '商业服务/中介服务' => 34,
        '商业服务/认证' => 35, '商业服务/审计' => 36, '文体娱乐/传媒' => 37,
        '文体娱乐/体育' => 38, '文体娱乐/娱乐休闲' => 39, '印刷/印刷' => 40, '其它/其它' => 41,
    ];

    /** 类目模板关键词库（照抄 canzhao Mptmpl::getKeywordsList） @var array<string, array<int, string>> */
    public const MP_NEW_KEYWORDS = [
        // 订单提交成功通知
        '45764' => ['订单号', '下单门店', '商品名称', '下单金额', '提交时间'],
        // 订单支付成功通知
        '43216' => ['门店', '订单号', '商品名称', '会员名称', '支付金额'],
        // 买单支付成功通知
        '47568' => ['门店', '订单编号', '商品名称', '付款人', '交易金额'],
        // 订单发货通知
        '42984' => ['商品名称', '快递公司', '快递单号', '收货人'],
        // 订单完成通知
        '46234' => ['收货人', '订单号', '送达时间'],
        // 退款申请通知
        '46044' => ['订单号', '退款金额'],
        // 退款成功通知
        '46622' => ['订单编号', '商品名称', '退款金额'],
        // 退款驳回通知
        '46623' => ['订单编号', '商品名称', '退款金额'],
        // 消费成功通知
        '42830' => ['消费项目', '消费金额', '卡内余额', '消费时间'],
        // 收到客户新订单通知
        '48089' => ['订单编号', '门店名称', '商品名称', '客户地址', '订单时间'],
        // 提现成功通知
        '52381' => ['提现金额', '提现时间'],
        // 收到报名申请通知
        '46046' => ['报名名称', '申请时间'],
        // 报名审核结果通知
        '48306' => ['报名名称', '审核结果', '审核时间'],
        // 订单缺货提醒
        '57863' => ['订单编号', '下单时间', '商品名称'],
        // 账单结算成功通知
        '48189' => ['店铺名称', '结算时间', '结算金额', '可用余额'],
        // 设备缺货提醒
        '53349' => ['机器名称', '地点'],
        // 会员购买成功通知
        '46230' => ['到期时间', '购买类型'],
        // 售后订单处理通知
        '52660' => ['商品名称', '客户名称'],
        // 下级确认收货通知
        '54501' => ['订单编号', '商品名称', '订单金额', '订单时间', '预计佣金'],
        // 客户预约车险通知
        '42773' => ['车牌号', '客户姓名', '到期时间', '联系电话'],
        // 车辆保养申请成功提醒
        '54729' => ['车牌号码', '本次时间'],
        // 车辆年检申请提醒
        '53913' => ['申请人', '车牌号', '到期时间', '咨询电话'],
        // 收款已完成通知
        '47391' => ['活动名称', '活动时间'],
        // 设备告警提醒
        '42933' => ['设备名称', '告警原因', '告警时间'],
        // 取货提醒
        '47967' => ['取货时间', '门店名称', '商品名称', '取货地址', '联系方式'],
        // 客户预定成功通知
        '48014' => ['酒店名称', '入住人', '入离时间', '手机号', '订单号'],
        // 接单成功提醒
        '58919' => ['订单编号', '接单人', '服务时间', '联系电话'],
    ];

    /** mp_tmplset 可写字段（23 个业务场景 + 消息链接） @var array<string, string> */
    public const MP_FIELDS = [
        'tmpl_orderconfirm' => '订单提交成功通知', 'tmpl_orderpay' => '订单支付成功通知',
        'tmpl_orderfahuo' => '订单发货通知', 'tmpl_ordershouhuo' => '订单收货通知',
        'tmpl_ordertui' => '退款申请通知', 'tmpl_tuisuccess' => '退款成功通知',
        'tmpl_tuierror' => '退款申请驳回通知', 'tmpl_withdraw' => '提现申请通知',
        'tmpl_tixiansuccess' => '提现成功通知', 'tmpl_tixianerror' => '提现失败通知',
        'tmpl_collagesuccess' => '拼团成功通知', 'tmpl_formsub' => '表单提交通知',
        'tmpl_kehuzixun' => '客户咨询通知', 'tmpl_fenxiaosuccess' => '分销成功提醒',
        'tmpl_fuwudaoqi' => '服务到期提醒', 'tmpl_joinin' => '成员加入提醒',
        'tmpl_peisongorder' => '新配送订单通知', 'tmpl_uplv' => '会员升级通知',
        'tmpl_moneychange' => '余额变动提示', 'tmpl_restaurant_booking' => '餐饮预定通知',
        'tmpl_shenhe' => '审核结果通知', 'tmpl_prize' => '抽奖结果通知',
        'tmpl_fenhong' => '分红到账通知', 'tmpl_coupon_expire' => '优惠券到期提醒',
    ];

    /** mp_tmplset_new 可写字段（类目模板版，多 joinfree/zhaopin/register/maidanpay/yuyue_jiedan） @var array<string, string> */
    public const MP_NEW_FIELDS = [
        'tmpl_orderconfirm' => '订单提交成功通知', 'tmpl_orderpay' => '订单支付成功通知',
        'tmpl_orderfahuo' => '订单发货通知', 'tmpl_ordershouhuo' => '订单收货通知',
        'tmpl_ordertui' => '退款申请通知', 'tmpl_tuisuccess' => '退款成功通知',
        'tmpl_tuierror' => '退款驳回通知', 'tmpl_withdraw' => '提现申请通知',
        'tmpl_tixiansuccess' => '提现成功通知', 'tmpl_tixianerror' => '提现失败通知',
        'tmpl_collagesuccess' => '拼团成功通知', 'tmpl_formsub' => '收到报名申请通知',
        'tmpl_kehuzixun' => '客户咨询通知', 'tmpl_fenxiaosuccess' => '分销成功提醒',
        'tmpl_fuwudaoqi' => '服务到期提醒', 'tmpl_joinin' => '成员加入提醒',
        'tmpl_peisongorder' => '收到客户新订单通知', 'tmpl_uplv' => '会员升级通知',
        'tmpl_moneychange' => '余额消费通知', 'tmpl_restaurant_booking' => '餐饮预定通知',
        'tmpl_shenhe' => '审核结果通知', 'tmpl_prize' => '抽奖结果通知',
        'tmpl_fenhong' => '分红到账通知', 'tmpl_joinfree' => '加入免单通知',
        'tmpl_zhaopin_notice' => '招聘通知', 'tmpl_register' => '注册成功通知',
        'tmpl_coupon_expire' => '业务到期通知', 'tmpl_maidanpay' => '买单付款通知',
        'tmpl_yuyue_jiedan' => '预约接单提醒',
    ];

    /** wx_tmplset 主字段组（旧模板消息/管理员通知） @var array<string, string> */
    public const WX_FIELDS = [
        'tmpl_orderconfirm' => '订单下单提醒', 'tmpl_orderpay' => '支付成功通知',
        'tmpl_orderfahuo' => '订单发货通知', 'tmpl_ordershouhuo' => '收货结果通知',
        'tmpl_ordertui' => '申请退款通知', 'tmpl_tuisuccess' => '退款成功通知',
        'tmpl_tuierror' => '退款申请驳回通知', 'tmpl_withdraw' => '提现申请通知',
        'tmpl_tixiansuccess' => '提现成功通知', 'tmpl_tixianerror' => '提现失败通知',
        'tmpl_collagesuccess' => '拼团成功通知', 'tmpl_fuwudaoqi' => '订单到期提醒',
        'tmpl_shenhe' => '审核结果通知', 'tmpl_kehuzixun' => '收到留言通知',
        'tmpl_choujiang' => '抽奖结果通知',
    ];

    /** wx_tmplset _new 订阅消息字段组 @var array<string, string> */
    public const WX_NEW_FIELDS = [
        'tmpl_orderfahuo_new' => '发货通知', 'tmpl_tuisuccess_new' => '退款成功通知',
        'tmpl_tuierror_new' => '退款拒绝通知', 'tmpl_tixiansuccess_new' => '提现到账通知',
        'tmpl_tixianerror_new' => '提现结果通知', 'tmpl_collagesuccess_new' => '拼团成功通知',
        'tmpl_shenhe_new' => '审核结果通知',
    ];

    public function __construct(
        protected OpenPlatformService $open,
        protected BindingTokenService $token,
        protected WxTmplApiClient $api,
    ) {
    }

    /* ==================== 配置读写 ==================== */

    /** 读取公众号模板消息设置（无记录自动初始化空行） @return array<string, mixed> */
    public function mpTmplsetGet(int $aid): array
    {
        return $this->rowOf('mp_tmplset', $aid);
    }

    /** 保存公众号模板消息设置（白名单过滤，upsert） */
    public function mpTmplsetSave(int $aid, array $info): void
    {
        $this->saveRow('mp_tmplset', $aid, $info, array_keys(self::MP_FIELDS), 'tmpl_message_link');
    }

    /** 读取类目模板消息设置 @return array<string, mixed> */
    public function mpTmplsetNewGet(int $aid): array
    {
        return $this->rowOf('mp_tmplset_new', $aid);
    }

    /** 保存类目模板消息设置 */
    public function mpTmplsetNewSave(int $aid, array $info): void
    {
        $this->saveRow('mp_tmplset_new', $aid, $info, array_keys(self::MP_NEW_FIELDS), 'tmpl_message_link');
    }

    /** 读取小程序订阅消息设置 @return array<string, mixed> */
    public function wxTmplsetGet(int $aid): array
    {
        return $this->rowOf('wx_tmplset', $aid);
    }

    /** 保存小程序订阅消息设置（支持部分字段组独立保存） */
    public function wxTmplsetSave(int $aid, array $info): void
    {
        $allow = array_merge(array_keys(self::WX_FIELDS), array_keys(self::WX_NEW_FIELDS));
        $this->saveRow('wx_tmplset', $aid, $info, $allow, '');
    }

    /** 取配置行（不存在自动插入空行） @return array<string, mixed> */
    protected function rowOf(string $table, int $aid): array
    {
        $row = ScopeService::query($table, $aid)->find();
        if (empty($row)) {
            Db::name($table)->insert(['aid' => $aid]);
            $row = ScopeService::query($table, $aid)->find();
        }

        return (array) ($row ?: ['aid' => $aid]);
    }

    /** 白名单过滤后 upsert */
    protected function saveRow(string $table, int $aid, array $info, array $allowFields, string $linkField): void
    {
        $save = [];
        foreach ($allowFields as $field) {
            if (array_key_exists($field, $info)) {
                $save[$field] = trim((string) $info[$field]);
            }
        }
        if ('' !== $linkField && array_key_exists($linkField, $info)) {
            $save[$linkField] = 'wx' === (string) $info[$linkField] ? 'wx' : 'mp';
        }
        if (empty($save)) {
            throw new BusinessException('没有可保存的配置项');
        }

        $row = ScopeService::query($table, $aid)->find();
        if (empty($row)) {
            $save['aid'] = $aid;
            Db::name($table)->insert($save);
        } else {
            Db::name($table)->where('id', (int) $row['id'])->update($save);
        }
    }

    /* ==================== 已添加的模板（聚合列表/删除） ==================== */

    /**
     * 聚合本租户已配置的模板ID（mp_tmplset + mp_tmplset_new 非空字段）
     *
     * @return array<int, array{source: string, source_label: string, field: string, label: string, template_id: string}>
     */
    public function myTmplList(int $aid): array
    {
        $list = [];
        $sets = [
            ['mp_tmplset', '公众号模板消息', self::MP_FIELDS],
            ['mp_tmplset_new', '类目模板消息', self::MP_NEW_FIELDS],
        ];

        foreach ($sets as [$table, $tableLabel, $fields]) {
            $row = (array) (ScopeService::query($table, $aid)->find() ?: []);
            foreach ($fields as $field => $label) {
                $tmplId = trim((string) ($row[$field] ?? ''));
                if ('' === $tmplId) {
                    continue;
                }
                $list[] = [
                    'source'      => $table,
                    'source_label' => $tableLabel,
                    'field'       => $field,
                    'label'       => $label,
                    'template_id' => $tmplId,
                ];
            }
        }

        return $list;
    }

    /** 删除已添加模板 = 清空对应配置字段 */
    public function myTmplDel(int $aid, string $source, string $field): void
    {
        $fields = match ($source) {
            'mp_tmplset' => self::MP_FIELDS,
            'mp_tmplset_new' => self::MP_NEW_FIELDS,
            default => throw new BusinessException('未知的模板来源'),
        };
        if (!isset($fields[$field])) {
            throw new BusinessException('未知的模板字段');
        }

        $row = ScopeService::query($source, $aid)->find();
        if (!empty($row)) {
            Db::name($source)->where('id', (int) $row['id'])->update([$field => '']);
        }
    }

    /* ==================== 公众号：添加模板 ==================== */

    /**
     * 添加行业模板（api_add_template，返回 template_id）
     * mock：直出模拟ID；编号 ERR<code> 可模拟错误码分支
     */
    public function mpAddTemplate(int $aid, string $templateNo): string
    {
        $templateNo = trim($templateNo);
        if ('' === $templateNo) {
            throw new BusinessException('模板编号不能为空');
        }

        if ($this->open->isMock()) {
            return $this->mockTemplateId('MOCKTMPL', $aid, $templateNo);
        }

        $token = $this->token->accessToken('mp', $aid);
        $rs    = $this->api->apiAddTemplate($token, $templateNo);

        return $this->assertMpAdd($rs);
    }

    /**
     * 添加类目模板（附 keyword_name_list，添加前自动确保行业含 互联网|电子商务）
     */
    public function mpAddTemplateNew(int $aid, string $templateNo): string
    {
        $templateNo = trim($templateNo);
        if ('' === $templateNo) {
            throw new BusinessException('模板编号不能为空');
        }

        if ($this->open->isMock()) {
            return $this->mockTemplateId('MOCKCAT', $aid, $templateNo);
        }

        $token = $this->token->accessToken('mp', $aid);
        $this->ensureIndustry($token);

        $keywords = self::MP_NEW_KEYWORDS[$templateNo] ?? [];
        $rs       = $this->api->apiAddTemplateNew($token, $templateNo, $keywords);

        return $this->assertMpAdd($rs);
    }

    /**
     * 确保行业含「互联网|电子商务」（对标 canzhao mytmpl 行业自动设置逻辑）
     */
    protected function ensureIndustry(string $token): void
    {
        $rs = $this->api->templateGetIndustry($token);
        $errcode = (int) ($rs['errcode'] ?? 0);
        if (0 !== $errcode) {
            if (48001 === $errcode) {
                throw new BusinessException('只有认证的服务号才有此权限');
            }
            throw new BusinessException('获取行业信息失败[' . $errcode . '] ' . (string) ($rs['errmsg'] ?? ''));
        }

        $primary   = (array) ($rs['primary_industry'] ?? []);
        $secondary = (array) ($rs['secondary_industry'] ?? []);
        if ('互联网|电子商务' === ($primary['second_class'] ?? '')
            || '互联网|电子商务' === ($secondary['second_class'] ?? '')) {
            return;
        }

        // 已有主营行业则保留，副营补「互联网|电子商务」；否则主营=互联网|电子商务 副营=IT软件与服务
        $industryId1 = 1;
        $industryId2 = 2;
        if (!empty($primary['first_class'])) {
            $key = $primary['first_class'] . '/' . $primary['second_class'];
            $industryId1 = self::INDUSTRY_LIST[$key] ?? 1;
            $industryId2 = 1;
        }

        $set = $this->api->templateSetIndustry($token, $industryId1, $industryId2);
        $setErr = (int) ($set['errcode'] ?? 0);
        if (0 !== $setErr) {
            if (48001 === $setErr) {
                throw new BusinessException('只有认证的服务号才有此权限');
            }
            throw new BusinessException('设置行业失败[' . $setErr . '] ' . (string) ($set['errmsg'] ?? ''));
        }
    }

    /** 公众号添加模板结果断言（45026 超限 / 48001 未认证） */
    protected function assertMpAdd(array $rs): string
    {
        $errcode = (int) ($rs['errcode'] ?? 0);
        if (0 === $errcode) {
            $tmplId = trim((string) ($rs['template_id'] ?? ''));
            if ('' === $tmplId) {
                throw new BusinessException('微信未返回模板ID');
            }

            return $tmplId;
        }
        if (45026 === $errcode) {
            throw new BusinessException('已添加的模板已达到数量限制，请先删除一些不用的模板再试');
        }
        if (48001 === $errcode) {
            throw new BusinessException('只有认证的服务号才有此权限');
        }

        throw new BusinessException('添加模板失败[' . $errcode . '] ' . (string) ($rs['errmsg'] ?? ''));
    }

    /* ==================== 小程序：订阅消息 ==================== */

    /**
     * 获取模板标题（小程序类目 newtmpl/gettitle）
     * mock 返回预置类目（覆盖本页使用的模板标题）
     *
     * @return array<int, array{tid: int, title: string}>
     */
    public function wxSubscribeTitle(int $aid): array
    {
        if ($this->open->isMock()) {
            return [
                ['tid' => 1458, 'title' => '发货通知'],
                ['tid' => 9244, 'title' => '退款成功通知'],
                ['tid' => 8746, 'title' => '退款拒绝通知'],
                ['tid' => 5263, 'title' => '提现到账通知'],
                ['tid' => 1470, 'title' => '提现结果通知'],
                ['tid' => 3098, 'title' => '拼团成功通知'],
                ['tid' => 4082, 'title' => '审核结果通知'],
                ['tid' => 585, 'title' => '订单下单提醒'],
                ['tid' => 3578, 'title' => '支付成功通知'],
                ['tid' => 1481, 'title' => '收货结果通知'],
                ['tid' => 1468, 'title' => '申请退款通知'],
                ['tid' => 6789, 'title' => '提现申请通知'],
                ['tid' => 3840, 'title' => '收到留言通知'],
            ];
        }

        $token = $this->token->accessToken('wx', $aid);
        $rs    = $this->api->newTmplGetTitle($token);
        $errcode = (int) ($rs['errcode'] ?? 0);
        if (0 !== $errcode) {
            throw new BusinessException('获取模板标题失败[' . $errcode . '] ' . (string) ($rs['errmsg'] ?? ''));
        }

        $list = [];
        foreach ((array) ($rs['data'] ?? []) as $item) {
            $list[] = ['tid' => (int) ($item['tid'] ?? 0), 'title' => (string) ($item['title'] ?? '')];
        }

        return $list;
    }

    /**
     * 组合模板并添加至账号（newtmpl/addtemplate，tid+kidList，返回 priTmplId）
     *
     * @param array<int, int|string> $kidList
     */
    public function wxAddTemplate(int $aid, string $templateNo, array $kidList): string
    {
        $templateNo = trim($templateNo);
        // ERR<code> 为 mock 错误码模拟约定，豁免数字校验
        if ('' === $templateNo || (!str_starts_with($templateNo, 'ERR') && !is_numeric($templateNo))) {
            throw new BusinessException('模板编号必须为数字');
        }

        if ($this->open->isMock()) {
            if (str_starts_with($templateNo, 'ERR')) {
                $code = (int) substr($templateNo, 3);
                if (45026 === $code || 200012 === $code) {
                    throw new BusinessException('模板数量超过微信最大限制，请先删除一些再添加。操作路径：微信公众号后台-[基础功能]-[订阅消息]');
                }
                throw new BusinessException('添加模板失败[' . $code . '] mock error');
            }

            return $this->mockTemplateId('MOCKSUB', $aid, $templateNo);
        }

        $token = $this->token->accessToken('wx', $aid);
        $rs    = $this->api->newTmplAddTemplate($token, (int) $templateNo, $kidList);

        $errcode = (int) ($rs['errcode'] ?? 0);
        if (0 === $errcode) {
            $tmplId = trim((string) ($rs['priTmplId'] ?? ''));
            if ('' === $tmplId) {
                throw new BusinessException('微信未返回模板ID');
            }

            return $tmplId;
        }
        if (45026 === $errcode || 200012 === $errcode) {
            throw new BusinessException('模板数量超过微信最大限制，请先删除一些再添加。操作路径：微信公众号后台-[基础功能]-[订阅消息]');
        }

        throw new BusinessException('添加订阅消息模板失败[' . $errcode . '] ' . (string) ($rs['errmsg'] ?? ''));
    }

    /* ==================== mock 辅助 ==================== */

    /**
     * mock 模式模拟模板ID生成；编号 ERR<code>（如 ERR45026）模拟微信错误码分支
     */
    protected function mockTemplateId(string $prefix, int $aid, string $templateNo): string
    {
        if (str_starts_with($templateNo, 'ERR')) {
            $code = (int) substr($templateNo, 3);
            match ($code) {
                45026 => throw new BusinessException('已添加的模板已达到数量限制，请先删除一些不用的模板再试'),
                48001 => throw new BusinessException('只有认证的服务号才有此权限'),
                200012 => throw new BusinessException('模板数量超过微信最大限制，请先删除一些再添加。操作路径：微信公众号后台-[基础功能]-[订阅消息]'),
                default => throw new BusinessException('添加模板失败[' . $code . '] mock error'),
            };
        }

        return $prefix . '_' . strtoupper(substr(md5($aid . '|' . $templateNo), 0, 16));
    }
}
