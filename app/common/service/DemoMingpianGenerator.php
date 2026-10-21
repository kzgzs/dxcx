<?php
// +----------------------------------------------------------------------
// | 名片示例数据生成器：名片5张(含头衔/联系信息/经纬度/个人简介) + 浏览记录 + 收藏记录
// | 幂等：姓名统一前缀 DEMO-MP-；purge 按前缀删本生成器名片并级联删除其浏览/收藏记录，不碰手工数据。
// | 对标 canzhao 名片列表：列表展示会员信息/姓名信息/头衔/联系信息动态列/创建时间，
// |   操作列 编辑/删除/查看链接/查看记录/收藏记录 均有真实数据可验证。
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoMingpianGenerator
{
    /** 姓名前缀（幂等标识） */
    private string $prefix = 'DEMO-MP-';
    private int $aid = 1;
    private int $now;

    public function __construct()
    {
        $this->now = time();
    }

    public function setAid(int $aid): void
    {
        $this->aid = $aid;
    }

    /** 仅删除本生成器数据（按姓名前缀），级联浏览/收藏记录，不动任何手工数据 */
    public function purge(): void
    {
        $ids = Db::name('mingpian')->where('aid', $this->aid)
            ->where('realname', 'like', $this->prefix . '%')->column('id');
        if (!empty($ids)) {
            Db::name('mingpian')->whereIn('id', $ids)->delete();
            Db::name('mingpian_readlog')->whereIn('mpid', $ids)->delete();
            Db::name('mingpian_favorite')->whereIn('mpid', $ids)->delete();
        }
    }

    /** 生成完整演示数据（幂等：先 purge） */
    public function generate(): void
    {
        $this->purge();

        // 取该租户下真实会员用于关联（会员信息列展示昵称头像），不足则 mid=0
        $mids = Db::name('member')->where('aid', $this->aid)->order('id')->limit(5)->column('id');

        // [姓名, 头衔1, 头衔2, 头衔3, 手机, 微信, 地址, 经度, 纬度, 邮箱, 抖音, 微博, 头条, 自定义1(快手号), 自定义2(视频号), 简介, 创建天数前]
        $cases = [
            ['张伟', '高级产品经理', 'PMP认证', '', '13800138001', 'zhangwei88', '北京市朝阳区建国路88号SOHO现代城A座1201', '116.480881', '39.989410', 'zhangwei@example.com', 'zhangwei_dy', 'zhangwei_wb', '', 'kuaishou_zw', 'sph_zw', '<p>10年互联网产品经验，主导过多个 SaaS 商城系统从0到1的落地，擅长多租户架构设计与增长策略。</p>', 1],
            ['李娜', '设计总监', '', '', '13900139002', 'lina_design', '上海市徐汇区漕溪北路331号中金国际广场B座8层', '121.436763', '31.197842', 'lina@example.com', 'lina_dy', '', 'lina_tt', '', '', '<p>专注品牌视觉与交互设计，服务过多家上市公司，主张「设计驱动商业价值」。</p>', 2],
            ['王强', '技术合伙人', '全栈工程师', '', '13700137003', 'wangqiang_dev', '广州市天河区珠江新城华夏路16号富力盈盛广场', '113.324520', '23.129163', 'wangqiang@example.com', '', 'wangqiang_wb', '', 'kuaishou_wq', '', '<p>PHP/Vue/小程序全栈开发，主导过电商、预约、餐饮等多行业系统交付。</p>', 4],
            ['刘洋', '市场运营总监', '', '金牌讲师', '13600136004', 'liuyang_op', '深圳市南山区科技园南区深南大道9988号', '113.953109', '22.533321', 'liuyang@example.com', 'liuyang_dy', 'liuyang_wb', '', '', 'sph_ly', '<p>擅长私域流量运营与裂变增长，操盘过百万级会员体系的搭建与激活。</p>', 6],
            ['陈静', '客户成功经理', '', '', '13500135005', 'chenjing_cs', '杭州市西湖区文三路90号东部软件园创新大厦', '120.130588', '30.259324', 'chenjing@example.com', '', '', 'chenjing_tt', '', '', '<p>以客户为中心，负责大客户交付与续约，客户满意度连续三年保持98%以上。</p>', 8],
        ];

        $mpIds = [];
        foreach ($cases as $i => $c) {
            [$name, $tx1, $tx2, $tx3, $tel, $weixin, $address, $lng, $lat, $email, $douyin, $weibo, $toutiao, $f1, $f2, $detailHtml, $daysAgo] = $c;
            // detail 存组件数组 JSON（口径同商品详情/个人简介装修器：richtext 组件包装富文本）
            $detail = json_encode([[
                'id'      => 'MPDEMO' . $i,
                'temp'    => 'richtext',
                'params'  => [
                    'bgcolor'   => '#FFFFFF',
                    'margin_x'  => '0',
                    'margin_y'  => '0',
                    'padding_x' => '10',
                    'padding_y' => '10',
                    'platform'  => ['all' => true],
                    'quanxian'  => ['all' => true],
                ],
                'content' => $detailHtml,
                'data'    => [],
            ]], JSON_UNESCAPED_UNICODE);
            $ct = (int) round($this->now - 86400 * $daysAgo);
            $mpIds[] = (int) Db::name('mingpian')->insertGetId([
                'aid'        => $this->aid,
                'mid'        => (int) ($mids[$i] ?? 0),
                'bgpic'      => '',
                'headimg'    => '',
                'realname'   => $this->prefix . $name,
                'touxian1'   => $tx1,
                'touxian2'   => $tx2,
                'touxian3'   => $tx3,
                'tel'        => $tel,
                'weixin'     => $weixin,
                'address'    => $address,
                'email'      => $email,
                'douyin'     => $douyin,
                'weibo'      => $weibo,
                'toutiao'    => $toutiao,
                'field1'     => $f1,
                'field2'     => $f2,
                'field3'     => '',
                'field4'     => '',
                'field5'     => '',
                'longitude'  => $lng,
                'latitude'   => $lat,
                'detail'     => $detail,
                'sharetitle' => '',
                'createtime' => $ct,
                'updatetime' => $ct,
            ]);
        }

        // 浏览/收藏记录：取真实会员产生记录，验证 查看记录/收藏记录 弹窗
        $memberRows = Db::name('member')->where('aid', $this->aid)
            ->whereIn('id', $mids ?: [0])->field('id')->select()->toArray();
        $memberIds = array_map(static fn ($m) => (int) $m['id'], $memberRows);
        if (empty($memberIds)) {
            $memberIds = [1, 2, 3];
        }

        foreach ($mpIds as $idx => $mpid) {
            // 浏览记录 3-5 条
            $readNum = 3 + ($idx % 3);
            for ($j = 0; $j < $readNum; $j++) {
                Db::name('mingpian_readlog')->insert([
                    'aid'        => $this->aid,
                    'mid'        => $memberIds[($idx + $j) % count($memberIds)],
                    'mpid'       => $mpid,
                    'createtime' => $this->now - 3600 * ($j + 1) - 86400 * ($idx % 3),
                ]);
            }
            // 收藏记录 1-3 条
            $favNum = 1 + ($idx % 3);
            for ($j = 0; $j < $favNum; $j++) {
                Db::name('mingpian_favorite')->insert([
                    'aid'        => $this->aid,
                    'mid'        => $memberIds[($idx + $j + 1) % count($memberIds)],
                    'mpid'       => $mpid,
                    'createtime' => $this->now - 7200 * ($j + 1) - 86400 * ($idx % 2),
                ]);
            }
        }
    }
}
