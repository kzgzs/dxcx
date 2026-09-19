<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 音乐库平台级口径归一（任务315）
 *
 * - admin_music 以 aid=0 为平台级：音乐库由超管控制台维护，租户设计编辑器透传消费
 * - 存量开发期数据统一归并 aid=0（幂等：重复执行无副作用）
 */
class Task315AdminMusicPlatform extends Migrator
{
    public function up(): void
    {
        $this->execute('UPDATE `admin_music` SET `aid` = 0 WHERE `aid` <> 0');
    }
}
