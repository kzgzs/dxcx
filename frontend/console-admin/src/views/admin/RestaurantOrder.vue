<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  cancelRestaurantOrder,
  finishRestaurantOrder,
  getRestaurantOrderList,
} from '../../api/restaurant'
import type { RestaurantOrderItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// ============ 状态 tabs ============
// 状态值：0待支付 1待出餐 2已完成 4已取消 5已退款
const tabs = [
  { name: '', label: '全部' },
  { name: '0', label: '待支付' },
  { name: '1', label: '待出餐' },
  { name: '2', label: '已完成' },
  { name: '4', label: '已取消' },
  { name: '5', label: '已退款' },
]

// 状态 tag 颜色
const STATUS_TAG: Record<number, '' | 'success' | 'warning' | 'info' | 'danger'> = {
  0: 'warning',
  1: '',
  2: 'success',
  4: 'danger',
  5: 'danger',
}

// ============ 列表 ============
const activeTab = ref('')
const loading = ref(false)
const tableData = ref<RestaurantOrderItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getRestaurantOrderList({
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

// ============ 完成 / 取消 ============
function handleFinish(row: RestaurantOrderItem) {
  ElMessageBox.confirm(`确认将订单「${row.order_sn}」标记为已完成吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await finishRestaurantOrder(row.id)
        ElMessage.success('操作成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleCancel(row: RestaurantOrderItem) {
  ElMessageBox.confirm(`确认取消订单「${row.order_sn}」吗？`, '操作确认', {
    confirmButtonText: '确定取消',
    cancelButtonText: '再想想',
    type: 'warning',
  })
    .then(async () => {
      try {
        await cancelRestaurantOrder(row.id)
        ElMessage.success('已取消')
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
            placeholder="订单号 / 桌台"
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
        <el-table-column prop="table_name" label="桌台" width="100">
          <template #default="{ row }">{{ row.table_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="商品" min-width="240">
          <template #default="{ row }">
            <div v-for="(g, i) in row.goods" :key="i" class="goods-line">
              {{ g.name }} × {{ g.num }}（¥{{ Number(g.price).toFixed(2) }}）
            </div>
            <span v-if="!row.goods || row.goods.length === 0">-</span>
          </template>
        </el-table-column>
        <el-table-column label="原价" width="100">
          <template #default="{ row }">¥{{ Number(row.amount).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="实付" width="100">
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
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 1"
              link
              type="primary"
              @click="handleFinish(row)"
            >
              完成
            </el-button>
            <el-button
              v-if="row.status === 0"
              link
              type="danger"
              @click="handleCancel(row)"
            >
              取消
            </el-button>
            <span v-if="row.status !== 0 && row.status !== 1">-</span>
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

.goods-line {
  line-height: 1.8;
  color: #606266;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
