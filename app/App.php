<?php

declare(strict_types=1);

namespace app;

use think\App as ThinkApp;

/**
 * 应用容器扩展
 *
 * 覆写 .env 加载逻辑：支持行内注释（KEY=value # 注释），
 * 框架原生 parse_ini_file 不支持该语法，此处先剥离行内注释再解析。
 */
class App extends ThinkApp
{
    /**
     * 加载环境变量文件（支持行内 # 注释）
     */
    public function loadEnv(string $envName = ''): void
    {
        $envFile = $envName ? $this->rootPath . '.env.' . $envName : $this->rootPath . '.env';

        if (!is_file($envFile)) {
            return;
        }

        $content = (string) file_get_contents($envFile);

        // 剥离行内注释：# 前必须存在空白字符，避免误伤值中的 #
        $content = (string) preg_replace('/\h+#.*$/m', '', $content);

        $env = parse_ini_string($content, true, INI_SCANNER_RAW) ?: [];

        $this->env->set($env);
    }
}
