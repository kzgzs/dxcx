<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\AddressService;

/**
 * 客户端 - 会员收货地址（api 应用，JWT role=member）
 */
class Address extends Base
{
    public function __construct(\think\App $app, protected AddressService $addressService)
    {
        parent::__construct($app);
    }

    /**
     * 新增地址
     * POST /api/address/add {name,phone,province,city,district,detail,is_default}
     */
    public function add()
    {
        [$aid, $mid] = $this->memberContext();

        $data = $this->request->post();

        return $this->success($this->addressService->add($aid, $mid, $data));
    }

    /**
     * 编辑地址
     * POST /api/address/edit {id,...}
     */
    public function edit()
    {
        [$aid, $mid] = $this->memberContext();

        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);

        if ($id <= 0) {
            throw new BusinessException('缺少地址ID');
        }

        return $this->success($this->addressService->edit($aid, $mid, $id, $data));
    }

    /**
     * 删除地址（软删）
     * POST /api/address/delete {id}
     */
    public function delete()
    {
        [$aid, $mid] = $this->memberContext();

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少地址ID');
        }

        $this->addressService->delete($aid, $mid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 地址列表（当前会员）
     * GET /api/address/list
     */
    public function list()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->addressService->list($aid, $mid));
    }
}
