<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { auditWithdraw, getWithdrawList, payWithdraw } from '../../api/finance'
import type { WithdrawItem, WithdrawStat } from '../../api/finance'
import { formatTime } from '../../utils/time'

// 状态机：0待审核 → 1待打款 → 3已打款（4转账中）；0 → 2已驳回（返还余额）
const STATUS_TAG: Record<number, 'warning' | 'primary' | 'danger' | 'success' | 'info'> = {
  0: 'warning',
  1: 'primary',
  2: 'danger',
  3: 'success',
  4: 'info',
}

const loading = ref(false)
const tableData = ref<WithdrawItem[]>([])
const total = ref(0)
const stat = ref<WithdrawStat>({ pending: 0, approved: 0, rejected: 0, paid: 0, pending_amount: 0 })

const query = reactive({
  page: 1,
  pagesize: 10,
  status: '',
  nickname: '',
  mid: '',
  paytype: '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getWithdrawList({
      page: query.page,
      pagesize: query.pagesize,
      status: query.status || undefined,
      nickname: query.nickname || undefined,
      mid: query.mid ? Number(query.mid) : undefined,
      paytype: query.paytype || undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
    })
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

function handleReset() {
  query.status = ''
  query.nickname = ''
  query.mid = ''
  query.paytype = ''
  query.timeRange = null
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

// ============ 审核弹窗 ============
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
    await auditWithdraw({
      id: auditRow.value.id,
      pass: auditForm.pass,
      reason: auditForm.reason,
    })
    ElMessage.success(auditForm.pass === 1 ? '已通过，待打款' : '已驳回，金额已返还会员余额')
    auditVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    auditSubmitting.value = false
  }
}

// ============ 打款 ============
async function handlePay(row: WithdrawItem) {
  try {
    await ElMessageBox.confirm(
      `确认向「${row.nickname || '会员' + row.mid}」打款 ￥${Number(row.txmoney).toFixed(2)}（${row.paytype || '-'}）？`,
      '提现打款',
      { type: 'warning', confirmButtonText: '确认打款', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  try {
    await payWithdraw(row.id)
    ElMessage.success('打款成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// 收款账户展示（按支付方式）
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
    <!-- 顶部统计卡片 -->
    <el-row :gutter="12" class="stat-row">
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">待审核</div>
          <div class="stat-value warning">{{ stat.pending }}</div>
          <div class="stat-sub">金额 ￥{{ Number(stat.pending_amount).toFixed(2) }}</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">待打款（含转账中）</div>
          <div class="stat-value primary">{{ stat.approved }}</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">已打款</div>
          <div class="stat-value success">{{ stat.paid }}</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">已驳回</div>
          <div class="stat-value danger">{{ stat.rejected }}</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="待审核" value="0" />
            <el-option label="待打款" value="1" />
            <el-option label="已驳回" value="2" />
            <el-option label="已打款" value="3" />
            <el-option label="转账中" value="4" />
          </el-select>
        </el-form-item>
        <el-form-item label="方式">
          <el-select v-model="query.paytype" placeholder="全部" clearable style="width: 110px">
            <el-option label="微信" value="微信" />
            <el-option label="支付宝" value="支付宝" />
            <el-option label="银行卡" value="银行卡" />
          </el-select>
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="会员昵称" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="ID" clearable style="width: 90px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.timeRange"
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

    <!-- 列表（border 竖线 + 紧凑列宽，1366 无横向滚动） -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
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
              <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">{{ row.status_text }}</el-tag>
            </el-tooltip>
            <el-tag v-else :type="STATUS_TAG[row.status] || 'info'" size="small">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="110" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <div class="op-wrap">
              <el-button v-if="row.status === 0" link type="warning" @click="openAudit(row)">审核</el-button>
              <el-button v-if="row.status === 1" link type="primary" @click="handlePay(row)">打款</el-button>
              <span v-if="row.status === 4" class="sub-text">转账中</span>
              <el-tooltip
                v-if="row.status === 3 && row.paytime"
                :content="`打款时间：${formatTime(row.paytime)}${row.paynum ? ' 单号：' + row.paynum : ''}`"
                placement="top"
              >
                <span class="sub-text">已打款</span>
              </el-tooltip>
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

    <!-- 审核弹窗 -->
    <el-dialog v-model="auditVisible" title="提现审核" width="460px">
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
          <el-radio :value="2">驳回（返还余额）</el-radio>
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

.op-wrap {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: nowrap;
}

.sub-text {
  color: #909399;
  font-size: 12px;
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
