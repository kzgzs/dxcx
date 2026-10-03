<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  auditYuyueWithdrawLog,
  deleteYuyueWithdrawLog,
  getYuyueWithdrawLogList,
  payYuyueWithdrawLog,
} from '../../api/yuyue'
import { formatTime } from '../../utils/time'

interface WithdrawRow {
  id: number
  uid: number
  realname?: string
  tel?: string
  txmoney: number
  money: number
  paytype: string
  weixin?: string
  aliaccount?: string
  bankname?: string
  bankcarduser?: string
  bankcardnum?: string
  createtime: number
  status: number
  reason?: string
  status_text?: string
}

// 状态：0审核中 1已审核 2已驳回 3已打款
const STATUS_TAG: Record<number, 'warning' | 'primary' | 'danger' | 'success' | 'info'> = {
  0: 'warning',
  1: 'primary',
  2: 'danger',
  3: 'success',
}
const STATUS_TEXT: Record<number, string> = {
  0: '审核中',
  1: '已审核',
  2: '已驳回',
  3: '已打款',
}

const loading = ref(false)
const tableData = ref<WithdrawRow[]>([])
const total = ref(0)
const selected = ref<WithdrawRow[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  uid: '' as number | '',
  status: '' as number | '',
})

const workerOptions = ref<{ id: number; realname: string }[]>([])
async function fetchWorkers() {
  try {
    const { getYuyueWorkerList } = await import('../../api/yuyue')
    const data = await getYuyueWorkerList({ page: 1, pagesize: 100 })
    const list = ((data as unknown as { list?: Record<string, unknown>[] }).list || []) as Record<string, unknown>[]
    workerOptions.value = list.map((w) => ({ id: Number(w.id), realname: String(w.realname || '') }))
  } catch { /* ignore */ }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueWithdrawLogList({
      page: query.page,
      pagesize: query.pagesize,
      uid: query.uid === '' ? undefined : Number(query.uid),
      status: query.status === '' ? undefined : Number(query.status),
    })
    tableData.value = (data.list || []) as WithdrawRow[]
    total.value = data.total || 0
  } catch { /* 拦截器 */ } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}
function handleSelectionChange(rows: WithdrawRow[]) {
  selected.value = rows
}

// 审核弹窗
const auditVisible = ref(false)
const auditSubmitting = ref(false)
const auditRow = ref<WithdrawRow | null>(null)
const auditForm = reactive({ pass: 1, reason: '' })

function openAudit(row: WithdrawRow, defPass = 1) {
  auditRow.value = row
  auditForm.pass = defPass
  auditForm.reason = ''
  auditVisible.value = true
}

async function submitAudit() {
  if (!auditRow.value) return
  if (auditForm.pass === 2 && !auditForm.reason.trim()) {
    ElMessage.warning('驳回时请填写原因')
    return
  }
  auditSubmitting.value = true
  try {
    await auditYuyueWithdrawLog(auditRow.value.id, auditForm.pass, auditForm.reason)
    ElMessage.success(auditForm.pass === 1 ? '已通过，待打款' : '已驳回，金额已退回人员余额')
    auditVisible.value = false
    fetchList()
  } catch { /* 拦截器 */ } finally {
    auditSubmitting.value = false
  }
}

