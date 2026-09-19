<?php
// 演示自定义表单种子（幂等，可重复执行）：
//   部署执行 `php think migrate:run` 时自动生成商户「自定义表单」的演示表单与提交数据，
//   避免线上忘记手动执行 `php think demo:forms` 导致客户端 → 商户 → 自定义表单空白。
//   逻辑全部复用 app\common\service\DemoFormGenerator（与 demo:forms 命令同一份）：
//     - 先把历史平台表单/提交记录的 bid NULL 规范化为 0（平台视图固定 bid=0）；
//     - 复用第一个正常商户作为归属（bid=商户ID，对标点大商户在商户后台自建表单）；
//     - 表单名以【示例】开头：1 个覆盖全部 11 种字段并开启在线支付的「商户报名」（含 3 条
//       待处理/已确认/已驳回提交，演示“数据”角标、动态列、查看图片小弹窗、支付与状态），
//       1 个免支付的「到店服务预约」（1 条已确认）；凭证图 GD 生成在 public/uploads/demo。
//   回滚 `php think migrate:rollback`：删除全部【示例】商户表单及其提交记录；平台/真实表单一律保留。
//   演示数据生成失败不阻断迁移部署（仅打印告警）。
use app\common\service\DemoFormGenerator;
use think\migration\Migrator;

class SeedDemoForms extends Migrator
{
    public function up(): void
    {
        try {
            $res = app(DemoFormGenerator::class)->generate(1, false);
            echo "[seed-demo-forms] 新建表单 {$res['formCreated']} 个、提交记录 {$res['orderCreated']} 条，跳过 {$res['skipped']} 项（归属商户 bid={$res['merchantBid']}）\n";
        } catch (\Throwable $e) {
            // 演示数据不影响正式表结构与部署，失败仅告警
            echo '[seed-demo-forms] 演示表单生成失败（不阻断迁移）：' . $e->getMessage() . "\n";
        }
    }

    public function down(): void
    {
        try {
            $res = app(DemoFormGenerator::class)->purge(1);
            echo "[seed-demo-forms] 已清理 {$res['forms']} 个【示例】表单、{$res['orders']} 条提交记录\n";
        } catch (\Throwable $e) {
            echo '[seed-demo-forms] 清理失败（不阻断回滚）：' . $e->getMessage() . "\n";
        }
    }
}
