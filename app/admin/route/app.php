<?php

declare(strict_types=1);

use app\common\service\Result;
use think\facade\Route;

// admin 应用使用显式路由（未定义的路由统一走 miss 返回 JSON 404）
// 鉴权：除 login 外均要求 Authorization: Bearer <jwt>（role=admin，aid 取自 token）

// 登录（白名单）
Route::post('login', 'Login/index');

// 当前账号信息（必须先于 'index' 规则注册，避免前缀匹配抢占）
Route::get('index/profile', 'Index/profile');
Route::post('index/editPassword', 'Index/editPassword');

// 菜单树
Route::get('menu', 'Menu/index');

// 商品管理（分类规则先于商品规则注册）
Route::get('goods/category/list', 'Goods/categoryList');
Route::post('goods/category/add', 'Goods/categoryAdd');
Route::post('goods/category/edit', 'Goods/categoryEdit');
Route::post('goods/category/delete', 'Goods/categoryDelete');
Route::get('goods/list', 'Goods/list');
Route::get('goods/detail', 'Goods/detail');
Route::post('goods/add', 'Goods/add');
Route::post('goods/edit', 'Goods/edit');
Route::post('goods/delete', 'Goods/delete');
Route::post('goods/status', 'Goods/status');
Route::post('goods/stockImport', 'Goods/stockImport');
// 多商户商品审核（商户频道：通过/驳回）
Route::get('goodsAudit/list', 'GoodsAudit/list');
Route::post('goodsAudit/setcheckst', 'GoodsAudit/setcheckst');

// 商城商品卡密（列表/导入/生成/导出/删除；对标参考系统 ShopCode）
Route::get('shopCode/list', 'ShopCode/list');
Route::post('shopCode/import', 'ShopCode/importexcel');
Route::post('shopCode/make', 'ShopCode/makecode');
Route::get('shopCode/export', 'ShopCode/exportexcel');
Route::post('shopCode/delete', 'ShopCode/delete');

// 商品评价管理（列表/审核/回复/删除/虚拟评价/评价开关）
Route::get('comment/list', 'Comment/list');
Route::post('comment/check', 'Comment/check');
Route::post('comment/reply', 'Comment/reply');
Route::post('comment/delete', 'Comment/delete');
Route::post('comment/batchSet', 'Comment/batchSet');
Route::post('comment/batchDelete', 'Comment/batchDelete');
Route::post('comment/save', 'Comment/save');
Route::get('comment/sysset', 'Comment/sysset');
Route::post('comment/sysset', 'Comment/saveSysset');

// 素材库（任务290：浏览/删除/分组五接口/图标渲染落盘）
// 注意：upload/* 具体规则必须先于 'upload' 注册——TP 默认 route_complete_match=false，
// 'upload' 会前缀匹配抢占 upload/iconrender 等请求（任务296 修复图标生成失败根因）
Route::get('upload/browser', 'Upload/browser');
Route::post('upload/delete', 'Upload/delete');
Route::get('upload/group', 'Upload/group');
Route::post('upload/addgroup', 'Upload/addgroup');
Route::post('upload/editgroup', 'Upload/editgroup');
Route::post('upload/delgroup', 'Upload/delgroup');
Route::post('upload/changegroup', 'Upload/changegroup');
Route::post('upload/iconrender', 'Upload/iconrender');
Route::post('upload/cert', 'Upload/cert');

// 通用图片上传（multipart 字段 file；completeMatch 防再被前缀匹配扰动）
Route::post('upload', 'Upload/index')->completeMatch();

// 音乐列表透传（任务315：平台级 aid=0 音乐库由超管控制台 /console/music/* 维护，设计编辑器消费本接口）
Route::get('music/list', 'Music/list');

// 订单管理
Route::get('order/list', 'Order/list');
Route::get('order/detail', 'Order/detail');
Route::post('order/ship', 'Order/ship');
Route::get('order/stat', 'Order/stat');

// 订单批量操作（P17-2：Excel批量发货/选中项批量发货/批量删除/备注/导出）
Route::post('order/batchShip', 'Order/batchShip');
Route::post('order/batchShipSelected', 'Order/batchShipSelected');
Route::post('order/batchDelete', 'Order/batchDelete');
Route::post('order/remark', 'Order/setRemark');
Route::get('order/export', 'Order/export');
Route::get('order/freightOptions', 'Order/freightOptions');
Route::get('order/businessOptions', 'Order/businessOptions');
Route::post('order/changePrice', 'Order/changePrice');
Route::post('order/close', 'Order/close');

// 录入订单（P18-2：代客下单，写 shop_order platform=admin status=1）
Route::get('shopOrderlr/getUser', 'ShopOrderlr/getUser');
Route::get('shopOrderlr/addressList', 'ShopOrderlr/addressList');
Route::get('shopOrderlr/goodsOptions', 'ShopOrderlr/goodsOptions');
Route::get('shopOrderlr/guigeOptions', 'ShopOrderlr/guigeOptions');
Route::post('shopOrderlr/save', 'ShopOrderlr/save');

// 买单收款（P18-2：买单扣费/买单记录退款导出/聚合收款码设置）
Route::get('maidan/memberByTel', 'Maidan/memberByTel');
Route::get('maidan/coupons', 'Maidan/coupons');
Route::post('maidan/preview', 'Maidan/preview');
Route::post('maidan/add', 'Maidan/add');
Route::get('maidan/list', 'Maidan/list');
Route::post('maidan/refund', 'Maidan/refund');
Route::get('maidan/export', 'Maidan/export');
Route::get('maidan/set', 'Maidan/set');
Route::post('maidan/saveSet', 'Maidan/saveSet');

// 送货单（设置三字段读写 + HTML 打印页）
Route::get('shd/set', 'Shd/set');
Route::post('shd/set', 'Shd/saveSet');
Route::get('shd/print', 'Shd/shdPrint');

// 统计（首页面板 + 趋势 + 销售统计）
Route::get('dashboard', 'Stat/dashboard');
Route::get('stat/trend', 'Stat/trend');
Route::get('stat/sales', 'Stat/sales');
Route::get('stat/goods_sales', 'Stat/goodsSales');

// 退款管理
Route::get('refund/list', 'Refund/list');
Route::get('refund/detail', 'Refund/detail');
Route::post('refund/audit', 'Refund/audit');
Route::get('refund/stat', 'Refund/stat');

Route::post('refund/del', 'Refund/del');

// 商户管理
Route::get('merchant/list', 'Merchant/list');
Route::post('merchant/add', 'Merchant/add');
Route::post('merchant/edit', 'Merchant/edit');
Route::post('merchant/delete', 'Merchant/delete');
// 多商户管理（business 表：列表/详情/保存/删除/审核状态/营业状态/充值/登录地址）
Route::get('business/list', 'Business/list');
Route::get('business/detail', 'Business/detail');
Route::post('business/save', 'Business/save');
Route::post('business/delete', 'Business/delete');
Route::post('business/setst', 'Business/setst');
Route::post('business/setopen', 'Business/setopen');
Route::post('business/recharge', 'Business/recharge');
Route::get('business/loginUrl', 'Business/loginUrl');

// 门店管理
Route::get('store/list', 'Store/list');
Route::post('store/add', 'Store/add');
Route::post('store/edit', 'Store/edit');
Route::post('store/delete', 'Store/delete');

// 营销中心 - 优惠券管理
Route::get('coupon/list', 'Coupon/list');
Route::post('coupon/add', 'Coupon/add');
Route::post('coupon/edit', 'Coupon/edit');
Route::post('coupon/status', 'Coupon/status');
Route::post('coupon/delete', 'Coupon/delete');
Route::get('coupon/records', 'Coupon/records');

// 营销中心 - 优惠券管理补全（发放/记录核销/过期提醒设置）
Route::get('couponManage/list', 'CouponManage/list');
Route::get('couponManage/detail', 'CouponManage/detail');
Route::post('couponManage/save', 'CouponManage/save');
Route::post('couponManage/delete', 'CouponManage/delete');
Route::post('couponManage/status', 'CouponManage/status');
Route::post('couponManage/copy', 'CouponManage/copy');
Route::get('couponManage/records', 'CouponManage/records');
Route::post('couponManage/send', 'CouponManage/send');
Route::post('couponManage/recordStatus', 'CouponManage/recordStatus');
Route::get('couponManage/set', 'CouponManage/set');
Route::post('couponManage/set', 'CouponManage/saveSet');

// 营销中心 - 积分签到（设置/记录/删除）
Route::get('sign/set', 'SignReward/signSet');
Route::post('sign/set', 'SignReward/saveSignSet');
Route::get('sign/records', 'SignReward/signRecords');
Route::post('sign/recordDelete', 'SignReward/signRecordDelete');

// 营销中心 - 赠送奖励设置（支付后赠 / 确认收货奖励）
Route::get('reward/payaftergive', 'SignReward/payaftergive');
Route::post('reward/payaftergive', 'SignReward/savePayaftergive');
Route::get('reward/collect', 'SignReward/collectReward');
Route::post('reward/collect', 'SignReward/saveCollectReward');

// 会员管理（等级规则先注册）
Route::get('member/level/record/list', 'Member/levelRecordList');
Route::post('member/level/record/save', 'Member/levelRecordSave');
Route::get('member/level/list', 'Member/levelList');
Route::get('member/level/detail', 'Member/levelDetail');
Route::post('member/level/save', 'Member/levelSave');
Route::post('member/level/delete', 'Member/levelDelete');
Route::get('member/list', 'Member/list');
Route::get('member/detail', 'Member/detail');
Route::post('member/edit', 'Member/edit');
Route::post('member/recharge', 'Member/recharge');
Route::post('member/addscore', 'Member/addscore');
Route::post('member/freeze', 'Member/freeze');
Route::post('member/lock', 'Member/lock');
Route::get('member/moneylog', 'Member/moneylog');
Route::get('member/scorelog', 'Member/scorelog');
Route::get('member/orders', 'Member/orders');
Route::get('member/address', 'Member/address');

// 营销中心 - 积分设置
Route::get('score/config', 'Score/config');
Route::post('score/config', 'Score/saveConfig');
Route::get('score/memberlist', 'Score/memberlist');
Route::post('score/adjust', 'Score/adjust');
Route::get('score/log', 'Score/log');

// 营销中心 - 满减促销
Route::get('promotion/list', 'Promotion/list');
Route::post('promotion/add', 'Promotion/add');
Route::post('promotion/edit', 'Promotion/edit');
Route::post('promotion/status', 'Promotion/status');
Route::post('promotion/delete', 'Promotion/delete');

