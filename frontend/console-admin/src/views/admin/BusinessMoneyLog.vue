<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteBusinessMoneyLog,
  exportBusinessMoneyLog,
  getBusinessMoneyLogList,
  type BusinessMoneyLogItem,
} from '../../api/business'
import { formatTime } from '../../utils/time'

// 商户过滤视图预留（merchantViewOf 注入 bid='gt0'）；平台视角下用商户ID输入框过滤
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

const loading = ref(false)
const exporting = ref(false)
const tableData = ref<BusinessMoneyLogItem[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  bid: '',
  name: '',
  remark: '',
  range: [] as string[],
  field: '',
  order: '' as '' | 'asc' | 'desc',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getBusinessMoneyLogList({
      page: query.page,
      pagesize: query.pagesize,
      bid: props.bid || query.bid || undefined,
      name: query.name || undefined,
      remark: query.remark || undefined,
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

function handleSelectionChange(rows: BusinessMoneyLogItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

function batchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请选择记录')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${selectedIds.value.length} 条明细吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteBusinessMoneyLog(selectedIds.value)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function rowDelete(row: BusinessMoneyLogItem) {
  ElMessageBox.confirm('确定要删除该条明细吗？删除后不可恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteBusinessMoneyLog([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 备注列：说明为主，订单号另起一行（对齐点大"货款，订单号：xxx"口径）
function remarkText(row: BusinessMoneyLogItem): string {
  return row.remark || row.type || '-'
}

async function handleExport() {
  if (exporting.value) return
  exporting.value = true
  try {
    const blob = await exportBusinessMoneyLog({
      bid: props.bid || query.bid || undefined,
      name: query.name || undefined,
      remark: query.remark || undefined,
      start: query.range?.[0] || undefined,
      end: query.range?.[1] || undefined,
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `商户余额明细_${Date.now()}.xlsx`
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
  <div class="money-page">
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
            style="width: 130px"
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
        <el-form-item label="备注">
          <el-input
            v-model="query.remark"
            placeholder="备注"
            clearable
            style="width: 120px"
            @keyup.enter="handleSearch"
          />
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
        <el-table-column prop="bid" label="商户ID" width="80" align="center" />
        <el-table-column prop="name" label="商户名称" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.name || '-' }}</template>
        </el-table-column>
        <el-table-column prop="money" label="变更金额" width="120" align="right" sortable="custom">
          <template #default="{ row }">
            <span :class="Number(row.money) >= 0 ? 'money-plus' : 'money-minus'">
              {{ Number(row.money) >= 0 ? '+' : '' }}{{ Number(row.money).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="after" label="变更后金额" width="120" align="right" sortable="custom">
          <template #default="{ row }">{{ Number(row.after).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="createtime" label="变更时间" width="165" sortable="custom">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="备注" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <div class="remark-cell">
              <span>{{ remarkText(row) }}</span>
              <span v-if="row.ordernum" class="remark-order">订单号：{{ row.ordernum }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90" align="center">
          <template #default="{ row }">
            <el-button size="small" @click="rowDelete(row)">删除</el-button>
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
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.money-plus {
  color: #67c23a;
  font-weight: 600;
}
.money-minus {
  color: #303133;
}
.remark-cell {
  display: flex;
  flex-direction: column;
  line-height: 1.5;
}
.remark-order {
  color: #909399;
  font-size: 12px;
}
</style>
