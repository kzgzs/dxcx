<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 外卖配送方式（对照参考系统 RestaurantTakeawayFreight）
 *
 * pstype：0快递模板 1自提门店 2同城配送 3发货信息 5配送门店
 */
class RestaurantTakeawayFreight extends BaseController
{
    /**
     * 配送方式列表
     * GET /admin/restaurant/takeawayFreight/list
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
            $status = $this->request->get('status', '');
            if ('' !== $status && null !== $status) {
                $q->where('status', (int) $status);
            }
            return $q;
        };

        $count = (int) $applyWhere(ScopeService::query('restaurant_takeaway_freight', $aid))->count();
        $rows  = $applyWhere(ScopeService::query('restaurant_takeaway_freight', $aid))
            ->page($page, $limit)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $mendianArr = ScopeService::query('mendian', $aid)->order('id', 'asc')->column('name', 'id');

        foreach ($rows as &$row) {
            $row['id']      = (int) $row['id'];
            $row['pstype']  = (int) $row['pstype'];
            $row['type']    = (int) $row['type'];
            $row['status']  = (int) $row['status'];
            $row['sort']    = (int) $row['sort'];
            $row['summary'] = $this->summary($row, $mendianArr);
        }
        unset($row);

        return $this->success(['count' => $count, 'list' => $rows]);
    }

    /**
     * 配送方式详情
     * GET /admin/restaurant/takeawayFreight/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        if ($id > 0) {
            $info = ScopeService::query('restaurant_takeaway_freight', $aid)->where('bid', 0)->where('id', $id)->find();
            if (empty($info)) {
                throw new BusinessException('配送方式不存在');
            }
            $info['pricedata']  = json_decode((string) $info['pricedata'], true) ?: [];
            $info['pstimedata'] = json_decode((string) $info['pstimedata'], true) ?: [];
            $info['field_list'] = json_decode((string) $info['field_list'], true) ?: [];
            $info['storeids']   = '' !== $info['storeids'] ? array_map('intval', explode(',', (string) $info['storeids'])) : [];
        } else {
            $info = [
                'id'                => 0,
                'pstype'            => 2,
                'name'              => '同城配送',
                'type'              => 0,
                'status'            => 1,
                'sort'              => 0,
                'psprehour'         => 4,
                'pstimeset'         => 0,
                'pstimedata'        => [],
                'peisong_juli1'     => 5,
                'peisong_fee1'      => 3,
                'peisong_juli2'     => 1,
                'peisong_fee2'      => 1,
                'peisong_rangetype' => 0,
                'pricedata'         => [],
                'field_list'        => [],
                'storeids'          => [],
            ];
        }

        $stores = ScopeService::query('mendian', $aid)->where('bid', 0)
            ->field('id,name')->order('id', 'asc')->select()->toArray();

        return $this->success(['info' => $info, 'stores' => $stores]);
    }

    /**
     * 保存配送方式
     * POST /admin/restaurant/takeawayFreight/save  { info }
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $uid  = (int) ($this->request->authUid ?? 0);
        $info = $this->request->post('info/a', []);
        if (empty($info)) {
            throw new BusinessException('参数错误');
        }

        $pstype = (int) ($info['pstype'] ?? 2);
        if (!in_array($pstype, [0, 1, 2, 3, 5], true)) {
            throw new BusinessException('配送类型不正确');
        }
        $name = trim((string) ($info['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写配送方式名称');
        }

        $pricedata = [];
        foreach ((array) ($info['pricedata'] ?? []) as $pv) {
            $pricedata[] = [
                'region'       => (string) ($pv['region'] ?? ''),
                'fristweight'  => (string) ($pv['fristweight'] ?? ''),
                'fristprice'   => (string) ($pv['fristprice'] ?? ''),
                'secondweight' => (string) ($pv['secondweight'] ?? ''),
                'secondprice'  => (string) ($pv['secondprice'] ?? ''),
            ];
        }

        $data = [
            'pstype'              => $pstype,
            'name'                => $name,
            'type'                => (int) ($info['type'] ?? 0),
            'status'              => (int) ($info['status'] ?? 1) === 1 ? 1 : 0,
            'sort'                => (int) ($info['sort'] ?? 0),
            'freeset'             => (int) ($info['freeset'] ?? 0),
            'free_price'          => (float) ($info['free_price'] ?? 0),
            'minpriceset'         => in_array($pstype, [1, 3], true) ? 0 : (int) ($info['minpriceset'] ?? 0),
            'minprice'            => (float) ($info['minprice'] ?? 0),
            'pricedata'           => json_encode($pricedata, JSON_UNESCAPED_UNICODE),
            'storetype'           => (int) ($info['storetype'] ?? 0),
            'storeids'            => implode(',', array_map('intval', (array) ($info['storeids'] ?? []))),
            'field_list'          => json_encode((array) ($info['field_list'] ?? []), JSON_UNESCAPED_UNICODE),
            'pscontenttype'       => (int) ($info['pscontenttype'] ?? 0),
            'pscontent'           => (string) ($info['pscontent'] ?? ''),
            'needlinkinfo'        => (int) ($info['needlinkinfo'] ?? 0),
            'fwprice'             => (float) ($info['fwprice'] ?? 0),
            'pstimeset'           => (int) ($info['pstimeset'] ?? 0),
            'pstimedata'          => json_encode((array) ($info['pstimedata'] ?? []), JSON_UNESCAPED_UNICODE),
            'psprehour'           => (int) ($info['psprehour'] ?? 4),
            'peisong_juli1'       => (float) ($info['peisong_juli1'] ?? 0),
            'peisong_fee1'        => (float) ($info['peisong_fee1'] ?? 0),
            'peisong_juli2'       => (float) ($info['peisong_juli2'] ?? 0),
            'peisong_fee2'        => (float) ($info['peisong_fee2'] ?? 0),
            'peisong_lng'         => (string) ($info['peisong_lng'] ?? ''),
            'peisong_lat'         => (string) ($info['peisong_lat'] ?? ''),
            'peisong_lng2'        => (string) ($info['peisong_lng2'] ?? ''),
            'peisong_lat2'        => (string) ($info['peisong_lat2'] ?? ''),
            'peisong_range'       => (float) ($info['peisong_range'] ?? 0),
            'peisong_rangetype'   => (int) ($info['peisong_rangetype'] ?? 0),
            'peisong_rangepath'   => (string) ($info['peisong_rangepath'] ?? ''),
        ];

        $id = (int) ($info['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('restaurant_takeaway_freight', $aid)->where('bid', 0)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('配送方式不存在');
            }
            Db::name('restaurant_takeaway_freight')->where('id', $id)->update($data);
            PlogService::write($aid, $uid, '修改餐饮配送方式' . $id, 0, (string) $this->request->ip());
        } else {
            $data['aid']        = $aid;
            $data['bid']        = 0;
            $data['createtime'] = time();
            $id = (int) Db::name('restaurant_takeaway_freight')->insertGetId($data);
            PlogService::write($aid, $uid, '添加餐饮配送方式' . $id, 0, (string) $this->request->ip());
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除配送方式
     * POST /admin/restaurant/takeawayFreight/del  { ids }
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $ids = array_map('intval', $this->request->post('ids/a', []));
        if (empty($ids)) {
            throw new BusinessException('参数错误');
        }

        $rows = ScopeService::query('restaurant_takeaway_freight', $aid)->where('bid', 0)->whereIn('id', $ids)->select()->toArray();
        if (empty($rows)) {
            throw new BusinessException('配送方式不存在');
        }

        Db::name('restaurant_takeaway_freight')->whereIn('id', $ids)->delete();

        PlogService::write($aid, $uid, '删除餐饮配送方式' . implode(',', $ids), 0, (string) $this->request->ip());

        return $this->success([], '删除成功');
    }

    /** 列表摘要文案（替代参考系统 pricedatahtml） */
    private function summary(array $row, array $mendianArr): string
    {
        $pstype = (int) $row['pstype'];
        if (0 === $pstype) {
            $pricedata = json_decode((string) $row['pricedata'], true) ?: [];
            $unit = 1 === (int) $row['type'] ? '克' : '件';
            $lines = [];
            foreach ($pricedata as $pv) {
                $lines[] = rtrim((string) $pv['region'], ';') . '：' . $pv['fristweight'] . $unit . '以下' . $pv['fristprice'] . '元，每超' . $pv['secondweight'] . $unit . '加' . $pv['secondprice'] . '元';
            }
            return implode('；', $lines);
        }
        if (1 === $pstype || 5 === $pstype) {
            if (0 === (int) $row['storetype']) {
                return '全部门店';
            }
            $names = [];
            foreach (explode(',', (string) $row['storeids']) as $sid) {
                if (isset($mendianArr[$sid])) {
                    $names[] = $mendianArr[$sid];
                }
            }
            return implode('、', $names);
        }
        if (2 === $pstype) {
            return $row['peisong_juli1'] . '公里以内' . $row['peisong_fee1'] . '元；每超' . $row['peisong_juli2'] . '公里加收' . $row['peisong_fee2'] . '元';
        }
        if (3 === $pstype) {
            return 0 === (int) $row['pscontenttype'] ? mb_substr((string) $row['pscontent'], 0, 40) : '不固定信息(在线卡密)';
        }
        return '';
    }
}
