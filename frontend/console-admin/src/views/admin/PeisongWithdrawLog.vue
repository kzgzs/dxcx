<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  delPeisongWithdrawLog,
  getPeisongUserList,
  getPeisongWithdrawLogList,
  setstPeisongWithdrawLog,
} from '../../api/peisong'
import type { PeisongWithdrawLogItem } from '../../api/peisong'
import { formatTime } from '../../utils/time'

// 租户后台 - 同城配送·提现记录（照截图：左 删除/导出EXCEL；右 配送员/状态/搜索；
// 状态机：0审核中 1已审核 2已驳回 3已打款 4待用户确认；
// 行操作：审核中→审核/驳回；已审核→改为已打款/微信打款(仅微信钱包)；其余行仅删除）

const loading = ref(false)
const tableData = ref<PeisongWithdrawLogItem[]>([])
const total = ref(0)
const selected = ref<PeisongWithdrawLogItem[]>([])
const riderOptions = ref<{ id: number; realname: string }[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  uid: '' as number | '',
  status: '' as number | '',
})

const STATUS_TAG: Record<number, 'warning' | 'primary' | 'danger' | 'success' | 'info'> = {
  0: 'info',
  1: 'primary',
  2: 'info',
  3: 'success',
  4: 'warning',
}

async function fetchRiders() {
  try {
    const data = await getPeisongUserList({ page: 1, pagesize: 100 })
    riderOptions.value = (data.list || []).map((u) => ({
      id: Number(u.id),
      realname: String(u.realname || ''),
    }))
  } catch { /* 拦截器 */ }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getPeisongWithdrawLogList({
      page: query.page,
      pagesize: query.pagesize,
      uid: query.uid === '' ? undefined : query.uid,
      status: query.status === '' ? undefined : query.status,
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

/** 收款账号（照 canzhao：支付宝→aliaccount；银行卡→银行-持卡人-卡号；其余为空） */
function accountOf(row: PeisongWithdrawLogItem): string {
  if (row.paytype === '支付宝') return row.aliaccount || '-'
  if (row.paytype === '银行卡') {
    return [row.bankname, row.bankcarduser, row.bankcardnum].filter(Boolean).join(' - ') || '-'
  }
  return ''
}

/** 通用确认弹窗（照截图：标题"操作确认" + 警告图标 + 确定/取消） */
async function confirmAction(message: string): Promise<boolean> {
  try {
    await ElMessageBox.confirm(message, '操作确认', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消',
    })
    return true
  } catch {
    return false
  }
}

/** 审核通过（st=1） */
async function handleAudit(row: PeisongWithdrawLogItem) {
  if (!(await confirmAction('确定要审核通过吗?'))) return
  await setstPeisongWithdrawLog({ id: row.id, st: 1 })
  ElMessage.success('已审核')
  fetchList()
}

/** 驳回（st=2，原因弹窗，后端返还余额） */
async function handleReject(row: PeisongWithdrawLogItem) {
  let reason = ''
  try {
    const r = await ElMessageBox.prompt('请输入驳回原因', '操作确认', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      inputPlaceholder: '驳回原因',
      type: 'warning',
    })
    reason = r.value || ''
  } catch {
    return
  }
  await setstPeisongWithdrawLog({ id: row.id, st: 2, reason })
  ElMessage.success('已驳回，余额已返还')
  fetchList()
}

/** 设为已打款（st=3，仅改状态不打款） */
async function handleSetPaid(row: PeisongWithdrawLogItem) {
  if (!(await confirmAction('确定您已经通过其他方式打款给用户了吗？此操作仅修改状态，并不会进行打款。'))) return
  await setstPeisongWithdrawLog({ id: row.id, st: 3 })
  ElMessage.success('已设为已打款')
  fetchList()
}

/** 微信打款（st=10，mock 即时成功） */
async function handleWechatPay(row: PeisongWithdrawLogItem) {
  if (!(await confirmAction('确定要微信打款吗?'))) return
  const rs = await setstPeisongWithdrawLog({ id: row.id, st: 10 })
  ElMessage.success(rs && (rs as { mock?: boolean }).mock ? '打款成功（mock）' : '打款成功')
  fetchList()
}

async function handleDelete(row?: PeisongWithdrawLogItem) {
  const ids = row ? [row.id] : selected.value.map((v) => v.id)
  if (!ids.length) {
    ElMessage.warning('请先选择记录')
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
  await delPeisongWithdrawLog(ids)
  ElMessage.success('删除成功')
  fetchList()
}

/** 导出EXCEL（按当前筛选全量 CSV） */
async function handleExport() {
  try {
    const data = await getPeisongWithdrawLogList({
      page: 1,
      pagesize: 100000,
      uid: query.uid === '' ? undefined : query.uid,
      status: query.status === '' ? undefined : query.status,
    })
    const rows = data.list || []
    const header = ['配送员', '提现金额', '打款金额', '提现方式', '收款账号', '提现时间', '状态']
    const lines = rows.map((r) => [
      `${r.realname || ''} ${r.tel || ''}`.trim(),
      String(r.txmoney),
      String(r.money),
      r.paytype || '',
      accountOf(r),
      r.createtime ? formatTime(r.createtime) : '',
      r.status_name || '',
    ])
    const csv = '﻿' + [header, ...lines].map((l) => l.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = '提现记录.csv'
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
    <!-- 一行工具条：左 删除/导出EXCEL；右 配送员/状态/搜索 -->
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-inner">
        <div class="toolbar-left">
          <el-button @click="handleDelete()">删除</el-button>
          <el-button @click="handleExport">导出EXCEL</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tf-label">配送员</span>
          <el-select v-model="query.uid" placeholder="全部" clearable style="width: 140px">
            <el-option v-for="u in riderOptions" :key="u.id" :label="u.realname" :value="u.id" />
          </el-select>
          <span class="tf-label">状态</span>
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="审核中" :value="0" />
            <el-option label="已审核" :value="1" />
            <el-option label="已驳回" :value="2" />
            <el-option label="已打款" :value="3" />
            <el-option label="待用户确认" :value="4" />
          </el-select>
          <button class="search-btn" title="搜索" @click="handleSearch">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M11.4 10.2a6 6 0 1 0-1.2 1.2l3.4 3.4 1.2-1.2-3.4-3.4zM7 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
          </button>
        </div>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="(rows: PeisongWithdrawLogItem[]) => (selected = rows)">
        <el-table-column type="selection" width="40" />
        <el-table-column label="配送员" min-width="140">
          <template #default="{ row }">
            <div>{{ row.realname || '-' }}</div>
            <div v-if="row.tel" class="sub">{{ row.tel }}</div>
          </template>
        </el-table-column>
        <el-table-column label="提现金额" width="100">
          <template #default="{ row }">{{ Number(row.txmoney).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="打款金额" width="100" sortable>
          <template #default="{ row }">{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="paytype" label="提现方式" width="110">
          <template #default="{ row }">{{ row.paytype || '-' }}</template>
        </el-table-column>
        <el-table-column label="收款账号" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ accountOf(row) || '-' }}</template>
        </el-table-column>
        <el-table-column label="提现时间" width="150" sortable>
          <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">{{ row.status_name || row.status }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="190">
          <template #default="{ row }">
            <div class="row-ops">
              <template v-if="row.status === 0">
                <el-button link type="primary" @click="handleAudit(row)">审核</el-button>
                <el-button link type="warning" @click="handleReject(row)">驳回</el-button>
              </template>
              <template v-else-if="row.status === 1">
                <el-button link type="primary" @click="handleSetPaid(row)">改为已打款</el-button>
                <el-button v-if="row.paytype === '微信钱包'" link type="primary" @click="handleWechatPay(row)">微信打款</el-button>
              </template>
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
</style>
