<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getTakeFoodList, takeFoodCall, takeFoodFinish, takeFoodOut } from '../../api/takefood'
import type { TakeFoodItem } from '../../api/takefood'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<TakeFoodItem[]>([])
const total = ref(0)

// tabs：''全部 0制作中 1待取餐 2已取餐 -1过期
const activeTab = ref('')

const query = reactive({
  page: 1,
  pagesize: 10,
  pickup_number: '',
  ordernum: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getTakeFoodList({
      page: query.page,
      pagesize: query.pagesize,
      status: activeTab.value || undefined,
      pickup_number: query.pickup_number || undefined,
      ordernum: query.ordernum || undefined,
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

function handleReset() {
  query.pickup_number = ''
  query.ordernum = ''
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

function statusTagType(status: number) {
  if (status === 0) return 'warning'
  if (status === 1) return 'success'
  if (status === 2) return 'info'
  return 'danger'
}

// ============ 出餐（制作中 → 待取餐） ============
async function handleOut(row: TakeFoodItem) {
  try {
    await takeFoodOut(row.id)
    ElMessage.success('已出餐')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 叫号（弹窗编辑叫号文案） ============
const callVisible = ref(false)
const callRow = ref<TakeFoodItem | null>(null)
const callText = ref('')
const callSubmitting = ref(false)

function openCall(row: TakeFoodItem) {
  callRow.value = row
  callText.value = row.call_text || `请${row.pickup_number}号到取餐处取餐。`
  callVisible.value = true
}

async function handleCallSubmit() {
  if (!callRow.value) return
  if (!callText.value.trim()) {
    ElMessage.warning('请填写叫号内容')
    return
  }
  callSubmitting.value = true
  try {
    await takeFoodCall(callRow.value.id, callText.value.trim())
    ElMessage.success('已叫号')
    callVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    callSubmitting.value = false
  }
}

// ============ 完成取餐 ============
async function handleFinish(row: TakeFoodItem) {
  try {
    await ElMessageBox.confirm(`确认 ${row.pickup_number} 号已完成取餐？`, '完成取餐', { type: 'warning' })
  } catch {
    return
  }
  try {
    await takeFoodFinish(row.id)
    ElMessage.success('已完成取餐')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <!-- 状态 tabs -->
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane label="全部" name="" />
        <el-tab-pane label="制作中" name="0" />
        <el-tab-pane label="待取餐" name="1" />
        <el-tab-pane label="已取餐" name="2" />
        <el-tab-pane label="已过期" name="-1" />
      </el-tabs>

      <!-- 搜索工具栏 -->
      <el-form inline @submit.prevent>
        <el-form-item label="取餐号">
          <el-input
            v-model="query.pickup_number"
            placeholder="取餐号"
            clearable
            style="width: 140px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="订单号">
          <el-input
            v-model="query.ordernum"
            placeholder="订单号"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>

      <!-- 取餐列表 -->
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="pickup_number" label="取餐号" width="110" />
        <el-table-column prop="ordernum" label="订单号" width="190" />
        <el-table-column prop="table_name" label="桌号" width="90" />
        <el-table-column label="会员" min-width="110">
          <template #default="{ row }">
            <span>{{ row.member_nickname || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="叫号内容" min-width="180">
          <template #default="{ row }">
            <span>{{ row.call_text || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="叫号时间" width="150">
          <template #default="{ row }">{{ formatTime(row.call_time) }}</template>
        </el-table-column>
        <el-table-column label="下单时间" width="150">
          <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.status === 0" link type="primary" @click="handleOut(row)">出餐</el-button>
            <el-button v-if="row.status === 0 || row.status === 1" link type="warning" @click="openCall(row)">叫号</el-button>
            <el-button v-if="row.status === 0 || row.status === 1" link type="success" @click="handleFinish(row)">完成取餐</el-button>
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

    <!-- 叫号弹窗 -->
    <el-dialog v-model="callVisible" title="叫号" width="460px">
      <el-form label-width="90px" label-position="left">
        <el-form-item label="取餐号">
          <span>{{ callRow?.pickup_number }}</span>
        </el-form-item>
        <el-form-item label="叫号内容">
          <el-input v-model="callText" placeholder="如：请D001号到取餐处取餐。" maxlength="100" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="callVisible = false">取消</el-button>
        <el-button type="primary" :loading="callSubmitting" @click="handleCallSubmit">确定叫号</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
