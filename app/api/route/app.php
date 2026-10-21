<?php

declare(strict_types=1);

use think\facade\Route;

// api 应用默认关闭强制路由：未显式定义的路由走 控制器/方法 自动解析
// 此处仅登记需要自定义规则的路由

Route::get('index/home', 'Index/home');
Route::get('index$', 'Index/index');

// 会员会话（鉴权：role=member，白名单见 AuthMiddleware）
Route::post('member/login', 'Member/login');
Route::post('member/wxlogin', 'Member/wxlogin');
Route::post('member/sendsms', 'Member/sendsms');
Route::post('member/smslogin', 'Member/smslogin');
Route::get('member/profile', 'Member/profile');
Route::get('member/score', 'Member/score');

// 购物车（鉴权：role=member，aid/mid 取自 token）
Route::get('cart/list', 'Cart/list');
Route::post('cart/add', 'Cart/add');
Route::post('cart/update', 'Cart/update');
Route::post('cart/delete', 'Cart/delete');
Route::post('cart/selectAll', 'Cart/selectAll');

// 通用图片上传（鉴权：role=member，multipart 字段 file）
Route::post('upload', 'Upload/index');

// 商品（本期不鉴权，白名单见 AuthMiddleware，aid 走 query）
Route::get('goods/category/list', 'Goods/categoryList');
Route::get('goods/list', 'Goods/list');
Route::get('goods/detail', 'Goods/detail');

// 满减促销（匿名，白名单见 AuthMiddleware，aid 走 query）
Route::get('promotion/list', 'Promotion/list');

// 网约车（匿名：list/detail，白名单见 AuthMiddleware；订单接口鉴权 role=member）
Route::get('carhailing/list', 'Carhailing/list');
Route::get('carhailing/detail', 'Carhailing/detail');
Route::post('carhailing/order/create', 'Carhailing/orderCreate');
Route::get('carhailing/order/list', 'Carhailing/orderList');
Route::post('carhailing/order/pay', 'Carhailing/orderPay');
Route::post('carhailing/order/cancel', 'Carhailing/orderCancel');
Route::post('carhailing/order/refund', 'Carhailing/orderRefund');

// 酒店（匿名：list/detail/room/available，白名单见 AuthMiddleware；订单接口鉴权 role=member）
Route::get('hotel/list', 'Hotel/list');
Route::get('hotel/detail', 'Hotel/detail');
Route::get('hotel/room/available', 'Hotel/roomAvailable');
Route::post('hotel/order/create', 'Hotel/orderCreate');
Route::get('hotel/order/list', 'Hotel/orderList');
Route::post('hotel/order/pay', 'Hotel/orderPay');
Route::post('hotel/order/cancel', 'Hotel/orderCancel');
Route::post('hotel/order/refund', 'Hotel/orderRefund');

// 预约（匿名：product/list、product/detail、worker/list、slot，白名单见 AuthMiddleware；订单接口鉴权 role=member）
Route::get('yuyue/product/list', 'Yuyue/productList');
Route::get('yuyue/product/detail', 'Yuyue/productDetail');
Route::get('yuyue/worker/list', 'Yuyue/workerList');
Route::get('yuyue/slot', 'Yuyue/slot');
Route::post('yuyue/order/create', 'Yuyue/orderCreate');
Route::get('yuyue/order/list', 'Yuyue/orderList');
Route::post('yuyue/order/pay', 'Yuyue/orderPay');
Route::post('yuyue/order/refund', 'Yuyue/orderRefund');

// 餐饮堂食（匿名：info、product/list，白名单见 AuthMiddleware；订单接口鉴权 role=member）
Route::get('restaurant/info', 'Restaurant/info');
Route::get('restaurant/product/list', 'Restaurant/productList');
Route::post('restaurant/order/create', 'Restaurant/orderCreate');
Route::get('restaurant/order/list', 'Restaurant/orderList');
Route::post('restaurant/order/pay', 'Restaurant/orderPay');
Route::post('restaurant/order/cancel', 'Restaurant/orderCancel');
Route::post('restaurant/order/complete', 'Restaurant/orderComplete');

