<?php
// 演示商品种子（幂等，可重复执行）：
//   部署执行 `php think migrate:run` 时自动生成 7 个营销模块的示例商品（平台自营 + 商户 各 2 个），
//   避免线上忘记手动执行 `php think demo:products` 导致后台商品页空白。
//   逻辑全部复用 app\common\service\DemoProductGenerator（与 demo:products 命令同一份，走各模块 Service 保存入口）：
//     - 没有正常商户时会自动创建一个「示例商户·优选生活馆」承接商户示例商品；
//     - 商品名均以「【示例】」开头，图片在 public/uploads/demo（随仓提交）。
//   回滚 `php think migrate:rollback`：删除全部【示例】商品（含规格）及自动创建的同名示例商户，
//   真实商户（如「义务包包」）与其名下真实数据一律保留。
//   演示数据生成失败不阻断迁移部署（仅打印告警）。

use app\common\service\DemoProductGenerator;
use think\migration\Migrator;

class SeedDemoProducts extends Migrator
{
    public function up(): void
    {
        try {
            $res = app(DemoProductGenerator::class)->generate(1, false);

            $merchant = $res['merchantCreated']
                ? ('（已自动创建示例商户 bid=' . $res['merchantBid'] . '）')
                : '（复用现有商户 bid=' . $res['merchantBid'] . '）';
            echo "[seed-demo-products] 新建 {$res['created']} 个，跳过 {$res['skipped']} 个 {$merchant}\n";
        } catch (\Throwable $e) {
            // 演示数据不影响正式表结构与部署，失败仅告警
            echo '[seed-demo-products] 演示商品生成失败（不阻断迁移）：' . $e->getMessage() . "\n";
        }
    }

    public function down(): void
    {
        try {
            $res = app(DemoProductGenerator::class)->purge(1);
            $merchant = $res['merchantDeleted'] ? '，并删除自动创建的示例商户' : '，保留真实商户';
            echo "[seed-demo-products] 已清理 {$res['products']} 个示例商品{$merchant}\n";
        } catch (\Throwable $e) {
            echo '[seed-demo-products] 清理失败（不阻断回滚）：' . $e->getMessage() . "\n";
        }
    }
}
