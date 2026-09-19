<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 演示短视频生成器（与运行方式解耦，命令 demo:shortvideos 与数据库种子迁移共用同一份逻辑）。
 *
 * 对标点大 canzhao 的 Shortvideo 控制器口径：
 *  - 短视频与短视频分类均按 bid 归属：bid=0 为平台，bid=商户ID 为商户在商户后台自建；
 *  - 平台租户后台「营销 → 短视频 → 视频列表」只能新增 bid=0 的平台视频；
 *  - 「客户端 → 商户 → 短视频列表」是平台对商户视频（bid>0）的聚合视图，不提供“添加”，
 *    商户视频在点大由商户自己登录商户后台（Backstage）创建。本项目暂无商户端入口，
 *    故通过本生成器以 Service 保存入口 + 回写 bid 的方式补齐商户示例短视频。
 *  - 状态三态：0 待审核 / 1 已通过 / 2 已驳回；business_sysset.shortvideo_check=0（免审），示例直接已通过。
 *
 * 幂等：视频/分类名统一以【示例】开头（同时作为幂等与清理标识，请勿与真实视频重名），
 * 已存在则跳过；--reset 先清理后重建；--purge 仅删除本生成器产出的演示视频与演示分类。
 * 封面：GD 生成 350×500 竖版图到 public/uploads/demo（随库提交；已存在则跳过）。
 * 视频：mp4 随库提交在 public/uploads/demo（shortvideo_m_1~3.mp4）；缺失时由 shortvideo_demo.mp4
 *       复制，再缺失尝试下载公共样片，最终回退外链，保证线上不空白。
 */
class DemoShortvideoGenerator
{
    /** 演示数据名前缀（同时作为幂等与清理标识） */
    public const DEMO_TAG = '【示例】';

    /** 演示媒体 URL 前缀（走 /uploads 代理，开发态/生产态均可访问） */
    private const MEDIA_URL = '/uploads/demo/';

    /** 本地视频缺失时的公共样片回退地址 */
    private const FALLBACK_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    private int $aid = 1;
    private bool $reset = false;
    private int $created = 0;
    private int $skipped = 0;
    private int $catCreated = 0;

    /** 商户演示视频归属的商户 ID（business.id） */
    private int $merchantBid = 0;

    /** @var array<int, string> 运行日志（带控制台颜色标签，由调用方决定如何输出） */
    private array $logs = [];

