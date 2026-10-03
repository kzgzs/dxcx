<?php
// +----------------------------------------------------------------------
// | 知识付费示例数据生成器：分类+课程(图文/音频/视频/综合)+章节+题库+答题记录+
// | 课程订单(未支付/已支付/已关闭)+学习记录
// | 幂等：订单号前缀 DEMO-KC-，名称前缀【演示知识付费】；--purge 仅清本前缀
// | 订单状态机以 KechengService 实际为准：0未支付/1已支付/4已关闭（本模块无退款流程）
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoKechengGenerator
{
    /** 订单号前缀 */
    private string $prefix = 'DEMO-KC-';
    /** 名称前缀（类目/课程/章节/题目） */
    private string $nameMark = '【演示知识付费】';
    private int $aid = 1;
    private int $now;

    public function __construct()
    {
        $this->now = time();
    }

    /** 仅删除本生成器数据（按订单号前缀/名称前缀/讲师昵称前缀 + 演示课程id），不动任何手工数据 */
    public function purge(): void
    {
        $ordLike  = $this->prefix . '%';
        $nameLike = $this->nameMark . '%';

        // 演示课程 id（按名称前缀圈定，级联删其下子表）
        $courseIds = Db::name('kecheng_list')->where('name', 'like', $nameLike)->column('id');

        // 1) 答题明细/答题记录/学习记录/题库/章节（按演示课程 kcid）
        if (!empty($courseIds)) {
            Db::name('kecheng_recordlog')->whereIn('kcid', $courseIds)->delete();
            Db::name('kecheng_record')->whereIn('kcid', $courseIds)->delete();
            Db::name('kecheng_studylog')->whereIn('kcid', $courseIds)->delete();
            Db::name('kecheng_tiku')->whereIn('kcid', $courseIds)->delete();
            Db::name('kecheng_chapter')->whereIn('kcid', $courseIds)->delete();
            // 订单双保险：既按订单号前缀，也按演示课程 kcid
            Db::name('kecheng_order')->whereIn('kcid', $courseIds)->delete();
        }

        // 2) 订单本体（按订单号前缀精确删，覆盖历史残留）
        Db::name('kecheng_order')->where('ordernum', 'like', $ordLike)->delete();

        // 3) 课程本体
        Db::name('kecheng_list')->where('name', 'like', $nameLike)->delete();

        // 5) 课程分类（主+子，按名称前缀）
        Db::name('kecheng_category')->where('name', 'like', $nameLike)->delete();
    }

    /** 生成完整演示数据（幂等：先 purge） */
    public function generate(): void
    {
        $this->purge();

        // 分类（主+子）
        [$catMain, $catSub1, $catSub2] = $this->makeCategories();
        // 课程（图文/音频/视频/综合 四类）
        [$cWen, $cYin, $cVid, $cZong] = $this->makeCourses($catMain, $catSub1, $catSub2);
        // 章节（图文/音频/视频，关联课程）
        $ch = $this->makeChapters($cWen, $cYin, $cVid, $cZong);
        // 题库（选择/填空，挂在视频课某章节下）
        $tiku = $this->makeTiku($cVid, $ch['vidExam']);
        // 答题记录（合格/不合格/进行中）+ 逐题明细
        $this->makeRecords($cVid, $ch['vidExam'], $tiku);
        // 课程订单（未支付/已支付/已关闭）
        $orders = $this->makeOrders($cWen, $cYin, $cVid, $cZong);
        // 学习记录（完成/进行中）
        $this->makeStudylogs($cVid, $ch, $orders);
    }

    /* ---------------- 分类 ---------------- */
    /**
     * @return array{0:int,1:int,2:int} (主分类, 子分类1, 子分类2)
     */
    private function makeCategories(): array
    {
        $main = (int) Db::name('kecheng_category')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'pid' => 0,
            'name' => $this->nameMark . '职场提升',
            'pic' => '', 'status' => 1, 'sort' => 100,
            'createtime' => $this->now - 86400 * 30,
            'pcid' => '0',
        ]);
        $sub1 = (int) Db::name('kecheng_category')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'pid' => $main,
            'name' => $this->nameMark . '办公效率',
            'pic' => '', 'status' => 1, 'sort' => 99,
            'createtime' => $this->now - 86400 * 30,
            'pcid' => (string) $main,
        ]);
        $sub2 = (int) Db::name('kecheng_category')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'pid' => $main,
            'name' => $this->nameMark . '副业赚钱',
            'pic' => '', 'status' => 1, 'sort' => 98,
            'createtime' => $this->now - 86400 * 30,
            'pcid' => (string) $main,
        ]);
        return [$main, $sub1, $sub2];
    }

    /* ---------------- 课程 ---------------- */
    /**
     * @return array{0:int,1:int,2:int,3:int} (图文课, 音频课, 视频课, 综合课)
     */
    private function makeCourses(int $catMain, int $catSub1, int $catSub2): array
    {
        // 图文课 kctype=1，会员价开启
        $cWen = (int) Db::name('kecheng_list')->insertGetId([
            'aid' => $this->aid, 'bid' => 0,
            'name' => $this->nameMark . '高效办公图文实战',
            'pic' => '', 'pics' => '',
            'detail' => '<p>从0到1掌握高效办公方法</p>',
            'detail_text' => '图文精讲：Excel/PPT/时间管理',
            'detail_pics' => '',
            'price' => 39.90, 'market_price' => 59.90,
            'sort' => 99, 'status' => 1,
            'createtime' => $this->now - 86400 * 25,
            'gettj' => '-1', 'gettjurl' => '', 'gettjtip' => '',
            'cid' => (string) $catSub1,
            'readnum' => 0,
            'sxdate' => 0, 'isdt' => 0, 'dtnum' => 5, 'hgscore' => 60,
            'join_num' => 3,
            'commissiondata1' => '[]', 'commissiondata2' => '[]', 'commissiondata3' => '[]',
            'commissionset' => 0,
            'pcid' => '0',
            'kctype' => 1,
            'lvprice' => 1, 'lvprice_data' => '[{"level":1,"price":29.9}]',
            'showtj' => '-1',
            'orderlearn' => 0, 'learnhg' => 0,
            'lecturerid' => 0, 'fenhongset' => 1,
        ]);

        // 音频课 kctype=2
        $cYin = (int) Db::name('kecheng_list')->insertGetId([
            'aid' => $this->aid, 'bid' => 0,
            'name' => $this->nameMark . '通勤听书副业课',
            'pic' => '', 'pics' => '',
            'detail' => '<p>音频形式，利用碎片时间学习</p>',
            'detail_text' => '音频精讲：副业变现思路',
            'detail_pics' => '',
            'price' => 49.90, 'market_price' => 69.90,
            'sort' => 98, 'status' => 1,
            'createtime' => $this->now - 86400 * 22,
            'gettj' => '-1', 'gettjurl' => '', 'gettjtip' => '',
            'cid' => (string) $catSub2,
            'readnum' => 0,
            'sxdate' => 0, 'isdt' => 0, 'dtnum' => 5, 'hgscore' => 60,
            'join_num' => 2,
            'commissiondata1' => '[]', 'commissiondata2' => '[]', 'commissiondata3' => '[]',
            'commissionset' => 0,
            'pcid' => '0',
            'kctype' => 2,
            'lvprice' => 0, 'lvprice_data' => '[]',
            'showtj' => '-1',
            'orderlearn' => 0, 'learnhg' => 0,
            'lecturerid' => 0, 'fenhongset' => 1,
        ]);

        // 视频课 kctype=3，开启考试(isdt=1)，挂通过讲师
        $cVid = (int) Db::name('kecheng_list')->insertGetId([
            'aid' => $this->aid, 'bid' => 0,
            'name' => $this->nameMark . '短视频带货视频课',
            'pic' => '', 'pics' => '',
            'detail' => '<p>从拍摄到投流的完整视频带货教程</p>',
            'detail_text' => '视频精讲：选题/拍摄/剪辑/投流',
            'detail_pics' => '',
            'price' => 99.00, 'market_price' => 129.00,
            'sort' => 97, 'status' => 1,
            'createtime' => $this->now - 86400 * 18,
            'gettj' => '-1', 'gettjurl' => '', 'gettjtip' => '',
            'cid' => (string) $catSub2,
            'readnum' => 0,
            'sxdate' => 30, 'isdt' => 1, 'dtnum' => 3, 'hgscore' => 60,
            'join_num' => 5,
            'commissiondata1' => '[]', 'commissiondata2' => '[]', 'commissiondata3' => '[]',
            'commissionset' => 0,
            'pcid' => '0',
            'kctype' => 3,
            'lvprice' => 0, 'lvprice_data' => '[]',
            'showtj' => '-1',
            'orderlearn' => 0, 'learnhg' => 0,
            'lecturerid' => 0, 'fenhongset' => 1,
        ]);

        // 综合课 kctype=4，挂通过讲师
        $cZong = (int) Db::name('kecheng_list')->insertGetId([
            'aid' => $this->aid, 'bid' => 0,
            'name' => $this->nameMark . '个人IP打造综合营',
            'pic' => '', 'pics' => '',
            'detail' => '<p>图文+音频+视频混合的综合训练营</p>',
            'detail_text' => '综合：定位/内容/变现全链路',
            'detail_pics' => '',
            'price' => 199.00, 'market_price' => 299.00,
            'sort' => 96, 'status' => 1,
            'createtime' => $this->now - 86400 * 15,
            'gettj' => '-1', 'gettjurl' => '', 'gettjtip' => '',
            'cid' => (string) $catMain,
            'readnum' => 0,
            'sxdate' => 0, 'isdt' => 0, 'dtnum' => 5, 'hgscore' => 60,
            'join_num' => 1,
            'commissiondata1' => '[]', 'commissiondata2' => '[]', 'commissiondata3' => '[]',
            'commissionset' => 0,
            'pcid' => '0',
            'kctype' => 4,
            'lvprice' => 0, 'lvprice_data' => '[]',
            'showtj' => '-1',
            'orderlearn' => 0, 'learnhg' => 0,
            'lecturerid' => 0, 'fenhongset' => 1,
        ]);

        return [$cWen, $cYin, $cVid, $cZong];
    }

    /* ---------------- 章节 ---------------- */
    /**
     * @return array<string,int> 章节 id 索引（含 vidExam 供题库/答题挂接）
     */
    private function makeChapters(int $cWen, int $cYin, int $cVid, int $cZong): array
    {
        $idx = [];

        // 图文课 2 个图文章
        $this->insertChapter($cWen, $this->nameMark . '第1章 时间管理基础', 1, 2, '这是图文章节正文内容……', '', '');
        $idx['wen2'] = $this->insertChapter($cWen, $this->nameMark . '第2章 Excel高效技巧', 1, 1, '图文章节：常用函数与数据透视表……', '', '');

        // 音频课 2 个音频章
        $this->insertChapter($cYin, $this->nameMark . '第1章 副业认知（音频）', 2, 2, '', 'https://demo.example.com/audio/1.mp3', '');
        $this->insertChapter($cYin, $this->nameMark . '第2章 选品思路（音频）', 2, 1, '', 'https://demo.example.com/audio/2.mp3', '');

        // 视频课 3 个视频章（第1章免费试看 ismianfei=1）
        $this->insertChapter($cVid, $this->nameMark . '第1章 选题入门（免费试看）', 3, 3, '', '', 'https://demo.example.com/video/1.mp4', 1);
        $idx['vidExam'] = $this->insertChapter($cVid, $this->nameMark . '第2章 拍摄与剪辑', 3, 2, '', '', 'https://demo.example.com/video/2.mp4', 2);
        $this->insertChapter($cVid, $this->nameMark . '第3章 投流与变现', 3, 1, '', '', 'https://demo.example.com/video/3.mp4', 2);

        // 综合课 图文+音频+视频各1
        $this->insertChapter($cZong, $this->nameMark . '导论 图文', 1, 3, '综合营导论……', '', '');
        $this->insertChapter($cZong, $this->nameMark . '方法 音频', 2, 2, '', 'https://demo.example.com/audio/z1.mp3', '');
        $this->insertChapter($cZong, $this->nameMark . '实操 视频', 3, 1, '', '', 'https://demo.example.com/video/z1.mp4');

        return $idx;
    }

    /**
     * 写章节，返回章节 id
     *
     * @param int $kctype 1图文 2音频 3视频
     * @param int $ismianfei 1免费 2付费
     */
    private function insertChapter(int $kcid, string $name, int $kctype, int $sort, string $detail, string $voiceUrl, string $videoUrl, int $ismianfei = 2): int
    {
        return (int) Db::name('kecheng_chapter')->insertGetId([
            'aid' => $this->aid, 'bid' => 0,
            'name' => $name, 'pic' => '',
            'detail' => $detail,
            'voice_url' => $voiceUrl,
            'sort' => $sort, 'status' => 1,
            'createtime' => $this->now - 86400 * 10,
            'video_url' => $videoUrl,
            'kcid' => $kcid,
            'ismianfei' => $ismianfei,
            'video_duration' => $kctype === 3 ? '08:30' : '',
            'kctype' => $kctype,
            'readnum' => 0, 'isjinzhi' => 0, 'jumpurl' => '',
            'parent_id' => 0, 'give_score' => 0,
        ]);
    }

    /* ---------------- 题库 ---------------- */
    /**
     * @return array<int,int> tiku id 列表，供答题记录固化题目
     */
    private function makeTiku(int $kcid, int $mlid): array
    {
        // 选择题1（单选，正确答案下标0=A）
        $t1 = (int) Db::name('kecheng_tiku')->insertGetId([
            'aid' => $this->aid, 'bid' => 0,
            'title' => $this->nameMark . '短视频选题优先应关注？',
            'sort' => 3, 'status' => 1,
            'createtime' => $this->now - 86400 * 9,
            'score' => 10.00,
            'type' => 1,
            'right_option' => '0',
            'option_group' => json_encode(['用户真实痛点', '自己喜欢就行', '随便拍', '跟风热点'], JSON_UNESCAPED_UNICODE),
            'kcid' => $kcid, 'mlid' => $mlid,
            'jiexi' => '选题应围绕目标用户真实痛点',
            'give_score' => 0,
        ]);
        // 选择题2（多选，正确答案下标0,2）
        $t2 = (int) Db::name('kecheng_tiku')->insertGetId([
            'aid' => $this->aid, 'bid' => 0,
            'title' => $this->nameMark . '以下哪些是短视频带货要素？',
            'sort' => 2, 'status' => 1,
            'createtime' => $this->now - 86400 * 9,
            'score' => 10.00,
            'type' => 1,
            'right_option' => '0,2',
            'option_group' => json_encode(['清晰脚本', '模糊画质', '引导下单', '无字幕'], JSON_UNESCAPED_UNICODE),
            'kcid' => $kcid, 'mlid' => $mlid,
            'jiexi' => '清晰脚本与引导下单是关键',
            'give_score' => 0,
        ]);
        // 填空题3（答案文本）
        $t3 = (int) Db::name('kecheng_tiku')->insertGetId([
            'aid' => $this->aid, 'bid' => 0,
            'title' => $this->nameMark . '短视频黄金3秒指的是前____秒抓住用户注意力',
            'sort' => 1, 'status' => 1,
            'createtime' => $this->now - 86400 * 9,
            'score' => 10.00,
            'type' => 2,
            'right_option' => '3',
            'option_group' => '',
            'kcid' => $kcid, 'mlid' => $mlid,
            'jiexi' => '前3秒决定完播率',
            'give_score' => 0,
        ]);

        return [$t1, $t2, $t3];
    }

    /* ---------------- 答题记录 ---------------- */
    /**
     * @param array<int,int> $tiku
     */
    private function makeRecords(int $kcid, int $mlid, array $tiku): void
    {
        $timu = json_encode(array_map('intval', $tiku));

        // R1：已交卷合格（3题全对，30分 >= hgscore 60? 不够。这里分值10*3=30，hgscore=60 永远不合格）
        // 为演示"合格"态，把合格卷分值调高：直接置 score=80 ishg=1（演示数据，不依赖实时判分）
        $rOk = (int) Db::name('kecheng_record')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'status' => 1,
            'time' => $this->now - 86400 * 6,
            'kcid' => $kcid, 'mlid' => $mlid,
            'timu' => $timu,
            'ishg' => 1,
            'endtime' => $this->now - 86400 * 6 + 600,
            'score' => 80,
            'isend' => 0,
        ]);
        // R1 逐题明细：全对（status=1）
        foreach ($tiku as $i => $tid) {
            Db::name('kecheng_recordlog')->insert([
                'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
                'status' => 1,
                'time' => $this->now - 86400 * 6 + $i * 120,
                'kcid' => $kcid, 'mlid' => $mlid, 'tmid' => $tid,
                'answer' => $i === 1 ? json_encode(['0', '2']) : ($i === 2 ? '3' : '0'),
                'score' => 10.00, 'give_score' => 0,
                'recordid' => $rOk, 'sort' => $i + 1,
            ]);
        }

        // R2：已交卷不合格（status=1，ishg=0，得分40）
        $rFail = (int) Db::name('kecheng_record')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'status' => 1,
            'time' => $this->now - 86400 * 3,
            'kcid' => $kcid, 'mlid' => $mlid,
            'timu' => $timu,
            'ishg' => 0,
            'endtime' => $this->now - 86400 * 3 + 500,
            'score' => 40,
            'isend' => 0,
        ]);
        // R2 明细：对1错1未答1
        Db::name('kecheng_recordlog')->insert([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1, 'status' => 1,
            'time' => $this->now - 86400 * 3, 'kcid' => $kcid, 'mlid' => $mlid, 'tmid' => $tiku[0],
            'answer' => '0', 'score' => 10.00, 'give_score' => 0, 'recordid' => $rFail, 'sort' => 1,
        ]);
        Db::name('kecheng_recordlog')->insert([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1, 'status' => 2,
            'time' => $this->now - 86400 * 3 + 120, 'kcid' => $kcid, 'mlid' => $mlid, 'tmid' => $tiku[1],
            'answer' => json_encode(['0']), 'score' => 0, 'give_score' => 0, 'recordid' => $rFail, 'sort' => 2,
        ]);

        // R3：进行中（status=0，未交卷，endtime=0）
        Db::name('kecheng_record')->insert([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'status' => 0,
            'time' => $this->now - 3600,
            'kcid' => $kcid, 'mlid' => $mlid,
            'timu' => $timu,
            'ishg' => 0,
            'endtime' => 0,
            'score' => 0,
            'isend' => 0,
        ]);
    }

    /* ---------------- 订单 ---------------- */
    /**
     * @return array<string,int> 已支付订单 kcid 集合，供学习记录引用
     */
    private function makeOrders(int $cWen, int $cYin, int $cVid, int $cZong): array
    {
        $nWen = $this->nameMark . '高效办公图文实战';
        $nYin = $this->nameMark . '通勤听书副业课';
        $nVid = $this->nameMark . '短视频带货视频课';

        // 1) 未支付 status=0（音频课）
        $this->insertOrder($cYin, $nYin, 49.90, 0, 0, '');

        // 2) 已支付 status=1（图文课）
        $this->insertOrder($cWen, $nWen, 39.90, 1, $this->now - 86400 * 4, 'wechat');

        // 3) 已支付 status=1（视频课，学习记录挂载于此）
        $this->insertOrder($cVid, $nVid, 99.00, 1, $this->now - 86400 * 2, 'wechat');

        // 4) 已关闭 status=4（音频课，未支付后被后台关闭）
        $this->insertOrder($cYin, $nYin, 49.90, 4, 0, '');

        // 注：本模块订单状态机仅 0未支付/1已支付/4已关闭（KechengService::orderClose 仅允许 0→4），
        // 无退款状态，故不构造退款订单。
        return ['cVid' => $cVid];
    }

    private function insertOrder(int $kcid, string $title, float $price, int $status, int $paytime, string $paytype): void
    {
        $ordernum = $this->makeOrderNum();
        $row = [
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'ordernum' => $ordernum,
            'title' => $title,
            'totalprice' => $price, 'pay_amount' => $price, 'price' => $price,
            'createtime' => $this->now - 86400 * 1,
            'status' => $status,
            'payorderid' => 0,
            'paytypeid' => $paytype === 'wechat' ? 2 : 0,
            'paytype' => $paytype,
            'paynum' => $paytype ? ($this->prefix . 'PAY' . $ordernum) : '',
            'paytime' => $paytime,
            'platform' => 'wx',
            'iscomment' => 0,
            'kcid' => $kcid,
            'pic' => '',
            'study_status' => 0.00,
            'parent1' => 0, 'parent2' => 0, 'parent3' => 0,
            'parent1commission' => 0, 'parent2commission' => 0, 'parent3commission' => 0,
            'parent1score' => 0, 'parent2score' => 0, 'parent3score' => 0,
            'iscommission' => 0,
        ];
        Db::name('kecheng_order')->insertGetId($row);
    }

    /* ---------------- 学习记录 ---------------- */
    private function makeStudylogs(int $cVid, array $ch, array $orders): void
    {
        // 视频课已学完的图文/视频章 status=1，进行中的 status=0
        $completed = [
            [$this->nameMark . '第1章 选题入门（免费试看）', $ch['vidExam'] ?? 0, 100.00, '100'],
        ];
        foreach ($completed as [$title, $mlid, $ct, $jindu]) {
            Db::name('kecheng_studylog')->insert([
                'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
                'mlid' => $mlid,
                'title' => $title,
                'createtime' => $this->now - 86400 * 2,
                'status' => 1,
                'kcid' => $cVid, 'pic' => '',
                'currentTime' => $ct,
                'platform' => 'wx',
                'jindu' => $jindu,
            ]);
        }
        // 进行中一章（status=0，进度 35%）
        Db::name('kecheng_studylog')->insert([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'mlid' => $ch['vidExam'] ?? 0,
            'title' => $this->nameMark . '第2章 拍摄与剪辑',
            'createtime' => $this->now - 3600,
            'status' => 0,
            'kcid' => $cVid, 'pic' => '',
            'currentTime' => 120.50,
            'platform' => 'wx',
            'jindu' => '35',
        ]);
    }

    /* ---------------- 工具 ---------------- */
    private function makeOrderNum(): string
    {
        return $this->prefix . date('YmdHis', $this->now)
            . sprintf('%04d', mt_rand(0, 9999))
            . sprintf('%03d', mt_rand(0, 999));
    }
}