// 营销中心 - 多人拼团（设置/商品/团管理/订单；超时解散由 schedule:run 挂接）
Route::get('collage/set', 'Collage/setGet');
Route::post('collage/set', 'Collage/setSave');
Route::get('collage/product/list', 'Collage/productList');
Route::get('collage/product/detail', 'Collage/productDetail');
Route::post('collage/product/save', 'Collage/productSave');
Route::post('collage/product/status', 'Collage/productStatus');
Route::post('collage/product/check', 'Collage/productCheck');
Route::post('collage/product/delete', 'Collage/productDelete');
Route::get('collage/team/list', 'Collage/teamList');
Route::post('collage/team/delete', 'Collage/teamDelete');
Route::get('collage/order/list', 'Collage/orderList');
Route::post('collage/order/changePrice', 'Collage/orderChangePrice');
Route::post('collage/order/close', 'Collage/orderClose');
Route::post('collage/order/refundCheck', 'Collage/orderRefundCheck');
Route::post('collage/order/sendExpress', 'Collage/orderSendExpress');

// 营销中心 - 幸运拼团（设置/分类/商品/团/订单/评价/卡密/机器人；超时退款由 schedule:run 挂接）
Route::get('luckyCollage/set', 'LuckyCollage/setGet');
Route::post('luckyCollage/set', 'LuckyCollage/setSave');
Route::get('luckyCollage/category/list', 'LuckyCollage/categoryList');
Route::post('luckyCollage/category/save', 'LuckyCollage/categorySave');
Route::post('luckyCollage/category/delete', 'LuckyCollage/categoryDelete');
Route::get('luckyCollage/product/list', 'LuckyCollage/productList');
Route::get('luckyCollage/product/detail', 'LuckyCollage/productDetail');
Route::post('luckyCollage/product/save', 'LuckyCollage/productSave');
Route::post('luckyCollage/product/status', 'LuckyCollage/productStatus');
Route::post('luckyCollage/product/check', 'LuckyCollage/productCheck');
Route::post('luckyCollage/product/delete', 'LuckyCollage/productDelete');
Route::get('luckyCollage/team/list', 'LuckyCollage/teamList');
Route::post('luckyCollage/team/delete', 'LuckyCollage/teamDelete');
Route::get('luckyCollage/order/list', 'LuckyCollage/orderList');
Route::post('luckyCollage/order/close', 'LuckyCollage/orderClose');
Route::post('luckyCollage/order/sendExpress', 'LuckyCollage/orderSendExpress');
Route::get('luckyCollage/comment/list', 'LuckyCollage/commentList');
Route::post('luckyCollage/comment/reply', 'LuckyCollage/commentReply');
Route::post('luckyCollage/comment/delete', 'LuckyCollage/commentDelete');
Route::get('luckyCollage/code/list', 'LuckyCollage/codeList');
Route::post('luckyCollage/code/import', 'LuckyCollage/codeImport');
Route::post('luckyCollage/code/delete', 'LuckyCollage/codeDelete');
Route::get('luckyCollage/jiqi/list', 'LuckyCollage/jiqiList');
Route::post('luckyCollage/jiqi/save', 'LuckyCollage/jiqiSave');
Route::post('luckyCollage/jiqi/delete', 'LuckyCollage/jiqiDelete');
Route::post('luckyCollage/jiqi/kaituan', 'LuckyCollage/kaituan');

// 营销中心 - 团购（设置/分类/商品阶梯价/订单全操作/评价/卡密；任务187）
Route::get('tuangou/set', 'Tuangou/setGet');
Route::post('tuangou/set', 'Tuangou/setSave');
Route::get('tuangou/category/list', 'Tuangou/categoryList');
Route::post('tuangou/category/save', 'Tuangou/categorySave');
Route::post('tuangou/category/delete', 'Tuangou/categoryDelete');
Route::get('tuangou/product/list', 'Tuangou/productList');
Route::get('tuangou/product/detail', 'Tuangou/productDetail');
Route::post('tuangou/product/save', 'Tuangou/productSave');
Route::post('tuangou/product/copy', 'Tuangou/productCopy');
Route::post('tuangou/product/status', 'Tuangou/productStatus');
Route::post('tuangou/product/check', 'Tuangou/productCheck');
Route::post('tuangou/product/delete', 'Tuangou/productDelete');
Route::post('tuangou/product/refundDiff', 'Tuangou/productRefundDiff');
Route::get('tuangou/order/list', 'Tuangou/orderList');
Route::get('tuangou/order/detail', 'Tuangou/orderDetail');
Route::get('tuangou/order/export', 'Tuangou/orderExport');
Route::post('tuangou/order/changePrice', 'Tuangou/orderChangePrice');
Route::post('tuangou/order/close', 'Tuangou/orderClose');
Route::post('tuangou/order/remark', 'Tuangou/orderRemark');
Route::post('tuangou/order/refundCheck', 'Tuangou/orderRefundCheck');
Route::post('tuangou/order/sendExpress', 'Tuangou/orderSendExpress');
Route::post('tuangou/order/hexiao', 'Tuangou/orderHexiao');
Route::get('tuangou/comment/list', 'Tuangou/commentList');
Route::post('tuangou/comment/setst', 'Tuangou/commentSetStatus');
Route::post('tuangou/comment/reply', 'Tuangou/commentReply');
Route::post('tuangou/comment/delete', 'Tuangou/commentDelete');
Route::get('tuangou/code/list', 'Tuangou/codelistList');
Route::post('tuangou/code/import', 'Tuangou/codelistImport');
Route::post('tuangou/code/make', 'Tuangou/codelistMake');
Route::get('tuangou/code/export', 'Tuangou/codelistExport');
Route::post('tuangou/code/delete', 'Tuangou/codelistDelete');

// 行业应用 - 网约车管理（分类规则先于车辆规则注册）
Route::get('carhailing/category/list', 'Carhailing/categoryList');
Route::post('carhailing/category/add', 'Carhailing/categoryAdd');
Route::post('carhailing/category/edit', 'Carhailing/categoryEdit');
Route::post('carhailing/category/delete', 'Carhailing/categoryDelete');
Route::get('carhailing/product/list', 'Carhailing/productList');
Route::post('carhailing/product/add', 'Carhailing/productAdd');
Route::post('carhailing/product/edit', 'Carhailing/productEdit');
Route::post('carhailing/product/delete', 'Carhailing/productDelete');
Route::post('carhailing/product/status', 'Carhailing/productStatus');
Route::get('carhailing/order/list', 'Carhailing/orderList');
Route::post('carhailing/order/finish', 'Carhailing/orderFinish');

// 行业应用 - 酒店管理（房型规则先于酒店规则中的 order 前缀注册）
Route::get('hotel/room/list', 'Hotel/roomList');
Route::post('hotel/room/add', 'Hotel/roomAdd');
Route::post('hotel/room/edit', 'Hotel/roomEdit');
Route::post('hotel/room/delete', 'Hotel/roomDelete');
Route::post('hotel/room/status', 'Hotel/roomStatus');
Route::get('hotel/order/list', 'Hotel/orderList');
Route::post('hotel/order/checkin', 'Hotel/orderCheckin');
Route::post('hotel/order/finish', 'Hotel/orderFinish');
Route::get('hotel/list', 'Hotel/list');
Route::post('hotel/add', 'Hotel/add');
Route::post('hotel/edit', 'Hotel/edit');
Route::post('hotel/delete', 'Hotel/delete');
Route::post('hotel/status', 'Hotel/status');

// 行业应用 - 预约管理（分类规则先于项目规则注册）
Route::get('yuyue/category/list', 'Yuyue/categoryList');
Route::post('yuyue/category/add', 'Yuyue/categoryAdd');
Route::post('yuyue/category/edit', 'Yuyue/categoryEdit');
Route::post('yuyue/category/delete', 'Yuyue/categoryDelete');
Route::get('yuyue/product/list', 'Yuyue/productList');
Route::get('yuyue/product/detail', 'Yuyue/productDetail');
Route::post('yuyue/product/add', 'Yuyue/productAdd');
Route::post('yuyue/product/edit', 'Yuyue/productEdit');
Route::post('yuyue/product/delete', 'Yuyue/productDelete');
Route::post('yuyue/product/status', 'Yuyue/productStatus');
Route::post('yuyue/product/check', 'Yuyue/productCheck');
Route::post('yuyue/product/copy', 'Yuyue/procopy');
Route::get('yuyue/worker/list', 'Yuyue/workerList');
Route::post('yuyue/worker/add', 'Yuyue/workerAdd');
Route::post('yuyue/worker/edit', 'Yuyue/workerEdit');
Route::post('yuyue/worker/delete', 'Yuyue/workerDelete');
Route::post('yuyue/worker/status', 'Yuyue/workerStatus');
Route::get('yuyue/order/list', 'Yuyue/orderList');
Route::post('yuyue/order/verify', 'Yuyue/orderVerify');

// 行业应用 - 餐饮管理（区域/桌台规则先于其他前缀注册）
Route::get('restaurant/area/list', 'Restaurant/areaList');
Route::post('restaurant/area/add', 'Restaurant/areaAdd');
Route::post('restaurant/area/edit', 'Restaurant/areaEdit');
Route::post('restaurant/area/delete', 'Restaurant/areaDelete');
Route::get('restaurant/table/list', 'Restaurant/tableList');
Route::post('restaurant/table/add', 'Restaurant/tableAdd');
Route::post('restaurant/table/edit', 'Restaurant/tableEdit');
Route::post('restaurant/table/delete', 'Restaurant/tableDelete');
Route::get('restaurant/category/list', 'Restaurant/categoryList');
Route::post('restaurant/category/add', 'Restaurant/categoryAdd');
Route::post('restaurant/category/edit', 'Restaurant/categoryEdit');
Route::post('restaurant/category/delete', 'Restaurant/categoryDelete');
Route::get('restaurant/product/list', 'Restaurant/productList');
Route::post('restaurant/product/add', 'Restaurant/productAdd');
Route::post('restaurant/product/edit', 'Restaurant/productEdit');
Route::post('restaurant/product/delete', 'Restaurant/productDelete');
Route::post('restaurant/product/status', 'Restaurant/productStatus');
Route::get('restaurant/order/list', 'Restaurant/orderList');
Route::post('restaurant/order/finish', 'Restaurant/orderFinish');
Route::post('restaurant/order/cancel', 'Restaurant/orderCancel');

