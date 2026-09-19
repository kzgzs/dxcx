<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getYuyueOrderList, verifyYuyueOrder } from '../../api/yuyue'
import type { YuyueOrderItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// ============ 状态 tabs ============
// 状态值约定：0待支付 1已支付 2已完成 4已取消 5已退款（与餐饮订单编号一致）
const tabs = [
  { name: '', label: '全部' },
  { name: '0', label: '待支付' },
  { name: '1', label: '已支付' },
  { name: '2', label: '已完成' },
  { name: '4', label: '已取消' },
  { name: '5', label: '已退款' },
]

// 状态 tag 颜色
const STATUS_TAG: Record<number, '' | 'success' | 'warning' | 'info' | 'danger'> = {
  0: 'warning',
  1: 'success',
  2: 'info',
  4: 'danger',
  5: 'danger',
}

// ============ 列表 ============
const activeTab = ref('')
const loading = ref(false)
const tableData = ref<YuyueOrderItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueOrderList({
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

// 预约时间（日期 'Y-m-d' + 时段字符串直接展示）
function bookText(row: YuyueOrderItem) {
  return `${row.book_date} ${row.book_time}`
}

// ============ 核销弹窗 ============
const verifyVisible = ref(false)
const verifySubmitting = ref(false)
const verifyTarget = ref<YuyueOrderItem | null>(null)
const verifyCode = ref('')

function openVerify(row: YuyueOrderItem) {
  verifyTarget.value = row
  verifyCode.value = ''
  verifyVisible.value = true
}

async function handleVerifySubmit() {
  if (!verifyTarget.value) return
  if (!verifyCode.value.trim()) {
    ElMessage.warning('请输入核销码')
    return
  }
  verifySubmitting.value = true
  try {
    await verifyYuyueOrder(verifyTarget.value.id, verifyCode.value.trim())
    ElMessage.success('核销成功')
    verifyVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    verifySubmitting.value = false
  }
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
        <el-table-column prop="product_name" label="预约项目" min-width="130" show-overflow-tooltip />
        <el-table-column prop="worker_name" label="技师" width="100">
          <template #default="{ row }">{{ row.worker_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="预约时间" width="170">
          <template #default="{ row }">{{ bookText(row) }}</template>
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
        <el-table-column label="下单时间" width="160">
          <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 1"
              link
              type="primary"
              @click="openVerify(row)"
            >
              核销
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

    <!-- 核销弹窗 -->
    <el-dialog v-model="verifyVisible" title="订单核销" width="420px">
      <el-form label-width="80px" label-position="left" @submit.prevent>
        <el-form-item label="订单号">
          <span>{{ verifyTarget?.order_sn }}</span>
        </el-form-item>
        <el-form-item label="核销码">
          <el-input
            v-model="verifyCode"
            placeholder="请输入顾客出示的核销码"
            clearable
            @keyup.enter="handleVerifySubmit"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="verifyVisible = false">取消</el-button>
        <el-button type="primary" :loading="verifySubmitting" @click="handleVerifySubmit">
          确认核销
        </el-button>
      </template>
    </el-dialog>
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
