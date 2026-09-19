<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { getPayorderList } from '../../api/finance'
import type { PayorderItem, PayorderStat } from '../../api/finance'
import { formatTime } from '../../utils/time'

// ============ 状态/筛选 ============
const STATUS_TABS = [
  { name: '1', label: '已支付' },
  { name: '0', label: '未支付' },
  { name: '2', label: '已取消' },
  { name: '', label: '全部' },
]

const STATUS_TEXT: Record<number, string> = { 0: '未支付', 1: '已支付', 2: '已取消' }
const STATUS_TAG: Record<number, 'warning' | 'success' | 'info'> = {
  0: 'warning',
  1: 'success',
  2: 'info',
}

const PAYTYPE_OPTIONS = [
  { label: '余额支付', value: 1 },
  { label: '微信支付', value: 2 },
  { label: '支付宝支付', value: 3 },
  { label: '货到付款', value: 4 },
  { label: '转账汇款', value: 5 },
]

const activeTab = ref('1')
const loading = ref(false)
const tableData = ref<PayorderItem[]>([])
const total = ref(0)
const stat = ref<PayorderStat>({ total_received: 0, total_refund: 0, total_cost: 0 })

const query = reactive({
  page: 1,
  pagesize: 10,
  paytypeid: undefined as number | undefined,
  nickname: '',
  mid: '',
  tel: '',
  ordernum: '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getPayorderList({
      page: query.page,
      pagesize: query.pagesize,
      status: activeTab.value,
      paytypeid: query.paytypeid,
      nickname: query.nickname || undefined,
      mid: query.mid ? Number(query.mid) : undefined,
      tel: query.tel || undefined,
      ordernum: query.ordernum || undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { total_received: 0, total_refund: 0, total_cost: 0 }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleTabChange() {
  query.page = 1
  fetchList()
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.paytypeid = undefined
  query.nickname = ''
  query.mid = ''
  query.tel = ''
  query.ordernum = ''
  query.timeRange = null
  query.page = 1
  fetchList()
}

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

// 时间列短格式（悬浮 tooltip 显示完整时间）
function shortDate(ts: number): string {
  if (!ts) return '-'
  const full = formatTime(ts)
  return full.length >= 10 ? full.slice(2, 10) : full
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- 顶部统计卡片 -->
    <el-row :gutter="12" class="stat-row">
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">总收款（已支付）</div>
          <div class="stat-value primary">￥{{ Number(stat.total_received).toFixed(2) }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">总退款</div>
          <div class="stat-value danger">￥{{ Number(stat.total_refund).toFixed(2) }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">总消费（收款-退款）</div>
          <div class="stat-value success">￥{{ Number(stat.total_cost).toFixed(2) }}</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-tabs v-model="activeTab" class="status-tabs" @tab-change="handleTabChange">
        <el-tab-pane v-for="t in STATUS_TABS" :key="t.name" :name="t.name" :label="t.label" />
      </el-tabs>
      <el-form inline @submit.prevent>
        <el-form-item label="支付方式">
          <el-select v-model="query.paytypeid" placeholder="全部" clearable style="width: 120px">
            <el-option v-for="p in PAYTYPE_OPTIONS" :key="p.value" :label="p.label" :value="p.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" placeholder="订单号" clearable style="width: 150px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="会员昵称" clearable style="width: 120px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="ID" clearable style="width: 80px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="手机">
          <el-input v-model="query.tel" placeholder="手机号" clearable style="width: 120px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表（border 竖线 + 紧凑列宽，1366 无横向滚动） -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="ordernum" label="订单号" min-width="150" show-overflow-tooltip />
        <el-table-column prop="title" label="标题" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title || '-' }}</template>
        </el-table-column>
        <el-table-column label="金额" width="86" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="paytype_text" label="支付方式" width="86" class-name="compact-col" show-overflow-tooltip />
        <el-table-column prop="type_text" label="类型" width="88" class-name="compact-col" show-overflow-tooltip />
        <el-table-column prop="paynum" label="支付单号" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.paynum || '-' }}</template>
        </el-table-column>
        <el-table-column label="支付时间" width="88" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.paytime" :content="formatTime(row.paytime)" placement="top">
              <span>{{ shortDate(row.paytime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="会员" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.nickname || `会员${row.mid}` }}
            <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="退款额" width="80" class-name="compact-col">
          <template #default="{ row }">
            <span v-if="Number(row.refund_money) > 0" class="danger-text">￥{{ Number(row.refund_money).toFixed(2) }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="76" class-name="compact-col">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">
              {{ STATUS_TEXT[row.status] || '未知' }}
            </el-tag>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>
  </div>
</template>

<style scoped>
.stat-row {
  margin-bottom: 12px;
}

.stat-card .stat-label {
  color: #909399;
  font-size: 13px;
}

.stat-card .stat-value {
  font-size: 22px;
  font-weight: 700;
  margin-top: 4px;
}

.stat-value.primary {
  color: #409eff;
}

.stat-value.danger {
  color: #f56c6c;
}

.stat-value.success {
  color: #67c23a;
}

.toolbar-card {
  margin-bottom: 12px;
}

.status-tabs {
  margin-bottom: 4px;
}

.status-tabs :deep(.el-tabs__header) {
  margin-bottom: 8px;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 4px;
}

.danger-text {
  color: #f56c6c;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