// 行业应用 - 餐饮订单（P14-2：堂食订单/外卖订单/常用备注预置；超时关单与自动完成挂 schedule:run）
Route::get('restaurant/shopOrder/list', 'RestaurantShopOrder/list');
Route::get('restaurant/shopOrder/detail', 'RestaurantShopOrder/detail');
Route::post('restaurant/shopOrder/setremark', 'RestaurantShopOrder/setremark');
Route::post('restaurant/shopOrder/changeprice', 'RestaurantShopOrder/changeprice');
Route::post('restaurant/shopOrder/close', 'RestaurantShopOrder/close');
Route::post('restaurant/shopOrder/ispay', 'RestaurantShopOrder/ispay');
Route::post('restaurant/shopOrder/refundCheck', 'RestaurantShopOrder/refundCheck');
Route::post('restaurant/shopOrder/print', 'RestaurantShopOrder/print');
Route::get('restaurant/takeawayOrder/list', 'RestaurantTakeawayOrder/list');
Route::get('restaurant/takeawayOrder/detail', 'RestaurantTakeawayOrder/detail');
Route::post('restaurant/takeawayOrder/jiedan', 'RestaurantTakeawayOrder/jiedan');
Route::post('restaurant/takeawayOrder/close', 'RestaurantTakeawayOrder/close');
Route::post('restaurant/takeawayOrder/sendExpress', 'RestaurantTakeawayOrder/sendExpress');
Route::get('restaurant/remark/list', 'RestaurantRemark/list');
Route::post('restaurant/remark/save', 'RestaurantRemark/save');
Route::post('restaurant/remark/del', 'RestaurantRemark/del');

// 行业应用 - 餐饮配置（P14-1：餐桌/餐桌分类/菜品/菜品分类/堂食设置/打印机；dining 前缀避免与旧 restaurant 路由冲突）
Route::get('dining/table/list', 'RestaurantTable/list');
Route::post('dining/table/save', 'RestaurantTable/save');
Route::post('dining/table/del', 'RestaurantTable/del');
Route::post('dining/table/setst', 'RestaurantTable/setst');
Route::post('dining/table/create', 'RestaurantTable/createTables');
Route::get('dining/table/qrcode', 'RestaurantTable/qrcode');
Route::get('dining/tablecat/list', 'RestaurantTableCategory/list');
Route::post('dining/tablecat/save', 'RestaurantTableCategory/save');
Route::post('dining/tablecat/del', 'RestaurantTableCategory/del');
Route::get('dining/procat/list', 'RestaurantProductCategory/list');
Route::post('dining/procat/save', 'RestaurantProductCategory/save');
Route::post('dining/procat/del', 'RestaurantProductCategory/del');
Route::get('dining/dish/list', 'RestaurantProduct/list');
Route::get('dining/dish/detail', 'RestaurantProduct/detail');
Route::get('dining/dish/options', 'RestaurantProduct/options');
Route::post('dining/dish/save', 'RestaurantProduct/save');
Route::post('dining/dish/del', 'RestaurantProduct/del');
Route::post('dining/dish/setst', 'RestaurantProduct/setst');
Route::post('dining/dish/setstock', 'RestaurantProduct/setstock');
Route::get('dining/shopset', 'RestaurantShopSet/index');
Route::post('dining/shopset', 'RestaurantShopSet/save');
Route::get('dining/printer/list', 'RestaurantPrint/list');
Route::post('dining/printer/save', 'RestaurantPrint/save');
Route::post('dining/printer/del', 'RestaurantPrint/del');
Route::post('dining/printer/test', 'RestaurantPrint/test');

// 财务中心（消费明细/余额明细/充值/提现/佣金/核销/发票/微信支付流水）
Route::get('finance/payorder/list', 'Finance/payorderList');
Route::get('finance/moneylog/list', 'Finance/moneylogList');
Route::get('finance/recharge/list', 'Finance/rechargeList');
Route::post('finance/recharge/refund', 'Finance/rechargeRefund');
Route::get('finance/withdraw/list', 'Finance/withdrawList');
Route::post('finance/withdraw/audit', 'Finance/withdrawAudit');
Route::post('finance/withdraw/pay', 'Finance/withdrawPay');
Route::get('finance/commission/list', 'Finance/commissionList');
Route::get('finance/commission/withdraw', 'Finance/commissionWithdrawList');
Route::post('finance/commission/withdrawAudit', 'Finance/commissionWithdrawAudit');
Route::get('finance/hexiao/list', 'Finance/hexiaoList');
Route::get('finance/invoice/list', 'Finance/invoiceList');
Route::post('finance/invoice/audit', 'Finance/invoiceAudit');
Route::post('finance/invoice/reply', 'Finance/invoiceReply');
Route::get('finance/wxpaylog/list', 'Finance/wxpaylogList');
Route::get('finance/commissionRecord/list', 'Finance/commissionRecordList');
Route::get('finance/commissionRecord/export', 'Finance/commissionRecordExport');
Route::post('finance/commissionRecord/settle', 'Finance/commissionRecordSettle');
Route::get('finance/fenhonglog/list', 'Finance/fenhonglogList');
Route::get('finance/fenhonglog/export', 'Finance/fenhonglogExport');
Route::get('finance/hexiaoShopproduct/list', 'Finance/hexiaoShopproductList');
Route::get('finance/hexiaoShopproduct/export', 'Finance/hexiaoShopproductExport');

// 余额宝（收益明细/提现审核打款/设置；任务181）
Route::get('yuebao/moneylog/list', 'Yuebao/moneylogList');
Route::get('yuebao/withdraw/list', 'Yuebao/withdrawList');
Route::post('yuebao/withdraw/audit', 'Yuebao/withdrawAudit');
Route::post('yuebao/withdraw/pay', 'Yuebao/withdrawPay');
Route::get('yuebao/config', 'Yuebao/config');
Route::post('yuebao/config', 'Yuebao/saveConfig');

// 营销中心 - 整点秒杀（场次明细规则先注册；关单定时任务挂 schedule:run）
Route::get('seckill/set', 'Seckill/set');
Route::post('seckill/set', 'Seckill/saveSet');
Route::get('seckill/product/list', 'Seckill/productList');
Route::get('seckill/product/detail', 'Seckill/productDetail');
Route::post('seckill/product/check', 'Seckill/productCheck');
Route::post('seckill/product/procopy', 'Seckill/procopy');
Route::post('seckill/product/save', 'Seckill/productSave');
Route::post('seckill/product/delete', 'Seckill/productDelete');
Route::post('seckill/product/status', 'Seckill/productStatus');
Route::get('seckill/prodata/list', 'Seckill/prodataList');
Route::post('seckill/prodata/save', 'Seckill/prodataSave');
Route::post('seckill/prodata/delete', 'Seckill/prodataDelete');
Route::get('seckill/order/list', 'Seckill/orderList');
Route::post('seckill/order/close', 'Seckill/orderClose');

// 营销中心 - 积分商城（设置/分类/商品/兑换订单；关单定时任务挂 schedule:run）
Route::get('scoreshop/set', 'Scoreshop/set');
Route::post('scoreshop/set', 'Scoreshop/saveSet');
Route::get('scoreshop/category/list', 'Scoreshop/categoryList');
Route::post('scoreshop/category/save', 'Scoreshop/categorySave');
Route::post('scoreshop/category/delete', 'Scoreshop/categoryDelete');
Route::get('scoreshop/product/list', 'Scoreshop/productList');
Route::post('scoreshop/product/save', 'Scoreshop/productSave');
Route::post('scoreshop/product/delete', 'Scoreshop/productDelete');
Route::post('scoreshop/product/status', 'Scoreshop/productStatus');
Route::get('scoreshop/order/list', 'Scoreshop/orderList');
Route::post('scoreshop/order/close', 'Scoreshop/orderClose');

// 营销中心 - 抽奖活动（大转盘/刮刮卡：活动管理 + 中奖记录领奖核销；概率/防超发核心在 LotteryService）
Route::get('choujiang/list', 'Choujiang/list');
Route::get('choujiang/detail', 'Choujiang/detail');
Route::post('choujiang/save', 'Choujiang/save');
Route::post('choujiang/delete', 'Choujiang/delete');
Route::post('choujiang/status', 'Choujiang/status');
Route::get('choujiang/records', 'Choujiang/records');
Route::post('choujiang/recordSetst', 'Choujiang/recordSetst');

// 营销中心 - 投票活动（活动 CRUD/分组/选手审核/排行/投票记录；防刷：同会员同活动每日 per_daycount 票、累计 per_allcount 票）
Route::get('toupiao/list', 'Toupiao/list');
Route::post('toupiao/save', 'Toupiao/save');
Route::post('toupiao/del', 'Toupiao/del');
Route::post('toupiao/status', 'Toupiao/status');
Route::get('toupiao/group/list', 'Toupiao/groupList');
Route::post('toupiao/group/save', 'Toupiao/groupSave');
Route::post('toupiao/group/del', 'Toupiao/groupDel');
Route::get('toupiao/join/list', 'Toupiao/joinList');
Route::post('toupiao/join/save', 'Toupiao/joinSave');
Route::post('toupiao/join/audit', 'Toupiao/joinAudit');
Route::post('toupiao/join/del', 'Toupiao/joinDel');
Route::post('toupiao/join/addpiao', 'Toupiao/joinAddpiao');
Route::get('toupiao/stat', 'Toupiao/stat');
Route::get('toupiao/help/list', 'Toupiao/helpList');
Route::post('toupiao/vote', 'Toupiao/vote');

// 营销中心 - 砍价活动（商品/发起记录/订单/核销码/设置；帮砍递减与下单链路封装 BargainService，C 端 api 预留）
Route::get('kanjia/sysset', 'Kanjia/sysset');
Route::post('kanjia/sysset', 'Kanjia/saveSysset');
Route::get('kanjia/product/list', 'Kanjia/productList');
Route::get('kanjia/product/detail', 'Kanjia/productDetail');
Route::post('kanjia/product/check', 'Kanjia/productCheck');
Route::post('kanjia/product/save', 'Kanjia/productSave');
Route::post('kanjia/product/del', 'Kanjia/productDelete');
Route::post('kanjia/product/status', 'Kanjia/productStatus');
Route::get('kanjia/join/list', 'Kanjia/joinList');
Route::get('kanjia/join/help', 'Kanjia/joinHelp');
Route::post('kanjia/join/del', 'Kanjia/joinDelete');
Route::get('kanjia/order/list', 'Kanjia/orderList');
Route::post('kanjia/order/ship', 'Kanjia/orderShip');
Route::post('kanjia/order/refundCheck', 'Kanjia/orderRefundCheck');
Route::post('kanjia/order/close', 'Kanjia/orderClose');
Route::post('kanjia/order/remark', 'Kanjia/orderRemark');
Route::get('kanjia/codelist/list', 'Kanjia/codelistList');
Route::post('kanjia/codelist/hexiao', 'Kanjia/codelistHexiao');

// 营销中心 - 周期购（商品+规格动态行/分类/订单状态机/按期发货 stage/退款审核；分类规则先注册）
Route::get('cycle/sysset', 'Cycle/sysset');
Route::post('cycle/sysset', 'Cycle/saveSysset');
Route::get('cycle/category/list', 'Cycle/categoryList');
Route::post('cycle/category/save', 'Cycle/categorySave');
Route::post('cycle/category/del', 'Cycle/categoryDelete');
Route::get('cycle/product/list', 'Cycle/productList');
Route::get('cycle/product/detail', 'Cycle/productDetail');
Route::post('cycle/product/save', 'Cycle/productSave');
Route::post('cycle/product/del', 'Cycle/productDelete');
Route::post('cycle/product/status', 'Cycle/productStatus');
Route::post('cycle/product/check', 'Cycle/productCheck');
Route::get('cycle/order/list', 'Cycle/orderList');
Route::get('cycle/order/detail', 'Cycle/orderDetail');
Route::post('cycle/order/ship', 'Cycle/orderShip');
Route::post('cycle/order/stageCollect', 'Cycle/orderStageCollect');
Route::post('cycle/order/refundCheck', 'Cycle/orderRefundCheck');
Route::post('cycle/order/remark', 'Cycle/orderRemark');