// 餐饮外卖（匿名：info，白名单见 AuthMiddleware；订单接口鉴权 role=member）
Route::get('restaurantTakeaway/info', 'RestaurantTakeaway/info');
Route::post('restaurantTakeaway/order/create', 'RestaurantTakeaway/orderCreate');
Route::get('restaurantTakeaway/order/list', 'RestaurantTakeaway/orderList');
Route::get('restaurantTakeaway/order/detail', 'RestaurantTakeaway/orderDetail');
Route::post('restaurantTakeaway/order/pay', 'RestaurantTakeaway/orderPay');
Route::post('restaurantTakeaway/order/cancel', 'RestaurantTakeaway/orderCancel');
Route::post('restaurantTakeaway/order/complete', 'RestaurantTakeaway/orderComplete');
Route::post('restaurantTakeaway/order/refund', 'RestaurantTakeaway/orderRefund');

// 自定义表单（匿名：detail，白名单见 AuthMiddleware；提交/支付/我的记录鉴权 role=member）
Route::get('form/detail', 'Form/detail');
Route::post('form/submit', 'Form/submit');
Route::post('form/pay', 'Form/pay');
Route::get('form/mydata', 'Form/mydata');

// 会员收货地址（鉴权：role=member）
Route::post('address/add', 'Address/add');
Route::post('address/edit', 'Address/edit');
Route::post('address/delete', 'Address/delete');
Route::get('address/list', 'Address/list');

// 订单（鉴权：role=member，mid/aid 取自 token）
Route::post('order/create', 'Order/create');
Route::get('order/list', 'Order/list');
Route::get('order/detail', 'Order/detail');
Route::post('order/cancel', 'Order/cancel');
Route::post('order/pay', 'Order/pay');
Route::post('order/confirm', 'Order/confirm');
Route::post('order/refund', 'Order/refund');

// 退款单（鉴权：role=member）
Route::get('refund/list', 'Refund/list');

// 优惠券（鉴权：role=member，aid/mid 取自 token）
Route::get('coupon/list', 'Coupon/list');
Route::post('coupon/receive', 'Coupon/receive');
Route::get('coupon/mine', 'Coupon/mine');

// 积分（鉴权：role=member）
Route::get('score/log', 'Score/log');

// 支付回调（白名单免 JWT；mock 明文幂等 / 真实 V3 验签+解密）
Route::post('pay/notify/wechat', 'Pay/notifyWechat');
Route::post('pay/notify/wechatRefund', 'Pay/notifyWechatRefund');
Route::post('pay/notify/alipay', 'Pay/notifyAlipay');
Route::post('pay/notify/mock', 'Pay/mock');

// C 端设计器消费与系统配置下发（匿名白名单见 AuthMiddleware，aid 走 TenantMiddleware）
Route::get('designer/page', 'DesignerC/page');
Route::get('designer/tab/get', 'DesignerC/tabGet');
Route::get('designer/template', 'DesignerC/template');
Route::get('designer/login', 'DesignerC/login');
// 付费查看下单/支付（登录；非白名单，任务305）
Route::post('designer/pagebuy', 'DesignerC/pagebuy');
Route::post('designer/pagepay', 'DesignerC/pagepay');
Route::get('index/sysset', 'Index/sysset');
// 最近购买记录（订单播报滚动条数据源，匿名白名单，任务308）
Route::get('index/recentOrders', 'Index/recentOrders');

// 营销 - 签到（鉴权：role=member，aid/mid 取自 token）
Route::post('sign/signin', 'Sign/signin');
Route::get('sign/page', 'Sign/page');
Route::get('sign/records', 'Sign/records');

// 营销 - 拼团（鉴权：role=member）
Route::get('collage/product/list', 'Collage/productList');
Route::get('collage/product/detail', 'Collage/productDetail');
Route::get('collage/team/list', 'Collage/teamList');
Route::get('collage/team/detail', 'Collage/teamDetail');
Route::post('collage/order/create', 'Collage/orderCreate');
Route::get('collage/order/list', 'Collage/myOrders');

// 营销 - 幸运拼团（鉴权：role=member；开奖由支付入账满员触发）
Route::get('luckyCollage/product/list', 'LuckyCollage/productList');
Route::get('luckyCollage/product/detail', 'LuckyCollage/productDetail');
Route::get('luckyCollage/team/list', 'LuckyCollage/teamList');
Route::get('luckyCollage/team/detail', 'LuckyCollage/teamDetail');
Route::post('luckyCollage/order/create', 'LuckyCollage/orderCreate');
Route::get('luckyCollage/order/list', 'LuckyCollage/myOrders');
Route::get('luckyCollage/order/drawResult', 'LuckyCollage/drawResult');

