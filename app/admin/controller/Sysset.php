<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;

/**
 * 租户后台 - 系统设置（admin_set 单行表，aid 唯一）
 *
 * 参考系统 admin_set 为超宽单行配置表，本接口仅开放白名单字段：
 * 基础/财务/积分/登录注册/文本自定义/加载图标等组。
 * GET 返回白名单字段（行不存在给表默认值）；POST 保存（行不存在兜底 insert）。
 * 注册协议（xyinfo）走独立表 admin_set_xieyi，随本接口一并读写。
 */
class Sysset extends BaseController
{
    /**
     * 白名单字段 => 类型（string/int/decimal）
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        // 基本信息
        'name'      => 'string',
        'logo'      => 'string',
        'desc'      => 'string',
        'tel'       => 'string',
        'province'  => 'string',
        'city'      => 'string',
        'district'  => 'string',
        'address'   => 'string',
        'longitude' => 'string',
        'latitude'  => 'string',
        'main_business'         => 'string',
        'official_account_status' => 'int',
        // 基础设置增量（任务308：关注提示/订单播报/导航色/前端访问条件）
        'guanzhu_tip'     => 'string',
        'order_broadcast' => 'string',
        'navbgmode'       => 'int',
        'navbgcolor'      => 'string',
        'navtextcolor'    => 'int',
        'visitperm'       => 'string',
        // 客服
        'kfurl'     => 'string',
        'wxkf'      => 'int',
        'wxkfurl'   => 'string',
        // 资金开关
        'moneypay'    => 'int',
        'recharge'    => 'int',
        'recharge_minimum' => 'decimal',
        'withdraw'    => 'int',
        'comwithdraw' => 'int',
        'withdrawmin' => 'decimal',
        'withdrawfee' => 'decimal',
        'withdrawmax' => 'int',
        'comwithdrawmin' => 'decimal',
        'comwithdrawmax' => 'int',
        'comwithdrawfee' => 'decimal',
        'day_withdraw_num' => 'int',
        'withdraw_desc'    => 'string',
        'withdraw_weixin'    => 'int',
        'withdraw_aliaccount' => 'int',
        'withdraw_bankcard'  => 'int',
        // 积分（score2money 为比例字符串，库列 varchar，不强转两位小数）
        'score2money'       => 'string',
        'scoredkmaxpercent' => 'decimal',
        'scorein_money'     => 'decimal',
        'scorein_score'     => 'int',
        'scorecz_money'     => 'decimal',
        'scorecz_score'     => 'int',
        'scorebdkyf'        => 'int',
        'score_from_moneypay' => 'int',
        'score_transfer'    => 'int',
        'score_expire_status' => 'int',
        'score_expire_days'   => 'int',
        // 登录注册
        'logintype_wx'    => 'string',
        'logintype_h5'    => 'string',
        'login_setnickname' => 'int',
        'login_bind'      => 'int',
        'reg_check'       => 'int',
        'reg_invite_code' => 'int',
        // 加载图标
        'loading_icon' => 'string',
        // 主题
        'color1' => 'string',
        'color2' => 'string',
        // 分销（口径对齐参考系统 backstage/sysset.html 分销板块）
        'fxjiesuantype'           => 'int',
        'fxjiesuantype_pj'        => 'int',
        'fxjiesuantime'           => 'int',
        'fxjiesuantime_delaydays' => 'decimal',
        'fxjiesuantime_perweek'   => 'int',
        'fx_differential'         => 'int',
        'commission_send_wallet'  => 'int',
        'commission_send_wallet_levelids' => 'string',
        // 发票
        'invoice'      => 'int',
        'invoice_type' => 'string',
        'invoice_rate' => 'decimal',
        // 关停
        'status'    => 'int',
        'close_tip' => 'string',
    ];

    /**
     * 行不存在时的兜底默认值（与建表 DEFAULT 对齐）
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'name'              => '',
        'logo'              => '',
        'desc'              => '',
        'tel'               => '',
        'province'          => '',
        'city'              => '',
        'district'          => '',
        'address'           => '',
        'longitude'         => '',
        'latitude'          => '',
        'main_business'     => '',
        'official_account_status' => 0,
        'guanzhu_tip'       => '',
        'order_broadcast'   => '1,2',
        'navbgmode'         => 0,
        'navbgcolor'        => '',
        'navtextcolor'      => 0,
        'visitperm'         => 'all',
        'kfurl'             => '',
        'wxkf'              => 0,
        'wxkfurl'           => '',
        'moneypay'          => 1,
        'recharge'          => 1,
        'recharge_minimum'  => '0.00',
        'withdraw'          => 1,
        'comwithdraw'       => 1,
        'withdrawmin'       => '1.00',
        'withdrawfee'       => '0.00',
        'withdrawmax'       => 0,
        'comwithdrawmin'    => '1.00',
        'comwithdrawmax'    => 0,
        'comwithdrawfee'    => '0.00',
        'day_withdraw_num'  => 0,
        'withdraw_desc'     => '',
        'withdraw_weixin'   => 1,
        'withdraw_aliaccount' => 0,
        'withdraw_bankcard' => 0,
        'score2money'       => '0.01',
        'scoredkmaxpercent' => '100.00',
        'scorein_money'     => '1.00',
        'scorein_score'     => 1,
        'scorecz_money'     => '1.00',
        'scorecz_score'     => 1,
        'scorebdkyf'        => 0,
        'score_from_moneypay' => 1,
        'score_transfer'    => 0,
        'score_expire_status' => 0,
        'score_expire_days' => 0,
        'logintype_wx'      => '1',
        'logintype_h5'      => '1',
        'login_setnickname' => 0,
        'login_bind'        => 0,
        'reg_check'         => 0,
        'reg_invite_code'   => 0,
        'loading_icon'      => '',
        'textset'           => '{"余额":"余额","积分":"积分","佣金":"佣金","优惠券":"优惠券","会员":"会员"}',
        'color1'            => '#FD4A46',
        'color2'            => '#7E71F6',
        'fxjiesuantype'           => 0,
        'fxjiesuantype_pj'        => 0,
        'fxjiesuantime'           => 0,
        'fxjiesuantime_delaydays' => '0.00',
        'fxjiesuantime_perweek'   => 0,
        'fx_differential'         => 0,
        'commission_send_wallet'  => 0,
        'commission_send_wallet_levelids' => '',
        'invoice'           => 0,
        'invoice_type'      => '1',
        'invoice_rate'      => '0.00',
        'status'            => 1,
        'close_tip'         => '',
    ];

    /**
     * 文本自定义默认键值对（canzhao：余额/积分/佣金/优惠券/会员）
     *
     * @var array<string, string>
     */
    private const TEXTSET_DEFAULT = [
        '余额'   => '余额',
        '积分'   => '积分',
        '佣金'   => '佣金',
        '优惠券' => '优惠券',
        '会员'   => '会员',
    ];