    /**
     * 生成演示短视频（商户视图）。
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

        $this->log('<info>==== 演示短视频生成开始（aid=' . $this->aid . ($this->reset ? '，--reset 重建' : '') . '）====</info>');

        if (empty(Db::name('admin')->where('id', $this->aid)->find())) {
            $this->log('<error>平台租户 admin.id=' . $this->aid . ' 不存在，请先完成平台初始化。</error>');
            return $this->result();
        }

        // 规范化历史平台数据：短视频/分类早期 bid 可能为 NULL，平台视图固定 bid=0 后需先归 0
        $nVideo = Db::name('shortvideo')->where('aid', $this->aid)->whereNull('bid')->update(['bid' => 0]);
        $nCat   = Db::name('shortvideo_category')->where('aid', $this->aid)->whereNull('bid')->update(['bid' => 0]);
        if ($nVideo || $nCat) {
            $this->log('  规范化平台数据 bid（NULL→0）：视频 ' . $nVideo . ' 条、分类 ' . $nCat . ' 个');
        }

        $this->merchantBid = $this->resolveMerchant();
        if ($this->merchantBid <= 0) {
            $this->log('<error>未找到可用商户（business 表无 status=1 的商户），请先在后台添加商户后再执行。</error>');
            return $this->result();
        }
        $mname = (string) Db::name('business')->where('id', $this->merchantBid)->value('name');
        $this->log('<info>商户演示视频归属商户：bid=' . $this->merchantBid . '，' . $mname . '</info>');

        $this->ensureAssets();

        // 商户短视频分类（点大口径：分类随商户，bid=商户ID；短视频分类为平铺一级）
        $catIds = $this->ensureMerchantCategories($mname);

        $this->log('');
        $this->log('<comment>-- 商户短视频（shortvideo，bid=' . $this->merchantBid . '）--</comment>');
        foreach ($this->videoSpecs($catIds) as $spec) {
            $this->upsertVideo($spec);
        }

        $this->log('');
        $this->log('<info>==== 生成结束：新建视频 ' . $this->created . ' 条，跳过 ' . $this->skipped . ' 条；分类新建 ' . $this->catCreated . ' 个 ====</info>');

        return $this->result();
    }

    /**
     * 清理本生成器产出的全部演示数据（商户演示视频 + 演示分类）。
     * 只删带【示例】前缀的记录，不触碰真实数据。
     *
     * @return array{videos:int, categories:int}
     */
    public function purge(int $aid): array
    {
        $aid = max(1, $aid);

        $videoIds = Db::name('shortvideo')
            ->where('aid', $aid)
            ->where('bid', '>', 0)
            ->whereLike('name', self::DEMO_TAG . '%')
            ->column('id');
        if ($videoIds) {
            // 走 Service 删除以级联清理评论 / 回评 / 点赞日志
            app(ShortvideoService::class)->videoDelete($aid, array_map('intval', $videoIds));
        }

        $catIds = Db::name('shortvideo_category')
            ->where('aid', $aid)
            ->where('bid', '>', 0)
            ->whereLike('name', self::DEMO_TAG . '%')
            ->column('id');
        if ($catIds) {
            Db::name('shortvideo_category')
                ->where('aid', $aid)
                ->whereIn('id', $catIds)
                ->delete();
        }

        return ['videos' => count($videoIds), 'categories' => count($catIds)];
    }

    /**
     * 复用第一个正常商户（与 DemoArticleGenerator / DemoProductGenerator 口径一致：sort 倒序、id 正序）。
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
     * 商户演示分类（平铺一级），返回 [分类名 => id]。
     *
     * @return array<string, int>
     */
    private function ensureMerchantCategories(string $merchantName): array
    {
        $names = [self::DEMO_TAG . '商户视频', self::DEMO_TAG . '好物推荐'];
        $now   = time();
        $map   = [];

        foreach ($names as $i => $name) {
            $existing = Db::name('shortvideo_category')
                ->where('aid', $this->aid)
                ->where('bid', $this->merchantBid)
                ->where('name', $name)
                ->find();
            if (!empty($existing)) {
                $map[$name] = (int) $existing['id'];
                continue;
            }
            $id = (int) Db::name('shortvideo_category')->insertGetId([
                'aid'        => $this->aid,
                'bid'        => $this->merchantBid,
                'name'       => $name,
                'status'     => 1,
                'sort'       => 100 - $i,
                'createtime' => $now,
            ]);
            $map[$name] = $id;
            $this->catCreated++;
            $this->log('  新建商户短视频分类：' . $name . '（cid=' . $id . '，归属 ' . $merchantName . '）');
        }

        return $map;
    }

