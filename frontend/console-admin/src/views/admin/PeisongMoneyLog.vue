<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  delPeisongMoneyLog,
  getPeisongMoneyLogList,
  getPeisongUserList,
} from '../../api/peisong'
import type { PeisongMoneyLogItem } from '../../api/peisong'
import { formatTime } from '../../utils/time'

// 租户后台 - 同城配送·提成明细（照截图：左 删除/导出EXCEL；右 配送员下拉/昵称/搜索；列 配送员/变更金额/变更后金额/变更时间/备注/操作）

const loading = ref(false)
const tableData = ref<PeisongMoneyLogItem[]>([])
const total = ref(0)
const selected = ref<PeisongMoneyLogItem[]>([])
const riderOptions = ref<{ id: number; realname: string; tel: string }[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  uid: '' as number | '',
  realname: '',
})

async function fetchRiders() {
  try {
    const data = await getPeisongUserList({ page: 1, pagesize: 100 })
    riderOptions.value = (data.list || []).map((u) => ({
      id: Number(u.id),
      realname: String(u.realname || ''),
      tel: String(u.tel || ''),
    }))
  } catch { /* 拦截器 */ }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getPeisongMoneyLogList({
      page: query.page,
      pagesize: query.pagesize,
      uid: query.uid === '' ? undefined : query.uid,
      realname: query.realname || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch { /* 拦截器 */ } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

async function handleDelete(row?: PeisongMoneyLogItem) {
  const ids = row ? [row.id] : selected.value.map((v) => v.id)
  if (!ids.length) {
    ElMessage.warning('请先选择明细')
    return
  }
  try {
    await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  await delPeisongMoneyLog(ids)
  ElMessage.success('删除成功')
  fetchList()
}

// 导出EXCEL（按当前筛选条件全量导出 CSV，Excel 可直接打开）
async function handleExport() {
  try {
    const data = await getPeisongMoneyLogList({
      page: 1,
      pagesize: 100000,
      uid: query.uid === '' ? undefined : query.uid,
      realname: query.realname || undefined,
    })
    const rows = data.list || []
    const header = ['配送员', '变更金额', '变更后剩余', '变更时间', '备注']
    const lines = rows.map((r) => [
      `${r.realname || ''} ${r.tel || ''}`.trim(),
      String(r.money),
      String(r.after),
      r.createtime ? formatTime(r.createtime) : '',
      r.remark || '',
    ])
    const csv = '﻿' + [header, ...lines].map((l) => l.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = '提成明细.csv'
    a.click()
    URL.revokeObjectURL(a.href)
    ElMessage.success(`已导出 ${rows.length} 条`)
  } catch { /* 拦截器 */ }
}

onMounted(() => {
  fetchList()
  fetchRiders()
})
</script>

<template>
  <div>
    <!-- 一行工具条：左 删除/导出EXCEL；右 配送员/昵称/搜索 -->
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-inner">
        <div class="toolbar-left">
          <el-button @click="handleDelete()">删除</el-button>
          <el-button @click="handleExport">导出EXCEL</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tf-label">配送员</span>
          <el-select v-model="query.uid" placeholder="全部" clearable style="width: 150px">
            <el-option
              v-for="u in riderOptions"
              :key="u.id"
              :label="u.realname"
              :value="u.id"
            />
          </el-select>
          <span class="tf-label">昵称</span>
          <el-input
            v-model="query.realname"
            placeholder=""
            clearable
            style="width: 160px"
            @keyup.enter="handleSearch"
          />
          <button class="search-btn" title="搜索" @click="handleSearch">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M11.4 10.2a6 6 0 1 0-1.2 1.2l3.4 3.4 1.2-1.2-3.4-3.4zM7 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
          </button>
        </div>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="(rows: PeisongMoneyLogItem[]) => (selected = rows)">
        <el-table-column type="selection" width="40" />
        <el-table-column label="配送员" min-width="160">
          <template #default="{ row }">
            <div>{{ row.realname || '-' }}</div>
            <div v-if="row.tel" class="sub">{{ row.tel }}</div>
          </template>
        </el-table-column>
        <el-table-column label="变更金额" width="110">
          <template #default="{ row }">
            <span :class="Number(row.money) >= 0 ? 'money-plus' : 'money-minus'">
              {{ Number(row.money) >= 0 ? '+' : '' }}{{ Number(row.money).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="变更后金额" width="110">
          <template #default="{ row }">{{ Number(row.after).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="变更时间" width="150" sortable>
          <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
            </div>
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
.row-ops { display: flex; gap: 10px; flex-wrap: nowrap; }
.money-plus { color: #2ba84a; font-weight: 600; }
.money-minus { color: #333; font-weight: 600; }
</style>
