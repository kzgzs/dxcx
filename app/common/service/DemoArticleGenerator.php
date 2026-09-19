<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 演示文章生成器（与运行方式解耦，命令 demo:articles 与数据库种子迁移共用同一份逻辑）。
 *
 * 对标点大 canzhao 的 Article 控制器口径：
 *  - 文章与文章分类均按 bid 归属：bid=0 为平台，bid=商户ID 为商户在商户后台自建；
 *  - 平台租户后台「营销/扩展 → 文章管理」只能新增 bid=0 的平台文章；
 *  - 「客户端 → 商户 → 文章列表」是平台对商户文章（bid>0）的聚合视图，不提供“添加”，
 *    商户文章在点大由商户自己登录商户后台（Backstage）创建。本项目暂无商户端入口，
 *    故通过本生成器以 Service 保存入口 + 回写 bid 的方式补齐商户示例文章。
 *
 * 幂等：文章/分类名统一以【示例】开头（同时作为幂等与清理标识，请勿与真实文章重名），
 * 已存在则跳过；--reset 先清理后重建；--purge 仅删除本生成器产出的演示文章与演示分类。
 * 图片：GD 生成到 public/uploads/demo（随库提交；已存在则跳过，线上无需 GD 也能显示）。
 */
class DemoArticleGenerator
{
    /** 演示数据名前缀（同时作为幂等与清理标识） */
    public const DEMO_TAG = '【示例】';

    /** 演示 URL 前缀（走 /uploads 代理，开发态/生产态均可访问） */
    private const IMG_URL = '/uploads/demo/';

    private int $aid = 1;
    private bool $reset = false;
    private int $created = 0;
    private int $skipped = 0;
    private int $catCreated = 0;

    /** 商户演示文章归属的商户 ID（business.id） */
    private int $merchantBid = 0;

    /** @var array<int, string> 运行日志（带控制台颜色标签，由调用方决定如何输出） */
    private array $logs = [];

    /**
     * 生成演示文章（商户视图）。
     *
     * @return array{aid:int, created:int, skipped:int, catCreated:int, merchantBid:int, logs:array<int,string>}
     */
    public function generate(int $aid, bool $reset = false): array
    {
        $this->aid        = max(1, $aid);
        $this->reset     = $reset;
        $this->created   = 0;
        $this->skipped   = 0;
        $this->catCreated = 0;
        $this->logs      = [];

        $this->log('<info>==== 演示文章生成开始（aid=' . $this->aid . ($this->reset ? '，--reset 重建' : '') . '）====</info>');

        if (empty(Db::name('admin')->where('id', $this->aid)->find())) {
            $this->log('<error>平台租户 admin.id=' . $this->aid . ' 不存在，请先完成平台初始化。</error>');
            return $this->result();
        }

        $this->merchantBid = $this->resolveMerchant();
        if ($this->merchantBid <= 0) {
            $this->log('<error>未找到可用商户（business 表无 status=1 的商户），请先在后台添加商户后再执行。</error>');
            return $this->result();
        }
        $mname = (string) Db::name('business')->where('id', $this->merchantBid)->value('name');
        $this->log('<info>商户演示文章归属商户：bid=' . $this->merchantBid . '，' . $mname . '</info>');

        $this->ensureImages();

        // 商户文章分类（点大口径：分类随商户，bid=商户ID）
        $catIds = $this->ensureMerchantCategories($mname);

        $this->log('');
        $this->log('<comment>-- 商户文章（article，bid=' . $this->merchantBid . '）--</comment>');
        foreach ($this->articleSpecs($mname, $catIds) as $spec) {
            $this->upsertArticle($spec);
        }

        $this->log('');
        $this->log('<info>==== 生成结束：新建文章 ' . $this->created . ' 篇，跳过 ' . $this->skipped . ' 篇；分类新建 ' . $this->catCreated . ' 个 ====</info>');

        return $this->result();
    }