    /**
     * 注册协议默认值（admin_set_xieyi 表，与建表 DEFAULT 对齐）
     *
     * @var array<string, mixed>
     */
    private const XYINFO_DEFAULTS = [
        'status'     => 0,
        'agree_type' => 0,
        'name'       => '《用户注册协议》',
        'content'    => '',
        'name2'      => '',
        'content2'   => '',
    ];

    /**
     * 读取系统设置
     * GET /admin/sysset
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        $row = ScopeService::query('admin_set', $aid)->find();

        $data = self::DEFAULTS;
        if (!empty($row)) {
            foreach (self::FIELDS as $field => $type) {
                $data[$field] = $this->castValue($row[$field] ?? null, $type, self::DEFAULTS[$field]);
            }
            $data['textset'] = $this->decodeTextset($row['textset'] ?? null);
        } else {
            $data['textset'] = self::TEXTSET_DEFAULT;
        }

        // 注册协议（独立表 admin_set_xieyi）
        $data['xyinfo'] = $this->readXyinfo($aid);

        return $this->success($data);
    }

    /**
     * 保存系统设置（行不存在兜底 insert，存在仅更新白名单字段）
     * POST /admin/sysset
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $post = $this->request->post();
        if (!is_array($post)) {
            $post = [];
        }

        $data = [];
        foreach (self::FIELDS as $field => $type) {
            if (!array_key_exists($field, $post)) {
                continue;
            }
            $data[$field] = $this->castValue($post[$field], $type, self::DEFAULTS[$field]);
        }

        // 文本自定义：提交对象或 JSON 串，落库统一 JSON 字符串
        if (array_key_exists('textset', $post)) {
            $data['textset'] = $this->encodeTextset($post['textset']);
        }

        if ('' === trim((string) ($data['name'] ?? $post['name'] ?? ''))) {
            // name 未提交时不校验；提交了空串才拦截
            if (array_key_exists('name', $post)) {
                throw new BusinessException('商城名称不能为空');
            }
        }

        $exists = ScopeService::query('admin_set', $aid)->find();
        if (empty($exists)) {
            // 兜底初始化：默认值 + 本次提交值一并落库
            $insert = array_merge(self::DEFAULTS, $data, ['aid' => $aid]);
            ScopeService::query('admin_set', $aid)->insert($insert);
        } else {
            if (!empty($data)) {
                ScopeService::query('admin_set', $aid)
                    ->where('id', (int) $exists['id'])
                    ->update($data);
            }
        }

        // 注册协议（独立表 admin_set_xieyi，update-or-insert）
        if (isset($post['xyinfo']) && is_array($post['xyinfo'])) {
            $this->saveXyinfo($aid, $post['xyinfo']);
        }

        return $this->success([], '保存成功');
    }

    /**
     * 读取注册协议（行不存在给默认值）
     *
     * @return array<string, mixed>
     */
    private function readXyinfo(int $aid): array
    {
        $row = ScopeService::query('admin_set_xieyi', $aid)->find();
        $xyinfo = self::XYINFO_DEFAULTS;
        if (!empty($row)) {
            $xyinfo['status']     = (int) ($row['status'] ?? 0);
            $xyinfo['agree_type'] = (int) ($row['agree_type'] ?? 0);
            $xyinfo['name']       = (string) ($row['name'] ?? '');
            $xyinfo['content']    = (string) ($row['content'] ?? '');
            $xyinfo['name2']      = (string) ($row['name2'] ?? '');
            $xyinfo['content2']   = (string) ($row['content2'] ?? '');
        }

        return $xyinfo;
    }

