<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\BindingTokenService;
use app\common\service\OpenPlatformService;
use app\common\service\ScopeService;
use app\common\service\WxApiClient;

/**
 * 租户后台 - 小程序服务类目
 *
 * 无本地表，纯接口透传（对照 canzhao\app\controller\Wxleimu.php 路数重写）：
 * - 列表：getCategory（createtype 区分展示口径）
 * - 添加：addCategory（createtype 1/2 代创建分路；资质图片 pictomedia 转临时素材，mock 直通）
 * - 删除：deleteCategory
 * mock 模式（OPEN_PLATFORM_MOCK=1）全部返回预置成功数据，可重复操作。
 */
class Wxleimu extends BaseController
{
    /** 单次最多添加类目数（微信上限 5） */
    private const MAX_CATEGORIES = 5;

    public function __construct(
        \think\App $app,
        protected OpenPlatformService $open,
        protected WxApiClient $api,
        protected BindingTokenService $bindingToken,
    ) {
        parent::__construct($app);
    }

    /**
     * 已设置类目列表
     * GET /admin/wxleimu/index
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);
        $row = $this->appRow($aid);
        $createtype = (int) ($row['createtype'] ?? 0);

        if ($this->open->isMock()) {
            // mock：预置两条样例类目（一条审核中、一条已通过），可稳定复测
            $list = [
                ['first' => 1, 'second' => 101, 'first_name' => '餐饮服务', 'second_name' => '外卖平台', 'audit_status' => 3],
                ['first' => 2, 'second' => 205, 'first_name' => '电商平台', 'second_name' => '综合电商', 'audit_status' => 1],
            ];
        } else {
            $token = $this->bindingToken->accessToken('wx', $aid);
            $rs = $this->api->getCategory($token);
            $errcode = (int) ($rs['errcode'] ?? 0);
            if (0 !== $errcode) {
                return $this->error('获取服务类目失败：' . $errcode . ' ' . (string) ($rs['errmsg'] ?? ''));
            }
            // 兼容两种口径：wxa/get_category=category_list；代创建 wxopen/getcategory=categories
            $list = $rs['category_list'] ?? $rs['categories'] ?? [];
        }

        // 归一化展示字段（对照参考系统：first_class/second_class；非代创建口径补 audit_status=3 已通过）
        foreach ($list as $k => $v) {
            $list[$k]['first_class']  = (string) ($v['first_name'] ?? $v['first_class'] ?? '');
            $list[$k]['second_class'] = (string) ($v['second_name'] ?? $v['second_class'] ?? '');
            if (!isset($v['audit_status']) && !in_array($createtype, [1, 2], true)) {
                $list[$k]['audit_status'] = 3;
            }
        }

        return $this->success([
            'list'       => array_values($list),
            'count'      => count($list),
            'createtype' => $createtype,
            'is_mock'    => $this->open->isMock(),
        ]);
    }

    /**
     * 可选类目（添加表单下拉数据）
     * GET /admin/wxleimu/options
     *
     * 微信 getallcategories 仅普通小程序可用、代授权无对应接口，
     * 这里统一提供常用类目样本（对照参考系统 getallleimu 的 first/second/exter_list 结构）。
     */
    public function options()
    {
        ScopeService::aidOf($this->request);

        $options = [
            ['first' => 1, 'first_name' => '餐饮服务', 'second' => 101, 'second_name' => '外卖平台', 'exter_list' => ['《食品经营许可证》']],
            ['first' => 1, 'first_name' => '餐饮服务', 'second' => 102, 'second_name' => '餐厅排队', 'exter_list' => []],
            ['first' => 2, 'first_name' => '电商平台', 'second' => 205, 'second_name' => '综合电商', 'exter_list' => ['《增值电信业务经营许可证》或《营业执照》']],
            ['first' => 2, 'first_name' => '电商平台', 'second' => 207, 'second_name' => '生鲜果蔬', 'exter_list' => ['《食品经营许可证》']],
            ['first' => 3, 'first_name' => '生活服务', 'second' => 301, 'second_name' => '丽人美发', 'exter_list' => []],
            ['first' => 3, 'first_name' => '生活服务', 'second' => 309, 'second_name' => '跑腿配送', 'exter_list' => ['《道路运输经营许可证》']],
            ['first' => 4, 'first_name' => '教育', 'second' => 401, 'second_name' => '在线教育', 'exter_list' => ['《民办学校办学许可证》']],
            ['first' => 5, 'first_name' => '旅游', 'second' => 502, 'second_name' => '酒店预订', 'exter_list' => ['《特种行业许可证》']],
        ];

        return $this->success(['list' => $options, 'is_mock' => $this->open->isMock()]);
    }

