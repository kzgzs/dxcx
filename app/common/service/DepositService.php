<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 餐饮寄存服务（批次6-T6-3 任务204）
 *
 * 状态机：0审核中 → 1寄存中（B端 check 通过）/ 3未通过；1 → 2全部取出
 * 部分取出：仅扣 num 并写 log，订单保持 status=1
 * 对标 canzhao ApiAdminRestaurantDepositOrder / ApiRestaurantDeposit，
 * 位置字典（restaurant_deposit_position / _number）为自研扩展
 */
class DepositService
{
    /** 审核中 */
    public const STATUS_PENDING = 0;
    /** 寄存中 */
    public const STATUS_DEPOSITING = 1;
    /** 全部取出 */
    public const STATUS_TAKEN = 2;
    /** 未通过 */
    public const STATUS_REFUSED = 3;

    /**
     * 状态文案
     */
    public function statusLabel(int $status): string
    {
        return match ($status) {
            self::STATUS_PENDING    => '审核中',
            self::STATUS_DEPOSITING => '寄存中',
            self::STATUS_TAKEN      => '全部取出',
            self::STATUS_REFUSED    => '未通过',
            default                 => '未知',
        };
    }

    /**
     * 寄存设置行（不存在则初始化；一期 bid=0）
     *
     * @return array{id:int, aid:int, bid:int, time:string, status:int}
     */
    public function syssetRow(int $aid, int $bid = 0): array
    {
        $row = Db::name('restaurant_deposit_sysset')
            ->where('aid', $aid)->where('bid', $bid)->order('id', 'asc')->find();

        if (empty($row)) {
            $newId = (int) Db::name('restaurant_deposit_sysset')->insertGetId([
                'aid'    => $aid,
                'bid'    => $bid,
                'time'   => '',
                'status' => 0,
            ]);

            $row = ['id' => $newId, 'aid' => $aid, 'bid' => $bid, 'time' => '', 'status' => 0];
        }

        return [
            'id'     => (int) $row['id'],
            'aid'    => (int) $row['aid'],
            'bid'    => (int) $row['bid'],
            'time'   => (string) $row['time'],
            'status' => (int) $row['status'],
        ];
    }

