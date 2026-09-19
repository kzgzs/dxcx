<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 奖金池服务（P19-6 批次3-E 任务184）
 *
 * 对标参考系统：
 * - canzhao/app/common/Fenxiao.php bonus_poul@1734 / send_prize_pool@1780（利润池）
 * - canzhao/app/common/Order.php collect_area_prizepool@13798 / collect_reach_prizepool@13889（入池）
 * - canzhao/app/controller/ApiAuto.php auto_area_prizepool@9430 / auto_reach_prizepool@9530（周/月发放）
 *
 * 三池机制：
 * 1. 利润池（prize_pool_*）：订单完成入池，利润=实付−成本×数量（成本取商品 cost_price）；
 *    发放=池金额×send_bili%，按 levelids 等级比例拆分后对等级名单平分（佣金入账）；
 *    每日到 send_time 小时自动发放（当日幂等），后台可手动发放。
 * 2. 区域池（areareach_* type=0）：订单完成按收货省名匹配池，商品按参与设置入池；
 *    周/月发放给配置的 mids 名单平分，发放后清空池。
 * 3. 达标池（areareach_* type=1）：入池同区域池（不限省）；发放时统计周期内
 *    累计确认收货金额≥reach_money 且等级符合 gettj 的会员平分，发放后清空池。
 *
 * 幂等：利润池按 prize_pool_log(orderid+type) 判重；区域/达标池按 money_orderids 判重；
 * 周/月发放按 areareach_prize_pool_send_log(prize_pool_id+send_start_time) 周期判重；
 * 利润池自动发放按当日 prize_pool_send_log(send_type=1) 判重。
 */
class PrizePoolService
{
    /** 省份列表（区域池配置/匹配用，索引即 province_id） */
    public const PROVINCES = [
        '北京', '天津', '河北', '山西', '内蒙古', '辽宁', '吉林', '黑龙江', '上海', '江苏',
        '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南', '广东', '广西',
        '海南', '重庆', '四川', '贵州', '云南', '西藏', '陕西', '甘肃', '青海', '宁夏',
        '新疆', '香港', '澳门', '台湾',
    ];

    /* ================= 订单完成钩子（OrderService.confirm 追加调用） ================= */

    /**
     * 订单确认收货后统一入池入口（利润池 + 区域池 + 达标池，内部各自幂等）
     */
    public function onOrderFinished(int $aid, int $orderId): void
    {
        $this->collectProfitPool($aid, $orderId);
        $this->collectAreaReachPool($aid, $orderId);
    }

    /**
     * 利润池入池（对标 Fenxiao::bonus_poul）：利润=实付−成本×数量
     */
    public function collectProfitPool(int $aid, int $orderId, string $type = 'shop'): void
    {
        $set = Db::name('prize_pool_set')->where('aid', $aid)->find();

        if (empty($set) || 1 !== (int) ($set['status'] ?? 0)) {
            return; // 未配置/未开启利润池不入池
        }

        // 幂等：同一订单只统计一次
        $have = Db::name('prize_pool_log')->where('aid', $aid)->where('orderid', $orderId)->where('type', $type)->find();

        if ($have) {
            return;
        }

        $order = Db::name('shop_order')->where('id', $orderId)->where('aid', $aid)->find();

        if (empty($order)) {
            return;
        }

        $oglist = Db::name('shop_order_goods')->where('orderid', $orderId)->where('aid', $aid)->select()->toArray();

        $poolNum = '0';

        foreach ($oglist as $og) {
            // 实付：优先实际支付金额 real_totalmoney，回退实际销售金额 real_totalprice
            $real = (float) ($og['real_totalmoney'] ?? 0);
            if ($real <= 0) {
                $real = (float) ($og['real_totalprice'] ?? 0);
            }

            // 成本：订单行快照优先，回退商品 cost_price
            $cost = $og['cost_price'] ?? null;
            if (null === $cost || '' === (string) $cost) {
                $cost = Db::name('shop_product')->where('id', (int) ($og['proid'] ?? 0))->value('cost_price');
            }

            $profit = $real - (float) $cost * (int) ($og['num'] ?? 0);
            $poolNum = bcadd($poolNum, (string) round($profit, 2), 2);
        }

        if (bccomp($poolNum, '0', 2) <= 0) {
            return; // 无利润不入池（也不写判重日志，允许后续修正重算）
        }

        Db::name('prize_pool_set')->where('id', (int) $set['id'])->inc('pool_num', (float) $poolNum)->update(['updatetime' => time()]);

        Db::name('prize_pool_log')->insert([
            'aid'        => $aid,
            'mid'        => (int) ($order['mid'] ?? 0),
            'orderid'    => $orderId,
            'type'       => $type,
            'num'        => (float) $poolNum,
            'levelid'    => 0,
            'createtime' => time(),
            'remark'     => '商城订单新增',
        ]);
    }

