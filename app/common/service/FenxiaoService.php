<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 分销引擎服务（任务179 批次3-A）
 *
 * 对标参考系统 canzhao/app/common/Fenxiao.php：
 * - fenxiao()：佣金计算主入口（沿 pid 向上找 parent1-3/4，逐代校验 can_agent）
 * - settleOrderCommission()：支付成功结算入口（写 og 分销字段 + member_commission_record status=0 冻结）
 * - refundFenxiao()：退款回滚（commission_record 置 2 作废，已结算的扣回佣金余额）
 *
 * commissionset 模式（shop_product）：
 *   -1 不参与 / 0 按会员等级(commission1-6+commissiontype) / 1 商品比例(commissiondata1)
 *    2 固定金额(commissiondata2) / 3 积分(commissiondata3) / 5 比例+积分 / 6 金额+积分
 *    7 积分比例(commissiondata4) / 8 比例+余额(commissiondata1+commissiondata4)
 *
 * 级差（fx_differential）：parent2 = max(parent2-parent1,0)；parent3 = max(parent3-parent2-parent1,0)
 * 持续推荐奖（commission_parent）：commissionset=0 时 parent2/3 按 parent1/2 提成追加
 * 平级奖（commission_parent_pj）：同等级上级补发
 * 份数限制（member_level.commission_max）：同一商品佣金记录条数上限
 *
 * member_commission_record.status：0 冻结 / 1 已结算 / 2 已作废
 * （冻结→结算由批次3-B 结算任务处理，本服务只在支付时写冻结记录）
 */
class FenxiaoService
{
    /**
     * 支付成功后分销入账（结算入口）
     *
     * 逐商品行计算佣金：更新 shop_order_goods 的 parent1-4 与各级佣金（替换占位 0），
     * 并按受益人写 member_commission_record（status=0 冻结）。幂等：og.iscommission=1 跳过。
     */
    public function settleOrderCommission(int $aid, int $orderId): void
    {
        $order = Db::name('shop_order')->where('id', $orderId)->where('aid', $aid)->find();

        if (empty($order)) {
            return;
        }

        $member = Db::name('member')->where('id', (int) $order['mid'])->where('aid', $aid)->find();

        if (empty($member)) {
            return;
        }

        $sysset = $this->sysset($aid);
        $member = (array) $member;

        $goodsRows = Db::name('shop_order_goods')
            ->where('orderid', $orderId)
            ->where('aid', $aid)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($goodsRows as $og) {
            if ((int) ($og['iscommission'] ?? 0) === 1) {
                continue; // 幂等：已计算过
            }

            $product = Db::name('shop_product')->where('id', (int) $og['proid'])->where('aid', $aid)->find();

            if (empty($product)) {
                Db::name('shop_order_goods')->where('id', (int) $og['id'])->update(['iscommission' => 1]);
                continue;
            }

            $product = (array) $product;
            $num     = (int) ($og['num'] ?? 0);

            // 结算基数（fxjiesuantype：0 按商品价格 / 1 按成交价格 / 2 按销售利润）
            $commissionTotalprice = $this->settleBase($sysset, (array) $og, $product, $num);

            $ogupdate = $this->fenxiao($aid, $sysset, $member, $product, $num, $commissionTotalprice);

            if (empty($ogupdate)) {
                Db::name('shop_order_goods')->where('id', (int) $og['id'])->update(['iscommission' => 1]);
                continue;
            }

            // 写订单商品行分销字段
            $ogWrite = ['iscommission' => 1];
            foreach (['parent1', 'parent2', 'parent3', 'parent4'] as $field) {
                if (isset($ogupdate[$field])) {
                    $ogWrite[$field] = (int) $ogupdate[$field];
                }
            }
            foreach (['parent1commission', 'parent2commission', 'parent3commission', 'parent4commission',
                'parent1score', 'parent2score', 'parent3score'] as $field) {
                if (isset($ogupdate[$field])) {
                    $ogWrite[$field] = $ogupdate[$field];
                }
            }

            Db::name('shop_order_goods')->where('id', (int) $og['id'])->update($ogWrite);

            // 写佣金记录（冻结 status=0）
            $this->writeRecords($aid, (int) $order['id'], (int) $og['id'], (int) $order['mid'], $ogupdate, $product);
        }
    }

