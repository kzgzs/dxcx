<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 共享海报服务（任务176 批次2-D）
 *
 * 表口径：
 * - admin_set_poster 海报模板（type：product 商品海报 / index 会员分享海报，
 *   及批次4-D 扩展六类：collage 拼团 / kanjia 砍价 / lucky_collage 幸运拼团 /
 *   tuangou 团购 / scoreshop 积分兑换 / cycle 周期购；
 *   platform：wx 小程序 / mp 公众号；content JSON：{poster_bg, poster_data[]}；guize 规则文案）
 * - member_poster    会员已生成海报缓存（模板保存「清除历史」时按 posterid 清理）
 *
 * 对照参考系统 ShopPoster / MemberPoster：
 * - product 单行（按 type+platform 定位，无则自动建默认模板）
 * - index 多行模板管理（新建默认模板/删除/切换）
 *
 * 元素结构：{left, top, type: img|text|textarea|head|qrwx|qrmp, width, height,
 * src?, color?, size?, content?, radius?}
 */
class PosterService
{
    /** 合法海报类型（yuyue：任务222 预约域海报，仿 ShopPoster 模式） */
    public const TYPES = ['product', 'index', 'collage', 'collageteam', 'kanjia', 'kanjiateam', 'lucky_collage', 'lucky_collageteam', 'tuangou', 'tuangouteam', 'scoreshop', 'cycle', 'fifa', 'shortvideo', 'kecheng', 'yuyue'];

    /** 合法平台 */
    /** 合法平台（mp 微信公众号 / wx 微信小程序 / alipay 支付宝小程序 / baidu 百度小程序 / toutiao 抖音小程序 / qq QQ小程序 / h5 手机H5 / app 手机APP） */
    public const PLATFORMS = ['mp', 'wx', 'alipay', 'baidu', 'toutiao', 'qq', 'h5', 'app'];

    /** 规则文案默认值（对齐参考系统 MemberPoster::index op=add） */
    public const DEFAULT_GUIZE = "第一步、转发链接或图片给微信好友；\r\n第二步、从您转发的链接或图片进入商城的好友，系统将自动锁定成为您的客户，他们在商城中购买商品，您就可以获得佣金；\r\n第三步、您可以在会员中心查看【我的团队】和【分销订单】，好友确认收货后佣金方可提现。";

    /**
     * 海报模板列表（管理用：index 多模板列表）
     *
     * @return array{list: array<int, array<string, mixed>>}
     */
    public function list(int $aid, string $type, string $platform): array
    {
        $this->assertType($type);

        $rows = ScopeService::query('admin_set_poster', $aid)
            ->where('type', $type)
            ->where('platform', $platform)
            ->field('id, createtime, guize, content')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $list = array_map(static function (array $row) {
            $content = json_decode((string) ($row['content'] ?? ''), true);

            return [
                'id'         => (int) $row['id'],
                'createtime' => (int) ($row['createtime'] ?? 0),
                'poster_bg'  => (string) ($content['poster_bg'] ?? ''),
                'element_num' => is_array($content['poster_data'] ?? null) ? count($content['poster_data']) : 0,
            ];
        }, $rows);

        return ['list' => $list];
    }