    /**
     * 三条商户演示短视频规格。
     *
     * @param array<string, int> $catIds
     * @return array<int, array<string, mixed>>
     */
    private function videoSpecs(array $catIds): array
    {
        return [
            [
                'name'          => self::DEMO_TAG . '门店实拍｜欢迎到店逛逛',
                'cid_name'      => self::DEMO_TAG . '商户视频',
                'cover'         => 'shortvideo_m_1.jpg',
                'video'         => 'shortvideo_m_1.mp4',
                'description'   => '带您线上逛一圈门店，店内环境与热门商品抢先看，欢迎到店体验。',
                'view_num'      => 320,
                'zan_num'       => 18,
                'sort'          => 100,
                'days_ago'      => 1,
                'comment'       => 1,
                'comment_check' => 0,
            ],
            [
                'name'          => self::DEMO_TAG . '好物开箱｜精选商品近距离展示',
                'cid_name'      => self::DEMO_TAG . '好物推荐',
                'cover'         => 'shortvideo_m_2.jpg',
                'video'         => 'shortvideo_m_2.mp4',
                'description'   => '新到好物开箱实拍，细节、材质与使用方式一次看清，喜欢就下单吧。',
                'view_num'      => 560,
                'zan_num'       => 42,
                'sort'          => 90,
                'days_ago'      => 3,
                'comment'       => 1,
                'comment_check' => 0,
            ],
            [
                'name'          => self::DEMO_TAG . '使用教程｜一分钟学会日常保养',
                'cid_name'      => self::DEMO_TAG . '好物推荐',
                'cover'         => 'shortvideo_m_3.jpg',
                'video'         => 'shortvideo_m_3.mp4',
                'description'   => '简单几步做好日常保养，让商品用得更久、更省心，建议收藏。',
                'view_num'      => 210,
                'zan_num'       => 9,
                'sort'          => 80,
                'days_ago'      => 6,
                'comment'       => 1,
                'comment_check' => 1,
            ],
        ];
    }

    /**
     * 幂等落库：已存在则跳过（或 reset 先删后建），统一走 ShortvideoService 保存入口，再回写商户归属与演示时间。
     *
     * @param array<string, mixed> $spec
     */
    private function upsertVideo(array $spec): void
    {
        $name = (string) $spec['name'];

        $existing = Db::name('shortvideo')
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
            app(ShortvideoService::class)->videoDelete($this->aid, [(int) $existing['id']]);
            $this->log('  重置（先删后建）：' . $name);
        }

        $cid = 0;
        if (!empty($spec['cid_name'])) {
            $cid = (int) Db::name('shortvideo_category')
                ->where('aid', $this->aid)
                ->where('bid', $this->merchantBid)
                ->where('name', (string) $spec['cid_name'])
                ->value('id');
        }

        $createtime = time() - max(0, (int) $spec['days_ago']) * 86400;

        $data = [
            // 新增归属（对标商户后台商户自建视频）
            'bid'            => $this->merchantBid,
            'cid'            => $cid,
            'name'           => $name,
            'url'            => $this->mediaUrl((string) $spec['video'], true),
            'video_duration' => 10,
            'coverimg'       => $this->mediaUrl((string) $spec['cover'], false),
            'description'    => (string) $spec['description'],
            'productids'     => [],
            'linkurl'        => '',
            'linkname'       => '',
            'comment'        => (int) $spec['comment'],
            'comment_check'  => (int) $spec['comment_check'],
            'view_num'       => (int) $spec['view_num'],
            'zan_num'        => (int) $spec['zan_num'],
            'sort'           => (int) $spec['sort'],
            // 免审（shortvideo_check=0），示例直接“已通过”
            'status'         => 1,
            'reason'         => '',
        ];