    /**
     * 清理本生成器产出的全部演示数据（商户演示文章 + 演示文章分类）。
     * 只删带【示例】前缀的记录，不触碰真实数据。
     *
     * @return array{articles:int, categories:int}
     */
    public function purge(int $aid): array
    {
        $aid = max(1, $aid);

        $articleIds = Db::name('article')
            ->where('aid', $aid)
            ->where('bid', '>', 0)
            ->whereLike('name', self::DEMO_TAG . '%')
            ->column('id');
        if ($articleIds) {
            app(ArticleService::class)->delete($aid, array_map('intval', $articleIds));
        }

        $catIds = Db::name('article_category')
            ->where('aid', $aid)
            ->where('bid', '>', 0)
            ->whereLike('name', self::DEMO_TAG . '%')
            ->column('id');
        if ($catIds) {
            Db::name('article_category')
                ->where('aid', $aid)
                ->whereIn('id', $catIds)
                ->delete();
        }

        return ['articles' => count($articleIds), 'categories' => count($catIds)];
    }

    /**
     * 复用第一个正常商户（与 DemoProductGenerator 口径一致：sort 倒序、id 正序）。
     */
    private function resolveMerchant(): int
    {
        return (int) Db::name('business')
            ->where('aid', $this->aid)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->value('id');
    }

    /**
     * 商户演示分类（两个一级分类），返回 [分类名 => id]。
     *
     * @return array<string, int>
     */
    private function ensureMerchantCategories(string $merchantName): array
    {
        $names = [self::DEMO_TAG . '门店动态', self::DEMO_TAG . '优惠活动'];
        $now   = time();
        $map   = [];

        foreach ($names as $i => $name) {
            $existing = Db::name('article_category')
                ->where('aid', $this->aid)
                ->where('bid', $this->merchantBid)
                ->where('name', $name)
                ->find();
            if (!empty($existing)) {
                $map[$name] = (int) $existing['id'];
                continue;
            }
            $id = (int) Db::name('article_category')->insertGetId([
                'aid'        => $this->aid,
                'bid'        => $this->merchantBid,
                'pid'        => 0,
                'name'       => $name,
                'pic'        => '',
                'status'     => 1,
                'sort'       => 100 - $i,
                'createtime' => $now,
            ]);
            $map[$name] = $id;
            $this->catCreated++;
            $this->log('  新建商户文章分类：' . $name . '（cid=' . $id . '，归属 ' . $merchantName . '）');
        }

        return $map;
    }

    /**
     * 四篇商户演示文章规格。
     *
     * @param array<string, int> $catIds
     * @return array<int, array<string, mixed>>
     */
    private function articleSpecs(string $author, array $catIds): array
    {
        $now = time();

        return [
            [
                'name'      => self::DEMO_TAG . '新店入驻｜' . $author . '欢迎您',
                'subname'   => '门店正式上线，精选好物陆续上架，转发给好友一起看看吧。',
                'pic'       => self::IMG_URL . 'article_m_1.jpg',
                'cid_name'  => self::DEMO_TAG . '门店动态',
                'readcount' => 128,
                'sort'      => 100,
                'days_ago'  => 2,
                'paragraphs' => [
                    '您好，我们的门店已正式入驻平台，店内精选好物正在陆续上架。',
                    '门店坚持品质优先、服务至上的原则，所有商品均经过认真挑选，您可以放心下单。',
                    '开业期间关注店铺并下单，还可享受新客专属优惠，欢迎进店选购。',
                ],
            ],
            [
                'name'      => self::DEMO_TAG . '本周特惠｜精选商品限时折扣',
                'subname'   => '本周精选商品限时优惠，数量有限，先到先得。',
                'pic'       => self::IMG_URL . 'article_m_2.jpg',
                'cid_name'  => self::DEMO_TAG . '优惠活动',
                'readcount' => 356,
                'sort'      => 90,
                'days_ago'  => 1,
                'paragraphs' => [
                    '本周特惠活动开启，多款精选商品开启限时折扣，最低可享专属会员价。',
                    '活动商品库存有限，拍下后请尽快完成支付，逾期订单将自动关闭。',
                    '更多优惠信息可持续关注店铺动态，我们会不定期发放优惠券与福利。',
                ],
            ],
            [
                'name'      => self::DEMO_TAG . '保养小知识｜日常使用与维护指南',
                'subname'   => '几个简单的日常保养小技巧，让商品用得更久、更省心。',
                'pic'       => self::IMG_URL . 'article_m_3.jpg',
                'cid_name'  => self::DEMO_TAG . '门店动态',
                'readcount' => 89,
                'sort'      => 80,
                'days_ago'  => 5,
                'paragraphs' => [
                    '正确的使用与保养习惯，可以有效延长商品使用寿命。',
                    '建议避免长时间暴晒与潮湿环境，日常清洁使用柔软干布轻轻擦拭即可。',
                    '如在使用过程中遇到任何问题，欢迎随时联系门店客服，我们将竭诚为您服务。',
                ],
            ],
            [
                'name'      => self::DEMO_TAG . '会员日福利｜下单即享专属权益',
                'subname'   => '会员日当天，会员下单可享专属折扣与积分加倍。',
                'pic'       => self::IMG_URL . 'article_m_4.jpg',
                'cid_name'  => self::DEMO_TAG . '优惠活动',
                'readcount' => 245,
                'sort'      => 70,
                'days_ago'  => 9,
                'paragraphs' => [
                    '每月会员日，会员下单可享专属折扣，部分商品还可获得积分加倍。',
                    '积分可在积分商城兑换好礼，消费越多、权益越多。',
                    '还不是会员？进店即可快速开通，立即享受会员专属权益。',
                ],
            ],
        ];
    }

