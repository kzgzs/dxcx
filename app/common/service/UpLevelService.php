<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 升级引擎（任务179 批次3-A）
 *
 * 对标参考系统 canzhao/app/common/Member.php：uplv@12 / douplv@97 / upLevel@148 / handleUpLevel@1616
 *
 * - checkUpChain()：本人 + path 上所有上级连锁检查（支付成功后触发）
 * - checkMember()：单会员升级判定（统计业绩 → 遍历更高等级 → 满足则升级）
 * - 与升级申请单（MemberLevelupService）共存：自动升级走本服务，人工申请走申请单审核
 *
 * 升级条件（组内 or / 组间 and，对齐参考系统 $tjor/$hasor/$hasand 语义）：
 * - up_wxpaymoney 累计微信支付 / up_ordermoney 累计商城订单 / up_rechargemoney 累计充值
 * - up_orderprice 单笔订单金额满（up_orderprice_condition）
 * - up_fxordermoney 下级团队总业绩（up_fxorder_condition）
 * - up_fxdowncount/2/3 直推/团队人数（按等级 up_fxdownlevelid、按代数 up_fxdownlevelnum）
 * - up_proid+up_pronum 购买指定商品（up_buygoods_condition）
 * - maxnum 名额上限
 *
 * 升级落库：member.levelid/levelstarttime/levelendtime + 赠送积分/余额/佣金/上级佣金（流水齐全）
 */
class UpLevelService
{
    /**
     * 连锁升级检查：本人 + path 上所有上级（由近及远）
     */
    public function checkUpChain(int $aid, int $mid): void
    {
        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();

        if (empty($member)) {
            return;
        }

        $member = (array) $member;

        // path 异常修复（去空去重）
        if (!empty($member['path'])) {
            $pathArr = array_values(array_filter(array_unique(array_map('trim', explode(',', (string) $member['path'])))));
            $newPath = implode(',', $pathArr);
            if ($newPath !== (string) $member['path']) {
                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['path' => $newPath]);
                $member['path'] = $newPath;
            }
        }

        $this->checkMember($aid, $member);

        if (empty($member['path'])) {
            return;
        }

        $pathIds = array_map('intval', explode(',', (string) $member['path']));
        $parents = Db::name('member')->where('aid', $aid)->whereIn('id', $pathIds)->select()->toArray();
        $parentMap = [];
        foreach ($parents as $row) {
            $parentMap[(int) $row['id']] = (array) $row;
        }