    /**
     * C端/B端 新增寄存申请（status=0 待审核）
     *
     * @return int 订单id
     */
    public function createOrder(int $aid, int $mid, array $data, string $platform = 'wx'): int
    {
        $name    = trim((string) ($data['name'] ?? ''));
        $num     = (int) ($data['num'] ?? 0);
        $linkman = trim((string) ($data['linkman'] ?? ''));
        $tel     = trim((string) ($data['tel'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写物品名称');
        }

        if ($num <= 0) {
            throw new BusinessException('请填写寄存数量');
        }

        if ('' === $linkman) {
            throw new BusinessException('请填写联系人');
        }

        if ('' === $tel) {
            throw new BusinessException('请填写联系电话');
        }

        $bid = (int) ($data['bid'] ?? 0); // 一期固定 0

        return (int) Db::name('restaurant_deposit_order')->insertGetId([
            'aid'           => $aid,
            'bid'           => $bid,
            'mid'           => $mid,
            'name'          => $name,
            'num'           => (string) $num,
            'linkman'       => $linkman,
            'tel'           => $tel,
            'pic'           => (string) ($data['pic'] ?? ''),
            'message'       => (string) ($data['message'] ?? ''),
            'position_id'   => (int) ($data['position_id'] ?? 0),
            'position_name' => (string) ($data['position_name'] ?? ''),
            'number_id'     => (int) ($data['number_id'] ?? 0),
            'number_name'   => (string) ($data['number_name'] ?? ''),
            'tableid'       => (int) ($data['tableid'] ?? 0),
            'createtime'    => time(),
            'status'        => self::STATUS_PENDING,
            'platform'      => $platform,
        ]);
    }

    /**
     * B端录入寄存（直接 status=1 寄存中，并写存入 log）
     *
     * @return int 订单id
     */
    public function adminAdd(int $aid, array $data, int $waiterId): int
    {
        $orderId = $this->createOrder($aid, (int) ($data['mid'] ?? 0), $data, 'admin');

        Db::name('restaurant_deposit_order')->where('id', $orderId)->update([
            'status'    => self::STATUS_DEPOSITING,
            'waiter_id' => $waiterId,
        ]);

        $this->writeLog([
            'aid'       => $aid,
            'bid'       => (int) ($data['bid'] ?? 0),
            'order_id'  => $orderId,
            'mid'       => (int) ($data['mid'] ?? 0),
            'num'       => (string) (int) ($data['num'] ?? 0),
            'type'      => 0,
            'createtime' => time(),
            'platform'  => 'admin',
            'remark'    => '后台存入',
            'waiter_id' => $waiterId,
        ]);

        return $orderId;
    }

    /**
     * B端审核：access 通过（0→1 + 存入log）；refuse 驳回（0→3）
     */
    public function check(int $aid, int $orderId, string $type, int $waiterId): void
    {
        if (!in_array($type, ['access', 'refuse'], true)) {
            throw new BusinessException('审核类型错误');
        }

        $order = Db::name('restaurant_deposit_order')
            ->where('id', $orderId)->where('aid', $aid)->find();

        if (empty($order)) {
            throw new BusinessException('寄存订单不存在', 404, 404);
        }

        if ((int) $order['status'] !== self::STATUS_PENDING) {
            throw new BusinessException('该订单已审核，请勿重复操作');
        }

        if ('access' === $type) {
            Db::name('restaurant_deposit_order')->where('id', $orderId)->update([
                'status'    => self::STATUS_DEPOSITING,
                'waiter_id' => $waiterId,
            ]);

            $this->writeLog([
                'aid'       => (int) $order['aid'],
                'bid'       => (int) $order['bid'],
                'order_id'  => (int) $order['id'],
                'mid'       => (int) $order['mid'],
                'num'       => (string) $order['num'],
                'type'      => 0,
                'createtime' => time(),
                'platform'  => 'admin',
                'remark'    => '审核通过',
                'waiter_id' => $waiterId,
            ]);

            return;
        }

        Db::name('restaurant_deposit_order')->where('id', $orderId)->update([
            'status'    => self::STATUS_REFUSED,
            'waiter_id' => $waiterId,
        ]);
    }

    /**
     * 取出（部分/全部），事务+行锁保证幂等、防超取
     *
     * @param int      $numbers  取出数量（<=0 视为非法）
     * @param string   $platform admin / wx / h5 ...
     * @param int      $mid      C端取出时校验归属，0 表示 B端代取不校验
     * @return array{left:int, taken:int} 剩余数量与本次取出数量
     */
    public function takeout(int $aid, int $orderId, int $numbers, string $platform, int $waiterId = 0, int $mid = 0): array
    {
        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        if ($numbers <= 0) {
            throw new BusinessException('取出数量必须大于0');
        }

        Db::startTrans();

        try {
            $query = Db::name('restaurant_deposit_order')
                ->where('id', $orderId)->where('aid', $aid);

            if ($mid > 0) {
                $query->where('mid', $mid);
            }

            /** @var array|null $order */
            $order = $query->lock(true)->find();

            if (empty($order)) {
                throw new BusinessException('寄存订单不存在', 404, 404);
            }

            if ((int) $order['status'] !== self::STATUS_DEPOSITING) {
                throw new BusinessException('该订单当前状态不可取出');
            }

            $left = (int) $order['num'];

            if ($left < 1) {
                throw new BusinessException('没有可取出的物品');
            }

            if ($numbers > $left) {
                throw new BusinessException('取出数量超过可取数量（剩 ' . $left . '）');
            }

            $now       = time();
            $remainNum = $left - $numbers;
            $logNum    = $numbers;

            if ($remainNum > 0) {
                Db::name('restaurant_deposit_order')->where('id', $orderId)->update([
                    'num'          => (string) $remainNum,
                    'takeout_time' => $now,
                ]);
            } else {
                // 全部取出：订单置为 2
                $logNum = $left;
                Db::name('restaurant_deposit_order')->where('id', $orderId)->update([
                    'status'       => self::STATUS_TAKEN,
                    'num'          => '0',
                    'takeout_time' => $now,
                ]);
            }

            $this->writeLog([
                'aid'       => (int) $order['aid'],
                'bid'       => (int) $order['bid'],
                'order_id'  => (int) $order['id'],
                'mid'       => (int) $order['mid'],
                'num'       => (string) $logNum,
                'type'      => 1,
                'createtime' => $now,
                'platform'  => $platform,
                'remark'    => 'admin' === $platform ? '后台取出' : '用户取出',
                'waiter_id' => $waiterId,
            ]);

            Db::commit();

            return ['left' => $remainNum, 'taken' => $logNum];
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 写寄存流水
     */
    public function writeLog(array $data): int
    {
        return (int) Db::name('restaurant_deposit_order_log')->insertGetId(array_merge([
            'status'    => 1,
            'waiter_id' => 0,
        ], $data));
    }

    /**
     * 位置字典解析：返回 [position_id, position_name, number_id, number_name]
     */
    public function resolvePosition(int $aid, int $positionId, int $numberId): array
    {
        $result = ['position_id' => 0, 'position_name' => '', 'number_id' => 0, 'number_name' => ''];

        if ($positionId > 0) {
            $position = Db::name('restaurant_deposit_position')
                ->where('id', $positionId)->where('aid', $aid)->find();

            if (empty($position)) {
                throw new BusinessException('寄存位置不存在');
            }

            $result['position_id']   = $positionId;
            $result['position_name'] = (string) $position['name'];
        }

        if ($numberId > 0) {
            $number = Db::name('restaurant_deposit_position_number')
                ->where('id', $numberId)->where('aid', $aid)->find();

            if (empty($number) || (int) $number['position_id'] !== $positionId) {
                throw new BusinessException('寄存编号不存在或与位置不匹配');
            }

            $result['number_id']   = $numberId;
            $result['number_name'] = (string) $number['name'];
        }

        return $result;
    }
}