// 页面设计器（页面装修：页面 CRUD / 设首页 / 模板套用；保存即发布无草稿）
Route::get('designer/page/list', 'Designer/pageList');
Route::get('designer/page/detail', 'Designer/pageDetail');
Route::post('designer/page/save', 'Designer/pageSave');
Route::post('designer/page/del', 'Designer/pageDel');
Route::post('designer/page/copy', 'Designer/pageCopy');
Route::post('designer/page/sethome', 'Designer/pageSethome');
Route::post('designer/page/rehome', 'Designer/pageRehome');
Route::get('designer/template/list', 'Designer/templateList');
Route::post('designer/template/apply', 'Designer/templateApply');

// 设计器深化（底部导航/内页导航/商品详情导航/登录页装修/分享设置，均为固定结构表单+JSON）
Route::get('designer/menu/get', 'Designer/menuGet');
Route::post('designer/menu/save', 'Designer/menuSave');
Route::get('designer/menu2/list', 'Designer/menu2List');
Route::get('designer/menu2/detail', 'Designer/menu2Detail');
Route::post('designer/menu2/save', 'Designer/menu2Save');
Route::post('designer/menu2/setst', 'Designer/menu2Setst');
Route::post('designer/menu2/del', 'Designer/menu2Del');
Route::post('designer/menu2/copy', 'Designer/menu2Copy');
Route::get('designer/shopdetail/get', 'Designer/shopdetailGet');
Route::post('designer/shopdetail/save', 'Designer/shopdetailSave');
Route::get('designer/login/get', 'Designer/loginGet');
Route::post('designer/login/save', 'Designer/loginSave');
Route::get('designer/share/list', 'Designer/shareList');
Route::get('designer/share/detail', 'Designer/shareDetail');
Route::post('designer/share/save', 'Designer/shareSave');
Route::post('designer/share/setst', 'Designer/shareSetst');
Route::post('designer/share/del', 'Designer/shareDel');

// 系统工具 - 清理缓存（租户壳「系统 - 清理缓存」当页执行）
Route::post('system/clearCache', 'System/clearCache');

// 系统设置（admin_set 白名单字段，GET 读取 / POST 保存）
Route::get('sysset', 'Sysset/index');
Route::post('sysset', 'Sysset/save');

// 平台绑定中心（八端配置：wx/mp 支持手动配置与开放平台代授权，其余六端手动配置）
Route::get('binding/overview', 'Binding/overview');
Route::get('binding/wxAuthUrl', 'Binding/wxAuthUrl');
Route::post('binding/refreshAuth', 'Binding/refreshAuth');
Route::get('binding/config/:platform', 'Binding/config');
Route::post('binding/save/:platform', 'Binding/save');

// 小程序发布链（选模板→上传 commit→提审→审核状态→发布/撤回/回退；体验码/体验者/更新记录）
Route::get('wxapp/overview', 'Wxapp/overview');
Route::get('wxapp/templateList', 'Wxapp/templateList');
Route::post('wxapp/commit', 'Wxapp/commit');
Route::post('wxapp/submitAudit', 'Wxapp/submitAudit');
Route::get('wxapp/getAuditStatus', 'Wxapp/getAuditStatus');
Route::post('wxapp/release', 'Wxapp/release');
Route::post('wxapp/undocodeaudit', 'Wxapp/undocodeaudit');
Route::post('wxapp/revertCodeRelease', 'Wxapp/revertCodeRelease');
Route::get('wxapp/getTrialQrcode', 'Wxapp/getTrialQrcode');
Route::post('wxapp/bindTester', 'Wxapp/bindTester');
Route::get('wxapp/wxalogList', 'Wxapp/wxalogList');

// 小程序运营设置（改名/头像/简介/搜索开关/服务器域名/业务域名/隐私指引/ICP备案）
Route::get('wxappSet/accountInfo', 'WxappSet/accountInfo');
Route::post('wxappSet/setNickname', 'WxappSet/setNickname');
Route::post('wxappSet/setHeadimg', 'WxappSet/setHeadimg');
Route::post('wxappSet/setSignature', 'WxappSet/setSignature');
Route::post('wxappSet/setSearchStatus', 'WxappSet/setSearchStatus');
Route::rule('wxappSet/serverDomain', 'WxappSet/serverDomain', 'GET|POST');
Route::rule('wxappSet/jumpDomain', 'WxappSet/jumpDomain', 'GET|POST');
Route::get('wxappSet/jumpDomainFile', 'WxappSet/jumpDomainFile');
Route::rule('wxappSet/privacy', 'WxappSet/privacy', 'GET|POST');
Route::get('wxappSet/icpInfo', 'WxappSet/icpInfo');
Route::post('wxappSet/icpSave', 'WxappSet/icpSave');
Route::post('wxappSet/icpFaceLaunch', 'WxappSet/icpFaceLaunch');
Route::get('wxappSet/icpFaceQuery', 'WxappSet/icpFaceQuery');
Route::post('wxappSet/icpSubmit', 'WxappSet/icpSubmit');

// 配送方式（freight 表：列表/保存/删除/启停）
Route::get('freight/list', 'Freight/list');
Route::post('freight/save', 'Freight/save');
Route::post('freight/delete', 'Freight/delete');
Route::post('freight/status', 'Freight/status');

// 快递设置（express_data 表：候选字典 + 启用公司 JSON）
Route::get('expressdata', 'ExpressData/index');
Route::post('expressdata', 'ExpressData/save');

// 帮助中心
Route::get('help/list', 'Help/list');
Route::get('help/detail', 'Help/detail');

// 通知公告
Route::get('notice/mine', 'Notice/mine');
Route::get('notice/detail', 'Notice/detail');
Route::post('notice/read', 'Notice/read');

// 会员组 - 升级申请审核（列表过滤/表单解析/通过发奖/驳回退款；任务176）
Route::get('memberLevelup/list', 'MemberLevelup/list');
Route::post('memberLevelup/audit', 'MemberLevelup/audit');

// 会员组 - 会员关系图（ECharts tree 数据 + chartsMore 懒加载；任务176）
Route::get('memberRelation/charts', 'MemberRelation/charts');
Route::get('memberRelation/chartsMore', 'MemberRelation/chartsMore');

// 共享海报（商品海报 type=product / 分享海报 type=index 多模板；任务176）
Route::get('poster/list', 'Poster/list');
Route::get('poster/get', 'Poster/get');
Route::post('poster/save', 'Poster/save');
Route::post('poster/add', 'Poster/add');
Route::post('poster/del', 'Poster/del');
Route::get('poster/qrcode', 'Poster/qrcode');

// 系统权限组（批次2-C：管理员/权限组/短信/店铺评价/操作日志/快捷菜单）
Route::get('adminUser/list', 'AdminUser/list');
Route::post('adminUser/save', 'AdminUser/save');
Route::post('adminUser/delete', 'AdminUser/delete');
// 管理员绑定会员/解绑/手机端登录地址（任务269）
Route::post('adminUser/jiebang', 'AdminUser/jiebang');
Route::post('adminUser/bindmid', 'AdminUser/bindmid');
Route::get('adminUser/loginurl', 'AdminUser/loginurl');

Route::get('userGroup/list', 'UserGroup/list');
Route::get('userGroup/detail', 'UserGroup/detail');
Route::post('userGroup/save', 'UserGroup/save');
Route::post('userGroup/delete', 'UserGroup/delete');
Route::get('userGroup/menuTree', 'UserGroup/menuTree');

Route::get('sms/set', 'Sms/set');
Route::post('sms/saveSet', 'Sms/saveSet');
Route::get('sms/sendlog', 'Sms/sendlog');
Route::post('sms/test', 'Sms/test');

Route::get('businessComment/list', 'BusinessComment/list');
Route::get('businessComment/detail', 'BusinessComment/detail');
Route::post('businessComment/reply', 'BusinessComment/reply');
Route::post('businessComment/setst', 'BusinessComment/setst');
Route::post('businessComment/delete', 'BusinessComment/delete');

Route::get('plog/list', 'Plog/list');
Route::post('plog/delete', 'Plog/delete');
Route::get('plog/export', 'Plog/export');

Route::get('shortcutMenu/index', 'ShortcutMenu/index');
Route::post('shortcutMenu/save', 'ShortcutMenu/save');

// 商城配置组（批次2-A 任务173：商品分组/商品参数/商品服务/商城设置/商品采集）
Route::get('shopGroup/list', 'ShopGroup/list');
Route::get('shopGroup/detail', 'ShopGroup/detail');
Route::post('shopGroup/save', 'ShopGroup/save');
Route::post('shopGroup/delete', 'ShopGroup/delete');

Route::get('shopParam/list', 'ShopParam/list');
Route::get('shopParam/categoryTree', 'ShopParam/categoryTree');
Route::get('shopParam/detail', 'ShopParam/detail');
Route::post('shopParam/save', 'ShopParam/save');
Route::post('shopParam/delete', 'ShopParam/delete');

Route::get('shopFuwu/list', 'ShopFuwu/list');
Route::get('shopFuwu/detail', 'ShopFuwu/detail');
Route::post('shopFuwu/save', 'ShopFuwu/save');
Route::post('shopFuwu/delete', 'ShopFuwu/delete');

Route::get('shopSet/config', 'ShopSet/config');
Route::post('shopSet/save', 'ShopSet/save');

Route::get('shopTaobao/config', 'ShopTaobao/config');
Route::post('shopTaobao/saveKey', 'ShopTaobao/saveKey');
Route::post('shopTaobao/collect', 'ShopTaobao/collect');
Route::post('shopTaobao/picLocal', 'ShopTaobao/picLocal');

Route::get('index', 'Index/index');

// 分红体系（任务183：团队/股东/区域三套分红预览、结算、发放审核）
Route::get('fenhong/preview', 'Fenhong/preview');
Route::post('fenhong/settle', 'Fenhong/settle');
Route::get('fenhong/loglist', 'Fenhong/loglist');
Route::post('fenhong/examinesend', 'Fenhong/examinesend');
Route::post('fenhong/sendall', 'Fenhong/sendall');

