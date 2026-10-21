<?php
declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 餐饮演示数据生成器：覆盖餐饮全部菜单页
 * 区域/餐桌分类/餐桌/菜品分类/菜品/规格/堂食订单/外卖订单/排队/预定/寄存/优惠券/促销/配送方式/备注
 * 前缀 DEMO-RT-，幂等，--purge 清除
 */
class DemoRestaurantGenerator
{
    protected int $aid = 1;
    public const PREFIX = 'DEMO-RT-';

    public function setAid(int $aid): void { $this->aid = $aid > 0 ? $aid : 1; }

    public function purge(): void
    {
        $aid = $this->aid;
        // 菜品规格
        $prodIds = Db::name('restaurant_product')->where('aid', $aid)->whereLike('name', self::PREFIX . '%')->column('id');
        if ($prodIds) {
            Db::name('restaurant_product_guige')->where('aid', $aid)->whereIn('product_id', $prodIds)->delete();
        }
        // 订单商品
        foreach (['restaurant_shop_order_goods','restaurant_takeaway_order_goods'] as $og) {
            $ordIds = Db::name(str_replace('_goods','',$og))->where('aid',$aid)->whereLike('ordernum', self::PREFIX . '%')->column('id');
            if ($ordIds) Db::name($og)->where('aid',$aid)->whereIn('orderid', $ordIds)->delete();
        }
        // 订单
        Db::name('restaurant_shop_order')->where('aid',$aid)->whereLike('ordernum', self::PREFIX . '%')->delete();
        Db::name('restaurant_takeaway_order')->where('aid',$aid)->whereLike('ordernum', self::PREFIX . '%')->delete();
        Db::name('restaurant_booking_order')->where('aid',$aid)->whereLike('ordernum', self::PREFIX . '%')->delete();
        // 排队（queue_no 前缀）
        Db::name('restaurant_queue')->where('aid',$aid)->whereLike('queue_no', self::PREFIX . '%')->delete();
        // 寄存
        Db::name('restaurant_deposit_order')->where('aid',$aid)->whereLike('name', self::PREFIX . '%')->delete();
        // 菜品
        if ($prodIds) Db::name('restaurant_product')->whereIn('id', $prodIds)->delete();
        // 分类/区域/餐桌
        Db::name('restaurant_product_category')->where('aid',$aid)->whereLike('name', self::PREFIX . '%')->delete();
        Db::name('restaurant_table_category')->where('aid',$aid)->whereLike('name', self::PREFIX . '%')->delete();
        Db::name('restaurant_table')->where('aid',$aid)->whereLike('name', self::PREFIX . '%')->delete();
        Db::name('restaurant_area')->where('aid',$aid)->whereLike('name', self::PREFIX . '%')->delete();
        // 营销（coupon表 type=5）
        Db::name('coupon')->where('aid',$aid)->where('type',5)->whereLike('name', self::PREFIX . '%')->update(['deleted'=>1]);
        Db::name('restaurant_cuxiao')->where('aid',$aid)->whereLike('name', self::PREFIX . '%')->delete();
        // 配送方式/备注
        Db::name('restaurant_takeaway_freight')->where('aid',$aid)->whereLike('name', self::PREFIX . '%')->delete();
        Db::name('restaurant_remark')->where('aid',$aid)->whereLike('content', self::PREFIX . '%')->delete();
    }

