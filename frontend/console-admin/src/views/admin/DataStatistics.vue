<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import * as echarts from 'echarts'
import { getStatIndex, getStatOptions } from '../../api/marketing'
import type { StatIndexParams, StatIndexResult, StatOptionsResult } from '../../api/marketing'

const loading = ref(false)
const range = ref('today')
const customRange = ref<[string, string] | null>(null)
const bid = ref('-1')
const freightId = ref('')
const qus = ref('sales')

const data = ref<StatIndexResult>({
  total_money: 0,
  total_order_num: 0,
  total_member_num: 0,
  total_kdj: 0,
  xszb: [],
  type_date_arr: [],
  date_arr: [],
  sxqsdb: [],
  member_data: [],
  money_arr: [],
})
const options = ref<StatOptionsResult>({ business: [], freight: [] })

const typeChartRef = ref<HTMLDivElement>()
const trendChartRef = ref<HTMLDivElement>()
const memberChartRef = ref<HTMLDivElement>()
let typeChart: echarts.ECharts | null = null
let trendChart: echarts.ECharts | null = null
let memberChart: echarts.ECharts | null = null

const QUS_TEXT: Record<string, string> = {
  sales: '销售额',
  orders: '订单量',
  members: '支付人数',
}

function fmtMoney(v: number | undefined): string {
  return '￥' + Number(v || 0).toFixed(2)
}

async function fetchOptions() {
  try {
    options.value = await getStatOptions()
  } catch {
    // 拦截器已提示
  }
}

async function fetchAll() {
  loading.value = true
  try {
    const params: StatIndexParams = {
      range: range.value,
      bid: bid.value,
      freight_id: freightId.value,
      qus: qus.value,
    }
    if (range.value === 'custom' && customRange.value) {
      params.custom_start = customRange.value[0]
      params.custom_end = customRange.value[1]
    }
    const res = await getStatIndex(params)
    data.value = res
    await nextTick()
    renderTypeChart()
    renderTrendChart()
    renderMemberChart()
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

function handleRangeChange() {
  if (range.value === 'custom') return
  fetchAll()
}

function handleSearch() {
  if (range.value === 'custom' && !customRange.value) return
  fetchAll()
}

function renderTypeChart() {
  if (!typeChartRef.value) return
  typeChart = typeChart || echarts.init(typeChartRef.value)
  const rows = data.value.xszb
  typeChart.setOption(
    {
      title: { text: '商品类型销售占比', left: 'center', textStyle: { fontSize: 14 } },
      tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        formatter: (p: any) => {
          const d = p[0]
          return `${d.name}<br/>销售额：￥${Number(d.value).toFixed(2)}<br/>占比：${d.data.percent}%`
        },
      },
      grid: { left: '3%', right: '12%', bottom: '3%', top: 40, containLabel: true },
      xAxis: { type: 'value', axisLabel: { formatter: (v: number) => '￥' + v } },
      yAxis: { type: 'category', data: rows.map((r) => r.name) },
      series: [
        {
          name: '销售额',
          type: 'bar',
          barWidth: 20,
          data: rows.map((r) => ({ value: r.value, percent: r.percent })),
          label: { show: true, position: 'right', formatter: (p: any) => `￥${p.value} (${p.data.percent}%)` },
        },
      ],
    },
    true,
  )
}

function renderTrendChart() {
  if (!trendChartRef.value) return
  trendChart = trendChart || echarts.init(trendChartRef.value)
  const colors = ['#5470c6', '#91cc75', '#fac858', '#ee6666', '#73c0de', '#3ba272']
  trendChart.setOption(
    {
      title: { text: `销售趋势对比图（${QUS_TEXT[qus.value]}）`, left: 'center', textStyle: { fontSize: 14 } },
      tooltip: { trigger: 'axis' },
      legend: { bottom: 0, data: data.value.sxqsdb.map((s) => s.name) },
      grid: { left: '3%', right: '4%', bottom: 50, top: 40, containLabel: true },
      xAxis: { type: 'category', boundaryGap: false, data: data.value.date_arr },
      yAxis: { type: 'value' },
      series: data.value.sxqsdb.map((s, i) => ({
        name: s.name,
        type: 'line',
        smooth: true,
        data: s.data,
        itemStyle: { color: colors[i % colors.length] },
      })),
    },
    true,
  )
}

function renderMemberChart() {
  if (!memberChartRef.value) return
  memberChart = memberChart || echarts.init(memberChartRef.value)
  memberChart.setOption(
    {
      title: { text: '会员消费数据', left: 'center', textStyle: { fontSize: 14 } },
      tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        formatter: (p: any) => {
          const d = p[0].data
          return `消费区间：${p[0].name}<br/>人数：${d.value}<br/>占比：${d.zb}%<br/>累计消费：￥${Number(d.totalConsumption).toFixed(2)}<br/>人均消费：￥${Number(d.rjxf).toFixed(2)}`
        },
      },
      grid: { left: '3%', right: '4%', bottom: 60, top: 40, containLabel: true },
      xAxis: { type: 'category', data: data.value.money_arr, axisLabel: { rotate: 30, fontSize: 10 } },
      yAxis: { type: 'value' },
      series: [
        {
          name: '人数',
          type: 'bar',
          barMaxWidth: 30,
          itemStyle: { color: '#1E9FFF' },
          data: data.value.member_data.map((m) => ({
            value: m.value,
            zb: m.zb,
            totalConsumption: m.totalConsumption,
            rjxf: m.rjxf,
          })),
        },
      ],
    },
    true,
  )
}

