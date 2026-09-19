<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { exportCommissionRecord, getCommissionRecordList, settleCommissionRecord } from '../../api/finance'
import type { CommissionRecordItem, CommissionRecordStat } from '../../api/finance'
import { formatTime } from '../../utils/time'

/**
 * 佣金记录（member_commission_record，任务177）
 * 与「佣金明细」（member_commissionlog）是两个不同页面：
 * 本页按业务类型回查对应订单表取订单号与订单状态，含发放状态统计与导出
 */
const loading = ref(false)
const exporting = ref(false)
const settling = ref(false)
const total = ref(0)
const tableData = ref<CommissionRecordItem[]>([])
const selectedRows = ref<CommissionRecordItem[]>([])
const stat = ref<CommissionRecordStat>({ count: 0, pending: 0, paid: 0, backed: 0, total_commission: 0 })

const STATUS_TAG: Record<number, 'warning' | 'success' | 'danger' | 'info'> = {
  0: 'warning',
  1: 'success',
  2: 'danger',
}

const query = reactive({
  page: 1,
  pagesize: 10,
  nickname: '',
  mid: '',
  status: '',
  type: '',
  orderid: '',
  timeRange: null as [string, string] | null,
})

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    nickname: query.nickname || undefined,
    mid: query.mid ? Number(query.mid) : undefined,
    status: query.status || undefined,
    type: query.type || undefined,
    orderid: query.orderid || undefined,
    start_date: query.timeRange?.[0] || undefined,
    end_date: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getCommissionRecordList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { count: 0, pending: 0, paid: 0, backed: 0, total_commission: 0 }
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
  query.nickname = ''
  query.mid = ''
  query.status = ''
  query.type = ''
  query.orderid = ''
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
    const blob = await exportCommissionRecord(buildParams())
    downloadBlob(blob, `佣金记录_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    exporting.value = false
  }
}

function handleSelectionChange(rows: CommissionRecordItem[]) {
  selectedRows.value = rows
}

/** 手动结算（任务182，对照参考系统 jiesuan 手动按钮）：仅未发放（status=0）记录可结算 */
async function handleSettle() {
  const pending = selectedRows.value.filter((row) => row.status === 0)

  if (pending.length === 0) {
    ElMessage.warning('请先勾选未发放的佣金记录')
    return
  }

  try {
    await ElMessageBox.confirm(`确认立即结算选中的 ${pending.length} 条佣金记录？结算后佣金将入账且不可撤销`, '手动结算', {
      type: 'warning',
      confirmButtonText: '确认结算',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }

  settling.value = true
  try {
    await settleCommissionRecord(pending.map((row) => row.id))
    ElMessage.success('结算完成')
    fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    settling.value = false
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
    <el-row :gutter="12" class="stat-row">
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">未发放</div>
          <div class="stat-value warning">{{ stat.pending }}</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">已发放</div>
          <div class="stat-value success">{{ stat.paid }}</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">已退回</div>
          <div class="stat-value danger">{{ stat.backed }}</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">佣金合计（未发放+已发放）</div>
          <div class="stat-value primary">￥{{ Number(stat.total_commission).toFixed(2) }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="会员昵称" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="ID" clearable style="width: 90px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 100px">
            <el-option label="未发放" value="0" />
            <el-option label="已发放" value="1" />
            <el-option label="已退回" value="2" />
          </el-select>
        </el-form-item>
        <el-form-item label="业务类型">
          <el-input v-model="query.type" placeholder="type" clearable style="width: 110px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="订单ID">
          <el-input v-model="query.orderid" placeholder="orderid" clearable style="width: 100px" @keyup.enter="handleSearch" />
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
          <el-button type="warning" :loading="settling" @click="handleSettle">手动结算</el-button>
          <el-button type="success" :loading="exporting" @click="handleExport">导出</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="42" :selectable="(row: CommissionRecordItem) => row.status === 0" />
        <el-table-column prop="id" label="ID" width="64" class-name="compact-col" />
        <el-table-column label="会员" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.nickname || `会员${row.mid}` }}
            <span class="sub-text">ID:{{ row.mid }}</span>
          </template>
        </el-table-column>
        <el-table-column label="佣金" width="86" class-name="compact-col">
          <template #default="{ row }">
            <span class="success-text">+{{ Number(row.commission).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="积分" width="72" class-name="compact-col">
          <template #default="{ row }">{{ Number(row.score).toFixed(0) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="84" class-name="compact-col">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="type" label="业务类型" width="110" class-name="compact-col" show-overflow-tooltip>
          <template #default="{ row }">{{ row.type || '-' }}</template>
        </el-table-column>
        <el-table-column label="订单" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.ordernum">{{ row.ordernum }}</span>
            <span v-else class="sub-text">-</span>
          </template>
        </el-table-column>
        <el-table-column label="来源会员" min-width="100" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.frommid > 0">{{ row.from_nickname || `会员${row.frommid}` }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="产生时间" width="92" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ shortDate(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="发放时间" width="92" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.endtime" :content="formatTime(row.endtime)" placement="top">
              <span>{{ shortDate(row.endtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
      </el-table>
    </el-card>

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

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