    public function generate(): void
    {
        $aid = $this->aid;
        if (Db::name('restaurant_area')->where('aid',$aid)->whereLike('name', self::PREFIX . '%')->count() > 0) return;

        $now = time();

        // 1. 出餐区域
        $areaIds = [];
        foreach (['热菜出餐口','凉菜出餐口'] as $i => $name) {
            $areaIds[] = Db::name('restaurant_area')->insertGetId([
                'aid'=>$aid,'bid'=>0,'name'=>self::PREFIX.$name,'status'=>1,'sort'=>9-$i,'createtime'=>$now,
            ]);
        }

        // 2. 餐桌分类
        $tcIds = [];
        foreach (['大厅','包厢','吧台'] as $i => $name) {
            $tcIds[] = Db::name('restaurant_table_category')->insertGetId([
                'aid'=>$aid,'bid'=>0,'name'=>self::PREFIX.$name,'status'=>1,'sort'=>9-$i,
                'service_fee'=>0,'limit_fee'=>0,'booking_fee'=>0,'seat'=>0,'create_time'=>$now,'update_time'=>$now,
            ]);
        }

        // 3. 餐桌
        $tableIds = [];
        $tableNames = ['A01','A02','A03','B01','B02','C01','C02','C03'];
        foreach ($tableNames as $i => $name) {
            $tableIds[] = Db::name('restaurant_table')->insertGetId([
                'aid'=>$aid,'bid'=>0,'cid'=>$tcIds[$i%3],'name'=>self::PREFIX.$name,
                'seat'=>($i%3+2),'canbook'=>1,'status'=>($i<2?2:0),'sort'=>9-$i,
                'create_time'=>$now,'update_time'=>$now,'orderid'=>0,'minprice'=>0,
            ]);
        }

        // 4. 菜品分类
        $pcIds = [];
        foreach (['热菜','凉菜','汤品','主食'] as $i => $name) {
            $pcIds[] = Db::name('restaurant_product_category')->insertGetId([
                'aid'=>$aid,'bid'=>0,'pid'=>0,'name'=>self::PREFIX.$name,
                'is_shop'=>1,'is_takeaway'=>1,'is_booking'=>1,'status'=>1,'sort'=>9-$i,'create_time'=>$now,
            ]);
        }

        // 5. 菜品 + 规格
        $dishes = [
            ['宫保鸡丁', 28, 15, 0, 0],
            ['鱼香肉丝', 26, 14, 0, 0],
            ['麻婆豆腐', 18, 8, 0, 0],
            ['红烧肉', 38, 20, 0, 0],
            ['拍黄瓜', 12, 5, 0, 1],
            ['凉拌木耳', 14, 6, 0, 1],
            ['西红柿蛋汤', 10, 4, 0, 2],
            ['紫菜蛋花汤', 10, 4, 0, 2],
            ['米饭', 3, 1, 0, 3],
            ['炒饭', 12, 6, 0, 3],
            ['牛肉面', 18, 8, 0, 3],
            ['水饺(15个)', 20, 10, 0, 3],
        ];
        $prodIds = [];
        foreach ($dishes as $i => [$name, $price, $cost, $pack, $catIdx]) {
            $pid = Db::name('restaurant_product')->insertGetId([
                'aid'=>$aid,'bid'=>0,'cid'=>(string)$pcIds[$catIdx],'area_id'=>$areaIds[$catIdx%2],
                'name'=>self::PREFIX.$name,'sellpoint'=>'招牌美味','pic'=>'','pics'=>'',
                'market_price'=>$price+5,'sell_price'=>$price,'cost_price'=>$cost,'pack_fee'=>$pack,
                'sort'=>9-$i,'status'=>1,'status_week'=>'1,2,3,4,5,6,7',
                'stock'=>100,'stock_daily'=>100,'sales'=>mt_rand(10,200),
                'create_time'=>$now-$i*3600,'update_time'=>$now,'is_delete'=>0,
            ]);
            $prodIds[] = $pid;
            // 部分菜品加规格（大份/小份）
            if (in_array($name,['宫保鸡丁','鱼香肉丝','米饭'])) {
                Db::name('restaurant_product_guige')->insertAll([
                    ['aid'=>$aid,'product_id'=>$pid,'name'=>'小份','sell_price'=>$price,'market_price'=>$price+3,'cost_price'=>$cost,'stock'=>50,'stock_daily'=>50,'ks'=>'0','sales'=>0],
                    ['aid'=>$aid,'product_id'=>$pid,'name'=>'大份','sell_price'=>$price+8,'market_price'=>$price+12,'cost_price'=>$cost+3,'stock'=>50,'stock_daily'=>50,'ks'=>'1','sales'=>0],
                ]);
            }
        }

        // 6. 堂食订单（多状态）
        $shopOrders = [
            ['堂食-已支付', 1, $tableIds[0], 86.00, 1, $now-3600],
            ['堂食-进行中', 1, $tableIds[1], 52.00, 1, $now-1800],
            ['堂食-已完成', 3, $tableIds[2], 68.00, 1, $now-7200],
            ['堂食-待支付', 0, $tableIds[3], 45.00, 0, $now-600],
            ['堂食-已退款', 1, $tableIds[4], 38.00, 1, $now-10800],
        ];
        foreach ($shopOrders as $i => [$name, $status, $tid, $money, $paid, $ptime]) {
            $oid = Db::name('restaurant_shop_order')->insertGetId([
                'aid'=>$aid,'bid'=>0,'mid'=>0,'tableid'=>$tid,
                'ordernum'=>self::PREFIX.date('YmdHis',$ptime).$i,'title'=>self::PREFIX.$name,
                'renshu'=>($i%4+2),'totalprice'=>$money,'pay_amount'=>$paid?$money:0,
                'createtime'=>$ptime,'status'=>$status,'paytypeid'=>$paid?1:0,'paytype'=>$paid?'wechat':'',
                'paytime'=>$paid?$ptime:0,'collect_time'=>$status==3?$ptime+3600:0,
                'refund_status'=>($status==1&&str_contains($name,'退款'))?2:0,
                'refund_money'=>($status==1&&str_contains($name,'退款'))?$money:0,
            ]);
            // 订单商品
            Db::name('restaurant_shop_order_goods')->insert([
                'aid'=>$aid,'orderid'=>$oid,'ordernum'=>self::PREFIX.date('YmdHis',$ptime).$i,
                'proid'=>$prodIds[$i%count($prodIds)],
                'name'=>self::PREFIX.$dishes[$i%count($dishes)][0],
                'sell_price'=>$money/2,'totalprice'=>$money,'num'=>2,'status'=>1,'createtime'=>$ptime,
            ]);
        }

        // 7. 外卖订单（多状态）
        $takeawayOrders = [
            ['外卖-已接单', 1, 35.00, 1, $now-3000],
            ['外卖-配送中', 2, 52.00, 1, $now-2000],
            ['外卖-已完成', 3, 28.00, 1, $now-14400],
            ['外卖-待接单', 0, 18.00, 0, $now-900],
        ];
        foreach ($takeawayOrders as $i => [$name, $status, $money, $paid, $ptime]) {
            $oid = Db::name('restaurant_takeaway_order')->insertGetId([
                'aid'=>$aid,'bid'=>0,'mid'=>0,
                'ordernum'=>self::PREFIX.'W'.date('YmdHis',$ptime).$i,'title'=>self::PREFIX.$name,
                'totalprice'=>$money,'pay_amount'=>$paid?$money:0,
                'createtime'=>$ptime,'status'=>$status,'paytypeid'=>$paid?1:0,'paytype'=>$paid?'wechat':'',
                'paytime'=>$paid?$ptime:0,'collect_time'=>$status==3?$ptime+3600:0,
            ]);
            Db::name('restaurant_takeaway_order_goods')->insert([
                'aid'=>$aid,'orderid'=>$oid,'ordernum'=>self::PREFIX.'W'.date('YmdHis',$ptime).$i,
                'proid'=>$prodIds[$i%count($prodIds)],
                'name'=>self::PREFIX.$dishes[($i+2)%count($dishes)][0],
                'sell_price'=>$money/2,'totalprice'=>$money,'num'=>1,'status'=>1,'createtime'=>$ptime,
            ]);
        }

        // 8. 排队
        foreach (['A001','A002','B001'] as $i => $qno) {
            Db::name('restaurant_queue')->insert([
                'aid'=>$aid,'bid'=>0,'queue_no'=>$qno,'linkman'=>'顾客'.($i+1),
                'tel'=>'138****'.str_pad((string)(1000+$i),4,'0',STR_PAD_LEFT),
                'renshu'=>($i%3+2),'status'=>($i<2?1:2),'date'=>date('Y-m-d',$now),
                'create_time'=>$now-$i*600,
            ]);
        }

        // 9. 预定
        foreach (['预定-周六晚餐','预定-周日午餐'] as $i => $name) {
            Db::name('restaurant_booking_order')->insert([
                'aid'=>$aid,'bid'=>0,'mid'=>0,'tableid'=>$tableIds[$i],
                'ordernum'=>self::PREFIX.'B'.date('YmdHis',$now+$i*86400),
                'linkman'=>'张'.($i+1),'tel'=>'139****'.str_pad((string)(2000+$i),4,'0',STR_PAD_LEFT),
                'booking_time'=>date('Y-m-d H:i',$now+($i+2)*86400),
                'seat'=>($i+4),'totalprice'=>0,'status'=>1,'check_status'=>0,
                'createtime'=>$now-$i*3600,'refund_status'=>0,
            ]);
        }

        // 10. 寄存
        foreach (['寄存-红酒1瓶','寄存-茶叶1盒'] as $i => $name) {
            Db::name('restaurant_deposit_order')->insert([
                'aid'=>$aid,'bid'=>0,'mid'=>0,'name'=>self::PREFIX.$name,'num'=>1,
                'linkman'=>'李'.($i+1),'tel'=>'137****'.str_pad((string)(3000+$i),4,'0',STR_PAD_LEFT),
                'number_name'=>self::PREFIX.'N'.($i+1),'status'=>1,
                'createtime'=>$now-$i*7200,'takeout_time'=>$now+30*86400,
            ]);
        }

        // 11. 优惠券（coupon表 type=5 餐饮券）
        foreach (['满50减10券','满100减25券'] as $i => $name) {
            Db::name('coupon')->insert([
                'aid'=>$aid,'bid'=>0,'type'=>5,'name'=>self::PREFIX.$name,
                'money'=>($i+1)*10,'minprice'=>($i+1)*50,'stock'=>100,'getnum'=>mt_rand(10,50),'used'=>mt_rand(0,10),
                'starttime'=>date('Y-m-d',$now-86400),'endtime'=>date('Y-m-d',$now+30*86400),
                'status'=>1,'deleted'=>0,'sort'=>9-$i,'createtime'=>$now-$i*3600,
            ]);
        }

        // 12. 促销
        foreach (['满百减十五','第二份半价'] as $i => $name) {
            Db::name('restaurant_cuxiao')->insert([
                'aid'=>$aid,'bid'=>0,'name'=>self::PREFIX.$name,'type'=>($i+1),
                'starttime'=>$now-86400,'endtime'=>$now+30*86400,
                'minprice'=>($i+1)*100,'money'=>($i+1)*15,'zhekou'=>0.5,
                'sort'=>9-$i,'createtime'=>$now-$i*3600,
            ]);
        }

        // 13. 配送方式
        Db::name('restaurant_takeaway_freight')->insert([
            'aid'=>$aid,'bid'=>0,'name'=>self::PREFIX.'同城配送','pstype'=>2,'status'=>1,
            'pricedata'=>'[{"region":"默认","fristweight":"1000","fristprice":"3","secondweight":"1000","secondprice":"1"}]',
            'psprehour'=>4,'peisong_juli1'=>3,'peisong_fee1'=>3,'peisong_range'=>2000,
        ]);

        // 14. 备注预置
        foreach (['少辣','不要香菜','多加米饭','打包'] as $i => $content) {
            Db::name('restaurant_remark')->insert([
                'aid'=>$aid,'bid'=>0,'content'=>self::PREFIX.$content,'sort'=>9-$i,'status'=>1,'createtime'=>$now-$i*600,
            ]);
        }
    }
}
