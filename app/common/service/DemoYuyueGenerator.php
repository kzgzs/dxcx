<?php
// +----------------------------------------------------------------------
// | 预约服务示例数据生成器：类目+商品+技师+全状态订单+派单+提成+提现+评价
// | 状态覆盖 待支付/已支付待派单/已派单服务中/已完成/已取消/已退款，含上门+到店
// | 幂等：订单号前缀 DEMO-YY-，--purge 仅清本前缀数据；generate() 先 purge 再建
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoYuyueGenerator
{
    /** 订单号/提现单号前缀 */
    private string $prefix = 'DEMO-YY-';
    /** 名称前缀（类目/商品/服务标签/人员类型/技师姓名/提成备注） */
    private string $nameMark = '【演示预约】';
    /** 技师登录账号前缀 */
    private string $workerUn = 'demoyuyue_';
    private int $aid = 1;
    private int $now;

    public function __construct()
    {
        $this->now = time();
    }

    /** 仅删除本生成器数据（按订单号前缀/名称前缀/技师账号前缀），不动任何手工数据 */
    public function purge(): void
    {
        $ordLike = $this->prefix . '%';
        $nameLike = $this->nameMark . '%';

        // 1) 关联订单号的子表（评论/派单/提现）
        Db::name('yuyue_comment')->where('ordernum', 'like', $ordLike)->delete();
        Db::name('yuyue_worker_comment')->where('ordernum', 'like', $ordLike)->delete();
        Db::name('yuyue_worker_order')->where('ordernum', 'like', $ordLike)->delete();
        Db::name('yuyue_worker_withdrawlog')->where('ordernum', 'like', $ordLike)->delete();

        // 2) 订单本体
        Db::name('yuyue_order')->where('ordernum', 'like', $ordLike)->delete();

        // 3) 技师提成流水（按备注前缀 + 演示技师 uid 双保险）
        $workerIds = Db::name('yuyue_worker')->where('un', 'like', $this->workerUn . '%')->column('id');
        Db::name('yuyue_worker_moneylog')->where('remark', 'like', $nameLike)->delete();
        if (!empty($workerIds)) {
            Db::name('yuyue_worker_moneylog')->whereIn('uid', $workerIds)->delete();
        }

        // 4) 技师本体
        Db::name('yuyue_worker')->where('un', 'like', $this->workerUn . '%')->delete();

        // 5) 商品/服务标签/人员类型/项目分类（按名称前缀）
        Db::name('yuyue_product')->where('name', 'like', $nameLike)->delete();
        Db::name('yuyue_fuwu')->where('name', 'like', $nameLike)->delete();
        Db::name('yuyue_worker_category')->where('name', 'like', $nameLike)->delete();
        Db::name('yuyue_category')->where('name', 'like', $nameLike)->delete();
    }

    /** 生成完整演示数据（幂等：先 purge） */
    public function generate(): void
    {
        $this->purge();

        // 类目（主+子）
        $catId = $this->makeCategory();
        // 人员类型
        $workerCid = $this->makeWorkerCategory();
        // 服务人员（技师）
        [$w1, $w2] = $this->makeWorkers($workerCid);
        // 服务商品（到店+上门）
        [$prodA, $prodB] = $this->makeProducts($catId);
        // 商品服务标签
        $this->makeFuwuTags();
        // 订单（全状态）+ 派单记录
        $orders = $this->makeOrders($prodA, $prodB, $w1, $w2);
        // 提成流水（服务完成产生）
        $this->makeMoneylogs($w1, $w2, $orders);
        // 提现记录（审核中/已打款/驳回）
        $this->makeWithdrawlogs($w1, $w2);
        // 评价（商品评价 + 人员评价）
        $this->makeComments($prodA, $prodB, $w1, $orders);
    }

    /* ---------------- 类目 ---------------- */
    private function makeCategory(): int
    {
        $pid = (int) Db::name('yuyue_category')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'pid' => 0,
            'name' => $this->nameMark . '养生推拿',
            'pic' => '', 'sort' => 100, 'status' => 1,
            'createtime' => $this->now - 86400 * 30,
        ]);
        Db::name('yuyue_category')->insert([
            'aid' => $this->aid, 'bid' => 0, 'pid' => $pid,
            'name' => $this->nameMark . '经络调理',
            'pic' => '', 'sort' => 99, 'status' => 1,
            'createtime' => $this->now - 86400 * 30,
        ]);
        return $pid;
    }

    private function makeWorkerCategory(): int
    {
        return (int) Db::name('yuyue_worker_category')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'pid' => 0,
            'name' => $this->nameMark . '金牌技师',
            'pic' => '', 'status' => 1, 'sort' => 99,
            'createtime' => $this->now - 86400 * 30,
        ]);
    }

    /* ---------------- 技师 ---------------- */
    /**
     * @return array{0: int, 1: int} worker id (w1, w2)
     */
    private function makeWorkers(int $workerCid): array
    {
        $w1 = (int) Db::name('yuyue_worker')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 0,
            'un' => $this->workerUn . 'wang',
            'pwd' => md5('123456'),
            'realname' => $this->nameMark . '王技师',
            'tel' => '13900000001',
            'cid' => $workerCid,
            'headimg' => '',
            'jineng' => '推拿|经络',
            'desc' => '从业8年，擅长经络调理，手法专业',
            'status' => 1, 'sort' => 99,
            'money' => 0, 'totalmoney' => 0, 'totalnum' => 0,
            'sex' => 1, 'age' => 32,
            'citys' => '河南省南阳市',
            'createtime' => $this->now - 86400 * 30,
            'shstatus' => 1,
        ]);
        $w2 = (int) Db::name('yuyue_worker')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 0,
            'un' => $this->workerUn . 'li',
            'pwd' => md5('123456'),
            'realname' => $this->nameMark . '李技师',
            'tel' => '13900000002',
            'cid' => $workerCid,
            'headimg' => '',
            'jineng' => '保洁|上门服务',
            'desc' => '上门服务标杆，认真细致',
            'status' => 1, 'sort' => 98,
            'money' => 0, 'totalmoney' => 0, 'totalnum' => 0,
            'sex' => 2, 'age' => 28,
            'citys' => '河南省南阳市',
            'createtime' => $this->now - 86400 * 30,
            'shstatus' => 1,
        ]);
        return [$w1, $w2];
    }

    /* ---------------- 商品 ---------------- */
    /**
     * @return array{0: int, 1: int} product id (A 到店推拿, B 上门保洁)
     */
    private function makeProducts(int $catId): array
    {
        // A：到店推拿（按单固定提成 tcmoney=30）
        $pA = (int) Db::name('yuyue_product')->insertGetId([
            'aid' => $this->aid, 'bid' => 0,
            'name' => $this->nameMark . '全身经络推拿60分钟',
            'sellpoint' => '资深技师专业手法，缓解肩颈疲劳',
            'fuwupoint' => '',
            'fwid' => '',
            'pic' => '', 'pics' => '',
            'sell_price' => 128.00,
            'sales' => 0, 'sort' => 99, 'status' => 1,
            'detail' => '<p>全身经络推拿60分钟</p>',
            'cid' => (string) $catId,
            'danwei' => '次',
            'fwtype' => '1,2',       // 支持到店+上门
            'fwpeople' => 0,
            'fwlong' => 60,          // 时长(分钟)
            'yynum' => 2,            // 每时段容量
            'timejg' => 60,
            'zaohour' => 9, 'wanhour' => 21,
            'jiesuantype' => 0,      // 0按单固定提成
            'tcmoney' => 30.00,
            'tc_bfb' => 0.00,
            'tichengtype' => 0,
            'rqtype' => 1, 'datetype' => 1,
            'yyzhouqi' => '', 'yytimeday' => '', 'timepoint' => '',
            'formdata' => '[]', 'guigedata' => '[]',
            'gettj' => '-1',
            'isareaxz' => 0, 'areadata' => '',
            'is_open' => 1, 'is_delete' => 0,
            'ischecked' => 1, 'check_reason' => '',
            'stock' => 100, 'minbuynum' => 1,
            'comment_score' => 5.0, 'comment_num' => 0, 'comment_haopercent' => 100,
            'createtime' => $this->now - 86400 * 30,
        ]);

        // B：上门深度保洁（按比例提成 tc_bfb=40%）
        $pB = (int) Db::name('yuyue_product')->insertGetId([
            'aid' => $this->aid, 'bid' => 0,
            'name' => $this->nameMark . '居家深度保洁2小时',
            'sellpoint' => '持证保洁员上门，自带工具，深度清洁',
            'fuwupoint' => '',
            'fwid' => '',
            'pic' => '', 'pics' => '',
            'sell_price' => 200.00,
            'sales' => 0, 'sort' => 98, 'status' => 1,
            'detail' => '<p>居家深度保洁2小时，上门服务</p>',
            'cid' => (string) $catId,
            'danwei' => '次',
            'fwtype' => '2',         // 上门
            'fwpeople' => 0,
            'fwlong' => 120,
            'yynum' => 1,
            'timejg' => 60,
            'zaohour' => 9, 'wanhour' => 21,
            'jiesuantype' => 1,      // 1按比例提成
            'tcmoney' => 0.00,
            'tc_bfb' => 40.00,
            'tichengtype' => 0,
            'rqtype' => 1, 'datetype' => 1,
            'yyzhouqi' => '', 'yytimeday' => '', 'timepoint' => '',
            'formdata' => '[]', 'guigedata' => '[]',
            'gettj' => '-1',
            'isareaxz' => 1, 'areadata' => '河南省[南阳市]',
            'is_open' => 1, 'is_delete' => 0,
            'ischecked' => 1, 'check_reason' => '',
            'stock' => 100, 'minbuynum' => 1,
            'comment_score' => 5.0, 'comment_num' => 0, 'comment_haopercent' => 100,
            'createtime' => $this->now - 86400 * 30,
        ]);

        return [$pA, $pB];
    }

    private function makeFuwuTags(): void
    {
        foreach ([$this->nameMark . '免费停车', $this->nameMark . '免费茶水'] as $i => $name) {
            Db::name('yuyue_fuwu')->insert([
                'aid' => $this->aid, 'bid' => 0,
                'name' => $name,
                'desc' => '到店客户专属权益',
                'status' => 1, 'sort' => 100 - $i,
                'createtime' => $this->now - 86400 * 30,
            ]);
        }
    }

    /* ---------------- 订单 ---------------- */
    /**
     * 基础订单行（未含状态/时间差异）
     */
    private function baseOrder(int $proid, string $proname, int $fwtype, string $linkman, string $tel, string $address, string $bookDate, string $bookTime): array
    {
        return [
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'ordernum' => $this->makeOrderNum(),
            'title' => $proname, 'proname' => $proname, 'proid' => $proid,
            'propic' => '', 'ggid' => 0, 'ggname' => '',
            'num' => 1,
            'product_price' => 0, 'totalprice' => 0, 'pay_amount' => 0,
            'coupon_money' => 0, 'score_money' => 0, 'use_score' => 0,
            'coupon_id' => 0, 'coupon_rid' => 0,
            'book_date' => $bookDate, 'book_time' => $bookTime,
            'yy_time' => $bookDate . ' ' . $bookTime,
            'hexiao_code' => $this->makeHexiao(),
            'status' => 0, 'paytime' => 0,
            'paytype' => '', 'paytypeid' => 0, 'paynum' => '',
            'linkman' => $linkman, 'tel' => $tel,
            'area' => '河南省南阳市', 'area2' => '宛城区',
            'address' => $address,
            'platform' => 'wx', 'iscomment' => 0,
            'delete' => 0, 'fwtype' => $fwtype,
            'worker_id' => 0, 'worker_orderid' => 0,
            'createtime' => $this->now - 3600,
        ];
    }

    /**
     * @return array<string, array{id:int,num:string,status:int,worker:int,price:float}> 订单摘要，供提成/评价引用
     */
    private function makeOrders(int $pA, int $pB, int $w1, int $w2): array
    {
        $pronA = $this->nameMark . '全身经络推拿60分钟';
        $pronB = $this->nameMark . '居家深度保洁2小时';
        $summary = [];

        $dFar   = date('Y-m-d', $this->now + 86400 * 6);
        $dFar2  = date('Y-m-d', $this->now + 86400 * 5);
        $dToday = date('Y-m-d', $this->now);
        $dPast3 = date('Y-m-d', $this->now - 86400 * 3);
        $dPast2 = date('Y-m-d', $this->now - 86400 * 2);
        $dFar3  = date('Y-m-d', $this->now + 86400 * 4);
        $dPast1 = date('Y-m-d', $this->now - 86400);

        // 1) 待支付（到店，未派单）
        $o = $this->baseOrder($pA, $pronA, 1, '张三', '13811110001', '到店服务', $dFar, '10:00');
        $o['product_price'] = 128; $o['totalprice'] = 128; $o['pay_amount'] = 128;
        $id = $this->insertOrder($o);
        $summary['unpaid'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 0, 'worker' => 0, 'price' => 128];

        // 2) 已支付待派单（到店，无技师无派单）
        //    paytime 控制在自动退款阈值(默认60分钟)内，避免被超时退款任务翻成 status=5
        $o = $this->baseOrder($pA, $pronA, 1, '李四', '13811110002', '到店服务', $dFar2, '14:00');
        $o['product_price'] = 128; $o['totalprice'] = 128; $o['pay_amount'] = 128;
        $o['status'] = 1; $o['paytime'] = $this->now - 1200;
        $o['paytype'] = 'wechat'; $o['paytypeid'] = 2; $o['paynum'] = $this->prefix . 'PAY' . $id . mt_rand(1000, 9999);
        $id = $this->insertOrder($o);
        $summary['paid_wait'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 1, 'worker' => 0, 'price' => 128];

        // 3) 已派单（上门，服务中：wo.status=1，已开始已到店未结束）
        //    paytime 30 分钟内（未超时），避免被自动退款任务退款
        $o = $this->baseOrder($pB, $pronB, 2, '王五', '13811110003', '宛城区人民大道1号锦绣花园3栋502', $dToday, '12:00');
        $o['product_price'] = 200; $o['totalprice'] = 200; $o['pay_amount'] = 200;
        $o['status'] = 1; $o['paytime'] = $this->now - 1800;
        $o['paytype'] = 'wechat'; $o['paytypeid'] = 2; $o['paynum'] = $this->prefix . 'PAY' . $id . mt_rand(1000, 9999);
        $o['worker_id'] = $w1;
        $woId = $this->insertWorkerOrder($w1, $o, 1, $this->now - 1200, $this->now - 900, 0, 0);
        $o['worker_orderid'] = $woId;
        $id = $this->insertOrder($o);
        // 回写派单表的 orderid
        Db::name('yuyue_worker_order')->where('id', $woId)->update(['orderid' => $id]);
        $summary['serving'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 1, 'worker' => $w1, 'price' => 200];

        // 4) 已完成（到店，w1，固定提成30）
        $o = $this->baseOrder($pA, $pronA, 1, '赵六', '13811110004', '到店服务', $dPast3, '15:00');
        $o['product_price'] = 128; $o['totalprice'] = 128; $o['pay_amount'] = 128;
        $o['status'] = 2; $o['paytime'] = $this->now - 86400 * 3;
        $o['paytype'] = 'wechat'; $o['paytypeid'] = 2; $o['paynum'] = $this->prefix . 'PAY' . $id . mt_rand(1000, 9999);
        $o['collect_time'] = $this->now - 86400 * 3 + 3600;
        $o['worker_id'] = $w1;
        $woId = $this->insertWorkerOrder($w1, $o, 2, $this->now - 86400 * 3, $this->now - 86400 * 3, $this->now - 86400 * 3 + 3600, 30.00);
        $o['worker_orderid'] = $woId;
        $id = $this->insertOrder($o);
        Db::name('yuyue_worker_order')->where('id', $woId)->update(['orderid' => $id]);
        $summary['done_a'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 2, 'worker' => $w1, 'price' => 128];

        // 5) 已完成（上门，w2，比例提成 200*40%=80）
        $o = $this->baseOrder($pB, $pronB, 2, '孙七', '13811110005', '卧龙区文化路8号水岸华庭2栋1101', $dPast2, '09:00');
        $o['product_price'] = 200; $o['totalprice'] = 200; $o['pay_amount'] = 200;
        $o['status'] = 2; $o['paytime'] = $this->now - 86400 * 2;
        $o['paytype'] = 'wechat'; $o['paytypeid'] = 2; $o['paynum'] = $this->prefix . 'PAY' . $id . mt_rand(1000, 9999);
        $o['collect_time'] = $this->now - 86400 * 2 + 7200;
        $o['worker_id'] = $w2;
        $woId = $this->insertWorkerOrder($w2, $o, 2, $this->now - 86400 * 2, $this->now - 86400 * 2, $this->now - 86400 * 2 + 7200, 80.00);
        $o['worker_orderid'] = $woId;
        $id = $this->insertOrder($o);
        Db::name('yuyue_worker_order')->where('id', $woId)->update(['orderid' => $id]);
        $summary['done_b'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 2, 'worker' => $w2, 'price' => 200];

        // 6) 已取消（到店，未支付取消）
        $o = $this->baseOrder($pA, $pronA, 1, '周八', '13811110006', '到店服务', $dFar3, '11:00');
        $o['product_price'] = 128; $o['totalprice'] = 128; $o['pay_amount'] = 128;
        $o['status'] = 4;
        $id = $this->insertOrder($o);
        $summary['cancelled'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 4, 'worker' => 0, 'price' => 128];

        // 7) 已退款（上门，已支付后退款）
        $o = $this->baseOrder($pB, $pronB, 2, '吴九', '13811110007', '宛城区新华东路12号', $dPast1, '16:00');
        $o['product_price'] = 200; $o['totalprice'] = 200; $o['pay_amount'] = 200;
        $o['status'] = 5; $o['paytime'] = $this->now - 86400;
        $o['paytype'] = 'wechat'; $o['paytypeid'] = 2; $o['paynum'] = $this->prefix . 'PAY' . $id . mt_rand(1000, 9999);
        $o['refund_status'] = 2; $o['refund_money'] = 200;
        $o['refund_reason'] = '用户临时有事，申请退款'; $o['refund_time'] = $this->now - 3600;
        $id = $this->insertOrder($o);
        $summary['refunded'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 5, 'worker' => 0, 'price' => 200];

        return $summary;
    }

    private function insertOrder(array $row): int
    {
        return (int) Db::name('yuyue_order')->insertGetId($row);
    }

    /**
     * 写派单记录
     *
     * @param int $status 1=服务中 2=已完成
     */
    private function insertWorkerOrder(int $workerId, array $orderRow, int $status, int $start, int $daodian, int $end, float $ticheng): int
    {
        return (int) Db::name('yuyue_worker_order')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'worker_id' => $workerId,
            'orderid' => 0, // 先占位，订单插入后回写
            'ordernum' => $orderRow['ordernum'],
            'createtime' => $orderRow['paytime'] > 0 ? $orderRow['paytime'] : $this->now - 3600,
            'starttime' => $start,
            'daodiantime' => $daodian,
            'quhuotime' => 0,
            'endtime' => $end,
            'status' => $status,
            'ticheng' => $ticheng,
            'psfee' => 0,
            'iscomment' => 0,
            'fwtype' => $orderRow['fwtype'],
            'sign_status' => 0, 'sign_time' => 0,
        ]);
    }

    /* ---------------- 提成流水 ---------------- */
    /**
     * @param array<string, array{id:int,num:string,status:int,worker:int,price:float}> $orders
     */
    private function makeMoneylogs(int $w1, int $w2, array $orders): void
    {
        // w1 完成订单4（推拿，固定提成30）
        $remark1 = $this->nameMark . '订单' . $orders['done_a']['num'] . '完成提成';
        Db::name('yuyue_worker_moneylog')->insert([
            'aid' => $this->aid, 'bid' => 0, 'uid' => $w1,
            'money' => 30.00, 'after' => 30.00,
            'createtime' => $orders['done_a']['price'] > 0 ? ($this->now - 86400 * 3 + 3600) : $this->now,
            'remark' => $remark1,
        ]);
        Db::name('yuyue_worker')->where('id', $w1)->update(['money' => 30.00, 'totalmoney' => 30.00, 'totalnum' => 1]);

        // w2 完成订单5（保洁，比例提成 80）
        Db::name('yuyue_worker_moneylog')->insert([
            'aid' => $this->aid, 'bid' => 0, 'uid' => $w2,
            'money' => 80.00, 'after' => 80.00,
            'createtime' => $this->now - 86400 * 2 + 7200,
            'remark' => $this->nameMark . '订单' . $orders['done_b']['num'] . '完成提成',
        ]);
        Db::name('yuyue_worker')->where('id', $w2)->update(['money' => 80.00, 'totalmoney' => 80.00, 'totalnum' => 1]);
    }

    /* ---------------- 提现 ---------------- */
    private function makeWithdrawlogs(int $w1, int $w2): void
    {
        // w1：审核中
        Db::name('yuyue_worker_withdrawlog')->insert([
            'aid' => $this->aid, 'bid' => 0, 'uid' => $w1,
            'money' => 20.00, 'txmoney' => 20.00,
            'ordernum' => $this->prefix . 'TX' . date('YmdHis', $this->now - 86400) . '01',
            'paytype' => 'bank', 'status' => 0,
            'bankname' => '中国工商银行', 'bankcarduser' => '王技师', 'bankcardnum' => '6222021234567890123',
            'createtime' => $this->now - 86400,
            'platform' => 'wx',
        ]);

        // w2：已打款
        Db::name('yuyue_worker_withdrawlog')->insert([
            'aid' => $this->aid, 'bid' => 0, 'uid' => $w2,
            'money' => 50.00, 'txmoney' => 50.00,
            'ordernum' => $this->prefix . 'TX' . date('YmdHis', $this->now - 86400 * 2) . '02',
            'paytype' => 'bank', 'status' => 3,
            'bankname' => '中国建设银行', 'bankcarduser' => '李技师', 'bankcardnum' => '6227001234567890456',
            'createtime' => $this->now - 86400 * 2,
            'paytime' => $this->now - 86400,
            'paynum' => 'YWTX' . date('YmdHis', $this->now - 86400),
            'platform' => 'wx',
        ]);

        // w1：已驳回
        Db::name('yuyue_worker_withdrawlog')->insert([
            'aid' => $this->aid, 'bid' => 0, 'uid' => $w1,
            'money' => 10.00, 'txmoney' => 10.00,
            'ordernum' => $this->prefix . 'TX' . date('YmdHis', $this->now - 86400 * 3) . '03',
            'paytype' => 'bank', 'status' => 2,
            'bankname' => '中国工商银行', 'bankcarduser' => '王技师', 'bankcardnum' => '6222021234567890123',
            'createtime' => $this->now - 86400 * 3,
            'reason' => '银行卡信息有误，请核对后重新提交',
            'platform' => 'wx',
        ]);
    }

    /* ---------------- 评价 ---------------- */
    /**
     * @param array<string, array{id:int,num:string,status:int,worker:int,price:float}> $orders
     */
    private function makeComments(int $pA, int $pB, int $w1, array $orders): void
    {
        // 商品评价 2 条（分别挂在 A/B 商品已完成订单上）
        Db::name('yuyue_comment')->insert([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'orderid' => $orders['done_a']['id'], 'proid' => $pA,
            'proname' => $this->nameMark . '全身经络推拿60分钟', 'propic' => '',
            'ordernum' => $orders['done_a']['num'],
            'nickname' => '赵六', 'headimg' => '',
            'score' => 5, 'content' => '技师手法很专业，肩颈轻松多了，环境也不错，会再来。',
            'content_pic' => '',
            'reply_content' => '感谢您的认可，期待下次光临！',
            'reply_time' => $this->now - 86400 * 2,
            'status' => 1,
            'createtime' => $this->now - 86400 * 2,
            'appendtime' => 0,
        ]);
        Db::name('yuyue_comment')->insert([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'orderid' => $orders['done_b']['id'], 'proid' => $pB,
            'proname' => $this->nameMark . '居家深度保洁2小时', 'propic' => '',
            'ordernum' => $orders['done_b']['num'],
            'nickname' => '孙七', 'headimg' => '',
            'score' => 5, 'content' => '上门很准时，打扫得非常干净，自带工具，推荐。',
            'content_pic' => '',
            'reply_content' => '',
            'reply_time' => 0,
            'status' => 1,
            'createtime' => $this->now - 86400,
            'appendtime' => 0,
        ]);

        // 人员评价 1 条（挂 w1）
        Db::name('yuyue_worker_comment')->insert([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'worker_id' => $w1,
            'orderid' => $orders['done_a']['id'],
            'ordernum' => $orders['done_a']['num'],
            'nickname' => '赵六', 'headimg' => '',
            'score' => 5, 'content' => '王技师服务态度好，手法到位，强烈推荐。',
            'content_pic' => '',
            'reply_content' => '谢谢认可！',
            'reply_time' => $this->now - 86400 * 2,
            'status' => 1,
            'createtime' => $this->now - 86400 * 2,
        ]);

        // 回写商品评分统计（对齐 commentSetStatus 口径）
        foreach ([$pA, $pB] as $proid) {
            $cq = Db::name('yuyue_comment')->where('proid', $proid)->where('status', 1);
            $num = (clone $cq)->count();
            $avg = $num > 0 ? (clone $cq)->avg('score') : 5;
            $hao = (clone $cq)->where('score', '>', 3)->count();
            Db::name('yuyue_product')->where('id', $proid)->update([
                'comment_score' => round((float) $avg, 1),
                'comment_num' => $num,
                'comment_haopercent' => $num > 0 ? (int) round($hao / $num * 100) : 100,
            ]);
        }
    }

    /* ---------------- 工具 ---------------- */
    private function makeOrderNum(): string
    {
        // 纯数字后缀（Windows 下 microtime 转字符串可能带小数点，这里用定长随机数避免脏字符）
        return $this->prefix . date('YmdHis', $this->now)
            . sprintf('%04d', mt_rand(0, 9999))
            . sprintf('%03d', mt_rand(0, 999));
    }

    private function makeHexiao(): string
    {
        return 'DYY' . sprintf('%08d', mt_rand(10000000, 99999999)) . sprintf('%03d', mt_rand(100, 999));
    }
}