    /**
     * 佣金计算主入口（对标参考系统 Fenxiao::fenxiao）
     *
     * @param array<string, mixed> $sysset  admin_set 行
     * @param array<string, mixed> $member  下单会员行（含 pid/path/levelid）
     * @param array<string, mixed> $product 商品行
     * @return array<string, mixed> ogupdate（parent1-4/parentNcommission/parentNscore 等）
     */
    public function fenxiao(int $aid, array $sysset, array $member, array $product, int $num, float $commissionTotalprice, int $isfg = 0): array
    {
        $ogupdate = [];

        if ((int) ($product['commissionset'] ?? 0) === -1) {
            return $ogupdate; // 不参与分销
        }

        $levelMap = $this->levelMap($aid);

        $parent1 = $parent2 = $parent3 = $parent4 = null;
        $agleveldata1 = $agleveldata2 = $agleveldata3 = $agleveldata4 = null;

        // ---------- 沿 pid 向上找 parent1-4（逐代校验 can_agent） ----------
        $jinsuo = (int) ($sysset['fx_jinsuo'] ?? 0) === 1;

        if ($jinsuo && !empty($member['path'])) {
            // 紧缩模式：沿 path 向上，跳过无分销权限/提成为0的等级，按代数补齐 3 代
            $pathIds = array_values(array_filter(array_unique(array_map('intval', explode(',', (string) $member['path'])))));
            $parents = Db::name('member')->where('aid', $aid)->whereIn('id', $pathIds)->select()->toArray();
            $parentMap = [];
            foreach ($parents as $row) {
                $parentMap[(int) $row['id']] = (array) $row;
            }
            // path 由远及近，反转为由近及远
            $chain = [];
            foreach (array_reverse($pathIds) as $pid) {
                if (isset($parentMap[$pid])) {
                    $chain[] = $parentMap[$pid];
                }
            }

            $dai = 1;
            foreach ($chain as $up) {
                if ($dai > 3) {
                    break;
                }
                $lv = $levelMap[(int) ($up['levelid'] ?? 0)] ?? null;
                if (empty($lv)) {
                    continue; // 无等级紧缩
                }
                if ((int) $lv['can_agent'] === 0) {
                    continue; // 无分销权限紧缩
                }
                if ('' !== (string) ($lv['commission_appointlevelid'] ?? '') && !in_array((int) $member['levelid'], array_map('intval', explode(',', (string) $lv['commission_appointlevelid'])), true)) {
                    continue; // 下单人不在指定等级内紧缩
                }
                if ((float) ($lv['commission1'] ?? 0) <= 0) {
                    continue; // 提成为0紧缩
                }
                if (1 === $dai) {
                    $parent1 = $up;
                    $agleveldata1 = $lv;
                } elseif (2 === $dai) {
                    $parent2 = $up;
                    $agleveldata2 = $lv;
                } else {
                    $parent3 = $up;
                    $agleveldata3 = $lv;
                }
                ++$dai;
            }
        } else {
            // 标准模式：parent1=pid，parent2=parent1.pid，parent3=parent2.pid
            if (!empty($member['pid'])) {
                $row = Db::name('member')->where('aid', $aid)->where('id', (int) $member['pid'])->find();
                if (!empty($row)) {
                    $parent1 = (array) $row;
                    $lv = $levelMap[(int) ($parent1['levelid'] ?? 0)] ?? null;
                    if (!empty($lv)
                        && (int) $lv['can_agent'] !== 0
                        && ('' === (string) ($lv['commission_appointlevelid'] ?? '')
                            || in_array((int) $member['levelid'], array_map('intval', explode(',', (string) $lv['commission_appointlevelid'])), true))) {
                        $agleveldata1 = $lv;
                        $ogupdate['parent1'] = (int) $parent1['id'];
                        $ogupdate['parent1_levelid'] = (int) ($parent1['levelid'] ?? 0);
                    } else {
                        $parent1 = null; // 无权限：本级不拿奖，但链条继续向上
                    }
                }
            }

            if (!empty($parent1['pid'])) {
                $row = Db::name('member')->where('aid', $aid)->where('id', (int) $parent1['pid'])->find();
                if (!empty($row)) {
                    $parent2 = (array) $row;
                    $lv = $levelMap[(int) ($parent2['levelid'] ?? 0)] ?? null;
                    if (!empty($lv)
                        && ((int) $lv['can_agent'] > 1 || (float) ($lv['commission_parent'] ?? 0) > 0)
                        && ('' === (string) ($lv['commission_appointlevelid'] ?? '')
                            || in_array((int) $member['levelid'], array_map('intval', explode(',', (string) $lv['commission_appointlevelid'])), true))) {
                        $agleveldata2 = $lv;
                        $ogupdate['parent2'] = (int) $parent2['id'];
                        $ogupdate['parent2_levelid'] = (int) ($parent2['levelid'] ?? 0);
                    } else {
                        $parent2 = null;
                    }
                }
            }

            if (!empty($parent2['pid'])) {
                $row = Db::name('member')->where('aid', $aid)->where('id', (int) $parent2['pid'])->find();
                if (!empty($row)) {
                    $parent3 = (array) $row;
                    $lv = $levelMap[(int) ($parent3['levelid'] ?? 0)] ?? null;
                    if (!empty($lv)
                        && ((int) $lv['can_agent'] > 2 || (float) ($lv['commission_parent'] ?? 0) > 0)
                        && ('' === (string) ($lv['commission_appointlevelid'] ?? '')
                            || in_array((int) $member['levelid'], array_map('intval', explode(',', (string) $lv['commission_appointlevelid'])), true))) {
                        $agleveldata3 = $lv;
                        $ogupdate['parent3'] = (int) $parent3['id'];
                        $ogupdate['parent3_levelid'] = (int) ($parent3['levelid'] ?? 0);
                    } else {
                        $parent3 = null;
                    }
                }
            }
        }

        // parent4：持续推荐奖/平级奖受益人（第四级）
        if (!empty($parent3['pid'])) {
            $row = Db::name('member')->where('aid', $aid)->where('id', (int) $parent3['pid'])->find();
            if (!empty($row)) {
                $p4 = (array) $row;
                $lv4 = $levelMap[(int) ($p4['levelid'] ?? 0)] ?? null;
                if (!empty($lv4)
                    && (int) $lv4['can_agent'] > 0
                    && ((float) ($lv4['commission_parent'] ?? 0) > 0
                        || ((int) ($p4['levelid'] ?? 0) === (int) ($parent3['levelid'] ?? -1) && (float) ($lv4['commission_parent_pj'] ?? 0) > 0))) {
                    $parent4 = $p4;
                    $agleveldata4 = $lv4;
                    $ogupdate['parent4'] = (int) $p4['id'];
                }
            }
        }

        // ---------- 按 commissionset 模式计算各级提成 ----------
        $ogupdate += [
            'parent1' => 0, 'parent2' => 0, 'parent3' => 0, 'parent4' => 0,
            'parent1commission' => 0, 'parent2commission' => 0, 'parent3commission' => 0, 'parent4commission' => 0,
            'parent1score' => 0, 'parent2score' => 0, 'parent3score' => 0,
        ];

        $set = (int) ($product['commissionset'] ?? 0);

        switch ($set) {
            case 1: // 商品比例
                $data = $this->jsonArr($product['commissiondata1'] ?? '');
                if ($agleveldata1) {
                    $ogupdate['parent1commission'] = (float) ($data[(int) $agleveldata1['id']]['commission1'] ?? 0) * $commissionTotalprice * 0.01;
                }
                if ($agleveldata2) {
                    $ogupdate['parent2commission'] = (float) ($data[(int) $agleveldata2['id']]['commission2'] ?? 0) * $commissionTotalprice * 0.01;
                }
                if ($agleveldata3) {
                    $ogupdate['parent3commission'] = (float) ($data[(int) $agleveldata3['id']]['commission3'] ?? 0) * $commissionTotalprice * 0.01;
                }
                break;
            case 2: // 固定金额
                $data = $this->jsonArr($product['commissiondata2'] ?? '');
                if ($agleveldata1) {
                    $ogupdate['parent1commission'] = (float) ($data[(int) $agleveldata1['id']]['commission1'] ?? 0) * $num;
                }
                if ($agleveldata2) {
                    $ogupdate['parent2commission'] = (float) ($data[(int) $agleveldata2['id']]['commission2'] ?? 0) * $num;
                }
                if ($agleveldata3) {
                    $ogupdate['parent3commission'] = (float) ($data[(int) $agleveldata3['id']]['commission3'] ?? 0) * $num;
                }
                break;
            case 3: // 积分
                $data = $this->jsonArr($product['commissiondata3'] ?? '');
                if ($agleveldata1) {
                    $ogupdate['parent1score'] = (int) round((float) ($data[(int) $agleveldata1['id']]['commission1'] ?? 0) * $num);
                }
                if ($agleveldata2) {
                    $ogupdate['parent2score'] = (int) round((float) ($data[(int) $agleveldata2['id']]['commission2'] ?? 0) * $num);
                }
                if ($agleveldata3) {
                    $ogupdate['parent3score'] = (int) round((float) ($data[(int) $agleveldata3['id']]['commission3'] ?? 0) * $num);
                }
                break;
            case 5: // 比例+积分
            case 6: // 金额+积分
                $ratioData = 5 === $set ? $this->jsonArr($product['commissiondata1'] ?? '') : $this->jsonArr($product['commissiondata2'] ?? '');
                $scoreData = $this->jsonArr($product['commissiondata3'] ?? '');
                foreach ([1 => $agleveldata1, 2 => $agleveldata2, 3 => $agleveldata3] as $dai => $lv) {
                    if (empty($lv)) {
                        continue;
                    }
                    $key = 'commission' . $dai;
                    if (5 === $set) {
                        $ogupdate['parent' . $dai . 'commission'] = (float) ($ratioData[(int) $lv['id']][$key] ?? 0) * $commissionTotalprice * 0.01;
                    } else {
                        $ogupdate['parent' . $dai . 'commission'] = (float) ($ratioData[(int) $lv['id']][$key] ?? 0) * $num;
                    }
                    $ogupdate['parent' . $dai . 'score'] = (int) round((float) ($scoreData[(int) $lv['id']][$key] ?? 0) * $num);
                }
                break;
            case 7: // 积分比例
                $data = $this->jsonArr($product['commissiondata4'] ?? '');
                if ($agleveldata1) {
                    $ogupdate['parent1score'] = (int) round((float) ($data[(int) $agleveldata1['id']]['commission1'] ?? 0) * $commissionTotalprice * 0.01);
                }
                if ($agleveldata2) {
                    $ogupdate['parent2score'] = (int) round((float) ($data[(int) $agleveldata2['id']]['commission2'] ?? 0) * $commissionTotalprice * 0.01);
                }
                if ($agleveldata3) {
                    $ogupdate['parent3score'] = (int) round((float) ($data[(int) $agleveldata3['id']]['commission3'] ?? 0) * $commissionTotalprice * 0.01);
                }
                break;
            case 8: // 比例+余额
                $ratioData = $this->jsonArr($product['commissiondata1'] ?? '');
                $moneyData = $this->jsonArr($product['commissiondata4'] ?? '');
                foreach ([1 => $agleveldata1, 2 => $agleveldata2, 3 => $agleveldata3] as $dai => $lv) {
                    if (empty($lv)) {
                        continue;
                    }
                    $key = 'commission' . $dai;
                    $ogupdate['parent' . $dai . 'commission'] = (float) ($ratioData[(int) $lv['id']][$key] ?? 0) * $commissionTotalprice * 0.01;
                    $ogupdate['parent' . $dai . 'money'] = round((float) ($moneyData[(int) $lv['id']][$key] ?? 0) * $commissionTotalprice * 0.01, 2);
                }
                break;
            default: // 0 按会员等级设置的分销比例
                if ($agleveldata1) {
                    $rate = 1 === $isfg ? (float) ($agleveldata1['commission4'] ?? 0) : (float) ($agleveldata1['commission1'] ?? 0);
                    $ogupdate['parent1commission'] = (int) ($agleveldata1['commissiontype'] ?? 0) === 1
                        ? $rate
                        : $rate * $commissionTotalprice * 0.01;
                }
                if ($agleveldata2) {
                    $rate = 1 === $isfg ? (float) ($agleveldata2['commission5'] ?? 0) : (float) ($agleveldata2['commission2'] ?? 0);
                    $ogupdate['parent2commission'] = (int) ($agleveldata2['can_agent'] ?? 0) > 1
                        ? ((int) ($agleveldata2['commissiontype'] ?? 0) === 1 ? $rate : $rate * $commissionTotalprice * 0.01)
                        : 0;
                }
                if ($agleveldata3) {
                    $rate = 1 === $isfg ? (float) ($agleveldata3['commission6'] ?? 0) : (float) ($agleveldata3['commission3'] ?? 0);
                    $ogupdate['parent3commission'] = (int) ($agleveldata3['can_agent'] ?? 0) > 2
                        ? ((int) ($agleveldata3['commissiontype'] ?? 0) === 1 ? $rate : $rate * $commissionTotalprice * 0.01)
                        : 0;
                }
                break;
        }

        // ---------- 级差 ----------
        $differential = ((int) ($product['fx_differential'] ?? -1) === -1 && (int) ($sysset['fx_differential'] ?? 0) === 1)
            || (int) ($product['fx_differential'] ?? -1) === 1;

        if ($differential && in_array($set, [0, 1, 2, 5, 6], true)) {
            if ((float) $ogupdate['parent2commission'] > 0) {
                $cha = (float) $ogupdate['parent2commission'] - (float) $ogupdate['parent1commission'];
                $ogupdate['parent2commission'] = $cha > 0 ? $cha : 0;
            }
            if ((float) $ogupdate['parent3commission'] > 0) {
                $cha = (float) $ogupdate['parent3commission'] - (float) $ogupdate['parent2commission'] - (float) $ogupdate['parent1commission'];
                $ogupdate['parent3commission'] = $cha > 0 ? $cha : 0;
            }
        }

        // ---------- 持续推荐奖（commission_parent，仅 commissionset=0） ----------
        if (0 === $set) {
            if ($agleveldata2 && (float) ($agleveldata2['commission_parent'] ?? 0) > 0 && !empty($ogupdate['parent1'])) {
                $ogupdate['parent2commission'] += (int) ($agleveldata2['commissiontype'] ?? 0) === 1
                    ? (float) $agleveldata2['commission_parent']
                    : (float) $ogupdate['parent1commission'] * (float) $agleveldata2['commission_parent'] * 0.01;
            }
            if ($agleveldata3 && (float) ($agleveldata3['commission_parent'] ?? 0) > 0 && !empty($ogupdate['parent2'])) {
                $ogupdate['parent3commission'] += (int) ($agleveldata3['commissiontype'] ?? 0) === 1
                    ? (float) $agleveldata3['commission_parent']
                    : (float) $ogupdate['parent2commission'] * (float) $agleveldata3['commission_parent'] * 0.01;
            }
            if ($agleveldata4 && (float) ($agleveldata4['commission_parent'] ?? 0) > 0 && !empty($ogupdate['parent3'])) {
                $ogupdate['parent4commission'] = (int) ($agleveldata4['commissiontype'] ?? 0) === 1
                    ? (float) $agleveldata4['commission_parent']
                    : (float) $ogupdate['parent3commission'] * (float) $agleveldata4['commission_parent'] * 0.01;
            }
        }

        // ---------- 平级奖（commission_parent_pj） ----------
        $this->calcPingji($aid, $ogupdate, $parent1, $parent2, $parent3, $levelMap, $product, $num, $commissionTotalprice);

        // ---------- 份数限制（member_level.commission_max） ----------
        foreach ([1 => $agleveldata1, 2 => $agleveldata2, 3 => $agleveldata3] as $dai => $lv) {
            if (empty($lv) || empty($ogupdate['parent' . $dai])) {
                continue;
            }
            $max = (int) ($lv['commission_max'] ?? 0);
            if ($max <= 0) {
                continue;
            }
            $have = Db::name('member_commission_record')
                ->alias('r')
                ->join('shop_order_goods og', 'og.id = r.ogid')
                ->where('r.mid', (int) $ogupdate['parent' . $dai])
                ->where('r.type', 'shop')
                ->whereIn('r.status', [0, 1])
                ->where('og.proid', (int) ($product['id'] ?? 0))
                ->count();
            if ($have >= $max) {
                $ogupdate['parent' . $dai] = 0;
                $ogupdate['parent' . $dai . 'commission'] = 0;
                $ogupdate['parent' . $dai . 'score'] = 0;
            }
        }

        // 金额取两位小数
        foreach (['parent1commission', 'parent2commission', 'parent3commission', 'parent4commission'] as $field) {
            $ogupdate[$field] = round((float) ($ogupdate[$field] ?? 0), 2);
        }

        return $ogupdate;
    }

