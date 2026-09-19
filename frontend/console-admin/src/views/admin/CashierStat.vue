<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getCashierJiaoban, getCashierStat } from '../../api/cashier'
import type { CashierStatData, JiaobanData } from '../../api/cashier'

/* ============ 订单统计 ============ */
const statLoading = ref(false)
const stat = ref<CashierStatData | null>(null)
const dateRange = ref<[string, string] | null>(null)

async function fetchStat() {
  statLoading.value = true
  try {
    stat.value = await getCashierStat({
      starttime: dateRange.value?.[0] || undefined,
      endtime: dateRange.value?.[1] || undefined,
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    statLoading.value = false
  }
}

function handleQuickDay(offset: number) {
  const fmt = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  const day = new Date()
  day.setDate(day.getDate() + offset)
  const s = fmt(day)
  dateRange.value = [s, s]
  fetchStat()
}

/* ============ 交接班 ============ */
const jiaobanLoading = ref(false)
const jiaoban = ref<JiaobanData | null>(null)

async function fetchJiaoban() {
  jiaobanLoading.value = true
  try {
    jiaoban.value = await getCashierJiaoban()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    jiaobanLoading.value = false
  }
}

function handlePrintJiaoban() {
  const j = jiaoban.value
  if (!j) return
  const rows = j.pay_stat
    .map(
      (p) => `<tr><td>${p.paytype}</td><td style="text-align:center">${p.count}</td>
        <td style="text-align:right">¥${Number(p.money).toFixed(2)}</td></tr>`,
    )
    .join('')
  const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>交接班小票</title>
<style>
  body{font-family:'Microsoft YaHei',sans-serif;width:300px;margin:0 auto;font-size:12px;color:#333}
  h2{text-align:center;margin:8px 0}
  .meta{line-height:1.9}
  table{width:100%;border-collapse:collapse;margin-top:6px}
  th,td{padding:3px 2px;border-bottom:1px dashed #bbb}
  th{border-bottom:1px solid #333;text-align:left}
  .total{text-align:right;font-size:15px;font-weight:700;margin:8px 0}
  .line{border-top:1px dashed #333;margin:6px 0}
</style></head><body>
<h2>交接班统计</h2>
<div class="meta">
  收银员：${j.cashdesk_user}<br>
  上班时间：${j.logintime}<br>
  交班时间：${j.jiaobantime}
</div>
<div class="line"></div>
<div class="meta">
  订单数：${j.ordercount}<br>
  营业额：¥${Number(j.today_total_money).toFixed(2)}<br>
  退款金额：¥${Number(j.refund_money).toFixed(2)}<br>
  会员单数：${j.member_count}（¥${Number(j.member_money).toFixed(2)}）
</div>
<table><thead><tr><th>支付方式</th><th style="text-align:center">单数</th><th style="text-align:right">金额</th></tr></thead>
<tbody>${rows}</tbody></table>
<script>window.onload=function(){window.print()}<\/script>
</body></html>`
  const win = window.open('', '_blank', 'width=420,height=640')
  if (!win) {
    ElMessage.warning('请允许弹窗后重试打印')
    return
  }
  win.document.write(html)
  win.document.close()
}

onMounted(() => {
  fetchStat()
  fetchJiaoban()
})
</script>

<template>
  <div>
    <!-- 订单统计 -->
    <el-card v-loading="statLoading" shadow="never">
      <div class="list-title">订单统计</div>
      <el-form inline @submit.prevent>
        <el-form-item label="时间范围">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="fetchStat">查询</el-button>
          <el-button @click="handleQuickDay(0)">今日</el-button>
          <el-button @click="handleQuickDay(-1)">昨日</el-button>
          <el-button @click="handleQuickDay(-7)">7天前</el-button>
        </el-form-item>
      </el-form>

      <template v-if="stat">
        <el-row :gutter="12" class="stat-cards">
          <el-col :span="6">
            <div class="stat-card">
              <div class="stat-label">订单数</div>
              <div class="stat-value">{{ stat.order_count }}</div>
            </div>
          </el-col>
          <el-col :span="6">
            <div class="stat-card">
              <div class="stat-label">营业额</div>
              <div class="stat-value money">¥{{ Number(stat.total_money).toFixed(2) }}</div>
            </div>
          </el-col>
          <el-col :span="6">
            <div class="stat-card">
              <div class="stat-label">会员单数</div>
              <div class="stat-value">{{ stat.member_count }}</div>
            </div>
          </el-col>
          <el-col :span="6">
            <div class="stat-card">
              <div class="stat-label">退款（{{ stat.refund_count }} 笔）</div>
              <div class="stat-value danger">¥{{ Number(stat.refund_money).toFixed(2) }}</div>
            </div>
          </el-col>
        </el-row>
        <div class="stat-range">
          统计区间：{{ stat.starttime }} ~ {{ stat.endtime }}（口径：已支付订单）
        </div>

        <el-row :gutter="12">
          <el-col :span="12">
            <div class="sub-title">按支付方式</div>
            <el-table :data="stat.pay_stat" size="small" border>
              <el-table-column prop="paytype" label="支付方式" min-width="140" />
              <el-table-column prop="count" label="单数" width="90" />
              <el-table-column label="金额" width="120">
                <template #default="{ row }">¥{{ Number(row.money).toFixed(2) }}</template>
              </el-table-column>
            </el-table>
          </el-col>
          <el-col :span="12">
            <div class="sub-title">按商品（销量前100）</div>
            <el-table :data="stat.goods_stat" size="small" border max-height="360">
              <el-table-column prop="proname" label="商品" min-width="140" show-overflow-tooltip />
              <el-table-column prop="num" label="销量" width="90" />
              <el-table-column label="金额" width="120">
                <template #default="{ row }">¥{{ Number(row.money).toFixed(2) }}</template>
              </el-table-column>
            </el-table>
          </el-col>
        </el-row>
      </template>
    </el-card>

    <!-- 交接班 -->
    <el-card v-loading="jiaobanLoading" shadow="never" style="margin-top: 12px">
      <div class="list-title">
        交接班
        <span class="sub-note">（统计本班登录后至今的收银数据）</span>
        <el-button type="primary" size="small" style="margin-left: 12px" @click="fetchJiaoban">
          刷新
        </el-button>
        <el-button size="small" :disabled="!jiaoban" @click="handlePrintJiaoban">
          打印交班小票
        </el-button>
      </div>

      <template v-if="jiaoban">
        <el-descriptions :column="3" border size="small">
          <el-descriptions-item label="收银员">{{ jiaoban.cashdesk_user }}</el-descriptions-item>
          <el-descriptions-item label="上班时间">{{ jiaoban.logintime }}</el-descriptions-item>
          <el-descriptions-item label="交班时间">{{ jiaoban.jiaobantime }}</el-descriptions-item>
          <el-descriptions-item label="订单数">{{ jiaoban.ordercount }}</el-descriptions-item>
          <el-descriptions-item label="营业额">
            ¥{{ Number(jiaoban.today_total_money).toFixed(2) }}
          </el-descriptions-item>
          <el-descriptions-item label="退款金额">
            ¥{{ Number(jiaoban.refund_money).toFixed(2) }}
          </el-descriptions-item>
          <el-descriptions-item label="会员单数">{{ jiaoban.member_count }}</el-descriptions-item>
          <el-descriptions-item label="会员金额" :span="2">
            ¥{{ Number(jiaoban.member_money).toFixed(2) }}
          </el-descriptions-item>
        </el-descriptions>

        <div class="sub-title">本班收款明细（按支付方式）</div>
        <el-table :data="jiaoban.pay_stat" size="small" border>
          <el-table-column prop="paytype" label="支付方式" min-width="140" />
          <el-table-column prop="count" label="单数" width="90" />
          <el-table-column label="金额" width="120">
            <template #default="{ row }">¥{{ Number(row.money).toFixed(2) }}</template>
          </el-table-column>
        </el-table>
      </template>
    </el-card>
  </div>
</template>

<style scoped>
.list-title {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 16px;
}

.sub-note {
  font-size: 12px;
  color: #909399;
  font-weight: 400;
}

.stat-cards {
  margin-bottom: 12px;
}

.stat-card {
  background: #f5f7fa;
  border-radius: 6px;
  padding: 14px 16px;
}

.stat-label {
  color: #909399;
  font-size: 13px;
}

.stat-value {
  font-size: 22px;
  font-weight: 700;
  color: #303133;
  margin-top: 4px;
}

.stat-value.money {
  color: #f56c6c;
}

.stat-value.danger {
  color: #e6a23c;
}

.stat-range {
  color: #909399;
  font-size: 12px;
  margin-bottom: 12px;
}

.sub-title {
  font-weight: 600;
  margin: 12px 0 8px;
}
</style>
