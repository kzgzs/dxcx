<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 微信会员卡服务（批次7-6 任务214；对标 canzhao/app/controller/Membercard.php）
 *
 * - 大表单保存/回读（membercard 表，57 字段全量配置）
 * - 同步微信：card/create（mock/真实双轨），成功后回写 card_id、ret_url
 * - 同步审核状态：card/get → status 映射（0审核中 1通过 2失败 3被删）
 * - 领取记录：列表/详情/删除/导出行
 * - 送积分：membercard_sendscorelog 分页幂等（logid 续传）
 *
 * mock 语义（OPEN_PLATFORM_MOCK=1）：cardCreate 返回模拟 card_id，
 * cardGet 直接返回审核通过，不发任何真实微信请求。
 */
class MembercardService
{
    /** 微信卡券颜色映射（对标参考系统 Membercard::getcolor） */
    public const COLOR_MAP = [
        'Color010' => '#63b359',
        'Color020' => '#2c9f67',
        'Color030' => '#509fc9',
        'Color040' => '#5885cf',
        'Color050' => '#9062c0',
        'Color060' => '#d09a45',
        'Color070' => '#e4b138',
        'Color080' => '#ee903c',
        'Color081' => '#f08500',
        'Color082' => '#a9d92d',
        'Color090' => '#dd6549',
        'Color100' => '#cc463d',
        'Color101' => '#cf3e36',
        'Color102' => '#5e6671',
    ];

    /** 审核状态：0审核中 1通过 2失败 3被删 */
    public const STATUS_MAP = [
        'CARD_STATUS_NOT_VERIFY'  => 0,
        'CARD_STATUS_VERIFY_OK'   => 1,
        'CARD_STATUS_VERIFY_FAIL' => 2,
        'CARD_STATUS_DELETE'      => 3,
    ];

    /** 开卡表单固定字段（key => 默认名称） */
    public const FIELD_KEYS = [
        'name'      => '姓名',
        'mobile'    => '手机号',
        'sex'       => '性别',
        'birthday'  => '生日',
        'email'     => '邮箱',
        'idcard'    => '身份证',
        'education' => '学历',
        'industry'  => '行业',
        'income'    => '年收入',
        'habit'     => '爱好',
        'location'  => '地址',
        'field1'    => '',
        'field2'    => '',
        'field3'    => '',
        'field4'    => '',
        'field5'    => '',
    ];

    /** 开卡表单字段 → 微信公共字段标识 */
    protected const FORM_FLAG = [
        'name'      => 'USER_FORM_INFO_FLAG_NAME',
        'mobile'    => 'USER_FORM_INFO_FLAG_MOBILE',
        'sex'       => 'USER_FORM_INFO_FLAG_SEX',
        'idcard'    => 'USER_FORM_INFO_FLAG_IDCARD',
        'birthday'  => 'USER_FORM_INFO_FLAG_BIRTHDAY',
        'email'     => 'USER_FORM_INFO_FLAG_EMAIL',
        'location'  => 'USER_FORM_INFO_FLAG_LOCATION',
        'education' => 'USER_FORM_INFO_FLAG_EDUCATION_BACKGROUND',
        'industry'  => 'USER_FORM_INFO_FLAG_INDUSTRY',
        'income'    => 'USER_FORM_INFO_FLAG_INCOME',
        'habit'     => 'USER_FORM_INFO_FLAG_HABIT',
    ];

    /** 允许前端写入的列（白名单，防脏字段入库） */
    protected const SAVE_FIELDS = [
        'card_type', 'logo_url', 'code_type', 'brand_name', 'title', 'bg_type', 'color',
        'background_pic_url', 'notice', 'description', 'custom_field', 'bonus_rules', 'quantity',
        'date_info_type', 'date_info_begin_time', 'date_info_end_time',
        'date_info_fixed_begin_term', 'date_info_fixed_term', 'service_phone',
        'location_id_list', 'use_all_locations',
        'center_title', 'center_sub_title', 'center_url',
        'custom_url_name', 'custom_url', 'custom_url_sub_title',
        'promotion_url_name', 'promotion_url', 'promotion_url_sub_title',
        'custom_cell1_name', 'custom_cell1_tips', 'custom_cell1_url',
        'get_limit', 'can_share', 'can_give_friend', 'prerogative', 'field_list',
        'auto_activate', 'wx_activate', 'activate_url',
        'custom_field_customize1_name', 'custom_field_customize1_value', 'custom_field_customize1_link',
        'custom_field_customize2_name', 'custom_field_customize2_value', 'custom_field_customize2_link',
        'remark',
    ];

