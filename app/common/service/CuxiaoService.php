<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 商品促销服务（批次4-A 任务186）
 *
 * 表口径：cuxiao（时间 varchar datetime）
 * - type 0仅显示 1满减 2满赠 3换购
 * - gettj 参与人群（'-1'=全部，逗号分隔会员等级ID）
 * - fwtype 范围 0全部 1分类(categoryids 逗号) 2指定商品(productids 逗号)
 * - minprice 满额门槛 / minnum 最低件数 / money 满减额度或换购加价 / proid+ggid 赠品或换购品
 * - tip 提示文案（C 端展示）
 *
 * 下单算价口径（对标参考系统 ApiShop.php cuxiao 段）：
 * - type1 满减：商品金额>=minprice 且 num>=minnum → 减 money（多个活动累加）
 * - type2 满赠：满足门槛 → 追加赠品行（gtype=1，价格 0，不扣库存）
 * - type3 换购：满足门槛且用户勾选换购 → 追加换购行（gtype=2，价格=money 加价金额，扣库存）
 * - 与满减促销 promotion 表并行生效（两个独立模块，先 promotion 择优再 cuxiao）
 */
class CuxiaoService
{
    public const TYPE_SHOW                = 0; // 无（仅显示活动名称到商品详情）
    public const TYPE_MANJIAN             = 1; // 满额立减
    public const TYPE_MANZENG             = 2; // 满额赠送
    public const TYPE_HUANGOU             = 3; // 加价换购
    public const TYPE_MANJIAN_ZHEKOU      = 4; // 满额打折
    public const TYPE_MANJIAN_DISCOUNT    = 5; // 满件打折
    public const TYPE_MANJIAN_LIJIAN      = 6; // 满件立减

    /* ================= admin 租户后台侧 ================= */

    /**
     * 活动列表（keyword 匹配名称；type 空=全部）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminList(int $aid, string $keyword, string $type, int $page, int $pageSize): array
    {
        $query = ScopeService::query('cuxiao', $aid);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        if ('' !== $type) {
            $query->where('type', (int) $type);
        }

        $total = (clone $query)->count();

        $rows = $query->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->format($aid, $row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 活动详情
     *
     * @return array<string, mixed>
     */
    public function detail(int $aid, int $id): array
    {
        return $this->format($aid, $this->findOne($aid, $id));
    }