        // 由近及远（path 由远及近）
        foreach (array_reverse($pathIds) as $pid) {
            if (isset($parentMap[$pid])) {
                $this->checkMember($aid, $parentMap[$pid]);
            }
        }
    }

    /**
     * 单会员升级判定与执行
     *
     * @param array<string, mixed> $member
     * @return int 新等级ID（0=未升级）
     */
    public function checkMember(int $aid, array $member): int
    {
        $mid = (int) $member['id'];

        // 业绩统计
        $ordermoney = (float) Db::name('shop_order')
            ->where('aid', $aid)->where('mid', $mid)
            ->whereIn('status', [1, 2, 3])
            ->sum('totalprice');

        $wxpaymoney = (float) Db::name('shop_order')
            ->where('aid', $aid)->where('mid', $mid)
            ->whereIn('status', [1, 2, 3])
            ->where('paytype', 'wechat')
            ->sum('totalprice');

        $rechargemoney = (float) Db::name('recharge_order')
            ->where('aid', $aid)->where('mid', $mid)
            ->where('status', 1)
            ->sum('money');

        // 当前等级
        $nowlv = ['id' => 0, 'sort' => -1, 'cid' => 0];
        if (!empty($member['levelid'])) {
            $lv = Db::name('member_level')->where('aid', $aid)->where('id', (int) $member['levelid'])->find();
            if (!empty($lv)) {
                $nowlv = (array) $lv;
            }
        }

        // 遍历更高等级（sort 升序）
        $lvlist = Db::name('member_level')
            ->where('aid', $aid)
            ->where('cid', (int) ($nowlv['cid'] ?? 0))
            ->where('can_up', 1)
            ->where('sort', '>', (int) ($nowlv['sort'] ?? -1))
            ->order('sort', 'asc')->order('id', 'asc')
            ->select()
            ->toArray();

        $newlv = $nowlv;

        foreach ($lvlist as $lvRow) {
            $lv = (array) $lvRow;

            // 名额上限
            if ((int) ($lv['maxnum'] ?? 0) > 0) {
                $count = (int) Db::name('member')->where('aid', $aid)->where('levelid', (int) $lv['id'])->count();
                if ($count >= (int) $lv['maxnum']) {
                    continue;
                }
            }

            if ($this->matchConditions($aid, $mid, $member, $lv, $ordermoney, $wxpaymoney, $rechargemoney)) {
                $newlv = $lv;
            }
        }

        if ((int) ($newlv['id'] ?? 0) === (int) ($nowlv['id'] ?? 0) || empty($newlv['id'])) {
            return 0;
        }

        $this->applyLevel($aid, $member, $newlv);

        return (int) $newlv['id'];
    }

    /**
     * 升级条件判定（组内 or：任一满足 tjor=true；组间 and：不满足直接 false）
     *
     * @param array<string, mixed> $member
     * @param array<string, mixed> $lv
     */
    protected function matchConditions(int $aid, int $mid, array $member, array $lv, float $ordermoney, float $wxpaymoney, float $rechargemoney): bool
    {
        $tjor  = false; // or 组有一个满足
        $hasor = false; // 存在 or 条件
        $hasand = false; // 存在 and 条件（全部通过）

        // ---- or 基础组：微信支付/商城订单/充值 累计金额 ----
        if ((float) ($lv['up_wxpaymoney'] ?? 0) > 0) {
            $hasor = true;
            if ($wxpaymoney >= (float) $lv['up_wxpaymoney']) {
                $tjor = true;
            }
        }
        if (!$tjor && (float) ($lv['up_ordermoney'] ?? 0) > 0) {
            $hasor = true;
            if ($ordermoney >= (float) $lv['up_ordermoney']) {
                $tjor = true;
            }
        }
        if (!$tjor && (float) ($lv['up_rechargemoney'] ?? 0) > 0) {
            $hasor = true;
            if ($rechargemoney >= (float) $lv['up_rechargemoney']) {
                $tjor = true;
            }
        }

        // ---- 单笔订单金额满（or/and） ----
        if (((!$tjor && 'or' === (string) ($lv['up_orderprice_condition'] ?? 'or')) || 'and' === (string) ($lv['up_orderprice_condition'] ?? 'or'))
            && (float) ($lv['up_orderprice'] ?? 0) > 0) {
            if ('or' === (string) ($lv['up_orderprice_condition'] ?? 'or')) {
                $hasor = true;
            }
            $maxPrice = (float) Db::name('shop_order')
                ->where('aid', $aid)->where('mid', $mid)
                ->whereIn('status', [1, 2, 3])
                ->max('totalprice');
            $ismeet = $maxPrice >= (float) $lv['up_orderprice'];
            if ('or' === (string) ($lv['up_orderprice_condition'] ?? 'or')) {
                if ($ismeet) {
                    $tjor = true;
                }
            } else {
                if (!$ismeet) {
                    return false;
                }
                $hasand = true;
            }
        }

        // ---- 下级团队总业绩（or/and） ----
        if (((!$tjor && 'or' === (string) ($lv['up_fxorder_condition'] ?? 'or')) || 'and' === (string) ($lv['up_fxorder_condition'] ?? 'or'))
            && (float) ($lv['up_fxordermoney'] ?? 0) > 0) {
            if ('or' === (string) ($lv['up_fxorder_condition'] ?? 'or')) {
                $hasor = true;
            }
            $downmids = $this->getdownmids($aid, $mid, (int) ($lv['up_fxorderlevelnum'] ?? 0), (string) ($lv['up_fxdownlevelid'] ?? ($lv['up_fxorderlevelid'] ?? '')));
            $ismeet = false;
            if (!empty($downmids)) {
                $fxordermoney = (float) Db::name('shop_order_goods')
                    ->where('aid', $aid)
                    ->whereIn('mid', $downmids)
                    ->whereIn('status', [1, 2, 3])
                    ->sum('real_totalmoney');
                $ismeet = $fxordermoney >= (float) $lv['up_fxordermoney'];
            }
            if ('or' === (string) ($lv['up_fxorder_condition'] ?? 'or')) {
                if ($ismeet) {
                    $tjor = true;
                }
            } else {
                if (!$ismeet) {
                    return false;
                }
                $hasand = true;
            }
        }

        // ---- 直推/团队人数（三组均满足才通过，属 or 组） ----
        if (!$tjor && ((int) ($lv['up_fxdowncount'] ?? 0) > 0 || (int) ($lv['up_fxdowncount2'] ?? 0) > 0 || (int) ($lv['up_fxdowncount3'] ?? 0) > 0)) {
            $hasor = true;
            $ok = true;
            foreach ([1 => 'up_fxdowncount', 2 => 'up_fxdowncount2', 3 => 'up_fxdowncount3'] as $i => $field) {
                $need = (int) ($lv[$field] ?? 0);
                if ($need <= 0) {
                    continue;
                }
                $levelnumField = 1 === $i ? 'up_fxdownlevelnum' : 'up_fxdownlevelnum' . $i;
                $levelidField  = 1 === $i ? 'up_fxdownlevelid' : 'up_fxdownlevelid' . $i;
                $downmids = $this->getdownmids($aid, $mid, (int) ($lv[$levelnumField] ?? 0), (string) ($lv[$levelidField] ?? ''));
                if (count($downmids) < $need) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $tjor = true;
            }
        }

        // ---- 购买指定商品（or/and） ----
        $upProid = trim((string) ($lv['up_proid'] ?? ''));
        if (((!$tjor && 'or' === (string) ($lv['up_buygoods_condition'] ?? 'or')) || 'and' === (string) ($lv['up_buygoods_condition'] ?? 'or'))
            && '' !== $upProid && '0' !== $upProid) {
            if ('or' === (string) ($lv['up_buygoods_condition'] ?? 'or')) {
                $hasor = true;
            }
            $ismeet = $this->matchBuyGoods($aid, $mid, $upProid, (string) ($lv['up_pronum'] ?? '1'));
            if ('or' === (string) ($lv['up_buygoods_condition'] ?? 'or')) {
                if ($ismeet) {
                    $tjor = true;
                }
            } else {
                if (!$ismeet) {
                    return false;
                }
                $hasand = true;
            }
        }

        // 结论（对齐参考系统：hasor&&!tjor → 否；无条件 → 否；否则 → 是）
        if ($hasor && !$tjor) {
            return false;
        }
        if (!$hasor && !$tjor && !$hasand) {
            return false;
        }

        return true;
    }

    /**
     * 购买指定商品条件判定（多商品按 up_pronum 数量）
     */
    protected function matchBuyGoods(int $aid, int $mid, string $upProid, string $upPronum): bool
    {
        $proids  = array_values(array_filter(array_map('intval', explode(',', str_replace('，', ',', $upProid)))));
        $pronums = array_values(array_map('intval', explode(',', str_replace('，', ',', $upPronum))));

        if (empty($proids)) {
            return false;
        }

        if (count($pronums) > 1) {
            foreach ($proids as $k => $proid) {
                $need = $pronums[$k] ?? 1;
                if ($need <= 0) {
                    $need = 1;
                }
                $buynum = (int) Db::name('shop_order_goods')
                    ->where('aid', $aid)->where('mid', $mid)->where('proid', $proid)
                    ->whereIn('status', [1, 2, 3])
                    ->sum('num');
                if ($buynum >= $need) {
                    return true;
                }
            }
            return false;
        }

        $need = $pronums[0] ?? 1;
        if ($need <= 0) {
            $need = 1;
        }
        $buynum = (int) Db::name('shop_order_goods')
            ->where('aid', $aid)->where('mid', $mid)
            ->whereIn('proid', $proids)
            ->whereIn('status', [1, 2, 3])
            ->sum('num');

        return $buynum >= $need;
    }

    /**
     * 团队下级会员 ID 集合（path find_in_set；levelnum 限制代数，levelids 限制等级）
     *
     * @return array<int, int>
     */
    public function getdownmids(int $aid, int $mid, int $levelnum = 0, string $levelids = ''): array
    {
        $query = Db::name('member')
            ->where('aid', $aid)
            ->whereRaw('find_in_set(' . $mid . ', path)');

        $ids = array_values(array_filter(array_map('intval', explode(',', str_replace('，', ',', $levelids)))));
        if (!empty($ids)) {
            $query->whereIn('levelid', $ids);
        }

        $rows = $query->field('id, path')->select()->toArray();

        $result = [];
        foreach ($rows as $row) {
            if ($levelnum > 0) {
                // 代数 = path 中 mid 之后的上级个数 + 1（mid 紧邻的直接下级为第 1 代）
                $pathArr = array_values(array_filter(explode(',', (string) ($row['path'] ?? ''))));
                $pos = array_search((string) $mid, $pathArr, true);
                if (false === $pos) {
                    continue;
                }
                $gen = count($pathArr) - $pos; // mid 到末端（本人）的节点数即代数
                if ($gen > $levelnum) {
                    continue;
                }
            }
            $result[] = (int) $row['id'];
        }

        return $result;
    }

    /**
     * 执行升级（对标参考系统 handleUpLevel：等级变更 + 有效期 + 奖励发放）
     *
     * @param array<string, mixed> $member
     * @param array<string, mixed> $newlv
     */
    protected function applyLevel(int $aid, array $member, array $newlv): void
    {
        $mid = (int) $member['id'];

        $yxqdate  = (int) ($newlv['yxqdate'] ?? 0);
        $levelEnd = $yxqdate > 0 ? strtotime(date('Y-m-d')) + 86400 + 86400 * $yxqdate : 0;

        $giveScore  = (int) ($newlv['up_give_score'] ?? 0);
        $giveMoney  = round((float) ($newlv['up_give_money'] ?? 0), 2);
        $giveComm   = round((float) ($newlv['up_give_commission'] ?? 0), 2);
        $giveParent = round((float) ($newlv['up_give_parent_money'] ?? 0), 2);

        Db::transaction(function () use ($aid, $member, $mid, $newlv, $levelEnd, $giveScore, $giveMoney, $giveComm, $giveParent) {
            $locked = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();

            if (empty($locked)) {
                return;
            }

            // 升级只升不降（并发保护：若期间已被申请单升到更高等级则放弃）
            $lockedLevel = Db::name('member_level')->where('aid', $aid)->where('id', (int) ($locked['levelid'] ?? 0))->find();
            if (!empty($lockedLevel) && (int) ($lockedLevel['sort'] ?? 0) >= (int) ($newlv['sort'] ?? 0)) {
                return;
            }

            Db::name('member')->where('id', $mid)->where('aid', $aid)->update([
                'levelid'        => (int) $newlv['id'],
                'levelstarttime' => time(),
                'levelendtime'   => $levelEnd,
            ]);

            // 赠送积分
            if ($giveScore > 0) {
                $afterScore = (int) ($locked['score'] ?? 0) + $giveScore;
                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['score' => $afterScore]);
                Db::name('member_scorelog')->insert([
                    'aid'        => $aid,
                    'bid'        => 0,
                    'mid'        => (string) $mid,
                    'score'      => $giveScore,
                    'after'      => $afterScore,
                    'used'       => 0,
                    'createtime' => time(),
                    'remark'     => '升级奖励',
                    'type'       => 1,
                    'channel'    => 'levelup_auto',
                    'status'     => 0,
                    'uid'        => 0,
                ]);
            }

            // 奖励余额
            if ($giveMoney > 0) {
                $afterMoney = round((float) ($locked['money'] ?? 0) + $giveMoney, 2);
                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $afterMoney]);
                Db::name('member_moneylog')->insert([
                    'aid'        => $aid,
                    'mid'        => $mid,
                    'money'      => $giveMoney,
                    'after'      => $afterMoney,
                    'createtime' => time(),
                    'remark'     => '升级奖励',
                    'paytype'    => 'levelup_auto',
                    'uid'        => 0,
                ]);
            }

            // 奖励佣金
            if ($giveComm > 0) {
                $afterComm = round((float) ($locked['commission'] ?? 0) + $giveComm, 2);
                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['commission' => $afterComm]);
                Db::name('member_commissionlog')->insert([
                    'aid'        => $aid,
                    'mid'        => $mid,
                    'frommid'    => 0,
                    'commission' => $giveComm,
                    'after'      => $afterComm,
                    'createtime' => time(),
                    'remark'     => '升级奖励',
                    'fhtype'     => 'levelup_auto_give',
                    'uid'        => 0,
                    'fhid'       => 0,
                ]);
            }

            // 赠送上级余额（直推奖）
            $pid = (int) ($locked['pid'] ?? 0);
            if ($giveParent > 0 && $pid > 0) {
                $parent = Db::name('member')->where('id', $pid)->where('aid', $aid)->lock(true)->find();
                if (!empty($parent)) {
                    $afterParent = round((float) ($parent['money'] ?? 0) + $giveParent, 2);
                    Db::name('member')->where('id', $pid)->where('aid', $aid)->update(['money' => $afterParent]);
                    Db::name('member_moneylog')->insert([
                        'aid'        => $aid,
                        'mid'        => $pid,
                        'money'      => $giveParent,
                        'after'      => $afterParent,
                        'createtime' => time(),
                        'remark'     => '下级升级奖励',
                        'paytype'    => 'levelup_auto_parent',
                        'uid'        => 0,
                    ]);
                }
            }
        });
    }
}
