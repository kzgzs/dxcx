<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteBusinessWithdrawLog,
  exportBusinessWithdrawLog,
  getBusinessWithdrawLogList,
  setBusinessWithdrawLogSt,
  type BusinessWithdrawLogItem,
} from '../../api/business'
import { formatTime } from '../../utils/time'

// 商户过滤视图预留（merchantViewOf 注入 bid='gt0'）；平台视角下用商户ID输入框过滤
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

const loading = ref(false)
const exporting = ref(false)
const tableData = ref<BusinessWithdrawLogItem[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

// status：0=审核中 1=已审核 2=已驳回 3=已打款
const STATUS_META: Record<number, { text: string; type: 'info' | 'warning' | 'danger' | 'success' | 'primary' }> = {
  0: { text: '审核中', type: 'warning' },
  1: { text: '已审核', type: 'primary' },
  2: { text: '已驳回', type: 'danger' },
  3: { text: '已打款', type: 'success' },
}

const query = reactive({
  page: 1,
  pagesize: 10,
  bid: '',
  name: '',
  status: '' as number | '',
  range: [] as string[],
  field: '',
  order: '' as '' | 'asc' | 'desc',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getBusinessWithdrawLogList({
      page: query.page,
      pagesize: query.pagesize,
      bid: props.bid || query.bid || undefined,
      name: query.name || undefined,
      status: query.status === '' ? undefined : query.status,
      start: query.range?.[0] || undefined,
      end: query.range?.[1] || undefined,
      field: query.field || undefined,
      order: query.order || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleSortChange({ prop, order }: { prop: string; order: null | 'ascending' | 'descending' }) {
  if (!prop || !order) {
    query.field = ''
    query.order = ''
  } else {
    query.field = prop
    query.order = order === 'ascending' ? 'asc' : 'desc'
  }
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

function handleSelectionChange(rows: BusinessWithdrawLogItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

// ============ 审核（通过） ============
async function handleAudit(row: BusinessWithdrawLogItem) {
  try {
    await ElMessageBox.confirm(`确认审核通过该笔提现（¥${Number(row.txmoney).toFixed(2)}）吗？`, '审核确认', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'warning',
    })
    await setBusinessWithdrawLogSt(row.id, 1)
    ElMessage.success('审核成功')
    fetchList()
  } catch {
    // 取消或错误提示已由拦截器统一弹出
  }
}

// ============ 驳回（审核中/已审核未打款均可；返还提现额 txmoney 至商户余额，需填原因） ============
async function handleReject(row: BusinessWithdrawLogItem) {
  try {
    const { value } = await ElMessageBox.prompt('请输入驳回原因（提现金额将返还商户余额）', '驳回提现', {
      confirmButtonText: '驳回',
      cancelButtonText: '取消',
      inputPlaceholder: '驳回原因',
      inputValue: row.reason || '',
      inputValidator: (v) => (v && String(v).trim() ? true : '请填写驳回原因'),
      type: 'warning',
    })
    await setBusinessWithdrawLogSt(row.id, 2, String(value).trim())
    ElMessage.success('已驳回')
    fetchList()
  } catch {
    // 取消或错误提示已由拦截器统一弹出
  }
}

// ============ 改为已打款（人工标记，仅已审核可操作） ============
async function handlePaid(row: BusinessWithdrawLogItem) {
  try {
    await ElMessageBox.confirm(`确认该笔提现（打款 ¥${Number(row.money).toFixed(2)}）已完成打款吗？`, '改为已打款', {
      confirmButtonText: '确认打款',
      cancelButtonText: '取消',
      type: 'warning',
    })
    await setBusinessWithdrawLogSt(row.id, 3)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 取消或错误提示已由拦截器统一弹出
  }
}

// ============ 删除 ============
function batchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请选择记录')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${selectedIds.value.length} 条提现记录吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteBusinessWithdrawLog(selectedIds.value)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function rowDelete(row: BusinessWithdrawLogItem) {
  ElMessageBox.confirm('确定要删除该条提现记录吗？删除后不可恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteBusinessWithdrawLog([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

async function handleExport() {
  if (exporting.value) return
  exporting.value = true
  try {
    const blob = await exportBusinessWithdrawLog({
      bid: props.bid || query.bid || undefined,
      name: query.name || undefined,
      status: query.status === '' ? undefined : query.status,
      start: query.range?.[0] || undefined,
      end: query.range?.[1] || undefined,
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `商户提现记录_${Date.now()}.xlsx`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    exporting.value = false
  }
}

onMounted(fetchList)
</script>

<template>
  <div class="withdraw-page">
    <!-- 操作 + 搜索 同一行 -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-left">
        <el-button @click="batchDelete">删除</el-button>
        <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item v-if="!props.bid" label="商户ID">
          <el-input
            v-model="query.bid"
            placeholder="商户ID"
            clearable
            style="width: 96px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="商户名称">
          <el-input
            v-model="query.name"
            placeholder="商户名称"
            clearable
            style="width: 120px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.range"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 100px">
            <el-option label="审核中" :value="0" />
            <el-option label="已审核" :value="1" />
            <el-option label="已驳回" :value="2" />
            <el-option label="已打款" :value="3" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="success" @click="handleSearch">搜索</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="70" sortable="custom" align="center" />
        <el-table-column prop="bid" label="商户ID" width="80" align="center" />
        <el-table-column prop="name" label="商户名称" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.name || '-' }}</template>
        </el-table-column>
        <el-table-column prop="shop_weixin" label="店主微信" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.shop_weixin || '-' }}</template>
        </el-table-column>
        <el-table-column label="提现金额" width="100" align="right">
          <template #default="{ row }">{{ Number(row.txmoney).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="money" label="打款金额" width="100" align="right" sortable="custom">
          <template #default="{ row }">{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="paytype" label="提现方式" width="95" align="center">
          <template #default="{ row }">{{ row.paytype || '-' }}</template>
        </el-table-column>
        <el-table-column prop="account" label="收款账号" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.account || '-' }}</template>
        </el-table-column>
        <el-table-column prop="createtime" label="提现时间" width="165" sortable="custom">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column prop="platform" label="操作平台" width="90" align="center">
          <template #default="{ row }">{{ row.platform || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="92" align="center">
          <template #default="{ row }">
            <el-tooltip
              v-if="row.status === 2 && row.reason"
              :content="'驳回原因：' + row.reason"
              placement="top"
            >
              <el-tag :type="STATUS_META[row.status]?.type || 'info'" size="small">
                {{ STATUS_META[row.status]?.text || '未知' }}
              </el-tag>
            </el-tooltip>
            <el-tag v-else :type="STATUS_META[row.status]?.type || 'info'" size="small">
              {{ STATUS_META[row.status]?.text || '未知' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="210">
          <template #default="{ row }">
            <div class="row-ops">
              <template v-if="row.status === 0">
                <el-button link type="success" @click="handleAudit(row)">通过</el-button>
                <el-button link type="danger" @click="handleReject(row)">驳回</el-button>
              </template>
              <template v-else-if="row.status === 1">
                <el-button link type="primary" @click="handlePaid(row)">改为已打款</el-button>
                <el-button link type="warning" @click="handleReject(row)">驳回</el-button>
              </template>
              <el-button link type="danger" @click="rowDelete(row)">删除</el-button>
            </div>
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
/* 主区域四边边距由 AdminLayout .admin-main 统一 3px，页面不再自带 padding */
.toolbar-card {
  margin-bottom: 3px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 10px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 0 0 auto;
}
.toolbar-left .el-button {
  margin: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  min-width: 0;
  margin: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.toolbar-search :deep(.el-form-item__label) {
  padding-right: 6px;
}
.row-ops {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 2px 8px;
}
.row-ops :deep(.el-button) {
  margin: 0;
  padding: 0;
  height: auto;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
</style>
