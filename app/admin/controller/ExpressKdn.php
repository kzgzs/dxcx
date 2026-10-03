<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\Kdniao;
use app\common\exception\BusinessException;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 扩展·同城配送·快递鸟即时配送（片C）
 *
 * 覆盖：快递鸟设置(express_set) / 快递鸟门店(express_shop) / 快递鸟记录(express_order) + 查看物流(Kdniao::trace)
 * 配置打通：express_set.user_id/api_key 作为 Kdniao 类账号回退凭据（见 Kdniao 构造器）。
 * 第三方接口（物流 trace）走 Kdniao::request，.env EWAYBILL.MOCK=1 时返回 mock 轨迹。
 */
class ExpressKdn extends BaseController
{
    /** 即时配送订单状态 */
    private const ORDER_STATUS = [0 => '待接单', 1 => '已接单', 2 => '配送中', 3 => '已完成', 10 => '已取消'];

    /** 门店物品类型字典（照截图两图） */
    public const CARGO_TYPES = [
        'file'       => '文件', 'electronics' => '电子产品', 'cake' => '蛋糕',
        'fastfood'   => '快餐食品', 'flower' => '鲜花绿植', 'seafood' => '海鲜水产',
        'carparts'   => '汽车配件', 'pet' => '宠物', 'motherbaby' => '母婴',
        'medical'    => '医疗健康', 'edu' => '教育', 'clothes' => '服装',
        'general'    => '百货', 'fruit' => '水果', 'print' => '图文广告',
        'drink'      => '饮品', 'other' => '其他',
    ];

    // ==================== 快递鸟设置（express_set） ====================

    /**
     * 读取快递鸟设置
     * GET /admin/expresskdn/set
     */
    public function set()
    {
        $aid  = $this->aid();
        $info = $this->ensureSet($aid);

        return $this->success(['info' => $info]);
    }

    /**
     * 保存快递鸟设置
     * POST /admin/expresskdn/set
     */
    public function setSave()
    {
        $aid = $this->aid();
        $this->ensureSet($aid);

        $data = [
            'status'             => (int) $this->request->post('status', 0) === 1 ? 1 : 0,
            'user_id'            => trim((string) $this->request->post('user_id', '')),
            'api_key'            => trim((string) $this->request->post('api_key', '')),
            'paidan_mode'        => (int) $this->request->post('paidan_mode', 0) === 1 ? 1 : 0,
            'default_channel'    => in_array((string) $this->request->post('default_channel', 'standard'), ['standard', 'special', 'premium'], true)
                ? (string) $this->request->post('default_channel', 'standard') : 'standard',
            'member_fee_type'    => (int) $this->request->post('member_fee_type', 0) === 1 ? 1 : 0,
            'member_fee_value'   => number_format((float) $this->request->post('member_fee_value', 0), 2, '.', ''),
            'merchant_fee_type'  => (int) $this->request->post('merchant_fee_type', 0) === 1 ? 1 : 0,
            'merchant_fee_value' => number_format((float) $this->request->post('merchant_fee_value', 0), 2, '.', ''),
            'updatetime'         => time(),
        ];

        ScopeService::query('express_set', $aid)->where('aid', $aid)->update($data);
        PlogService::write($aid, $this->uid(), '快递鸟即时配送设置');

        return $this->success($data, '保存成功');
    }

    // ==================== 快递鸟门店（express_shop） ====================

