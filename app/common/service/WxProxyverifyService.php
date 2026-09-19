<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 微信代认证服务（任务216 批次7-8）
 *
 * 对标 canzhao\app\controller\extend\WxProxyverify.php + canzhao\app\custom\WxProxyverify.php：
 * - 小程序列表：聚合 open_authorizer（授权接入）与 wx_proxyverify_authorizer（认证快照）
 * - 认证订单：创建（主体类型）→checkNickName 名称校验→资料/资质上传→wxaauth/reauth 提交
 *   →taskid/task_status 流转→queryauth 查询进度回写
 * - 系统设置：wx_proxyverify_set 三类客户（企业/个体工商户/个人）代缴额度读写
 * - mock 模式（OpenPlatformService::isMock）：全部微信调用返回预置报文，状态链模拟推进
 * - 真实模式走 WxApiClient（token 经 OpenPlatformService，失效自动重刷）
 */
class WxProxyverifyService
{
    /** 任务状态文案（对标参考系统 task_status_names） */
    public const TASK_STATUS_NAMES = [
        -1 => '未提交', 0 => '初始状态', 1 => '任务超时，24小时内有效', 2 => '用户授权拒绝',
        3 => '用户授权同意', 4 => '发起人脸流程', 5 => '人脸认证失败', 6 => '人脸认证ok',
        7 => '人脸认证后，已经提交手机号码下发验证码', 8 => '手机验证失败', 9 => '手机验证成功',
        11 => '创建认证审核单失败', 12 => '创建认证审核审核单成功', 14 => '验证失败', 15 => '等待支付',
    ];

    /** 审核单状态文案（对标参考系统 apply_status_names） */
    public const APPLY_STATUS_NAMES = [
        -1 => '未提交', 0 => '审核单不存在', 1 => '待支付', 2 => '审核中',
        3 => '打回重填', 4 => '认证通过', 5 => '认证最终失败',
    ];

    /** 主体类型（对标参考系统 customer_types） */
    public const CUSTOMER_TYPES = [
        ['type' => 1, 'name' => '企业'],
        ['type' => 12, 'name' => '个体工商户'],
        ['type' => 15, 'name' => '个人'],
    ];

    /** 订单表可写字段白名单 */
    protected const ORDER_FIELDS = [
        'authorizer_appid', 'customer_type', 'company_name', 'company_pic', 'company_license_number',
        'company_address', 'company_tel', 'contact_name', 'contact_email', 'contact_mobile',
        'invoice_type', 'electronic_id', 'electronic_desc', 'vat_enterprise_phone', 'vat_id',
        'vat_enterprise_address', 'vat_desc', 'vat_bank_name', 'vat_bank_account',
        'qualification_other', 'account_name', 'account_name_type', 'account_supplemental',
        'pay_type', 'auth_identification', 'auth_ident_material', 'service_money', 'remark',
    ];

    /** mock 状态推进链：[task_status, apply_status]（对标参考系统任务状态语义） */
    protected const MOCK_STATUS_CHAIN = [
        [0, 0], [3, 0], [6, 0], [9, 0], [12, 0], [15, 1], [15, 2], [15, 4],
    ];

    public function __construct(protected OpenPlatformService $open)
    {
    }

    /* ==================== appid 解析 / 授权快照同步 ==================== */

    /**
     * 租户绑定的小程序 appid（admin_setapp_wx）；mock 无绑定时回落 Mock 授权方
     */
    public function resolveAppid(int $aid): string
    {
        $appid = (string) (Db::name('admin_setapp_wx')->where('aid', $aid)->value('appid') ?: '');
        if ('' === $appid) {
            if (!$this->open->isMock()) {
                throw new BusinessException('请先在平台绑定中心绑定微信小程序');
            }
            $appid = OpenPlatformService::MOCK_APPID;
        }

        return $appid;
    }

