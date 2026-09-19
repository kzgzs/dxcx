<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  auditYuyueWithdrawLog,
  deleteYuyueWithdrawLog,
  getYuyueWithdrawLogList,
  payYuyueWithdrawLog,
} from '../../api/yuyue'
import type { YuyueWithdrawLogItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// 预约 - 人员提现记录（任务222，对照参考系统 YuyueMoney::withdrawlog）
// 状态机：0审核中 → 1已审核（待打款） → 3已打款；0 → 2已驳回（退回人员余额）
const STATUS_TAG: Record<number, 'warning' | 'primary' | 'danger' | 'success' | 'info'> = {
  0: 'warning',
  1: 'primary',
  2: 'danger',
  3: 'success',
}

const loading = ref(false)
const tableData = ref<YuyueWithdrawLogItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  realname: '',
  uid: '',
  status: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueWithdrawLogList({
      page: query.page,
      pagesize: query.pagesize,
      realname: query.realname || undefined,
      uid: query.uid ? Number(query.uid) : undefined,
      status: query.status === '' ? undefined : Number(query.status),
    })
    tableData.value = data.list || []
    total.value = data.total || 0
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
  query.realname = ''
  query.uid = ''
  query.status = ''
  query.page = 1
  fetchList()
}

// ============ 审核弹窗 ============
const auditVisible = ref(false)
const auditSubmitting = ref(false)
const auditRow = ref<YuyueWithdrawLogItem | null>(null)
const auditForm = reactive({ pass: 1, reason: '' })

function openAudit(row: YuyueWithdrawLogItem) {
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
    await auditYuyueWithdrawLog(auditRow.value.id, auditForm.pass, auditForm.reason)
    ElMessage.success(auditForm.pass === 1 ? '已通过，待打款' : '已驳回，金额已退回人员余额')
    auditVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    auditSubmitting.value = false
  }
}

// ============ 打款 ============
async function handlePay(row: YuyueWithdrawLogItem) {
  try {
    await ElMessageBox.confirm(
      `确认向「${row.realname || '人员' + row.uid}」打款 ￥${Number(row.txmoney).toFixed(2)}（${row.paytype || '-'}）？`,
      '提现打款',
      { type: 'warning', confirmButtonText: '确认打款', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  try {
    await payYuyueWithdrawLog(row.id)
    ElMessage.success('打款成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 删除 ============
function handleDelete(row: YuyueWithdrawLogItem) {
  ElMessageBox.confirm('确定要删除这条提现记录吗？', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueWithdrawLog([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 收款账户展示（按支付方式）
function accountText(row: YuyueWithdrawLogItem): string {
  if (row.paytype === '支付宝') return row.aliaccount || '-'
  if (row.paytype === '银行卡') return [row.bankname, row.bankcarduser, row.bankcardnum].filter(Boolean).join(' - ') || '-'
  if (row.paytype === '微信') return row.weixin || '微信'
  return row.paytype || '-'
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="待审核" value="0" />
            <el-option label="待打款" value="1" />
            <el-option label="已驳回" value="2" />
            <el-option label="已打款" value="3" />
          </el-select>
        </el-form-item>
        <el-form-item label="姓名">
          <el-input v-model="query.realname" placeholder="人员姓名" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="人员ID">
          <el-input v-model="query.uid" placeholder="ID" clearable style="width: 90px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="人员" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.realname || `人员${row.uid}` }}
            <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="申请金额" width="90">
          <template #default="{ row }">￥{{ Number(row.txmoney).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="实付金额" width="90">
          <template #default="{ row }">￥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="paytype" label="方式" width="72">
          <template #default="{ row }">{{ row.paytype || '-' }}</template>
        </el-table-column>
        <el-table-column label="收款账户" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ accountText(row) }}</template>
        </el-table-column>
        <el-table-column label="申请时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="84">
          <template #default="{ row }">
            <el-tooltip v-if="row.status === 2 && row.reason" :content="`驳回原因：${row.reason}`" placement="top">
              <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">{{ row.status_text }}</el-tag>
            </el-tooltip>
            <el-tag v-else :type="STATUS_TAG[row.status] || 'info'" size="small">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <div class="op-wrap">
              <el-button v-if="row.status === 0" link type="warning" @click="openAudit(row)">审核</el-button>
              <el-button v-if="row.status === 1" link type="primary" @click="handlePay(row)">打款</el-button>
              <el-tooltip
                v-if="row.status === 3 && row.paytime"
                :content="`打款时间：${formatTime(row.paytime)}${row.paynum ? ' 单号：' + row.paynum : ''}`"
                placement="top"
              >
                <span class="sub-text">已打款</span>
              </el-tooltip>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="pager"
        background
        layout="total, sizes, prev, pager, next"
        :total="total"
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :page-sizes="[10, 20, 50]"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </el-card>

    <!-- 审核弹窗 -->
    <el-dialog v-model="auditVisible" title="预约提成提现审核" width="460px">
      <template v-if="auditRow">
        <div class="audit-info-card">
          <div class="audit-row">
            <span class="audit-label">人员</span>
            <span>{{ auditRow.realname || `人员${auditRow.uid}` }}（{{ auditRow.tel || '-' }}）</span>
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
          <el-radio :value="2">驳回（退回人员余额）</el-radio>
        </el-radio-group>
        <el-input
          v-model="auditForm.reason"
          type="textarea"
          :rows="3"
          :placeholder="auditForm.pass === 2 ? '请填写驳回原因（必填）' : '备注（选填）'"
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
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
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
</style>