    /**
     * 添加类目（最多 5 组，资质图片随表单一并提交）
     * POST /admin/wxleimu/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);
        $row = $this->appRow($aid);
        $createtype = (int) ($row['createtype'] ?? 0);

        $categories = $this->request->post('categories/a');
        if (!is_array($categories) || empty($categories)) {
            return $this->error('请至少选择一个服务类目');
        }
        if (count($categories) > self::MAX_CATEGORIES) {
            return $this->error('单次最多添加 ' . self::MAX_CATEGORIES . ' 个类目');
        }

        // 组装 payload：first/second + certicates（资质图片转微信素材）
        $datalist = [];
        foreach ($categories as $item) {
            $first  = (int) ($item['first'] ?? 0);
            $second = (int) ($item['second'] ?? 0);
            if ($first <= 0 || $second <= 0) {
                return $this->error('类目选择不能为空');
            }
            $images = is_array($item['certicates'] ?? null) ? (array) $item['certicates'] : [];
            $images = array_values(array_filter(array_map('strval', $images), static fn ($v) => '' !== $v));

            $certicates = [];
            foreach ($this->pictomedia($aid, $images) as $k => $mediaId) {
                $certicates[] = ['key' => $k, 'value' => $mediaId];
            }
            $datalist[] = ['first' => $first, 'second' => $second, 'certicates' => $certicates];
        }

        if ($this->open->isMock()) {
            return $this->success(['createtype' => $createtype], '提交成功');
        }

        $token = $this->bindingToken->accessToken('wx', $aid);
        // createtype 1/2 = 开放平台代注册/代创建小程序；0 = 自行注册。
        // 两路均走 wxa/add_category（categories 数组），仅口径区分保留，对照参考系统 setleimu 路数。
        if (in_array($createtype, [1, 2], true)) {
            $rs = $this->api->addCategory($token, ['categories' => $datalist]);
        } else {
            $rs = $this->api->addCategory($token, ['categories' => $datalist]);
        }

        $errcode = (int) ($rs['errcode'] ?? 0);
        if (0 !== $errcode) {
            return $this->error('添加类目失败：' . $errcode . ' ' . (string) ($rs['errmsg'] ?? ''));
        }

        return $this->success([], '提交成功');
    }

    /**
     * 删除类目
     * POST /admin/wxleimu/del
     */
    public function del()
    {
        $aid   = ScopeService::aidOf($this->request);
        $this->appRow($aid);
        $first  = (int) $this->request->post('first', 0);
        $second = (int) $this->request->post('second', 0);
        if ($first <= 0 || $second <= 0) {
            return $this->error('缺少类目参数');
        }

        if ($this->open->isMock()) {
            return $this->success([], '删除成功');
        }

        $token = $this->bindingToken->accessToken('wx', $aid);
        $rs = $this->api->deleteCategory($token, $first, $second);
        $errcode = (int) ($rs['errcode'] ?? 0);
        if (0 !== $errcode) {
            return $this->error('删除类目失败：' . $errcode . ' ' . (string) ($rs['errmsg'] ?? ''));
        }

        return $this->success([], '删除成功');
    }

    /**
     * 资质图片转微信临时素材（对照参考系统 Wechat::pictomedia）
     * mock：直接生成 MOCK media_id，不走网络
     *
     * @param array<int, string> $images 已上传的图片 URL
     * @return array<int, string> media_id 列表
     */
    protected function pictomedia(int $aid, array $images): array
    {
        if (empty($images)) {
            return [];
        }

        if ($this->open->isMock()) {
            return array_map(static fn (string $u): string => 'MOCK_WX_MEDIA_' . substr(md5($u), 0, 16), $images);
        }

        $token = $this->bindingToken->accessToken('wx', $aid);
        $out = [];
        foreach ($images as $url) {
            // 本地上传的相对路径补全为站点地址后读取
            $src = $url;
            if (!preg_match('#^https?://#i', $src)) {
                $src = rtrim((string) $this->request->domain(), '/') . '/' . ltrim($src, '/');
            }
            $bin = @file_get_contents($src);
            if (false === $bin || '' === $bin) {
                throw new BusinessException('资质图片读取失败：' . $url);
            }
            $tmp = tempnam(runtime_path(), 'wxcat_');
            if (false === $tmp) {
                throw new BusinessException('临时文件创建失败');
            }
            file_put_contents($tmp, $bin);

            $rs = $this->api->mediaUpload($token, 'image', $tmp);
            @unlink($tmp);

            if (empty($rs['media_id'])) {
                throw new BusinessException('资质图片上传微信素材失败：' . (string) ($rs['errmsg'] ?? 'unknown'));
            }
            $out[] = (string) $rs['media_id'];
        }

        return $out;
    }

    /**
     * 小程序绑定记录（未绑定直接拒绝）
     *
     * @return array<string, mixed>
     */
    protected function appRow(int $aid): array
    {
        $row = ScopeService::query('admin_setapp_wx', $aid)->find();
        if (empty($row) || '' === (string) ($row['appid'] ?? '')) {
            throw new BusinessException('请先在[应用-微信-小程序绑定]中完成小程序绑定');
        }

        return (array) $row;
    }
}
