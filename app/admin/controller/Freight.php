<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use app\common\service\TenantConfigService;

/**
 * 租户后台 - 配送方式（freight 表）
 *
 * pstype：0快递 / 1到店自提 / 2同城配送 / 3自动发货 / 5门店配送 / 6预约服务（同城自提）
 * pricedata 为地区分组运费 JSON：[{region, fristweight, fristprice, secondweight, secondprice}, ...]
 * pstimedata 为配送时段 JSON：[{day, hour, minute, hour2, minute2}, ...]
 * formdata 为下单附加表单 JSON：[{key, val1, val2, val3}, ...]
 * 保存统一 JSON_UNESCAPED_UNICODE；读取容错（空/非法 JSON 兜底空数组）
 */
class Freight extends BaseController
{
    /** 开放的配送方式：0快递 1到店自提 2同城配送 3自动发货 5门店配送 6预约服务 */
    private const PSTYPES = [0, 1, 2, 3, 5, 6];

    /** 到店自提类方式（无起订限制）：1到店自提 3自动发货 6预约服务 */
    private const PICKUP_TYPES = [1, 3, 6];

    /** formdata 允许的字段类型（对照参考系统设置表单） */
    private const FORM_KEYS = ['input', 'textarea', 'radio', 'checkbox', 'selector', 'time', 'date', 'upload'];

    /**
     * 配送方式列表
     * GET /admin/freight/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $rows = ScopeService::query('freight', $aid)
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        return $this->success(['list' => $rows, 'map_config' => self::mapConfig()]);
    }

    /**
     * 配送范围地图配置（对照参考系统：高德 JS API，key 读平台级系统设置）
     * tenant_config（aid=0，前缀 sys_system_）：map_amap_key / map_amap_jscode / map_qq_key
     */
    public static function mapConfig(): array
    {
        $amapKey = trim(TenantConfigService::get(0, 'sys_system_map_amap_key'));
        $qqKey   = trim(TenantConfigService::get(0, 'sys_system_map_qq_key'));

        // 配置了哪家的 Key 就用哪家：优先高德，其次腾讯；都未配置则返回空，前端降级为手动输入
        $mapType = '';
        if ('' !== $amapKey) {
            $mapType = 'amap';
        } elseif ('' !== $qqKey) {
            $mapType = 'qq';
        }

        return [
            'map_type'     => $mapType,
            'map_key'      => $amapKey,
            'map_jscode'   => trim(TenantConfigService::get(0, 'sys_system_map_amap_jscode')),
            'map_qq_key'   => $qqKey,
        ];
    }

