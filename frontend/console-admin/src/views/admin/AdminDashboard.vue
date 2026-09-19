<script setup lang="ts">
import { computed, inject, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getAdminDashboard, getTrend } from '../../api/stat'
import type { AdminDashboardData, TrendItem } from '../../api/types'

// 壳层菜单跳转（按叶子 key 切换 activeId）
const navigateByKey = inject<(key: string) => void>('adminNavigateByKey', () => {})

// 活动快捷入口（抽奖/砍价/投票/周期购）
const activityEntries = [
  { label: '抽奖活动', key: 'choujiang-index', color: '#e6a23c' },
  { label: '砍价活动', key: 'kanjia-product-index', color: '#f56c6c' },
  { label: '投票活动', key: 'toupiao-index', color: '#409eff' },
  { label: '周期购', key: 'cycle-product-index', color: '#52c41a' },
]

function goActivity(key: string) {
  navigateByKey(key)
}

// 加载态
const loading = ref(true)

// 面板汇总数据（初始全 0，接口返回后填充）
const data = ref<AdminDashboardData>({
  wait_pay: 0,
  wait_ship: 0,
  shipped: 0,
  received: 0,
  wait_refund: 0,
  today_money: 0,
  today_orders: 0,
  month_money: 0,
  month_orders: 0,
  total_money: 0,
  total_orders: 0,
  member_total: 0,
  member_today: 0,
  member_month: 0,
  balance_total: 0,
  spend_total: 0,
  recharge_total: 0,
  refund_money: 0,
})

// 近30天趋势
const trend = ref<TrendItem[]>([])
const trendLoading = ref(true)

// 千分位格式化
function fmtInt(n: number): string {
  return Math.round(n).toLocaleString('en-US')
}

