<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  auditWithdraw,
  deleteWithdraw,
  exportWithdraw,
  getWithdrawList,
  payWithdraw,
} from '../../api/finance'
import type { WithdrawItem, WithdrawStat } from '../../api/finance'
import { assetUrl } from '../../api/upload'
import { formatTime } from '../../utils/time'

/**
 * 余额提现（member_withdrawlog）对齐 canzhao money/withdrawlog.html
 * 工具栏[删除,导出EXCEL]；行操作按后端 can_* 布尔显隐
 */
const loading = ref(false)
const exporting = ref(false)
const tableData = ref<WithdrawItem[]>([])
const selectedRows = ref<WithdrawItem[]>([])
const total = ref(0)
const stat = ref<WithdrawStat>({ pending: 0, approved: 0, rejected: 0, paid: 0, pending_amount: 0 })

const STATUS_OPTIONS = [
  { label: '全部', value: '' },
  { label: '审核中', value: '0' },
  { label: '已审核', value: '1' },
  { label: '已驳回', value: '2' },
  { label: '已打款', value: '3' },
]

const query = reactive({
  page: 1,
  pagesize: 10,
  mid: '',
  nickname: '',
  status: '',
  timeRange: null as [string, string] | null,
})

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    mid: query.mid ? Number(query.mid) : undefined,
    nickname: query.nickname || undefined,
    status: query.status || undefined,
    start_date: query.timeRange?.[0] || undefined,
    end_date: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getWithdrawList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { pending: 0, approved: 0, rejected: 0, paid: 0, pending_amount: 0 }
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

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: WithdrawItem[]) {
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
    const blob = await exportWithdraw(buildParams())
    downloadBlob(blob, `余额提现_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    exporting.value = false
  }
}

// 收款账号（照 canzhao）
function accountText(row: WithdrawItem): string {
  if (row.paytype === '支付宝') return [row.aliaccountname, row.aliaccount].filter(Boolean).join(' ') || '-'
  if (row.paytype === '银行卡') return [row.bankname, row.bankcarduser, row.bankcardnum].filter(Boolean).join(' - ') || '-'
  return row.paytype || '-'
}

// 身份信息
function identityText(row: WithdrawItem): string {
  return [row.realname, row.usercard].filter(Boolean).join(' / ') || '-'
}

// 审核通过（can_audit）
async function handleAudit(row: WithdrawItem) {
  try {
    await ElMessageBox.confirm('确定要审核通过吗?', '操作确认', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await auditWithdraw({ id: row.id, pass: 1 })
    ElMessage.success('已通过，待打款')
    fetchList()
  } catch {
    // 拦截器统一提示
  }
}

// 驳回（can_reject）：prompt 填原因
async function handleReject(row: WithdrawItem) {
  let reason = ''
  try {
    const res = await ElMessageBox.prompt('请填写驳回原因', '驳回提现', {
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
      inputPlaceholder: '请输入驳回原因',
      inputValidator: (v) => (v && v.trim() ? true : '驳回原因不能为空'),
    })
    reason = res.value
  } catch {
    return
  }
  try {
    await auditWithdraw({ id: row.id, pass: 2, reason })
    ElMessage.success('已驳回，金额已返还会员余额')
    fetchList()
  } catch {
    // 拦截器统一提示
  }
}

// 改为已打款（can_mark_paid）
async function handleMarkPaid(row: WithdrawItem) {
  try {
    await ElMessageBox.confirm('确定您已经通过其他方式打款给用户了吗？此操作仅修改状态，并不会进行打款。', '操作确认', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await payWithdraw(row.id)
    ElMessage.success('已标记为打款')
    fetchList()
  } catch {
    // 拦截器统一提示
  }
}

// 微信打款（can_wx_pay）
async function handleWxPay(row: WithdrawItem) {
  try {
    await ElMessageBox.confirm('确定要微信打款吗?', '操作确认', {
      type: 'warning',
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
    })
  } catch {
    return
  }
  try {
    await payWithdraw(row.id)
    ElMessage.success('打款成功')
    fetchList()
  } catch {
    // 拦截器统一提示
  }
}

async function doDelete(ids: number[]) {
  await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    type: 'warning',
    confirmButtonText: '确 定',
    cancelButtonText: '取 消',
  })
  await deleteWithdraw({ ids })
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

function handleRowDelete(row: WithdrawItem) {
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
          <el-select v-model="query.status" placeholder="状态" class="tw-status">
            <el-option v-for="o in STATUS_OPTIONS" :key="o.value" :label="o.label" :value="o.value" />
          </el-select>
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            class="tw-date"
          />
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
          </el-button>
        </div>
      </div>
    </el-card>

    <!-- 表格 -->
    <el-card shadow="never" class="table-card">
      <div class="table-stat">
        <span>待审核 <b>{{ stat.pending }}</b></span>
        <span>待打款 <b>{{ stat.approved }}</b></span>
        <span>已打款 <b>{{ stat.paid }}</b></span>
        <span>已驳回 <b>{{ stat.rejected }}</b></span>
        <span>待打款金额 <b>￥{{ Number(stat.pending_amount).toFixed(2) }}</b></span>
      </div>
      <div class="table-scroll">
        <el-table v-loading="loading" :data="tableData" border size="small" @selection-change="handleSelectionChange">
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
          <el-table-column prop="tel" label="手机号" width="120">
            <template #default="{ row }">{{ row.tel || '-' }}</template>
          </el-table-column>
          <el-table-column label="提现金额" width="110" sortable="custom">
            <template #default="{ row }">￥{{ Number(row.txmoney).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="打款金额" width="110" sortable="custom">
            <template #default="{ row }">￥{{ Number(row.money).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column prop="paytype" label="提现方式" width="100">
            <template #default="{ row }">{{ row.paytype || '-' }}</template>
          </el-table-column>
          <el-table-column label="收款账号" min-width="160" show-overflow-tooltip>
            <template #default="{ row }">{{ accountText(row) }}</template>
          </el-table-column>
          <el-table-column label="身份信息" min-width="130" show-overflow-tooltip>
            <template #default="{ row }">{{ identityText(row) }}</template>
          </el-table-column>
          <el-table-column label="提现时间" width="150" sortable="custom">
            <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="110">
            <template #default="{ row }">
              <el-tooltip v-if="row.status === 2 && row.reason" :content="`驳回原因：${row.reason}`" placement="top">
                <span>{{ row.status_text || '-' }}</span>
              </el-tooltip>
              <span v-else>{{ row.status_text || '-' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="160">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button v-if="row.can_audit" link type="primary" @click="handleAudit(row)">审核</el-button>
                <el-button v-if="row.can_mark_paid" link type="primary" @click="handleMarkPaid(row)">改为已打款</el-button>
                <el-button v-if="row.can_wx_pay" link type="primary" @click="handleWxPay(row)">微信打款</el-button>
                <el-button v-if="row.can_reject" link type="warning" @click="handleReject(row)">驳回</el-button>
                <el-button v-if="row.can_delete" link type="danger" @click="handleRowDelete(row)">删除</el-button>
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
.tw-status { width: 110px; flex-shrink: 0; }
.tw-date { width: 230px; flex-shrink: 0; }
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
.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}
.member-avatar {
  width: 32px;
  height: 32px;
  border-radius: 4px;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid #ebeef5;
}
.pagination {
  margin-top: 8px;
  justify-content: flex-end;
}
</style>