// 奖金池（利润池配置/区域达标池CRUD/发放记录；任务184）
Route::get('prizepool/profit/config', 'PrizePool/profitConfig');
Route::post('prizepool/profit/config', 'PrizePool/saveProfitConfig');
Route::post('prizepool/profit/send', 'PrizePool/sendProfitNow');
Route::get('prizepool/profit/log', 'PrizePool/profitLog');
Route::get('prizepool/pool/list', 'PrizePool/poolList');
Route::post('prizepool/pool/save', 'PrizePool/savePool');
Route::post('prizepool/pool/delete', 'PrizePool/deletePool');
Route::get('prizepool/pool/orderlog', 'PrizePool/orderLog');
Route::get('prizepool/sendlog/list', 'PrizePool/sendLogList');
Route::get('prizepool/provinces', 'PrizePool/provinces');

// 口令/谜语游戏（批次4-E 任务190 E1-1：页面设置/活动CRUD/参与记录，type=kouling|riddle）
Route::get('kouling/set', 'Kouling/set');
Route::post('kouling/set', 'Kouling/saveSet');
Route::get('kouling/list', 'Kouling/list');
Route::get('kouling/detail', 'Kouling/detail');
Route::post('kouling/save', 'Kouling/save');
Route::post('kouling/delete', 'Kouling/delete');
Route::get('kouling/records', 'Kouling/records');

// 微信红包（批次4-E 任务190 E1-2：活动CRUD/领取记录/抽奖码生成导入导出）
Route::get('hongbao/list', 'Hongbao/list');
Route::get('hongbao/detail', 'Hongbao/detail');
Route::post('hongbao/save', 'Hongbao/save');
Route::post('hongbao/delete', 'Hongbao/delete');
Route::get('hongbao/records', 'Hongbao/records');
Route::get('hongbao/codelist', 'Hongbao/codelist');
Route::post('hongbao/makecode', 'Hongbao/makecode');
Route::post('hongbao/importcode', 'Hongbao/importcode');
Route::get('hongbao/exportcode', 'Hongbao/exportcode');

// Fifa 世界杯竞猜（批次4-E 任务190 E1-3：赛事手工录入/设置/记录/开奖）
Route::get('fifa/list', 'Fifa/list');
Route::get('fifa/detail', 'Fifa/detail');
Route::post('fifa/save', 'Fifa/save');
Route::post('fifa/delete', 'Fifa/delete');
Route::get('fifa/set', 'Fifa/set');
Route::post('fifa/set', 'Fifa/saveSet');
Route::get('fifa/records', 'Fifa/records');
Route::post('fifa/recordDelete', 'Fifa/recordDelete');
Route::post('fifa/open', 'Fifa/open');

// 拼团/秒杀评价（批次4-E 任务190 E2-4：列表/审核/回复/删除 + 好评率聚合回写，type=collage|seckill）
Route::get('activityComment/list', 'ActivityComment/list');
Route::post('activityComment/check', 'ActivityComment/check');
Route::post('activityComment/reply', 'ActivityComment/reply');
Route::post('activityComment/delete', 'ActivityComment/delete');

// 确认收货奖励记录（批次4-E 任务190 E2-5；设置在 SignReward reward/collect）
Route::get('orderCollectReward/records', 'OrderCollectReward/records');

// 营销数据统计（批次4-E 任务190 E2-6：六模块汇总/金额分布/时间趋势）
Route::get('dataStatistics/summary', 'DataStatistics/summary');
Route::get('dataStatistics/moneyDist', 'DataStatistics/moneyDist');
Route::get('dataStatistics/trend', 'DataStatistics/trend');

// 营销中心 - 注册赠送设置（批次4-A 任务186）
Route::get('registerGive/config', 'RegisterGive/config');
Route::post('registerGive/save', 'RegisterGive/save');

// 营销中心 - 充值赠送设置（批次4-A 任务186：档位行动态行）
Route::get('moneyGiveset/config', 'MoneyGiveset/config');
Route::post('moneyGiveset/save', 'MoneyGiveset/save');

// 营销中心 - 商品促销 cuxiao（批次4-A 任务186：type 四模式，下单算价生效）
Route::get('cuxiao/list', 'Cuxiao/list');
Route::get('cuxiao/detail', 'Cuxiao/detail');
Route::post('cuxiao/add', 'Cuxiao/add');
Route::post('cuxiao/edit', 'Cuxiao/edit');
Route::post('cuxiao/status', 'Cuxiao/status');
Route::post('cuxiao/delete', 'Cuxiao/delete');

// 营销中心 - 购物返现 cashback（批次4-A 任务186：规则 CRUD + 参与记录，确认收货触发）
Route::get('cashback/list', 'Cashback/list');
Route::get('cashback/detail', 'Cashback/detail');
Route::post('cashback/add', 'Cashback/add');
Route::post('cashback/edit', 'Cashback/edit');
Route::post('cashback/status', 'Cashback/status');
Route::post('cashback/delete', 'Cashback/delete');
Route::get('cashback/memberList', 'Cashback/memberList');
Route::get('cashback/memberLogList', 'Cashback/memberLogList');

// 短视频整组（批次5-C 任务196：分类/视频管理审核/评论回复/系统设置；海报走通用 poster 路由 type=shortvideo）
Route::get('shortvideo/category/list', 'ShortvideoCategory/list');
Route::get('shortvideo/category/options', 'ShortvideoCategory/options');
Route::post('shortvideo/category/save', 'ShortvideoCategory/save');
Route::post('shortvideo/category/delete', 'ShortvideoCategory/delete');
Route::get('shortvideo/video/list', 'Shortvideo/list');
Route::get('shortvideo/video/detail', 'Shortvideo/detail');
Route::post('shortvideo/video/save', 'Shortvideo/save');
Route::post('shortvideo/video/status', 'Shortvideo/status');
Route::post('shortvideo/video/check', 'Shortvideo/check');
Route::post('shortvideo/video/delete', 'Shortvideo/delete');
Route::get('shortvideo/comment/list', 'ShortvideoComment/list');
Route::post('shortvideo/comment/setst', 'ShortvideoComment/setst');
Route::post('shortvideo/comment/reply', 'ShortvideoComment/reply');
Route::post('shortvideo/comment/delete', 'ShortvideoComment/delete');
Route::get('shortvideo/reply/list', 'ShortvideoCommentReply/list');
Route::post('shortvideo/reply/setst', 'ShortvideoCommentReply/setst');
Route::post('shortvideo/reply/delete', 'ShortvideoCommentReply/delete');
Route::get('shortvideo/sysset/get', 'ShortvideoSysset/get');
Route::post('shortvideo/sysset/save', 'ShortvideoSysset/save');

// 文章管理整组（批次5-A 任务194：文章/分类/评论/回评/设置；商户视图复用 article 列表 bid 过滤）
Route::get('article/list', 'Article/list');
Route::get('article/detail', 'Article/detail');
Route::post('article/save', 'Article/save');
Route::post('article/delete', 'Article/delete');
Route::post('article/setst', 'Article/setst');
Route::post('article/setcheckst', 'Article/setcheckst');
Route::post('article/copy', 'Article/copy');

Route::get('articlecategory/list', 'ArticleCategory/list');
Route::post('articlecategory/save', 'ArticleCategory/save');
Route::post('articlecategory/delete', 'ArticleCategory/delete');

Route::get('articlepinglun/list', 'ArticlePinglun/list');
Route::post('articlepinglun/setst', 'ArticlePinglun/setst');
Route::post('articlepinglun/delete', 'ArticlePinglun/delete');
Route::post('articlepinglun/reply', 'ArticlePinglun/reply');

Route::get('articleplreply/list', 'ArticlePlreply/list');
Route::post('articleplreply/setst', 'ArticlePlreply/setst');
Route::post('articleplreply/delete', 'ArticlePlreply/delete');

Route::get('articleset/get', 'ArticleSet/get');
Route::post('articleset/save', 'ArticleSet/save');

// Luntan group (batch 5-B task 195: post/category/comment/reply/sysset)
Route::get('luntan/list', 'Luntan/list');
Route::post('luntan/audit', 'Luntan/audit');
Route::post('luntan/setTop', 'Luntan/setTop');
Route::post('luntan/delete', 'Luntan/delete');
Route::get('luntan/categoryOptions', 'Luntan/categoryOptions');
Route::get('luntan/sysset', 'Luntan/sysset');
Route::post('luntan/syssetSave', 'Luntan/syssetSave');

Route::get('luntanCategory/list', 'LuntanCategory/list');
Route::post('luntanCategory/save', 'LuntanCategory/save');
Route::post('luntanCategory/delete', 'LuntanCategory/delete');

Route::get('luntanPinglun/list', 'LuntanPinglun/list');
Route::post('luntanPinglun/audit', 'LuntanPinglun/audit');
Route::post('luntanPinglun/delete', 'LuntanPinglun/delete');

Route::get('luntanPlreply/list', 'LuntanPlreply/list');
Route::post('luntanPlreply/audit', 'LuntanPlreply/audit');
Route::post('luntanPlreply/delete', 'LuntanPlreply/delete');

// Knowledge-payment course management (batch 5-D1 task 197: category/course/chapter/order/studylog/sysset; poster uses generic poster routes type=kecheng)
Route::get('kechengCategory/list', 'KechengCategory/list');
Route::post('kechengCategory/save', 'KechengCategory/save');
Route::post('kechengCategory/delete', 'KechengCategory/delete');

Route::get('kechengList/list', 'KechengList/list');
Route::get('kechengList/detail', 'KechengList/detail');
Route::post('kechengList/save', 'KechengList/save');
Route::post('kechengList/delete', 'KechengList/delete');
Route::post('kechengList/status', 'KechengList/status');

Route::get('kechengChapter/list', 'KechengChapter/list');
Route::post('kechengChapter/save', 'KechengChapter/save');
Route::post('kechengChapter/delete', 'KechengChapter/delete');

Route::get('kechengOrder/list', 'KechengOrder/list');
Route::get('kechengStudylog/list', 'KechengStudylog/list');

Route::get('kechengSet/config', 'KechengSet/config');
Route::post('kechengSet/save', 'KechengSet/save');

// Knowledge-payment question bank & lecturer audit (batch 5-D2 task 198)
Route::get('kechengTiku/list', 'KechengTiku/list');
Route::post('kechengTiku/save', 'KechengTiku/save');
Route::post('kechengTiku/delete', 'KechengTiku/delete');
Route::post('kechengTiku/status', 'KechengTiku/status');
Route::get('kechengTiku/chapters', 'KechengTiku/chapters');
Route::post('kechengTiku/import', 'KechengTiku/import');
Route::get('kechengTiku/export', 'KechengTiku/export');

Route::get('kechengLecturer/list', 'KechengLecturer/list');
Route::post('kechengLecturer/check', 'KechengLecturer/check');
Route::post('kechengLecturer/status', 'KechengLecturer/status');

