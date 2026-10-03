<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { auditInvoice, deleteInvoice, getInvoiceDetail, getInvoiceList, replyInvoice } from '../../api/finance'
import type { InvoiceItem, InvoiceQuery, InvoiceStat } from '../../api/finance'
import { formatTime } from '../../utils/time'

const ORDER_TYPE_LABEL: Record<string, string> = {
  shop: '普通', collage: '拼团', seckill: '秒杀', scoreshop: '积分', kanjia: '砍价',
  tuangou: '团购', yuyue: '预约', hotel: '酒店', kecheng: '课程',
}

function statusInfo(s: number): { text: string; color: string } {
  if (s === 0) return { text: '待审核', color: '#e6a23c' }
  if (s === 1) return { text: '已通过', color: '#67c23a' }
  return { text: '已驳回', color: '#f56c6c' }
}

const loading = ref(false)
const total = ref(0)
const tableData = ref<InvoiceItem[]>([])
const selectedRows = ref<InvoiceItem[]>([])
const stat = ref<InvoiceStat>({ pending: 0, passed: 0, rejected: 0 })

const query = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  invoice_name: '',
  timeRange: null as [string, string] | null,
})

function buildParams(): InvoiceQuery {
  return {
    page: query.page,
    pagesize: query.pagesize,
    ordernum: query.ordernum || undefined,
    invoice_name: query.invoice_name || undefined,
    start_date: query.timeRange?.[0] || undefined,
    end_date: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getInvoiceList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { pending: 0, passed: 0, rejected: 0 }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() { query.page = 1; fetchList() }
function handlePageChange(page: number) { query.page = page; fetchList() }
function handleSizeChange(size: number) { query.pagesize = size; query.page = 1; fetchList() }
function handleSelectionChange(rows: InvoiceItem[]) { selectedRows.value = rows }

// ============ 审核（通过/驳回） ============
async function doAudit(ids: number[], pass: number) {
  if (ids.length === 0) { ElMessage.warning('请先勾选发票'); return }
  const label = pass === 1 ? '通过' : '驳回'
  let remark = ''
  try {
    const rs = await ElMessageBox.prompt(`请填写${label}备注（可留空）`, `发票审核 - ${label}`, {
      confirmButtonText: `确认${label}`, cancelButtonText: '取消', inputValue: '',
      inputPlaceholder: '备注（选填，驳回时建议填写原因）',
    })
    remark = rs.value || ''
  } catch { return }
  try {
    await auditInvoice({ ids, pass, remark })
    ElMessage.success(`已${label} ${ids.length} 条发票`)
    fetchList()
  } catch { /* 拦截器弹出 */ }
}

function handleBatchAudit(pass: number) { doAudit(selectedRows.value.map((r) => r.id), pass) }
function handleRowAudit(row: InvoiceItem, pass: number) { doAudit([row.id], pass) }

// ============ 回复 ============
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
  if (!replyRemark.value.trim()) { ElMessage.warning('回复内容不能为空'); return }
  replySubmitting.value = true
  try {
    await replyInvoice({ id: replyRow.value.id, remark: replyRemark.value })
    ElMessage.success('已回复')
    replyVisible.value = false
    fetchList()
  } catch { /* 拦截器弹出 */ } finally { replySubmitting.value = false }
}

// ============ 详情（平铺主区域，右上 X） ============
const detailVisible = ref(false)
const detailRow = ref<InvoiceItem | null>(null)

async function openDetail(row: InvoiceItem) {
  detailRow.value = row
  try {
    detailRow.value = await getInvoiceDetail({ id: row.id })
  } catch {
    // 拉取详情失败则用行数据兜底
  }
  detailVisible.value = true
}

function closeDetail() { detailVisible.value = false; detailRow.value = null }

// ============ 删除 ============
async function handleBatchDelete() {
  if (selectedRows.value.length === 0) { ElMessage.warning('请先勾选要删除的发票'); return }
  try {
    await ElMessageBox.confirm(`确定要删除选中的 ${selectedRows.value.length} 条发票吗？删除后无法恢复！`, '操作确认', {
      type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消',
    })
  } catch { return }
  try {
    await deleteInvoice({ ids: selectedRows.value.map((r) => r.id) })
    ElMessage.success('删除成功')
    fetchList()
  } catch { /* 拦截器弹出 */ }
}

async function handleRowDelete(row: InvoiceItem) {
  try {
    await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
      type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消',
    })
  } catch { return }
  try {
    await deleteInvoice({ id: row.id })
    ElMessage.success('删除成功')
    fetchList()
  } catch { /* 拦截器弹出 */ }
}

