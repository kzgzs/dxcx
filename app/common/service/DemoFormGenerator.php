<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 演示自定义表单生成器（与运行方式解耦，命令 demo:forms 与数据库种子迁移共用同一份逻辑）。
 *
 * 对标点大 canzhao 的 Form / ApiAdminForm 控制器口径：
 *  - 表单 form 与提交记录 form_order 均按 bid 归属：bid=0 为平台表单，bid=商户ID 为商户在商户后台自建；
 *  - 平台租户后台「扩展 → 自定义表单」只能新增 bid=0 的平台表单；
 *  - 「客户端 → 商户 → 自定义表单」是平台对商户表单（bid>0）的聚合视图，不提供“添加”，
 *    商户表单在点大由商户自己登录商户后台（Backstage）创建。本项目暂无商户端入口，
 *    故通过本生成器按后台 save / recordsave 同一落库口径补齐商户示例表单及其提交数据。
 *  - form.content 为字段定义 JSON（key/val1..val15，严格对照前端 FormIndex 的 FormField）；
 *    form_order.form0~formN 按字段下标存放每条提交值（选择类逗号拼接、上传多图逗号拼接）。
 *  - 提交记录三态：status 0 待处理 / 1 已确认 / 2 已驳回（驳回带 reason）；paystatus 1 已支付。
 *
 * 幂等：表单名统一以【示例】开头（同时作为幂等与清理标识，请勿与真实表单重名），
 * 表单与其提交记录已存在则跳过；--reset 先清理后重建；--purge 仅删除本生成器产出的演示表单与提交记录。
 * 凭证图片：GD 生成 480×360 横版图到 public/uploads/demo（随库提交；已存在则跳过）。
 */
class DemoFormGenerator
{
    /** 演示数据名前缀（同时作为幂等与清理标识） */
    public const DEMO_TAG = '【示例】';

    /** 演示媒体 URL 前缀（走 /uploads 代理，开发态/生产态均可访问） */
    private const MEDIA_URL = '/uploads/demo/';

    private int $aid = 1;
    private bool $reset = false;
    private int $formCreated = 0;
    private int $orderCreated = 0;
    private int $skipped = 0;

    /** 商户演示表单归属的商户 ID（business.id） */
    private int $merchantBid = 0;

    /** @var array<int, string> 运行日志（带控制台颜色标签，由调用方决定如何输出） */
    private array $logs = [];

    /**
     * 生成演示表单（商户视图）。
     *
     * @return array{aid:int, formCreated:int, orderCreated:int, skipped:int, merchantBid:int, logs:array<int,string>}
     */
    public function generate(int $aid, bool $reset = false): array
    {
        $this->aid          = max(1, $aid);
        $this->reset        = $reset;
        $this->formCreated  = 0;
        $this->orderCreated = 0;
        $this->skipped      = 0;
        $this->logs         = [];

        $this->log('<info>==== 演示自定义表单生成开始（aid=' . $this->aid . ($this->reset ? '，--reset 重建' : '') . '）====</info>');

        if (empty(Db::name('admin')->where('id', $this->aid)->find())) {
            $this->log('<error>平台租户 admin.id=' . $this->aid . ' 不存在，请先完成平台初始化。</error>');
            return $this->result();
        }

        // 规范化历史平台数据：表单/提交记录早期 bid 可能为 NULL，平台视图固定 bid=0 后需先归 0
        $nForm  = Db::name('form')->where('aid', $this->aid)->whereNull('bid')->update(['bid' => 0]);
        $nOrder = Db::name('form_order')->where('aid', $this->aid)->whereNull('bid')->update(['bid' => 0]);
        if ($nForm || $nOrder) {
            $this->log('  规范化平台数据 bid（NULL→0）：表单 ' . $nForm . ' 个、提交记录 ' . $nOrder . ' 条');
        }

        $this->merchantBid = $this->resolveMerchant();
        if ($this->merchantBid <= 0) {
            $this->log('<error>未找到可用商户（business 表无 status=1 的商户），请先在后台添加商户后再执行。</error>');
            return $this->result();
        }
        $mname = (string) Db::name('business')->where('id', $this->merchantBid)->value('name');
        $this->log('<info>商户演示表单归属商户：bid=' . $this->merchantBid . '，' . $mname . '</info>');

        $this->ensureAssets();

        $this->log('');
        $this->log('<comment>-- 商户自定义表单（form，bid=' . $this->merchantBid . '）--</comment>');
        foreach ($this->formSpecs() as $spec) {
            $this->upsertForm($spec);
        }

        $this->log('');
        $this->log('<info>==== 生成结束：新建表单 ' . $this->formCreated . ' 个，新建提交记录 ' . $this->orderCreated . ' 条；跳过 ' . $this->skipped . ' 项 ====</info>');

        return $this->result();
    }