    /**
     * 新建活动
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function add(int $aid, array $data): array
    {
        $row = $this->buildData($data);

        $row['aid']        = $aid;
        $row['bid']        = 0;
        $row['status']     = 1;
        $row['createtime'] = time();

        return ['id' => (int) Db::name('cuxiao')->insertGetId($row)];
    }

    /**
     * 编辑活动
     *
     * @param array<string, mixed> $data
     */
    public function edit(int $aid, array $data): void
    {
        $id = (int) ($data['id'] ?? 0);

        $this->findOne($aid, $id);

        $row = $this->buildData($data);

        if (array_key_exists('status', $data)) {
            $status = (int) $data['status'];

            if (!in_array($status, [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $row['status'] = $status;
        }

        Db::name('cuxiao')->where('id', $id)->where('aid', $aid)->update($row);
    }

    /**
     * 启用/停用
     */
    public function status(int $aid, int $id, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $this->findOne($aid, $id);

        Db::name('cuxiao')->where('id', $id)->where('aid', $aid)->update(['status' => $status]);
    }

    /**
     * 删除活动（物理删除）
     */
    public function delete(int $aid, int $id): void
    {
        $this->findOne($aid, $id);

        Db::name('cuxiao')->where('id', $id)->where('aid', $aid)->delete();
    }

    /* ================= api 侧 ================= */

    /**
     * 生效中活动列表（C 端展示；proid>0 时仅返回覆盖该商品的活动）
     *
     * @return array{list: array}
     */
    public function apiActiveList(int $aid, int $proid): array
    {
        $rows = $this->activeRows($aid, time());

        $list = [];

        foreach ($rows as $row) {
            if ($proid > 0 && !$this->matchScope($row, $proid, '')) {
                continue;
            }

            $list[] = $this->format($aid, $row);
        }

        return ['list' => $list];
    }

    /* ================= 订单计价联动 ================= */

    /**
     * 下单算价：匹配生效活动并计算满减额/赠品行/换购行
     *
     * @param array<string, mixed> $goods 商品行（取 id/cid）
     * @param array<string, mixed>|null $memberLevel 会员行（取 levelid，可为 null=匿名按全部人群）
     * @return array{cuxiao_ids: array<int,int>, cuxiao_money: float, gift: array<string,int>|null, huangou: array<string,mixed>|null}
     */
    public function applyForOrder(int $aid, array $goods, ?array $member, int $num, float $productPrice, bool $wantHuango): array
    {
        $result = ['cuxiao_ids' => [], 'cuxiao_money' => 0.0, 'gift' => null, 'huangou' => null];

        $proid = (int) ($goods['id'] ?? 0);
        $cid   = (string) ($goods['cid'] ?? '');
        $levelId = null !== $member ? (int) ($member['levelid'] ?? 0) : -1;

        $rows = $this->activeRows($aid, time());

        foreach ($rows as $row) {
            $type = (int) ($row['type'] ?? 0);

            if (self::TYPE_SHOW === $type) {
                continue; // 仅显示不参与算价
            }

            // 人群条件：-1 全部；否则会员等级命中（匿名浏览不参与人群限制活动）
            if (!$this->matchGettj((string) ($row['gettj'] ?? '-1'), $levelId)) {
                continue;
            }

            // 范围条件
            if (!$this->matchScope($row, $proid, $cid)) {
                continue;
            }

            // 门槛条件：满额 + 最低件数
            if ($productPrice < (float) ($row['minprice'] ?? 0)) {
                continue;
            }

            if ($num < max(1, (int) ($row['minnum'] ?? 1))) {
                continue;
            }

            if (self::TYPE_MANJIAN === $type) {
                $cut = round((float) ($row['money'] ?? 0), 2);

                if ($cut > 0) {
                    $result['cuxiao_money'] = round($result['cuxiao_money'] + $cut, 2);
                    $result['cuxiao_ids'][] = (int) $row['id'];
                }
            } elseif (self::TYPE_MANZENG === $type) {
                // 满赠：只取首个满足的赠品行（避免多活动叠加赠品失控）
                if (null === $result['gift'] && (int) ($row['proid'] ?? 0) > 0) {
                    $result['gift'] = [
                        'proid' => (int) $row['proid'],
                        'ggid'  => (int) ($row['ggid'] ?? 0),
                        'num'   => 1,
                    ];
                    $result['cuxiao_ids'][] = (int) $row['id'];
                }
            } elseif (self::TYPE_HUANGOU === $type) {
                // 换购：用户勾选时生效，只取首个满足的换购行
                if ($wantHuango && null === $result['huangou'] && (int) ($row['proid'] ?? 0) > 0) {
                    $result['huangou'] = [
                        'proid' => (int) $row['proid'],
                        'ggid'  => (int) ($row['ggid'] ?? 0),
                        'num'   => 1,
                        'price' => round((float) ($row['money'] ?? 0), 2),
                    ];
                    $result['cuxiao_ids'][] = (int) $row['id'];
                }
            }
        }

        // 满减额不超过商品金额-0.01（实付下限由订单侧保证）
        if ($result['cuxiao_money'] > $productPrice) {
            $result['cuxiao_money'] = round($productPrice, 2);
        }

        return $result;
    }

    /* ================= 内部工具 ================= */

    /**
     * 生效中活动行（status=1 且当前时间在起止时间内；时间空=不限）
     *
     * @return array<int, array<string, mixed>>
     */
    protected function activeRows(int $aid, int $now): array
    {
        $rows = ScopeService::query('cuxiao', $aid)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $today = date('Y-m-d H:i:s', $now);
        $active = [];

        foreach ($rows as $row) {
            $start = trim((string) ($row['starttime'] ?? ''));
            $end   = trim((string) ($row['endtime'] ?? ''));

            if ('' !== $start && strcmp($today, $start) < 0) {
                continue;
            }
            if ('' !== $end && strcmp($today, $end) > 0) {
                continue;
            }

            $active[] = $row;
        }

        return $active;
    }

    /**
     * 人群条件匹配（-1/空=全部；levelId<0 匿名视为不命中受限人群）
     */
    protected function matchGettj(string $gettj, int $levelId): bool
    {
        $gettj = trim($gettj);

        if ('' === $gettj || '-1' === $gettj) {
            return true;
        }

        if ($levelId < 0) {
            return false;
        }

        $ids = array_filter(array_map('intval', explode(',', $gettj)));

        return in_array($levelId, $ids, true);
    }

    /**
     * 范围条件匹配（fwtype 0全部 1分类 2指定商品）
     *
     * @param array<string, mixed> $row
     */
    protected function matchScope(array $row, int $proid, string $cid): bool
    {
        $fwtype = (int) ($row['fwtype'] ?? 0);

        if (1 === $fwtype) {
            $categoryIds = array_filter(array_map('trim', explode(',', (string) ($row['categoryids'] ?? ''))));

            if (empty($categoryIds)) {
                return false;
            }

            // 商品 cid 可能为单值或逗号多值
            $productCids = array_filter(array_map('trim', explode(',', $cid)));

            foreach ($productCids as $productCid) {
                if (in_array($productCid, $categoryIds, true)) {
                    return true;
                }
            }

            return false;
        }

        if (2 === $fwtype) {
            $productIds = array_filter(array_map('intval', explode(',', (string) ($row['productids'] ?? ''))));

            return in_array($proid, $productIds, true);
        }

        return true;
    }

    /**
     * 保存参数组装与校验
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function buildData(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写活动名称');
        }

        $type = (int) ($data['type'] ?? 1);

        $allowedTypes = [
            self::TYPE_SHOW,
            self::TYPE_MANJIAN,
            self::TYPE_MANZENG,
            self::TYPE_HUANGOU,
            self::TYPE_MANJIAN_ZHEKOU,
            self::TYPE_MANJIAN_DISCOUNT,
            self::TYPE_MANJIAN_LIJIAN,
        ];

        if (!in_array($type, $allowedTypes, true)) {
            throw new BusinessException('活动类型无效');
        }

        $starttime = trim((string) ($data['starttime'] ?? ''));
        $endtime   = trim((string) ($data['endtime'] ?? ''));

        if ('' === $starttime || '' === $endtime) {
            throw new BusinessException('请填写活动起止时间');
        }

        $startTs = strtotime($starttime);
        $endTs   = strtotime($endtime);

        if (false === $startTs || false === $endTs) {
            throw new BusinessException('起止时间格式不正确');
        }
        if ($endTs <= $startTs) {
            throw new BusinessException('结束时间必须晚于开始时间');
        }

        $fwtype = (int) ($data['fwtype'] ?? 0);

        if (!in_array($fwtype, [0, 1, 2], true)) {
            throw new BusinessException('适用范围类型无效');
        }

        $categoryids = trim((string) ($data['categoryids'] ?? ''));
        $productids  = trim((string) ($data['productids'] ?? ''));

        if (1 === $fwtype && '' === $categoryids) {
            throw new BusinessException('请选择适用分类');
        }
        if (2 === $fwtype && '' === $productids) {
            throw new BusinessException('请选择适用商品');
        }

        $minprice = round((float) ($data['minprice'] ?? 0), 2);
        $minnum   = max(0, (int) ($data['minnum'] ?? 1));
        $money    = round((float) ($data['money'] ?? 0), 2);
        $zhekou   = round((float) ($data['zhekou'] ?? 0), 2);
        $proid    = (int) ($data['proid'] ?? 0);
        $ggid     = (int) ($data['ggid'] ?? 0);

        // 指定商品单独折扣/单独件数（逗号字符串，与 productids 顺序对齐）
        $prozk  = trim((string) ($data['prozk'] ?? ''));
        $pronum = trim((string) ($data['pronum'] ?? ''));

        if (self::TYPE_MANJIAN === $type) {
            if ($minprice <= 0) {
                throw new BusinessException('满减活动必须填写满额门槛');
            }
            if ($money <= 0) {
                throw new BusinessException('满减活动必须填写减免金额');
            }
            if ($money > $minprice) {
                throw new BusinessException('减免金额不能大于满额门槛');
            }
            $proid = 0;
            $ggid  = 0;
        } elseif (self::TYPE_MANZENG === $type) {
            if ($minprice <= 0) {
                throw new BusinessException('满赠活动必须填写满额门槛');
            }
            if ($proid <= 0) {
                throw new BusinessException('满赠活动必须选择赠送商品');
            }
            $money = 0.0;
        } elseif (self::TYPE_HUANGOU === $type) {
            if ($minprice <= 0) {
                throw new BusinessException('换购活动必须填写满额门槛');
            }
            if ($proid <= 0) {
                throw new BusinessException('换购活动必须选择换购商品');
            }
            if ($money < 0) {
                throw new BusinessException('换购加价金额不能为负数');
            }
        } elseif (self::TYPE_MANJIAN_ZHEKOU === $type) {
            if ($minprice <= 0) {
                throw new BusinessException('满额打折活动必须填写满额门槛');
            }
            if ($zhekou <= 0 || $zhekou > 9.99) {
                throw new BusinessException('折扣须为0.01~9.99的数字');
            }
            $money = 0.0;
            $proid = 0;
            $ggid  = 0;
        } elseif (self::TYPE_MANJIAN_DISCOUNT === $type) {
            if ($minnum <= 0) {
                throw new BusinessException('满件打折活动必须填写最低购买件数');
            }
            if ($zhekou <= 0 || $zhekou > 9.99) {
                throw new BusinessException('折扣须为0.01~9.99的数字');
            }
            $minprice = 0.0;
            $money    = 0.0;
            $proid    = 0;
            $ggid     = 0;
        } elseif (self::TYPE_MANJIAN_LIJIAN === $type) {
            if ($minnum <= 0) {
                throw new BusinessException('满件立减活动必须填写最低购买件数');
            }
            if ($money <= 0) {
                throw new BusinessException('满件立减活动必须填写立减金额');
            }
            $minprice = 0.0;
            $proid    = 0;
            $ggid     = 0;
        } else {
            // 无：仅显示活动名称，无算价参数
            $minprice = 0.0;
            $minnum   = 0;
            $money    = 0.0;
            $zhekou   = 0.0;
            $proid    = 0;
            $ggid     = 0;
        }

        return [
            'name'        => $name,
            'type'        => $type,
            'tip'         => trim((string) ($data['tip'] ?? '')),
            'gettj'       => trim((string) ($data['gettj'] ?? '-1')) ?: '-1',
            'fwtype'      => $fwtype,
            'categoryids' => $categoryids,
            'productids'  => $productids,
            'minprice'    => $minprice,
            'minnum'      => $minnum,
            'money'       => $money,
            'zhekou'      => $zhekou,
            'proid'       => $proid,
            'ggid'        => $ggid,
            'prozk'       => $prozk,
            'pronum'      => $pronum,
            'starttime'   => date('Y-m-d H:i:s', $startTs),
            'endtime'     => date('Y-m-d H:i:s', $endTs),
            'sort'        => (int) ($data['sort'] ?? 0),
        ];
    }

    /**
     * 查找本租户活动（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findOne(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少活动ID');
        }

        $row = ScopeService::query('cuxiao', $aid)->where('id', $id)->find();

        if (empty($row)) {
            throw new BusinessException('活动不存在', 404, 404);
        }

        return $row;
    }

    /**
     * 活动行格式化（附赠品/换购品名称便于后台展示）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function format(int $aid, array $row): array
    {
        $proName = '';
        $proid = (int) ($row['proid'] ?? 0);

        if ($proid > 0) {
            $proName = (string) ScopeService::query('shop_product', $aid)->where('id', $proid)->value('name');
        }

        return [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'type'        => (int) ($row['type'] ?? 1),
            'tip'         => (string) ($row['tip'] ?? ''),
            'gettj'       => (string) ($row['gettj'] ?? '-1'),
            'fwtype'      => (int) ($row['fwtype'] ?? 0),
            'categoryids' => (string) ($row['categoryids'] ?? ''),
            'productids'  => (string) ($row['productids'] ?? ''),
            'minprice'    => (float) ($row['minprice'] ?? 0),
            'minnum'      => (int) ($row['minnum'] ?? 1),
            'money'       => (float) ($row['money'] ?? 0),
            'zhekou'      => (float) ($row['zhekou'] ?? 0),
            'proid'       => $proid,
            'ggid'        => (int) ($row['ggid'] ?? 0),
            'proname'     => $proName,
            'prozk'       => (string) ($row['prozk'] ?? ''),
            'pronum'      => (string) ($row['pronum'] ?? ''),
            'starttime'   => (string) ($row['starttime'] ?? ''),
            'endtime'     => (string) ($row['endtime'] ?? ''),
            'sort'        => (int) ($row['sort'] ?? 0),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0),
        ];
    }
}