    /**
     * 区域池 + 达标池入池（对标 Order::collect_area_prizepool / collect_reach_prizepool）
     *
     * 区域池按收货省名匹配（type=0）；达标池不限省（type=1）。
     * 商品参与：shop_product.area_prizepool_set / reach_prizepool_set（0不参与 1比例 2金额）。
     */
    public function collectAreaReachPool(int $aid, int $orderId): void
    {
        $pools = Db::name('areareach_prize_pool_set')->where('aid', $aid)->where('status', 1)->select()->toArray();

        if (empty($pools)) {
            return;
        }

        $order = Db::name('shop_order')->where('id', $orderId)->where('aid', $aid)->find();

        if (empty($order)) {
            return;
        }

        // 收货省：receiver_province 优先，回退 area2 首段（对标参考系统 area2 explode）
        $province = $this->normProvince((string) ($order['receiver_province'] ?? ''));

        if ('' === $province) {
            $area2 = (string) ($order['area2'] ?? '');
            $seg = explode(',', $area2);
            $province = $this->normProvince((string) ($seg[0] ?? ''));
        }

        $oglist = Db::name('shop_order_goods')->where('orderid', $orderId)->where('aid', $aid)->select()->toArray();

        foreach ($oglist as $og) {
            $product = Db::name('shop_product')->where('id', (int) ($og['proid'] ?? 0))->where('aid', $aid)->find();

            if (empty($product)) {
                continue;
            }

            foreach ($pools as $pool) {
                $ptype = (int) ($pool['type'] ?? 0);

                // 区域池必须省名匹配
                if (0 === $ptype && $this->normProvince((string) ($pool['province_name'] ?? '')) !== $province) {
                    continue;
                }

                // 该订单是否已给此池入过（幂等，对标 money_orderids）
                $ids = array_filter(explode(',', (string) ($pool['money_orderids'] ?? '')));

                if (in_array((string) $orderId, $ids, true)) {
                    continue;
                }

                // 商品参与设置：1 按比例（实付×gold%）/ 2 按固定金额
                $pset  = (int) ($product[0 === $ptype ? 'area_prizepool_set' : 'reach_prizepool_set'] ?? 0);
                $gold  = (float) ($product[0 === $ptype ? 'area_prizepool_gold' : 'reach_prizepool_gold'] ?? 0);

                $addMoney = 0.0;

                if (1 === $pset && $gold > 0) {
                    $real = (float) ($og['real_totalmoney'] ?? 0);
                    if ($real <= 0) {
                        $real = (float) ($og['real_totalprice'] ?? 0);
                    }
                    $addMoney = round($real * $gold * 0.01, 2);
                } elseif (2 === $pset && $gold > 0) {
                    $addMoney = $gold;
                }

                if ($addMoney <= 0) {
                    continue;
                }

                Db::name('areareach_prize_pool_set')->where('id', (int) $pool['id'])->update([
                    'money'          => bcadd((string) (float) $pool['money'], (string) $addMoney, 2),
                    'money_orderids' => trim((string) ($pool['money_orderids'] ?? ''), ',') . ',' . $orderId,
                    'updatetime'     => time(),
                ]);

                Db::name('areareach_prize_pool_order_log')->insert([
                    'aid'           => $aid,
                    'bid'           => (int) ($pool['bid'] ?? 0),
                    'type'          => $ptype,
                    'orderid'       => $orderId,
                    'ogid'          => (int) ($og['id'] ?? 0),
                    'prize_pool_id' => (int) $pool['id'],
                    'money'         => $addMoney,
                    'before_money'  => (float) $pool['money'],
                    'remark'        => 0 === $ptype ? '区域奖金池订单入池' : '达标奖金池订单入池',
                    'createtime'    => time(),
                ]);
            }
        }
    }

