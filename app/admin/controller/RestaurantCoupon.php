<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮优惠券（对照参考系统 RestaurantCoupon）
 *
 * 不建新表：直接读写 coupon / coupon_record，type=5（餐饮券口径）+ bid=0。
 * 删除沿用本库软删口径（deleted=1），领取记录沿用 coupon_record 快照口径。
 */
class RestaurantCoupon extends BaseController
{
    /** 餐饮券类型 */
    private const COUPON_TYPE = 5;

    /**
     * 券列表
     * GET /admin/restaurant/coupon/list
     */
    public function list()
    {
        $aid   = ScopeService::aidOf($this->request);
        $page  = max(1, (int) $this->request->get('page', 1));
        $limit = min(100, max(1, (int) $this->request->get('limit', 10)));

        $applyWhere = function ($q) {
            $q->where('bid', 0)->where('type', self::COUPON_TYPE)->where('deleted', 0);
            $name = trim((string) $this->request->get('name', ''));
            if ('' !== $name) {
                $q->where('name', 'like', '%' . $name . '%');
            }
            return $q;
        };

        $count = (int) $applyWhere(ScopeService::query('coupon', $aid))->count();
        $rows  = $applyWhere(ScopeService::query('coupon', $aid))
            ->page($page, $limit)->order('id', 'desc')->select()->toArray();

        $now = time();
        foreach ($rows as &$row) {
            $start = strtotime((string) $row['starttime']) ?: 0;
            $end   = strtotime((string) $row['endtime']) ?: 0;
            if ($start > $now) {
                $row['status_text'] = '未开始';
                $row['status']      = 0;
            } elseif ($end > 0 && $end < $now) {
                $row['status_text'] = '已结束';
                $row['status']      = 2;
            } else {
                $row['status_text'] = '进行中';
                $row['status']      = 1;
            }
            $row['id']       = (int) $row['id'];
            $row['money']    = round((float) $row['money'], 2);
            $row['discount'] = round((float) $row['discount'], 2);
            $row['minprice'] = round((float) $row['minprice'], 2);
            $row['stock']    = (int) $row['stock'];
            $row['getnum']   = (int) $row['getnum'];
            $row['used']     = (int) $row['used'];
            $row['perlimit'] = (int) $row['perlimit'];
            $row['sort']     = (int) $row['sort'];
        }
        unset($row);

        return $this->success(['count' => $count, 'list' => $rows]);
    }