function handleResize() {
  typeChart?.resize()
  trendChart?.resize()
  memberChart?.resize()
}

onMounted(async () => {
  await fetchOptions()
  await fetchAll()
  window.addEventListener('resize', handleResize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', handleResize)
  typeChart?.dispose()
  trendChart?.dispose()
  memberChart?.dispose()
})
</script>

<template>
  <div v-loading="loading">
    <!-- 工具栏：时间范围 + 商家 + 配送方式，一行 -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-row">
        <el-radio-group v-model="range" @change="handleRangeChange">
          <el-radio-button value="today">本日</el-radio-button>
          <el-radio-button value="7days">近7天</el-radio-button>
          <el-radio-button value="30days">近30天</el-radio-button>
          <el-radio-button value="month">本月</el-radio-button>
          <el-radio-button value="custom">自定义</el-radio-button>
        </el-radio-group>

        <el-date-picker
          v-if="range === 'custom'"
          v-model="customRange"
          type="daterange"
          range-separator="至"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          value-format="YYYY-MM-DD"
          size="small"
          style="width: 260px"
        />

        <el-select v-model="bid" placeholder="商家" style="width: 150px" @change="handleSearch">
          <el-option label="全部" value="-1" />
          <el-option label="平台" value="0" />
          <el-option v-for="b in options.business" :key="b.id" :label="b.name" :value="String(b.id)" />
        </el-select>

        <el-select v-model="freightId" placeholder="配送方式" clearable style="width: 150px" @change="handleSearch">
          <el-option label="全部" value="" />
          <el-option v-for="f in options.freight" :key="f.id" :label="f.name" :value="String(f.id)" />
        </el-select>

        <el-button type="success" @click="handleSearch">搜索</el-button>
      </div>
    </el-card>

    <!-- 汇总卡片 -->
    <el-card shadow="never" class="block-card">
      <div class="summary-row">
        <div class="summary-item">
          <div class="summary-value">￥{{ data.total_money.toFixed(2) }}</div>
          <div class="summary-label">累计销售额</div>
        </div>
        <div class="summary-item">
          <div class="summary-value">{{ data.total_order_num }}</div>
          <div class="summary-label">累计订单数</div>
        </div>
        <div class="summary-item">
          <div class="summary-value">{{ data.total_member_num }}</div>
          <div class="summary-label">累计支付会员</div>
        </div>
        <div class="summary-item">
          <div class="summary-value">￥{{ data.total_kdj.toFixed(2) }}</div>
          <div class="summary-label">客单价</div>
        </div>
      </div>
    </el-card>

    <!-- 图表区 -->
    <div class="chart-row">
      <el-card shadow="never" class="block-card chart-card">
        <div ref="typeChartRef" class="chart-box" />
      </el-card>
      <el-card shadow="never" class="block-card chart-card">
        <template #header>
          <div class="trend-header">
            <span>销售趋势对比图</span>
            <el-select v-model="qus" size="small" style="width: 110px" @change="handleSearch">
              <el-option label="销售额" value="sales" />
              <el-option label="订单量" value="orders" />
              <el-option label="支付人数" value="members" />
            </el-select>
          </div>
        </template>
        <div ref="trendChartRef" class="chart-box" />
      </el-card>
    </div>

    <el-card shadow="never" class="block-card">
      <div ref="memberChartRef" class="chart-box-lg" />
    </el-card>

    <!-- 详细数据列表 -->
    <el-card shadow="never" class="block-card">
      <template #header>详细数据列表</template>
      <el-table :data="data.type_date_arr" class="admin-table" stripe>
        <el-table-column prop="name" label="类型" min-width="120" />
        <el-table-column label="销售额(元)" min-width="120">
          <template #default="{ row }">{{ fmtMoney(row.total_money) }}</template>
        </el-table-column>
        <el-table-column prop="order_num" label="订单量(单)" min-width="100" />
        <el-table-column prop="member_num" label="支付会员(人)" min-width="110" />
        <el-table-column label="客单价(元)" min-width="110">
          <template #default="{ row }">{{ fmtMoney(row.type_kdj) }}</template>
        </el-table-column>
        <el-table-column label="退款金额(元)" min-width="120">
          <template #default="{ row }">{{ fmtMoney(row.z_refund_money) }}</template>
        </el-table-column>
        <el-table-column label="退款率(%)" min-width="100">
          <template #default="{ row }">{{ row.tkl.toFixed(2) }}%</template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 3px;
}

.toolbar-row {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 10px;
}

.block-card {
  margin-bottom: 3px;
}

.summary-row {
  display: flex;
}

.summary-item {
  flex: 1;
  text-align: center;
}

.summary-value {
  font-size: 24px;
  font-weight: 600;
  color: #1e9fff;
}

.summary-label {
  color: #666;
  margin-top: 6px;
}

.chart-row {
  display: flex;
  gap: 3px;
  margin-bottom: 3px;
}

.chart-card {
  flex: 1;
}

.chart-box {
  height: 320px;
}

.chart-box-lg {
  height: 340px;
}

.trend-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.admin-table :deep(th) {
  background: #e6e6e6;
}

.admin-table :deep(.cell) {
  text-align: left;
}
</style>
