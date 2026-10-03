<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  auditCommissionWithdraw,
  deleteCommissionLog,
  deleteCommissionWithdraw,
  exportCommissionLog,
  exportCommissionWithdraw,
  getCommissionList,
  getCommissionWithdrawList,
} from '../../api/finance'
import type { CommissionItem, MoneyLogStat, WithdrawItem, WithdrawStat } from '../../api/finance'
import { assetUrl } from '../../api/upload'
import { formatTime } from '../../utils/time'

/**
 * 佣金双视图工厂（保留原签名）：
 * mode=log 佣金明细（契约第8页）；mode=withdraw 佣金提现（契约第9页）
 * 菜单「佣金明细 / 佣金提现」两项共用本组件，各自独立实例
 */
const props = withDefaults(defineProps<{ mode?: 'log' | 'withdraw'; title?: string }>(), {
  mode: 'log',
  title: '佣金明细',
})

const loading = ref(false)
const exporting = ref(false)
const total = ref(0)

const STATUS_OPTIONS = [
  { label: '全部', value: '' },
  { label: '审核中', value: '0' },
  { label: '已审核', value: '1' },
  { label: '已驳回', value: '2' },
  { label: '已打款', value: '3' },
]

// ---- 佣金明细 ----
const logData = ref<CommissionItem[]>([])
const logSelected = ref<CommissionItem[]>([])
const logStat = ref<MoneyLogStat>({ income: 0, expense: 0, net: 0 })
const logQuery = reactive({
  page: 1,
  pagesize: 10,
  mid: '',
  nickname: '',
  remark: '',
  timeRange: null as [string, string] | null,
})

// ---- 佣金提现 ----
const withdrawData = ref<WithdrawItem[]>([])
const withdrawSelected = ref<WithdrawItem[]>([])
const withdrawStat = ref<WithdrawStat>({ pending: 0, approved: 0, rejected: 0, paid: 0, pending_amount: 0 })
const withdrawQuery = reactive({
  page: 1,
  pagesize: 10,
  mid: '',
  nickname: '',
  status: '',
  timeRange: null as [string, string] | null,
})

function isWithdraw() {
  return props.mode === 'withdraw'
}

function buildLogParams() {
  return {
    page: logQuery.page,
    pagesize: logQuery.pagesize,
    mid: logQuery.mid ? Number(logQuery.mid) : undefined,
    nickname: logQuery.nickname || undefined,
    remark: logQuery.remark || undefined,
    start_date: logQuery.timeRange?.[0] || undefined,
    end_date: logQuery.timeRange?.[1] || undefined,
  }
}

function buildWithdrawParams() {
  return {
    page: withdrawQuery.page,
    pagesize: withdrawQuery.pagesize,
    mid: withdrawQuery.mid ? Number(withdrawQuery.mid) : undefined,
    nickname: withdrawQuery.nickname || undefined,
    status: withdrawQuery.status || undefined,
    start_date: withdrawQuery.timeRange?.[0] || undefined,
    end_date: withdrawQuery.timeRange?.[1] || undefined,
  }
}

async function fetchLog() {
  loading.value = true
  try {
    const data = await getCommissionList(buildLogParams())
    logData.value = data.list || []
    total.value = data.total || 0
    logStat.value = data.stat || { income: 0, expense: 0, net: 0 }
  } catch {
    // 拦截器统一提示
  } finally {
    loading.value = false
  }
}

async function fetchWithdraw() {
  loading.value = true
  try {
    const data = await getCommissionWithdrawList(buildWithdrawParams())
    withdrawData.value = data.list || []
    total.value = data.total || 0
    withdrawStat.value = data.stat || { pending: 0, approved: 0, rejected: 0, paid: 0, pending_amount: 0 }
  } catch {
    // 拦截器统一提示
  } finally {
    loading.value = false
  }
}

function fetchList() {
  if (isWithdraw()) fetchWithdraw()
  else fetchLog()
}

function handleSearch() {
  if (isWithdraw()) withdrawQuery.page = 1
  else logQuery.page = 1
  fetchList()
}

function handlePageChange(page: number) {
  if (isWithdraw()) withdrawQuery.page = page
  else logQuery.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  if (isWithdraw()) {
    withdrawQuery.pagesize = size
    withdrawQuery.page = 1
  } else {
    logQuery.pagesize = size
    logQuery.page = 1
  }
  fetchList()
}