    /**
     * 幂等落库：已存在则跳过（或 reset 先删后建），统一走 ArticleService 保存入口，再回写商户归属。
     *
     * @param array<string, mixed> $spec
     */
    private function upsertArticle(array $spec): void
    {
        $name = (string) $spec['name'];

        $existing = Db::name('article')
            ->where('aid', $this->aid)
            ->where('bid', $this->merchantBid)
            ->where('name', $name)
            ->find();

        if (!empty($existing)) {
            if (!$this->reset) {
                $this->log('  跳过（已存在）：' . $name);
                $this->skipped++;
                return;
            }
            app(ArticleService::class)->delete($this->aid, [(int) $existing['id']]);
            $this->log('  重置（先删后建）：' . $name);
        }

        $cid = (int) ($spec['cid'] ?? 0);
        if (!$cid && !empty($spec['cid_name'])) {
            $cid = (int) Db::name('article_category')
                ->where('aid', $this->aid)
                ->where('bid', $this->merchantBid)
                ->where('name', (string) $spec['cid_name'])
                ->value('id');
        }

        $createtime = time() - max(0, (int) $spec['days_ago']) * 86400;
        $content    = $this->buildContent((array) $spec['paragraphs']);

        $data = [
            'cid'           => $cid,
            'name'          => $name,
            'subname'       => (string) $spec['subname'],
            'pic'           => (string) $spec['pic'],
            'author'        => $this->merchantName(),
            'content'       => $content,
            'readcount'     => (int) $spec['readcount'],
            'sort'          => (int) $spec['sort'],
            'createtime'    => $createtime,
            'sendtime'      => 0,
            'status'        => 1,
            'showname'      => 1,
            'showsubname'   => 1,
            'showreadcount' => 1,
            'showsendtime'  => 1,
            'showauthor'    => 1,
            'canpl'         => 1,
            'canplrp'       => 1,
            'pinglun_check' => 0,
            'showtj'        => '-1',
            'pcid'          => 0,
            'fujian'        => '',
            'mid'           => 0,
            // 新增时由 Service 写入归属（对标商户后台商户自建文章）
            'bid'           => $this->merchantBid,
        ];

        $res = app(ArticleService::class)->save($this->aid, $data);
        $id  = (int) ($res['id'] ?? 0);
        // 双保险：Service 新增口径已支持 bid，这里再次确保归属正确
        if ($id > 0) {
            Db::name('article')->where('aid', $this->aid)->where('id', $id)->update(['bid' => $this->merchantBid]);
        }
        $this->created++;
        $this->log('  新建：' . $name . '（id=' . $id . '，cid=' . $cid . '）');
    }

    private function merchantName(): string
    {
        return (string) Db::name('business')->where('id', $this->merchantBid)->value('name');
    }