    /**
     * 清理本生成器产出的全部演示数据（商户演示表单 + 其提交记录）。
     * 只删带【示例】前缀且 bid>0 的表单，不触碰平台/真实表单。
     *
     * @return array{forms:int, orders:int}
     */
    public function purge(int $aid): array
    {
        $aid = max(1, $aid);

        $formIds = Db::name('form')
            ->where('aid', $aid)
            ->where('bid', '>', 0)
            ->whereLike('name', self::DEMO_TAG . '%')
            ->column('id');
        $formIds = array_map('intval', $formIds);

        $orders = 0;
        if ($formIds) {
            // 与后台 Form::delete 口径一致：删除表单时物理清理其提交记录
            $orders = Db::name('form_order')->where('aid', $aid)->whereIn('formid', $formIds)->delete();
            Db::name('form')->where('aid', $aid)->whereIn('id', $formIds)->delete();
        }

        return ['forms' => count($formIds), 'orders' => (int) $orders];
    }

    /**
     * 复用第一个正常商户（与 DemoProductGenerator / DemoArticleGenerator / DemoShortvideoGenerator 口径一致）。
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
     * 两个商户演示表单规格（字段定义严格对照点大截图：表单1 覆盖全部 11 种字段并开启在线支付；表单2 简易预约免支付）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function formSpecs(): array
    {
        $pic1 = self::MEDIA_URL . 'form_pic_1.jpg';
        $pic2 = self::MEDIA_URL . 'form_pic_2.jpg';

        return [
            [
                'name'      => self::DEMO_TAG . '商户报名',
                'days_ago'  => 7,
                'start_ago' => 30,
                'end_days'  => 365,
                'sort'      => 100,
                'payset'    => 1,
                'price'     => '22.00',
                // 字段定义：顺序即 form0~form10，与点大「编辑表单-表单内容」11 种字段一一对应
                'content'   => [
                    $this->fInput('姓名', '请填写姓名', 1, 0),
                    $this->fTextarea('报名留言', '请填写您的留言', 1),
                    $this->fRadio('单项选择', ['草莓', 'M', '香蕉', '菠萝'], 1),
                    $this->fCheckbox('多项选择', ['饼研', '乔林', '与一名', '老A'], 1),
                    $this->fSelector('下拉选择', ['1', '2', '3', '4'], 0),
                    $this->fRange('time', '时间选择', ['00:00', '23:59'], 0),
                    $this->fRange('date', '日期选择', ['1970-01-01', '2080-01-01'], 0),
                    $this->fRange('year', '年份选择', ['1970', '2080'], 0),
                    $this->fRegion('省市区选择', 1),
                    $this->fUpload('upload', '上传图片', '请上传凭证图片', 1),
                    $this->fUpload('upload_pics', '上传多图', '请上传多张图片', 0),
                ],
                // 提交记录：待处理（未支付，产生列表“数据”红色角标 1）/ 已确认（已支付）/ 已驳回（带原因）
                'orders'    => [
                    [
                        'days_ago' => 1, 'mid' => 1, 'status' => 0, 'paystatus' => 0, 'money' => '22.00',
                        'vals' => ['张小明', '想到店体验，希望了解新款商品。', '菠萝', '饼研,乔林', '2', '14:30', '2026-09-20', '2026', '山西省,长治市,郊区', $pic1, $pic1 . ',' . $pic2],
                    ],
                    [
                        'days_ago' => 2, 'mid' => 990039, 'status' => 1, 'paystatus' => 1, 'money' => '22.00', 'paid' => true,
                        'vals' => ['李女士', '报名线下体验活动，已确认行程。', '草莓', '与一名,老A', '1', '09:00', '2026-09-18', '2026', '山西省,长治市,郊区', $pic2, $pic1],
                    ],
                    [
                        'days_ago' => 3, 'mid' => 990040, 'status' => 2, 'paystatus' => 0, 'money' => '0.00',
                        'reason' => '信息不完整，请补充联系电话',
                        'vals' => ['王先生', '', 'M', '', '3', '', '', '', '', $pic1, ''],
                    ],
                ],
            ],
            [
                'name'      => self::DEMO_TAG . '到店服务预约',
                'days_ago'  => 6,
                'start_ago' => 20,
                'end_days'  => 180,
                'sort'      => 90,
                'payset'    => 0,
                'price'     => '0.00',
                'content'   => [
                    $this->fInput('联系人姓名', '请填写姓名', 1, 0),
                    $this->fInput('联系电话', '请填写手机号', 1, 2),
                    $this->fRange('date', '预约到店日期', ['1970-01-01', '2080-01-01'], 1),
                ],
                'orders'    => [
                    [
                        'days_ago' => 4, 'mid' => 1, 'status' => 1, 'paystatus' => 0, 'money' => '0.00',
                        'vals' => ['赵女士', '13800138000', '2026-09-22'],
                    ],
                ],
            ],
        ];
    }

    // ============ content 字段构造（键名/取值严格对照前端 FormIndex 与 canzhao） ============

    /**
     * @param int $required 1必填 0选填；$inputType 0文本/1数字/2手机号/3身份证/4邮箱
     * @return array<string, mixed>
     */
    private function fInput(string $name, string $placeholder, int $required, int $inputType): array
    {
        return $this->field('input', $name, $placeholder, $required, ['val4' => $inputType]);
    }

