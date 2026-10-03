<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 签到 + 赠送奖励设置服务（P13-1 客户端营销补全）
 *
 * 表口径：
 * - signset                   签到设置：score(每日积分)/lxqdset,lxzsset(JSON 连续签到/连续赠送规则)/
 *                             status(开关)/display(排名显示)/name/guize/style/bgpic
 * - sign_record               签到记录：mid/nickname 快照/signdate/signtimes/signtimeslx/score/remark
 * - payaftergive              支付后赠配置：money/score/give_coupon+coupon_ids/时间int/gettj/场景
 * - order_collect_reward      确认收货奖励配置：reward_type(1积分 2券 3余额 4佣金)/时间int/
 *                             order_type/platform/gettj(逗号分隔)/满额门槛/每日每月限额
 */
class SignRewardService
{
    public function __construct(
        protected ScoreService $scoreService,
        protected CouponService $couponService
    ) {
    }

    /* ================= 签到设置 ================= */

    /**
     * 读取签到设置（lxqdset/lxzsset 解析为数组）
     *
     * @return array<string, mixed>
     */
    public function getSignSet(int $aid): array
    {
        $row = ScopeService::query('signset', $aid)->find();

        return [
            'score'    => (int) ($row['score'] ?? 0),
            'lxqdset'  => $this->decodeRules((string) ($row['lxqdset'] ?? '')),
            'lxzsset'  => $this->decodeRules((string) ($row['lxzsset'] ?? '')),
            'name'     => (string) ($row['name'] ?? ''),
            'status'   => (int) ($row['status'] ?? 0),
            'display'  => (int) ($row['display'] ?? 0),
            'guize'    => (string) ($row['guize'] ?? ''),
            'style'    => (int) ($row['style'] ?? 1),
            'bgpic'    => (string) ($row['bgpic'] ?? ''),
            'rewardedvideoad' => (string) ($row['rewardedvideoad'] ?? ''),
        ];
    }

    /**
     * 保存签到设置（按 aid upsert）
     *
     * @param array<string, mixed> $data
     */
    public function saveSignSet(int $aid, array $data): void
    {
        $score = $data['score'] ?? 0;

        if (!is_numeric($score) || (int) $score < 0) {
            throw new BusinessException('每日签到积分须为不小于0的整数');
        }

        $status  = (int) ($data['status'] ?? 0);
        $display = (int) ($data['display'] ?? 0);

        if (!in_array($status, [0, 1], true) || !in_array($display, [0, 1], true)) {
            throw new BusinessException('开关值无效');
        }

        $update = [
            'score'   => (int) $score,
            'lxqdset' => json_encode($this->normalizeRules($data['lxqdset'] ?? []), JSON_UNESCAPED_UNICODE),
            'lxzsset' => json_encode($this->normalizeRules($data['lxzsset'] ?? []), JSON_UNESCAPED_UNICODE),
            'name'    => trim((string) ($data['name'] ?? '')),
            'status'  => $status,
            'display' => $display,
            'guize'   => trim((string) ($data['guize'] ?? '')),
            'rewardedvideoad' => trim((string) ($data['rewardedvideoad'] ?? '')),
        ];

        if (array_key_exists('style', $data)) {
            $update['style'] = (int) $data['style'];
        }

        if (array_key_exists('bgpic', $data)) {
            $update['bgpic'] = trim((string) $data['bgpic']);
        }

        $exists = ScopeService::query('signset', $aid)->find();

        if ($exists) {
            Db::name('signset')->where('id', (int) $exists['id'])->update($update);
        } else {
            $update['aid'] = $aid;

            Db::name('signset')->insert($update);
        }
    }

    /* ================= 签到记录 ================= */

