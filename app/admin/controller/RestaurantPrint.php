<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮小票打印机（wifiprint_set）
 *
 * type：0易联云 1飞鹅 3大趣 4芯烨
 * 一期 test 仅校验配置完整性，真实云打印 API 二期接入
 */
class RestaurantPrint extends BaseController
{
    /** 支持的打印机类型 */
    private const TYPES = [0 => '易联云', 1 => '飞鹅', 3 => '大趣', 4 => '芯烨'];

    /**
     * 打印机列表
     * GET /admin/dining/printer/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $rows = ScopeService::query('wifiprint_set', $aid)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['id']           = (int) $row['id'];
            $row['type']         = (int) $row['type'];
            $row['type_text']    = self::TYPES[$row['type']] ?? '未知';
            $row['status']       = (int) $row['status'];
            $row['autoprint']    = (int) $row['autoprint'];
            $row['voice']        = (int) $row['voice'];
            $row['tmpltype']     = (int) $row['tmpltype'];
            $row['width']        = (int) $row['width'];
            $row['height']       = (int) $row['height'];
            // 密钥脱敏回显
            $row['secret_masked'] = '' !== trim((string) $row['client_secret'])
                ? substr((string) $row['client_secret'], 0, 4) . '****'
                : '';
        }
        unset($row);

        return $this->success(['list' => $rows]);
    }

    /**
     * 新增/编辑打印机
     * POST /admin/dining/printer/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $id   = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ('' === $name) {
            throw new BusinessException('请填写打印机名称');
        }

        $type = (int) $this->request->post('type', 0);
        if (!isset(self::TYPES[$type])) {
            throw new BusinessException('不支持的打印机类型');
        }

        $machineCode = trim((string) $this->request->post('machine_code', ''));
        if ('' === $machineCode) {
            throw new BusinessException('请填写打印机终端号（machine_code）');
        }

        $clientId = trim((string) $this->request->post('client_id', ''));
        if ('' === $clientId) {
            throw new BusinessException('请填写应用 client_id');
        }

        // 编辑时未传密钥则沿用旧值
        $clientSecret = trim((string) $this->request->post('client_secret', ''));

        $data = [
            'name'         => $name,
            'title'        => trim((string) $this->request->post('title', '')),
            'type'         => $type,
            'client_id'    => $clientId,
            'machine_code' => $machineCode,
            'msign'        => trim((string) $this->request->post('msign', '')),
            'status'       => (int) $this->request->post('status', 1) === 1 ? 1 : 0,
            'autoprint'    => (int) $this->request->post('autoprint', 1) === 1 ? 1 : 0,
            'voice'        => max(0, min(3, (int) $this->request->post('voice', 2))),
            'print_ps'     => (int) $this->request->post('print_ps', 1) === 1 ? 1 : 0,
            'tmpltype'     => (int) $this->request->post('tmpltype', 0),
            'tmplcontent'  => (string) $this->request->post('tmplcontent', ''),
            'width'        => (int) $this->request->post('width', 40),
            'height'       => (int) $this->request->post('height', 30),
        ];

        if ($id > 0) {
            $exists = ScopeService::query('wifiprint_set', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('打印机不存在');
            }
            if ('' !== $clientSecret) {
                $data['client_secret'] = $clientSecret;
            }
            Db::name('wifiprint_set')->where('id', $id)->update($data);
        } else {
            if ('' === $clientSecret) {
                throw new BusinessException('请填写应用 client_secret');
            }
            $data['client_secret'] = $clientSecret;
            $data['aid']           = $aid;
            $data['bid']           = 0;
            $data['createtime']    = time();
            $id = Db::name('wifiprint_set')->insertGetId($data);
        }

        return $this->success(['id' => (int) $id], '保存成功');
    }

    /**
     * 删除打印机
     * POST /admin/dining/printer/del
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('参数错误');
        }

        $row = ScopeService::query('wifiprint_set', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('打印机不存在');
        }

        Db::name('wifiprint_set')->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 测试打印机（一期仅校验配置完整性）
     * POST /admin/dining/printer/test
     */
    public function test()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('参数错误');
        }

        $row = ScopeService::query('wifiprint_set', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('打印机不存在');
        }

        $missing = [];
        foreach (['client_id' => 'client_id', 'client_secret' => 'client_secret', 'machine_code' => '终端号'] as $field => $label) {
            if ('' === trim((string) $row[$field])) {
                $missing[] = $label;
            }
        }
        if (!empty($missing)) {
            throw new BusinessException('配置不完整，缺少：' . implode('、', $missing));
        }
        if (!isset(self::TYPES[(int) $row['type']])) {
            throw new BusinessException('打印机类型配置不正确');
        }

        return $this->success([], '配置校验通过，云打印在线测试将在二期开放');
    }
}