function handleLogSelection(rows: CommissionItem[]) {
  logSelected.value = rows
}
function handleWithdrawSelection(rows: WithdrawItem[]) {
  withdrawSelected.value = rows
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
    if (isWithdraw()) {
      const blob = await exportCommissionWithdraw(buildWithdrawParams())
      downloadBlob(blob, `佣金提现_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
    } else {
      const blob = await exportCommissionLog(buildLogParams())
      downloadBlob(blob, `佣金明细_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
    }
  } catch {
    // 拦截器统一提示
  } finally {
    exporting.value = false
  }
}

// ============ 佣金明细 删除 ============
async function doLogDelete(ids: number[]) {
  await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    type: 'warning',
    confirmButtonText: '确 定',
    cancelButtonText: '取 消',
  })
  await deleteCommissionLog({ ids })
  ElMessage.success('删除成功')
  fetchLog()
}
function handleLogBatchDelete() {
  if (logSelected.value.length === 0) {
    ElMessage.warning('请先勾选数据')
    return
  }
  doLogDelete(logSelected.value.map((r) => r.id)).catch(() => {})
}
function handleLogRowDelete(row: CommissionItem) {
  doLogDelete([row.id]).catch(() => {})
}

// ============ 佣金提现 审核 / 驳回 / 删除 ============
async function handleWithdrawAudit(row: WithdrawItem) {
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
    await auditCommissionWithdraw({ id: row.id, pass: 1 })
    ElMessage.success('已通过，待打款')
    fetchWithdraw()
  } catch {
    // 拦截器统一提示
  }
}

async function handleWithdrawReject(row: WithdrawItem) {
  let reason = ''
  try {
    const res = await ElMessageBox.prompt('请填写驳回原因', '驳回佣金提现', {
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
    await auditCommissionWithdraw({ id: row.id, pass: 2, reason })
    ElMessage.success('已驳回，佣金已返还会员')
    fetchWithdraw()
  } catch {
    // 拦截器统一提示
  }
}

async function doWithdrawDelete(ids: number[]) {
  await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    type: 'warning',
    confirmButtonText: '确 定',
    cancelButtonText: '取 消',
  })
  await deleteCommissionWithdraw({ ids })
  ElMessage.success('删除成功')
  fetchWithdraw()
}
function handleWithdrawBatchDelete() {
  if (withdrawSelected.value.length === 0) {
    ElMessage.warning('请先勾选数据')
    return
  }
  doWithdrawDelete(withdrawSelected.value.map((r) => r.id)).catch(() => {})
}
function handleWithdrawRowDelete(row: WithdrawItem) {
  doWithdrawDelete([row.id]).catch(() => {})
}

