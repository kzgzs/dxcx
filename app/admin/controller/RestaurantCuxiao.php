<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮促销（对照参考系统 RestaurantCuxiao）
 *
 * type：1满额立减 2满额赠送 3加价换购 4满额打折 5满件打折 6满件立减 7第二件打折
 */
class RestaurantCuxiao extends BaseController
{
    /** 促销类型与提示词 */
    private const TYPES = [
        1 => '满减', 2 => '满赠', 3 => '换购', 4 => '打折',
        5 => '满件打折', 6 => '满件立减', 7 => '第二件打折',
    ];

    /**
     * 促销列表
     * GET /admin/restaurant/cuxiao/list
     */
    public function list()
    {
        $aid   = ScopeService::aidOf($this->request);
        $page  = max(1, (int) $this->request->get('page', 1));
        $limit = min(100, max(1, (int) $this->request->get('limit', 10)));

        $applyWhere = function ($q) {
            $q->where('bid', 0);
            $name = trim((string) $this->request->get('name', ''));
            if ('' !== $name) {
                $q->where('name', 'like', '%' . $name . '%');
            }
            return $q;
        };

        $count = (int) $applyWhere(ScopeService::query('restaurant_cuxiao', $aid))->count();
        $rows  = $applyWhere(ScopeService::query('restaurant_cuxiao', $aid))
            ->page($page, $limit)->order('sort', 'desc')->order('id', 'desc')
            ->select()->toArray();

        $now = time();
        foreach ($rows as &$row) {
            $row['id']        = (int) $row['id'];
            $row['type']      = (int) $row['type'];
            $row['sort']      = (int) $row['sort'];
            $row['starttime'] = (int) $row['starttime'];
            $row['endtime']   = (int) $row['endtime'];
            $row['minprice']  = round((float) $row['minprice'], 2);
            $row['money']     = round((float) $row['money'], 2);
            $row['zhekou']    = round((float) $row['zhekou'], 2);
            $row['minnum']    = (int) $row['minnum'];
            if ($row['starttime'] > $now) {
                $row['status']      = 0;
                $row['status_text'] = '未开始';
            } elseif ($row['endtime'] < $now) {
                $row['status']      = 2;
                $row['status_text'] = '已结束';
            } else {
                $row['status']      = 1;
                $row['status_text'] = '进行中';
            }
            $row['type_text'] = self::TYPES[$row['type']] ?? '';
        }
        unset($row);

        return $this->success(['count' => $count, 'list' => $rows]);
    }

    /**
     * 促销详情（编辑回显）
     * GET /admin/restaurant/cuxiao/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        if ($id > 0) {
            $info = ScopeService::query('restaurant_cuxiao', $aid)->where('bid', 0)->where('id', $id)->find();
            if (empty($info)) {
                throw new BusinessException('促销活动不存在');
            }
        } else {
            $info = [
                'id'          => 0,
                'type'        => 1,
                'tip'         => '满减',
                'name'        => '',
                'starttime'   => time(),
                'endtime'     => time() + 7 * 86400,
                'gettj'       => '-1',
                'sort'        => 0,
                'fwtype'      => 0,
                'categoryids' => '',
                'productids'  => '',
                'minprice'    => 0,
                'money'       => 0,
                'minnum'      => 0,
                'zhekou'      => 0,
                'proid'       => 0,
                'ggid'        => 0,
            ];
        }

        $info['id']         = (int) $info['id'];
        $info['type']       = (int) $info['type'];
        $info['starttime']  = (int) $info['starttime'];
        $info['endtime']    = (int) $info['endtime'];
        $info['gettj']      = explode(',', (string) ($info['gettj'] ?? '-1'));
        $info['categoryids'] = '' !== $info['categoryids'] ? array_map('intval', explode(',', (string) $info['categoryids'])) : [];
        $info['productids']  = '' !== $info['productids'] ? array_map('intval', explode(',', (string) $info['productids'])) : [];

        $defaultCid = (int) (ScopeService::query('member_level_category', $aid)->where('isdefault', 1)->value('id') ?? 0);
        $info['levels'] = ScopeService::query('member_level', $aid)
            ->where('cid', $defaultCid)->field('id,name')->order('sort', 'asc')->order('id', 'asc')
            ->select()->toArray();

        return $this->success(['info' => $info]);
    }

    /**
     * 保存促销
     * POST /admin/restaurant/cuxiao/save  { info }
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $uid  = (int) ($this->request->authUid ?? 0);
        $info = $this->request->post('info/a', []);
        if (empty($info)) {
            throw new BusinessException('参数错误');
        }

        $type = (int) ($info['type'] ?? 1);
        if (!isset(self::TYPES[$type])) {
            throw new BusinessException('促销类型不正确');
        }
        $name = trim((string) ($info['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写促销活动名称');
        }
        $starttime = (int) ($info['starttime'] ?? 0);
        $endtime   = (int) ($info['endtime'] ?? 0);
        if ($starttime <= 0 || $endtime <= $starttime) {
            throw new BusinessException('请正确设置活动起止时间');
        }

        $data = [
            'type'        => $type,
            'tip'         => self::TYPES[$type],
            'name'        => $name,
            'starttime'   => $starttime,
            'endtime'     => $endtime,
            'sort'        => (int) ($info['sort'] ?? 0),
            'gettj'       => implode(',', array_map('strval', (array) ($info['gettj'] ?? ['-1']))),
            'fwtype'      => (int) ($info['fwtype'] ?? 0),
            'categoryids' => implode(',', array_map('intval', (array) ($info['categoryids'] ?? []))),
            'productids'  => implode(',', array_map('intval', (array) ($info['productids'] ?? []))),
            'minprice'    => round((float) ($info['minprice'] ?? 0), 2),
            'money'       => round((float) ($info['money'] ?? 0), 2),
            'minnum'      => max(0, (int) ($info['minnum'] ?? 0)),
            'zhekou'      => round((float) ($info['zhekou'] ?? 0), 2),
            'proid'       => (int) ($info['proid'] ?? 0),
            'ggid'        => (int) ($info['ggid'] ?? 0),
        ];

        $id = (int) ($info['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('restaurant_cuxiao', $aid)->where('bid', 0)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('促销活动不存在');
            }
            Db::name('restaurant_cuxiao')->where('id', $id)->update($data);
            PlogService::write($aid, $uid, '修改餐饮促销活动' . $id, 0, (string) $this->request->ip());
        } else {
            $data['aid']        = $aid;
            $data['bid']        = 0;
            $data['createtime'] = time();
            $id = (int) Db::name('restaurant_cuxiao')->insertGetId($data);
            PlogService::write($aid, $uid, '添加餐饮促销活动' . $id, 0, (string) $this->request->ip());
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除促销
     * POST /admin/restaurant/cuxiao/del  { ids }
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $ids = array_map('intval', $this->request->post('ids/a', []));
        if (empty($ids)) {
            throw new BusinessException('参数错误');
        }

        $rows = ScopeService::query('restaurant_cuxiao', $aid)->where('bid', 0)->whereIn('id', $ids)->select()->toArray();
        if (empty($rows)) {
            throw new BusinessException('促销活动不存在');
        }

        Db::name('restaurant_cuxiao')->whereIn('id', $ids)->delete();

        PlogService::write($aid, $uid, '删除餐饮促销活动' . implode(',', $ids), 0, (string) $this->request->ip());

        return $this->success([], '删除成功');
    }
}