    /* ================= 利润池发放（对标 Fenxiao::send_prize_pool） ================= */

    /**
     * 利润池发放：池金额×send_bili% 按 levelids 等级比例拆分，等级名单平分入账
     *
     * @param int $sendType 0手动 1自动
     * @return array{status: int, msg: string, sent: int}
     */
    public function sendProfitPool(int $aid, int $sendType = 0): array
    {
        $set = Db::name('prize_pool_set')->where('aid', $aid)->lock(true)->find();

        if (empty($set) || bccomp((string) (float) $set['pool_num'], '0', 2) <= 0) {
            return ['status' => 0, 'msg' => '奖金池金额为0', 'sent' => 0];
        }

        $sendBili = (float) ($set['send_bili'] ?? 0);

        if ($sendBili <= 0) {
            return ['status' => 0, 'msg' => '未配置发放比例', 'sent' => 0];
        }

        $levelids = $this->jsonMap((string) ($set['levelids'] ?? ''));

        if (empty($levelids)) {
            return ['status' => 0, 'msg' => '未配置参与等级', 'sent' => 0];
        }

        $poolNum   = (string) (float) $set['pool_num'];
        $prizeTotal = bcmul($poolNum, (string) ($sendBili / 100), 2);

        if (bccomp($prizeTotal, '0', 2) <= 0) {
            return ['status' => 0, 'msg' => '发放奖金为0', 'sent' => 0];
        }

        // 先扣池（一次性扣发放总额，避免参考系统循环内重复扣减问题）
        Db::name('prize_pool_set')->where('id', (int) $set['id'])->dec('pool_num', (float) $prizeTotal)->update(['updatetime' => time()]);

        $now  = time();
        $sent = 0;

        foreach ($levelids as $levelid => $bili) {
            $levelid    = (int) $levelid;
            $bili       = (float) $bili;
            $levelPrize = bcmul($prizeTotal, (string) ($bili / 100), 4);

            $members = Db::name('member')->where('aid', $aid)->where('levelid', $levelid)->select()->toArray();

            if (empty($members)) {
                continue;
            }

            $avgPrize = bcdiv($levelPrize, (string) count($members), 2);

            if (bccomp($avgPrize, '0', 2) <= 0) {
                continue;
            }

            foreach ($members as $member) {
                $this->addCommission($aid, (int) $member['id'], (float) $avgPrize, '奖金池分红', 'prizepool');

                Db::name('prize_pool_log')->insert([
                    'aid'        => $aid,
                    'mid'        => (int) $member['id'],
                    'orderid'    => 0,
                    'type'       => '',
                    'num'        => -1 * (float) $avgPrize,
                    'levelid'    => $levelid,
                    'createtime' => $now,
                    'remark'     => '奖金池发放',
                ]);
                ++$sent;
            }

            Db::name('prize_pool_send_log')->insert([
                'aid'               => $aid,
                'pool_num'          => (float) $poolNum,
                'send_bili'         => $sendBili,
                'prize_total'       => (float) $prizeTotal,
                'levelid'           => $levelid,
                'level_bili'        => $bili,
                'level_prize_total' => (float) $levelPrize,
                'member_count'      => count($members),
                'send_type'         => $sendType,
                'createtime'        => $now,
            ]);
        }

        return ['status' => 1, 'msg' => '发放成功', 'sent' => $sent];
    }

