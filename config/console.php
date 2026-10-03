<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'order:cancel' => \app\command\OrderCancel::class, // 超时未支付订单自动取消+回补库存（幂等）
        'schedule:run' => \app\command\ScheduleRun::class, // 统一调度（Redis 锁防并发；Windows 计划任务每分钟触发）
        'pay:reconcile' => \app\command\PayReconcile::class, // 支付查单补偿（对账捞回，幂等）
        'data:migrate' => \app\command\DataMigrate::class, // 旧系统数据迁移（列交集分批拷贝，幂等）
        'demo:products' => \app\command\DemoProducts::class, // 生成7个营销模块示例商品（平台自营+商户各2个，幂等；--purge 清除；部署迁移也会自动生成）
        'demo:articles' => \app\command\DemoArticles::class, // 生成商户文章列表演示文章（幂等，--purge 清除；部署迁移也会自动生成）
        'demo:shortvideos' => \app\command\DemoShortvideos::class, // 生成商户短视频列表演示视频（幂等，--purge 清除；部署迁移也会自动生成）
        'demo:shortvideo' => \app\command\DemoShortvideo::class, // 生成平台「营销→短视频」演示数据（分类/视频三态/评论/回评，DEMO-SV- 前缀，--purge 清除）
        'demo:forms' => \app\command\DemoForms::class, // 生成商户自定义表表演示表单与提交数据（幂等，--purge 清除；部署迁移也会自动生成）
        'demo:kanjia' => \app\command\DemoKanjia::class, // 生成砍价活动订单管理演示数据（4种状态行操作差异可见，幂等，--purge 清除）
        'demo:seckill' => \app\command\DemoSeckill::class, // 生成秒杀活动订单管理演示数据（4种状态行操作差异可见，幂等，--purge 清除）
        'demo:tuangou' => \app\command\DemoTuangou::class, // 生成团购活动订单管理演示数据（4种状态行操作差异可见，自提+快递，--purge 清除）
        'demo:yuyue' => \app\command\DemoYuyue::class, // 生成租户后台「扩展·预约服务」演示数据（类目/商品/技师/订单全状态/派单/提成/提现/评价，DEMO-YY- 前缀，--purge 清除）
        'demo:kecheng' => \app\command\DemoKecheng::class, // 生成租户后台「扩展·知识付费」演示数据（分类/课程四类型/章节/题库/答题/订单全状态/学习记录/讲师三态，DEMO-KC- 前缀，--purge 清除）
        'demo:peisong' => \app\command\DemoPeisong::class, // 生成租户后台「扩展·同城配送」演示数据（配送员三态/配送单0待接单1已接单2已到店3已取货4已完成10已取消/评价/提成/提现四态，DEMO-PS- 前缀，--purge 清除）
        'demo:scoreshop' => \app\command\DemoScoreshop::class, // 生成积分兑换订单管理演示数据（5种状态+自提/快递/同城配送，--purge 清除）
        'demo:lottery' => \app\command\DemoLottery::class, // 生成抽奖活动（大转盘+刮刮卡）演示数据，记录覆盖未领取/已领取/未中奖，--purge 清除
        'demo:confirmreward' => \app\command\DemoConfirmReward::class, // 生成租户后台「确认收货奖励」演示数据（设置+12条记录，DEMO-CR- 前缀，--purge 清除）
        'demo:miandan' => \app\command\DemoMiandan::class, // 生成租户后台「扩展·物流助手」演示数据（运单正常/已取消/物流账号/打印员，DEMO-WL- 前缀，--purge 清除）
    ],
];