// 金额格式化
function fmtMoney(n: number): string {
  return n.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

// 订单状态卡片配置
const statusCards = computed(() => [
  { label: '待付款', value: data.value.wait_pay, color: '#e6a23c' },
  { label: '待发货', value: data.value.wait_ship, color: '#409eff' },
  { label: '已发货', value: data.value.shipped, color: '#9254de' },
  { label: '已收货', value: data.value.received, color: '#52c41a' },
  { label: '待退款', value: data.value.wait_refund, color: '#f56c6c' },
])

/* ===== SVG 趋势图（柱=订单数，线=订单金额） ===== */
const CHART_W = 920
const CHART_H = 240
const PAD_L = 12
const PAD_R = 12
const PAD_T = 16
const PAD_B = 28

// 图上几何由趋势数据计算（无数据时占位空图）
const chart = computed(() => {
  const list = trend.value
  const n = list.length
  if (n === 0) return { bars: [], line: '', moneyLabels: [], dateLabels: [], maxMoney: 0, maxOrders: 0 }

  const innerW = CHART_W - PAD_L - PAD_R
  const innerH = CHART_H - PAD_T - PAD_B
  const maxMoney = Math.max(...list.map((i) => i.money), 1)
  const maxOrders = Math.max(...list.map((i) => i.orders), 1)
  const step = innerW / n
  const barW = Math.max(2, Math.min(16, step * 0.5))

  const bars = list.map((item, idx) => {
    const cx = PAD_L + step * idx + step / 2
    const h = (item.orders / maxOrders) * innerH
    return {
      x: cx - barW / 2,
      y: PAD_T + innerH - h,
      w: barW,
      h,
      cx,
      tip: `${item.date} 金额¥${fmtMoney(item.money)} 订单${item.orders}笔 新会员${item.members}人`,
    }
  })

  const line = list
    .map((item, idx) => {
      const cx = PAD_L + step * idx + step / 2
      const y = PAD_T + innerH - (item.money / maxMoney) * innerH
      return `${idx === 0 ? 'M' : 'L'}${cx.toFixed(1)},${y.toFixed(1)}`
    })
    .join(' ')

  // X 轴日期标签：约每 5 天一个
  const dateLabels = list
    .map((item, idx) => ({ idx, text: item.date.slice(5) }))
    .filter((_, idx) => idx % Math.ceil(n / 8) === 0 || idx === n - 1)
    .map((i) => ({ x: PAD_L + step * i.idx + step / 2, text: i.text }))

  return { bars, line, dateLabels, maxMoney, maxOrders }
})

// 加载数据
async function loadData() {
  loading.value = true
  try {
    const res = await getAdminDashboard()
    if (res) data.value = res
  } catch {
    // request.ts 已统一弹提示
  } finally {
    loading.value = false
  }

  trendLoading.value = true
  try {
    const t = await getTrend(30)
    if (t) trend.value = t.list
  } catch {
    ElMessage.error('趋势数据加载失败')
  } finally {
    trendLoading.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <div class="admin-dashboard">
    <!-- 活动快捷入口 -->
    <el-row :gutter="16" style="margin-bottom: 16px">
      <el-col :xs="24">
        <el-card shadow="never" class="activity-card">
          <div class="card-title">营销活动</div>
          <div class="activity-grid">
            <div
              v-for="item in activityEntries"
              :key="item.key"
              class="activity-tile"
              @click="goActivity(item.key)"
            >
              <div class="activity-dot" :style="{ background: item.color }"></div>
              <span>{{ item.label }}</span>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 运营数据概览卡片（三卡横向） -->
    <el-row :gutter="16" v-loading="loading">
      <!-- 卡 1：会员数概览 -->
      <el-col :xs="24" :sm="12" :md="8" :lg="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-header">
            <span class="stat-title">会员数概览</span>
            <span class="stat-unit">人</span>
          </div>
          <div class="stat-value-container">
            <span class="stat-big-number">{{ fmtInt(data.member_total) }}</span>
            <div class="stat-small-row">
              <div class="small-item">
                <span class="small-label">本月新增</span>
                <span class="small-value">{{ fmtInt(data.member_month) }}</span>
              </div>
              <div class="small-item">
                <span class="small-label">今日新增</span>
                <span class="small-value">{{ fmtInt(data.member_today) }}</span>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 卡 2：订单统计 -->
      <el-col :xs="24" :sm="12" :md="8" :lg="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-header">
            <span class="stat-title">订单统计</span>
            <span class="stat-unit">笔</span>
          </div>
          <div class="stat-value-container">
            <span class="stat-big-number">{{ fmtInt(data.total_orders) }}</span>
            <div class="stat-small-row">
              <div class="small-item">
                <span class="small-label">本月订单</span>
                <span class="small-value">{{ fmtInt(data.month_orders) }}</span>
              </div>
              <div class="small-item">
                <span class="small-label">今日订单</span>
                <span class="small-value">{{ fmtInt(data.today_orders) }}</span>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 卡 3：订单金额 -->
      <el-col :xs="24" :sm="12" :md="8" :lg="8">
        <el-card shadow="never" class="stat-card stat-money-card">
          <div class="stat-header">
            <span class="stat-title">订单金额</span>
            <span class="stat-unit">¥</span>
          </div>
          <div class="stat-value-container">
            <span class="stat-big-number">{{ fmtMoney(data.total_money) }}</span>
            <div class="stat-small-row">
              <div class="small-item">
                <span class="small-label">本月收款</span>
                <span class="small-value">{{ fmtMoney(data.month_money) }}</span>
              </div>
              <div class="small-item">
                <span class="small-label">今日收款</span>
                <span class="small-value">{{ fmtMoney(data.today_money) }}</span>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 订单状态待办 + 金额概览 -->
    <el-row :gutter="16" style="margin-top: 16px" v-loading="loading">
      <!-- 订单状态五卡 -->
      <el-col :xs="24" :sm="24" :md="16" :lg="16">
        <el-card shadow="never" class="overview-card">
          <div class="card-title">订单待办</div>
          <div class="status-grid">
            <div v-for="item in statusCards" :key="item.label" class="status-tile">
              <div class="status-num" :style="{ color: item.color }">{{ fmtInt(item.value) }}</div>
              <div class="status-label">{{ item.label }}</div>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 金额概览卡片 -->
      <el-col :xs="24" :sm="24" :md="8" :lg="8">
        <el-card shadow="never" class="overview-card money-card">
          <div class="card-title">金额概览</div>
          <div class="card-content">
            <div class="content-row">
              <span class="label">会员余额总计</span>
              <span class="value money">{{ fmtMoney(data.balance_total) }} ¥</span>
            </div>
            <div class="content-row">
              <span class="label">充值总额</span>
              <span class="value money">{{ fmtMoney(data.recharge_total) }} ¥</span>
            </div>
            <div class="content-row">
              <span class="label">消费总额</span>
              <span class="value money">{{ fmtMoney(data.spend_total) }} ¥</span>
            </div>
            <div class="content-row">
              <span class="label">已退款金额</span>
              <span class="value refund">-{{ fmtMoney(data.refund_money) }} ¥</span>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 近30天趋势图 -->
    <el-row style="margin-top: 16px">
      <el-col :xs="24">
        <el-card shadow="never" class="overview-card" v-loading="trendLoading">
          <div class="card-title trend-title-row">
            <span>近30天趋势</span>
            <span class="trend-legend">
              <span class="legend-item"><i class="legend-bar"></i>订单数</span>
              <span class="legend-item"><i class="legend-line"></i>订单金额</span>
            </span>
          </div>
          <div v-if="trend.length === 0 && !trendLoading" class="trend-empty">
            <el-empty description="近30天暂无支付订单" :image-size="60" />
          </div>
          <svg
            v-else
            class="trend-svg"
            :viewBox="`0 0 ${920} ${240}`"
            preserveAspectRatio="none"
          >
            <!-- 柱：订单数 -->
            <rect
              v-for="(bar, idx) in chart.bars"
              :key="'b' + idx"
              :x="bar.x"
              :y="bar.y"
              :width="bar.w"
              :height="bar.h"
              class="trend-bar"
            >
              <title>{{ bar.tip }}</title>
            </rect>
            <!-- 线：订单金额 -->
            <path v-if="chart.line" :d="chart.line" class="trend-line" />
            <!-- X 轴日期 -->
            <text
              v-for="(lb, idx) in chart.dateLabels"
              :key="'d' + idx"
              :x="lb.x"
              :y="234"
              text-anchor="middle"
              class="trend-axis-text"
            >{{ lb.text }}</text>
          </svg>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<style scoped>
/* 活动快捷入口 */
.activity-card {
  padding: 12px 16px;
  border-radius: 8px;
  background: #ffffff;
}

.activity-grid {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.activity-tile {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 18px;
  border-radius: 6px;
  background-color: #fafafa;
  font-size: 13px;
  color: #303133;
  cursor: pointer;
  transition: background-color 0.2s;
}

.activity-tile:hover {
  background-color: #f0f5ff;
}

.activity-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.stat-card {
  padding: 16px;
  border-radius: 8px;
  background: #ffffff;
}

.stat-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.stat-title {
  font-size: 13px;
  color: #606266;
  font-weight: 500;
}

.stat-unit {
  font-size: 12px;
  color: #909399;
}

.stat-value-container {
  display: flex;
  flex-direction: column;
}

.stat-big-number {
  font-size: 28px;
  font-weight: 700;
  color: #1f2329;
  line-height: 1.2;
  margin-bottom: 8px;
}

.stat-money-card .stat-big-number {
  color: #52c41a;
}

.stat-small-row {
  display: flex;
  gap: 16px;
}

.small-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.small-label {
  color: #909399;
}

.small-value {
  color: #303133;
  font-weight: 600;
}

/* ===== 下排卡片样式 ===== */
.overview-card {
  height: 100%;
  border-radius: 8px;
  background: #ffffff;
}

.card-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f0f0f0;
}

.card-content {
  padding: 4px 0;
}

.content-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 0;
  font-size: 13px;
}

.label {
  color: #606266;
}

.value {
  color: #303133;
  font-weight: 600;
}

.money {
  color: #52c41a;
}

.refund {
  color: #f56c6c;
}

/* 订单待办五格 */
.status-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 12px;
}

.status-tile {
  padding: 14px 8px;
  border-radius: 6px;
  background-color: #fafafa;
  text-align: center;
}

.status-num {
  font-size: 24px;
  font-weight: 700;
  line-height: 1.3;
}

.status-label {
  margin-top: 4px;
  font-size: 12px;
  color: #909399;
}

/* 趋势图 */
.trend-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.trend-legend {
  display: flex;
  gap: 16px;
  font-size: 12px;
  font-weight: 400;
  color: #909399;
}

.legend-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.legend-bar {
  width: 10px;
  height: 10px;
  background: #409eff;
  opacity: 0.6;
  display: inline-block;
}

.legend-line {
  width: 16px;
  height: 2px;
  background: #f56c6c;
  display: inline-block;
}

.trend-svg {
  width: 100%;
  height: 240px;
  display: block;
}

.trend-bar {
  fill: #409eff;
  opacity: 0.55;
}

.trend-bar:hover {
  opacity: 0.9;
}

.trend-line {
  fill: none;
  stroke: #f56c6c;
  stroke-width: 2;
  vector-effect: non-scaling-stroke;
}

.trend-axis-text {
  font-size: 11px;
  fill: #909399;
}

.trend-empty {
  padding: 8px 0;
}
</style>