    /**
     * 利润池自动发放调度（schedule:run 每分钟调用）：
     * 各租户到达 send_time 小时且当日未自动发放过 → 触发发放（当日幂等）
     *
     * @return array{tenants: int, sent: int}
     */
    public function scheduleProfitSend(): array
    {
        $list = Db::name('prize_pool_set')->where('status', 1)->where('pool_num', '>', 0)->select()->toArray();

        $tenants = 0;
        $sent    = 0;

        foreach ($list as $set) {
            $aid = (int) ($set['aid'] ?? 0);

            if ((float) ($set['send_bili'] ?? 0) <= 0 || '' === trim((string) ($set['levelids'] ?? ''))) {
                continue;
            }

            // 时间门：当前小时 >= 配置的发放小时
            if ((int) date('G') < (int) ($set['send_time'] ?? 0)) {
                continue;
            }

            // 当日幂等：今日已有自动发放记录则跳过
            $dayStart = strtotime(date('Y-m-d'));
            $done = Db::name('prize_pool_send_log')
                ->where('aid', $aid)
                ->where('send_type', 1)
                ->where('createtime', '>=', $dayStart)
                ->find();

            if ($done) {
                continue;
            }

            ++$tenants;
            $result = $this->sendProfitPool($aid, 1);
            $sent += (int) ($result['sent'] ?? 0);
        }

        return ['tenants' => $tenants, 'sent' => $sent];
    }

    /* ================= 区域池/达标池 周月发放（对标 ApiAuto::auto_area/reach_prizepool） ================= */

    /**
     * 区域池+达标池发放调度（schedule:run 每分钟调用）
     *
     * 周发放在周一执行（结算上周），月发放在每月1日执行（结算上月）；
     * 周期幂等：areareach_prize_pool_send_log(prize_pool_id + send_start_time) 判重。
     *
     * @param int $onlyType -1全部 0仅区域池 1仅达标池（ScheduleRun 拆分为两个任务分别调用）
     * @return array{pools: int, sent: int}
     */
    public function scheduleAreaReachSend(int $onlyType = -1): array
    {
        $pools = 0;
        $sent  = 0;

        foreach ([0, 1] as $sendType) {
            if ($onlyType >= 0 && $sendType !== $onlyType) {
                continue;
            }

            // 周发放只在周一跑，月发放只在每月1日跑（当日幂等由发放记录保证）
            if (0 === $sendType && '1' !== date('N')) {
                continue;
            }
            if (1 === $sendType && '1' !== date('j')) {
                continue;
            }

            [$sTime, $eTime] = $this->periodRange($sendType);

            $list = Db::name('areareach_prize_pool_set')
                ->where('status', 1)
                ->where('send_type', $sendType)
                ->where('money', '>', 0)
                ->select()
                ->toArray();

            foreach ($list as $pool) {
                // 周期幂等
                $done = Db::name('areareach_prize_pool_send_log')
                    ->where('prize_pool_id', (int) $pool['id'])
                    ->where('send_start_time', $sTime)
                    ->find();

                if ($done) {
                    continue;
                }

                ++$pools;
                $sent += 1 === (int) $pool['type']
                    ? $this->sendReachPool((array) $pool, $sTime, $eTime)
                    : $this->sendAreaPool((array) $pool, $sTime, $eTime);
            }
        }

        return ['pools' => $pools, 'sent' => $sent];
    }

    /**
     * 区域池发放：池金额平分给 mids 名单（对标 auto_area_prizepool）
     */
    protected function sendAreaPool(array $pool, int $sTime, int $eTime): int
    {
        $aid  = (int) ($pool['aid'] ?? 0);
        $mids = array_values(array_filter(array_unique(array_map('intval', explode(',', (string) ($pool['mids'] ?? ''))))));

        $sent = 0;

        if ($mids) {
            $pjMoney = bcdiv((string) (float) $pool['money'], (string) count($mids), 2);

            if (bccomp($pjMoney, '0', 2) > 0) {
                foreach ($mids as $mid) {
                    $this->writeAreaReachSendLog($pool, $mid, (float) $pjMoney, $sTime, $eTime);
                    $this->addCommission($aid, $mid, (float) $pjMoney, '区域奖金池发放', 'area_prizepool');
                    ++$sent;
                }
            }
        }

        $this->resetPoolAfterSend($pool, '新的周期清空奖金池');

        return $sent;
    }

