<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 周期购 分享海报（片C，对齐参考系统 CyclePoster）
 *
 * 对应「扩展--周期购--分享海报」截图：顶部 8 平台子 tab（公众号/微信/支付宝/百度/抖音/QQ/H5/APP），
 * 左侧手机预览，右侧组件面板（背景图/海报元素/二维码元素）+ 保存。
 * 真实画布读写走通用海报通道 /admin/poster/get|save（PosterService 已内置 type=cycle 默认模板）；
 * 本控制器负责：进页面时幂等确保 8 个平台都已生成 cycle 海报默认行（对齐参考系统首次加载播种），
 * 避免切到未初始化的平台出现空画布。默认元素对齐参考系统截图：商品图/商品名/头像/昵称/推荐语/销售价/市场价/二维码。
 */
class CyclePoster extends BaseController
{
    /** 八个终端：mp 公众号 / wx 微信小程序 / alipay 支付宝 / baidu 百度 / toutiao 抖音 / qq QQ / h5 H5 / app APP */
    public const PLATFORMS = ['mp', 'wx', 'alipay', 'baidu', 'toutiao', 'qq', 'h5', 'app'];

    /**
     * 进页面：幂等确保 8 平台 cycle 海报默认行存在，返回平台列表
     * GET /admin/cyclePoster/index
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        foreach (self::PLATFORMS as $platform) {
            $exists = Db::name('admin_set_poster')
                ->where('aid', $aid)->where('type', 'cycle')->where('platform', $platform)
                ->find();
            if (empty($exists)) {
                Db::name('admin_set_poster')->insert([
                    'aid'        => $aid,
                    'type'       => 'cycle',
                    'platform'   => $platform,
                    'content'    => json_encode($this->defaultContent($platform), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'guize'      => '',
                    'createtime' => time(),
                ]);
            }
        }

        return $this->success(['platforms' => self::PLATFORMS], 'ok');
    }

    /**
     * 默认海报元素（对齐参考系统 cycle 海报：商品图/商品名/头像/昵称/推荐语/销售价/市场价/二维码）
     *
     * @return array<string, mixed>
     */
    protected function defaultContent(string $platform): array
    {
        $qrType = match ($platform) {
            'wx'      => 'qrwx',
            'alipay'  => 'qralipay',
            'baidu'   => 'qrbaidu',
            'toutiao' => 'qrtoutiao',
            'qq'      => 'qrqq',
            default   => 'qrmp',
        };

        return [
            'poster_bg'   => '/static/img/posterbg.jpg',
            'poster_data' => [
                ['left' => '30px', 'top' => '70px', 'type' => 'pro_img', 'width' => '285px', 'height' => '285px'],
                ['left' => '30px', 'top' => '370px', 'type' => 'textarea', 'width' => '286px', 'height' => '47px', 'size' => '16px', 'color' => '#000000', 'content' => '[商品名称]'],
                ['left' => '34px', 'top' => '452px', 'type' => 'head', 'width' => '47px', 'height' => '47px', 'radius' => '100'],
                ['left' => '89px', 'top' => '459px', 'type' => 'text', 'width' => '90px', 'height' => '18px', 'size' => '16px', 'color' => '#333333', 'content' => '[昵称]'],
                ['left' => '90px', 'top' => '484px', 'type' => 'text', 'width' => '140px', 'height' => '14px', 'size' => '12px', 'color' => '#B6B6B6', 'content' => '推荐给你一个宝贝'],
                ['left' => '35px', 'top' => '516px', 'type' => 'text', 'width' => '142px', 'height' => '22px', 'size' => '20px', 'color' => '#FD0000', 'content' => '￥[商品销售价]'],
                ['left' => '180px', 'top' => '518px', 'type' => 'text', 'width' => '135px', 'height' => '16px', 'size' => '14px', 'color' => '#BBBBBB', 'content' => '原价:￥[商品市场价]'],
                ['left' => '221px', 'top' => '446px', 'type' => $qrType, 'width' => '94px', 'height' => '94px', 'size' => ''],
            ],
        ];
    }
}