    /**
     * 平级奖：沿链向上找与各代分销人同等级（sort 相同）的上级，补发 commission_parent_pj
     *
     * @param array<string, mixed>       $ogupdate
     * @param array<string, mixed>|null  $parent1
     * @param array<string, mixed>|null  $parent2
     * @param array<string, mixed>|null  $parent3
     * @param array<int, array>          $levelMap
     * @param array<string, mixed>       $product
     */
    protected function calcPingji(int $aid, array &$ogupdate, ?array $parent1, ?array $parent2, ?array $parent3, array $levelMap, array $product, int $num, float $commissionTotalprice): void
    {
        $targets = [
            1 => [$parent1, (float) ($ogupdate['parent1commission'] ?? 0)],
            2 => [$parent2, (float) ($ogupdate['parent2commission'] ?? 0)],
            3 => [$parent3, (float) ($ogupdate['parent3commission'] ?? 0)],
        ];

        foreach ($targets as $dai => [$parent, $baseCommission]) {
            if (empty($parent) || $baseCommission <= 0) {
                continue;
            }

            $parentLevel = $levelMap[(int) ($parent['levelid'] ?? 0)] ?? null;

            if (empty($parentLevel) || (float) ($parentLevel['commission_parent_pj'] ?? 0) <= 0) {
                continue;
            }

            // 从该代分销人向上找最近的同等级会员
            $cursor = (int) ($parent['pid'] ?? 0);
            $depth  = 0;
            $pjMember = null;

            while ($cursor > 0 && $depth < 6) {
                ++$depth;
                $up = Db::name('member')->where('aid', $aid)->where('id', $cursor)->find();
                if (empty($up)) {
                    break;
                }
                $up = (array) $up;
                if ((int) ($up['levelid'] ?? 0) === (int) ($parent['levelid'] ?? -1)) {
                    $pjMember = $up;
                    break;
                }
                $cursor = (int) ($up['pid'] ?? 0);
            }

            if (empty($pjMember)) {
                continue;
            }

            $pjLevel = $levelMap[(int) ($pjMember['levelid'] ?? 0)] ?? null;
            $pjRate = (float) ($pjLevel['commission_parent_pj'] ?? 0);

            if ($pjRate <= 0) {
                continue;
            }

            // commissiontype：0 比例（基于下级提成）/ 1 固定金额
            $pj = (int) ($pjLevel['commissiontype'] ?? 0) === 1 ? $pjRate : $baseCommission * $pjRate * 0.01;

            if ($pj > 0) {
                $ogupdate['parent_pj' . $dai] = (int) $pjMember['id'];
                $ogupdate['parent' . $dai . 'commission_pj'] = round($pj, 2);
            }
        }
    }