    /**
     * 达标池发放：周期内累计确认收货金额≥reach_money 且等级符合 gettj 的会员平分
     * （对标 auto_reach_prizepool；参考系统为单笔达标，本系统按任务口径改为周期内累计）
     */
    protected function sendReachPool(array $pool, int $sTime, int $eTime): int
    {
        $aid = (int) ($pool['aid'] ?? 0);

        $sent = 0;

        if ((float) ($pool['reach_money'] ?? 0) > 0) {
            $gettj = array_filter(array_map('trim', explode(',', (string) ($pool['gettj'] ?? ''))));

            $query = Db::name('shop_order')
                ->alias('o')
                ->join('member m', 'm.id = o.mid')
                ->where('o.aid', $aid)
                ->where('o.status', 3)
                ->whereBetween('o.paytime', [$sTime, $eTime]);

            if ($gettj && !in_array('-1', $gettj, true)) {
                $query->whereIn('m.levelid', array_map('intval', $gettj));
            }

            // 累计确认收货金额（实付-已退）达标判定
            $mids = $query->group('o.mid')
                ->having('SUM(o.totalprice - o.refund_money) >= ' . (float) $pool['reach_money'])
                ->column('o.mid');

            if ($mids) {
                $pjMoney = bcdiv((string) (float) $pool['money'], (string) count($mids), 2);

                if (bccomp($pjMoney, '0', 2) > 0) {
                    foreach ($mids as $mid) {
                        $this->writeAreaReachSendLog($pool, (int) $mid, (float) $pjMoney, $sTime, $eTime);
                        $this->addCommission($aid, (int) $mid, (float) $pjMoney, '达标奖金池发放', 'reach_prizepool');
                        ++$sent;
                    }
                }
            }
        }

        $this->resetPoolAfterSend($pool, '新的周期清空达标奖金池');

        return $sent;
    }