// 团购（C 端：分类/商品阶梯价/下单/订单/退款/收货/评价/核销码；任务187）
Route::get('tuangou/set', 'Tuangou/setGet');
Route::get('tuangou/category/list', 'Tuangou/categoryList');
Route::get('tuangou/product/list', 'Tuangou/productList');
Route::get('tuangou/product/detail', 'Tuangou/productDetail');
Route::post('tuangou/order/create', 'Tuangou/orderCreate');
Route::get('tuangou/order/list', 'Tuangou/myOrders');
Route::get('tuangou/order/detail', 'Tuangou/orderDetail');
Route::get('tuangou/order/hexiaoCode', 'Tuangou/orderHexiaoCode');
Route::post('tuangou/order/refund', 'Tuangou/orderRefund');
Route::post('tuangou/order/collect', 'Tuangou/orderCollect');
Route::post('tuangou/order/delete', 'Tuangou/orderDelete');
Route::post('tuangou/comment/create', 'Tuangou/commentCreate');

// 营销 - 秒杀（鉴权：role=member）
Route::get('seckill/product/list', 'Seckill/productList');
Route::get('seckill/product/detail', 'Seckill/productDetail');
Route::post('seckill/order/create', 'Seckill/orderCreate');
Route::get('seckill/order/list', 'Seckill/myOrders');

// 营销 - 积分商城（鉴权：role=member）
Route::get('scoreshop/product/list', 'Scoreshop/productList');
Route::get('scoreshop/product/detail', 'Scoreshop/productDetail');
Route::post('scoreshop/order/create', 'Scoreshop/orderCreate');
Route::get('scoreshop/order/list', 'Scoreshop/myOrders');

// 营销 - 砍价（鉴权：role=member）
Route::get('kanjia/product/list', 'Kanjia/productList');
Route::get('kanjia/product/detail', 'Kanjia/productDetail');
Route::post('kanjia/join/start', 'Kanjia/startJoin');
Route::post('kanjia/join/help', 'Kanjia/helpCut');
Route::get('kanjia/join/list', 'Kanjia/joinList');
Route::get('kanjia/join/helpList', 'Kanjia/helpList');
Route::post('kanjia/order/create', 'Kanjia/orderCreate');
Route::get('kanjia/order/list', 'Kanjia/myOrders');

// 营销 - 抽奖（鉴权：role=member）
Route::get('choujiang/detail', 'Choujiang/detail');
Route::get('choujiang/leftTimes', 'Choujiang/leftTimes');
Route::post('choujiang/draw', 'Choujiang/draw');
Route::post('choujiang/share', 'Choujiang/share');
Route::get('choujiang/records', 'Choujiang/records');
Route::post('choujiang/claim', 'Choujiang/claim');

// 营销 - 余额宝（鉴权：role=member，aid/mid 取自 token；任务181）
Route::get('yuebao/info', 'Yuebao/info');
Route::get('yuebao/log', 'Yuebao/log');
Route::post('yuebao/withdraw', 'Yuebao/withdraw');
Route::post('yuebao/turnMoney', 'Yuebao/turnMoney');

// 营销 - 口令/谜语游戏（鉴权：role=member；批次4-E 任务190）
Route::get('kouling/set', 'Kouling/set');
Route::post('kouling/exchange', 'Kouling/exchange');
Route::get('kouling/riddle', 'Kouling/riddle');
Route::post('kouling/riddleSubmit', 'Kouling/riddleSubmit');

// 营销 - 微信红包（鉴权：role=member；批次4-E 任务190）
Route::get('hongbao/detail', 'Hongbao/detail');
Route::post('hongbao/receive', 'Hongbao/receive');

// 营销 - Fifa 竞猜（鉴权：role=member；批次4-E 任务190）
Route::get('fifa/index', 'Fifa/index');
Route::get('fifa/detail', 'Fifa/detail');
Route::post('fifa/subguess', 'Fifa/subguess');

// 会员 - 编辑个人资料（批次4-A 任务186：四字段齐全触发完善资料送分）
Route::post('member/editProfile', 'Member/editProfile');

// 营销 - 商品促销 cuxiao（匿名，白名单见 AuthMiddleware；批次4-A 任务186）
Route::get('cuxiao/list', 'Cuxiao/list');

// 营销 - 充值（鉴权：role=member；批次4-A 任务186，支付成功匹配最高档发放赠送）
Route::get('recharge/giveset', 'Recharge/giveset');
Route::post('recharge/create', 'Recharge/create');
Route::post('recharge/pay', 'Recharge/pay');
Route::get('recharge/orders', 'Recharge/orders');