        $res = app(ShortvideoService::class)->videoSave($this->aid, $data);
        $id  = (int) ($res['id'] ?? 0);
        // 双保险：再次确保归属正确，并写入演示创建时间
        if ($id > 0) {
            Db::name('shortvideo')->where('aid', $this->aid)->where('id', $id)
                ->update(['bid' => $this->merchantBid, 'createtime' => $createtime]);
        }
        $this->created++;
        $this->log('  新建：' . $name . '（id=' . $id . '，cid=' . $cid . '）');
    }

    /**
     * 媒体地址：本地文件存在走 /uploads/demo，视频本地缺失时回退公共样片外链。
     */
    private function mediaUrl(string $file, bool $isVideo): string
    {
        $local = public_path() . 'uploads' . DIRECTORY_SEPARATOR . 'demo' . DIRECTORY_SEPARATOR . $file;
        if (is_file($local) && filesize($local) > 0) {
            return self::MEDIA_URL . $file;
        }

        return $isVideo ? self::FALLBACK_VIDEO : self::MEDIA_URL . $file;
    }

    private function ensureAssets(): void
    {
        $dir = public_path() . 'uploads' . DIRECTORY_SEPARATOR . 'demo' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // 竖版封面（GD）
        foreach ($this->coverSpecs() as $spec) {
            $path = $dir . $spec['file'];
            if (is_file($path) && filesize($path) > 0) {
                continue;
            }
            $this->drawCover($path, $spec);
            $this->log('  生成封面：uploads/demo/' . $spec['file']);
        }

        // 视频文件：缺失则由 shortvideo_demo.mp4 复制，再缺失尝试下载公共样片
        foreach (['shortvideo_m_1.mp4', 'shortvideo_m_2.mp4', 'shortvideo_m_3.mp4'] as $file) {
            $path = $dir . $file;
            if (is_file($path) && filesize($path) > 0) {
                continue;
            }
            $demo = $dir . 'shortvideo_demo.mp4';
            if (is_file($demo) && filesize($demo) > 0) {
                @copy($demo, $path);
                $this->log('  复制视频：uploads/demo/' . $file);
                continue;
            }
            if ($this->downloadFile(self::FALLBACK_VIDEO, $path)) {
                $this->log('  下载视频：uploads/demo/' . $file);
            } else {
                $this->log('<comment>  视频本地缺失且下载失败，将回退外链：' . $file . '</comment>');
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function coverSpecs(): array
    {
        return [
            ['file' => 'shortvideo_m_1.jpg', 'from' => [90, 150, 250],  'to' => [29, 95, 208],  'tag' => '商户视频', 'title' => '门店实拍'],
            ['file' => 'shortvideo_m_2.jpg', 'from' => [255, 120, 138], 'to' => [224, 53, 75],  'tag' => '好物推荐', 'title' => '好物开箱'],
            ['file' => 'shortvideo_m_3.jpg', 'from' => [78, 205, 125],  'to' => [31, 158, 84],  'tag' => '好物推荐', 'title' => '使用教程'],
        ];
    }

    /**
     * 350×500 竖版渐变封面（对标点大建议尺寸 350×500）。
     *
     * @param array<string, mixed> $spec
     */
    private function drawCover(string $path, array $spec): void
    {
        $w = 350;
        $h = 500;
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

        $this->ttfCenter($im, 22, $font, $white, 175, 120, (string) $spec['tag']);
        $this->ttfCenter($im, 40, $font, $white, 175, 268, (string) $spec['title']);
        $this->ttfCenter($im, 15, $font, imagecolorallocatealpha($im, 255, 255, 255, 55), 175, 462, 'DEMO 商户示例短视频');

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
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc',
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
     * 下载远程文件（cURL 优先，allow_url_fopen 兜底），成功返回 true。
     */
    private function downloadFile(string $url, string $path): bool
    {
        if (function_exists('curl_init')) {
            $fp = @fopen($path, 'wb');
            if ($fp === false) {
                return false;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $ok = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            if ($ok && is_file($path) && filesize($path) > 0) {
                return true;
            }
            @unlink($path);
            return false;
        }

        if ((bool) ini_get('allow_url_fopen')) {
            $data = @file_get_contents($url);
            if ($data !== false && $data !== '') {
                return (bool) @file_put_contents($path, $data);
            }
        }

        return false;
    }

    /**
     * @return array{aid:int, created:int, skipped:int, catCreated:int, merchantBid:int, logs:array<int,string>}
     */
    private function result(): array
    {
        return [
            'aid'         => $this->aid,
            'created'     => $this->created,
            'skipped'     => $this->skipped,
            'catCreated'  => $this->catCreated,
            'merchantBid' => $this->merchantBid,
            'logs'        => $this->logs,
        ];
    }

    private function log(string $line): void
    {
        $this->logs[] = $line;
    }
}
