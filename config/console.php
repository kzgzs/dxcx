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
    ],
];