// 收款账号 / 身份信息
function accountText(row: WithdrawItem): string {
  if (row.paytype === '支付宝') return [row.aliaccountname, row.aliaccount].filter(Boolean).join(' ') || '-'
  if (row.paytype === '银行卡') return [row.bankname, row.bankcarduser, row.bankcardnum].filter(Boolean).join(' - ') || '-'
  return row.paytype || '-'
}
function identityText(row: WithdrawItem): string {
  return [row.realname, row.usercard].filter(Boolean).join(' / ') || '-'
}
// 操作员（照 canzhao：有 uid 才展示）
function operatorText(row: CommissionItem): string {
  if (row.uid) return `${row.un || ''}(操作员ID:${row.uid})`
  return row.un || '-'
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- ===================== 佣金明细 ===================== -->
    <template v-if="!isWithdraw()">
      <el-card shadow="never" class="toolbar-card">
        <div class="toolbar-line">
          <div class="toolbar-actions">
            <el-button @click="handleLogBatchDelete">删除</el-button>
            <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
          </div>
          <div class="toolbar-search">
            <el-input v-model="logQuery.mid" placeholder="会员ID" clearable class="tw-mid" @keyup.enter="handleSearch" />
            <el-input v-model="logQuery.nickname" placeholder="昵称" clearable class="tw-nick" @keyup.enter="handleSearch" />
            <el-input v-model="logQuery.remark" placeholder="备注" clearable class="tw-remark" @keyup.enter="handleSearch" />
            <el-date-picker
              v-model="logQuery.timeRange"
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

      <el-card shadow="never" class="table-card">
        <div class="table-stat">
          <span>佣金收入 <b>￥{{ Number(logStat.income).toFixed(2) }}</b></span>
          <span>佣金支出 <b>￥{{ Number(logStat.expense).toFixed(2) }}</b></span>
          <span>净流入 <b>￥{{ Number(logStat.net).toFixed(2) }}</b></span>
        </div>
        <div class="table-scroll">
          <el-table v-loading="loading" :data="logData" border size="small" @selection-change="handleLogSelection">
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
            <el-table-column label="佣金来源" min-width="140" show-overflow-tooltip>
              <template #default="{ row }">
                <span v-if="row.frommid > 0">{{ row.from_nickname || ('会员' + row.frommid) }}</span>
                <span v-else>-</span>
              </template>
            </el-table-column>
            <el-table-column label="变更金额" width="110" sortable="custom">
              <template #default="{ row }">
                <span :class="Number(row.commission) >= 0 ? 'money-plus' : 'money-minus'">
                  {{ Number(row.commission) >= 0 ? '+' : '' }}{{ Number(row.commission).toFixed(2) }}
                </span>
              </template>
            </el-table-column>
            <el-table-column label="变更后金额" width="110" sortable="custom">
              <template #default="{ row }">{{ Number(row.after).toFixed(2) }}</template>
            </el-table-column>
            <el-table-column label="变更时间" width="150" sortable="custom">
              <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
            </el-table-column>
            <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip>
              <template #default="{ row }">{{ row.remark || '-' }}</template>
            </el-table-column>
            <el-table-column label="操作员" width="140" show-overflow-tooltip>
              <template #default="{ row }">{{ operatorText(row) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="80">
              <template #default="{ row }">
                <div class="row-ops">
                  <el-button link type="danger" @click="handleLogRowDelete(row)">删除</el-button>
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
          :current-page="logQuery.page"
          :page-size="logQuery.pagesize"
          :page-sizes="[10, 20, 50, 100]"
          @current-change="handlePageChange"
          @size-change="handleSizeChange"
        />
      </el-card>
    </template>

    <!-- ===================== 佣金提现 ===================== -->
    <template v-else>
      <el-card shadow="never" class="toolbar-card">
        <div class="toolbar-line">
          <div class="toolbar-actions">
            <el-button @click="handleWithdrawBatchDelete">删除</el-button>
            <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
          </div>
          <div class="toolbar-search">
            <el-input v-model="withdrawQuery.mid" placeholder="会员ID" clearable class="tw-mid" @keyup.enter="handleSearch" />
            <el-input v-model="withdrawQuery.nickname" placeholder="昵称" clearable class="tw-nick" @keyup.enter="handleSearch" />
            <el-select v-model="withdrawQuery.status" placeholder="状态" class="tw-status">
              <el-option v-for="o in STATUS_OPTIONS" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
            <el-date-picker
              v-model="withdrawQuery.timeRange"
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

      <el-card shadow="never" class="table-card">
        <div class="table-stat">
          <span>待审核 <b>{{ withdrawStat.pending }}</b></span>
          <span>待打款 <b>{{ withdrawStat.approved }}</b></span>
          <span>已打款 <b>{{ withdrawStat.paid }}</b></span>
          <span>已驳回 <b>{{ withdrawStat.rejected }}</b></span>
          <span>待打款金额 <b>￥{{ Number(withdrawStat.pending_amount).toFixed(2) }}</b></span>
        </div>
        <div class="table-scroll">
          <el-table v-loading="loading" :data="withdrawData" border size="small" @selection-change="handleWithdrawSelection">
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
            <el-table-column label="操作" width="130">
              <template #default="{ row }">
                <div class="row-ops">
                  <el-button v-if="row.can_audit" link type="primary" @click="handleWithdrawAudit(row)">审核</el-button>
                  <el-button v-if="row.can_reject" link type="warning" @click="handleWithdrawReject(row)">驳回</el-button>
                  <el-button v-if="row.can_delete" link type="danger" @click="handleWithdrawRowDelete(row)">删除</el-button>
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
          :current-page="withdrawQuery.page"
          :page-size="withdrawQuery.pagesize"
          :page-sizes="[10, 20, 50, 100]"
          @current-change="handlePageChange"
          @size-change="handleSizeChange"
        />
      </el-card>
    </template>
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
.tw-remark { width: 130px; flex-shrink: 0; }
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