<?php
// +----------------------------------------------------------------------
// | 同城配送示例数据生成器：配送员(三态)+配送单(0待接单/1已接单/2已到店/3已取货/4已完成/10已取消)
// | +配送评价+提成流水+提现记录(0审核中/1已审核/2已驳回/3已打款)
// | 幂等：配送单/提现单号前缀 DEMO-PS-，配送员姓名/流水备注前缀【演示同城配送】，
// |       配送员微信号账号前缀 demops_；purge 仅按前缀删本生成器数据，不碰手工数据。
// | 状态机以 Peisong 控制器常量为准：
// |   配送单 0待接单/1已接单/2已到店/3已取货/4已完成/10已取消；
// |   提现   0审核中/1已审核/2已驳回/3已打款。
// | 说明：peisong_user 表无独立登录账号列(登录经 member.mid)，故无 un 可加前缀；
// |       配送员的幂等标识 = realname【演示同城配送】前缀(微信号另置 demops_* 账号痕迹)。
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoPeisongGenerator
{
    /** 配送单号/提现单号前缀 */
    private string $prefix = 'DEMO-PS-';
    /** 名称前缀（配送员姓名/提成备注/评价内容标识） */
    private string $nameMark = '【演示同城配送】';
    /** 配送员微信号(账号痕迹)前缀 */
    private string $workerUn = 'demops_';
    private int $aid = 1;
    private int $now;

    public function __construct()
    {
        $this->now = time();
    }

    /** 指定平台租户 ID（默认 1） */
    public function setAid(int $aid): void
    {
        $this->aid = $aid;
    }

    /** 仅删除本生成器数据（按单号前缀/姓名前缀/备注前缀），不动任何手工数据 */
    public function purge(): void
    {
        $ordLike  = $this->prefix . '%';
        $nameLike = $this->nameMark . '%';

        // 演示配送员 id（按姓名前缀圈定，提成流水双保险用）
        $workerIds = Db::name('peisong_user')->where('realname', 'like', $nameLike)->column('id');

        // 1) 配送评价（按演示单号）
        Db::name('peisong_order_comment')->where('ordernum', 'like', $ordLike)->delete();

        // 2) 配送单本体（按单号前缀精确删，覆盖历史残留）
        Db::name('peisong_order')->where('ordernum', 'like', $ordLike)->delete();

        // 3) 提现记录（按单号前缀）
        Db::name('peisong_withdrawlog')->where('ordernum', 'like', $ordLike)->delete();

        // 4) 提成流水（按备注前缀 + 演示配送员 uid 双保险）
        Db::name('peisong_moneylog')->where('remark', 'like', $nameLike)->delete();
        if (!empty($workerIds)) {
            Db::name('peisong_moneylog')->whereIn('uid', $workerIds)->delete();
        }

        // 5) 配送员本体（按姓名前缀）
        Db::name('peisong_user')->where('realname', 'like', $nameLike)->delete();

        // 6) 快递鸟即时配送记录（按单号前缀精确删，不碰手工数据）
        Db::name('express_order')->where('ordernum', 'like', $ordLike)->delete();
    }

    /** 生成完整演示数据（幂等：先 purge） */
    public function generate(): void
    {
        $this->purge();

        // 配送员（启用/启用/停用 三态）
        [$w1, $w2] = $this->makeWorkers();
        // 配送单（覆盖 0/1/2/3/4/10 全状态）
        $orders = $this->makeOrders($w1, $w2);
        // 提成流水（完成订单产生）
        $this->makeMoneylogs($w1, $w2, $orders);
        // 提现记录（0审核中/1已审核/2已驳回/3已打款）
        $this->makeWithdrawlogs($w1, $w2);
        // 配送评价（已完成订单）
        $this->makeComments($w1, $w2, $orders);
        // 快递鸟即时配送记录（express_order，覆盖待接单/已接单/配送中/已完成/已取消）
        $this->makeExpressOrders();
    }

    /* ---------------- 配送员 ---------------- */
    /**
     * @return array{0:int,1:int} 配送员 id (w1 启用, w2 启用；另含1名停用)
     */
    private function makeWorkers(): array
    {
        // w1 启用，银行卡收款
        $w1 = (int) Db::name('peisong_user')->insertGetId([
            'aid' => $this->aid, 'mid' => 0,
            'realname' => $this->nameMark . '王快送',
            'tel' => '13700000001',
            'status' => 1, 'sort' => 99,
            'comment_score' => 5.0, 'comment_num' => 0, 'comment_haopercent' => 100,
            'money' => 0, 'totalmoney' => 0, 'totalnum' => 0,
            'weixin' => $this->workerUn . 'wang',
            'aliaccount' => '',
            'bankname' => '中国工商银行', 'bankcarduser' => '王快送', 'bankcardnum' => '6222021234567891001',
            'longitude' => '112.5283', 'latitude' => '33.0052',
            'createtime' => $this->now - 86400 * 30,
        ]);
        // w2 启用，微信钱包收款
        $w2 = (int) Db::name('peisong_user')->insertGetId([
            'aid' => $this->aid, 'mid' => 0,
            'realname' => $this->nameMark . '李快送',
            'tel' => '13700000002',
            'status' => 1, 'sort' => 98,
            'comment_score' => 5.0, 'comment_num' => 0, 'comment_haopercent' => 100,
            'money' => 0, 'totalmoney' => 0, 'totalnum' => 0,
            'weixin' => $this->workerUn . 'li',
            'aliaccount' => '',
            'bankname' => '', 'bankcarduser' => '', 'bankcardnum' => '',
            'longitude' => '112.5310', 'latitude' => '33.0110',
            'createtime' => $this->now - 86400 * 28,
        ]);
        // w3 停用（status=0，暂休），不接完成单
        Db::name('peisong_user')->insert([
            'aid' => $this->aid, 'mid' => 0,
            'realname' => $this->nameMark . '赵暂休',
            'tel' => '13700000003',
            'status' => 0, 'sort' => 1,
            'comment_score' => 5.0, 'comment_num' => 0, 'comment_haopercent' => 100,
            'money' => 0, 'totalmoney' => 0, 'totalnum' => 0,
            'weixin' => $this->workerUn . 'zhao',
            'aliaccount' => '',
            'bankname' => '', 'bankcarduser' => '', 'bankcardnum' => '',
            'longitude' => '', 'latitude' => '',
            'createtime' => $this->now - 86400 * 20,
        ]);
        return [$w1, $w2];
    }

    /* ---------------- 配送单 ---------------- */
    /**
     * 基础配送单行（跑腿单 paotui_order）
     */
    private function baseOrder(int $psid, string $linkman, string $tel, string $recvAddr): array
    {
        return [
            'aid' => $this->aid, 'bid' => 0, 'mdid' => null, 'mid' => 1,
            'psid' => $psid, 'orderid' => 0,
            'ordernum' => $this->makeOrderNum(),
            'createtime' => $this->now - 1800,
            'starttime' => 0, 'daodiantime' => 0, 'quhuotime' => 0, 'endtime' => 0,
            'status' => 0,
            'type' => 'paotui_order',
            'ticheng' => 0.00, 'psfee' => 0.00,
            'iscomment' => 0,
            'yujitime' => $this->now + 3600,
            'juli' => 2,
            // 发货地(商家)：南阳某商圈
            'longitude' => '112.5283', 'latitude' => '33.0052',
            // 收货地(用户)
            'longitude2' => '112.5401', 'latitude2' => '33.0188',
            'orderinfo' => json_encode([
                'goods' => '文件资料袋', 'weight' => '1kg', 'note' => '请尽快取件，易碎轻放',
            ], JSON_UNESCAPED_UNICODE),
            'prolist' => json_encode([
                ['name' => '文件袋', 'num' => 1, 'price' => 0],
            ], JSON_UNESCAPED_UNICODE),
            'binfo' => json_encode([
                'name' => $linkman, 'tel' => $tel, 'address' => $recvAddr,
            ], JSON_UNESCAPED_UNICODE),
            'make_ordernum' => '', 'make_rider_name' => '', 'make_rider_mobile' => '',
        ];
    }

    /**
     * @return array<string, array{id:int,num:string,status:int,worker:int,ticheng:float}> 订单摘要
     */
    private function makeOrders(int $w1, int $w2): array
    {
        $summary = [];

        // 1) status=0 待接单（未派单，抢单池 psid=0）
        $o = $this->baseOrder(0, '张三', '13811110001', '宛城区人民大道1号锦绣花园3栋502');
        $o['createtime'] = $this->now - 1800;
        $id = (int) Db::name('peisong_order')->insertGetId($o);
        $summary['waiting'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 0, 'worker' => 0, 'ticheng' => 0];

        // 2) status=1 已接单（w1 已接单，未到店）
        $o = $this->baseOrder($w1, '李四', '13811110002', '卧龙区文化路8号水岸华庭2栋1101');
        $o['createtime'] = $this->now - 2400;
        $o['status'] = 1;
        $o['starttime'] = $this->now - 2000;
        $o['ticheng'] = 5.00; $o['psfee'] = 5.00;
        $id = (int) Db::name('peisong_order')->insertGetId($o);
        $summary['accepted'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 1, 'worker' => $w1, 'ticheng' => 5];

        // 3) status=2 已到店（w1 已到店等取货）
        $o = $this->baseOrder($w1, '王五', '13811110003', '宛城区新华东路12号国贸中心');
        $o['createtime'] = $this->now - 3600;
        $o['status'] = 2;
        $o['starttime'] = $this->now - 3200;
        $o['daodiantime'] = $this->now - 2400;
        $o['ticheng'] = 6.00; $o['psfee'] = 6.00;
        $id = (int) Db::name('peisong_order')->insertGetId($o);
        $summary['arrived'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 2, 'worker' => $w1, 'ticheng' => 6];

        // 4) status=3 已取货（w2 已取货，配送途中）
        $o = $this->baseOrder($w2, '赵六', '13811110004', '卧龙区车站路6号万达华府1栋302');
        $o['createtime'] = $this->now - 5400;
        $o['status'] = 3;
        $o['starttime'] = $this->now - 5000;
        $o['daodiantime'] = $this->now - 4200;
        $o['quhuotime'] = $this->now - 3000;
        $o['ticheng'] = 4.00; $o['psfee'] = 4.00;
        $id = (int) Db::name('peisong_order')->insertGetId($o);
        $summary['picked'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 3, 'worker' => $w2, 'ticheng' => 4];

        // 5) status=4 已完成（w1，提成6，挂评价）
        $o = $this->baseOrder($w1, '孙七', '13811110005', '宛仲景路9号府院1期7栋1501');
        $o['createtime'] = $this->now - 86400 * 2;
        $o['status'] = 4;
        $o['starttime'] = $this->now - 86400 * 2 + 600;
        $o['daodiantime'] = $this->now - 86400 * 2 + 1200;
        $o['quhuotime'] = $this->now - 86400 * 2 + 1800;
        $o['endtime'] = $this->now - 86400 * 2 + 3600;
        $o['ticheng'] = 6.00; $o['psfee'] = 6.00;
        $o['iscomment'] = 1;
        $id = (int) Db::name('peisong_order')->insertGetId($o);
        $summary['done_a'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 4, 'worker' => $w1, 'ticheng' => 6];

        // 6) status=4 已完成（w2，提成4，挂评价）
        $o = $this->baseOrder($w2, '周八', '13811110006', '卧龙区人民南路20号建业壹号城邦');
        $o['createtime'] = $this->now - 86400;
        $o['status'] = 4;
        $o['starttime'] = $this->now - 86400 + 600;
        $o['daodiantime'] = $this->now - 86400 + 1200;
        $o['quhuotime'] = $this->now - 86400 + 1800;
        $o['endtime'] = $this->now - 86400 + 3600;
        $o['ticheng'] = 4.00; $o['psfee'] = 4.00;
        $o['iscomment'] = 1;
        $id = (int) Db::name('peisong_order')->insertGetId($o);
        $summary['done_b'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 4, 'worker' => $w2, 'ticheng' => 4];

        // 7) status=10 已取消（未派单取消）
        $o = $this->baseOrder(0, '吴九', '13811110007', '宛城区光武路33号');
        $o['createtime'] = $this->now - 86400;
        $o['status'] = 10;
        $id = (int) Db::name('peisong_order')->insertGetId($o);
        $summary['cancelled'] = ['id' => $id, 'num' => $o['ordernum'], 'status' => 10, 'worker' => 0, 'ticheng' => 0];

        return $summary;
    }

    /* ---------------- 提成流水 ---------------- */
    /**
     * @param array<string, array{id:int,num:string,status:int,worker:int,ticheng:float}> $orders
     */
    private function makeMoneylogs(int $w1, int $w2, array $orders): void
    {
        // w1 完成订单 done_a，提成 6.00
        Db::name('peisong_moneylog')->insert([
            'aid' => $this->aid, 'uid' => $w1,
            'money' => 6.00, 'after' => 6.00,
            'createtime' => $this->now - 86400 * 2 + 3600,
            'remark' => $this->nameMark . '订单' . $orders['done_a']['num'] . '完成提成',
        ]);
        Db::name('peisong_user')->where('id', $w1)->update([
            'money' => 6.00, 'totalmoney' => 6.00, 'totalnum' => 1,
        ]);

        // w2 完成订单 done_b，提成 4.00
        Db::name('peisong_moneylog')->insert([
            'aid' => $this->aid, 'uid' => $w2,
            'money' => 4.00, 'after' => 4.00,
            'createtime' => $this->now - 86400 + 3600,
            'remark' => $this->nameMark . '订单' . $orders['done_b']['num'] . '完成提成',
        ]);
        Db::name('peisong_user')->where('id', $w2)->update([
            'money' => 4.00, 'totalmoney' => 4.00, 'totalnum' => 1,
        ]);
    }

    /* ---------------- 提现 ---------------- */
    private function makeWithdrawlogs(int $w1, int $w2): void
    {
        $seq = 0;
        $txNum = function (int $daysAgo) use (&$seq): string {
            $seq++;
            return $this->prefix . 'TX' . date('YmdHis', $this->now - 86400 * $daysAgo) . sprintf('%02d', $seq);
        };

        // w1：status=0 审核中（银行卡）
        Db::name('peisong_withdrawlog')->insert([
            'aid' => $this->aid, 'uid' => $w1,
            'money' => 2.00, 'txmoney' => 2.00,
            'weixin' => '', 'aliaccount' => '',
            'ordernum' => $txNum(1),
            'paytype' => '银行卡', 'status' => 0,
            'bankname' => '中国工商银行', 'bankcarduser' => '王快送', 'bankcardnum' => '6222021234567891001',
            'createtime' => $this->now - 86400,
            'paytime' => 0, 'paynum' => '', 'platform' => 'wx', 'reason' => '',
        ]);

        // w1：status=2 已驳回（银行卡，余额返还）
        Db::name('peisong_withdrawlog')->insert([
            'aid' => $this->aid, 'uid' => $w1,
            'money' => 1.00, 'txmoney' => 1.00,
            'weixin' => '', 'aliaccount' => '',
            'ordernum' => $txNum(3),
            'paytype' => '银行卡', 'status' => 2,
            'bankname' => '中国工商银行', 'bankcarduser' => '王快送', 'bankcardnum' => '6222021234567891001',
            'createtime' => $this->now - 86400 * 3,
            'paytime' => 0, 'paynum' => '', 'platform' => 'wx',
            'reason' => '银行卡户名与配送员姓名不一致，请核对后重新提交',
        ]);

        // w2：status=1 已审核（微信钱包，待打款）
        Db::name('peisong_withdrawlog')->insert([
            'aid' => $this->aid, 'uid' => $w2,
            'money' => 3.00, 'txmoney' => 3.00,
            'weixin' => $this->workerUn . 'li', 'aliaccount' => '',
            'ordernum' => $txNum(2),
            'paytype' => '微信钱包', 'status' => 1,
            'bankname' => '', 'bankcarduser' => '', 'bankcardnum' => '',
            'createtime' => $this->now - 86400 * 2,
            'paytime' => 0, 'paynum' => '', 'platform' => 'wx', 'reason' => '',
        ]);

        // w2：status=3 已打款（微信钱包，mock 打款成功）
        Db::name('peisong_withdrawlog')->insert([
            'aid' => $this->aid, 'uid' => $w2,
            'money' => 1.00, 'txmoney' => 1.00,
            'weixin' => $this->workerUn . 'li', 'aliaccount' => '',
            'ordernum' => $txNum(4),
            'paytype' => '微信钱包', 'status' => 3,
            'bankname' => '', 'bankcarduser' => '', 'bankcardnum' => '',
            'createtime' => $this->now - 86400 * 4,
            'paytime' => $this->now - 86400 * 3,
            'paynum' => 'MOCK' . date('YmdHis', $this->now - 86400 * 3) . '0001',
            'platform' => 'wx', 'reason' => '', 'wx_state' => 'SUCCESS',
        ]);
    }

    /* ---------------- 评价 ---------------- */
    /**
     * @param array<string, array{id:int,num:string,status:int,worker:int,ticheng:float}> $orders
     */
    private function makeComments(int $w1, int $w2, array $orders): void
    {
        // 订单 done_a（w1）评价，带商家回复
        Db::name('peisong_order_comment')->insert([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'psid' => $w1, 'orderid' => $orders['done_a']['id'],
            'ordernum' => $orders['done_a']['num'],
            'openid' => '', 'nickname' => '孙七', 'headimg' => '',
            'score' => 5,
            'content' => '配送很准时，电话沟通顺畅，文件包装完好，王师傅态度好。',
            'content_pic' => '',
            'reply_content' => '感谢好评，我们会继续保持！',
            'reply_content_pic' => '',
            'status' => 1,
            'createtime' => $this->now - 86400 * 2,
            'reply_time' => $this->now - 86400 * 2 + 3600,
        ]);

        // 订单 done_b（w2）评价，未回复
        Db::name('peisong_order_comment')->insert([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 1,
            'psid' => $w2, 'orderid' => $orders['done_b']['id'],
            'ordernum' => $orders['done_b']['num'],
            'openid' => '', 'nickname' => '周八', 'headimg' => '',
            'score' => 4,
            'content' => '整体不错，稍慢了一点，东西没磕碰。',
            'content_pic' => '',
            'reply_content' => '', 'reply_content_pic' => '',
            'status' => 1,
            'createtime' => $this->now - 86400,
            'reply_time' => 0,
        ]);

        // 回写配送员评分统计（对齐列表展示口径）
        foreach ([$w1 => 5.0, $w2 => 4.0] as $uid => $avg) {
            $cq = Db::name('peisong_order_comment')->where('psid', $uid)->where('status', 1);
            $num = (clone $cq)->count();
            $avgScore = $num > 0 ? (clone $cq)->avg('score') : $avg;
            $hao = (clone $cq)->where('score', '>', 3)->count();
            Db::name('peisong_user')->where('id', $uid)->update([
                'comment_score' => round((float) $avgScore, 1),
                'comment_num' => $num,
                'comment_haopercent' => $num > 0 ? (int) round($hao / $num * 100) : 100,
            ]);
        }
    }

    /* ---------------- 快递鸟即时配送记录 ---------------- */
    /**
     * 生成快递鸟即时配送演示记录（express_order）
     * 覆盖 0待接单/1已接单/2配送中/3已完成/10已取消 全状态；
     * 订单号统一带 DEMO-PS- 前缀，purge 按前缀精确删除，幂等不翻倍。
     */
    private function makeExpressOrders(): void
    {
        $base = [
            'aid'               => $this->aid,
            'mid'               => '1',
            'company'           => 'KDN',
            'recManName'        => '菲菲',
            'recManMobile'      => '17854553149',
            'recManPrintAddr'   => '山东省临沂市兰山区大官苑社区6号楼二单元801门口（放门口就行）',
            'recManPrintPro'    => '山东省',
            'recManPrintCity'   => '临沂市',
            'sendManName'       => '测试门店',
            'sendManMobile'     => '17811111111',
            'sendManPrintAddr'  => 'IEC国际企业中心',
            'sendManPrintPro'   => '山东省',
            'sendManPrintCity'  => '临沂市',
            'orderId'           => '0',
            'platform'          => 'manual',
            'salt'              => '',
            'courierName'       => '',
            'courierMobile'     => '',
            'remark'            => 0,
        ];

        // [状态, 商品, 重量, 多少天前创建, 是否有推单时间]
        $cases = [
            [10, '芒果（1000g）', '1.1', 2.5, true],
            [1,  '鲜活水果礼盒',   '1.0', 1.2, true],
            [2,  '生鲜冷链包裹',   '1.5', 0.6, true],
            [3,  '文件资料袋',     '0.5', 4.0, true],
            [0,  '鲜花绿植一束',   '1.0', 0.1, false],
        ];

        $seq = 0;
        foreach ($cases as [$status, $cargo, $weight, $daysAgo, $pushed]) {
            $seq++;
            $ct = (int) round($this->now - 86400 * $daysAgo);
            $row = $base;
            $row['ordernum']    = $this->prefix . 'EXP' . date('YmdHis', $ct) . sprintf('%02d', $seq);
            $row['kuaidinum']   = 'KDN' . date('Ymd', $ct) . sprintf('%010d', 2400 + $seq);
            $row['taskId']      = $row['kuaidinum'];
            $row['sm_time']     = $pushed ? date('Y-m-d H:i:s', $ct + 60) : '';
            $row['cargo']       = $cargo;
            $row['weight']      = $weight;
            $row['status']      = $status;
            $row['createtime']  = $ct;
            Db::name('express_order')->insert($row);
        }
    }

    /* ---------------- 工具 ---------------- */
    private function makeOrderNum(): string
    {
        return $this->prefix . date('YmdHis', $this->now)
            . sprintf('%04d', mt_rand(0, 9999))
            . sprintf('%03d', mt_rand(0, 999));
    }
}