    /**
     * open_authorizer 记录同步为 wx_proxyverify_authorizer 快照（不存在则新增）
     *
     * @return array<string, mixed> 快照行
     */
    public function syncAuthorizer(int $aid, string $appid): array
    {
        $open = Db::name('open_authorizer')->where('authorizer_appid', $appid)->find();
        if (empty($open)) {
            // mock 兜底：走一遍授权方落库（签发模拟记录）
            if (!$this->open->isMock()) {
                throw new BusinessException('该小程序未授权给开放平台：' . $appid);
            }
            $this->open->authorizerAccessToken($appid);
            $open = Db::name('open_authorizer')->where('authorizer_appid', $appid)->find();
            if (empty($open)) {
                throw new BusinessException('该小程序未授权给开放平台：' . $appid);
            }
            Db::name('open_authorizer')->where('id', $open['id'])->update(['aid' => $aid, 'update_time' => time()]);
        }

        $now  = time();
        $find = Db::name('wx_proxyverify_authorizer')->where('aid', $aid)->where('authorizer_appid', $appid)->find();
        $data = [
            'nick_name'       => (string) ($open['nick_name'] ?? ''),
            'head_img'        => (string) ($open['head_img'] ?? ''),
            'user_name'       => (string) ($open['user_name'] ?? ''),
            'qrcode_url'      => (string) ($open['qrcode_url'] ?? ''),
            'principal_name'  => (string) ($open['principal_name'] ?? ''),
            'service_type_id' => (int) ($open['service_type_id'] ?? 0),
            'verify_type_id'  => (int) ($open['verify_type_id'] ?? -1),
            'refresh_token'   => (string) ($open['refresh_token'] ?? ''),
            'MiniProgramInfo' => '{"network":{"RequestDomain":[]}}',
            'updatetime'      => $now,
        ];

        if ($find) {
            Db::name('wx_proxyverify_authorizer')->where('id', $find['id'])->update($data);

            return Db::name('wx_proxyverify_authorizer')->where('id', $find['id'])->find();
        }

        $data['aid']              = $aid;
        $data['authorizer_appid'] = $appid;
        $data['auth_time']        = (int) ($open['auth_time'] ?? $now);
        $data['createtime']       = $now;
        // mock 模式快照默认未认证（便于模拟认证闭环）；真实模式按认证类型推导
        $data['verify_status']    = $this->open->isMock() ? 0 : ((int) $data['verify_type_id'] > -1 ? 2 : 0);
        $data['id']               = Db::name('wx_proxyverify_authorizer')->insertGetId($data);

        return $data;
    }

    /* ==================== 小程序列表 ==================== */