    /**
     * 新增/编辑配送方式
     * POST /admin/freight/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $name   = trim((string) $this->request->post('name', ''));
        $pstype = (int) $this->request->post('pstype', 0);
        $type   = (int) $this->request->post('type', 1);

        if ('' === $name) {
            throw new BusinessException('请填写名称');
        }
        if (!in_array($pstype, self::PSTYPES, true)) {
            throw new BusinessException('不支持的配送方式');
        }
        if (!in_array($type, [1, 2], true)) {
            $type = 1;
        }

        // 计费地区分组（前端传数组；容错：非法结构逐项过滤）
        $pricedata = $this->normalizePricedata($this->request->post('pricedata', []));

        $data = [
            'name'        => $name,
            'pstype'      => $pstype,
            'type'        => $type,
            'pricedata'   => json_encode($pricedata, JSON_UNESCAPED_UNICODE),
            'freeset'     => (int) $this->request->post('freeset', 0) === 1 ? 1 : 0,
            'free_price'  => $this->money($this->request->post('free_price', 0)),
            'minpriceset' => (int) $this->request->post('minpriceset', 0) === 1 ? 1 : 0,
            'minprice'    => $this->money($this->request->post('minprice', 0)),
            'minnumset'   => (int) $this->request->post('minnumset', 0) === 1 ? 1 : 0,
            'minnum'      => max(0, (int) $this->request->post('minnum', 0)),
            'status'      => (int) $this->request->post('status', 0) === 1 ? 1 : 0,
            'sort'        => (int) $this->request->post('sort', 0),
            // 自提/配送门店（pstype 1/5/6）
            'storetype'   => (int) $this->request->post('storetype', 0) === 1 ? 1 : 0,
            'storeids'    => $this->normalizeStoreids($this->request->post('storeids', [])),
            // 自动发货（pstype 3）
            'pscontenttype' => (int) $this->request->post('pscontenttype', 0) === 1 ? 1 : 0,
            'pscontent'     => trim((string) $this->request->post('pscontent', '')),
            // 联系方式是否必填 / 服务费（自提类）
            'needlinkinfo' => (int) $this->request->post('needlinkinfo', 1) === 1 ? 1 : 0,
            'fwprice'      => $this->money($this->request->post('fwprice', 0)),
            // 配送/提货时段
            'pstimeset'  => (int) $this->request->post('pstimeset', 0) === 1 ? 1 : 0,
            'pstimedata' => json_encode($this->normalizePstimedata($this->request->post('pstimedata', []))),
            'psprehour'  => max(0, (int) $this->request->post('psprehour', 4)),
            // 同城配送距离计费与范围（pstype 2）
            'peisong_juli1'     => $this->num($this->request->post('peisong_juli1', 5), 1),
            'peisong_fee1'      => $this->money($this->request->post('peisong_fee1', 0)),
            'peisong_juli2'     => $this->num($this->request->post('peisong_juli2', 1), 1),
            'peisong_fee2'      => $this->money($this->request->post('peisong_fee2', 0)),
            'peisong_lng'       => trim((string) $this->request->post('peisong_lng', '')),
            'peisong_lat'       => trim((string) $this->request->post('peisong_lat', '')),
            'peisong_range'     => max(0, (int) $this->request->post('peisong_range', 0)),
            'peisong_rangetype' => (int) $this->request->post('peisong_rangetype', 0) === 1 ? 1 : 0,
            'peisong_rangepath' => trim((string) $this->request->post('peisong_rangepath', '')),
            'peisong_lng2'      => trim((string) $this->request->post('peisong_lng2', '')),
            'peisong_lat2'      => trim((string) $this->request->post('peisong_lat2', '')),
            // 下单附加表单
            'formdata' => json_encode($this->normalizeFormdata($this->request->post('formdata', [])), JSON_UNESCAPED_UNICODE),
        ];

        // 到店自提（1）/自动发货（3）/预约服务（6）无起订限制概念，强制置 0
        if (in_array($pstype, self::PICKUP_TYPES, true)) {
            $data['minpriceset'] = 0;
            $data['minnumset']   = 0;
        }

        if ($id > 0) {
            $exists = ScopeService::query('freight', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('配送方式不存在');
            }
            ScopeService::query('freight', $aid)->where('id', $id)->update($data);
        } else {
            $data['aid']        = $aid;
            $data['bid']        = 0;
            $data['createtime'] = time();
            $id                 = (int) ScopeService::query('freight', $aid)->insertGetId($data);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除配送方式
     * POST /admin/freight/delete
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少ID');
        }

        $exists = ScopeService::query('freight', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('配送方式不存在');
        }

        ScopeService::query('freight', $aid)->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 启用/停用
     * POST /admin/freight/status {id, status}
     */
    public function status()
    {
        $aid    = ScopeService::aidOf($this->request);
        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0) === 1 ? 1 : 0;
        if ($id <= 0) {
            throw new BusinessException('缺少ID');
        }

        $exists = ScopeService::query('freight', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('配送方式不存在');
        }

        ScopeService::query('freight', $aid)->where('id', $id)->update(['status' => $status]);

