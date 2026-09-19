<?php
// 演示文章种子（幂等，可重复执行）：
//   部署执行 `php think migrate:run` 时自动生成商户「文章列表」的演示文章与商户文章分类，
//   避免线上忘记手动执行 `php think demo:articles` 导致客户端 → 商户 → 文章列表空白。
//   逻辑全部复用 app\common\service\DemoArticleGenerator（与 demo:articles 命令同一份）：
//     - 复用第一个正常商户作为归属（bid=商户ID，对标点大商户在商户后台自建文章）；
//     - 文章/分类名均以【示例】开头，图片在 public/uploads/demo（随库提交）。
//   回滚 `php think migrate:rollback`：删除全部【示例】商户文章与演示分类；真实文章与分类一律保留。
//   演示数据生成失败不阻断迁移部署（仅打印告警）。
use app\common\service\DemoArticleGenerator;
use think\migration\Migrator;

class SeedDemoArticles extends Migrator
{
    public function up(): void
    {
        try {
            $res = app(DemoArticleGenerator::class)->generate(1, false);
            echo "[seed-demo-articles] 新建 {$res['created']} 篇，跳过 {$res['skipped']} 篇；新建分类 {$res['catCreated']} 个（归属商户 bid={$res['merchantBid']}）\n";
        } catch (\Throwable $e) {
            // 演示数据不影响正式表结构与部署，失败仅告警
            echo '[seed-demo-articles] 演示文章生成失败（不阻断迁移）：' . $e->getMessage() . "\n";
        }
    }

    public function down(): void
    {
        try {
            $res = app(DemoArticleGenerator::class)->purge(1);
            echo "[seed-demo-articles] 已清理 {$res['articles']} 篇【示例】文章、{$res['categories']} 个演示分类\n";
        } catch (\Throwable $e) {
            echo '[seed-demo-articles] 清理失败（不阻断回滚）：' . $e->getMessage() . "\n";
        }
    }
}
