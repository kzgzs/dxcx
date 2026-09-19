<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  auditCommissionWithdraw,
  getCommissionList,
  getCommissionWithdrawList,
} from '../../api/finance'
import type { CommissionItem, MoneyLogStat, WithdrawItem, WithdrawStat } from '../../api/finance'
import { formatTime } from '../../utils/time'

/**
 * 佣金双视图：mode=log 佣金明细（只读+统计）；mode=withdraw 佣金提现（审核，驳回返还佣金）
 * 菜单「佣金明细 / 佣金提现」两项共用本组件，各自独立实例
 */
const props = withDefaults(defineProps<{ mode?: 'log' | 'withdraw'; title?: string }>(), {
  mode: 'log',
  title: '佣金明细',
})

const loading = ref(false)
const total = ref(0)

// ---- 佣金明细 ----
const logData = ref<CommissionItem[]>([])
const logStat = ref<MoneyLogStat>({ income: 0, expense: 0, net: 0 })
const logQuery = reactive({
  page: 1,
  pagesize: 10,
  nickname: '',
  mid: '',
  fhtype: '',
  timeRange: null as [string, string] | null,
})

// ---- 佣金提现 ----
const WITHDRAW_TAG: Record<number, 'warning' | 'primary' | 'danger' | 'success' | 'info'> = {
  0: 'warning',
  1: 'primary',
  2: 'danger',
  3: 'success',
  4: 'info',
}
const withdrawData = ref<WithdrawItem[]>([])
const withdrawStat = ref<WithdrawStat>({ pending: 0, approved: 0, rejected: 0, paid: 0, pending_amount: 0 })
const withdrawQuery = reactive({
  page: 1,
  pagesize: 10,
  status: '',
  nickname: '',
  mid: '',
  timeRange: null as [string, string] | null,
})