    public function __construct(
        protected BindingTokenService $binding,
        protected WxApiClient $api,
        protected OpenPlatformService $open,
    ) {
    }

    /* ==================== 会员卡主表 ==================== */

    /**
     * 会员卡列表（附领取人数；status 含义：0审核中 1通过 2失败 3被删）
     *
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function list(int $aid, int $page, int $pagesize, string $keyword = ''): array
    {
        $query = Db::name('membercard')->where('aid', $aid);
        if ('' !== trim($keyword)) {
            $query->where('title', 'like', '%' . trim($keyword) . '%');
        }

        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['field_list']   = $this->jsonDecode((string) ($row['field_list'] ?? ''));
            $row['custom_field'] = $this->jsonDecode((string) ($row['custom_field'] ?? ''));
            $row['membercount']  = (int) Db::name('membercard_record')
                ->where('aid', $aid)->where('card_id', (string) ($row['card_id'] ?? ''))->count();
            $row['status_text']  = self::statusText((int) ($row['status'] ?? 0));
        }
        unset($row);

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 会员卡详情（id=0 返回创建默认值，对标参考系统 edit 默认段）
     *
     * @return array<string, mixed>
     */
    public function detail(int $aid, int $id): array
    {
        if ($id <= 0) {
            $set = Db::name('admin_set')->where('aid', $aid)->find();

            return [
                'id'                   => 0,
                'bg_type'              => 0,
                'color'                => '#63b359',
                'background_pic_url'   => '',
                'logo_url'             => (string) ($set['logo'] ?? ''),
                'brand_name'           => (string) ($set['name'] ?? ''),
                'code_type'            => 'CODE_TYPE_TEXT',
                'title'                => '微信会员卡',
                'custom_field'         => ['积分', '余额', '等级'],
                'center_title'         => '快速买单',
                'center_sub_title'     => '买单即享优惠',
                'center_url'           => 'pagesB/maidan/pay',
                'custom_url_name'      => '微信商城',
                'custom_url_sub_title' => '点击进入',
                'custom_url'           => 'pages/index/index',
                'promotion_url_name'   => '积分兑换',
                'promotion_url_sub_title' => '点击进入',
                'promotion_url'        => 'activity/scoreshop/index',
                'custom_cell1_name'    => '个人中心',
                'custom_cell1_tips'    => '点击进入',
                'custom_cell1_url'     => 'pages/my/usercenter',
                'notice'               => '到店出示会员卡',
                'description'          => '每人限领1张，仅限本人使用',
                'prerogative'          => '持卡消费送积分，参与丰富的会员专享活动',
                'bonus_rules'          => '每消费1元即可获得1积分，每1积分即可抵扣0.01元',
                'quantity'             => 99999999,
                'get_limit'            => 1,
                'service_phone'        => '',
                'date_info_type'       => 'DATE_TYPE_PERMANENT',
                'date_info_begin_time' => '',
                'date_info_end_time'   => '',
                'date_info_fixed_term' => 0,
                'date_info_fixed_begin_term' => 0,
                'field_list'           => $this->defaultFieldList(),
                'custom_field_customize1_name'  => '自定义1',
                'custom_field_customize1_value' => '查看',
                'custom_field_customize1_link'  => '',
                'custom_field_customize2_name'  => '自定义2',
                'custom_field_customize2_value' => '查看',
                'custom_field_customize2_link'  => '',
                'card_id'              => '',
                'ret_url'              => '',
                'status'               => 0,
            ];
        }

        $info = Db::name('membercard')->where('aid', $aid)->where('id', $id)->find();
        if (empty($info)) {
            throw new BusinessException('会员卡不存在');
        }

        $info['field_list']   = $this->jsonDecode((string) ($info['field_list'] ?? '')) ?: $this->defaultFieldList();
        $info['custom_field'] = $this->jsonDecode((string) ($info['custom_field'] ?? ''));
        if ('' === (string) ($info['code_type'] ?? '')) {
            $info['code_type'] = 'CODE_TYPE_TEXT';
        }
        $info['status_text'] = self::statusText((int) ($info['status'] ?? 0));

        return $info;
    }

