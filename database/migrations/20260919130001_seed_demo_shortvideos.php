<?php
// 演示短视频种子（幂等，可重复执行）：
//   部署执行 `php think migrate:run` 时自动生成商户「短视频列表」的演示视频与商户短视频分类，
//   避免线上忘记手动执行 `php think demo:shortvideos` 导致客户端 → 商户 → 短视频列表空白。
//   逻辑全部复用 app\common\service\DemoShortvideoGenerator（与 demo:shortvideos 命令同一份）：
//     - 先把历史平台短视频/分类的 bid NULL 规范化为 0（平台视图固定 bid=0）；
//     - 复用第一个正常商户作为归属（bid=商户ID，对标点大商户在商户后台自建视频）；
//     - 视频/分类名均以【示例】开头，封面 GD 生成、mp4 随库提交在 public/uploads/demo。
//   回滚 `php think migrate:rollback`：删除全部【示例】商户视频与演示分类；真实视频与分类一律保留。
//   演示数据生成失败不阻断迁移部署（仅打印告警）。
use app\common\service\DemoShortvideoGenerator;
use think\migration\Migrator;

class SeedDemoShortvideos extends Migrator
{
    public function up(): void
    {
        try {
            $res = app(DemoShortvideoGenerator::class)->generate(1, false);
            echo "[seed-demo-shortvideos] 新建 {$res['created']} 条，跳过 {$res['skipped']} 条；新建分类 {$res['catCreated']} 个（归属商户 bid={$res['merchantBid']}）\n";
        } catch (\Throwable $e) {
            // 演示数据不影响正式表结构与部署，失败仅告警
            echo '[seed-demo-shortvideos] 演示短视频生成失败（不阻断迁移）：' . $e->getMessage() . "\n";
        }
    }

    public function down(): void
    {
        try {
            $res = app(DemoShortvideoGenerator::class)->purge(1);
            echo "[seed-demo-shortvideos] 已清理 {$res['videos']} 条【示例】短视频、{$res['categories']} 个演示分类\n";
        } catch (\Throwable $e) {
            echo '[seed-demo-shortvideos] 清理失败（不阻断回滚）：' . $e->getMessage() . "\n";
        }
    }
}
