<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;
use think\facade\Log;

/**
 * 购物返现服务（批次4-A 任务186）
 *
 * 表口径：
 * - cashback：back_ratio 返还比率（%）、back_type 1余额 2佣金 3积分、gettj 人群、
 *   fwtype/categoryids/productids 范围、minprice 商品单价门槛、receiver_type 受益人（1自己）、
 *   goods_multiple_max 返现限额=商品售价×N倍（0 不限制）、starttime/endtime Unix 秒
 * - cashback_member：参与台账（按 会员+活动+商品 聚合，cashback_money 累计已返、cashback_money_max 限额）
 * - cashback_member_log：逐笔返现流水
 *
 * 触发链（对标参考系统 Order.php::dealcashback@10693）：
 * 确认收货 OrderService::confirm → dealCashback（独立 try/catch 钩子）：
 *   逐商品行（gtype=0）匹配规则 → back_price = 行实付×比率/100 → 台账限额裁剪 → 按 back_type 发放 → 落台账/流水
 */
class CashbackService
{
    public const BACK_MONEY      = 1; // 返余额
    public const BACK_COMMISSION = 2; // 返佣金
    public const BACK_SCORE      = 3; // 返积分

    /* ================= admin 租户后台侧 ================= */

    /**
     * 规则列表（keyword 匹配名称；status 空=全部）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminList(int $aid, string $keyword, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('cashback', $aid);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->format($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 规则详情
     *
     * @return array<string, mixed>
     */
    public function detail(int $aid, int $id): array
    {
        return $this->format($this->findOne($aid, $id));
    }