    /**
     * 读取海报模板（id>0 精确定位；否则取该 type+platform 首行；无则自动初始化）
     *
     * @return array<string, mixed>
     */
    public function get(int $aid, string $type, string $platform, int $id): array
    {
        $this->assertType($type);
        $platform = $this->normalizePlatform($platform);

        $query = ScopeService::query('admin_set_poster', $aid)
            ->where('type', $type)
            ->where('platform', $platform);

        $row = $id > 0
            ? (clone $query)->where('id', $id)->find()
            : (clone $query)->order('id', 'asc')->find();

        if (empty($row)) {
            // 自动初始化默认模板（对齐参考系统首进页面即有模板）
            $newId = $this->createDefault($aid, $type, $platform);
            $row   = ScopeService::query('admin_set_poster', $aid)->where('id', $newId)->find();
        }

        $row     = (array) $row;
        $content = json_decode((string) ($row['content'] ?? ''), true);

        return [
            'id'          => (int) $row['id'],
            'type'        => $type,
            'platform'    => $platform,
            'poster_bg'   => (string) ($content['poster_bg'] ?? ''),
            'poster_data' => is_array($content['poster_data'] ?? null) ? $content['poster_data'] : [],
            'guize'       => (string) ($row['guize'] ?? ''),
            'createtime'  => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 保存海报模板（id>0 更新；否则插入新行）
     * clearhistory=1：同步清理该模板已生成的会员海报缓存
     *
     * @param array<int, array<string, mixed>> $posterData
     * @return array{id: int}
     */
    public function save(int $aid, string $type, string $platform, int $id, string $posterBg, array $posterData, string $guize, int $clearHistory): array
    {
        $this->assertType($type);
        $platform = $this->normalizePlatform($platform);

        $content = json_encode([
            'poster_bg'   => $posterBg,
            'poster_data' => $posterData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($id > 0) {
            $exists = ScopeService::query('admin_set_poster', $aid)
                ->where('id', $id)
                ->where('type', $type)
                ->find();

            if (empty($exists)) {
                throw new BusinessException('海报模板不存在', 404, 404);
            }

            $update = ['content' => $content];
            if ('index' === $type) {
                $update['guize'] = $guize;
            }

            Db::name('admin_set_poster')->where('id', $id)->where('aid', $aid)->update($update);
        } else {
            $id = (int) Db::name('admin_set_poster')->insertGetId([
                'aid'        => $aid,
                'type'       => $type,
                'platform'   => $platform,
                'content'    => $content,
                'guize'      => 'index' === $type ? ($guize ?: self::DEFAULT_GUIZE) : '',
                'createtime' => time(),
            ]);
        }

        if (1 === $clearHistory) {
            Db::name('member_poster')
                ->where('aid', $aid)
                ->where('posterid', $id)
                ->delete();
        }

        return ['id' => $id];
    }

    /**
     * 新建海报模板（index 多模板；默认元素模板对齐参考系统 MemberPoster op=add）
     *
     * @return array{id: int}
     */
    public function add(int $aid, string $type, string $platform): array
    {
        $this->assertType($type);
        $platform = $this->normalizePlatform($platform);

        return ['id' => $this->createDefault($aid, $type, $platform)];
    }

    /**
     * 删除海报模板（index 专用；级联清理会员海报缓存）
     */
    public function delete(int $aid, string $type, int $id): void
    {
        if ('index' !== $type) {
            throw new BusinessException('仅分享海报模板支持删除');
        }
        if ($id <= 0) {
            throw new BusinessException('缺少海报ID');
        }

        $exists = ScopeService::query('admin_set_poster', $aid)
            ->where('id', $id)
            ->where('type', 'index')
            ->find();

        if (empty($exists)) {
            throw new BusinessException('海报模板不存在', 404, 404);
        }

        Db::name('admin_set_poster')->where('id', $id)->where('aid', $aid)->delete();
        Db::name('member_poster')->where('aid', $aid)->where('posterid', $id)->delete();
    }

    /* ================= 内部工具 ================= */

    /**
     * 创建默认模板并返回 ID
     * - index：对齐参考系统六元素默认模板（图片/标题/头像/昵称/推荐语/二维码）
     * - product：商品海报默认模板（商品图/商品名/价格/二维码）
     * - 批次4-D 六业务：背景+主图+标题+价格+业务标签+二维码（占位符对齐参考系统 C 端替换口径）
     */
    protected function createDefault(int $aid, string $type, string $platform): int
    {
        if ('index' === $type) {
            $data = [
                'poster_bg'   => '/static/img/posterbg.jpg',
                'poster_data' => [
                    ['left' => '30px', 'top' => '70px', 'type' => 'img', 'width' => '285px', 'height' => '285px', 'src' => '/static/img/poster_goods.jpg'],
                    ['left' => '30px', 'top' => '370px', 'type' => 'textarea', 'width' => '286px', 'height' => '47px', 'size' => '16px', 'color' => '#000', 'content' => '商城系统'],
                    ['left' => '34px', 'top' => '452px', 'type' => 'head', 'width' => '47px', 'height' => '47px', 'radius' => '100'],
                    ['left' => '89px', 'top' => '459px', 'type' => 'text', 'width' => '50px', 'height' => '18px', 'size' => '16px', 'color' => '#333333', 'content' => '[昵称]'],
                    ['left' => '90px', 'top' => '484px', 'type' => 'text', 'width' => '98px', 'height' => '14px', 'size' => '12px', 'color' => '#B6B6B6', 'content' => '推荐您加入'],
                    ['left' => '221px', 'top' => '446px', 'type' => self::qrTypeOf($platform), 'width' => '94px', 'height' => '94px', 'size' => ''],
                ],
            ];
            $guize = self::DEFAULT_GUIZE;
        } elseif ('product' === $type) {
            $data = [
                'poster_bg'   => '/static/img/posterbg.jpg',
                'poster_data' => [
                    ['left' => '30px', 'top' => '60px', 'type' => 'img', 'width' => '285px', 'height' => '285px', 'src' => '/static/img/poster_goods.jpg'],
                    ['left' => '30px', 'top' => '360px', 'type' => 'text', 'width' => '200px', 'height' => '22px', 'size' => '16px', 'color' => '#333333', 'content' => '[商品名称]'],
                    ['left' => '30px', 'top' => '390px', 'type' => 'text', 'width' => '120px', 'height' => '22px', 'size' => '18px', 'color' => '#e93b3d', 'content' => '[价格]'],
                    ['left' => '221px', 'top' => '446px', 'type' => self::qrTypeOf($platform), 'width' => '94px', 'height' => '94px', 'size' => ''],
                ],
            ];
            $guize = '';
        } else {
            // 六业务默认模板：主图/商品名/价格/业务标签/二维码（占位符对齐参考系统 ApiXxx::textReplaceArr）
            $tag = [
                'collage'          => '多人拼团 · 满员开团',
                'collageteam'      => '多人拼团 · 我已参团，快来拼单',
                'kanjia'           => '邀请好友砍一刀 · 越砍越便宜',
                'kanjiateam'       => '我已发起砍价 · 快来帮我砍一刀',
                'lucky_collage'    => '幸运拼团 · 满团抽奖开奖',
                'lucky_collageteam' => '幸运拼团 · 我已参团，快来拼单',
                'tuangou'          => '限时团购 · 限量特惠',
                'tuangouteam'      => '我已参团团购 · 快来一起拼单',
                'scoreshop'        => '积分兑换 · 好物免费换',
                'cycle'            => '周期购 · 一次下单定期配送',
                'yuyue'            => '预约服务 · 在线预约', // 任务222
            ][$type] ?? '';

            $price = 'scoreshop' === $type ? '[所需积分]积分' : '[商品销售价]';

            $data = [
                'poster_bg'   => '/static/img/posterbg.jpg',
                'poster_data' => [
                    ['left' => '30px', 'top' => '60px', 'type' => 'img', 'width' => '285px', 'height' => '285px', 'src' => '/static/img/poster_goods.jpg'],
                    ['left' => '30px', 'top' => '360px', 'type' => 'text', 'width' => '240px', 'height' => '22px', 'size' => '16px', 'color' => '#333333', 'content' => '[商品名称]'],
                    ['left' => '30px', 'top' => '390px', 'type' => 'text', 'width' => '140px', 'height' => '22px', 'size' => '18px', 'color' => '#e93b3d', 'content' => $price],
                    ['left' => '30px', 'top' => '420px', 'type' => 'text', 'width' => '180px', 'height' => '16px', 'size' => '12px', 'color' => '#999999', 'content' => $tag],
                    ['left' => '221px', 'top' => '446px', 'type' => self::qrTypeOf($platform), 'width' => '94px', 'height' => '94px', 'size' => ''],
                ],
            ];
            $guize = '';
        }

        return (int) Db::name('admin_set_poster')->insertGetId([
            'aid'        => $aid,
            'type'       => $type,
            'platform'   => $platform,
            'content'    => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'guize'      => $guize,
            'createtime' => time(),
        ]);
    }

    protected function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new BusinessException('海报类型无效');
        }
    }

    /**
     * 平台对应的默认二维码元素类型：五个小程序为各自小程序码，公众号 / H5 / APP 为网页二维码
     */
    protected static function qrTypeOf(string $platform): string
    {
        return match ($platform) {
            'wx'      => 'qrwx',
            'alipay'  => 'qralipay',
            'baidu'   => 'qrbaidu',
            'toutiao' => 'qrtoutiao',
            'qq'      => 'qrqq',
            default   => 'qrmp',
        };
    }

    protected function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return in_array($platform, self::PLATFORMS, true) ? $platform : 'wx';
    }
}