    /**
     * 券详情（编辑回显）
     * GET /admin/restaurant/coupon/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        if ($id > 0) {
            $info = ScopeService::query('coupon', $aid)
                ->where('bid', 0)->where('type', self::COUPON_TYPE)->where('deleted', 0)
                ->where('id', $id)->find();
            if (empty($info)) {
                throw new BusinessException('优惠券不存在');
            }
        } else {
            $info = [
                'id'       => 0,
                'name'     => '',
                'money'    => 0,
                'minprice' => 0,
                'stock'    => 100,
                'perlimit' => 1,
                'starttime' => date('Y-m-d 00:00:00'),
                'endtime'   => date('Y-m-d 00:00:00', time() + 7 * 86400),
                'yxqtype'  => 1,
                'yxqdate'  => 7,
                'gettj'    => '-1',
                'fwtype'   => 0,
                'categoryids' => '',
                'restaurant_productids' => '',
                'gettips'  => '',
                'usetips'  => '',
                'sort'     => 0,
            ];
        }

        $info['id']      = (int) $info['id'];
        $info['gettj']   = explode(',', (string) ($info['gettj'] ?? '-1'));
        $info['levels']  = $this->memberLevels($aid);

        return $this->success(['info' => $info]);
    }

    /**
     * 保存优惠券
     * POST /admin/restaurant/coupon/save  { info }
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $uid  = (int) ($this->request->authUid ?? 0);
        $info = $this->request->post('info/a', []);
        if (empty($info)) {
            throw new BusinessException('参数错误');
        }

        $name = trim((string) ($info['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写优惠券名称');
        }
        $starttime = trim((string) ($info['starttime'] ?? ''));
        $endtime   = trim((string) ($info['endtime'] ?? ''));
        if ('' === $starttime || '' === $endtime || strtotime($endtime) <= strtotime($starttime)) {
            throw new BusinessException('请正确填写领取起止时间（结束须晚于开始）');
        }

        $data = [
            'name'                  => $name,
            'money'                 => round((float) ($info['money'] ?? 0), 2),
            'minprice'              => round((float) ($info['minprice'] ?? 0), 2),
            'stock'                 => max(0, (int) ($info['stock'] ?? 0)),
            'perlimit'              => max(1, (int) ($info['perlimit'] ?? 1)),
            'starttime'             => $starttime,
            'endtime'               => $endtime,
            'yxqtype'               => (int) ($info['yxqtype'] ?? 1),
            'yxqdate'               => max(0, (int) ($info['yxqdate'] ?? 0)),
            'gettj'                 => implode(',', array_map('strval', (array) ($info['gettj'] ?? ['-1']))),
            'fwtype'                => (int) ($info['fwtype'] ?? 0),
            'categoryids'           => implode(',', array_map('intval', (array) ($info['categoryids'] ?? []))),
            'restaurant_productids' => implode(',', array_map('intval', (array) ($info['productids'] ?? []))),
            'gettips'               => (string) ($info['gettips'] ?? ''),
            'usetips'               => (string) ($info['usetips'] ?? ''),
            'sort'                  => (int) ($info['sort'] ?? 0),
        ];

        $id = (int) ($info['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('coupon', $aid)
                ->where('bid', 0)->where('type', self::COUPON_TYPE)->where('deleted', 0)
                ->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('优惠券不存在');
            }
            Db::name('coupon')->where('id', $id)->update($data);
            PlogService::write($aid, $uid, '修改餐饮优惠券' . $id, 0, (string) $this->request->ip());
        } else {
            $data['aid']        = $aid;
            $data['bid']        = 0;
            $data['type']       = self::COUPON_TYPE;
            $data['getnum']     = 0;
            $data['used']       = 0;
            $data['status']     = 1;
            $data['deleted']    = 0;
            $data['createtime'] = time();
            $id = (int) Db::name('coupon')->insertGetId($data);
            PlogService::write($aid, $uid, '添加餐饮优惠券' . $id, 0, (string) $this->request->ip());
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除优惠券（软删）
     * POST /admin/restaurant/coupon/del  { ids }
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $ids = array_map('intval', $this->request->post('ids/a', []));
        if (empty($ids)) {
            throw new BusinessException('参数错误');
        }

        $rows = ScopeService::query('coupon', $aid)
            ->where('bid', 0)->where('type', self::COUPON_TYPE)->where('deleted', 0)
            ->whereIn('id', $ids)->select()->toArray();
        if (empty($rows)) {
            throw new BusinessException('优惠券不存在');
        }

        Db::name('coupon')->whereIn('id', $ids)->update(['deleted' => 1]);

        PlogService::write($aid, $uid, '删除餐饮优惠券' . implode(',', $ids), 0, (string) $this->request->ip());

        return $this->success([], '删除成功');
    }

    /**
     * 复制优惠券
     * POST /admin/restaurant/coupon/copy  { id }
     */
    public function copy()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);
        $id  = (int) $this->request->post('id', 0);

        $info = ScopeService::query('coupon', $aid)
            ->where('bid', 0)->where('type', self::COUPON_TYPE)->where('deleted', 0)
            ->where('id', $id)->find();
        if (empty($info)) {
            throw new BusinessException('优惠券不存在');
        }

        $data = $info;
        unset($data['id']);
        $data['name']     = '复制-' . $data['name'];
        $data['getnum']   = 0;
        $data['used']     = 0;
        $data['createtime'] = time();
        $newId = (int) Db::name('coupon')->insertGetId($data);

        PlogService::write($aid, $uid, '复制餐饮优惠券' . $newId, 0, (string) $this->request->ip());

        return $this->success(['id' => $newId], '复制成功');
    }

    /**
     * 领取记录列表（join member 取昵称头像）
     * GET /admin/restaurant/coupon/record?couponid=&page=&limit=&nickname=
     */
    public function record()
    {
        $aid       = ScopeService::aidOf($this->request);
        $couponId  = (int) $this->request->get('couponid', 0);
        $page      = max(1, (int) $this->request->get('page', 1));
        $limit     = min(100, max(1, (int) $this->request->get('limit', 10)));
        $nickname  = trim((string) $this->request->get('nickname', ''));

        if ($couponId <= 0) {
            throw new BusinessException('参数错误');
        }

        $applyWhere = function ($q) use ($aid, $couponId, $nickname) {
            $q->where('r.aid', $aid)->where('r.bid', 0)->where('r.couponid', $couponId);
            if ('' !== $nickname) {
                $q->where('m.nickname', 'like', '%' . $nickname . '%');
            }
            return $q;
        };

        $count = (int) $applyWhere(ScopeService::queryAs('coupon_record', $aid, 'r')
            ->join('member m', 'm.id = r.mid'))->count();

        $rows = $applyWhere(ScopeService::queryAs('coupon_record', $aid, 'r')
            ->field('r.*, m.nickname, m.headimg')
            ->join('member m', 'm.id = r.mid'))
            ->page($page, $limit)->order('r.id', 'desc')->select()->toArray();

        $now = time();
        foreach ($rows as &$row) {
            $row['id']        = (int) $row['id'];
            $row['mid']       = (int) $row['mid'];
            $row['money']     = round((float) $row['money'], 2);
            $row['minprice']  = round((float) $row['minprice'], 2);
            $row['status']    = (int) $row['status'];
            if (0 === $row['status'] && (int) $row['endtime'] > 0 && (int) $row['endtime'] < $now) {
                $row['status'] = 2; // 已过期（实时计算）
            }
            $row['createtime'] = (int) $row['createtime'];
            $row['usetime']    = (int) $row['usetime'];
        }
        unset($row);

        return $this->success(['count' => $count, 'list' => $rows]);
    }

    /**
     * 领取记录改状态（核销/恢复）
     * POST /admin/restaurant/coupon/recordsetst  { ids, st }
     */
    public function recordsetst()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $ids = array_map('intval', $this->request->post('ids/a', []));
        $st  = (int) $this->request->post('st', 1);
        if (empty($ids) || !in_array($st, [0, 1], true)) {
            throw new BusinessException('参数错误');
        }

        $rows = ScopeService::query('coupon_record', $aid)->where('bid', 0)->whereIn('id', $ids)->select()->toArray();
        if (empty($rows)) {
            throw new BusinessException('领取记录不存在');
        }

        foreach ($rows as $row) {
            $update = ['status' => $st];
            if (1 === $st) {
                $update['usetime'] = time();
                Db::name('coupon')->where('id', (int) $row['couponid'])->inc('used', 1)->update();
            } else {
                $update['usetime'] = 0;
                Db::name('coupon')->where('id', (int) $row['couponid'])->where('used', '>', 0)->dec('used', 1)->update();
            }
            Db::name('coupon_record')->where('id', (int) $row['id'])->update($update);
        }

        PlogService::write($aid, $uid, '餐饮优惠券领取记录改状态' . implode(',', $ids), 0, (string) $this->request->ip());

        return $this->success([], '操作成功');
    }

    /**
     * 删除领取记录
     * POST /admin/restaurant/coupon/recorddel  { ids }
     */
    public function recorddel()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $ids = array_map('intval', $this->request->post('ids/a', []));
        if (empty($ids)) {
            throw new BusinessException('参数错误');
        }

        $rows = ScopeService::query('coupon_record', $aid)->where('bid', 0)->whereIn('id', $ids)->select()->toArray();
        if (empty($rows)) {
            throw new BusinessException('领取记录不存在');
        }

        Db::name('coupon_record')->whereIn('id', $ids)->delete();

        PlogService::write($aid, $uid, '删除餐饮优惠券领取记录' . implode(',', $ids), 0, (string) $this->request->ip());

        return $this->success([], '删除成功');
    }

    /**
     * 发送优惠券给指定会员
     * POST /admin/restaurant/coupon/send  { couponid, mids[], num }
     */
    public function send()
    {
        $aid      = ScopeService::aidOf($this->request);
        $uid      = (int) ($this->request->authUid ?? 0);
        $couponId = (int) $this->request->post('couponid', 0);
        $mids     = array_map('intval', $this->request->post('mids/a', []));
        $num      = max(1, min(10, (int) $this->request->post('num', 1)));

        $coupon = ScopeService::query('coupon', $aid)
            ->where('bid', 0)->where('type', self::COUPON_TYPE)->where('deleted', 0)
            ->where('id', $couponId)->find();
        if (empty($coupon)) {
            throw new BusinessException('优惠券不存在');
        }
        if (empty($mids)) {
            throw new BusinessException('请选择发送会员');
        }

        $now   = time();
        $start = strtotime((string) $coupon['starttime']) ?: 0;
        $end   = strtotime((string) $coupon['endtime']) ?: 0;
        // yxqtype=2 领取后 N 天有效
        $recStart = 2 === (int) $coupon['yxqtype'] ? $now : $start;
        $recEnd   = 2 === (int) $coupon['yxqtype'] ? $now + (int) $coupon['yxqdate'] * 86400 : $end;

        $sucNum = 0;
        $errNum = 0;
        foreach ($mids as $mid) {
            $member = ScopeService::query('member', $aid)->where('id', $mid)->find();
            if (empty($member)) {
                $errNum += $num;
                continue;
            }
            for ($i = 0; $i < $num; $i++) {
                if ((int) $coupon['getnum'] + 1 > (int) $coupon['stock']) {
                    $errNum++;
                    continue;
                }
                Db::name('coupon_record')->insert([
                    'aid'          => $aid,
                    'bid'          => 0,
                    'mid'          => $mid,
                    'couponid'     => $couponId,
                    'couponname'   => (string) $coupon['name'],
                    'money'        => (float) $coupon['money'],
                    'discount'     => (float) $coupon['discount'],
                    'minprice'     => (float) $coupon['minprice'],
                    'type'         => self::COUPON_TYPE,
                    'starttime'    => $recStart,
                    'endtime'      => $recEnd,
                    'status'       => 0,
                    'usetime'      => 0,
                    'receive_time' => $now,
                    'createtime'   => $now,
                    'remark'       => '管理员后台发送',
                ]);
                Db::name('coupon')->where('id', $couponId)->inc('getnum', 1)->update();
                $coupon['getnum'] = (int) $coupon['getnum'] + 1;
                $sucNum++;
            }
        }

        PlogService::write($aid, $uid, '发送餐饮优惠券' . $couponId . '（成功' . $sucNum . '张）', 0, (string) $this->request->ip());

        return $this->success(['sucnum' => $sucNum, 'errnum' => $errNum], '发送完成');
    }

    /** 会员等级列表（gettj 下拉用） */
    private function memberLevels(int $aid): array
    {
        $defaultCid = (int) (ScopeService::query('member_level_category', $aid)->where('isdefault', 1)->value('id') ?? 0);
        return ScopeService::query('member_level', $aid)
            ->where('cid', $defaultCid)
            ->field('id,name')->order('sort', 'asc')->order('id', 'asc')
            ->select()->toArray();
    }
}