// 行业应用 - 餐饮排队叫号（批次6-T6-2 任务203：分类CRUD/队列列表+叫号过号/大屏设置；语音降级浏览器TTS）
Route::get('restaurant/queue/category/list', 'RestaurantQueueCategory/list');
Route::post('restaurant/queue/category/save', 'RestaurantQueueCategory/save');
Route::post('restaurant/queue/category/del', 'RestaurantQueueCategory/del');
Route::get('restaurant/queue/list', 'RestaurantQueue/list');
Route::post('restaurant/queue/jiaohao', 'RestaurantQueue/jiaohao');
Route::post('restaurant/queue/guohao', 'RestaurantQueue/guohao');
Route::post('restaurant/queue/cancel', 'RestaurantQueue/cancel');
Route::post('restaurant/queue/del', 'RestaurantQueue/del');
Route::get('restaurant/queue/set', 'RestaurantQueueSet/index');
Route::post('restaurant/queue/set', 'RestaurantQueueSet/save');

// 行业应用 - 餐饮取餐（批次6-T6-4 任务205：取餐列表 出餐/叫号/完成取餐 + 大屏轮播设置；支付成功自动生成取餐号，24h未取过期挂 schedule:run）
Route::get('restaurant/takefood/list', 'RestaurantTakeFood/list');
Route::post('restaurant/takefood/out', 'RestaurantTakeFood/out');
Route::post('restaurant/takefood/call', 'RestaurantTakeFood/call');
Route::post('restaurant/takefood/finish', 'RestaurantTakeFood/finish');
Route::get('restaurant/takefood/set', 'RestaurantTakeFood/set');
Route::post('restaurant/takefood/set', 'RestaurantTakeFood/saveSet');

// 行业应用 - 餐饮寄存（批次6-T6-3 任务204：寄存管理/添加寄存/位置编号字典/寄存设置；状态机 0审核中→1寄存中/3未通过→2全部取出）
Route::get('restaurant/deposit/list', 'RestaurantDeposit/list');
Route::get('restaurant/deposit/detail', 'RestaurantDeposit/detail');
Route::post('restaurant/deposit/check', 'RestaurantDeposit/check');
Route::post('restaurant/deposit/takeout', 'RestaurantDeposit/takeout');
Route::post('restaurant/deposit/setremark', 'RestaurantDeposit/setremark');
Route::post('restaurant/deposit/del', 'RestaurantDeposit/del');

Route::get('restaurant/deposit/form', 'RestaurantDepositAdd/form');
Route::post('restaurant/deposit/add', 'RestaurantDepositAdd/save');

Route::get('restaurant/deposit/position/list', 'RestaurantDepositPosition/positionList');
Route::post('restaurant/deposit/position/save', 'RestaurantDepositPosition/positionSave');
Route::post('restaurant/deposit/position/del', 'RestaurantDepositPosition/positionDel');
Route::get('restaurant/deposit/number/list', 'RestaurantDepositPosition/numberList');
Route::post('restaurant/deposit/number/save', 'RestaurantDepositPosition/numberSave');
Route::post('restaurant/deposit/number/del', 'RestaurantDepositPosition/numberDel');

Route::get('restaurant/deposit/set/config', 'RestaurantDepositSet/config');
Route::post('restaurant/deposit/set/save', 'RestaurantDepositSet/save');

// 行业应用 - 通用收银台一期（批次6-T6-5 任务206：收银设置/收银操作/收银订单/统计交接班；
// 支付口径 paytypeid：1微信付款码mock 3现金 4余额 100+自定义字典id；bid=0 平台自营）
Route::get('cashier/set', 'CashierSet/index');
Route::post('cashier/set', 'CashierSet/save');
Route::get('cashier/set/customlist', 'CashierSet/customList');
Route::post('cashier/set/customsave', 'CashierSet/customSave');
Route::post('cashier/set/customdelete', 'CashierSet/customDelete');
Route::get('cashier/set/paylist', 'CashierSet/payList');

Route::get('cashier/categories', 'Cashier/categories');
Route::get('cashier/products', 'Cashier/products');
Route::get('cashier/member', 'Cashier/memberSearch');
Route::get('cashier/cart', 'Cashier/cart');
Route::post('cashier/addgoods', 'Cashier/addGoods');
Route::post('cashier/changenum', 'Cashier/changeNum');
Route::post('cashier/changeprice', 'Cashier/changePrice');
Route::post('cashier/changeremark', 'Cashier/changeRemark');
Route::post('cashier/delgoods', 'Cashier/delGoods');
Route::post('cashier/clearcart', 'Cashier/clearCart');
Route::post('cashier/hangup', 'Cashier/hangup');
Route::post('cashier/cancelhangup', 'Cashier/cancelHangup');
Route::get('cashier/hanguplist', 'Cashier/hangupList');
Route::post('cashier/paypreview', 'Cashier/payPreview');
Route::post('cashier/pay', 'Cashier/pay');

Route::get('cashier/order', 'CashierOrder/index');
Route::get('cashier/order/detail', 'CashierOrder/detail');
Route::post('cashier/order/refundapply', 'CashierOrder/refundApply');
Route::post('cashier/order/refundaudit', 'CashierOrder/refundAudit');
Route::post('cashier/order/delete', 'CashierOrder/delete');

Route::get('cashier/stat', 'CashierStat/index');
Route::get('cashier/jiaoban', 'CashierStat/jiaoban');

// 行业应用 - 餐饮预定订座（批次6-T6-1 任务202：订单列表/详情/审核通过驳回/备注/关闭 + 预定设置）
Route::get('restaurant/booking/list', 'RestaurantBookingOrder/list');
Route::get('restaurant/booking/detail', 'RestaurantBookingOrder/detail');
Route::post('restaurant/booking/check', 'RestaurantBookingOrder/check');
Route::post('restaurant/booking/setremark', 'RestaurantBookingOrder/setremark');
Route::post('restaurant/booking/close', 'RestaurantBookingOrder/close');
Route::get('restaurant/booking/set', 'RestaurantBookingSet/index');
Route::post('restaurant/booking/set', 'RestaurantBookingSet/save');

// 行业应用 - 餐饮收银台二期（批次6-T6-6 任务207：开台/点餐加菜(含加料)/改价抹零/退菜/结账/反结账/统计；
// 支付口径沿用一期：1微信mock 3现金 4余额 100+自定义字典id；canzhao RestaurantCashdesk 缺失，自研）
Route::get('restaurant/cashdesk/desk', 'RestaurantCashdesk/desk');
Route::post('restaurant/cashdesk/open', 'RestaurantCashdesk/open');
Route::get('restaurant/cashdesk/categories', 'RestaurantCashdesk/categories');
Route::get('restaurant/cashdesk/products', 'RestaurantCashdesk/products');
Route::post('restaurant/cashdesk/addgoods', 'RestaurantCashdesk/addgoods');
Route::post('restaurant/cashdesk/changenum', 'RestaurantCashdesk/changenum');
Route::post('restaurant/cashdesk/changeprice', 'RestaurantCashdesk/changeprice');
Route::post('restaurant/cashdesk/removezero', 'RestaurantCashdesk/removezero');
Route::post('restaurant/cashdesk/refundgoods', 'RestaurantCashdesk/refundgoods');
Route::get('restaurant/cashdesk/orderdetail', 'RestaurantCashdesk/orderdetail');
Route::get('restaurant/cashdesk/orderlist', 'RestaurantCashdesk/orderlist');
Route::post('restaurant/cashdesk/pay', 'RestaurantCashdesk/pay');
Route::get('restaurant/cashdesk/paylist', 'RestaurantCashdesk/paylist');
Route::post('restaurant/cashdesk/reversepay', 'RestaurantCashdesk/reversepay');
Route::post('restaurant/cashdesk/reverseaudit', 'RestaurantCashdesk/reverseaudit');
Route::get('restaurant/cashdesk/config', 'RestaurantCashdesk/config');
Route::post('restaurant/cashdesk/saveconfig', 'RestaurantCashdesk/saveconfig');
Route::get('restaurant/cashdesk/customlist', 'RestaurantCashdesk/customlist');
Route::post('restaurant/cashdesk/customsave', 'RestaurantCashdesk/customsave');
Route::post('restaurant/cashdesk/customdelete', 'RestaurantCashdesk/customdelete');
Route::get('restaurant/cashdesk/stat', 'RestaurantCashdesk/stat');

// 公众号运营（批次7-2 任务210：菜单设置/关键字回复/被关注回复，OPEN_PLATFORM_MOCK=1 全链路 mock）
Route::get('mp/menu', 'MpMenu/index');
Route::post('mp/menu/save', 'MpMenu/save');
Route::post('mp/menu/sync', 'MpMenu/sync');
Route::post('mp/menu/fetch', 'MpMenu/fetch');
Route::post('mp/media/upload', 'MpMenu/uploadMedia');
Route::get('mp/material/newslist', 'MpMenu/newsList');
Route::get('mp/keyword/index', 'MpKeyword/index');
Route::post('mp/keyword/save', 'MpKeyword/save');
Route::post('mp/keyword/del', 'MpKeyword/del');
Route::get('mp/keyword/subscribe', 'MpKeyword/subscribe');
Route::post('mp/keyword/subscribeSave', 'MpKeyword/subscribeSave');
Route::post('mp/keyword/subscribeDel', 'MpKeyword/subscribeDel');

// 微信会员卡（批次7-6 任务214：大表单保存/同步微信card-create/审核状态card-get/领取记录/导出/送积分，OPEN_PLATFORM_MOCK=1）
Route::get('membercard/list', 'Membercard/list');
Route::get('membercard/detail', 'Membercard/detail');
Route::post('membercard/save', 'Membercard/save');
Route::post('membercard/sync', 'Membercard/sync');
Route::post('membercard/syncstatus', 'Membercard/syncStatus');
Route::post('membercard/del', 'Membercard/del');
Route::post('membercard/setremark', 'Membercard/setRemark');
Route::get('membercard/record', 'Membercard/record');
Route::get('membercard/recorddetail', 'Membercard/recordDetail');
Route::post('membercard/recorddel', 'Membercard/recordDel');
Route::get('membercard/recordexport', 'Membercard/recordExport');
Route::post('membercard/sendscore', 'Membercard/sendScore');

// 小程序直播（批次7-6 任务214：live_room CRUD + 推流二维码 getwxacodeunlimit mock 占位图）
Route::get('live/list', 'Live/list');
Route::get('live/detail', 'Live/detail');
Route::post('live/save', 'Live/save');
Route::post('live/setst', 'Live/setst');
Route::post('live/del', 'Live/del');
Route::post('live/getpusherqr', 'Live/getpusherqr');