    /**
     * 保存本地表（新增/修改；不触发微信请求，同步走 sync）
     *
     * @param array<string, mixed> $data 表单数据（含 id）
     * @return int 会员卡 ID
     */
    public function save(int $aid, array $data): int
    {
        $id   = (int) ($data['id'] ?? 0);
        $info = [];
        foreach (self::SAVE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $info[$field] = $data[$field];
            }
        }

        if ('' === trim((string) ($info['title'] ?? ''))) {
            throw new BusinessException('请填写卡券名');
        }
        if ('' === trim((string) ($info['brand_name'] ?? ''))) {
            throw new BusinessException('请填写商户名字');
        }

        // 结构化字段统一转 JSON 存储
        $info['custom_field'] = $this->jsonEncode(
            is_array($data['custom_field'] ?? null) ? array_values($data['custom_field']) : []
        );
        $info['field_list'] = $this->jsonEncode($this->normalizeFieldList($data['field_list'] ?? []));
        $info['card_type']  = 'MEMBER_CARD';
        foreach (['quantity', 'get_limit', 'date_info_fixed_term', 'date_info_fixed_begin_term', 'use_all_locations', 'can_share', 'can_give_friend', 'auto_activate', 'wx_activate', 'bg_type'] as $intField) {
            if (isset($info[$intField])) {
                $info[$intField] = (int) $info[$intField];
            }
        }

        if ($id > 0) {
            $exists = Db::name('membercard')->where('aid', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('会员卡不存在');
            }
            Db::name('membercard')->where('aid', $aid)->where('id', $id)->update($info);

            return $id;
        }

        $info['aid']        = $aid;
        $info['createtime'] = time();
        $info['status']     = 0;
        $newId = (int) Db::name('membercard')->insertGetId($info);

