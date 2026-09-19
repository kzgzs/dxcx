<script setup lang="ts">
/**
 * 餐饮 - 统计（参考系统 tongji 空壳，本方实现日期区间按天聚合）
 * 口径：已支付订单 status in 1,2,3（paytime 落天），退款 refund_status=2
 */
import { onMounted, reactive, ref } from 'vue'
import { getRestaurantTongjiList, getRestaurantTongjiOverview } from '../../api/restaurantExt'
import type { RestaurantTongjiOverview, RestaurantTongjiRow, RestaurantTongjiTotals } from '../../api/restaurantExt'

function pad2(n: number): string {
  return n < 10 ? `0${n}` : String(n)
}

const now = new Date()
const query = reactive({
  stime: `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-01`,
  etime: `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}`,
  scope: 'all',
})

const loading = ref(false)
const rows = ref<RestaurantTongjiRow[]>([])
const totals = ref<RestaurantTongjiTotals>({
  orderCount: 0,
  orderMoney: 0,
  refundCount: 0,
  refundMoney: 0,
  netMoney: 0,
})
const overview = ref<RestaurantTongjiOverview | null>(null)

async function fetchAll() {
  loading.value = true
  try {
    const [listData, ovData] = await Promise.all([
      getRestaurantTongjiList({ stime: query.stime, etime: query.etime, scope: query.scope }),
      getRestaurantTongjiOverview({ stime: query.stime, etime: query.etime }),
    ])
    rows.value = listData.list || []
    totals.value = listData.totals || totals.value
    overview.value = ovData
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

onMounted(fetchAll)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="tj-header">
        <span class="tj-title">餐饮统计</span>
        <span class="tj-tip">已支付订单按支付时间落天统计，退款按退款成功时间落天</span>
      </div>
    </template>

    <div class="tj-toolbar">
      <el-date-picker
        v-model="query.stime"
        type="date"
        value-format="YYYY-MM-DD"
        placeholder="开始日期"
        style="width: 150px"
      />
      <span class="tj-sep">至</span>
      <el-date-picker
        v-model="query.etime"
        type="date"
        value-format="YYYY-MM-DD"
        placeholder="结束日期"
        style="width: 150px"
      />
      <el-select v-model="query.scope" style="width: 140px">
        <el-option label="堂食+外卖" value="all" />
        <el-option label="仅堂食" value="shop" />
        <el-option label="仅外卖" value="takeaway" />
      </el-select>
      <el-button type="primary" @click="fetchAll">查询</el-button>
    </div>

    <!-- 堂食/外卖对比卡 -->
    <div v-if="overview" class="tj-cards">
      <el-card v-for="(item, key) in overview" :key="key" shadow="never" class="tj-card">
        <div class="tj-card-title">{{ key === 'shop' ? '堂食订单' : '外卖订单' }}</div>
        <div class="tj-card-grid">
          <div><div class="tj-num">{{ item.orderCount }}</div><div class="tj-label">订单数</div></div>
          <div><div class="tj-num">￥{{ item.orderMoney }}</div><div class="tj-label">订单金额</div></div>
          <div><div class="tj-num">{{ item.refundCount }}</div><div class="tj-label">退款单数</div></div>
          <div><div class="tj-num">￥{{ item.refundMoney }}</div><div class="tj-label">退款金额</div></div>
          <div><div class="tj-num tj-net">￥{{ item.netMoney }}</div><div class="tj-label">净收入</div></div>
        </div>
      </el-card>
    </div>

    <el-table :data="rows" v-loading="loading" stripe>
      <el-table-column prop="date" label="日期" width="120" />
      <el-table-column prop="orderCount" label="订单数" width="100" />
      <el-table-column label="订单金额" width="130">
        <template #default="{ row }">￥{{ row.orderMoney }}</template>
      </el-table-column>
      <el-table-column prop="refundCount" label="退款单数" width="100" />
      <el-table-column label="退款金额" width="130">
        <template #default="{ row }">￥{{ row.refundMoney }}</template>
      </el-table-column>
      <el-table-column label="净收入" min-width="130">
        <template #default="{ row }">
          <span class="tj-net">￥{{ row.netMoney }}</span>
        </template>
      </el-table-column>
      <template #empty>
        <el-empty description="该区间暂无统计数据" />
      </template>
    </el-table>

    <div class="tj-totals">
      <span>合计：订单 {{ totals.orderCount }} 笔</span>
      <span>订单金额 ￥{{ totals.orderMoney }}</span>
      <span>退款 {{ totals.refundCount }} 笔 / ￥{{ totals.refundMoney }}</span>
      <span class="tj-net">净收入 ￥{{ totals.netMoney }}</span>
    </div>
  </el-card>
</template>

<style scoped>
.tj-header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.tj-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.tj-tip {
  font-size: 12px;
  color: #909399;
}

.tj-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
}

.tj-sep {
  color: #909399;
}

.tj-cards {
  display: flex;
  gap: 12px;
  margin-bottom: 14px;
}

.tj-card {
  flex: 1;
}

.tj-card-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 10px;
}

.tj-card-grid {
  display: flex;
  gap: 18px;
  flex-wrap: wrap;
}

.tj-num {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}

.tj-net {
  color: #67c23a;
  font-weight: 600;
}

.tj-label {
  font-size: 12px;
  color: #909399;
  margin-top: 2px;
}

.tj-totals {
  display: flex;
  gap: 24px;
  justify-content: flex-end;
  margin-top: 12px;
  font-size: 13px;
  color: #606266;
}
</style>