// 微信消息 - 公众号模板消息（批次7-3 任务211：模板消息设置/类目模板消息/已添加的模板；
// 添加模板 apiAddTemplate（自动行业设置，45026/48001 错误分支）；对标 canzhao Mptmpl）
Route::get('mptmpl/tmplset', 'Mptmpl/tmplset');
Route::post('mptmpl/tmplset', 'Mptmpl/tmplset');
Route::get('mptmpl/tmplsetNew', 'Mptmpl/tmplsetNew');
Route::post('mptmpl/tmplsetNew', 'Mptmpl/tmplsetNew');
Route::post('mptmpl/getTmplId', 'Mptmpl/getTmplId');
Route::post('mptmpl/getTmplIdNew', 'Mptmpl/getTmplIdNew');
Route::get('mptmpl/mytmpl', 'Mptmpl/myTmpl');
Route::post('mptmpl/mytmplDel', 'Mptmpl/myTmplDel');

// 微信消息 - 小程序订阅消息（批次7-3 任务211：订阅消息设置主字段组+_new组独立保存；
// 获取模板标题 newTmplGetTitle / 添加 newTmplAddTemplate（45026/200012 超限分支）；对标 canzhao Wxtmpl）
Route::get('wxtmpl/tmplset', 'Wxtmpl/tmplset');
Route::post('wxtmpl/tmplset', 'Wxtmpl/tmplset');
Route::get('wxtmpl/getTitle', 'Wxtmpl/getTitle');
Route::post('wxtmpl/getTmplId', 'Wxtmpl/getTmplId');

// 微信代认证（批次7-8 任务216：小程序列表/认证订单全流程/三类代缴额度设置，OPEN_PLATFORM_MOCK=1 全链路 mock）
Route::get('wxproxyverify/index', 'WxProxyverify/index');
Route::post('wxproxyverify/authorizer/remark', 'WxProxyverify/authorizerRemark');
Route::post('wxproxyverify/authorizer/del', 'WxProxyverify/authorizerDel');
Route::get('wxproxyverify/callbackxcx', 'WxProxyverify/callbackxcx');
// detail 必须先于 order 注册（规则为前缀匹配，避免被 'wxproxyverify/order' 抢占）
Route::get('wxproxyverify/order/detail', 'WxProxyverify/orderDetail');
Route::get('wxproxyverify/order', 'WxProxyverify/order');
Route::post('wxproxyverify/order/save', 'WxProxyverify/orderSave');
Route::post('wxproxyverify/order/del', 'WxProxyverify/orderDel');
Route::post('wxproxyverify/order/remark', 'WxProxyverify/orderRemark');
Route::get('wxproxyverify/queryTaskStatus', 'WxProxyverify/queryTaskStatus');
Route::get('wxproxyverify/chooseidentity', 'WxProxyverify/chooseidentity');
Route::post('wxproxyverify/checkNickName', 'WxProxyverify/checkNickName');
Route::post('wxproxyverify/uploadauthmaterial', 'WxProxyverify/uploadauthmaterial');
Route::get('wxproxyverify/set', 'WxProxyverify/set');
Route::post('wxproxyverify/set', 'WxProxyverify/setSave');

// 公众号粉丝与群发（批次7-4 任务212：四步同步/标签CRUD/图文素材/模板群发/客服图文群发，对标参考系统 Mpfans）
Route::get('mpfans/fansList', 'Mpfans/fansList');
Route::post('mpfans/fansDelete', 'Mpfans/fansDelete');
Route::post('mpfans/setTag', 'Mpfans/setTag');
Route::post('mpfans/sync', 'Mpfans/sync');

Route::get('mpfans/tagsList', 'Mpfans/tagsList');
Route::post('mpfans/tagsSave', 'Mpfans/tagsSave');
Route::post('mpfans/tagsDelete', 'Mpfans/tagsDelete');
Route::post('mpfans/syncTags', 'Mpfans/syncTags');

Route::get('mpfans/sourceList', 'Mpfans/sourceList');
Route::get('mpfans/sourceDetail', 'Mpfans/sourceDetail');
Route::post('mpfans/sourceSave', 'Mpfans/sourceSave');
Route::post('mpfans/sourceDelete', 'Mpfans/sourceDelete');

Route::get('mpfans/tmplsendPrepare', 'Mpfans/tmplsendPrepare');
Route::post('mpfans/tmplSend', 'Mpfans/tmplSend');
Route::get('mpfans/tmplSendLog', 'Mpfans/tmplSendLog');
Route::post('mpfans/tmplSendLogDelete', 'Mpfans/tmplSendLogDelete');
Route::get('mpfans/tmplSendErrLog', 'Mpfans/tmplSendErrLog');

Route::post('mpfans/kfmsgSend', 'Mpfans/kfmsgSend');
Route::get('mpfans/kfmsgSendLog', 'Mpfans/kfmsgSendLog');
Route::post('mpfans/kfmsgSendLogDelete', 'Mpfans/kfmsgSendLogDelete');
Route::get('mpfans/kfmsgSendErrLog', 'Mpfans/kfmsgSendErrLog');

// 小程序扩展组（批次7-5 任务213）：服务类目 / 外部链接 / 半屏小程序 / 小程序客服关键字回复
Route::get('wxleimu/index', 'Wxleimu/index');
Route::get('wxleimu/options', 'Wxleimu/options');
Route::post('wxleimu/save', 'Wxleimu/save');
Route::post('wxleimu/del', 'Wxleimu/del');

Route::get('wxurl/index', 'Wxurl/index');
Route::post('wxurl/save', 'Wxurl/save');
Route::post('wxurl/del', 'Wxurl/del');

Route::get('wxembedded/index', 'Wxembedded/index');

Route::get('wxkeyword/index', 'WxKeyword/index');
Route::post('wxkeyword/save', 'WxKeyword/save');
Route::post('wxkeyword/del', 'WxKeyword/del');

// 同城配送整组（批次8 任务223：配送员/配送单/评价/提成明细/提现记录/系统设置/码科跑腿mock对接；
// 对标 canzhao Peisong 24方法 + PeisongUser/PeisongOrder/PeisongComment/PeisongMoney，.env PEISONG.MOCK=1）
Route::get('peisong/user/list', 'Peisong/userList');
Route::post('peisong/user/save', 'Peisong/userSave');
Route::post('peisong/user/setst', 'Peisong/userSetst');
Route::post('peisong/user/del', 'Peisong/userDel');
Route::get('peisong/user/options', 'Peisong/userOptions');

Route::get('peisong/order/list', 'Peisong/orderList');
Route::post('peisong/order/setst', 'Peisong/orderSetst');
Route::post('peisong/order/del', 'Peisong/orderDel');

Route::get('peisong/comment/list', 'Peisong/commentList');
Route::post('peisong/comment/detail', 'Peisong/commentDetail');
Route::post('peisong/comment/del', 'Peisong/commentDel');

Route::get('peisong/moneylog/list', 'Peisong/moneylogList');
Route::post('peisong/moneylog/del', 'Peisong/moneylogDel');

Route::get('peisong/withdrawlog/list', 'Peisong/withdrawlogList');
Route::post('peisong/withdrawlog/setst', 'Peisong/withdrawlogSetst');
Route::post('peisong/withdrawlog/del', 'Peisong/withdrawlogDel');

Route::get('peisong/set', 'Peisong/set');
Route::post('peisong/set', 'Peisong/setSave');
Route::get('peisong/makeset', 'Peisong/makeset');
Route::post('peisong/makeset', 'Peisong/makesetSave');
Route::get('peisong/getpeisonguser', 'Peisong/getpeisonguser');

// 行业应用 - 预约域批次8（任务222：商品服务/商品评价/人员类型/人员评价/提成明细/提现记录/系统设置；
// 海报走通用 poster 路由 type=yuyue；服务类目复用上方 yuyue/category 既有路由；商户视图用 bid 参数过滤）
Route::get('yuyue/fuwu/list', 'Yuyue/fuwuList');
Route::get('yuyue/fuwu/detail', 'Yuyue/fuwuDetail');
Route::post('yuyue/fuwu/save', 'Yuyue/fuwuSave');
Route::post('yuyue/fuwu/delete', 'Yuyue/fuwuDelete');

Route::get('yuyue/comment/list', 'Yuyue/commentList');
Route::get('yuyue/comment/detail', 'Yuyue/commentDetail');
Route::post('yuyue/comment/setst', 'Yuyue/commentSetStatus');
Route::post('yuyue/comment/reply', 'Yuyue/commentReply');
Route::post('yuyue/comment/delete', 'Yuyue/commentDelete');

Route::get('yuyue/workerCategory/list', 'Yuyue/workerCategoryList');
Route::post('yuyue/workerCategory/save', 'Yuyue/workerCategorySave');
Route::post('yuyue/workerCategory/setst', 'Yuyue/workerCategorySetStatus');
Route::post('yuyue/workerCategory/delete', 'Yuyue/workerCategoryDelete');

Route::get('yuyue/workerComment/list', 'Yuyue/workerCommentList');
Route::get('yuyue/workerComment/detail', 'Yuyue/workerCommentDetail');
Route::post('yuyue/workerComment/delete', 'Yuyue/workerCommentDelete');

Route::get('yuyue/moneylog/list', 'Yuyue/moneylogList');
Route::get('yuyue/moneylog/export', 'Yuyue/moneylogExport');
Route::post('yuyue/moneylog/delete', 'Yuyue/moneylogDelete');

Route::get('yuyue/withdrawlog/list', 'Yuyue/withdrawlogList');
Route::post('yuyue/withdrawlog/audit', 'Yuyue/withdrawlogAudit');
Route::post('yuyue/withdrawlog/pay', 'Yuyue/withdrawlogPay');
Route::post('yuyue/withdrawlog/delete', 'Yuyue/withdrawlogDelete');

Route::get('yuyue/set/config', 'Yuyue/setConfig');
Route::post('yuyue/set/save', 'Yuyue/setSave');

// 批次8 营销设计杂项整组（P24-7 任务227）：拼团分类/周期购评价/线下补助/活动日历/移动端后台/链接选择器
Route::get('collageCategory/list', 'CollageCategory/list');
Route::post('collageCategory/save', 'CollageCategory/save');
Route::post('collageCategory/delete', 'CollageCategory/delete');

Route::get('cycleComment/list', 'CycleComment/list');
Route::post('cycleComment/check', 'CycleComment/check');
Route::post('cycleComment/reply', 'CycleComment/reply');
Route::post('cycleComment/delete', 'CycleComment/delete');

Route::get('offlineSubsidies/set', 'OfflineSubsidies/setGet');
Route::post('offlineSubsidies/set', 'OfflineSubsidies/setSave');
Route::get('offlineSubsidies/log/list', 'OfflineSubsidies/logList');
Route::post('offlineSubsidies/log/delete', 'OfflineSubsidies/logDelete');

Route::get('huodongCalendar/get', 'HuodongCalendar/get');
Route::post('huodongCalendar/save', 'HuodongCalendar/save');

Route::get('designer/mobile/get', 'Designer/mobileGet');
Route::post('designer/mobile/save', 'Designer/mobileSave');
Route::get('designer/chooseurl', 'Designer/chooseUrl');

