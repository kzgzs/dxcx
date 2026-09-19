<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\constant\ExpressCompany;
use app\common\service\ScopeService;

/**
 * 租户后台 - 快递设置（express_data 表，每 aid 一行）
 *
 * 存储结构：express_data 列为 JSON {公司名:编码}
 * GET 返回内置候选字典 + 已启用数据；POST 保存（编码按内置字典回填标准化）
 */
class ExpressData extends BaseController
{
    /**
     * 读取快递设置
     * GET /admin/expressdata → {dict: 候选字典, data: 已启用 {公司名:编码}}
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        $row = ScopeService::query('express_data', $aid)->where('bid', 0)->find();

        $data = [];
        if (!empty($row['express_data'])) {
            $decoded = json_decode((string) $row['express_data'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $name => $code) {
                    if ('' === trim((string) $name)) {
                        continue;
                    }
                    $data[(string) $name] = (string) $code;
                }
            }
        }

        return $this->success([
            'dict' => ExpressCompany::dict(),
            'data' => $data,
        ]);
    }

    /**
     * 保存快递设置
     * POST /admin/expressdata {data: {公司名:编码}}（编码统一按内置字典回填）
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $post = $this->request->post('data', []);
        if (!is_array($post)) {
            $post = [];
        }

        $dict   = ExpressCompany::dict();
        $stored = [];
        foreach ($post as $name => $code) {
            $name = trim((string) $name);
            if ('' === $name) {
                continue;
            }
            // 字典内公司回填标准编码；字典外自定义公司保留提交编码
            $stored[$name] = array_key_exists($name, $dict) ? $dict[$name] : (string) $code;
        }

        $json = json_encode($stored, JSON_UNESCAPED_UNICODE);

        $row = ScopeService::query('express_data', $aid)->where('bid', 0)->find();
        if (empty($row)) {
            ScopeService::query('express_data', $aid)->insert([
                'aid'          => $aid,
                'bid'          => 0,
                'express_data' => $json,
                'createtime'   => time(),
                'updatetime'   => time(),
            ]);
        } else {
            ScopeService::query('express_data', $aid)
                ->where('id', (int) $row['id'])
                ->update([
                    'express_data' => $json,
                    'updatetime'   => time(),
                ]);
        }

        return $this->success(['data' => $stored], '保存成功');
    }
}