    /**
     * 签到记录列表（mid/nickname/时间范围筛选）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function signRecords(int $aid, int $mid, string $nickname, int $startTime, int $endTime, int $page, int $pageSize, string $field = '', string $order = 'desc'): array
    {
        $query = ScopeService::queryAs('sign_record', $aid, 's')
            ->leftJoin('member m', 'm.id = s.mid')
            ->field('s.*, m.tel as member_phone');

        if ($mid > 0) {
            $query->where('s.mid', $mid);
        }

        if ('' !== $nickname) {
            $query->where('s.nickname', 'like', '%' . $nickname . '%');
        }

        if ($startTime > 0) {
            $query->where('s.createtime', '>=', $startTime);
        }

        if ($endTime > 0) {
            $query->where('s.createtime', '<', $endTime);
        }

        $total = (clone $query)->count();

        // 排序白名单（对齐点大 record 页 ID/获得积分/签到总次数/连续次数 表头排序）
        $allowedSort = ['id' => 's.id', 'score' => 's.score', 'signtimes' => 's.signtimes', 'signtimeslx' => 's.signtimeslx'];
        $sortCol     = $allowedSort[$field] ?? '';
        $sortDir     = 'asc' === strtolower($order) ? 'asc' : 'desc';

        $rows = $query->order($sortCol ?: 's.id', $sortDir)
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $couponNames = $this->couponNameMap($aid, $rows);

        $list = array_map(function (array $row) use ($couponNames) {
            $lxqd = (int) ($row['lxqd_coupon_id'] ?? 0);
            $lxzs = (int) ($row['lxzs_coupon_id'] ?? 0);

            return [
                'id'               => (int) ($row['id'] ?? 0),
                'mid'              => (int) ($row['mid'] ?? 0),
                'nickname'         => (string) ($row['nickname'] ?? ''),
                'headimg'          => (string) ($row['headimg'] ?? ''),
                'member_phone'     => (string) ($row['member_phone'] ?? ''),
                'signdate'         => (string) ($row['signdate'] ?? ''),
                'signtimes'        => (int) ($row['signtimes'] ?? 0),
                'signtimeslx'      => (int) ($row['signtimeslx'] ?? 0),
                'score'            => (int) ($row['score'] ?? 0),
                'remark'           => (string) ($row['remark'] ?? ''),
                'create_time'      => (int) ($row['createtime'] ?? 0),
                'lxqd_coupon_id'   => $lxqd,
                'lxqd_coupon_name' => $lxqd > 0 ? ($couponNames[$lxqd] ?? '') : '',
                'lxzs_coupon_id'   => $lxzs,
                'lxzs_coupon_name' => $lxzs > 0 ? ($couponNames[$lxzs] ?? '') : '',
            ];
        }, $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 删除签到记录
     *
     * @param int[] $ids
     */
    public function deleteSignRecords(int $aid, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v) => $v > 0)));

        if (empty($ids)) {
            throw new BusinessException('缺少记录ID');
        }

        ScopeService::query('sign_record', $aid)->whereIn('id', $ids)->delete();
    }

    /**
     * 导出签到记录 CSV（与列表同过滤条件；返回 csv 字符串）
     *
     * @return string
     */
    public function exportSignRecords(int $aid, int $mid, string $nickname, int $startTime, int $endTime): string
    {
        $query = ScopeService::queryAs('sign_record', $aid, 's')
            ->leftJoin('member m', 'm.id = s.mid')
            ->field('s.*, m.tel as member_phone');

        if ($mid > 0) {
            $query->where('s.mid', $mid);
        }

        if ('' !== $nickname) {
            $query->where('s.nickname', 'like', '%' . $nickname . '%');
        }

        if ($startTime > 0) {
            $query->where('s.createtime', '>=', $startTime);
        }

        if ($endTime > 0) {
            $query->where('s.createtime', '<', $endTime);
        }

        $rows = $query->order('s.id', 'desc')->limit(10000)->select()->toArray();

        $couponNames = $this->couponNameMap($aid, $rows);

        $fp = fopen('php://temp', 'r+');
        // BOM 头解决 Excel 中文乱码
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['ID', '头像昵称', '签到时间', '获得积分', '签到总次数', '连续次数', '连续签到优惠券', '连续签到赠送优惠券', '备注', '创建时间']);

        foreach ($rows as $row) {
            $lxqd = (int) ($row['lxqd_coupon_id'] ?? 0);
            $lxzs = (int) ($row['lxzs_coupon_id'] ?? 0);

            fputcsv($fp, [
                (int) ($row['id'] ?? 0),
                (string) ($row['nickname'] ?? ''),
                (string) ($row['signdate'] ?? ''),
                (int) ($row['score'] ?? 0),
                (int) ($row['signtimes'] ?? 0),
                (int) ($row['signtimeslx'] ?? 0),
                $lxqd > 0 ? ($couponNames[$lxqd] ?? '无') : '无',
                $lxzs > 0 ? ($couponNames[$lxzs] ?? '无') : '无',
                (string) ($row['remark'] ?? ''),
                isset($row['createtime']) ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
            ]);
        }

        rewind($fp);
        $csv = (string) stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }

    /* ================= 支付后赠配置（payaftergive） ================= */

    /**
     * 读取支付后赠配置
     *
     * @return array<string, mixed>
     */
    public function getPayaftergive(int $aid): array
    {
        $row = ScopeService::query('payaftergive', $aid)->where('bid', 0)->order('id', 'desc')->find();

        return [
            'name'          => (string) ($row['name'] ?? ''),
            'gettj'         => (string) ($row['gettj'] ?? '-1'),
            'paygive_scene' => (string) ($row['paygive_scene'] ?? ''),
            'limittimes'    => (int) ($row['limittimes'] ?? 0),
            'pricestart'    => (float) ($row['pricestart'] ?? 0),
            'priceend'      => (float) ($row['priceend'] ?? 0),
            'starttime'     => (int) ($row['starttime'] ?? 0),
            'endtime'       => (int) ($row['endtime'] ?? 0),
            'money'         => (float) ($row['money'] ?? 0),
            'score'         => (int) ($row['score'] ?? 0),
            'give_coupon'   => (int) ($row['give_coupon'] ?? 0),
            'coupon_ids'    => (string) ($row['coupon_ids'] ?? ''),
            'tourl'         => (string) ($row['tourl'] ?? ''),
            'btntext'       => (string) ($row['btntext'] ?? ''),
            'status'        => (int) ($row['status'] ?? 0),
            'sort'          => (int) ($row['sort'] ?? 0),
        ];
    }

    /**
     * 保存支付后赠配置（按 aid+bid=0 upsert；金额/积分至少配置一项或赠券）
     *
     * @param array<string, mixed> $data
     */
    public function savePayaftergive(int $aid, array $data): void
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写活动名称');
        }

        $money = $this->parseAmount($data['money'] ?? 0, '赠送余额');
        $score = (int) ($data['score'] ?? 0);

        if ($score < 0) {
            throw new BusinessException('赠送积分不能为负');
        }

        $giveCoupon = (int) ($data['give_coupon'] ?? 0);

        if (!in_array($giveCoupon, [0, 1], true)) {
            throw new BusinessException('赠券开关值无效');
        }

        $couponIds = $this->normalizeCsv($data['coupon_ids'] ?? '');

        if (1 === $giveCoupon && '' === $couponIds) {
            throw new BusinessException('请选择赠送的优惠券');
        }

        if ($money <= 0 && $score <= 0 && 0 === $giveCoupon) {
            throw new BusinessException('赠送余额/积分/赠券至少配置一项');
        }

        $start = $this->stampOf($data['starttime'] ?? 0);
        $end   = $this->stampOf($data['endtime'] ?? 0);

        if ($start > 0 && $end > 0 && $end <= $start) {
            throw new BusinessException('活动结束时间必须晚于开始时间');
        }

        $pricestart = $this->parseAmount($data['pricestart'] ?? 0, '金额下限');
        $priceend   = $this->parseAmount($data['priceend'] ?? 0, '金额上限');

        if ($priceend > 0 && $pricestart > $priceend) {
            throw new BusinessException('金额下限不能大于上限');
        }

        $status = (int) ($data['status'] ?? 0);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $update = [
            'name'          => $name,
            'gettj'         => $this->normalizeCsv($data['gettj'] ?? '-1') ?: '-1',
            'paygive_scene' => $this->normalizeCsv($data['paygive_scene'] ?? ''),
            'limittimes'    => max(0, (int) ($data['limittimes'] ?? 0)),
            'pricestart'    => $pricestart,
            'priceend'      => $priceend,
            'starttime'     => $start,
            'endtime'       => $end,
            'money'         => $money,
            'score'         => $score,
            'give_coupon'   => $giveCoupon,
            'coupon_ids'    => 1 === $giveCoupon ? $couponIds : '',
            'tourl'         => trim((string) ($data['tourl'] ?? '')),
            'btntext'       => trim((string) ($data['btntext'] ?? '')),
            'status'        => $status,
            'sort'          => (int) ($data['sort'] ?? 0),
        ];

        $exists = ScopeService::query('payaftergive', $aid)->where('bid', 0)->order('id', 'desc')->find();

        if ($exists) {
            Db::name('payaftergive')->where('id', (int) $exists['id'])->update($update);
        } else {
            $update['aid']        = $aid;
            $update['bid']        = 0;
            $update['createtime'] = time();

            Db::name('payaftergive')->insert($update);
        }
    }

    /* ================= 确认收货奖励配置（order_collect_reward） ================= */

    /**
     * 读取确认收货奖励配置（逗号分隔字段解析为数组）
     *
     * @return array<string, mixed>
     */
    public function getCollectReward(int $aid): array
    {
        $row = ScopeService::query('order_collect_reward', $aid)->order('id', 'desc')->find();

        $couponId   = (int) ($row['coupon_id'] ?? 0);
        $couponName = '';

        if ($couponId > 0) {
            $couponName = (string) (ScopeService::query('coupon', $aid)->where('id', $couponId)->value('name') ?? '');
        }

        return [
            'start_time'         => (int) ($row['start_time'] ?? 0),
            'end_time'           => (int) ($row['end_time'] ?? 0),
            'reward_type'        => (int) ($row['reward_type'] ?? 0),
            'score'              => (int) ($row['score'] ?? 0),
            'coupon_id'          => $couponId,
            'coupon_name'        => $couponName,
            'money'              => (float) ($row['money'] ?? 0),
            'commission'         => (float) ($row['commission'] ?? 0),
            'order_type'         => $this->csvToArray((string) ($row['order_type'] ?? '')),
            'platform'           => $this->csvToArray((string) ($row['platform'] ?? '')),
            'gettj'              => $this->csvToArray((string) ($row['gettj'] ?? '-1')) ?: ['-1'],
            'min_order_amount'   => (float) ($row['min_order_amount'] ?? 0),
            'max_daily_return'   => (int) ($row['max_daily_return'] ?? 0),
            'max_month_return'   => (int) ($row['max_month_return'] ?? 0),
            'prompt'             => (string) ($row['prompt'] ?? ''),
            'bgcolor'            => (string) ($row['bgcolor'] ?? '#EF9B39'),
            'fontcolor'          => (string) ($row['fontcolor'] ?? ''),
            'status'             => (int) ($row['status'] ?? 0),
        ];
    }

    /**
     * 保存确认收货奖励配置（按 aid upsert）
     *
     * @param array<string, mixed> $data
     */
    public function saveCollectReward(int $aid, array $data): void
    {
        $rewardType = (int) ($data['reward_type'] ?? 0);

        if (!in_array($rewardType, [1, 2, 3, 4], true)) {
            throw new BusinessException('奖励类型无效（1积分 2优惠券 3余额 4佣金）');
        }

        $score      = (int) ($data['score'] ?? 0);
        $couponId   = (int) ($data['coupon_id'] ?? 0);
        $money      = $this->parseAmount($data['money'] ?? 0, '奖励余额');
        $commission = $this->parseAmount($data['commission'] ?? 0, '奖励佣金');

        if (1 === $rewardType && $score <= 0) {
            throw new BusinessException('请输入奖励积分');
        }

        if (2 === $rewardType && $couponId <= 0) {
            throw new BusinessException('请选择奖励优惠券');
        }

        if (3 === $rewardType && $money <= 0) {
            throw new BusinessException('请输入奖励余额');
        }

        if (4 === $rewardType && $commission <= 0) {
            throw new BusinessException('请输入奖励佣金');
        }

        if (2 === $rewardType) {
            $exists = ScopeService::query('coupon', $aid)->where('id', $couponId)->where('deleted', 0)->find();

            if (empty($exists)) {
                throw new BusinessException('奖励优惠券不存在');
            }
        }

        $start = $this->stampOf($data['start_time'] ?? 0);
        $end   = $this->stampOf($data['end_time'] ?? 0);

        if ($start <= 0 || $end <= 0) {
            throw new BusinessException('请选择活动时间');
        }

        if ($end <= $start) {
            throw new BusinessException('活动结束时间必须晚于开始时间');
        }

        $orderType = $this->normalizeCsv($data['order_type'] ?? []);
        $platform  = $this->normalizeCsv($data['platform'] ?? []);
        $gettj     = $this->normalizeCsv($data['gettj'] ?? []);

        if ('' === $orderType) {
            throw new BusinessException('请选择订单范围');
        }

        if ('' === $platform) {
            throw new BusinessException('请选择使用平台');
        }

        if ('' === $gettj) {
            throw new BusinessException('请选择参与条件');
        }

        $prompt = trim((string) ($data['prompt'] ?? ''));

        if (mb_strlen($prompt) > 20) {
            throw new BusinessException('引导话术不能超过20个字符');
        }

        $status = (int) ($data['status'] ?? 0);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $update = [
            'start_time'       => $start,
            'end_time'         => $end,
            'reward_type'      => $rewardType,
            'score'            => 1 === $rewardType ? $score : 0,
            'coupon_id'        => 2 === $rewardType ? $couponId : 0,
            'money'            => 3 === $rewardType ? $money : 0.0,
            'commission'       => 4 === $rewardType ? $commission : 0.0,
            'order_type'       => $orderType,
            'platform'         => $platform,
            'gettj'            => $gettj,
            'min_order_amount' => $this->parseAmount($data['min_order_amount'] ?? 0, '满额门槛'),
            'max_daily_return' => max(0, (int) ($data['max_daily_return'] ?? 0)),
            'max_month_return' => max(0, (int) ($data['max_month_return'] ?? 0)),
            'prompt'           => $prompt,
            'bgcolor'          => trim((string) ($data['bgcolor'] ?? '#EF9B39')),
            'fontcolor'        => trim((string) ($data['fontcolor'] ?? '')),
            'status'           => $status,
        ];

        $exists = ScopeService::query('order_collect_reward', $aid)->order('id', 'desc')->find();

        if ($exists) {
            Db::name('order_collect_reward')->where('id', (int) $exists['id'])->update($update);
        } else {
            $update['aid']        = $aid;
            $update['bid']        = 0;
            $update['createtime'] = time();

            Db::name('order_collect_reward')->insert($update);
        }
    }

    /* ================= C 端核心链路（api 预留） ================= */

    /**
     * 签到动作（member 行锁 + 防当日重复 + 连签奖励 + 送券同事务）
     *
     * 口径对照参考系统 ApiSign::signin：
     * - signtimeslx 以「昨天起连续有签到记录」倒推 +1（断签重置为1）
     * - lxqdset 连签>=天数：替换当日积分（取命中档）+ 送券
     * - lxzsset 连签==天数：追加积分 + 送券
     *
     * @return array{scoreadd: int, signtimes: int, signtimeslx: int, lxqd_coupon_id: int, lxzs_coupon_id: int}
     */
    public function sign(int $aid, int $mid): array
    {
        return Db::transaction(function () use ($aid, $mid) {
            $signset = ScopeService::query('signset', $aid)->order('id', 'desc')->find();

            if (empty($signset) || 1 !== (int) ($signset['status'] ?? 0)) {
                throw new BusinessException('签到活动尚未开启');
            }

            $member = Db::name('member')
                ->where('aid', $aid)->where('id', $mid)->lock(true)->find();

            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            $today = date('Y-m-d');

            if ((string) ($member['signdate'] ?? '') === $today) {
                throw new BusinessException('您今天已经签到过了');
            }

            $score     = (int) ($signset['score'] ?? 0);
            $signtimes = (int) ($member['signtimes'] ?? 0) + 1;

            // 以昨天起倒推连签天数（断签即止）
            $signtimeslx = 1;
            for ($i = 1; $i <= $signtimes; ++$i) {
                $date = date('Y-m-d', strtotime('-' . $i . ' day'));
                $hit  = Db::name('sign_record')
                    ->where('aid', $aid)->where('mid', $mid)->where('signdate', $date)
                    ->value('signdate');

                if (empty($hit)) {
                    break;
                }

                $signtimeslx = $i + 1;
            }

            $remark         = '签到';
            $lxqdCouponId   = 0;
            $lxzsCouponId   = 0;
            $extraScore     = 0;

            // 连续签到档：>=days 替换当日积分 + 送券
            foreach ($this->decodeRules((string) ($signset['lxqdset'] ?? '')) as $rule) {
                $days = (int) ($rule['days'] ?? 0);

                if ($days <= 0 || $signtimeslx < $days) {
                    continue;
                }

                if ((int) ($rule['score'] ?? 0) > 0) {
                    $score  = (int) $rule['score'];
                    $remark = '连续签到';
                }

                $cid = (int) ($rule['coupon_id'] ?? 0);

                if ($cid > 0) {
                    try {
                        $this->couponService->receive($aid, $mid, $cid);
                        $lxqdCouponId = $cid;
                    } catch (\Throwable) {
                        // 券配置失效不阻断签到主链路
                    }
                }
            }

            // 连续赠送档：==days 追加积分 + 送券
            foreach ($this->decodeRules((string) ($signset['lxzsset'] ?? '')) as $rule) {
                $days = (int) ($rule['days'] ?? 0);

                if ($days <= 0 || $signtimeslx !== $days) {
                    continue;
                }

                if ((int) ($rule['score'] ?? 0) > 0) {
                    $extraScore = (int) $rule['score'];
                    $remark    .= ' + 赠送';
                }

                $cid = (int) ($rule['coupon_id'] ?? 0);

                if ($cid > 0) {
                    try {
                        $this->couponService->receive($aid, $mid, $cid);
                        $lxzsCouponId = $cid;
                    } catch (\Throwable) {
                        // 券配置失效不阻断签到主链路
                    }
                }
            }

            $totalScore = $score + $extraScore;

            Db::name('sign_record')->insert([
                'aid'             => $aid,
                'mid'             => $mid,
                'nickname'        => (string) ($member['nickname'] ?? ''),
                'headimg'         => (string) ($member['headimg'] ?? ''),
                'signdate'        => $today,
                'signtimes'       => $signtimes,
                'signtimeslx'     => $signtimeslx,
                'score'           => $totalScore,
                'remark'          => $remark,
                'createtime'      => time(),
                'lxqd_coupon_id'  => $lxqdCouponId,
                'lxzs_coupon_id'  => $lxzsCouponId,
            ]);

            Db::name('member')->where('aid', $aid)->where('id', $mid)->update([
                'signdate'    => $today,
                'signtime'    => time(),
                'signtimes'   => $signtimes,
                'signtimeslx' => $signtimeslx,
            ]);

            if ($totalScore > 0) {
                $this->scoreService->adjust($aid, $mid, $totalScore, '签到赠积分');
            }

            return [
                'scoreadd'       => $totalScore,
                'signtimes'      => $signtimes,
                'signtimeslx'    => $signtimeslx,
                'lxqd_coupon_id' => $lxqdCouponId,
                'lxzs_coupon_id' => $lxzsCouponId,
            ];
        });
    }

    /**
     * 签到页数据（设置 + 会员签到态 + 日历已签日期 + 连签排名）
     *
     * @return array<string, mixed>
     */
    public function signPage(int $aid, int $mid): array
    {
        $set      = $this->getSignSet($aid);
        $userinfo = Db::name('member')
            ->field('id,nickname,headimg,score,signtimes,signtimeslx,signtime,signdate')
            ->where('aid', $aid)->where('id', $mid)->find();

        $dates = Db::name('sign_record')
            ->where('aid', $aid)->where('mid', $mid)
            ->order('createtime', 'desc')->limit(60)
            ->column('signdate');

        $selectedDate = array_map(
            static fn ($date) => ['date' => (string) $date, 'info' => '已签'],
            array_values(array_unique(array_filter($dates)))
        );

        $data = [
            'signset'      => $set,
            'userinfo'     => $userinfo ?: [],
            'hassign'      => (string) ($userinfo['signdate'] ?? '') === date('Y-m-d') ? 1 : 0,
            'today'        => date('Y-m-d'),
            'selectedDate' => $selectedDate,
        ];

        if (1 === (int) ($set['display'] ?? 0)) {
            $data['list'] = Db::name('member')
                ->where('aid', $aid)
                ->where('signtime', '>=', strtotime('yesterday'))
                ->where('signtimeslx', '>', 0)
                ->field('id,nickname,headimg,signtimes,signtimeslx,signtime')
                ->order('signtimeslx', 'desc')
                ->limit(10)
                ->select()
                ->toArray();
        }

        return $data;
    }

    /**
     * 我的签到记录（C 端分页）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiRecords(int $aid, int $mid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('sign_record', $aid)->where('mid', $mid);

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /* ================= 内部工具 ================= */

    /**
     * 连续签到/连续赠送规则归一化：[{days, score, coupon_id, coupon_name}]
     *
     * @param mixed $rules
     * @return array<int, array{days: int, score: int, coupon_id: int, coupon_name: string}>
     */
    protected function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        if (!is_array($rules)) {
            throw new BusinessException('连续签到规则格式无效');
        }

        $list = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $days = (int) ($rule['days'] ?? 0);

            if ($days <= 0) {
                continue; // 空行跳过
            }

            $score = (int) ($rule['score'] ?? 0);

            if ($score < 0) {
                throw new BusinessException('规则积分不能为负');
            }

            $list[] = [
                'days'        => $days,
                'score'       => $score,
                'coupon_id'   => (int) ($rule['coupon_id'] ?? 0),
                'coupon_name' => trim((string) ($rule['coupon_name'] ?? '')),
            ];
        }

        // 天数升序，保证命中逻辑（>=days / ==days）按档递进
        usort($list, static fn (array $a, array $b) => $a['days'] <=> $b['days']);

        return $list;
    }

    /**
     * JSON 规则串解析为数组（容错空串/坏 JSON）
     *
     * @return array<int, mixed>
     */
    protected function decodeRules(string $json): array
    {
        if ('' === trim($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 记录行中的赠券ID → 券名称映射
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    protected function couponNameMap(int $aid, array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            foreach (['lxqd_coupon_id', 'lxzs_coupon_id'] as $key) {
                $cid = (int) ($row[$key] ?? 0);

                if ($cid > 0) {
                    $ids[$cid] = true;
                }
            }
        }

        if (empty($ids)) {
            return [];
        }

        $names = ScopeService::query('coupon', $aid)
            ->whereIn('id', array_keys($ids))
            ->column('name', 'id');

        $map = [];

        foreach ($names as $cid => $name) {
            $map[(int) $cid] = (string) $name;
        }

        return $map;
    }

    /**
     * 金额解析（两位小数校验）
     */
    protected function parseAmount(mixed $value, string $label): float
    {
        if (null === $value || '' === $value) {
            return 0.0;
        }

        $str = (string) $value;

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $str)) {
            throw new BusinessException($label . '格式无效（最多两位小数）');
        }

        return (float) $str;
    }

    /**
     * 逗号分隔值归一化（数组/字符串均可；'-1' 保留表示不限制）
     */
    protected function normalizeCsv(mixed $value): string
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = explode(',', (string) $value);
        }

        $parts = array_filter(array_map(static fn ($v) => trim((string) $v), $parts), static fn ($v) => '' !== $v);

        if (empty($parts)) {
            return '';
        }

        if (in_array('-1', $parts, true)) {
            return '-1';
        }

        return implode(',', array_unique($parts));
    }

    /**
     * 逗号分隔串转数组
     *
     * @return string[]
     */
    protected function csvToArray(string $value): array
    {
        if ('' === trim($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => '' !== $v));
    }

    /**
     * 时间值 → Unix 秒（int/数字串/日期时间串均可）
     */
    protected function stampOf(mixed $value): int
    {
        if (is_int($value)) {
            return max($value, 0);
        }

        $str = trim((string) $value);

        if ('' === $str) {
            return 0;
        }

        if (ctype_digit($str)) {
            return (int) $str;
        }

        return strtotime($str) ?: 0;
    }
}
