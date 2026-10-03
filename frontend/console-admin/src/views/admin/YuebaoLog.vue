<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteYuebaoMoneyLog, exportYuebaoMoneyLog, getYuebaoMoneylogList } from '../../api/yuebao'
import type { YuebaoMoneyLogItem, YuebaoMoneyLogStat } from '../../api/yuebao'
import { formatTime } from '../../utils/time'

/**
 * 余额宝明细（member_yuebao_moneylog）对齐 canzhao yuebao/moneylog.html
 * 工具栏[删除,导出EXCEL]；行[删除]
 */
const loading = ref(false)
const exporting = ref(false)
const tableData = ref<YuebaoMoneyLogItem[]>([])
const selectedRows = ref<YuebaoMoneyLogItem[]>([])
const total = ref(0)
const stat = ref<YuebaoMoneyLogStat>({ income: 0, expense: 0, net: 0 })

const query = reactive({
  page: 1,
  pagesize: 10,
  mid: '',
  nickname: '',
})

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    mid: query.mid ? Number(query.mid) : undefined,
    nickname: query.nickname || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuebaoMoneylogList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { income: 0, expense: 0, net: 0 }
  } catch {
    // 拦截器统一提示
  } finally {
    loading.value = false
  }
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

function handleSelectionChange(rows: YuebaoMoneyLogItem[]) {
  selectedRows.value = rows
}

function downloadBlob(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = fileName
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

async function handleExport() {
  exporting.value = true
  try {
    const blob = await exportYuebaoMoneyLog(buildParams())
    downloadBlob(blob, `余额宝明细_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
  } catch {
    // 拦截器统一提示
  } finally {
    exporting.value = false
  }
}

async function doDelete(ids: number[]) {
  await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    type: 'warning',
    confirmButtonText: '确 定',
    cancelButtonText: '取 消',
  })
  await deleteYuebaoMoneyLog({ ids })
  ElMessage.success('删除成功')
  fetchList()
}

function handleBatchDelete() {
  if (selectedRows.value.length === 0) {
    ElMessage.warning('请先勾选数据')
    return
  }
  doDelete(selectedRows.value.map((r) => r.id)).catch(() => {})
}

function handleRowDelete(row: YuebaoMoneyLogItem) {
  doDelete([row.id]).catch(() => {})
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- 工具栏：批量按钮 + 搜索 同一行 -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-line">
        <div class="toolbar-actions">
          <el-button @click="handleBatchDelete">删除</el-button>
          <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
        </div>
        <div class="toolbar-search">
          <el-input v-model="query.mid" placeholder="会员ID" clearable class="tw-mid" @keyup.enter="handleSearch" />
          <el-input v-model="query.nickname" placeholder="昵称" clearable class="tw-nick" @keyup.enter="handleSearch" />
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
          </el-button>
        </div>
      </div>
    </el-card>

    <!-- 表格 -->
    <el-card shadow="never" class="table-card">
      <div class="table-stat">
        <span>收入 <b>￥{{ Number(stat.income).toFixed(2) }}</b></span>
        <span>支出 <b>￥{{ Number(stat.expense).toFixed(2) }}</b></span>
        <span>净额 <b>￥{{ Number(stat.net).toFixed(2) }}</b></span>
      </div>
      <div class="table-scroll">
        <el-table v-loading="loading" :data="tableData" border size="small" @selection-change="handleSelectionChange">
          <el-table-column type="selection" width="42" />
          <el-table-column prop="mid" label="会员ID" width="80" />
          <el-table-column label="会员信息" min-width="160">
            <template #default="{ row }">
              <span>{{ row.nickname || ('会员' + row.mid) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="变更金额" width="110" sortable="custom">
            <template #default="{ row }">
              <span :class="Number(row.money) >= 0 ? 'money-plus' : 'money-minus'">
                {{ Number(row.money) >= 0 ? '+' : '' }}{{ Number(row.money).toFixed(2) }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="变更后" width="110" sortable="custom">
            <template #default="{ row }">{{ Number(row.after).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column prop="type_text" label="类型" width="110" show-overflow-tooltip>
            <template #default="{ row }">{{ row.type_text || '-' }}</template>
          </el-table-column>
          <el-table-column label="变更时间" width="150" sortable="custom">
            <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
          </el-table-column>
          <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip>
            <template #default="{ row }">{{ row.remark || '-' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="80">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>
      </div>
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
.toolbar-card :deep(.el-card__body) {
  padding: 8px 10px;
}
.toolbar-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: nowrap;
}
.toolbar-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}
.toolbar-actions .el-button {
  margin-left: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  gap: 6px;
  flex: 1;
  flex-wrap: nowrap;
  overflow: hidden;
}
.toolbar-search :deep(.el-input__inner) {
  text-align: left;
}
.tw-mid { width: 90px; flex-shrink: 0; }
.tw-nick { width: 130px; flex-shrink: 0; }
.search-icon-btn {
  width: 32px;
  height: 32px;
  padding: 0;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.search-icon-btn svg {
  display: block;
}

.table-card :deep(.el-card__body) {
  padding: 5px;
}
.table-stat {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
  padding: 2px 4px 6px;
  font-size: 12px;
  color: #606266;
}
.table-stat b {
  color: #303133;
  font-weight: 600;
}
.table-scroll {
  width: 100%;
  overflow-x: auto;
}
.table-scroll :deep(.el-table) {
  --el-table-header-bg-color: #e6e6e6;
  --el-table-header-text-color: #303133;
}
.table-scroll :deep(.el-table th .cell),
.table-scroll :deep(.el-table td .cell) {
  text-align: left;
}
.table-scroll :deep(.el-table td .cell) {
  padding: 3px 6px;
}
.row-ops {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  align-items: center;
  gap: 2px 6px;
  white-space: nowrap;
}
.row-ops :deep(.el-button) {
  margin: 0;
  padding: 0;
}
.money-plus {
  color: #03bc01;
  font-weight: 600;
}
.money-minus {
  color: #f56c6c;
  font-weight: 600;
}
.pagination {
  margin-top: 8px;
  justify-content: flex-end;
}
</style>