// 名片管理（批次8-5 任务225：名片列表含浏览/收藏统计/编辑/删除/浏览收藏记录/名片设置，对标参考系统 Mingpian）
Route::get('mingpian/list', 'Mingpian/list');
Route::get('mingpian/detail', 'Mingpian/detail');
Route::post('mingpian/save', 'Mingpian/save');
Route::post('mingpian/delete', 'Mingpian/delete');
Route::get('mingpian/set', 'Mingpian/set');
Route::post('mingpian/set', 'Mingpian/set');
Route::get('mingpian/readlog', 'Mingpian/readlog');
Route::get('mingpian/favoritelog', 'Mingpian/favoritelog');
Route::post('mingpian/logdelete', 'Mingpian/logdelete');

// 礼品卡管理（批次8-5 任务225：活动CRUD/兑换码列表/生成导入导出/批量改状态/设置/分类，对标参考系统 Lipin+LipinCategory）
Route::get('lipin/list', 'Lipin/list');
Route::get('lipin/detail', 'Lipin/detail');
Route::post('lipin/save', 'Lipin/save');
Route::post('lipin/delete', 'Lipin/delete');
Route::get('lipin/set', 'Lipin/set');
Route::post('lipin/set', 'Lipin/set');
Route::get('lipin/codelist', 'Lipin/codelist');
Route::post('lipin/makecode', 'Lipin/makecode');
Route::post('lipin/importexcel', 'Lipin/importexcel');
Route::get('lipin/codelistexcel', 'Lipin/codelistexcel');
Route::post('lipin/setst', 'Lipin/setst');
Route::post('lipin/codelistdel', 'Lipin/codelistdel');

Route::get('lipinCategory/list', 'LipinCategory/list');
Route::get('lipinCategory/parents', 'LipinCategory/parents');
Route::post('lipinCategory/save', 'LipinCategory/save');
Route::post('lipinCategory/delete', 'LipinCategory/delete');

// 自定义表单（批次8-5 任务225：form/form_order 双表，form-index 与 form-index-showtype-2 双挂同组件，对标参考系统 Form）
Route::get('form/list', 'Form/list');
Route::get('form/detail', 'Form/detail');
Route::post('form/save', 'Form/save');
Route::post('form/delete', 'Form/delete');
Route::post('form/copy', 'Form/copy');
Route::get('form/record', 'Form/record');
Route::get('form/recorddetail', 'Form/recorddetail');
Route::post('form/recordsetst', 'Form/recordsetst');
Route::post('form/recorddel', 'Form/recorddel');
Route::post('form/recordsave', 'Form/recordsave');
Route::get('form/recordexcel', 'Form/recordexcel');

// 视频管理（批次8-5 任务225：video_list 本地视频/微信视频号，对标参考系统 VideoList）
Route::get('videoList/list', 'VideoList/list');
Route::get('videoList/detail', 'VideoList/detail');
Route::post('videoList/save', 'VideoList/save');
Route::post('videoList/delete', 'VideoList/delete');

// 商户域整组（批次8-1 任务221：城市商户/商户分类/商户销量/余额明细/提现记录/通知公告/系统设置；
// 对标 canzhao Business.php + BusinessNotice.php + BusinessMoney.php；商户视图用 bid 参数过滤）
Route::get('businessCity/list', 'BusinessCity/list');
Route::post('businessCity/save', 'BusinessCity/save');
Route::post('businessCity/setst', 'BusinessCity/setst');
Route::post('businessCity/delete', 'BusinessCity/delete');

Route::get('businessCategory/list', 'BusinessCategory/list');
Route::get('businessCategory/options', 'BusinessCategory/options');
Route::post('businessCategory/save', 'BusinessCategory/save');
Route::post('businessCategory/delete', 'BusinessCategory/delete');

Route::get('businessSales/list', 'BusinessSales/list');
Route::post('businessSales/delete', 'BusinessSales/delete');

Route::get('businessMoneyLog/list', 'BusinessMoneyLog/list');
Route::post('businessMoneyLog/delete', 'BusinessMoneyLog/delete');

Route::get('businessWithdrawLog/list', 'BusinessWithdrawLog/list');
Route::post('businessWithdrawLog/setst', 'BusinessWithdrawLog/setst');
Route::post('businessWithdrawLog/delete', 'BusinessWithdrawLog/delete');

Route::get('businessNotice/list', 'BusinessNotice/list');
Route::get('businessNotice/detail', 'BusinessNotice/detail');
Route::post('businessNotice/save', 'BusinessNotice/save');
Route::post('businessNotice/delete', 'BusinessNotice/delete');
Route::get('businessNotice/receivers', 'BusinessNotice/receivers');
Route::post('businessNotice/setst', 'BusinessNotice/setst');

Route::get('businessSysset/get', 'BusinessSysset/get');
Route::post('businessSysset/save', 'BusinessSysset/save');

// 多商户默认底部导航（批次8-1 任务221：designer_menu_business 八端分行，对标参考系统 DesignerMenu business/businesssave）
Route::get('designer/business/get', 'Designer/businessGet');
Route::post('designer/business/save', 'Designer/businessSave');

// 物流面单组（批次8 任务224：物流助手/面单记录/面单设置，快递鸟全链路 EWAYBILL_MOCK=1 mock）
Route::get('miandan/list', 'Miandan/list');
Route::get('miandan/set', 'Miandan/set');
Route::post('miandan/set', 'Miandan/setSave');
Route::post('miandan/getOrder', 'Miandan/getOrder');
Route::post('miandan/getPath', 'Miandan/getPath');
Route::post('miandan/cancel', 'Miandan/cancel');
Route::post('miandan/del', 'Miandan/del');
Route::get('miandan/deliveryList', 'Miandan/deliveryList');

Route::get('ewaybill/list', 'Ewaybill/list');
Route::get('ewaybill/detail', 'Ewaybill/detail');
Route::get('ewaybill/prepare', 'Ewaybill/prepare');
Route::post('ewaybill/add', 'Ewaybill/add');
Route::post('ewaybill/cancel', 'Ewaybill/cancel');
Route::post('ewaybill/del', 'Ewaybill/del');
Route::post('ewaybill/print', 'Ewaybill/printOrder');

Route::get('ewaybillSet/index', 'EwaybillSet/index');
Route::post('ewaybillSet/save', 'EwaybillSet/save');
Route::get('ewaybillSet/expressDict', 'EwaybillSet/expressDict');
Route::get('ewaybillSet/accountList', 'EwaybillSet/accountList');
Route::post('ewaybillSet/accountSave', 'EwaybillSet/accountSave');
Route::post('ewaybillSet/accountDel', 'EwaybillSet/accountDel');

// 餐饮余量整组（批次9 任务232：经营概览/菜品采集/外卖评价/点餐评价/配送方式/外卖设置/
// 餐饮优惠券/餐饮促销/餐饮统计/餐饮设置；区域打印走上方 dining/printer 既有路由）
Route::get('restaurant/index/overview', 'RestaurantIndex/overview');
Route::get('restaurant/index/chart', 'RestaurantIndex/chart');

Route::get('restaurant/taobao/config', 'RestaurantTaobao/config');
Route::post('restaurant/taobao/saveKey', 'RestaurantTaobao/saveKey');
Route::post('restaurant/taobao/collect', 'RestaurantTaobao/collect');

Route::get('restaurant/takeawayComment/list', 'RestaurantTakeawayComment/list');
Route::post('restaurant/takeawayComment/setst', 'RestaurantTakeawayComment/setst');
Route::get('restaurant/takeawayComment/detail', 'RestaurantTakeawayComment/detail');
Route::post('restaurant/takeawayComment/reply', 'RestaurantTakeawayComment/reply');
Route::post('restaurant/takeawayComment/del', 'RestaurantTakeawayComment/del');

Route::get('restaurant/shopComment/list', 'RestaurantShopComment/list');
Route::post('restaurant/shopComment/setst', 'RestaurantShopComment/setst');
Route::get('restaurant/shopComment/detail', 'RestaurantShopComment/detail');
Route::post('restaurant/shopComment/reply', 'RestaurantShopComment/reply');
Route::post('restaurant/shopComment/del', 'RestaurantShopComment/del');

Route::get('restaurant/takeawayFreight/list', 'RestaurantTakeawayFreight/list');
Route::get('restaurant/takeawayFreight/detail', 'RestaurantTakeawayFreight/detail');
Route::post('restaurant/takeawayFreight/save', 'RestaurantTakeawayFreight/save');
Route::post('restaurant/takeawayFreight/del', 'RestaurantTakeawayFreight/del');

Route::get('restaurant/takeawaySet/index', 'RestaurantTakeawaySet/index');
Route::post('restaurant/takeawaySet/save', 'RestaurantTakeawaySet/save');

Route::get('restaurant/coupon/list', 'RestaurantCoupon/list');
Route::get('restaurant/coupon/detail', 'RestaurantCoupon/detail');
Route::post('restaurant/coupon/save', 'RestaurantCoupon/save');
Route::post('restaurant/coupon/del', 'RestaurantCoupon/del');
Route::post('restaurant/coupon/copy', 'RestaurantCoupon/copy');
Route::get('restaurant/coupon/record', 'RestaurantCoupon/record');
Route::post('restaurant/coupon/recordsetst', 'RestaurantCoupon/recordsetst');
Route::post('restaurant/coupon/recorddel', 'RestaurantCoupon/recorddel');
Route::post('restaurant/coupon/send', 'RestaurantCoupon/send');

Route::get('restaurant/cuxiao/list', 'RestaurantCuxiao/list');
Route::get('restaurant/cuxiao/detail', 'RestaurantCuxiao/detail');
Route::post('restaurant/cuxiao/save', 'RestaurantCuxiao/save');
Route::post('restaurant/cuxiao/del', 'RestaurantCuxiao/del');

Route::get('restaurant/tongji/list', 'RestaurantTongji/list');
Route::get('restaurant/tongji/overview', 'RestaurantTongji/overview');

Route::get('restaurant/sysset/index', 'RestaurantSysset/index');
Route::post('restaurant/sysset/save', 'RestaurantSysset/save');

// ==================== 任务250（P27-6）：模板库深化与选项卡后端 ====================
// 存为模板/删模板（aid 租户隔离，平台模板 aid=0 禁删）+ tab 组件选项子内容读写
Route::post('designer/template/save', 'Designer/templateSave');
Route::post('designer/template/del', 'Designer/templateDel');
Route::post('designer/template/sync', 'Designer/templateSync');
Route::get('designer/tab/get', 'Designer/tabGet');
Route::post('designer/tab/save', 'Designer/tabSave');

// ==================== 任务256：租户附件设置（存储配置读写，字段口径对齐超管 AttachSetting） ====================
Route::get('attachSet/index', 'AttachSet/index');
Route::post('attachSet/save', 'AttachSet/save');

// 兜底：等效于该应用强制路由
Route::miss(function () {
    return json(Result::error('接口不存在', 404), 404);
});