// 知识付费 - 课程与交易（匿名：list/detail/category3/downclist3/classify，白名单见 AuthMiddleware；
// 其余鉴权 role=member；批次5-D1 任务197；考试类接口留 D2）
Route::get('kecheng/list', 'Kecheng/getlist');
Route::get('kecheng/mulu', 'Kecheng/getmululist');
Route::get('kecheng/category3', 'Kecheng/category3');
Route::get('kecheng/downclist3', 'Kecheng/getdownclist3');
Route::get('kecheng/classify', 'Kecheng/getClassifyKecheng');
Route::get('kecheng/detail', 'Kecheng/detail');
Route::rule('kecheng/mldetail', 'Kecheng/mldetail', 'GET|POST');
Route::get('kecheng/getposter', 'Kecheng/getposter');
Route::post('kecheng/order/create', 'Kecheng/createOrder');
Route::post('kecheng/order/pay', 'Kecheng/orderPay');
Route::get('kecheng/order/list', 'Kecheng/orderlist');
Route::post('kecheng/study/add', 'Kecheng/addstudy');
Route::get('kecheng/study/nextsection', 'Kecheng/nextsection');
Route::post('kecheng/givescore', 'Kecheng/givescore');

// 内容 - 短视频（鉴权：role=member；批次5-C 任务196）
Route::get('shortvideo/index', 'Shortvideo/index');
Route::get('shortvideo/detail', 'Shortvideo/detail');
Route::post('shortvideo/updateviewnum', 'Shortvideo/updateviewnum');
Route::get('shortvideo/getcommentlist', 'Shortvideo/getcommentlist');
Route::post('shortvideo/zan', 'Shortvideo/zan');
Route::post('shortvideo/subpinglun', 'Shortvideo/subpinglun');
Route::post('shortvideo/pzan', 'Shortvideo/pzan');
Route::post('shortvideo/addsharenum', 'Shortvideo/addsharenum');
Route::get('shortvideo/getprolist', 'Shortvideo/getprolist');
Route::get('shortvideo/uploadvideo', 'Shortvideo/uploadvideo');
Route::post('shortvideo/uploadvideo', 'Shortvideo/uploadvideo');
Route::get('shortvideo/myupload', 'Shortvideo/myupload');
Route::post('shortvideo/myuploadsetst', 'Shortvideo/myuploadsetst');
Route::post('shortvideo/myuploaddel', 'Shortvideo/myuploaddel');
Route::post('shortvideo/playEnd', 'Shortvideo/playEnd');

// 内容 - 文章（匿名：getartlist/detail 白名单见 AuthMiddleware；其余鉴权 role=member；批次5-A 任务194）
Route::get('article/getartlist', 'Article/getartlist');
Route::get('article/detail', 'Article/detail');
Route::get('article/getpllist', 'Article/getpllist');
Route::post('article/zan', 'Article/zan');
Route::post('article/subpinglun', 'Article/subpinglun');
Route::post('article/pzan', 'Article/pzan');
Route::post('article/reward', 'Article/reward');
Route::post('article/giveScorenum', 'Article/giveScorenum');
Route::post('article/saveResource', 'Article/saveResource');
Route::get('article/getResourceList', 'Article/getResourceList');
Route::get('article/getFujian', 'Article/getFujian');

// Content - Luntan forum (auth: role=member; batch 5-B task 195)
Route::get('luntan/index', 'Luntan/index');
Route::get('luntan/ltlist', 'Luntan/ltlist');
Route::get('luntan/detail', 'Luntan/detail');
Route::get('luntan/getprolist', 'Luntan/getprolist');
Route::get('luntan/fatieForm', 'Luntan/fatieForm');
Route::get('luntan/getform', 'Luntan/getform');
Route::post('luntan/fatie', 'Luntan/fatie');
Route::post('luntan/payTop', 'Luntan/payTop');
Route::get('luntan/fatielog', 'Luntan/fatielog');
Route::post('luntan/zan', 'Luntan/zan');
Route::post('luntan/subpinglun', 'Luntan/subpinglun');
Route::post('luntan/pzan', 'Luntan/pzan');
Route::post('luntan/deltie', 'Luntan/deltie');
Route::post('luntan/delpinglun', 'Luntan/delpinglun');
Route::post('luntan/delplreply', 'Luntan/delplreply');

// 知识付费 - 题库与考试（鉴权：role=member；批次5-D2 任务198）
Route::post('kecheng/exam/getTiku', 'Kecheng/getTiku');
Route::post('kecheng/exam/prevquestion', 'Kecheng/prevquestion');
Route::post('kecheng/exam/nextquestion', 'Kecheng/nextquestion');
Route::post('kecheng/exam/tofinish', 'Kecheng/tofinish');
Route::get('kecheng/exam/complete', 'Kecheng/complete');
Route::get('kecheng/exam/recordlog', 'Kecheng/recordlog');
Route::get('kecheng/exam/error', 'Kecheng/examError');
Route::get('kecheng/exam/geterrotiku', 'Kecheng/geterrotiku');

