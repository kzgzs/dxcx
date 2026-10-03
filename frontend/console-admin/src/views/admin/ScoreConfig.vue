<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  cancelScoreLog,
  deleteScoreLog,
  exportScoreLog,
  getScoreLogList,
} from '../../api/score'
import type { ScoreLogItem as ScoreLogRow } from '../../api/score'
import { assetUrl } from '../../api/upload'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const exporting = ref(false)
const tableData = ref<ScoreLogRow[]>([])
const selectedRows = ref<ScoreLogRow[]>([])
const total = ref(0)
const statCount = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  mid: '',
  nickname: '',
  remark: '',
  timeRange: null as [string, string] | null,
})

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    mid: query.mid ? Number(query.mid) : undefined,
    nickname: query.nickname || undefined,
    remark: query.remark || undefined,
    start_date: query.timeRange?.[0] || undefined,
    end_date: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getScoreLogList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
    statCount.value = (data.stat && data.stat.count) || 0
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

function handleSelectionChange(rows: ScoreLogRow[]) {
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
    const blob = await exportScoreLog(buildParams())
    downloadBlob(blob, `积分明细_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
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
  await deleteScoreLog({ ids })
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

function handleRowDelete(row: ScoreLogRow) {
  doDelete([row.id]).catch(() => {})
}

async function handleRowCancel(row: ScoreLogRow) {
  try {
    await ElMessageBox.confirm('确定要撤销吗?', '操作确认', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await cancelScoreLog({ id: row.id })
    ElMessage.success('撤销成功')
    fetchList()
  } catch {
    // 拦截器统一提示
  }
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- 工具栏一行：批量删除 + 导出 + 搜索 + 绿色放大镜 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item>
          <el-button :disabled="selectedRows.length === 0" @click="handleBatchDelete">删除</el-button>
          <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="会员ID" clearable style="width: 100px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="昵称" clearable style="width: 120px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 230px"
          />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="query.remark" placeholder="备注" clearable style="width: 120px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 表格 -->
    <el-card shadow="never" class="table-card">
      <div class="table-stat">
        <span>总记录 <b>{{ statCount }}</b></span>
      </div>
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="mid" label="会员ID" width="80" />
        <el-table-column label="会员信息" min-width="160">
          <template #default="{ row }">
            <div class="member-cell">
              <img v-if="row.headimg" :src="assetUrl(row.headimg)" class="member-avatar" @error="onAvatarError" alt="" />
              <span>{{ row.nickname || ('会员' + row.mid) }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="变更积分" width="110">
          <template #default="{ row }">
            <span :class="Number(row.score) >= 0 ? 'money-plus' : 'money-minus'">
              {{ Number(row.score) >= 0 ? '+' : '' }}{{ row.score }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="变更后剩余" width="110">
          <template #default="{ row }">{{ row.after_score }}</template>
        </el-table-column>
        <el-table-column label="变更时间" width="150">
          <template #default="{ row }">{{ row.create_time ? formatTime(row.create_time) : '-' }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button v-if="row.can_cancel" link type="warning" @click="handleRowCancel(row)">撤销</el-button>
              <el-button v-if="row.can_delete" link type="danger" @click="handleRowDelete(row)">删除</el-button>
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
.toolbar-card {
  margin-bottom: 8px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 8px 12px;
}
.toolbar-card :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-card :deep(.el-form-item__label) {
  padding-right: 6px;
}
.search-icon-btn {
  padding: 8px 14px;
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
.table-card :deep(.el-table__header-wrapper th.el-table__cell) {
  background: #e6e6e6;
}
.table-card :deep(.el-table .cell) {
  text-align: left;
  padding-left: 6px;
  padding-right: 6px;
}
.table-card :deep(.el-table td.el-table__cell) {
  padding: 3px 0;
}
.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
.member-avatar {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  object-fit: cover;
  border: 1px solid #ebeef5;
  flex-shrink: 0;
}
.row-ops {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 2px 6px;
  white-space: nowrap;
  align-items: center;
}
.row-ops :deep(.el-button) {
  height: auto;
  padding: 0;
  margin: 0;
  font-size: 12px;
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
  margin-top: 12px;
  justify-content: flex-end;
}
</style>