    /**
     * 退款回滚（对标参考系统 Fenxiao::refundFenxiao）
     *
     * 将订单相关 commission_record（status 0/1）置 2 作废；
     * 已结算（status=1）的同时扣回佣金余额并写流水。
     */
    public function refundFenxiao(int $aid, int $orderId, string $type = 'shop'): void
    {
        $records = Db::name('member_commission_record')
            ->where('aid', $aid)
            ->where('orderid', $orderId)
            ->where('type', $type)
            ->whereIn('status', [0, 1])
            ->select()
            ->toArray();

        foreach ($records as $record) {
            $commission = (float) ($record['commission'] ?? 0);

            // 已结算的扣回佣金余额
            if (1 === (int) $record['status'] && $commission > 0) {
                $member = Db::name('member')->where('id', (int) $record['mid'])->where('aid', $aid)->lock(true)->find();

                if (!empty($member)) {
                    $after = round((float) ($member['commission'] ?? 0) - $commission, 2);
                    Db::name('member')->where('id', (int) $record['mid'])->where('aid', $aid)->update([
                        'commission'      => $after,
                        'totalcommission' => round((float) ($member['totalcommission'] ?? 0) - $commission, 2),
                    ]);
                    Db::name('member_commissionlog')->insert([
                        'aid'        => $aid,
                        'mid'        => (int) $record['mid'],
                        'frommid'    => (int) ($record['frommid'] ?? 0),
                        'commission' => -1 * $commission,
                        'after'      => $after,
                        'createtime' => time(),
                        'remark'     => '订单退款扣除' . (string) ($record['remark'] ?? ''),
                        'fhtype'     => 'refund',
                        'uid'        => 0,
                        'fhid'       => 0,
                    ]);
                }
            }

            Db::name('member_commission_record')->where('id', (int) $record['id'])->update([
                'status'  => 2,
                'endtime' => time(),
            ]);
        }
    }