    /** @return array<string, mixed> */
    private function fTextarea(string $name, string $placeholder, int $required): array
    {
        return $this->field('textarea', $name, $placeholder, $required);
    }

    /** @param array<int,string> $options @return array<string, mixed> */
    private function fRadio(string $name, array $options, int $required): array
    {
        // val10 排列 0一行多个/1一行一个；val11 边框 0无/1有
        return $this->field('radio', $name, $options, $required, ['val10' => 0, 'val11' => 0]);
    }

    /** @param array<int,string> $options @return array<string, mixed> */
    private function fCheckbox(string $name, array $options, int $required): array
    {
        // val4 排列 0一行多个/1一行一个；val9 边框 0无/1有
        return $this->field('checkbox', $name, $options, $required, ['val4' => 0, 'val9' => 0]);
    }

    /** @param array<int,string> $options @return array<string, mixed> */
    private function fSelector(string $name, array $options, int $required): array
    {
        return $this->field('selector', $name, $options, $required);
    }

    /** @param array<int,string> $range @return array<string, mixed> */
    private function fRange(string $key, string $name, array $range, int $required): array
    {
        return $this->field($key, $name, $range, $required);
    }

    /** @return array<string, mixed> */
    private function fRegion(string $name, int $required): array
    {
        return $this->field('region', $name, '', $required);
    }

    /** @return array<string, mixed> */
    private function fUpload(string $key, string $name, string $tip, int $required): array
    {
        return $this->field($key, $name, '', $required, ['val15' => $tip]);
    }

    /**
     * 统一字段结构：{key,val1,val2,val3,val4,val8,val9,val10,val11,val15}。
     *
     * @param mixed $val2
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function field(string $key, string $name, $val2, int $required, array $override = []): array
    {
        return array_merge([
            'key'   => $key,
            'val1'  => $name,
            'val2'  => $val2,
            'val3'  => $required,
            'val4'  => 0,
            'val8'  => 0,
            'val9'  => 0,
            'val10' => 0,
            'val11' => 0,
            'val15' => '',
        ], $override);
    }

    /**
     * 幂等落库表单及其提交记录（同一落库口径对照后台 Form::save / Form::recordsave）。
     *
     * @param array<string, mixed> $spec
     */
    private function upsertForm(array $spec): void
    {
        $name = (string) $spec['name'];
        $now  = time();

        $existing = Db::name('form')
            ->where('aid', $this->aid)
            ->where('bid', $this->merchantBid)
            ->where('name', $name)
            ->find();

        if (!empty($existing) && $this->reset) {
            Db::name('form_order')->where('aid', $this->aid)->where('formid', (int) $existing['id'])->delete();
            Db::name('form')->where('aid', $this->aid)->where('id', (int) $existing['id'])->delete();
            $existing = null;
            $this->log('  重置（先删后建）：' . $name);
        }

        if (empty($existing)) {
            $formRow = [
                'aid'         => $this->aid,
                'bid'         => $this->merchantBid,
                'name'        => $name,
                'starttime'   => (string) ($now - max(0, (int) $spec['start_ago']) * 86400),
                'endtime'     => (string) ($now + max(1, (int) $spec['end_days']) * 86400),
                'content'     => json_encode(array_values((array) $spec['content']), JSON_UNESCAPED_UNICODE),
                'payset'      => (int) $spec['payset'],
                'price'       => (string) $spec['price'],
                'priceedit'   => 0,
                'maxlimit'    => 0,
                'perlimit'    => 1,
                'sort'        => (int) $spec['sort'],
                'createtime'  => $now - max(0, (int) $spec['days_ago']) * 86400,
                'commissionset' => -1,
            ];
            $fid = (int) Db::name('form')->insertGetId($formRow);
            $this->formCreated++;
            $this->log('  新建表单：' . $name . '（id=' . $fid . '，字段 ' . count((array) $spec['content']) . ' 个）');
        } else {
            $fid = (int) $existing['id'];
            $this->skipped++;
            $this->log('  跳过表单（已存在）：' . $name);
        }

        foreach ((array) $spec['orders'] as $ospec) {
            $this->upsertOrder($fid, $name, (array) $ospec);
        }
    }