// 知识付费 - 讲师体系（鉴权：role=member；kecheng_sysset.lecturer_open 开关；批次5-D2 任务198）
Route::get('kecheng/lecturer/applyInfo', 'Kecheng/lecturerapplyinfo');
Route::post('kecheng/lecturer/apply', 'Kecheng/lecturerapply');
Route::get('kecheng/lecturer/center', 'Kecheng/lecturercenter');
Route::post('kecheng/lecturer/editkecheng', 'Kecheng/lecturerEditkecheng');
Route::get('kecheng/lecturer/list', 'Kecheng/lecturerlist');

// 餐饮 - 排队叫号（批次6-T6-2 任务203：index/quhao/cancel/record 鉴权 role=member；
// getqueuelist 大屏轮询匿名，白名单见 AuthMiddleware，aid 走 query）
Route::get('restaurantQueue/index', 'RestaurantQueue/index');
Route::post('restaurantQueue/quhao', 'RestaurantQueue/quhao');
Route::post('restaurantQueue/cancel', 'RestaurantQueue/cancel');
Route::get('restaurantQueue/record', 'RestaurantQueue/record');
Route::get('restaurantQueue/getqueuelist', 'RestaurantQueue/getqueuelist');

// 餐饮 - 寄存（批次6-T6-3 任务204，鉴权：role=member；无取件码，取件凭登录身份）
Route::get('restaurantDeposit/orderlist', 'RestaurantDeposit/orderlist');
Route::get('restaurantDeposit/orderdetail', 'RestaurantDeposit/orderdetail');
Route::get('restaurantDeposit/orderlog', 'RestaurantDeposit/orderlog');
Route::post('restaurantDeposit/add', 'RestaurantDeposit/add');
Route::post('restaurantDeposit/takeout', 'RestaurantDeposit/takeout');

// 餐饮 - 预定订座（批次6-T6-1 任务202：add GET 时段匿名，白名单见 AuthMiddleware；
// 下单/订单/取消/支付鉴权 role=member，memberContext 内守卫）
Route::get('restaurant/booking/add', 'RestaurantBooking/add');
Route::post('restaurant/booking/add', 'RestaurantBooking/add');
Route::get('restaurant/booking/tableList', 'RestaurantBooking/tableList');
Route::get('restaurant/booking/tableCategory', 'RestaurantBooking/tableCategory');
Route::get('restaurant/booking/orderlist', 'RestaurantBooking/orderlist');
Route::get('restaurant/booking/detail', 'RestaurantBooking/detail');
Route::post('restaurant/booking/del', 'RestaurantBooking/del');
Route::post('restaurant/booking/pay', 'RestaurantBooking/pay');

// Mini-program external link landing redirect (batch 7-5 task 213): reverse-lookup wx_url by code and 302, no login required
Route::get('wxurl/go', 'Wxurl/go');

// ============================================================
// 设计器组件统一数据接口（P27-3 任务247）
// 匿名白名单见 AuthMiddleware：designercomponent/fetch，aid 走 TenantMiddleware
// temp 支持：product/collage/luckycollage/seckill/kanjia/tuangou/scoreshop/yuyue/kecheng/restaurant/article
// 参数：temp/productfrom/cid/group/ids/limit/sortby（契约与 B 端对齐）
// ============================================================
Route::get('designerComponent/fetch', 'DesignerComponent/fetch');

// ============================================================
// 手机端管理后端（任务270）
// 免 JWT 白名单见 AuthMiddleware（api 应用要求 role=member，本模块自行
// 校验 header/query token → cache adminidx_{token}，无效返回 code=401）
// ============================================================
Route::post('adminidx/login', 'AdminIndex/login');
Route::post('adminidx/autologin', 'AdminIndex/autologin');
Route::get('adminidx/stats', 'AdminIndex/stats');
Route::get('adminidx/orderlist', 'AdminIndex/orderlist');
Route::get('adminidx/productlist', 'AdminIndex/productlist');
Route::post('adminidx/productstatus', 'AdminIndex/productstatus');
Route::get('adminidx/memberlist', 'AdminIndex/memberlist');
Route::get('adminidx/finances', 'AdminIndex/finances');
Route::post('adminidx/setpwd', 'AdminIndex/setpwd');
