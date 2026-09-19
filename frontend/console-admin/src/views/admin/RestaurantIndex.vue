<script setup lang="ts">
/**
 * 餐饮 - 首页（经营概览，对照参考系统 Restaurant/index）
 * 统计卡：菜品/餐桌/堂食订单(数/金额/退款)/外卖订单(数/金额/退款)
 * 曲线：最近 30 天，type 1客户总数 2新增客户 3收款金额 4订单金额 5订单数
 */
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import * as echarts from 'echarts'
import { getRestaurantChart, getRestaurantOverview } from '../../api/restaurantExt'
import type { RestaurantOverview } from '../../api/restaurantExt'

const loading = ref(false)
const overview = ref<RestaurantOverview | null>(null)

const CHART_TYPES = [
  { value: 1, label: '客户总数' },
  { value: 2, label: '新增客户' },
  { value: 3, label: '收款金额' },
  { value: 4, label: '订单金额' },
  { value: 5, label: '订单数' },
]

const chartType = ref(4)
const chartRef = ref<HTMLDivElement>()
let chart: echarts.ECharts | null = null

async function fetchOverview() {
  loading.value = true
  try {
    overview.value = await getRestaurantOverview()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function fetchChart() {
  try {
    const data = await getRestaurantChart(chartType.value)
    await nextTick()
    renderChart(data.dateArr || [], data.dataArr || [])
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function renderChart(dates: string[], values: number[]) {
  if (!chartRef.value) return
  chart = chart || echarts.init(chartRef.value)
  const label = CHART_TYPES.find((t) => t.value === chartType.value)?.label || ''
  chart.setOption(
    {
      title: { text: `最近30天${label}`, left: 'center', textStyle: { fontSize: 14 } },
      tooltip: { trigger: 'axis' },
      grid: { left: 50, right: 20, bottom: 40, top: 50 },
      xAxis: { type: 'category', data: dates, axisLabel: { fontSize: 10 } },
      yAxis: { type: 'value' },
      series: [{ name: label, type: 'line', smooth: true, data: values, areaStyle: { opacity: 0.12 } }],
    },
    true,
  )
}

function handleChartTypeChange() {
  fetchChart()
}

function handleResize() {
  chart?.resize()
}

onMounted(() => {
  fetchOverview()
  fetchChart()
  window.addEventListener('resize', handleResize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', handleResize)
  chart?.dispose()
  chart = null
})
</script>

<template>
  <div v-loading="loading" class="ri-page">
    <el-row v-if="overview" :gutter="12">
      <el-col :span="6">
        <el-card shadow="never" class="ri-card">
          <div class="ri-card-title">菜品</div>
          <div class="ri-num">{{ overview.productCount }}</div>
          <div class="ri-sub">在售 {{ overview.productCount1 }} / 未上架 {{ overview.productCount0 }}</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="ri-card">
          <div class="ri-card-title">餐桌</div>
          <div class="ri-num">{{ overview.tableCount }}</div>
          <div class="ri-sub">空闲 {{ overview.tableCount0 }} / 使用中 {{ overview.tableCount2 }}</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="ri-card">
          <div class="ri-card-title">堂食订单</div>
          <div class="ri-num">{{ overview.shopOrderCount }} 笔 / ￥{{ overview.shopOrderMoney }}</div>
          <div class="ri-sub">
            昨日 {{ overview.shopOrderLastDayCount }} 笔 · 本月 {{ overview.shopOrderThisMonthCount }} 笔
          </div>
          <div class="ri-sub ri-refund">退款 ￥{{ overview.refundMoney }}（本月 ￥{{ overview.refundMoneyThisMonth }}）</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="ri-card">
          <div class="ri-card-title">外卖订单</div>
          <div class="ri-num">{{ overview.takeawayOrderCount }} 笔 / ￥{{ overview.takeawayOrderMoney }}</div>
          <div class="ri-sub">
            昨日 {{ overview.takeawayOrderLastDayCount }} 笔 · 本月 {{ overview.takeawayOrderThisMonthCount }} 笔
          </div>
          <div class="ri-sub ri-refund">退款 ￥{{ overview.takeawayRefundMoney }}（本月 ￥{{ overview.takeawayRefundMoneyThisMonth }}）</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" class="ri-chart-card">
      <div class="ri-chart-toolbar">
        <el-radio-group v-model="chartType" size="small" @change="handleChartTypeChange">
          <el-radio-button v-for="t in CHART_TYPES" :key="t.value" :value="t.value">{{ t.label }}</el-radio-button>
        </el-radio-group>
      </div>
      <div ref="chartRef" class="ri-chart" />
    </el-card>
  </div>
</template>

<style scoped>
.ri-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ri-card {
  height: 100%;
}

.ri-card-title {
  font-size: 13px;
  color: #909399;
  margin-bottom: 6px;
}

.ri-num {
  font-size: 20px;
  font-weight: 600;
  color: #303133;
}

.ri-sub {
  margin-top: 4px;
  font-size: 12px;
  color: #909399;
}

.ri-refund {
  color: #f56c6c;
}

.ri-chart-card {
  margin-top: 0;
}

.ri-chart-toolbar {
  margin-bottom: 8px;
}

.ri-chart {
  width: 100%;
  height: 320px;
}
</style>