    /* ================= 内部工具 ================= */

    /**
     * 写佣金记录（status=0 冻结）
     *
     * @param array<string, mixed> $ogupdate
     * @param array<string, mixed> $product
     */
    protected function writeRecords(int $aid, int $orderId, int $ogid, int $orderMid, array $ogupdate, array $product): void
    {
        $now = time();

        for ($dai = 1; $dai <= 4; ++$dai) {
            $mid        = (int) ($ogupdate['parent' . $dai] ?? 0);
            $commission = round((float) ($ogupdate['parent' . $dai . 'commission'] ?? 0), 2);
            $score      = (int) ($ogupdate['parent' . $dai . 'score'] ?? 0);

            if ($mid <= 0 || ($commission <= 0 && $score <= 0)) {
                continue;
            }

            Db::name('member_commission_record')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'frommid'    => $orderMid,
                'orderid'    => $orderId,
                'ogid'       => $ogid,
                'type'       => 'shop',
                'commission' => $commission,
                'score'      => $score,
                'remark'     => ($dai) . '级分销 ' . (string) ($product['name'] ?? ''),
                'createtime' => $now,
                'endtime'    => 0,
                'status'     => 0,
                'levelid'    => 0,
            ]);
        }
    }

    /**
     * 结算基数（fxjiesuantype：0 按商品价格 / 1 按成交价格 / 2 按销售利润）
     *
     * @param array<string, mixed> $sysset
     * @param array<string, mixed> $og
     * @param array<string, mixed> $product
     */
    protected function settleBase(array $sysset, array $og, array $product, int $num): float
    {
        $type = (int) ($sysset['fxjiesuantype'] ?? 0);

        if (1 === $type) {
            // 成交价格：优先实际支付金额，其次实际销售金额
            $real = (float) ($og['real_totalmoney'] ?? 0);
            return $real > 0 ? $real : (float) ($og['real_totalprice'] ?? 0);
        }

        if (2 === $type) {
            // 销售利润：实际销售金额 - 成本
            $real = (float) ($og['real_totalprice'] ?? ($og['totalprice'] ?? 0));
            $cost = (float) ($og['cost_price'] ?? ($product['cost_price'] ?? 0)) * $num;
            $profit = $real - $cost;
            return $profit > 0 ? $profit : 0;
        }

        // 默认按商品销售价格
        return (float) ($og['totalprice'] ?? 0);
    }

    /**
     * 租户 admin_set 行
     *
     * @return array<string, mixed>
     */
    protected function sysset(int $aid): array
    {
        $row = Db::name('admin_set')->where('aid', $aid)->find();

        return empty($row) ? ['aid' => $aid] : (array) $row;
    }

    /**
     * 租户等级表缓存（id → 行）
     *
     * @return array<int, array<string, mixed>>
     */
    protected function levelMap(int $aid): array
    {
        $rows = Db::name('member_level')->where('aid', $aid)->select()->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (array) $row;
        }

        return $map;
    }

    /**
     * JSON 字段解析为数组（容错）
     *
     * @return array<int|string, mixed>
     */
    protected function jsonArr(mixed $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : [];
    }
}
