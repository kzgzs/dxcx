<?php

// 统一时区：兼容 think run / CLI 未执行 App::init 的场景（date()/strtotime 与 +8 业务口径对齐）
date_default_timezone_set('Asia/Shanghai');

// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 默认应用
    'default_app'      => env('APP.DEFAULT_APP', 'console'),
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 应用映射（自动多应用模式有效）
    'app_map'          => [],
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => ['common'],
    // 开启应用快速匹配：首段不是应用目录时回退默认应用（计划任务 URL /ApiAuto/index 由此命中 console）
    'app_express'      => true,

    // 异常页面的模板文件
    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'   => false,
];
