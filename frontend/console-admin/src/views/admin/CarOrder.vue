<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { finishCarOrder, getCarOrderList } from '../../api/carhailing'
import type { CarOrderItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// ============ 状态 tabs ============
const tabs = [
  { name: '', label: '全部' },
  { name: '0', label: '待支付' },
  { name: '1', label: '已支付' },
  { name: '2', label: '使用中' },
  { name: '3', label: '已完成' },
  { name: '4', label: '已取消' },
  { name: '5', label: '已退款' },
]

// 状态 tag 颜色
const STATUS_TAG: Record<number, '' | 'success' | 'warning' | 'info' | 'danger'> = {
  0: 'warning',
  1: 'success',
  2: '',
  3: 'info',
  4: 'danger',
  5: 'danger',
}

// ============ 列表 ============
const activeTab = ref('')
const loading = ref(false)
const tableData = ref<CarOrderItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCarOrderList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      status: activeTab.value === '' ? undefined : Number(activeTab.value),
    })
    tableData.value = data.list || []
    total.value = data.total || 0
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

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

// 用车区间
function periodText(row: CarOrderItem) {
  return `${formatTime(row.start_time)} ~ ${formatTime(row.end_time)}`
}

// ============ 完成订单 ============
function handleFinish(row: CarOrderItem) {
  ElMessageBox.confirm(`确认将订单「${row.order_sn}」标记为已完成吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await finishCarOrder(row.id)
        ElMessage.success('操作成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <!-- 状态 tabs -->
      <el-tabs v-model="activeTab" class="order-tabs" @tab-change="handleTabChange">
        <el-tab-pane v-for="t in tabs" :key="t.name" :label="t.label" :name="t.name" />
      </el-tabs>

      <!-- 搜索工具栏 -->
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="订单号 / 手机号"
            clearable
            style="width: 240px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
        </el-form-item>
      </el-form>

      <!-- 订单列表 -->
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="order_sn" label="订单号" min-width="170" show-overflow-tooltip />
        <el-table-column prop="member_phone" label="会员手机" width="120" />
        <el-table-column prop="product_name" label="车辆" min-width="120" show-overflow-tooltip />
        <el-table-column label="用车时间" width="260">
          <template #default="{ row }">{{ periodText(row) }}</template>
        </el-table-column>
        <el-table-column label="天数" width="70">
          <template #default="{ row }">{{ row.days }}</template>
        </el-table-column>
        <el-table-column label="支付金额" width="100">
          <template #default="{ row }">¥{{ Number(row.pay_amount).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">
              {{ row.status_text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="支付时间" width="160">
          <template #default="{ row }">{{ formatTime(row.pay_time) }}</template>
        </el-table-column>
        <el-table-column label="下单时间" width="160">
          <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 1 || row.status === 2"
              link
              type="primary"
              @click="handleFinish(row)"
            >
              完成
            </el-button>
            <span v-else>-</span>
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
.order-tabs {
  margin-bottom: 8px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