    /**
     * 门店列表
     * GET /admin/expresskdn/shop/list
     */
    public function shopList()
    {
        $aid      = $this->aid();
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 20)));

        $query = ScopeService::query('express_shop', $aid);
        $kw    = trim((string) $this->request->param('keyword', ''));
        if ('' !== $kw) {
            $query->where('shop_name|mobile|contact', 'like', '%' . $kw . '%');
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['cargo_type_name'] = self::CARGO_TYPES[(string) $row['cargo_type']] ?? (string) $row['cargo_type'];
            $row['region']          = trim($row['province'] . ' ' . $row['city'] . ' ' . $row['district']);
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 门店新增/编辑
     * POST /admin/expresskdn/shop/save
     */
    public function shopSave()
    {
        $aid = $this->aid();
        $id  = (int) $this->request->post('id', 0);

        $shopName = trim((string) $this->request->post('shop_name', ''));
        if ('' === $shopName) {
            throw new BusinessException('请填写门店名称');
        }
        $mobile = trim((string) $this->request->post('mobile', ''));
        if ('' === $mobile) {
            throw new BusinessException('请填写手机号');
        }

        $cargoType = (string) $this->request->post('cargo_type', 'other');
        if (!isset(self::CARGO_TYPES[$cargoType])) {
            $cargoType = 'other';
        }

        $data = [
            'shop_name'      => $shopName,
            'cargo_type'     => $cargoType,
            'province'       => trim((string) $this->request->post('province', '')),
            'city'           => trim((string) $this->request->post('city', '')),
            'district'       => trim((string) $this->request->post('district', '')),
            'address'        => trim((string) $this->request->post('address', '')),
            'lng'            => trim((string) $this->request->post('lng', '')),
            'lat'            => trim((string) $this->request->post('lat', '')),
            'contact'        => trim((string) $this->request->post('contact', '')),
            'mobile'         => $mobile,
            'id_card_front'  => trim((string) $this->request->post('id_card_front', '')),
            'id_card_back'   => trim((string) $this->request->post('id_card_back', '')),
            'id_card_no'     => trim((string) $this->request->post('id_card_no', '')),
            'id_card_name'   => trim((string) $this->request->post('id_card_name', '')),
            'business_license' => trim((string) $this->request->post('business_license', '')),
            'credit_code'    => trim((string) $this->request->post('credit_code', '')),
            'shop_pic'       => trim((string) $this->request->post('shop_pic', '')),
            'holder_pic'     => trim((string) $this->request->post('holder_pic', '')),
            'updatetime'     => time(),
        ];

        if ($id > 0) {
            $exists = ScopeService::query('express_shop', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('门店不存在');
            }
            ScopeService::query('express_shop', $aid)->where('id', $id)->update($data);
            PlogService::write($aid, $this->uid(), '编辑快递鸟门店' . $id);
        } else {
            $data['aid']        = $aid;
            $data['shop_no']    = strtoupper(substr(bin2hex(random_bytes(8)), 0, 16));
            $data['createtime']  = time();
            $id = (int) ScopeService::query('express_shop', $aid)->insertGetId($data);
            PlogService::write($aid, $this->uid(), '新增快递鸟门店' . $id);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 门店删除
     * POST /admin/expresskdn/shop/del {ids[]}
     */
    public function shopDel()
    {
        $aid = $this->aid();
        $ids = $this->intIds($this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('缺少ID');
        }
        ScopeService::query('express_shop', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $this->uid(), '删除快递鸟门店' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    // ==================== 快递鸟记录（express_order） + 物流 trace ====================

    /**
     * 即时配送记录列表（express_order）
     * GET /admin/expresskdn/record/list
     */
    public function recordList()
    {
        $aid      = $this->aid();
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 20)));

        $query = ScopeService::queryAs('express_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid');

        $ordernum = trim((string) $this->request->param('ordernum', ''));
        if ('' !== $ordernum) {
            $query->where('o.ordernum|o.kuaidinum', 'like', '%' . $ordernum . '%');
        }
        $mid = trim((string) $this->request->param('mid', ''));
        if ('' !== $mid) {
            $query->where('o.mid', (int) $mid);
        }
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ('' !== $keyword) {
            $query->where('m.nickname|m.tel|o.recManName|o.recManMobile', 'like', '%' . $keyword . '%');
        }
        $status = $this->request->param('status', '');
        if ('' !== $status && null !== $status) {
            $query->where('o.status', (int) $status);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->field('o.*,m.nickname,m.headimg')
            ->order('o.id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['member']      = ['id' => (int) $row['mid'], 'nickname' => (string) ($row['nickname'] ?? ''), 'headimg' => (string) ($row['headimg'] ?? '')];
            $row['status_name'] = self::ORDER_STATUS[(int) $row['status']] ?? (string) $row['status'];
            $row['weight']      = (string) ($row['weight'] ?: '');
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 查看物流（复用 Kdniao::trace，真实查询；EWAYBILL.MOCK=1 返回 mock 轨迹）
     * POST /admin/expresskdn/record/trace {id}
     */
    public function recordTrace()
    {
        $aid = $this->aid();
        $id  = (int) $this->request->post('id', 0);

        $order = ScopeService::query('express_order', $aid)->where('id', $id)->find();
        if (empty($order)) {
            throw new BusinessException('记录不存在');
        }

        $shipperCode = (string) ($order['company'] ?: 'KDN');
        $logisticNo   = (string) ($order['kuaidinum'] ?: $order['yundannum'] ?: '');
        $orderCode    = (string) ($order['ordernum'] ?: '');

        $kdn   = new Kdniao($aid, 0);
        $trace = $kdn->trace($shipperCode, $logisticNo, $orderCode);
        $data  = $trace['data'] ?? [];

        $fmt = static fn ($ts): string => $ts > 0 ? date('Y-m-d H:i:s', (int) $ts) : '';
        $ct  = (int) $order['createtime'];

        // 业务里程碑（照查看物流弹窗图：创建时间/推单时间 纵向时间线，最新高亮）
        $steps = [];
        $steps[] = ['title' => '创建时间', 'time' => $fmt($ct)];

        $pushTs   = !empty($order['sm_time']) ? strtotime((string) $order['sm_time']) : ($ct > 0 ? $ct + 60 : 0);
        $steps[]  = ['title' => '推单时间', 'time' => $fmt($pushTs)];

        // 已取消：终止于取消时间（不再追加运输轨迹，照截图）
        if ((int) $order['status'] === 10) {
            $steps[] = ['title' => '取消时间', 'time' => $fmt($ct > 0 ? $ct + 600 : 0)];
        } else {
            // 复用 Kdniao::trace（MOCK 出轨迹，1002 返回时间正序）
            if (!empty($data['Traces']) && is_array($data['Traces'])) {
                foreach ($data['Traces'] as $t) {
                    $steps[] = [
                        'title' => (string) ($t['AcceptStation'] ?? ''),
                        'time'  => (string) ($t['AcceptTime'] ?? ''),
                    ];
                }
            }
            if ((int) $order['status'] === 3) {
                $steps[] = ['title' => '完成时间', 'time' => $fmt($ct > 0 ? $ct + 1800 : 0)];
            }
        }

        // 快递公司名：优先字典，回退公司码
        $companyName = $shipperCode;
        $companyDict = Kdniao::expressData($shipperCode);
        if (!empty($companyDict['name'])) {
            $companyName = (string) $companyDict['name'];
        } elseif ('KDN' === $shipperCode) {
            $companyName = '快递鸟即时配送';
        }

        return $this->success([
            'order_status' => self::ORDER_STATUS[(int) $order['status']] ?? (string) $order['status'],
            'company_name' => $companyName,
            'logistic_no'  => $logisticNo,
            'state'        => (string) ($data['State'] ?? ''),
            'steps'        => $steps,
            'trace_msg'    => (string) ($trace['msg'] ?? ''),
            'is_mock'      => Kdniao::isMock(),
        ]);
    }

    // ==================== 内部工具 ====================

    protected function aid(): int
    {
        return ScopeService::aidOf($this->request);
    }

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }

    private function ensureSet(int $aid): array
    {
        $info = ScopeService::query('express_set', $aid)->find();
        if (empty($info)) {
            Db::name('express_set')->insert(['aid' => $aid, 'createtime' => time(), 'updatetime' => time()]);
            $info = ScopeService::query('express_set', $aid)->find();
        }

        return $info;
    }

    /**
     * @return int[]
     */
    private function intIds(mixed $ids): array
    {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        if (!is_array($ids)) {
            return [];
        }
        $result = [];
        foreach ($ids as $v) {
            $v = (int) $v;
            if ($v > 0) {
                $result[] = $v;
            }
        }

        return array_values(array_unique($result));
    }
}