onMounted(() => { fetchList() })
</script>

<template>
  <div v-if="!detailVisible">
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item>
          <el-button :disabled="selectedRows.length === 0" @click="handleBatchDelete">删除</el-button>
          <el-button type="success" plain :disabled="selectedRows.length === 0" @click="handleBatchAudit(1)">审核通过</el-button>
          <el-button type="danger" plain :disabled="selectedRows.length === 0" @click="handleBatchAudit(2)">驳回</el-button>
        </el-form-item>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" placeholder="订单号" clearable style="width: 160px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="抬头">
          <el-input v-model="query.invoice_name" placeholder="发票抬头" clearable style="width: 140px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 230px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" class="table-card">
      <div class="stat-inline">
        <span class="stat-inline-item">待审核：<b>{{ stat.pending }}</b></span>
        <span class="stat-inline-item">已通过：<b>{{ stat.passed }}</b></span>
        <span class="stat-inline-item">已驳回：<b>{{ stat.rejected }}</b></span>
      </div>
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="订单号" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="ordertype-tag">[{{ ORDER_TYPE_LABEL[row.order_type] || row.order_type || '普通' }}]</span>
            <span>{{ row.ordernum || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="可开票金额" width="105">
          <template #default="{ row }">￥{{ Number(row.pay_money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="发票类型" width="110">
          <template #default="{ row }">{{ row.type === 2 ? '增值税专票' : '普票' }}</template>
        </el-table-column>
        <el-table-column label="抬头类型" width="80">
          <template #default="{ row }">{{ row.name_type === 2 ? '公司' : '个人' }}</template>
        </el-table-column>
        <el-table-column label="抬头" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.invoice_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="税号" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.tax_no || '-' }}</template>
        </el-table-column>
        <el-table-column label="注册地址和电话" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.address || '-' }}</div>
            <div>{{ row.tel || '' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="银行和账号" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.bank_name || '-' }}</div>
            <div>{{ row.bank_account || '' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="手机和邮箱" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.mobile || '-' }}</div>
            <div>{{ row.email || '' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="时间" width="150">
          <template #default="{ row }">
            <el-tooltip v-if="row.create_time" :content="formatTime(row.create_time)" placement="top">
              <span>{{ formatTime(row.create_time) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :style="{ color: statusInfo(row.status).color }">{{ statusInfo(row.status).text }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200">
          <template #default="{ row }">
            <div class="row-ops">
              <template v-if="row.status === 0">
                <el-button link type="success" @click="handleRowAudit(row, 1)">审核通过</el-button>
                <el-button link type="danger" @click="handleRowAudit(row, 2)">驳回</el-button>
              </template>
              <el-button link type="primary" @click="openReply(row)">回复</el-button>
              <el-button link type="primary" @click="openDetail(row)">详情</el-button>
              <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
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

    <el-dialog v-model="replyVisible" title="发票回复" width="440px">
      <template v-if="replyRow">
        <div class="reply-info">
          <span class="reply-label">发票抬头</span>
          <span>{{ replyRow.invoice_name }}（订单 {{ replyRow.ordernum || '-' }}）</span>
        </div>
        <el-input v-model="replyRemark" type="textarea" :rows="4" placeholder="请输入回复/审核备注内容" />
        <div v-if="replyRow.check_time" class="reply-time">上次回复时间：{{ formatTime(replyRow.check_time) }}</div>
      </template>
      <template #footer>
        <el-button @click="replyVisible = false">取消</el-button>
        <el-button type="success" :loading="replySubmitting" @click="submitReply">提 交</el-button>
      </template>
    </el-dialog>
  </div>

  <!-- 详情：平铺主区域，右上角 X -->
  <div v-else-if="detailRow" class="goods-edit">
    <div class="ge-head">
      <el-button link class="ge-close" @click="closeDetail">✕ 关闭</el-button>
    </div>
    <el-descriptions :column="2" border>
      <el-descriptions-item label="ID">{{ detailRow.id }}</el-descriptions-item>
      <el-descriptions-item label="订单号">{{ detailRow.ordernum || '-' }}</el-descriptions-item>
      <el-descriptions-item label="订单类型">{{ ORDER_TYPE_LABEL[detailRow.order_type] || detailRow.order_type || '-' }}</el-descriptions-item>
      <el-descriptions-item label="可开票金额">￥{{ Number(detailRow.pay_money).toFixed(2) }}</el-descriptions-item>
      <el-descriptions-item label="发票类型">{{ detailRow.type === 2 ? '增值税专票' : '普票' }}</el-descriptions-item>
      <el-descriptions-item label="抬头类型">{{ detailRow.name_type === 2 ? '公司' : '个人' }}</el-descriptions-item>
      <el-descriptions-item label="抬头" :span="2">{{ detailRow.invoice_name || '-' }}</el-descriptions-item>
      <el-descriptions-item label="税号">{{ detailRow.tax_no || '-' }}</el-descriptions-item>
      <el-descriptions-item label="状态">
        <span :style="{ color: statusInfo(detailRow.status).color }">{{ statusInfo(detailRow.status).text }}</span>
      </el-descriptions-item>
      <el-descriptions-item label="注册地址" :span="2">{{ detailRow.address || '-' }}</el-descriptions-item>
      <el-descriptions-item label="注册电话">{{ detailRow.tel || '-' }}</el-descriptions-item>
      <el-descriptions-item label="银行">{{ detailRow.bank_name || '-' }}</el-descriptions-item>
      <el-descriptions-item label="银行账号">{{ detailRow.bank_account || '-' }}</el-descriptions-item>
      <el-descriptions-item label="手机">{{ detailRow.mobile || '-' }}</el-descriptions-item>
      <el-descriptions-item label="邮箱">{{ detailRow.email || '-' }}</el-descriptions-item>
      <el-descriptions-item label="申请时间">{{ detailRow.create_time ? formatTime(detailRow.create_time) : '-' }}</el-descriptions-item>
      <el-descriptions-item label="审核时间">{{ detailRow.check_time ? formatTime(detailRow.check_time) : '-' }}</el-descriptions-item>
      <el-descriptions-item label="审核备注" :span="2">{{ detailRow.check_remark || '-' }}</el-descriptions-item>
    </el-descriptions>
  </div>
</template>

<style scoped>
.toolbar-card { margin-bottom: 8px; }
.toolbar-card :deep(.el-card__body) { padding: 8px 12px; }
.toolbar-card :deep(.el-form-item) { margin-bottom: 0; margin-right: 8px; }
.toolbar-card :deep(.el-form-item__label) { padding-right: 6px; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }
.table-card :deep(.el-card__body) { padding: 5px; }
.table-card :deep(.el-table__header-wrapper th.el-table__cell) { background: #e6e6e6; }
.table-card :deep(.el-table .cell) { text-align: left; padding-left: 6px; padding-right: 6px; }
.table-card :deep(.el-table td.el-table__cell) { padding: 3px 0; }
.stat-inline { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 6px 18px; padding: 2px 6px 6px; font-size: 13px; color: #606266; }
.stat-inline b { color: #303133; font-weight: 700; }
.ordertype-tag { color: #909399; margin-right: 4px; }
.row-ops { display: flex; flex-direction: row; flex-wrap: wrap; gap: 2px 6px; white-space: nowrap; align-items: center; }
.row-ops :deep(.el-button) { height: auto; padding: 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 12px; justify-content: flex-end; }
.reply-info { display: flex; gap: 8px; background: #f8fafc; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 10px; }
.reply-label { color: #909399; flex-shrink: 0; }
.reply-time { color: #909399; font-size: 12px; margin-top: 8px; }
.goods-edit { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; justify-content: flex-end; margin-bottom: 12px; }
.ge-close { border: none; background: transparent; color: #909399; font-size: 14px; }
.ge-close:hover { color: #409eff; background: transparent; }
</style>