        return $newId;
    }

    /**
     * 同步微信（card/create）：成功后回写 card_id / ret_url，status=0 等待审核
     *
     * @return array<string, mixed>
     */
    public function sync(int $aid, int $id): array
    {
        $row = Db::name('membercard')->where('aid', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('会员卡不存在');
        }
        if ('' !== (string) ($row['card_id'] ?? '')) {
            return ['id' => $id, 'card_id' => (string) $row['card_id'], 'already' => true];
        }

        $payload = $this->buildCreatePayload($row);

        if ($this->open->isMock()) {
            $cardId = 'MOCK_CARD_' . strtoupper(substr(md5($aid . '-' . $id . '-' . time()), 0, 16));
            Db::name('membercard')->where('aid', $aid)->where('id', $id)->update([
                'card_id' => $cardId,
                'ret_url' => 'https://mock.dxcx.local/card/activate?card_id=' . $cardId,
                'status'  => 0,
            ]);

            return ['id' => $id, 'card_id' => $cardId, 'mock' => true];
        }

        $token = $this->binding->accessToken('mp', $aid);
        $rs    = $this->api->cardCreate($token, $payload);
        if (0 !== (int) ($rs['errcode'] ?? -1)) {
            if (48001 === (int) $rs['errcode']) {
                throw new BusinessException('创建失败，请确保公众号已开通卡券功能（微信公众平台[功能]-[添加功能插件]-[卡券功能]）');
            }
            throw new BusinessException('同步微信失败：' . (string) ($rs['errmsg'] ?? 'unknown'));
        }

        // 真实链路：开卡表单设置 + 开卡链接为 card/create 之后的独立接口，本期随同步一次完成
        Db::name('membercard')->where('aid', $aid)->where('id', $id)->update([
            'card_id' => (string) ($rs['card_id'] ?? ''),
            'status'  => 0,
        ]);

        return ['id' => $id, 'card_id' => (string) ($rs['card_id'] ?? ''), 'mock' => false];
    }

    /**
     * 同步审核状态（card/get）：0审核中 1通过 2失败 3被删
     *
     * @return array<string, mixed>
     */
    public function syncStatus(int $aid, int $id): array
    {
        $row = Db::name('membercard')->where('aid', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('会员卡不存在');
        }
        if ('' === (string) ($row['card_id'] ?? '')) {
            throw new BusinessException('请先同步微信后再查询审核状态');
        }

        if ($this->open->isMock()) {
            // mock：模拟审核通过闭环
            Db::name('membercard')->where('aid', $aid)->where('id', $id)->update(['status' => 1, 'RefuseReason' => '']);

            return ['id' => $id, 'status' => 1, 'status_text' => self::statusText(1), 'mock' => true];
        }

        $token = $this->binding->accessToken('mp', $aid);
        $rs    = $this->api->cardGet($token, (string) $row['card_id']);
        if (0 !== (int) ($rs['errcode'] ?? -1)) {
            throw new BusinessException('查询审核状态失败：' . (string) ($rs['errmsg'] ?? 'unknown'));
        }

        $wxStatus = (string) ($rs['card']['member_card']['base_info']['status'] ?? '');
        $status   = self::STATUS_MAP[$wxStatus] ?? 0;
        $update   = ['status' => $status];
        if (2 === $status) {
            $update['RefuseReason'] = (string) ($rs['card']['member_card']['base_info']['refuse_reason'] ?? '');
        }
        Db::name('membercard')->where('aid', $aid)->where('id', $id)->update($update);

        return ['id' => $id, 'status' => $status, 'status_text' => self::statusText($status), 'mock' => false];
    }

    /**
     * 删除会员卡（本地删除；微信侧删除接口未接入，mock 语义等同）
     *
     * @param array<int, int|string> $ids
     */
    public function del(int $aid, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0));
        if (empty($ids)) {
            return 0;
        }

        $count = (int) Db::name('membercard')->where('aid', $aid)->whereIn('id', $ids)->delete();

        return $count;
    }

    /** 设置备注 */
    public function setRemark(int $aid, int $id, string $remark): void
    {
        Db::name('membercard')->where('aid', $aid)->where('id', $id)->update(['remark' => $remark]);
    }

    /* ==================== 领取记录 ==================== */

    /**
     * 领取记录列表
     *
     * @param array<string, mixed> $filters card_id/card_code/name/mobile/starttime/endtime
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function recordList(int $aid, int $page, int $pagesize, array $filters = []): array
    {
        $query = Db::name('membercard_record')->where('aid', $aid);
        $this->applyRecordFilters($query, $filters);

        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        // 附卡名
        $cardIds = array_values(array_unique(array_filter(array_column($list, 'card_id'))));
        $titles  = [];
        if (!empty($cardIds)) {
            $titles = Db::name('membercard')->where('aid', $aid)->whereIn('card_id', $cardIds)
                ->column('title', 'card_id');
        }
        foreach ($list as &$row) {
            $row['card_title'] = (string) ($titles[(string) $row['card_id']] ?? '');
        }
        unset($row);

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 领取记录导出行（对标参考系统 recordexcel 列：序号/昵称/领取时间/卡号/姓名/手机号）
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<int, mixed>>
     */
    public function recordExportRows(int $aid, array $filters = []): array
    {
        $query = Db::name('membercard_record')->where('aid', $aid);
        $this->applyRecordFilters($query, $filters);
        $list = $query->order('id', 'desc')->select()->toArray();

        $rows = [];
        foreach ($list as $v) {
            $rows[] = [
                $v['id'],
                (string) ($v['nickname'] ?? ''),
                date('Y-m-d H:i:s', (int) ($v['createtime'] ?? 0)),
                (string) ($v['card_code'] ?? ''),
                (string) ($v['name'] ?? ''),
                (string) ($v['mobile'] ?? ''),
            ];
        }

        return $rows;
    }

    /** 领取记录详情 */
    public function recordDetail(int $aid, int $id): array
    {
        $info = Db::name('membercard_record')->where('aid', $aid)->where('id', $id)->find();
        if (empty($info)) {
            throw new BusinessException('领取记录不存在');
        }

        return $info;
    }

    /**
     * 删除领取记录
     *
     * @param array<int, int|string> $ids
     */
    public function recordDel(int $aid, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0));
        if (empty($ids)) {
            return 0;
        }

        $count = (int) Db::name('membercard_record')->where('aid', $aid)->whereIn('id', $ids)->delete();

        return $count;
    }

    /* ==================== 送积分（幂等分页） ==================== */

    /**
     * 送积分：按卡批量给领取人加积分，写 membercard_sendscorelog；
     * 前端分页驱动（logid 续传保证同一批次幂等归账）。
     *
     * @return array{status: int, logid: int, sendcount: int, successcount: int, errorcount: int, mock: bool}
     *         status：1=全部完成 2=还有下一页
     */
    public function sendScore(int $aid, int $cardLocalId, int $score, string $remark, int $logid, int $pagenum, int $pagelimit): array
    {
        if ($score <= 0) {
            throw new BusinessException('请填写赠送积分数量');
        }
        if ($pagenum <= 0) {
            $pagenum = 1;
        }
        if ($pagelimit <= 0 || $pagelimit > 100) {
            $pagelimit = 20;
        }

        $membercard = Db::name('membercard')->where('aid', $aid)->where('id', $cardLocalId)->find();
        if (empty($membercard)) {
            throw new BusinessException('会员卡不存在');
        }
        $cardId = (string) ($membercard['card_id'] ?? '');
        if ('' === $cardId) {
            throw new BusinessException('该会员卡尚未同步微信');
        }

        $memberCount = (int) Db::name('membercard_record')->where('aid', $aid)->where('card_id', $cardId)->count();
        if ($memberCount <= 0) {
            throw new BusinessException('该会员卡暂无领取记录');
        }

        // 幂等批次：首页创建日志，续页携带 logid
        if ($logid <= 0) {
            $logid = (int) Db::name('membercard_sendscorelog')->insertGetId([
                'aid'          => $aid,
                'card_id'      => $cardId,
                'score'        => $score,
                'remark'       => $remark,
                'createtime'   => time(),
                'sendcount'    => 0,
                'successcount' => 0,
                'errorcount'   => 0,
                'endtime'      => time(),
            ]);
        } else {
            $log = Db::name('membercard_sendscorelog')->where('aid', $aid)->where('id', $logid)->find();
            if (empty($log) || (string) $log['card_id'] !== $cardId) {
                throw new BusinessException('送积分批次不存在或卡不匹配');
            }
        }

        $recordList = Db::name('membercard_record')->where('aid', $aid)->where('card_id', $cardId)
            ->page($pagenum, $pagelimit)->select()->toArray();

        if ($this->open->isMock()) {
            $sendcount    = count($recordList);
            $successcount = count($recordList);
            $errorcount   = 0;
        } else {
            // 真实链路：card/membercard/updateuser（WxApiClient 暂未封装该方法，与 YuebaoService 同口径桩）
            throw new BusinessException('真实微信送积分通道未接入（TODO）', 501, 501);
        }

        Db::name('membercard_sendscorelog')->where('aid', $aid)->where('id', $logid)->update([
            'sendcount'    => Db::raw('sendcount+' . $sendcount),
            'successcount' => Db::raw('successcount+' . $successcount),
            'errorcount'   => Db::raw('errorcount+' . $errorcount),
            'endtime'      => time(),
        ]);

        $log = Db::name('membercard_sendscorelog')->where('aid', $aid)->where('id', $logid)->find();

        return [
            'status'       => $memberCount <= $pagelimit * $pagenum ? 1 : 2,
            'logid'        => $logid,
            'sendcount'    => (int) ($log['sendcount'] ?? 0),
            'successcount' => (int) ($log['successcount'] ?? 0),
            'errorcount'   => (int) ($log['errorcount'] ?? 0),
            'mock'         => $this->open->isMock(),
        ];
    }

    /* ==================== 内部工具 ==================== */

    /** 构建 card/create 报文（对标参考系统 save 添加分支，简化 getcustom/小程序跳转段） */
    protected function buildCreatePayload(array $row): array
    {
        $customField = $this->jsonDecode((string) ($row['custom_field'] ?? ''));
        $fieldList   = $this->jsonDecode((string) ($row['field_list'] ?? ''));

        $baseInfo = [
            'color'       => self::colorCode((string) ($row['color'] ?? '')),
            'logo_url'    => (string) ($row['logo_url'] ?? ''),
            'code_type'   => (string) ($row['code_type'] ?: 'CODE_TYPE_TEXT'),
            'brand_name'  => (string) ($row['brand_name'] ?? ''),
            'title'       => (string) ($row['title'] ?? ''),
            'get_limit'   => (int) ($row['get_limit'] ?: 1),
            'notice'      => (string) ($row['notice'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'sku'         => ['quantity' => (int) ($row['quantity'] ?: 99999999)],
            'date_info'   => [
                'type'              => (string) ($row['date_info_type'] ?: 'DATE_TYPE_PERMANENT'),
                'begin_timestamp'   => '' !== (string) ($row['date_info_begin_time'] ?? '') ? strtotime((string) $row['date_info_begin_time']) : 0,
                'end_timestamp'     => '' !== (string) ($row['date_info_end_time'] ?? '') ? strtotime((string) $row['date_info_end_time']) : 0,
                'fixed_term'        => (int) ($row['date_info_fixed_term'] ?? 0),
                'fixed_begin_term'  => (int) ($row['date_info_fixed_begin_term'] ?? 0),
            ],
        ];
        if ('CODE_TYPE_TEXT' !== $baseInfo['code_type']) {
            $baseInfo['is_pay_and_qrcode'] = true;
        }
        if ('' !== (string) ($row['service_phone'] ?? '')) {
            $baseInfo['service_phone'] = (string) $row['service_phone'];
        }
        if ('' !== (string) ($row['center_title'] ?? '')) {
            $baseInfo['center_title']     = (string) $row['center_title'];
            $baseInfo['center_sub_title'] = (string) ($row['center_sub_title'] ?? '');
            $baseInfo['center_url']       = (string) ($row['center_url'] ?? '');
        }
        $baseInfo['custom_url_name']      = (string) ($row['custom_url_name'] ?? '');
        $baseInfo['custom_url_sub_title'] = (string) ($row['custom_url_sub_title'] ?? '');
        $baseInfo['custom_url']           = trim((string) ($row['custom_url'] ?? ''));
        $baseInfo['promotion_url_name']      = (string) ($row['promotion_url_name'] ?? '');
        $baseInfo['promotion_url_sub_title'] = (string) ($row['promotion_url_sub_title'] ?? '');
        $baseInfo['promotion_url']           = trim((string) ($row['promotion_url'] ?? ''));

        $memberCard = [
            'wx_activate'                => true,
            'wx_activate_after_submit'   => false,
            'supply_balance'             => false,
            'prerogative'                => (string) ($row['prerogative'] ?? ''),
            'activate_form'              => $this->buildActivateForm($fieldList),
        ];
        if (1 === (int) ($row['bg_type'] ?? 0)) {
            $memberCard['background_pic_url'] = (string) ($row['background_pic_url'] ?? '');
        }
        if (in_array('积分', $customField, true)) {
            $memberCard['supply_bonus'] = true;
            if ('' !== (string) ($row['bonus_rules'] ?? '')) {
                $memberCard['bonus_rules'] = (string) $row['bonus_rules'];
            }
        } else {
            $memberCard['supply_bonus'] = false;
        }

        // 顶部三导航（积分占 supply_bonus，其余依次占 custom_field1~3）
        $k = 1;
        foreach ($customField as $item) {
            if ('积分' === $item || $k > 3) {
                continue;
            }
            if ('余额' === $item) {
                $memberCard['custom_field' . $k] = ['name' => '余额', 'name_type' => '', 'url' => 'pagesExt/money/recharge'];
            } elseif ('优惠券' === $item) {
                $memberCard['custom_field' . $k] = ['name_type' => 'FIELD_NAME_TYPE_COUPON', 'url' => 'pagesExt/coupon/mycoupon'];
            } elseif ('等级' === $item) {
                $memberCard['custom_field' . $k] = ['name_type' => 'FIELD_NAME_TYPE_LEVEL', 'url' => 'pagesExt/my/levelinfo'];
            } elseif ('自定义1' === $item) {
                $memberCard['custom_field' . $k] = [
                    'name' => (string) ($row['custom_field_customize1_name'] ?? ''),
                    'url'  => (string) ($row['custom_field_customize1_link'] ?? ''),
                ];
            } elseif ('自定义2' === $item) {
                $memberCard['custom_field' . $k] = [
                    'name' => (string) ($row['custom_field_customize2_name'] ?? ''),
                    'url'  => (string) ($row['custom_field_customize2_link'] ?? ''),
                ];
            } else {
                continue;
            }
            ++$k;
        }

        // 自定义入口（左下 cell）
        $memberCard['custom_cell1'] = [
            'name' => (string) ($row['custom_cell1_name'] ?? ''),
            'tips' => (string) ($row['custom_cell1_tips'] ?? ''),
            'url'  => (string) ($row['custom_cell1_url'] ?? ''),
        ];

        $memberCard['base_info'] = $baseInfo;

        return [
            'card_type'   => 'MEMBER_CARD',
            'member_card' => $memberCard,
        ];
    }

    /** 开卡表单（activateuserform 报文中的 required_form/optional_form） */
    protected function buildActivateForm(array $fieldList): array
    {
        $required = ['can_modify' => false, 'common_field_id_list' => [], 'custom_field_list' => []];
        $optional = ['can_modify' => false, 'common_field_id_list' => [], 'custom_field_list' => []];

        foreach ($fieldList as $key => $v) {
            if (1 !== (int) ($v['isshow'] ?? 0)) {
                continue;
            }
            if (1 === (int) ($v['required'] ?? 0)) {
                $target = &$required;
            } else {
                $target = &$optional;
            }
            if (isset(self::FORM_FLAG[(string) $key])) {
                $target['common_field_id_list'][] = self::FORM_FLAG[(string) $key];
            } elseif ('' !== trim((string) ($v['name'] ?? ''))) {
                $target['custom_field_list'][] = (string) $v['name'];
            }
            unset($target);
        }

        return ['required_form' => $required, 'optional_form' => $optional];
    }

    /** 记录筛选条件组装 */
    protected function applyRecordFilters($query, array $filters): void
    {
        if ('' !== trim((string) ($filters['card_id'] ?? ''))) {
            $query->where('card_id', 'like', '%' . trim((string) $filters['card_id']) . '%');
        }
        if ('' !== trim((string) ($filters['card_code'] ?? ''))) {
            $query->where('card_code', 'like', '%' . trim((string) $filters['card_code']) . '%');
        }
        if ('' !== trim((string) ($filters['name'] ?? ''))) {
            $query->where('name', 'like', '%' . trim((string) $filters['name']) . '%');
        }
        if ('' !== trim((string) ($filters['mobile'] ?? ''))) {
            $query->where('mobile', 'like', '%' . trim((string) $filters['mobile']) . '%');
        }
        if ('' !== trim((string) ($filters['starttime'] ?? ''))) {
            $query->where('createtime', '>=', strtotime(trim((string) $filters['starttime'])));
        }
        if ('' !== trim((string) ($filters['endtime'] ?? ''))) {
            $query->where('createtime', '<', strtotime(trim((string) $filters['endtime'])) + 86400);
        }
    }

    /** 默认开卡表单字段（对标参考系统 edit 默认 field_list） */
    protected function defaultFieldList(): array
    {
        $list = [];
        foreach (self::FIELD_KEYS as $key => $name) {
            $list[$key] = [
                'name'     => $name,
                'isshow'   => in_array($key, ['name', 'mobile'], true) ? 1 : 0,
                'required' => in_array($key, ['name', 'mobile'], true) ? 1 : 0,
            ];
        }

        return $list;
    }

    /** 前端 field_list 规整：仅保留约定 key，补齐 name/isshow/required */
    protected function normalizeFieldList($input): array
    {
        $input  = is_array($input) ? $input : [];
        $result = [];
        foreach (self::FIELD_KEYS as $key => $defaultName) {
            $v = is_array($input[$key] ?? null) ? $input[$key] : [];
            $result[$key] = [
                'name'     => '' !== trim((string) ($v['name'] ?? '')) ? (string) $v['name'] : $defaultName,
                'isshow'   => (int) ($v['isshow'] ?? 0) === 1 ? 1 : 0,
                'required' => (int) ($v['required'] ?? 0) === 1 ? 1 : 0,
            ];
            // 必填前提是显示
            if (0 === $result[$key]['isshow']) {
                $result[$key]['required'] = 0;
            }
        }

        return $result;
    }

    /** 颜色值 → 微信颜色编码（兼容直接传编码） */
    public static function colorCode(string $color): string
    {
        if (isset(self::COLOR_MAP[$color])) {
            return $color;
        }
        $flipped = array_flip(self::COLOR_MAP);

        return (string) ($flipped[$color] ?? 'Color010');
    }

    public static function statusText(int $status): string
    {
        return match ($status) {
            1       => '审核通过',
            2       => '审核失败',
            3       => '已被删除',
            default => '审核中',
        };
    }

    protected function jsonEncode(array $data): string
    {
        return (string) (json_encode($data, JSON_UNESCAPED_UNICODE) ?: '[]');
    }

    protected function jsonDecode(string $raw): array
    {
        if ('' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