    /**
     * 写区域/达标池发放记录
     *
     * @param array<string, mixed> $pool
     */
    protected function writeAreaReachSendLog(array $pool, int $mid, float $sendMoney, int $sTime, int $eTime): void
    {
        Db::name('areareach_prize_pool_send_log')->insert([
            'aid'                 => (int) ($pool['aid'] ?? 0),
            'bid'                 => (int) ($pool['bid'] ?? 0),
            'mid'                 => $mid,
            'type'                => (int) ($pool['type'] ?? 0),
            'name'                => (string) ($pool['name'] ?? ''),
            'prize_pool_id'       => (int) ($pool['id'] ?? 0),
            'prize_pool_money'    => (float) ($pool['money'] ?? 0),
            'send_money'          => $sendMoney,
            'send_start_time'     => $sTime,
            'send_end_time'       => $eTime,
            'remark'              => 1 === (int) ($pool['type'] ?? 0) ? '达标奖金池发放' : '区域奖金池发放',
            'createtime'          => time(),
            'prize_pool_set_json' => json_encode($pool, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 发放后清空池金额与入池订单记录（对标参考系统：新周期先清空）
     *
     * @param array<string, mixed> $pool
     */
    protected function resetPoolAfterSend(array $pool, string $remark): void
    {
        Db::name('areareach_prize_pool_set')->where('id', (int) $pool['id'])->update([
            'money'          => 0,
            'money_orderids' => '',
            'updatetime'     => time(),
        ]);

        Db::name('areareach_prize_pool_order_log')->insert([
            'aid'           => (int) ($pool['aid'] ?? 0),
            'bid'           => (int) ($pool['bid'] ?? 0),
            'type'          => (int) ($pool['type'] ?? 0),
            'orderid'       => 0,
            'ogid'          => 0,
            'prize_pool_id' => (int) ($pool['id'] ?? 0),
            'money'         => 0,
            'before_money'  => (float) ($pool['money'] ?? 0),
            'remark'        => $remark,
            'createtime'    => time(),
        ]);
    }

    /**
     * 周期区间：0 上周（周一00:00 ~ 周日23:59）/ 1 上月（1日 ~ 月末）
     *
     * @return array{0: int, 1: int}
     */
    protected function periodRange(int $sendType): array
    {
        if (0 === $sendType) {
            return [(int) strtotime('monday last week'), (int) strtotime('monday this week') - 1];
        }

        return [(int) mktime(0, 0, 0, (int) date('n') - 1, 1), (int) mktime(23, 59, 59, (int) date('n'), 0)];
    }

    /* ================= admin 租户后台侧 ================= */

    /**
     * 利润池配置读取
     *
     * @return array<string, mixed>
     */
    public function profitConfig(int $aid): array
    {
        $row = Db::name('prize_pool_set')->where('aid', $aid)->find();

        if (empty($row)) {
            return [
                'id' => 0, 'aid' => $aid, 'status' => 0, 'pool_num' => '0.00', 'pool_time' => 1,
                'send_bili' => '0.00', 'levelids' => '', 'send_time' => 0, 'show_pool' => 0,
            ];
        }

        return (array) $row;
    }

    /**
     * 利润池配置保存（不存在则创建）
     *
     * @param array<string, mixed> $data
     */
    public function saveProfitConfig(int $aid, array $data): void
    {
        $write = [
            'status'    => (int) ($data['status'] ?? 0) === 1 ? 1 : 0,
            'pool_time' => (int) ($data['pool_time'] ?? 1) === 0 ? 0 : 1,
            'send_bili' => round((float) ($data['send_bili'] ?? 0), 2),
            'levelids'  => (string) ($data['levelids'] ?? ''),
            'send_time' => max(0, min(23, (int) ($data['send_time'] ?? 0))),
            'show_pool' => (int) ($data['show_pool'] ?? 0) === 1 ? 1 : 0,
            'updatetime' => time(),
        ];

        $bili = $write['send_bili'];

        if ($bili < 0 || $bili > 100) {
            $write['send_bili'] = 0;
        }

        $row = Db::name('prize_pool_set')->where('aid', $aid)->find();

        if (empty($row)) {
            $write['aid']        = $aid;
            $write['pool_num']   = 0;
            $write['createtime'] = time();
            Db::name('prize_pool_set')->insert($write);
        } else {
            Db::name('prize_pool_set')->where('id', (int) $row['id'])->update($write);
        }
    }

    /**
     * 区域/达标池配置列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function poolList(int $aid, int $type, int $page = 1, int $pagesize = 20): array
    {
        $query = Db::name('areareach_prize_pool_set')->where('aid', $aid)->where('type', $type);

        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        // 附：发放人昵称（区域池 mids）
        $midSet = [];
        foreach ($list as &$row) {
            $ids = array_filter(array_map('intval', explode(',', (string) ($row['mids'] ?? ''))));
            foreach ($ids as $id) {
                $midSet[$id] = true;
            }
            $row['mids_text'] = '';
        }
        unset($row);

        $nameMap = [];
        if ($midSet) {
            $nameMap = Db::name('member')->where('aid', $aid)->whereIn('id', array_keys($midSet))->column('nickname', 'id');
        }

        foreach ($list as &$row) {
            $ids  = array_filter(array_map('intval', explode(',', (string) ($row['mids'] ?? ''))));
            $text = [];
            foreach ($ids as $id) {
                $text[] = (string) ($nameMap[$id] ?? ('会员' . $id));
            }
            $row['mids_text'] = implode('、', $text);
        }
        unset($row);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 区域/达标池配置保存（id>0 更新，否则新增）
     *
     * @param array<string, mixed> $data
     */
    public function savePool(int $aid, array $data): void
    {
        $id   = (int) ($data['id'] ?? 0);
        $type = (int) ($data['type'] ?? 0) === 1 ? 1 : 0;

        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new \app\common\exception\BusinessException('请填写池名称');
        }

        $provinceId   = 0;
        $provinceName = '';

        if (0 === $type) {
            $provinceName = trim((string) ($data['province_name'] ?? ''));

            if ('' === $provinceName) {
                throw new \app\common\exception\BusinessException('区域奖金池请选择省份');
            }

            $provinceId = array_search($this->normProvince($provinceName), array_map(fn ($p) => $this->normProvince($p), self::PROVINCES), true);
            $provinceId = false === $provinceId ? 0 : (int) $provinceId;
        }

        $write = [
            'aid'           => $aid,
            'status'        => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'type'          => $type,
            'name'          => $name,
            'province_id'   => $provinceId,
            'province_name' => $provinceName,
            'send_type'     => (int) ($data['send_type'] ?? 0) === 1 ? 1 : 0,
            'gettj'         => trim((string) ($data['gettj'] ?? '-1')),
            'reach_money'   => round((float) ($data['reach_money'] ?? 0), 2),
            'remark'        => trim((string) ($data['remark'] ?? '')),
            'updatetime'    => time(),
        ];

        if (0 === $type) {
            // 发放人 mids：数组或逗号串
            $mids = $data['mids'] ?? [];
            $mids = is_array($mids) ? $mids : explode(',', (string) $mids);
            $mids = array_values(array_filter(array_unique(array_map('intval', $mids))));

            if (empty($mids)) {
                throw new \app\common\exception\BusinessException('区域奖金池请选择发放人');
            }

            $write['mids'] = implode(',', $mids);
        }

        if ($id > 0) {
            $row = Db::name('areareach_prize_pool_set')->where('id', $id)->where('aid', $aid)->find();

            if (empty($row)) {
                throw new \app\common\exception\BusinessException('配置不存在', 404, 404);
            }

            Db::name('areareach_prize_pool_set')->where('id', $id)->update($write);
        } else {
            $write['createtime']     = time();
            $write['money']          = 0;
            $write['money_orderids'] = '';
            Db::name('areareach_prize_pool_set')->insert($write);
        }
    }

    /**
     * 区域/达标池配置删除（有余额不允许删）
     */
    public function deletePool(int $aid, int $id): void
    {
        $row = Db::name('areareach_prize_pool_set')->where('id', $id)->where('aid', $aid)->find();

        if (empty($row)) {
            throw new \app\common\exception\BusinessException('配置不存在', 404, 404);
        }

        if ((float) $row['money'] > 0) {
            throw new \app\common\exception\BusinessException('池内尚有余额，请先发放');
        }

        Db::name('areareach_prize_pool_set')->where('id', $id)->delete();
    }

    /**
     * 区域/达标池入池订单日志
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function orderLogList(int $aid, array $filter): array
    {
        $type     = (int) ($filter['type'] ?? -1);
        $poolId   = (int) ($filter['pool_id'] ?? 0);
        $page     = max(1, (int) ($filter['page'] ?? 1));
        $pagesize = max(1, min(100, (int) ($filter['pagesize'] ?? 10)));

        $query = Db::name('areareach_prize_pool_order_log')->where('aid', $aid);

        if ($type >= 0) {
            $query->where('type', $type);
        }
        if ($poolId > 0) {
            $query->where('prize_pool_id', $poolId);
        }

        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        // 附订单号
        $orderIds = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) ($r['orderid'] ?? 0), $list))));

        $numMap = [];
        if ($orderIds) {
            $numMap = Db::name('shop_order')->whereIn('id', $orderIds)->column('ordernum', 'id');
        }

        foreach ($list as &$row) {
            $row['ordernum'] = (string) ($numMap[(int) $row['orderid']] ?? '');
        }
        unset($row);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 发放记录（三池合并视图）：pool_type profit=利润池 area=区域池 reach=达标池
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function sendLogList(int $aid, array $filter): array
    {
        $poolType = (string) ($filter['pool_type'] ?? 'profit');
        $page     = max(1, (int) ($filter['page'] ?? 1));
        $pagesize = max(1, min(100, (int) ($filter['pagesize'] ?? 10)));

        if ('profit' === $poolType) {
            $query = Db::name('prize_pool_send_log')->where('aid', $aid);
            $total = (clone $query)->count();
            $rows  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

            $levelMap = Db::name('member_level')->where('aid', $aid)->column('name', 'id');

            $list = [];
            foreach ($rows as $r) {
                $list[] = [
                    'id'           => (int) $r['id'],
                    'pool_type'    => 'profit',
                    'name'         => '利润奖金池',
                    'mid'          => 0,
                    'nickname'     => '',
                    'send_money'   => (float) $r['prize_total'],
                    'pool_money'   => (float) $r['pool_num'],
                    'level_text'   => (string) ($levelMap[(int) $r['levelid']] ?? ('等级' . $r['levelid'])),
                    'member_count' => (int) $r['member_count'],
                    'send_type'    => (int) $r['send_type'],
                    'createtime'   => (int) $r['createtime'],
                    'detail'       => sprintf('总比例%s%% 等级比例%s%% 等级发放%.2f', $r['send_bili'], $r['level_bili'], $r['level_prize_total']),
                ];
            }

            return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
        }

        $type = 'area' === $poolType ? 0 : 1;

        $query = Db::name('areareach_prize_pool_send_log')->where('aid', $aid)->where('type', $type);
        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $mids = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) ($r['mid'] ?? 0), $rows))));
        $nameMap = [];

        if ($mids) {
            $nameMap = Db::name('member')->where('aid', $aid)->whereIn('id', $mids)->column('nickname', 'id');
        }

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'           => (int) $r['id'],
                'pool_type'    => $poolType,
                'name'         => (string) ($r['name'] ?? ''),
                'mid'          => (int) $r['mid'],
                'nickname'     => (string) ($nameMap[(int) $r['mid']] ?? ('会员' . $r['mid'])),
                'send_money'   => (float) $r['send_money'],
                'pool_money'   => (float) $r['prize_pool_money'],
                'level_text'   => '',
                'member_count' => 0,
                'send_type'    => 1,
                'createtime'   => (int) $r['createtime'],
                'detail'       => sprintf('周期 %s ~ %s', date('Y-m-d', (int) $r['send_start_time']), date('Y-m-d', (int) $r['send_end_time'])),
            ];
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 利润池流水（入池/发放明细）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function profitLogList(int $aid, array $filter): array
    {
        $page     = max(1, (int) ($filter['page'] ?? 1));
        $pagesize = max(1, min(100, (int) ($filter['pagesize'] ?? 10)));

        $query = Db::name('prize_pool_log')->where('aid', $aid);

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $mids = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) ($r['mid'] ?? 0), $rows))));
        $nameMap = [];

        if ($mids) {
            $nameMap = Db::name('member')->where('aid', $aid)->whereIn('id', $mids)->column('nickname', 'id');
        }

        $numMap = [];
        $orderIds = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) ($r['orderid'] ?? 0), $rows))));

        if ($orderIds) {
            $numMap = Db::name('shop_order')->whereIn('id', $orderIds)->column('ordernum', 'id');
        }

        foreach ($rows as &$row) {
            $row['nickname'] = (string) ($nameMap[(int) $row['mid']] ?? '');
            $row['ordernum'] = (string) ($numMap[(int) $row['orderid']] ?? '');
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /* ================= 内部工具 ================= */

    /**
     * 佣金入账（对标参考系统 Member::addcommission）：member.commission 累加 + 流水
     */
    protected function addCommission(int $aid, int $mid, float $money, string $remark, string $fhtype): void
    {
        if ($money <= 0) {
            return;
        }

        $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();

        if (empty($member)) {
            return;
        }

        $after = round((float) ($member['commission'] ?? 0) + $money, 2);

        Db::name('member')->where('id', $mid)->where('aid', $aid)->update([
            'commission'      => $after,
            'totalcommission' => round((float) ($member['totalcommission'] ?? 0) + $money, 2),
        ]);

        Db::name('member_commissionlog')->insert([
            'aid'        => $aid,
            'mid'        => $mid,
            'frommid'    => 0,
            'commission' => $money,
            'after'      => $after,
            'createtime' => time(),
            'remark'     => $remark,
            'fhtype'     => $fhtype,
            'uid'        => 0,
            'fhid'       => 0,
        ]);
    }

    /**
     * 省名归一化：去掉 省/市/自治区/特别行政区 等后缀便于匹配
     */
    protected function normProvince(string $name): string
    {
        $name = trim($name);

        if ('' === $name) {
            return '';
        }

        $suffixes = ['维吾尔自治区', '壮族自治区', '回族自治区', '特别行政区', '自治区', '省', '市'];

        foreach ($suffixes as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return mb_substr($name, 0, mb_strlen($name) - mb_strlen($suffix));
            }
        }

        return $name;
    }

    /**
     * JSON 字符串解析为 map（容错）
     *
     * @return array<int|string, mixed>
     */
    protected function jsonMap(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