// 打款（改为已打款 / 微信打款）
async function handlePay(row: WithdrawRow, label: string) {
  try {
    await ElMessageBox.confirm(`确认${label} ￥${Number(row.txmoney).toFixed(2)}？`, '提现打款', {
      type: 'warning',
      confirmButtonText: '确认',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  try {
    await payYuyueWithdrawLog(row.id)
    ElMessage.success('已标记为打款')
    fetchList()
  } catch { /* 拦截器 */ }
}

// 删除
function handleBatchDelete() {
  if (!selected.value.length) {
    ElMessage.warning('请先勾选记录')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${selected.value.length} 条记录吗？`, '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteYuyueWithdrawLog(selected.value.map((r) => r.id))
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}
function handleDelete(row: WithdrawRow) {
  ElMessageBox.confirm('确定删除这条提现记录吗？', '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteYuyueWithdrawLog([row.id])
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function accountText(row: WithdrawRow): string {
  if (row.paytype === '支付宝') return row.aliaccount || '-'
  if (row.paytype === '银行卡') return [row.bankname, row.bankcarduser, row.bankcardnum].filter(Boolean).join(' - ') || '-'
  if (row.paytype === '微信钱包' || row.paytype === '微信') return row.weixin || '微信钱包'
  return row.paytype || '-'
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
          <el-button>导出EXCEL</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tf-label">服务人员</span>
          <el-select v-model="query.uid" placeholder="全部" clearable style="width: 140px">
            <el-option v-for="w in workerOptions" :key="w.id" :label="w.realname" :value="w.id" />
          </el-select>
          <span class="tf-label">状态</span>
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="审核中" :value="0" />
            <el-option label="已审核" :value="1" />
            <el-option label="已驳回" :value="2" />
            <el-option label="已打款" :value="3" />
          </el-select>
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
        <el-table-column label="提现金额" width="100">
          <template #default="{ row }">{{ Number(row.txmoney).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="打款金额" width="100">
          <template #default="{ row }">{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="paytype" label="提现方式" width="100">
          <template #default="{ row }">{{ row.paytype || '-' }}</template>
        </el-table-column>
        <el-table-column label="收款账号" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ accountText(row) }}</template>
        </el-table-column>
        <el-table-column label="提现时间" width="150" sortable>
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">{{ STATUS_TEXT[row.status] || row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="170">
          <template #default="{ row }">
            <div class="row-ops">
              <template v-if="row.status === 0">
                <el-button link type="warning" @click="openAudit(row)">审核</el-button>
                <el-button link type="danger" @click="openAudit(row, 2)">驳回</el-button>
              </template>
              <template v-else-if="row.status === 1">
                <el-button link type="primary" @click="handlePay(row, '改为已打款')">改为已打款</el-button>
                <el-button link type="primary" @click="handlePay(row, '微信打款')">微信打款</el-button>
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

    <!-- 审核弹窗 -->
    <el-dialog v-model="auditVisible" title="提现审核" width="460px">
      <template v-if="auditRow">
        <div class="audit-card">
          <div class="arow"><span class="alab">人员</span><span>{{ auditRow.realname || `人员${auditRow.uid}` }}（{{ auditRow.tel || '-' }}）</span></div>
          <div class="arow"><span class="alab">提现金额</span><span class="strong">￥{{ Number(auditRow.txmoney).toFixed(2) }}</span></div>
          <div class="arow"><span class="alab">收款方式</span><span>{{ accountText(auditRow) }}</span></div>
        </div>
        <el-radio-group v-model="auditForm.pass" class="audit-radio">
          <el-radio :value="1">通过</el-radio>
          <el-radio :value="2">驳回（退回人员余额）</el-radio>
        </el-radio-group>
        <el-input v-model="auditForm.reason" type="textarea" :rows="3" :placeholder="auditForm.pass === 2 ? '请填写驳回原因（必填）' : '备注（选填）'" />
      </template>
      <template #footer>
        <el-button @click="auditVisible = false">取消</el-button>
        <el-button type="primary" :loading="auditSubmitting" @click="submitAudit">提交</el-button>
      </template>
    </el-dialog>
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
.audit-card { background: #f8fafc; border-radius: 6px; padding: 12px 16px; font-size: 13px; }
.arow { display: flex; gap: 8px; padding: 4px 0; }
.alab { color: #909399; width: 70px; flex-shrink: 0; }
.strong { font-weight: 700; color: #f56c6c; }
.audit-radio { display: block; margin: 14px 0 8px; }
</style>