    /**
     * 文章内容：与前端 GoodsDetailEditor 富文本模块同构的 JSON（richtext 单模块）。
     *
     * @param array<int, string> $paragraphs
     */
    private function buildContent(array $paragraphs): string
    {
        $html = '';
        foreach ($paragraphs as $p) {
            $html .= '<p>' . htmlspecialchars($p, ENT_QUOTES | ENT_HTML5) . '</p>';
        }

        $module = [
            'id'     => 'richtext-demo-' . substr(md5(uniqid('', true)), 0, 8),
            'temp'   => 'richtext',
            'params' => [
                'bgcolor'    => '#FFFFFF',
                'margin_x'   => '0',
                'margin_y'   => '0',
                'padding_x'  => '10',
                'padding_y'  => '10',
            ],
            'content' => $html,
            'data'    => [],
        ];

        return json_encode([$module], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ensureImages(): void
    {
        $dir = public_path() . 'uploads' . DIRECTORY_SEPARATOR . 'demo' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        foreach ($this->imageSpecs() as $spec) {
            $path = $dir . $spec['file'];
            if (is_file($path) && filesize($path) > 0) {
                continue;
            }
            $this->drawImage($path, $spec);
            $this->log('  生成图片：uploads/demo/' . $spec['file']);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function imageSpecs(): array
    {
        return [
            ['file' => 'article_m_1.jpg', 'from' => [90, 150, 250],  'to' => [29, 95, 208],  'tag' => '门店动态', 'title' => '新店入驻'],
            ['file' => 'article_m_2.jpg', 'from' => [255, 120, 138], 'to' => [224, 53, 75],  'tag' => '优惠活动', 'title' => '本周特惠'],
            ['file' => 'article_m_3.jpg', 'from' => [78, 205, 125],  'to' => [31, 158, 84],  'tag' => '门店动态', 'title' => '保养指南'],
            ['file' => 'article_m_4.jpg', 'from' => [255, 168, 77],  'to' => [245, 112, 10], 'tag' => '优惠活动', 'title' => '会员日'],
        ];
    }

    /**
     * 600×430 渐变封面图（对标点大建议尺寸 600×430）。
     *
     * @param array<string, mixed> $spec
     */
    private function drawImage(string $path, array $spec): void
    {
        $w = 600;
        $h = 430;
        $im = imagecreatetruecolor($w, $h);

        [$r1, $g1, $b1] = $spec['from'];
        [$r2, $g2, $b2] = $spec['to'];
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $c = imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imagefilledrectangle($im, 0, $y, $w, $y + 1, $c);
        }

        $white = imagecolorallocate($im, 255, 255, 255);
        $font  = $this->fontPath();

        $this->ttfCenter($im, 26, $font, $white, 300, 88, $spec['tag']);
        $this->ttfCenter($im, 52, $font, $white, 300, 230, $spec['title']);
        $this->ttfCenter($im, 20, $font, imagecolorallocatealpha($im, 255, 255, 255, 60), 300, 370, 'DEMO 商户示例文章');

        imagejpeg($im, $path, 88);
        imagedestroy($im);
    }

    private function ttfCenter(\GdImage $im, int $size, ?string $font, int $color, int $cx, int $baseline, string $text): void
    {
        if (null !== $font) {
            $box = imagettfbbox($size, 0, $font, $text);
            $tw  = $box[2] - $box[0];
            imagettftext($im, $size, 0, (int) ($cx - $tw / 2), $baseline, $color, $font, $text);
            return;
        }

        $tw = imagefontwidth(5) * mb_strlen($text);
        imagestring($im, 5, (int) ($cx - $tw / 2), (int) ($baseline - 18), $text, $color);
    }

    private function fontPath(): ?string
    {
        $candidates = [
            'C:/Windows/Fonts/simhei.ttf',
            'C:/Windows/Fonts/msyh.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ];
        foreach ($candidates as $f) {
            if (is_file($f)) {
                return $f;
            }
        }

        return null;
    }

    /**
     * @return array{aid:int, created:int, skipped:int, catCreated:int, merchantBid:int, logs:array<int,string>}
     */
    private function result(): array
    {
        return [
            'aid'        => $this->aid,
            'created'    => $this->created,
            'skipped'    => $this->skipped,
            'catCreated' => $this->catCreated,
            'merchantBid' => $this->merchantBid,
            'logs'       => $this->logs,
        ];
    }

    private function log(string $line): void
    {
        $this->logs[] = $line;
    }
}
