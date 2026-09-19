<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import * as echarts from 'echarts'
import { getStatMoneyDist, getStatSummary, getStatTrend } from '../../api/marketing'
import type { StatSummaryResult } from '../../api/marketing'

const range = ref('today')
const loading = ref(false)
const summary = ref<StatSummaryResult>({ modules: [], total_money: 0, total_num: 0, total_kedan: 0 })

const pieRef = ref<HTMLDivElement>()
const barRef = ref<HTMLDivElement>()
const lineRef = ref<HTMLDivElement>()
let pieChart: echarts.ECharts | null = null
let barChart: echarts.ECharts | null = null
let lineChart: echarts.ECharts | null = null

const RANGE_TEXT: Record<string, string> = {
  today: '今日',
  '7days': '近7天',
  '30days': '近30天',
  month: '本月',
}

async function fetchAll() {
  loading.value = true
  try {
    const [summaryData, distData, trendData] = await Promise.all([
      getStatSummary(range.value),
      getStatMoneyDist(range.value),
      getStatTrend(range.value),
    ])
    summary.value = summaryData
    await nextTick()
    renderPie(summaryData)
    renderBar(distData.labels, distData.values)
    renderLine(trendData.labels, trendData.series, trendData.total)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleRangeChange() {
  fetchAll()
}

function renderPie(data: StatSummaryResult) {
  if (!pieRef.value) return
  pieChart = pieChart || echarts.init(pieRef.value)
  pieChart.setOption({
    title: { text: '销售占比', left: 'center', textStyle: { fontSize: 14 } },
    tooltip: { trigger: 'item', formatter: '{b}：￥{c}（{d}%）' },
    legend: { bottom: 0 },
    series: [
      {
        type: 'pie',
        radius: ['38%', '62%'],
        center: ['50%', '48%'],
        data: data.modules
          .filter((m) => m.money > 0)
          .map((m) => ({ name: m.name, value: m.money })),
        label: { formatter: '{b}\n{d}%' },
      },
    ],
  }, true)
}

function renderBar(labels: string[], values: number[]) {
  if (!barRef.value) return
  barChart = barChart || echarts.init(barRef.value)
  barChart.setOption({
    title: { text: '订单金额分布', left: 'center', textStyle: { fontSize: 14 } },
    tooltip: { trigger: 'axis' },
    grid: { left: 40, right: 20, bottom: 60, top: 40 },
    xAxis: { type: 'category', data: labels, axisLabel: { rotate: 35, fontSize: 10 } },
    yAxis: { type: 'value', minInterval: 1 },
    series: [{ type: 'bar', data: values, itemStyle: { color: '#409EFF' }, barMaxWidth: 30 }],
  }, true)
}

function renderLine(
  labels: string[],
  series: Record<string, { name: string; data: number[] }>,
  total: number[],
) {
  if (!lineRef.value) return
  lineChart = lineChart || echarts.init(lineRef.value)
  const seriesData = Object.values(series).map((s) => ({
    name: s.name,
    type: 'line' as const,
    smooth: true,
    data: s.data,
  }))
  seriesData.push({ name: '合计', type: 'line', smooth: true, data: total })
  lineChart.setOption({
    title: { text: `订单额趋势（${RANGE_TEXT[range.value] || range.value}）`, left: 'center', textStyle: { fontSize: 14 } },
    tooltip: { trigger: 'axis' },
    legend: { bottom: 0 },
    grid: { left: 50, right: 20, bottom: 60, top: 40 },
    xAxis: { type: 'category', data: labels, axisLabel: { fontSize: 10 } },
    yAxis: { type: 'value' },
    series: seriesData,
  }, true)
}

function handleResize() {
  pieChart?.resize()
  barChart?.resize()
  lineChart?.resize()
}

onMounted(() => {
  fetchAll()
  window.addEventListener('resize', handleResize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', handleResize)
  pieChart?.dispose()
  barChart?.dispose()
  lineChart?.dispose()
})
</script>

<template>
  <div v-loading="loading">
    <!-- 时间范围 -->
    <el-card shadow="never" class="toolbar-card">
      <el-radio-group v-model="range" @change="handleRangeChange">
        <el-radio-button value="today">今日</el-radio-button>
        <el-radio-button value="7days">近7天</el-radio-button>
        <el-radio-button value="30days">近30天</el-radio-button>
        <el-radio-button value="month">本月</el-radio-button>
      </el-radio-group>
    </el-card>

    <!-- 汇总卡片 -->
    <el-card shadow="never" class="stat-card">
      <div class="stat-row">
        <div class="stat-item">
          <div class="stat-label">订单总额</div>
          <div class="stat-value">￥{{ summary.total_money }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">订单总数</div>
          <div class="stat-value">{{ summary.total_num }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">综合客单价</div>
          <div class="stat-value">￥{{ summary.total_kedan }}</div>
        </div>
      </div>
    </el-card>

    <!-- 模块明细表 -->
    <el-card shadow="never" class="block-card">
      <template #header>各模块销售明细</template>
      <el-table :data="summary.modules" stripe show-summary :summary-method="() => ['合计', '', `￥${summary.total_money}`, summary.total_num, `￥${summary.total_kedan}`]">
        <el-table-column prop="name" label="模块" min-width="120" />
        <el-table-column label="销售占比" min-width="120">
          <template #default="{ row }">{{ row.percent }}%</template>
        </el-table-column>
        <el-table-column label="订单额" min-width="120">
          <template #default="{ row }">￥{{ row.money }}</template>
        </el-table-column>
        <el-table-column prop="num" label="订单数" min-width="100" />
        <el-table-column label="客单价" min-width="120">
          <template #default="{ row }">￥{{ row.kedan }}</template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 图表区 -->
    <div class="chart-row">
      <el-card shadow="never" class="chart-card">
        <div ref="pieRef" class="chart-box" />
      </el-card>
      <el-card shadow="never" class="chart-card">
        <div ref="barRef" class="chart-box" />
      </el-card>
    </div>

    <el-card shadow="never" class="block-card">
      <div ref="lineRef" class="chart-box chart-box-lg" />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.stat-card {
  margin-bottom: 16px;
}

.stat-row {
  display: flex;
  gap: 64px;
}

.stat-item {
  text-align: center;
}

.stat-label {
  color: #909399;
  font-size: 13px;
}

.stat-value {
  font-size: 24px;
  font-weight: 600;
  margin-top: 4px;
}

.block-card {
  margin-bottom: 16px;
}

.chart-row {
  display: flex;
  gap: 16px;
  margin-bottom: 16px;
}

.chart-card {
  flex: 1;
}

.chart-box {
  height: 320px;
}

.chart-box-lg {
  height: 360px;
}
</style>