    /**
     * 新建规则
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

        return ['id' => (int) Db::name('cashback')->insertGetId($row)];
    }

    /**
     * 编辑规则
     *
     * @param array<string, mixed> $data
     */
    public function edit(int $aid, array $data): void
    {
        $id = (int) ($data['id'] ?? 0);

        $current = $this->findOne($aid, $id);

        $row = $this->buildData($data, $current);

        if (array_key_exists('status', $data)) {
            $status = (int) $data['status'];

            if (!in_array($status, [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $row['status'] = $status;
        }

        Db::name('cashback')->where('id', $id)->where('aid', $aid)->update($row);
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

        Db::name('cashback')->where('id', $id)->where('aid', $aid)->update(['status' => $status]);
    }

    /**
     * 删除规则（物理删除；已有参与台账时拒绝）
     */
    public function delete(int $aid, int $id): void
    {
        $this->findOne($aid, $id);

        $used = (int) Db::name('cashback_member')->where('aid', $aid)->where('cashback_id', $id)->count();

        if ($used > 0) {
            throw new BusinessException('该规则已有参与记录，不能删除');
        }

        Db::name('cashback')->where('id', $id)->where('aid', $aid)->delete();
    }

    /**
     * 参与台账列表（cashback_member：会员+活动+商品聚合）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function memberList(int $aid, int $cashbackId, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('cashback_member', $aid, 'c')
            ->leftJoin('member m', 'm.id = c.mid')
            ->leftJoin('cashback cb', 'cb.id = c.cashback_id')
            ->field('c.*, m.tel as phone, m.nickname, cb.name as cashback_name');

        if ($cashbackId > 0) {
            $query->where('c.cashback_id', $cashbackId);
        }

        $total = (clone $query)->count();

        $rows = $query->order('c.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'                 => (int) ($row['id'] ?? 0),
            'mid'                => (int) ($row['mid'] ?? 0),
            'phone'              => (string) ($row['phone'] ?? ''),
            'nickname'           => (string) ($row['nickname'] ?? ''),
            'cashback_id'        => (int) ($row['cashback_id'] ?? 0),
            'cashback_name'      => (string) ($row['cashback_name'] ?? ''),
            'pro_id'             => (int) ($row['pro_id'] ?? 0),
            'pro_num'            => (int) ($row['pro_num'] ?? 0),
            'cashback_money_max' => (float) ($row['cashback_money_max'] ?? 0),
            'cashback_money'     => (float) ($row['cashback_money'] ?? 0),
            'score'              => (int) ($row['score'] ?? 0),
            'commission'         => (float) ($row['commission'] ?? 0),
            'back_type'          => (int) ($row['back_type'] ?? 0),
            'type'               => (string) ($row['type'] ?? 'shop'),
            'create_time'        => (int) ($row['create_time'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['create_time']) : '',
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 返现流水列表（cashback_member_log）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function memberLogList(int $aid, int $cashbackId, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('cashback_member_log', $aid, 'l')
            ->leftJoin('member m', 'm.id = l.mid')
            ->leftJoin('cashback cb', 'cb.id = l.cashback_id')
            ->field('l.*, m.tel as phone, m.nickname, cb.name as cashback_name');

        if ($cashbackId > 0) {
            $query->where('l.cashback_id', $cashbackId);
        }

        $total = (clone $query)->count();

        $rows = $query->order('l.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'             => (int) ($row['id'] ?? 0),
            'mid'            => (int) ($row['mid'] ?? 0),
            'phone'          => (string) ($row['phone'] ?? ''),
            'nickname'       => (string) ($row['nickname'] ?? ''),
            'cashback_id'    => (int) ($row['cashback_id'] ?? 0),
            'cashback_name'  => (string) ($row['cashback_name'] ?? ''),
            'pro_id'         => (int) ($row['pro_id'] ?? 0),
            'cashback_money' => (float) ($row['cashback_money'] ?? 0),
            'score'          => (int) ($row['score'] ?? 0),
            'commission'     => (float) ($row['commission'] ?? 0),
            'back_type'      => (int) ($row['back_type'] ?? 0),
            'type'           => (string) ($row['type'] ?? 'shop'),
            'create_time'    => (int) ($row['create_time'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['create_time']) : '',
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /* ================= 确认收货触发 ================= */

    /**
     * 确认收货返现结算（OrderService::confirm 独立 try/catch 钩子调用）
     *
     * 口径：逐商品行（gtype=0）匹配生效规则 → 行实付×比率 → 台账限额裁剪 → 发放 → 落台账/流水
     * 幂等依赖：confirm 状态条件更新只成功一次，本钩子只会被触发一次（同 PrizePool 口径）
     *
     * @return array{granted: int, money: float, commission: float, score: int}
     */
    public function dealCashback(int $aid, int $orderId): array
    {
        $result = ['granted' => 0, 'money' => 0.0, 'commission' => 0.0, 'score' => 0];

        $order = Db::name('shop_order')->where('id', $orderId)->where('aid', $aid)->find();

        if (empty($order)) {
            return $result;
        }

        $mid = (int) ($order['mid'] ?? 0);

        if ($mid <= 0) {
            return $result; // 游客订单不参与
        }

        $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->find();

        if (empty($member)) {
            return $result;
        }

        $ogRows = Db::name('shop_order_goods')
            ->where('orderid', $orderId)
            ->where('aid', $aid)
            ->select()
            ->toArray();

        $now = time();
        $rules = ScopeService::query('cashback', $aid)
            ->where('status', 1)
            ->where('starttime', '<=', $now)
            ->where('endtime', '>=', $now)
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        if (empty($rules)) {
            return $result;
        }

        foreach ($ogRows as $og) {
            // 赠品行/换购行不参与返现（仅正常购买行）
            if (0 !== (int) ($og['gtype'] ?? 0)) {
                continue;
            }

            $proid = (int) ($og['proid'] ?? 0);
            $num   = (int) ($og['num'] ?? 0);
            $sellPrice = (float) ($og['sell_price'] ?? 0);
            $realTotal = (float) ($og['real_totalprice'] ?? 0);
            $cid = (string) ($og['cid'] ?? '');

            if ($proid <= 0 || $num <= 0 || $realTotal <= 0) {
                continue;
            }

            foreach ($rules as $rule) {
                if (!$this->matchRule($rule, $proid, $cid, $sellPrice, (int) ($member['levelid'] ?? 0))) {
                    continue;
                }

                $backPrice = round($realTotal * (float) ($rule['back_ratio'] ?? 0) / 100, 2);

                if ($backPrice <= 0) {
                    continue;
                }

                // 台账限额：goods_multiple_max>0 时 限额=售价×倍数×数量，累计达限额停止
                $multiple = (float) ($rule['goods_multiple_max'] ?? 0);
                $ledger = Db::name('cashback_member')
                    ->where('aid', $aid)
                    ->where('mid', $mid)
                    ->where('cashback_id', (int) $rule['id'])
                    ->where('pro_id', $proid)
                    ->find();

                if ($multiple > 0) {
                    $max = round($sellPrice * $multiple * $num, 2);
                    $already = null !== $ledger ? (float) ($ledger['cashback_money'] ?? 0) : 0.0;
                    $left = round($max - $already, 2);

                    if ($left <= 0) {
                        continue; // 已达限额停止返现
                    }

                    if ($backPrice > $left) {
                        $backPrice = $left;
                    }
                }

                $this->grantOne($aid, $mid, $member, $rule, $og, $backPrice, $num, $ledger);

                ++$result['granted'];

                if (self::BACK_MONEY === (int) ($rule['back_type'] ?? 0)) {
                    $result['money'] = round($result['money'] + $backPrice, 2);
                } elseif (self::BACK_COMMISSION === (int) ($rule['back_type'] ?? 0)) {
                    $result['commission'] = round($result['commission'] + $backPrice, 2);
                } elseif (self::BACK_SCORE === (int) ($rule['back_type'] ?? 0)) {
                    $result['score'] += (int) floor($backPrice);
                }

                break; // 同一商品行只命中一条规则
            }
        }

        return $result;
    }

    /* ================= 内部工具 ================= */

    /**
     * 单笔返现发放（事务：余额/佣金/积分按 back_type 入账 + 台账累计 + 流水）
     *
     * @param array<string, mixed> $member
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $og
     * @param array<string, mixed>|null $ledger 已有台账行（null=首次）
     */
    protected function grantOne(int $aid, int $mid, array $member, array $rule, array $og, float $backPrice, int $num, ?array $ledger): void
    {
        $backType = (int) ($rule['back_type'] ?? 0);
        $now = time();
        $remark = '购物返现「' . (string) ($rule['name'] ?? '') . '」订单' . (string) ($og['ordernum'] ?? '');

        Db::transaction(function () use ($aid, $mid, $backType, $backPrice, $remark, $rule, $og, $num, $ledger, $now) {
            $memberLocked = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();

            if (empty($memberLocked)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            $scoreVal = 0;
            $commissionVal = 0.0;

            if (self::BACK_MONEY === $backType) {
                $after = round((float) ($memberLocked['money'] ?? 0) + $backPrice, 2);

                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);

                Db::name('member_moneylog')->insert([
                    'aid'        => $aid,
                    'mid'        => $mid,
                    'money'      => $backPrice,
                    'after'      => $after,
                    'createtime' => $now,
                    'remark'     => $remark,
                    'paytype'    => 'cashback',
                ]);
            } elseif (self::BACK_COMMISSION === $backType) {
                $commissionVal = $backPrice;
                $after = round((float) ($memberLocked['commission'] ?? 0) + $backPrice, 2);

                Db::name('member')->where('id', $mid)->where('aid', $aid)->update([
                    'commission'      => $after,
                    'totalcommission' => round((float) ($memberLocked['totalcommission'] ?? 0) + $backPrice, 2),
                ]);

                Db::name('member_commissionlog')->insert([
                    'aid'        => $aid,
                    'mid'        => $mid,
                    'frommid'    => $mid,
                    'commission' => $backPrice,
                    'after'      => $after,
                    'createtime' => $now,
                    'remark'     => $remark,
                    'fhtype'     => 'cashback',
                    'uid'        => 0,
                    'fhid'       => 0,
                ]);
            } elseif (self::BACK_SCORE === $backType) {
                $scoreVal = (int) floor($backPrice);

                if ($scoreVal <= 0) {
                    throw new BusinessException('返现积分不足1，跳过');
                }

                $after = (int) ($memberLocked['score'] ?? 0) + $scoreVal;

                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['score' => $after]);

                Db::name('member_scorelog')->insert([
                    'aid'        => $aid,
                    'bid'        => 0,
                    'mid'        => (string) $mid,
                    'score'      => $scoreVal,
                    'after'      => $after,
                    'used'       => 0,
                    'createtime' => $now,
                    'remark'     => $remark,
                    'type'       => 1,
                    'channel'    => 'cashback',
                    'status'     => 0,
                    'uid'        => 0,
                ]);
            } else {
                throw new BusinessException('返现类型无效');
            }

            // 台账累计（首次建档写限额，后续累计已返额度）
            $multiple = (float) ($rule['goods_multiple_max'] ?? 0);
            $max = $multiple > 0 ? round((float) ($og['sell_price'] ?? 0) * $multiple * $num, 2) : 0.0;

            if (null === $ledger) {
                Db::name('cashback_member')->insert([
                    'aid'                => $aid,
                    'mid'                => $mid,
                    'cashback_id'        => (int) $rule['id'],
                    'pro_id'             => (int) ($og['proid'] ?? 0),
                    'pro_num'            => $num,
                    'cashback_money_max' => $max,
                    'cashback_money'     => self::BACK_SCORE === $backType ? 0.0 : $backPrice,
                    'score'              => $scoreVal,
                    'commission'         => $commissionVal,
                    'back_type'          => $backType,
                    'create_time'        => $now,
                    'type'               => 'shop',
                    'ogid'               => (int) ($og['id'] ?? 0),
                ]);
            } else {
                Db::name('cashback_member')->where('id', (int) $ledger['id'])->update([
                    'pro_num'        => (int) ($ledger['pro_num'] ?? 0) + $num,
                    'cashback_money' => round((float) ($ledger['cashback_money'] ?? 0) + (self::BACK_SCORE === $backType ? 0.0 : $backPrice), 2),
                    'score'          => (int) ($ledger['score'] ?? 0) + $scoreVal,
                    'commission'     => round((float) ($ledger['commission'] ?? 0) + $commissionVal, 2),
                    'ogid'           => (int) ($og['id'] ?? 0),
                ]);
            }

            // 逐笔流水
            Db::name('cashback_member_log')->insert([
                'aid'            => $aid,
                'mid'            => $mid,
                'cashback_id'    => (int) $rule['id'],
                'pro_id'         => (int) ($og['proid'] ?? 0),
                'cashback_money' => self::BACK_SCORE === $backType ? 0.0 : $backPrice,
                'score'          => $scoreVal,
                'commission'     => $commissionVal,
                'back_type'      => $backType,
                'create_time'    => $now,
                'type'           => 'shop',
            ]);
        });
    }

    /**
     * 规则匹配（人群/范围/单价门槛）
     *
     * @param array<string, mixed> $rule
     */
    protected function matchRule(array $rule, int $proid, string $cid, float $sellPrice, int $levelId): bool
    {
        // 人群条件
        $gettj = trim((string) ($rule['gettj'] ?? '-1'));

        if ('' !== $gettj && '-1' !== $gettj) {
            $ids = array_filter(array_map('intval', explode(',', $gettj)));

            if (!in_array($levelId, $ids, true)) {
                return false;
            }
        }

        // 范围条件
        $fwtype = (int) ($rule['fwtype'] ?? 0);

        if (1 === $fwtype) {
            $categoryIds = array_filter(array_map('trim', explode(',', (string) ($rule['categoryids'] ?? ''))));
            $productCids = array_filter(array_map('trim', explode(',', $cid)));
            $hit = false;

            foreach ($productCids as $productCid) {
                if (in_array($productCid, $categoryIds, true)) {
                    $hit = true;
                    break;
                }
            }

            if (!$hit) {
                return false;
            }
        } elseif (2 === $fwtype) {
            $productIds = array_filter(array_map('intval', explode(',', (string) ($rule['productids'] ?? ''))));

            if (!in_array($proid, $productIds, true)) {
                return false;
            }
        }

        // 单价门槛
        if ($sellPrice < (float) ($rule['minprice'] ?? 0)) {
            return false;
        }

        return true;
    }

    /**
     * 保存参数组装与校验
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    protected function buildData(array $data, ?array $current = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写规则名称');
        }

        $backRatio = round((float) ($data['back_ratio'] ?? 0), 2);

        if ($backRatio <= 0 || $backRatio > 100) {
            throw new BusinessException('返还比率须在 0~100 之间');
        }

        $backType = (int) ($data['back_type'] ?? 0);

        if (!in_array($backType, [self::BACK_MONEY, self::BACK_COMMISSION, self::BACK_SCORE], true)) {
            throw new BusinessException('返现类型无效（1余额 2佣金 3积分）');
        }

        $receiverType = (int) ($data['receiver_type'] ?? 1);

        if (!in_array($receiverType, [1, 2], true)) {
            throw new BusinessException('受益人类型无效');
        }

        $multipleMax = round((float) ($data['goods_multiple_max'] ?? 0), 2);

        if ($multipleMax < 0) {
            throw new BusinessException('返现额度倍数不能为负数');
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

        $starttime = (int) ($data['starttime'] ?? 0);
        $endtime   = (int) ($data['endtime'] ?? 0);

        if ($starttime <= 0 || $endtime <= 0) {
            throw new BusinessException('请填写活动起止时间');
        }
        if ($endtime <= $starttime) {
            throw new BusinessException('结束时间必须晚于开始时间');
        }

        return [
            'name'               => $name,
            'back_ratio'         => $backRatio,
            'back_type'          => $backType,
            'gettj'              => trim((string) ($data['gettj'] ?? '-1')) ?: '-1',
            'fwtype'             => $fwtype,
            'categoryids'        => $categoryids,
            'productids'         => $productids,
            'minprice'           => round((float) ($data['minprice'] ?? 0), 2),
            'starttime'          => $starttime,
            'endtime'            => $endtime,
            'sort'               => (int) ($data['sort'] ?? 0),
            'receiver_type'      => $receiverType,
            'goods_multiple_max' => $multipleMax,
            'shuoming'           => trim((string) ($data['shuoming'] ?? '')),
        ];
    }

    /**
     * 查找本租户规则（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findOne(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少规则ID');
        }

        $row = ScopeService::query('cashback', $aid)->where('id', $id)->find();

        if (empty($row)) {
            throw new BusinessException('返现规则不存在', 404, 404);
        }

        return $row;
    }

    /**
     * 规则行格式化
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function format(array $row): array
    {
        return [
            'id'                 => (int) ($row['id'] ?? 0),
            'name'               => (string) ($row['name'] ?? ''),
            'back_ratio'         => (float) ($row['back_ratio'] ?? 0),
            'back_type'          => (int) ($row['back_type'] ?? 0),
            'gettj'              => (string) ($row['gettj'] ?? '-1'),
            'fwtype'             => (int) ($row['fwtype'] ?? 0),
            'categoryids'        => (string) ($row['categoryids'] ?? ''),
            'productids'         => (string) ($row['productids'] ?? ''),
            'minprice'           => (float) ($row['minprice'] ?? 0),
            'starttime'          => (int) ($row['starttime'] ?? 0),
            'endtime'            => (int) ($row['endtime'] ?? 0),
            'sort'               => (int) ($row['sort'] ?? 0),
            'status'             => (int) ($row['status'] ?? 1),
            'receiver_type'      => (int) ($row['receiver_type'] ?? 1),
            'goods_multiple_max' => (float) ($row['goods_multiple_max'] ?? 0),
            'shuoming'           => (string) ($row['shuoming'] ?? ''),
            'create_time'        => (int) ($row['createtime'] ?? 0),
        ];
    }
}