    /**
     * 已授权小程序聚合列表（open_authorizer → wx_proxyverify_authorizer 快照 + 认证状态）
     *
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, pagesize: int}
     */
    public function authorizerList(int $aid, int $page, int $pagesize, string $keyword = ''): array
    {
        // 1. 租户已授权小程序全部同步快照
        $openRows = Db::name('open_authorizer')->where('aid', $aid)->where('auth_status', 1)->select()->toArray();
        foreach ($openRows as $row) {
            $this->syncAuthorizer($aid, (string) $row['authorizer_appid']);
        }

        // 2. 快照表分页查询
        $query = ScopeService::query('wx_proxyverify_authorizer', $aid);
        $keyword = trim($keyword);
        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('nick_name', '%' . $keyword . '%')
                    ->whereOr('authorizer_appid', 'like', '%' . $keyword . '%')
                    ->whereOr('principal_name', 'like', '%' . $keyword . '%');
            });
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $list = array_map(function (array $row): array {
            $verifyStatus = (int) $row['verify_status'];
            // 有进行中的认证订单 → 认证中
            $doing = Db::name('wx_proxyverify')
                ->where('aid', $row['aid'])
                ->where('authorizer_appid', $row['authorizer_appid'])
                ->where('apply_status', 'in', [0, 1, 2, 3])
                ->where('task_status', '>=', 0)
                ->order('id', 'desc')
                ->find();
            if ($doing && 2 !== $verifyStatus) {
                $verifyStatus = 1;
            }

            return [
                'id'               => (int) $row['id'],
                'authorizer_appid' => (string) $row['authorizer_appid'],
                'nick_name'        => (string) ($row['nick_name'] ?? ''),
                'head_img'         => (string) ($row['head_img'] ?? ''),
                'principal_name'   => (string) ($row['principal_name'] ?? ''),
                'user_name'        => (string) ($row['user_name'] ?? ''),
                'qrcode_url'       => (string) ($row['qrcode_url'] ?? ''),
                'verify_status'    => $verifyStatus,
                'verify_status_name' => [0 => '未认证', 1 => '认证中', 2 => '已认证'][$verifyStatus] ?? '未知',
                'auth_time'        => (int) ($row['auth_time'] ?? 0),
                'auth_time_text'   => !empty($row['auth_time']) ? date('Y-m-d H:i', (int) $row['auth_time']) : '',
                'remark'           => (string) ($row['remark'] ?? ''),
            ];
        }, $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /** 修改小程序备注 */
    public function authorizerRemark(int $aid, int $id, string $remark): void
    {
        $row = ScopeService::query('wx_proxyverify_authorizer', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('该小程序不存在');
        }
        Db::name('wx_proxyverify_authorizer')->where('id', $id)->update(['remark' => $remark, 'updatetime' => time()]);
    }

    /** 删除小程序（快照 + 对应认证订单） */
    public function authorizerDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的小程序');
        }
        $rows = ScopeService::query('wx_proxyverify_authorizer', $aid)->whereIn('id', $ids)->select()->toArray();
        if (empty($rows)) {
            throw new BusinessException('无可删除的数据');
        }
        foreach ($rows as $row) {
            Db::name('wx_proxyverify_authorizer')->where('id', $row['id'])->delete();
            Db::name('wx_proxyverify')->where('aid', $aid)->where('authorizer_appid', $row['authorizer_appid'])->delete();
        }
    }

    /**
     * 授权回调（对标参考系统 callbackxcx）：auth_code 换取授权并落快照
     *
     * @return array<string, mixed>
     */
    public function callbackAuth(int $aid, string $authCode): array
    {
        if ('' === trim($authCode)) {
            throw new BusinessException('缺少 auth_code');
        }
        $record = $this->open->saveAuthorizerByAuthCode($authCode, $aid);
        $appid  = (string) $record['authorizer_appid'];
        $snap   = $this->syncAuthorizer($aid, $appid);

        return [
            'authorizer_appid' => $appid,
            'nick_name'        => (string) ($snap['nick_name'] ?? ''),
            'verify_status'    => (int) ($snap['verify_status'] ?? 0),
        ];
    }

    /* ==================== 认证订单 ==================== */

    /**
     * 认证订单列表（状态筛选）
     *
     * @param array<string, string> $filters customer_type/contact_name/account_name/pay_type/task_status/apply_status
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, pagesize: int}
     */
    public function orderList(int $aid, int $page, int $pagesize, array $filters = []): array
    {
        $query = ScopeService::query('wx_proxyverify', $aid);

        foreach (['customer_type', 'pay_type', 'task_status', 'apply_status'] as $field) {
            if (isset($filters[$field]) && '' !== (string) $filters[$field]) {
                $query->where($field, (int) $filters[$field]);
            }
        }
        foreach (['contact_name', 'account_name'] as $field) {
            if (!empty($filters[$field])) {
                $query->whereLike($field, '%' . trim((string) $filters[$field]) . '%');
            }
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $list = array_map(fn (array $row) => $this->formatOrder($row), $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /** 订单详情 @return array<string, mixed> */
    public function orderDetail(int $aid, int $id): array
    {
        $row = ScopeService::query('wx_proxyverify', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('该认证记录不存在');
        }

        return $this->formatOrder($row);
    }

    /** 订单行格式化（状态文案/时间） @return array<string, mixed> */
    protected function formatOrder(array $row): array
    {
        $taskStatus  = (int) $row['task_status'];
        $applyStatus = (int) $row['apply_status'];

        return array_merge($row, [
            'id'                 => (int) $row['id'],
            'customer_type'      => (int) $row['customer_type'],
            'customer_type_name' => [1 => '企业', 12 => '个体工商户', 15 => '个人'][(int) $row['customer_type']] ?? '',
            'task_status'        => $taskStatus,
            'task_status_name'   => self::TASK_STATUS_NAMES[$taskStatus] ?? '',
            'apply_status'       => $applyStatus,
            'apply_status_name'  => self::APPLY_STATUS_NAMES[$applyStatus] ?? '',
            'pay_type'           => (int) $row['pay_type'],
            'pay_type_name'      => 1 === (int) $row['pay_type'] ? '消耗服务商预购包' : '小程序开发者自行支付',
            'invoice_type'       => (int) $row['invoice_type'],
            'service_money'      => (string) $row['service_money'],
            'createtime_text'    => !empty($row['createtime']) ? date('Y-m-d H:i', (int) $row['createtime']) : '',
            'updatetime_text'    => !empty($row['updatetime']) ? date('Y-m-d H:i', (int) $row['updatetime']) : '',
        ]);
    }

    /**
     * 创建/编辑认证订单并提交微信（对标参考系统 orderedit + dealverify）
     *
     * @param array<string, mixed> $data 订单资料
     * @return array<string, mixed> {id, taskid, auth_url}
     */
    public function saveOrder(int $aid, array $data): array
    {
        $set = $this->getSet($aid);
        if ((int) $set['status'] !== 1) {
            throw new BusinessException('系统设置不存在，或未开启');
        }
        if ('' === (string) $set['service_appid']) {
            throw new BusinessException('系统未设置服务市场appid');
        }
        if ('' === (string) $set['third_party_phone']) {
            throw new BusinessException('系统未设置第三方联系电话');
        }

        // 租户侧微信认证模式（admin.wx_proxyverify：-1关闭 0跟随系统 1额度+自费 2仅服务商额度）
        $mode = (int) (Db::name('admin')->where('id', $aid)->value('wx_proxyverify') ?: 0);
        if (-1 === $mode) {
            throw new BusinessException('站点微信认证功能暂未开启，请联系客服开启');
        }

        $data['customer_type'] = (int) ($data['customer_type'] ?? 0);
        if (!in_array($data['customer_type'], [1, 12, 15], true)) {
            throw new BusinessException('机构类型错误');
        }
        if (empty($data['contact_name'])) {
            throw new BusinessException('认证联系人姓名必须填写');
        }
        if (empty($data['contact_email'])) {
            throw new BusinessException('认证联系人邮箱必须填写');
        }
        if (in_array($data['customer_type'], [1, 12], true) && empty($data['company_pic'])) {
            throw new BusinessException('请上传工商营业执照');
        }

        $data['invoice_type'] = (int) ($data['invoice_type'] ?? 1);
        if (!in_array($data['invoice_type'], [1, 2, 4], true)) {
            throw new BusinessException('发票类型错误');
        }
        if (2 === $data['invoice_type'] && empty($data['electronic_id'])) {
            throw new BusinessException('请填写发票类型的纳税识别号');
        }
        if (4 === $data['invoice_type']) {
            if (empty($data['vat_enterprise_phone'])) {
                throw new BusinessException('请填写发票类型的企业联系电话');
            }
            if (empty($data['vat_enterprise_address'])) {
                throw new BusinessException('请填写发票类型的企业注册地址');
            }
            if (empty($data['vat_id'])) {
                throw new BusinessException('请填写发票类型的纳税识别号');
            }
        }

        if (empty($data['account_name'])) {
            throw new BusinessException('请填写小程序账号名称');
        }

        // 已有记录 → 编辑重提；否则新建
        $info = [];
        if (!empty($data['id'])) {
            $info = (array) (ScopeService::query('wx_proxyverify', $aid)->where('id', (int) $data['id'])->find() ?: []);
            if (empty($info)) {
                throw new BusinessException('该认证记录不存在');
            }
        }

        $data['pay_type'] = (int) ($data['pay_type'] ?? 2);
        if ($info) {
            if ((int) $info['apply_status'] !== -1) {
                $data['pay_type'] = (int) $info['pay_type'];
            }
        } elseif (2 === $mode) {
            $data['pay_type'] = 1;
        }
        if (!in_array($data['pay_type'], [1, 2], true)) {
            throw new BusinessException('支付类型错误');
        }

        if (15 === $data['customer_type'] && !empty($data['auth_identification']) && empty($data['auth_ident_material'])) {
            throw new BusinessException('选择了个人认证类型必须上传身份证明材料');
        }

        if ($info) {
            if ((int) $info['task_status'] !== -1 && (int) $info['apply_status'] !== 3) {
                throw new BusinessException('该认证记录状态不符，不能再次提交');
            }
        } else {
            $data['authorizer_appid'] = trim((string) ($data['authorizer_appid'] ?? ''));
            if ('' === $data['authorizer_appid']) {
                $data['authorizer_appid'] = $this->resolveAppid($aid);
            }
            // 授权快照同步（保证后续状态流转有行可更新）
            $this->syncAuthorizer($aid, (string) $data['authorizer_appid']);

            // 使用服务商预购包：检查平台设置额度 + 租户额度
            if (1 === $data['pay_type']) {
                $this->checkQuota($aid, $set, $data['customer_type']);
            }
        }

        // 组装微信 auth_data 报文并上传资质材料
        $reqData = $this->buildAuthData($aid, $info, $data, (string) $set['service_appid'], (string) $set['third_party_phone']);

        $isReauth = $info && (int) $info['apply_status'] === 3;
        if ($this->open->isMock()) {
            $taskid  = 'MOCK_TASK_' . date('ymd') . substr(md5(uniqid('', true)), 0, 10);
            $authUrl = request()->domain() . '/open/mock/verifyauth?taskid=' . $taskid;
        } else {
            $appid = (string) ($info['authorizer_appid'] ?? $data['authorizer_appid']);
            if ($isReauth) {
                $reqData['taskid'] = (string) $info['taskid'];
            }
            $rs = $this->open->callWithAuthorizerToken($appid, function (string $token) use ($reqData, $isReauth) {
                return $isReauth ? $this->open->api()->wxaReauth($token, $reqData) : $this->open->api()->wxaAuth($token, $reqData);
            });
            if ((int) ($rs['errcode'] ?? -1) !== 0) {
                throw new BusinessException('提交失败：' . (string) ($rs['errmsg'] ?? 'unknown'));
            }
            $taskid  = (string) ($rs['taskid'] ?? '');
            $authUrl = (string) ($rs['auth_url'] ?? '');
        }

        // 落库（对标参考系统：提交成功后写订单 + 推进状态）
        $save = [];
        foreach (self::ORDER_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $save[$field] = $data[$field];
            }
        }
        $save['service_appid']      = (string) $set['service_appid'];
        $save['third_party_phone']  = (string) $set['third_party_phone'];
        $save['service_money']      = 1 === $data['pay_type'] ? (1 === $data['customer_type'] ? '300.00' : '30.00') : '0.00';
        $save['task_status']        = 0;
        $save['apply_status']       = 0;
        $save['taskid']             = $taskid;
        $save['auth_url']           = $authUrl;
        $save['auth_url_img']       = '';
        $save['errmsg']             = '';
        $save['updatetime']         = time();

        if ($info) {
            Db::name('wx_proxyverify')->where('id', (int) $info['id'])->update($save);
            $id = (int) $info['id'];
        } else {
            $save['aid']              = $aid;
            $save['authorizer_appid'] = (string) $data['authorizer_appid'];
            $save['createtime']       = time();
            $id = (int) Db::name('wx_proxyverify')->insertGetId($save);
        }

        // 小程序认证状态 → 认证中
        Db::name('wx_proxyverify_authorizer')
            ->where('aid', $aid)
            ->where('authorizer_appid', $save['authorizer_appid'] ?? (string) ($info['authorizer_appid'] ?? ''))
            ->where('verify_status', 0)
            ->update(['verify_status' => 1, 'updatetime' => time()]);

        // 扣减额度（仅首次提交消耗服务商包；打回重填不重复扣）
        if (1 === $data['pay_type'] && !$isReauth) {
            $this->decQuota($aid, $data['customer_type']);
        }

        return ['id' => $id, 'taskid' => $taskid, 'auth_url' => $authUrl];
    }

    /** 组装微信 auth_data 报文（含资质材料上传） @return array<string, mixed> */
    protected function buildAuthData(int $aid, array $info, array $data, string $serviceAppid, string $thirdPartyPhone): array
    {
        $appid   = (string) ($info['authorizer_appid'] ?? $data['authorizer_appid'] ?? '');
        $reqData = ['customer_type' => (int) $data['customer_type']];

        // 联系人信息
        $contactInfo = [
            'name'  => (string) $data['contact_name'],
            'email' => (string) $data['contact_email'],
        ];
        if ($info && (int) $info['apply_status'] === 3) {
            $contactInfo['mobile'] = (string) ($data['contact_mobile'] ?? '');
        }
        $reqData['contact_info'] = $contactInfo;

        // 发票信息
        $invoiceInfo = ['invoice_type' => (int) $data['invoice_type']];
        if (2 === (int) $data['invoice_type']) {
            $invoiceInfo['electronic'] = [
                'id'   => (string) ($data['electronic_id'] ?? ''),
                'desc' => (string) ($data['electronic_desc'] ?? ''),
            ];
        } elseif (4 === (int) $data['invoice_type']) {
            $invoiceInfo['vat'] = [
                'enterprise_phone'   => (string) ($data['vat_enterprise_phone'] ?? ''),
                'id'                 => (string) ($data['vat_id'] ?? ''),
                'enterprise_address' => (string) ($data['vat_enterprise_address'] ?? ''),
                'bank_name'          => (string) ($data['vat_bank_name'] ?? ''),
                'bank_account'       => (string) ($data['vat_bank_account'] ?? ''),
                'desc'               => (string) ($data['vat_desc'] ?? ''),
            ];
        }
        $reqData['invoice_info'] = $invoiceInfo;

        // 主体资质材料（企业/个体工商户）
        if (in_array((int) $data['customer_type'], [1, 12], true)) {
            $res = $this->uploadAuthMaterial($aid, $appid, (string) ($data['company_pic'] ?? ''));
            $reqData['qualification'] = $res['mediaid'];

            $reqData['qualification_other'] = $this->uploadMaterials(
                $aid, $appid, (string) ($data['qualification_other'] ?? ''), '主体资质其他证明材料'
            );
        }

        // 小程序账号名称 + 命名类型 + 补充材料
        $reqData['account_name']      = (string) ($data['account_name'] ?? '');
        $reqData['account_name_type'] = (int) ($data['account_name_type'] ?? 1);
        $reqData['account_supplemental'] = $this->uploadMaterials(
            $aid, $appid, (string) ($data['account_supplemental'] ?? ''), '小程序命名补充材料'
        );

        // 支付方式
        $reqData['pay_type'] = (int) $data['pay_type'];

        // 个人认证身份
        if (15 === (int) $data['customer_type']) {
            $reqData['auth_identification'] = (string) ($data['auth_identification'] ?? '');
            $reqData['auth_ident_material'] = $this->uploadMaterials(
                $aid, $appid, (string) ($data['auth_ident_material'] ?? ''), '身份证明材料'
            );
        }

        $reqData['third_party_phone'] = $thirdPartyPhone;
        $reqData['service_appid']     = $serviceAppid;

        return $reqData;
    }

    /** 逗号分隔多图批量上传 @return array<int, string> mediaid 列表 */
    protected function uploadMaterials(int $aid, string $appid, string $mediaStr, string $label): array
    {
        $list = array_values(array_filter(array_map('trim', explode(',', $mediaStr))));
        $ids  = [];
        foreach ($list as $media) {
            $res      = $this->uploadAuthMaterial($aid, $appid, $media);
            $ids[]    = (string) $res['mediaid'];
        }

        return $ids;
    }

    /**
     * 小程序认证上传补充材料（对标参考系统 uploadauthmaterial）
     *
     * @return array{status: int, msg: string, mediaid: string}
     */
    public function uploadAuthMaterial(int $aid, string $appid, string $media): array
    {
        $media = trim($media);
        if ('' === $media) {
            throw new BusinessException('上传材料不能为空');
        }

        if ($this->open->isMock()) {
            return ['status' => 1, 'msg' => '通过', 'mediaid' => 'MOCK_MEDIA_' . substr(md5($media), 0, 16)];
        }

        $filePath = $this->resolveLocalFile($media);
        $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->uploadAuthMaterial($token, $filePath));
        if ((int) ($rs['errcode'] ?? -1) !== 0) {
            throw new BusinessException('上传补充材料失败：' . (string) ($rs['errmsg'] ?? 'unknown'));
        }

        return ['status' => 1, 'msg' => '通过', 'mediaid' => (string) ($rs['mediaid'] ?? '')];
    }

    /** 本地附件 URL → 绝对路径 */
    protected function resolveLocalFile(string $media): string
    {
        $path = $media;
        if (preg_match('#^https?://#i', $path)) {
            $host = (string) request()->host(true);
            $path = preg_replace('#^https?://' . preg_quote($host, '#') . '#', '', $path) ?? $path;
        }
        $path = public_path() . ltrim(str_replace('\\', '/', $path), '/');
        if (!is_file($path)) {
            throw new BusinessException('上传材料文件不存在：' . $media);
        }

        return $path;
    }

    /* ==================== 名称校验 / 身份选项 ==================== */

    /**
     * 检查小程序认证名称是否合规（对标参考系统 checkNickName）
     *
     * @return array{msg: string, wording: string, hit_keyword: bool}
     */
    public function checkNickName(int $aid, string $appid, string $nickname): array
    {
        $nickname = trim($nickname);
        if ('' === $nickname) {
            throw new BusinessException('请填写小程序账号名称');
        }
        $this->syncAuthorizer($aid, $appid);

        if ($this->open->isMock()) {
            $lower = mb_strtolower($nickname);
            foreach (['微信', 'weixin', 'wechat', 'tencent', '腾讯'] as $word) {
                if (str_contains($lower, $word)) {
                    throw new BusinessException('名称校验失败：名称包含违禁词「' . $word . '」');
                }
            }
            $wording = '';
            foreach (['官方', '旗舰', '中国', '中华', '国家'] as $word) {
                if (str_contains($nickname, $word)) {
                    $wording = '名称包含「' . $word . '」相关词汇';
                    break;
                }
            }

            return [
                'msg'         => '' !== $wording ? '命中关键字策略，可能需要补充材料:' . $wording : '验证通过',
                'wording'     => $wording,
                'hit_keyword' => '' !== $wording,
            ];
        }

        $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->checkWxVerifyNickname($token, $nickname));
        if ((int) ($rs['errcode'] ?? -1) !== 0) {
            throw new BusinessException('验证失败：' . (string) ($rs['errmsg'] ?? 'unknown'));
        }
        $wording = (string) ($rs['wording'] ?? '');

        return [
            'msg'         => '' !== $wording ? '命中关键字策略，可能需要补充材料:' . $wording : '验证通过',
            'wording'     => $wording,
            'hit_keyword' => '' !== $wording,
        ];
    }

    /**
     * 查询个人认证身份选项列表（对标参考系统 chooseidentity，返回打平树）
     *
     * @return array<int, array<string, mixed>>
     */
    public function chooseIdentity(int $aid, string $appid): array
    {
        $this->syncAuthorizer($aid, $appid);

        if ($this->open->isMock()) {
            $tree = [
                ['name' => '企业', 'node_id' => 1, 'node_list' => [
                    ['name' => '企业员工', 'node_id' => 11, 'leaf_info' => ['requirement' => '需要提供在职证明']],
                    ['name' => '企业法人', 'node_id' => 12, 'leaf_info' => ['requirement' => '需要提供营业执照']],
                ]],
                ['name' => '个体商户', 'node_id' => 2, 'node_list' => [
                    ['name' => '个体商户法人', 'node_id' => 21, 'leaf_info' => ['requirement' => '需要提供个体工商户营业执照']],
                ]],
                ['name' => '其他', 'node_id' => 3, 'node_list' => [
                    ['name' => '自由职业者', 'node_id' => 31, 'leaf_info' => ['requirement' => '需要提供相关职业证明']],
                ]],
            ];
        } else {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->authIdentityTree($token));
            if ((int) ($rs['errcode'] ?? -1) !== 0) {
                throw new BusinessException('读取失败：' . (string) ($rs['errmsg'] ?? 'unknown'));
            }
            $tree = (array) ($rs['identity_tree_list'] ?? []);
        }

        // 打平两级树（对齐参考系统前端展示结构：showname/deep/desc）
        $data = [];
        foreach ($tree as $c0) {
            $c0 = (array) $c0;
            $data[] = [
                'id'       => (int) ($c0['node_id'] ?? 0),
                'name'     => (string) ($c0['name'] ?? ''),
                'showname' => (string) ($c0['name'] ?? ''),
                'desc'     => (string) ($c0['leaf_info']['requirement'] ?? ''),
                'deep'     => 0,
            ];
            foreach ((array) ($c0['node_list'] ?? []) as $c1) {
                $c1 = (array) $c1;
                $data[] = [
                    'id'       => (int) ($c1['node_id'] ?? 0),
                    'name'     => (string) ($c1['name'] ?? ''),
                    'showname' => '　└ ' . (string) ($c1['name'] ?? ''),
                    'desc'     => (string) ($c1['leaf_info']['requirement'] ?? ''),
                    'deep'     => 1,
                ];
            }
        }

        return $data;
    }

    /* ==================== 任务状态查询（对标参考系统 queryTaskStatus） ==================== */

    /**
     * 查询认证进度并回写状态
     *
     * @return array<string, mixed> 最新订单状态
     */
    public function queryTaskStatus(int $aid, int $id): array
    {
        $set = $this->getSet($aid);
        if ((int) $set['status'] !== 1) {
            throw new BusinessException('系统设置不存在，或未开启');
        }
        $row = (array) (ScopeService::query('wx_proxyverify', $aid)->where('id', $id)->find() ?: []);
        if (empty($row)) {
            throw new BusinessException('该认证记录不存在');
        }
        if ('' === (string) $row['taskid']) {
            throw new BusinessException('该认证记录任务id不存在');
        }
        $applyStatus = (int) $row['apply_status'];
        if ($applyStatus < 0 || $applyStatus >= 4) {
            throw new BusinessException('该认证记录审核单状态不符');
        }

        if ($this->open->isMock()) {
            $update = $this->mockAdvanceStatus((int) $row['task_status'], $applyStatus, (string) $row['taskid'], (string) $row['authorizer_appid']);
        } else {
            $rs = $this->open->callWithAuthorizerToken(
                (string) $row['authorizer_appid'],
                fn (string $token) => $this->open->api()->queryAuthTask($token, (string) $row['taskid'])
            );
            if ((int) ($rs['errcode'] ?? -1) !== 0) {
                throw new BusinessException('查询失败：' . (string) ($rs['errmsg'] ?? 'unknown'));
            }
            $update = [
                'task_status'  => (int) ($rs['task_status'] ?? 0),
                'apply_status' => (int) ($rs['apply_status'] ?? 0),
            ];
            foreach (['auth_url', 'appid', 'refill_reason', 'fail_reason'] as $field) {
                if (!empty($rs[$field])) {
                    $update[$field] = (string) $rs[$field];
                }
            }
            if (!empty($rs['orderid'])) {
                $update['orderid'] = (string) $rs['orderid'];
            }
        }

        $update['updatetime'] = time();
        Db::name('wx_proxyverify')->where('id', $id)->update($update);

        // 认证通过 → 小程序认证状态置 2
        if ((int) $update['apply_status'] === 4) {
            Db::name('wx_proxyverify_authorizer')
                ->where('aid', $aid)
                ->where('authorizer_appid', $row['authorizer_appid'])
                ->whereIn('verify_status', [0, 1])
                ->update(['verify_status' => 2, 'updatetime' => time()]);
        }

        return $this->orderDetail($aid, $id);
    }

    /**
     * mock 状态推进：按状态链逐格前进（每次查询推进一步，模拟微信审核流转）
     *
     * @return array<string, mixed>
     */
    protected function mockAdvanceStatus(int $taskStatus, int $applyStatus, string $taskid, string $appid): array
    {
        $chain = self::MOCK_STATUS_CHAIN;
        $idx   = 0;
        foreach ($chain as $i => $state) {
            if ($state[0] === $taskStatus && $state[1] === $applyStatus) {
                $idx = $i;
                break;
            }
        }
        $next = $chain[min($idx + 1, count($chain) - 1)];

        $update = ['task_status' => $next[0], 'apply_status' => $next[1]];
        if ($next[1] >= 1) {
            $update['orderid'] = 'MOCK' . substr(md5($taskid), 0, 12);
            $update['appid']   = $appid;
        }
        if (4 === $next[1]) {
            $update['refill_reason'] = '';
            $update['fail_reason']   = '';
        }

        return $update;
    }

    /** 删除认证订单 */
    public function orderDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的记录');
        }
        ScopeService::query('wx_proxyverify', $aid)->whereIn('id', $ids)->delete();
    }

    /** 修改订单备注 */
    public function orderRemark(int $aid, int $id, string $remark): void
    {
        $row = ScopeService::query('wx_proxyverify', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('该认证记录不存在');
        }
        Db::name('wx_proxyverify')->where('id', $id)->update(['remark' => $remark, 'updatetime' => time()]);
    }

    /* ==================== 系统设置（三类代缴额度） ==================== */

    /**
     * 读取设置（不存在自动初始化）
     *
     * @return array<string, mixed>
     */
    public function getSet(int $aid): array
    {
        $row = Db::name('wx_proxyverify_set')->where('aid', $aid)->find();
        if (empty($row)) {
            $id  = Db::name('wx_proxyverify_set')->insertGetId(['aid' => $aid, 'createtime' => time(), 'updatetime' => time()]);
            $row = Db::name('wx_proxyverify_set')->where('id', $id)->find();
        }

        $row['is_mock'] = $this->open->isMock();

        return $row;
    }

    /**
     * 保存设置（status/service_appid/third_party_phone + 三类额度 all/used，num=all-used 自动计算）
     *
     * @param array<string, mixed> $info
     */
    public function saveSet(int $aid, array $info): void
    {
        $row = $this->getSet($aid);

        $save = [
            'status'             => !empty($info['status']) ? 1 : 0,
            'service_appid'      => trim((string) ($info['service_appid'] ?? '')),
            'third_party_phone'  => trim((string) ($info['third_party_phone'] ?? '')),
            'updatetime'         => time(),
        ];
        foreach ([['', ''], ['2', '2'], ['3', '3']] as [$suffix, $sfx]) {
            $all  = max(0, (int) ($info['all' . $suffix] ?? $row['all' . $suffix] ?? 0));
            $used = max(0, min((int) ($info['used' . $suffix] ?? $row['used' . $suffix] ?? 0), $all));
            $save['all' . $suffix]  = $all;
            $save['used' . $suffix] = $used;
            $save['num' . $suffix]  = $all - $used;
        }

        Db::name('wx_proxyverify_set')->where('id', $row['id'])->update($save);
    }

    /* ==================== 额度检查 / 扣减 ==================== */

    /** 服务商额度校验（平台设置额度 + 租户额度） */
    protected function checkQuota(int $aid, array $set, int $customerType): void
    {
        $suffix  = 1 === $customerType ? '' : (12 === $customerType ? '2' : '3');
        $num     = (int) ($set['num' . $suffix] ?? 0);
        $all     = (int) ($set['all' . $suffix] ?? 0);
        $name    = [1 => '企业', 12 => '个体工商户', 15 => '个人'][$customerType] ?? '';

        if ($num <= 0 || $all <= 0) {
            throw new BusinessException('系统设置中的' . $name . '当前可用代缴次数不足，无法下单');
        }

        $field   = 'wx_proxyverify_wxnum' . $suffix;
        $tenant  = (int) (Db::name('admin')->where('id', $aid)->value($field) ?: 0);
        if ($tenant <= 0) {
            throw new BusinessException($name . '认证额度不足，请联系客服购买');
        }
    }

    /** 扣减额度（平台设置 + 租户） */
    protected function decQuota(int $aid, int $customerType): void
    {
        $suffix = 1 === $customerType ? '' : (12 === $customerType ? '2' : '3');

        Db::name('wx_proxyverify_set')->where('aid', $aid)->dec('num' . $suffix, 1)->inc('used' . $suffix, 1)->update();
        Db::name('admin')->where('id', $aid)->dec('wx_proxyverify_wxnum' . $suffix, 1)->update();
    }
}
