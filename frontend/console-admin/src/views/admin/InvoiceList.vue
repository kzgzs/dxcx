<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { auditInvoice, getInvoiceList, replyInvoice } from '../../api/finance'
import type { InvoiceItem, InvoiceStat } from '../../api/finance'
import { formatTime } from '../../utils/time'

// 状态 0待审核 1通过 2驳回
const STATUS_TAG: Record<number, 'warning' | 'success' | 'danger'> = {
  0: 'warning',
  1: 'success',
  2: 'danger',
}
const STATUS_TEXT: Record<number, string> = { 0: '待审核', 1: '已通过', 2: '已驳回' }

const loading = ref(false)
const tableData = ref<InvoiceItem[]>([])
const total = ref(0)
const stat = ref<InvoiceStat>({ pending: 0, passed: 0, rejected: 0 })
const selected = ref<InvoiceItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  status: '',
  keyword: '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getInvoiceList({
      page: query.page,
      pagesize: query.pagesize,
      status: query.status || undefined,
      keyword: query.keyword || undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { pending: 0, passed: 0, rejected: 0 }
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
  query.keyword = ''
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

function handleSelectionChange(rows: InvoiceItem[]) {
  selected.value = rows
}

// ============ 审核（批量/单条） ============
async function doAudit(ids: number[], pass: number) {
  const label = pass === 1 ? '通过' : '驳回'
  let remark = ''
  try {
    const rs = await ElMessageBox.prompt(`请填写${label}备注（可留空）`, `发票审核 - ${label}`, {
      confirmButtonText: `确认${label}`,
      cancelButtonText: '取消',
      inputValue: '',
      inputPlaceholder: '备注（选填，驳回时建议填写原因）',
    })
    remark = rs.value || ''
  } catch {
    return
  }
  try {
    await auditInvoice({ ids, pass, remark })
    ElMessage.success(`已${label} ${ids.length} 条发票`)
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleBatchAudit(pass: number) {
  if (!selected.value.length) {
    ElMessage.warning('请先勾选发票')
    return
  }
  doAudit(selected.value.map((r) => r.id), pass)
}

function handleAudit(row: InvoiceItem, pass: number) {
  doAudit([row.id], pass)
}

// ============ 回复（写审核备注+时间） ============
const replyVisible = ref(false)
const replySubmitting = ref(false)
const replyRow = ref<InvoiceItem | null>(null)
const replyRemark = ref('')

function openReply(row: InvoiceItem) {
  replyRow.value = row
  replyRemark.value = row.check_remark || ''
  replyVisible.value = true
}

async function submitReply() {
  if (!replyRow.value) return
  if (!replyRemark.value.trim()) {
    ElMessage.warning('回复内容不能为空')
    return
  }
  replySubmitting.value = true
  try {
    await replyInvoice({ id: replyRow.value.id, remark: replyRemark.value })
    ElMessage.success('已回复')
    replyVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    replySubmitting.value = false
  }
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
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">待审核</div>
          <div class="stat-value warning">{{ stat.pending }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">已通过</div>
          <div class="stat-value success">{{ stat.passed }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
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
            <el-option label="已通过" value="1" />
            <el-option label="已驳回" value="2" />
          </el-select>
        </el-form-item>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="抬头 / 订单号 / 税号"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
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
        <el-form-item class="batch-actions">
          <el-button type="success" plain :disabled="!selected.length" @click="handleBatchAudit(1)">
            批量通过
          </el-button>
          <el-button type="danger" plain :disabled="!selected.length" @click="handleBatchAudit(2)">
            批量驳回
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表（border 竖线 + 紧凑列宽，1366 无横向滚动） -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="42" class-name="compact-col" />
        <el-table-column prop="ordernum" label="订单号" min-width="140" show-overflow-tooltip />
        <el-table-column label="发票抬头" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.invoice_name }}
            <el-tag size="small" :type="row.name_type === 2 ? 'primary' : 'info'" class="mini-tag">
              {{ row.name_type === 2 ? '公司' : '个人' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="tax_no" label="税号" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.tax_no || '-' }}</template>
        </el-table-column>
        <el-table-column prop="type_text" label="票种" width="60" class-name="compact-col" />
        <el-table-column label="实付额" width="82" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.pay_money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="联系方式" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.mobile || row.tel || row.email || '-' }}</template>
        </el-table-column>
        <el-table-column label="申请时间" width="88" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.create_time" :content="formatTime(row.create_time)" placement="top">
              <span>{{ shortDate(row.create_time) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="78" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.check_remark" :content="`备注：${row.check_remark}`" placement="top">
              <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">{{ STATUS_TEXT[row.status] || '未知' }}</el-tag>
            </el-tooltip>
            <el-tag v-else :type="STATUS_TAG[row.status] || 'info'" size="small">{{ STATUS_TEXT[row.status] || '未知' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <div class="op-wrap">
              <template v-if="row.status === 0">
                <el-button link type="success" @click="handleAudit(row, 1)">通过</el-button>
                <el-button link type="danger" @click="handleAudit(row, 2)">驳回</el-button>
              </template>
              <el-button link type="primary" @click="openReply(row)">回复</el-button>
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

    <!-- 回复弹窗 -->
    <el-dialog v-model="replyVisible" title="发票回复" width="440px">
      <template v-if="replyRow">
        <div class="reply-info">
          <span class="reply-label">发票抬头</span>
          <span>{{ replyRow.invoice_name }}（订单 {{ replyRow.ordernum || '-' }}）</span>
        </div>
        <el-input
          v-model="replyRemark"
          type="textarea"
          :rows="4"
          placeholder="请输入回复/审核备注内容"
        />
        <div v-if="replyRow.check_time" class="reply-time">
          上次回复时间：{{ formatTime(replyRow.check_time) }}
        </div>
      </template>
      <template #footer>
        <el-button @click="replyVisible = false">取消</el-button>
        <el-button type="primary" :loading="replySubmitting" @click="submitReply">提交回复</el-button>
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

.batch-actions {
  margin-left: auto;
}

.mini-tag {
  margin-left: 4px;
}

.op-wrap {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: nowrap;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.reply-info {
  display: flex;
  gap: 8px;
  background: #f8fafc;
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 13px;
  margin-bottom: 10px;
}

.reply-label {
  color: #909399;
  flex-shrink: 0;
}

.reply-time {
  color: #909399;
  font-size: 12px;
  margin-top: 8px;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