    /**
     * 保存注册协议（白名单过滤后 update-or-insert）
     *
     * @param array<string, mixed> $input
     */
    private function saveXyinfo(int $aid, array $input): void
    {
        $data = [
            'status'     => (int) ($input['status'] ?? 0),
            'agree_type' => (int) ($input['agree_type'] ?? 0),
            'name'       => (string) ($input['name'] ?? self::XYINFO_DEFAULTS['name']),
            'content'    => (string) ($input['content'] ?? ''),
            'name2'      => (string) ($input['name2'] ?? ''),
            'content2'   => (string) ($input['content2'] ?? ''),
        ];

        $exists = ScopeService::query('admin_set_xieyi', $aid)->find();
        if (empty($exists)) {
            ScopeService::query('admin_set_xieyi', $aid)->insert(array_merge($data, ['aid' => $aid]));
        } else {
            ScopeService::query('admin_set_xieyi', $aid)
                ->where('id', (int) $exists['id'])
                ->update($data);
        }
    }

    /**
     * textset 读取：JSON 字符串转数组（非法/空回默认键值对）
     *
     * @return array<string, string>
     */
    private function decodeTextset(mixed $raw): array
    {
        if (!is_string($raw) || '' === trim($raw)) {
            return self::TEXTSET_DEFAULT;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('strval', $decoded) : self::TEXTSET_DEFAULT;
    }

    /**
     * textset 保存：对象/JSON 串统一编码落库（非法回默认键值对）
     */
    private function encodeTextset(mixed $value): string
    {
        if (is_string($value) && '' !== trim($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value) || [] === $value) {
            $value = self::TEXTSET_DEFAULT;
        }

        return (string) json_encode(array_map('strval', $value), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 按字段类型转换值（空值回默认）
     */
    private function castValue(mixed $value, string $type, mixed $default): mixed
    {
        if (null === $value || '' === $value) {
            // 开关/数值类字段空值回默认，避免落库 NULL 影响前端回显
            if ('string' !== $type) {
                return $default;
            }

            return '';
        }

        return match ($type) {
            'int'     => (int) $value,
            'decimal' => number_format((float) $value, 2, '.', ''),
            default   => (string) $value,
        };
    }
}