    /**
     * 幂等落库一条提交记录（按 formid+mid+form0 判重；字段写入对照 Form::recordsave 新增分支）。
     *
     * @param array<string, mixed> $spec
     */
    private function upsertOrder(int $formId, string $formName, array $spec): void
    {
        $mid       = (int) ($spec['mid'] ?? 0);
        $vals      = array_values((array) ($spec['vals'] ?? []));
        $firstVal  = (string) ($vals[0] ?? '');
        $createtime = time() - max(0, (int) ($spec['days_ago'] ?? 0)) * 86400;

        $dup = Db::name('form_order')
            ->where('aid', $this->aid)
            ->where('formid', $formId)
            ->where('mid', $mid)
            ->where('form0', $firstVal)
            ->where('isudel', 0)
            ->find();
        if (!empty($dup)) {
            $this->skipped++;
            return;
        }

        $row = [
            'aid'        => $this->aid,
            'bid'        => $this->merchantBid,
            'formid'     => $formId,
            'title'      => $formName,
            'mid'        => $mid,
            'status'     => (int) ($spec['status'] ?? 0),
            'createtime' => $createtime,
            'isudel'     => 0,
            'paystatus'  => (int) ($spec['paystatus'] ?? 0),
            'money'      => (string) ($spec['money'] ?? '0'),
            'ordernum'   => date('ymdHis', $createtime) . $this->aid . (string) mt_rand(1000, 9999),
        ];
        if (!empty($spec['paid'])) {
            $row['paytime']  = $createtime + 600;
            $row['platform'] = '微信支付';
        }
        if (isset($spec['reason']) && $spec['reason'] !== '') {
            $row['reason'] = (string) $spec['reason'];
        }
        foreach ($vals as $k => $v) {
            $row['form' . $k] = (string) $v;
        }

        Db::name('form_order')->insert($row);
        $this->orderCreated++;
    }

    // ============ 凭证图片（GD） ============

    private function ensureAssets(): void
    {
        $dir = public_path() . 'uploads' . DIRECTORY_SEPARATOR . 'demo' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        foreach ([
            ['file' => 'form_pic_1.jpg', 'from' => [64, 158, 255], 'to' => [31, 92, 196], 'title' => '报名凭证 ①'],
            ['file' => 'form_pic_2.jpg', 'from' => [255, 159, 64], 'to' => [230, 108, 25], 'title' => '报名凭证 ②'],
        ] as $spec) {
            $path = $dir . $spec['file'];
            if (is_file($path) && filesize($path) > 0) {
                continue;
            }
            $this->drawVoucher($path, $spec);
            $this->log('  生成凭证图：uploads/demo/' . $spec['file']);
        }
    }

    /**
     * 480×360 横版渐变凭证图（用于上传图片 / 上传多图字段演示“查看图片”小弹窗）。
     *
     * @param array<string, mixed> $spec
     */
    private function drawVoucher(string $path, array $spec): void
    {
        $w = 480;
        $h = 360;
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
        $this->ttfCenter($im, 18, $font, imagecolorallocatealpha($im, 255, 255, 255, 60), 240, 90, 'DEMO 商户示例表单');
        $this->ttfCenter($im, 34, $font, $white, 240, 200, (string) $spec['title']);

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
     * @return array{aid:int, formCreated:int, orderCreated:int, skipped:int, merchantBid:int, logs:array<int,string>}
     */
    private function result(): array
    {
        return [
            'aid'          => $this->aid,
            'formCreated'  => $this->formCreated,
            'orderCreated' => $this->orderCreated,
            'skipped'      => $this->skipped,
            'merchantBid'  => $this->merchantBid,
            'logs'         => $this->logs,
        ];
    }

    private function log(string $line): void
    {
        $this->logs[] = $line;
    }
}
