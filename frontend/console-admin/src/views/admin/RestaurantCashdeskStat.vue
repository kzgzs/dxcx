<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import type { CashdeskStatData } from '../../api/restaurantCashdesk'
import { fetchStat } from '../../api/restaurantCashdesk'

// 快捷时间范围 + 自定义时间范围（报表时间自定义）
const quickRanges = [
  { label: '今日', value: 'today' },
  { label: '昨日', value: 'yesterday' },
  { label: '近7天', value: 'week' },
  { label: '本月', value: 'month' },
  { label: '自定义', value: 'custom' },
]

const activeRange = ref('today')
const customRange = ref<[string, string] | null>(null)

const loading = ref(false)
const stat = ref<CashdeskStatData | null>(null)

function dayStr(offset: number): string {
  const d = new Date()
  d.setDate(d.getDate() + offset)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

function rangeOf(): [string, string] {
  switch (activeRange.value) {
    case 'yesterday':
      return [`${dayStr(-1)} 00:00:00`, `${dayStr(-1)} 23:59:59`]
    case 'week':
      return [`${dayStr(-6)} 00:00:00`, `${dayStr(0)} 23:59:59`]
    case 'month':
      return [`${dayStr(0).slice(0, 8)}01 00:00:00`, `${dayStr(0)} 23:59:59`]
    case 'custom':
      return customRange.value || ['', '']
    default:
      return [`${dayStr(0)} 00:00:00`, `${dayStr(0)} 23:59:59`]
  }
}

async function loadStat() {
  if ('custom' === activeRange.value && !customRange.value) {
    return
  }
  loading.value = true
  try {
    const [start, end] = rangeOf()
    stat.value = await fetchStat(start, end)
  } catch {
    // 错误提示由拦截器弹出
  } finally {
    loading.value = false
  }
}

function onQuickChange() {
  if ('custom' !== activeRange.value) {
    void loadStat()
  }
}

const payTypeTotal = reactive({ count: 0, amount: 0 })

function refreshPayTotal(data: CashdeskStatData | null) {
  payTypeTotal.count = (data?.pay_types || []).reduce((s, r) => s + r.count, 0)
  payTypeTotal.amount = (data?.pay_types || []).reduce((s, r) => s + r.amount, 0)
}

async function query() {
  await loadStat()
  refreshPayTotal(stat.value)
}

onMounted(query)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">收银统计</span>
        <div class="range-bar">
          <el-radio-group v-model="activeRange" size="small" @change="onQuickChange">
            <el-radio-button v-for="r in quickRanges" :key="r.value" :value="r.value">{{ r.label }}</el-radio-button>
          </el-radio-group>
          <el-date-picker
            v-if="activeRange === 'custom'"
            v-model="customRange"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            style="margin-left: 8px"
          />
          <el-button v-if="activeRange === 'custom'" type="primary" size="small" style="margin-left: 8px" @click="query">查询</el-button>
        </div>
      </div>
    </template>

    <div v-loading="loading">
      <template v-if="stat">
        <div class="stat-range">统计区间：{{ stat.start_time }} ~ {{ stat.end_time }}</div>

        <el-row :gutter="16" class="stat-cards">
          <el-col :span="8">
            <div class="stat-card">
              <div class="stat-label">订单数</div>
              <div class="stat-value">{{ stat.order_count }}</div>
            </div>
          </el-col>
          <el-col :span="8">
            <div class="stat-card">
              <div class="stat-label">营业额（实收）</div>
              <div class="stat-value amount">¥{{ stat.total_amount.toFixed(2) }}</div>
            </div>
          </el-col>
          <el-col :span="8">
            <div class="stat-card">
              <div class="stat-label">抹零金额</div>
              <div class="stat-value">¥{{ stat.moling_amount.toFixed(2) }}</div>
            </div>
          </el-col>
        </el-row>

        <el-row :gutter="16" style="margin-top: 16px">
          <el-col :span="12">
            <div class="section-title">按支付方式</div>
            <el-table :data="stat.pay_types" border size="small" show-summary :summary-method="() => ['合计', '', String(payTypeTotal.count), `¥${payTypeTotal.amount.toFixed(2)}`]">
              <el-table-column prop="paytype_text" label="支付方式" min-width="120" />
              <el-table-column prop="count" label="单数" width="90" align="right" />
              <el-table-column label="金额" width="120" align="right">
                <template #default="{ row }">¥{{ row.amount.toFixed(2) }}</template>
              </el-table-column>
            </el-table>
          </el-col>
          <el-col :span="12">
            <div class="section-title">按桌台</div>
            <el-table :data="stat.tables" border size="small" max-height="360">
              <el-table-column prop="table_name" label="桌台" min-width="120" />
              <el-table-column prop="count" label="单数" width="90" align="right" />
              <el-table-column label="金额" width="120" align="right">
                <template #default="{ row }">¥{{ row.amount.toFixed(2) }}</template>
              </el-table-column>
            </el-table>
          </el-col>
        </el-row>
      </template>
      <el-empty v-else-if="!loading" description="暂无统计数据" />
    </div>
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
}

.card-title {
  font-weight: 600;
}

.range-bar {
  display: flex;
  align-items: center;
}

.stat-range {
  color: #909399;
  font-size: 13px;
  margin-bottom: 12px;
}

.stat-cards .stat-card {
  border: 1px solid #e4e7ed;
  border-radius: 8px;
  padding: 16px;
  text-align: center;
}

.stat-label {
  color: #909399;
  font-size: 13px;
}

.stat-value {
  font-size: 26px;
  font-weight: 600;
  margin-top: 6px;
}

.stat-value.amount {
  color: #f56c6c;
}

.section-title {
  font-weight: 600;
  margin-bottom: 8px;
}
</style>
