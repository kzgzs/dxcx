<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteYuyueMoneyLog,
  exportYuyueMoneyLog,
  getYuyueMoneyLogList,
} from '../../api/yuyue'
import { formatTime } from '../../utils/time'

interface MoneyRow {
  id: number
  uid: number
  realname?: string
  tel?: string
  money: number
  after: number
  createtime: number
  remark: string
}

const loading = ref(false)
const exporting = ref(false)
const tableData = ref<MoneyRow[]>([])
const total = ref(0)
const selected = ref<MoneyRow[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  uid: '' as number | '',
  realname: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueMoneyLogList({
      page: query.page,
      pagesize: query.pagesize,
      uid: query.uid === '' ? undefined : Number(query.uid),
      realname: query.realname || undefined,
    })
    tableData.value = (data.list || []) as MoneyRow[]
    total.value = data.total || 0
  } catch { /* 拦截器 */ } finally {
    loading.value = false
  }
}

// 服务人员下拉
const workerOptions = ref<{ id: number; realname: string }[]>([])
async function fetchWorkers() {
  try {
    const { getYuyueWorkerList } = await import('../../api/yuyue')
    const data = await getYuyueWorkerList({ page: 1, pagesize: 100 })
    const list = ((data as unknown as { list?: Record<string, unknown>[] }).list || []) as Record<string, unknown>[]
    workerOptions.value = list.map((w) => ({ id: Number(w.id), realname: String(w.realname || '') }))
  } catch { /* ignore */ }
}

function handleSearch() {
  query.page = 1
  fetchList()
}
function handleSelectionChange(rows: MoneyRow[]) {
  selected.value = rows
}

// 导出
function csvEscape(val: string): string {
  if (/[",\n]/.test(val)) return `"${val.replace(/"/g, '""')}"`
  return val
}
async function handleExport() {
  exporting.value = true
  try {
    const data = await exportYuyueMoneyLog({
      uid: query.uid === '' ? undefined : Number(query.uid),
      realname: query.realname || undefined,
    })
    if (!data.count) {
      ElMessage.warning('没有可导出的数据')
      return
    }
    const lines = [data.title, ...data.data].map((row) => row.map((c) => csvEscape(String(c))).join(','))
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `预约提成明细_${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success(`已导出 ${data.count} 条记录`)
  } catch { /* 拦截器 */ } finally {
    exporting.value = false
  }
}

// 删除
function handleBatchDelete() {
  if (!selected.value.length) {
    ElMessage.warning('请先勾选记录')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${selected.value.length} 条记录吗？`, '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteYuyueMoneyLog(selected.value.map((r) => r.id))
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}
function handleDelete(row: MoneyRow) {
  ElMessageBox.confirm('确定删除这条提成记录吗？', '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteYuyueMoneyLog([row.id])
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

onMounted(() => {
  fetchList()
  fetchWorkers()
})
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-inner">
        <div class="toolbar-left">
          <el-button @click="handleBatchDelete">删除</el-button>
          <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tf-label">服务人员</span>
          <el-select v-model="query.uid" placeholder="全部" clearable style="width: 140px">
            <el-option v-for="w in workerOptions" :key="w.id" :label="w.realname" :value="w.id" />
          </el-select>
          <span class="tf-label">昵称</span>
          <el-input v-model="query.realname" placeholder="请输入" style="width: 130px" @keyup.enter="handleSearch" />
          <button class="search-btn" title="搜索" @click="handleSearch">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M11.4 10.2a6 6 0 1 0-1.2 1.2l3.4 3.4 1.2-1.2-3.4-3.4zM7 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
          </button>
        </div>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column label="服务人员" min-width="140">
          <template #default="{ row }">
            <div>{{ row.realname || `#${row.uid}` }}</div>
            <div v-if="row.tel" class="sub">{{ row.tel }}</div>
          </template>
        </el-table-column>
        <el-table-column label="变更金额" width="110" sortable>
          <template #default="{ row }">
            <span :class="Number(row.money) > 0 ? 'money-plus' : ''">{{ Number(row.money) > 0 ? '+' : '' }}{{ Number(row.money).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="变更后金额" width="110" sortable>
          <template #default="{ row }">{{ Number(row.after).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="变更时间" width="150" sortable>
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager" background layout="total, sizes, prev, pager, next, jumper"
        :total="total" v-model:current-page="query.page" v-model:page-size="query.pagesize"
        :page-sizes="[10, 20, 50]" @current-change="fetchList" @size-change="handleSearch"
      />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
.toolbar-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap; }
.toolbar-left { display: flex; gap: 8px; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.tf-label { font-size: 13px; color: #606266; white-space: nowrap; }
.search-btn { width: 30px; height: 30px; border: none; background: #67c23a; color: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 3px; }
.el-card { border-radius: 0; }
.el-table :deep(th) { background: #e6e6e6; text-align: left; }
.el-table :deep(td .cell) { text-align: left; }
.pager { margin-top: 12px; justify-content: flex-end; }
.sub { color: #909399; font-size: 12px; }
.money-plus { color: #67c23a; font-weight: 600; }
</style>