        return $this->success([], '操作成功');
    }

    /**
     * 输出格式化：JSON 字段反序列化容错，开关/数值转 int，金额转字符串
     */
    private function formatRow(array $row): array
    {
        $row['id']          = (int) ($row['id'] ?? 0);
        $row['pstype']      = (int) ($row['pstype'] ?? 0);
        $row['type']        = (int) ($row['type'] ?? 1);
        $row['pricedata']   = self::decodePricedata($row['pricedata'] ?? '');
        $row['freeset']     = (int) ($row['freeset'] ?? 0);
        $row['free_price']  = number_format((float) ($row['free_price'] ?? 0), 2, '.', '');
        $row['minpriceset'] = (int) ($row['minpriceset'] ?? 0);
        $row['minprice']    = number_format((float) ($row['minprice'] ?? 0), 2, '.', '');
        $row['minnumset']   = (int) ($row['minnumset'] ?? 0);
        $row['minnum']      = (int) ($row['minnum'] ?? 0);
        $row['status']      = (int) ($row['status'] ?? 0);
        $row['sort']        = (int) ($row['sort'] ?? 0);
        // 门店
        $row['storetype'] = (int) ($row['storetype'] ?? 0);
        $row['storeids']  = self::decodeIntList($row['storeids'] ?? '');
        // 自动发货
        $row['pscontenttype'] = (int) ($row['pscontenttype'] ?? 0);
        $row['pscontent']     = (string) ($row['pscontent'] ?? '');
        $row['needlinkinfo']  = (int) ($row['needlinkinfo'] ?? 1);
        $row['fwprice']       = number_format((float) ($row['fwprice'] ?? 0), 2, '.', '');
        // 配送/提货时段
        $row['pstimeset']  = (int) ($row['pstimeset'] ?? 0);
        $row['pstimedata'] = self::decodePstimedata($row['pstimedata'] ?? '');
        $row['psprehour']  = (int) ($row['psprehour'] ?? 4);
        // 同城配送距离计费与范围
        $row['peisong_juli1']     = number_format((float) ($row['peisong_juli1'] ?? 0), 1, '.', '');
        $row['peisong_fee1']      = number_format((float) ($row['peisong_fee1'] ?? 0), 2, '.', '');
        $row['peisong_juli2']     = number_format((float) ($row['peisong_juli2'] ?? 0), 1, '.', '');
        $row['peisong_fee2']      = number_format((float) ($row['peisong_fee2'] ?? 0), 2, '.', '');
        $row['peisong_lng']       = (string) ($row['peisong_lng'] ?? '');
        $row['peisong_lat']       = (string) ($row['peisong_lat'] ?? '');
        $row['peisong_range']     = (int) ($row['peisong_range'] ?? 0);
        $row['peisong_rangetype'] = (int) ($row['peisong_rangetype'] ?? 0);
        $row['peisong_rangepath'] = (string) ($row['peisong_rangepath'] ?? '');
        $row['peisong_lng2']      = (string) ($row['peisong_lng2'] ?? '');
        $row['peisong_lat2']      = (string) ($row['peisong_lat2'] ?? '');
        // 下单附加表单
        $row['formdata'] = self::decodeFormdata($row['formdata'] ?? '');

        return $row;
    }

    /**
     * pricedata JSON 反序列化（空/非法兜底空数组）
     */
    public static function decodePricedata(mixed $raw): array
    {
        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $result[] = [
                'region'      => (string) ($item['region'] ?? ''),
                'fristweight' => (int) ($item['fristweight'] ?? 0),
                'fristprice'  => number_format((float) ($item['fristprice'] ?? 0), 2, '.', ''),
                'secondweight' => (int) ($item['secondweight'] ?? 0),
                'secondprice' => number_format((float) ($item['secondprice'] ?? 0), 2, '.', ''),
            ];
        }

        return $result;
    }

    /**
     * pstimedata JSON 反序列化（空/非法兜底空数组）
     */
    public static function decodePstimedata(mixed $raw): array
    {
        $decoded = is_string($raw) && '' !== trim($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $result[] = [
                'day'     => max(1, min(15, (int) ($item['day'] ?? 1))),
                'hour'    => max(0, min(23, (int) ($item['hour'] ?? 0))),
                'minute'  => max(0, min(59, (int) ($item['minute'] ?? 0))),
                'hour2'   => max(0, min(23, (int) ($item['hour2'] ?? 0))),
                'minute2' => max(0, min(59, (int) ($item['minute2'] ?? 0))),
            ];
        }

        return $result;
    }

    /**
     * formdata JSON 反序列化（空/非法兜底空数组）
     */
    public static function decodeFormdata(mixed $raw): array
    {
        $decoded = is_string($raw) && '' !== trim($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            if (!in_array($key, self::FORM_KEYS, true)) {
                continue;
            }
            $result[] = [
                'key'  => $key,
                'val1' => (string) ($item['val1'] ?? ''),
                'val2' => (string) ($item['val2'] ?? ''),
                'val3' => (int) ($item['val3'] ?? 0) === 1 ? 1 : 0,
            ];
        }

        return $result;
    }

    /**
     * 逗号分隔 ID 串转 int 数组（容错：过滤非正整数）
     *
     * @return int[]
     */
    public static function decodeIntList(mixed $raw): array
    {
        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }

        $result = [];
        foreach (explode(',', $raw) as $v) {
            $v = (int) trim($v);
            if ($v > 0) {
                $result[] = $v;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * 保存前清洗 pricedata（非数组项剔除，数值归一）
     */
    private function normalizePricedata(mixed $pricedata): array
    {
        if (!is_array($pricedata)) {
            return [];
        }

        $result = [];
        foreach ($pricedata as $item) {
            if (!is_array($item)) {
                continue;
            }
            $region = trim((string) ($item['region'] ?? ''));
            if ('' === $region) {
                continue;
            }
            $result[] = [
                'region'       => $region,
                'fristweight'  => max(0, (int) ($item['fristweight'] ?? 0)),
                'fristprice'   => $this->money($item['fristprice'] ?? 0),
                'secondweight' => max(0, (int) ($item['secondweight'] ?? 0)),
                'secondprice'  => $this->money($item['secondprice'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * 清洗配送时段行
     */
    private function normalizePstimedata(mixed $pstimedata): array
    {
        if (!is_array($pstimedata)) {
            return [];
        }

        $result = [];
        foreach ($pstimedata as $item) {
            if (!is_array($item)) {
                continue;
            }
            $result[] = [
                'day'     => max(1, min(15, (int) ($item['day'] ?? 1))),
                'hour'    => max(0, min(23, (int) ($item['hour'] ?? 0))),
                'minute'  => max(0, min(59, (int) ($item['minute'] ?? 0))),
                'hour2'   => max(0, min(23, (int) ($item['hour2'] ?? 0))),
                'minute2' => max(0, min(59, (int) ($item['minute2'] ?? 0))),
            ];
        }

        return $result;
    }

    /**
     * 清洗下单附加表单行
     */
    private function normalizeFormdata(mixed $formdata): array
    {
        if (!is_array($formdata)) {
            return [];
        }

        $result = [];
        foreach ($formdata as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            if (!in_array($key, self::FORM_KEYS, true)) {
                continue;
            }
            $val1 = trim((string) ($item['val1'] ?? ''));
            if ('' === $val1) {
                continue;
            }
            $result[] = [
                'key'  => $key,
                'val1' => $val1,
                'val2' => trim((string) ($item['val2'] ?? '')),
                'val3' => (int) ($item['val3'] ?? 0) === 1 ? 1 : 0,
            ];
        }

        return $result;
    }

    /**
     * 清洗门店 ID 数组 → 逗号串
     */
    private function normalizeStoreids(mixed $storeids): string
    {
        if (is_string($storeids)) {
            return implode(',', self::decodeIntList($storeids));
        }
        if (!is_array($storeids)) {
            return '';
        }

        $ids = [];
        foreach ($storeids as $v) {
            $v = (int) $v;
            if ($v > 0) {
                $ids[] = $v;
            }
        }

        return implode(',', array_values(array_unique($ids)));
    }

    /**
     * 金额归一：两位小数字符串
     */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * 数值归一：指定小数位字符串
     */
    private function num(mixed $value, int $decimals): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }
}