async function fetchLog() {
  loading.value = true
  try {
    const data = await getCommissionList({
      page: logQuery.page,
      pagesize: logQuery.pagesize,
      nickname: logQuery.nickname || undefined,
      mid: logQuery.mid ? Number(logQuery.mid) : undefined,
      fhtype: logQuery.fhtype || undefined,
      start_date: logQuery.timeRange?.[0] || undefined,
      end_date: logQuery.timeRange?.[1] || undefined,
    })
    logData.value = data.list || []
    total.value = data.total || 0
    logStat.value = data.stat || { income: 0, expense: 0, net: 0 }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function fetchWithdraw() {
  loading.value = true
  try {
    const data = await getCommissionWithdrawList({
      page: withdrawQuery.page,
      pagesize: withdrawQuery.pagesize,
      status: withdrawQuery.status || undefined,
      nickname: withdrawQuery.nickname || undefined,
      mid: withdrawQuery.mid ? Number(withdrawQuery.mid) : undefined,
      start_date: withdrawQuery.timeRange?.[0] || undefined,
      end_date: withdrawQuery.timeRange?.[1] || undefined,
    })
    withdrawData.value = data.list || []
    total.value = data.total || 0
    withdrawStat.value = data.stat || { pending: 0, approved: 0, rejected: 0, paid: 0, pending_amount: 0 }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function fetchList() {
  if (props.mode === 'withdraw') fetchWithdraw()
  else fetchLog()
}

function handleSearch() {
  if (props.mode === 'withdraw') withdrawQuery.page = 1
  else logQuery.page = 1
  fetchList()
}

function handleReset() {
  if (props.mode === 'withdraw') {
    withdrawQuery.status = ''
    withdrawQuery.nickname = ''
    withdrawQuery.mid = ''
    withdrawQuery.timeRange = null
    withdrawQuery.page = 1
  } else {
    logQuery.nickname = ''
    logQuery.mid = ''
    logQuery.fhtype = ''
    logQuery.timeRange = null
    logQuery.page = 1
  }
  fetchList()
}

function handlePageChange(page: number) {
  if (props.mode === 'withdraw') withdrawQuery.page = page
  else logQuery.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  if (props.mode === 'withdraw') {
    withdrawQuery.pagesize = size
    withdrawQuery.page = 1
  } else {
    logQuery.pagesize = size
    logQuery.page = 1
  }
  fetchList()
}

// ============ 佣金提现审核弹窗 ============
const auditVisible = ref(false)
const auditSubmitting = ref(false)
const auditRow = ref<WithdrawItem | null>(null)
const auditForm = reactive({ pass: 1, reason: '' })

function openAudit(row: WithdrawItem) {
  auditRow.value = row
  auditForm.pass = 1
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
    await auditCommissionWithdraw({
      id: auditRow.value.id,
      pass: auditForm.pass,
      reason: auditForm.reason,
    })
    ElMessage.success(auditForm.pass === 1 ? '已通过，待打款' : '已驳回，佣金已返还会员')
    auditVisible.value = false
    fetchWithdraw()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    auditSubmitting.value = false
  }
}

function accountText(row: WithdrawItem): string {
  if (row.paytype === '支付宝') return `${row.aliaccountname || ''} ${row.aliaccount || ''}`.trim() || '-'
  if (row.paytype === '银行卡') return [row.bankname, row.bankcarduser, row.bankcardnum].filter(Boolean).join(' - ') || '-'
  return row.paytype || '-'
}

function shortDate(ts: number): string {
  if (!ts) return '-'
  const full = formatTime(ts)
  return full.length >= 10 ? full.slice(2, 10) : full
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- ============ 视图一：佣金明细 ============ -->
    <template v-if="props.mode === 'log'">
      <el-row :gutter="12" class="stat-row">
        <el-col :span="8">
          <el-card shadow="never" class="stat-card">
            <div class="stat-label">佣金收入</div>
            <div class="stat-value success">￥{{ Number(logStat.income).toFixed(2) }}</div>
          </el-card>
        </el-col>
        <el-col :span="8">
          <el-card shadow="never" class="stat-card">
            <div class="stat-label">佣金支出（提现等）</div>
            <div class="stat-value danger">￥{{ Number(logStat.expense).toFixed(2) }}</div>
          </el-card>
        </el-col>
        <el-col :span="8">
          <el-card shadow="never" class="stat-card">
            <div class="stat-label">净流入</div>
            <div class="stat-value primary">￥{{ Number(logStat.net).toFixed(2) }}</div>
          </el-card>
        </el-col>
      </el-row>

      <el-card shadow="never" class="toolbar-card">
        <el-form inline @submit.prevent>
          <el-form-item label="昵称">
            <el-input v-model="logQuery.nickname" placeholder="会员昵称" clearable style="width: 140px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="会员ID">
            <el-input v-model="logQuery.mid" placeholder="ID" clearable style="width: 90px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="分红类型">
            <el-input v-model="logQuery.fhtype" placeholder="fhtype" clearable style="width: 120px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="时间">
            <el-date-picker
              v-model="logQuery.timeRange"
              type="daterange"
              range-separator="至"
              start-placeholder="开始"
              end-placeholder="结束"
              value-format="YYYY-MM-DD"
              style="width: 220px"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="handleSearch">搜索</el-button>
            <el-button @click="handleReset">重置</el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-table v-loading="loading" :data="logData" stripe border size="small">
          <el-table-column prop="id" label="ID" width="64" class-name="compact-col" />
          <el-table-column label="会员" min-width="120" show-overflow-tooltip>
            <template #default="{ row }">
              {{ row.nickname || `会员${row.mid}` }}
              <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
            </template>
          </el-table-column>
          <el-table-column label="变动佣金" width="96" class-name="compact-col">
            <template #default="{ row }">
              <span :class="Number(row.commission) >= 0 ? 'success-text' : 'danger-text'">
                {{ Number(row.commission) >= 0 ? '+' : '' }}{{ Number(row.commission).toFixed(2) }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="变动后" width="86" class-name="compact-col">
            <template #default="{ row }">{{ Number(row.after).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column prop="fhtype" label="分红类型" width="96" class-name="compact-col" show-overflow-tooltip>
            <template #default="{ row }">{{ row.fhtype || '-' }}</template>
          </el-table-column>
          <el-table-column label="来源会员" min-width="100" show-overflow-tooltip>
            <template #default="{ row }">
              <span v-if="row.frommid > 0">{{ row.from_nickname || `会员${row.frommid}` }}</span>
              <span v-else>-</span>
            </template>
          </el-table-column>
          <el-table-column prop="remark" label="备注" min-width="150" show-overflow-tooltip>
            <template #default="{ row }">{{ row.remark || '-' }}</template>
          </el-table-column>
          <el-table-column label="时间" width="92" class-name="compact-col">
            <template #default="{ row }">
              <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
                <span>{{ shortDate(row.createtime) }}</span>
              </el-tooltip>
              <span v-else>-</span>
            </template>
          </el-table-column>
        </el-table>
      </el-card>
    </template>

    <!-- ============ 视图二：佣金提现 ============ -->
    <template v-else>
      <el-row :gutter="12" class="stat-row">
        <el-col :span="6">
          <el-card shadow="never" class="stat-card">
            <div class="stat-label">待审核</div>
            <div class="stat-value warning">{{ withdrawStat.pending }}</div>
            <div class="stat-sub">金额 ￥{{ Number(withdrawStat.pending_amount).toFixed(2) }}</div>
          </el-card>
        </el-col>
        <el-col :span="6">
          <el-card shadow="never" class="stat-card">
            <div class="stat-label">待打款（含转账中）</div>
            <div class="stat-value primary">{{ withdrawStat.approved }}</div>
          </el-card>
        </el-col>
        <el-col :span="6">
          <el-card shadow="never" class="stat-card">
            <div class="stat-label">已打款</div>
            <div class="stat-value success">{{ withdrawStat.paid }}</div>
          </el-card>
        </el-col>
        <el-col :span="6">
          <el-card shadow="never" class="stat-card">
            <div class="stat-label">已驳回</div>
            <div class="stat-value danger">{{ withdrawStat.rejected }}</div>
          </el-card>
        </el-col>
      </el-row>

      <el-card shadow="never" class="toolbar-card">
        <el-form inline @submit.prevent>
          <el-form-item label="状态">
            <el-select v-model="withdrawQuery.status" placeholder="全部" clearable style="width: 110px">
              <el-option label="待审核" value="0" />
              <el-option label="待打款" value="1" />
              <el-option label="已驳回" value="2" />
              <el-option label="已打款" value="3" />
            </el-select>
          </el-form-item>
          <el-form-item label="昵称">
            <el-input v-model="withdrawQuery.nickname" placeholder="会员昵称" clearable style="width: 130px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="会员ID">
            <el-input v-model="withdrawQuery.mid" placeholder="ID" clearable style="width: 90px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="时间">
            <el-date-picker
              v-model="withdrawQuery.timeRange"
              type="daterange"
              range-separator="至"
              start-placeholder="开始"
              end-placeholder="结束"
              value-format="YYYY-MM-DD"
              style="width: 220px"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="handleSearch">搜索</el-button>
            <el-button @click="handleReset">重置</el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-table v-loading="loading" :data="withdrawData" stripe border size="small">
          <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
          <el-table-column label="会员" min-width="110" show-overflow-tooltip>
            <template #default="{ row }">
              {{ row.nickname || `会员${row.mid}` }}
              <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
            </template>
          </el-table-column>
          <el-table-column label="申请金额" width="86" class-name="compact-col">
            <template #default="{ row }">￥{{ Number(row.txmoney).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="实付金额" width="86" class-name="compact-col">
            <template #default="{ row }">￥{{ Number(row.money).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column prop="paytype" label="方式" width="70" class-name="compact-col">
            <template #default="{ row }">{{ row.paytype || '-' }}</template>
          </el-table-column>
          <el-table-column label="收款账户" min-width="150" show-overflow-tooltip>
            <template #default="{ row }">{{ accountText(row) }}</template>
          </el-table-column>
          <el-table-column label="申请时间" width="88" class-name="compact-col">
            <template #default="{ row }">
              <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
                <span>{{ shortDate(row.createtime) }}</span>
              </el-tooltip>
              <span v-else>-</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="84" class-name="compact-col">
            <template #default="{ row }">
              <el-tooltip v-if="row.status === 2 && row.reason" :content="`驳回原因：${row.reason}`" placement="top">
                <el-tag :type="WITHDRAW_TAG[row.status] || 'info'" size="small">{{ row.status_text }}</el-tag>
              </el-tooltip>
              <el-tag v-else :type="WITHDRAW_TAG[row.status] || 'info'" size="small">{{ row.status_text }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="80" fixed="right" class-name="compact-col">
            <template #default="{ row }">
              <el-button v-if="row.status === 0" link type="warning" @click="openAudit(row)">审核</el-button>
              <span v-else class="sub-text">-</span>
            </template>
          </el-table-column>
        </el-table>
      </el-card>

      <!-- 佣金提现审核弹窗 -->
      <el-dialog v-model="auditVisible" title="佣金提现审核" width="460px">
        <template v-if="auditRow">
          <div class="audit-info-card">
            <div class="audit-row">
              <span class="audit-label">会员</span>
              <span>{{ auditRow.nickname || `会员${auditRow.mid}` }}（{{ auditRow.tel || '-' }}）</span>
            </div>
            <div class="audit-row">
              <span class="audit-label">申请金额</span>
              <span class="amount-strong">￥{{ Number(auditRow.txmoney).toFixed(2) }}</span>
            </div>
            <div class="audit-row">
              <span class="audit-label">收款方式</span>
              <span>{{ accountText(auditRow) }}</span>
            </div>
          </div>
          <el-radio-group v-model="auditForm.pass" class="audit-radio">
            <el-radio :value="1">通过（待打款）</el-radio>
            <el-radio :value="2">驳回（返还佣金）</el-radio>
          </el-radio-group>
          <el-input
            v-model="auditForm.reason"
            type="textarea"
            :rows="3"
            :placeholder="auditForm.pass === 2 ? '请填写驳回原因（必填）' : '备注（选填）'"
            class="audit-remark"
          />
        </template>
        <template #footer>
          <el-button @click="auditVisible = false">取消</el-button>
          <el-button type="primary" :loading="auditSubmitting" @click="submitAudit">提交审核</el-button>
        </template>
      </el-dialog>
    </template>

    <!-- 分页（两视图共用） -->
    <el-pagination
      class="pagination"
      background
      layout="total, sizes, prev, pager, next, jumper"
      :total="total"
      :current-page="props.mode === 'withdraw' ? withdrawQuery.page : logQuery.page"
      :page-size="props.mode === 'withdraw' ? withdrawQuery.pagesize : logQuery.pagesize"
      :page-sizes="[10, 20, 50, 100]"
      @current-change="handlePageChange"
      @size-change="handleSizeChange"
    />
  </div>
</template>

<style scoped>
.stat-row {
  margin-bottom: 12px;
}

.stat-card .stat-label {
  color: #909399;
  font-size: 13px;
}

.stat-card .stat-value {
  font-size: 22px;
  font-weight: 700;
  margin-top: 4px;
}

.stat-card .stat-sub {
  color: #909399;
  font-size: 12px;
  margin-top: 2px;
}

.stat-value.primary {
  color: #409eff;
}

.stat-value.danger {
  color: #f56c6c;
}

.stat-value.success {
  color: #67c23a;
}

.stat-value.warning {
  color: #e6a23c;
}

.toolbar-card {
  margin-bottom: 12px;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 4px;
}

.success-text {
  color: #67c23a;
  font-weight: 600;
}

.danger-text {
  color: #f56c6c;
  font-weight: 600;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.audit-info-card {
  background: #f8fafc;
  border-radius: 8px;
  padding: 12px 16px;
  font-size: 13px;
}

.audit-row {
  display: flex;
  gap: 8px;
  padding: 4px 0;
}

.audit-label {
  color: #909399;
  width: 64px;
  flex-shrink: 0;
}

.amount-strong {
  color: #f56c6c;
  font-weight: 700;
}

.audit-radio {
  display: block;
  margin: 14px 0 8px;
}

.audit-remark {
  margin-top: 4px;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
