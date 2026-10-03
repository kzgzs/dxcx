<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { assetUrl } from '../../api/upload'
import {
  deleteCommissionRecord,
  exportCommissionRecord,
  getCommissionRecordList,
  getCommissionRecordOrderDetail,
} from '../../api/finance'
import type {
  CommissionRecordItem,
  CommissionRecordOrderDetail,
  CommissionRecordStat,
} from '../../api/finance'
import { formatTime } from '../../utils/time'

/**
 * 佣金记录（member_commission_record）
 * 对齐 canzhao commission/record.html：工具栏[删除,导出EXCEL]，行[查看订单,删除]
 */
const loading = ref(false)
const exporting = ref(false)
const tableData = ref<CommissionRecordItem[]>([])
const selectedRows = ref<CommissionRecordItem[]>([])
const stat = ref<CommissionRecordStat>({ count: 0, pending: 0, paid: 0, backed: 0, total_commission: 0 })
const total = ref(0)

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

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: CommissionRecordItem[]) {
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
    const blob = await exportCommissionRecord(buildParams())
    downloadBlob(blob, `佣金记录_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    exporting.value = false
  }
}

async function doDelete(ids: number[]) {
  await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    type: 'warning',
    confirmButtonText: '确 定',
    cancelButtonText: '取 消',
  })
  await deleteCommissionRecord({ ids })
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

function handleRowDelete(row: CommissionRecordItem) {
  doDelete([row.id]).catch(() => {})
}

// ============ 查看订单 ============
const orderVisible = ref(false)
const orderLoading = ref(false)
const orderDetail = ref<CommissionRecordOrderDetail | null>(null)

async function handleViewOrder(row: CommissionRecordItem) {
  orderVisible.value = true
  orderLoading.value = true
  orderDetail.value = null
  try {
    orderDetail.value = await getCommissionRecordOrderDetail({ type: row.type, orderid: row.orderid })
  } catch {
    // 拦截器统一提示
  } finally {
    orderLoading.value = false
  }
}

function orderStatusText(st?: number): { text: string; color: string } {
  const map: Record<number, { text: string; color: string }> = {
    0: { text: '未支付', color: '#f56c6c' },
    1: { text: '已支付', color: '#67c23a' },
    2: { text: '已发货', color: '#67c23a' },
    3: { text: '已收货', color: '#67c23a' },
    4: { text: '已关闭', color: '#909399' },
  }
  return map[st ?? -1] || { text: '-', color: '#606266' }
}

function statusTextColor(st: number): string {
  if (st === 1) return '#67c23a'
  if (st === 2) return '#f56c6c'
  return '#f56c6c'
}

function maskName(name?: string): string {
  const s = String(name || '')
  if (!s) return ''
  return s.length > 1 ? s[0] + '**' : s
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
          <el-select v-model="query.status" placeholder="状态" clearable class="tw-status">
            <el-option label="全部" value="" />
            <el-option label="未发放" value="0" />
            <el-option label="已发放" value="1" />
            <el-option label="已退回" value="2" />
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
        <span>总记录 <b>{{ stat.count }}</b></span>
        <span>未发放 <b>{{ stat.pending }}</b></span>
        <span>已发放 <b>{{ stat.paid }}</b></span>
        <span>已退回 <b>{{ stat.backed }}</b></span>
        <span>佣金合计 <b>￥{{ Number(stat.total_commission).toFixed(2) }}</b></span>
      </div>
      <div class="table-scroll">
        <el-table v-loading="loading" :data="tableData" border size="small" @selection-change="handleSelectionChange">
          <el-table-column type="selection" width="42" />
          <el-table-column prop="mid" label="会员ID" width="80" />
          <el-table-column label="会员信息" min-width="160">
            <template #default="{ row }">
              <div v-if="row.nickname" class="member-cell">
                <img v-if="row.headimg" :src="assetUrl(row.headimg)" class="member-avatar" @error="onAvatarError" alt="" />
                <span>{{ row.nickname }}</span>
              </div>
              <span v-else>-</span>
            </template>
          </el-table-column>
          <el-table-column label="佣金来源" min-width="160">
            <template #default="{ row }">
              <div v-if="row.frommid" class="member-cell">
                <img v-if="row.from_headimg" :src="assetUrl(row.from_headimg)" class="member-avatar" @error="onAvatarError" alt="" />
                <span>{{ maskName(row.from_nickname) || row.frommid }}</span>
                <span v-if="row.level_text" class="level-tag">{{ row.level_text }}</span>
              </div>
              <span v-else>-</span>
            </template>
          </el-table-column>
          <el-table-column prop="commission" label="佣金" width="100" sortable>
            <template #default="{ row }">
              <span class="text-success">￥{{ Number(row.commission).toFixed(2) }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="score" label="积分" width="90" sortable>
            <template #default="{ row }">{{ row.score }}</template>
          </el-table-column>
          <el-table-column prop="ordernum" label="订单编号" min-width="160" show-overflow-tooltip>
            <template #default="{ row }">{{ row.ordernum || '-' }}</template>
          </el-table-column>
          <el-table-column prop="createtime" label="产生时间" width="150" sortable>
            <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
          </el-table-column>
          <el-table-column prop="endtime" label="发放时间" width="150" sortable>
            <template #default="{ row }">{{ row.endtime ? formatTime(row.endtime) : '-' }}</template>
          </el-table-column>
          <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip>
            <template #default="{ row }">{{ row.remark || '-' }}</template>
          </el-table-column>
          <el-table-column label="订单状态" width="90">
            <template #default="{ row }">
              <span :style="{ color: orderStatusText(row.orderstatus).color }">{{ orderStatusText(row.orderstatus).text }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <span :style="{ color: statusTextColor(row.status) }">{{ row.status_text }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="120">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button v-if="row.can_view_order" link type="primary" @click="handleViewOrder(row)">查看订单</el-button>
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

    <!-- 查看订单小弹窗 -->
    <el-dialog v-model="orderVisible" title="订单详情" width="420px">
      <div v-loading="orderLoading" class="order-detail">
        <template v-if="orderDetail">
          <div class="od-row"><span class="od-label">订单编号</span><span>{{ orderDetail.ordernum || '-' }}</span></div>
          <div class="od-row"><span class="od-label">订单类型</span><span>{{ orderDetail.type || '-' }}</span></div>
          <div class="od-row">
            <span class="od-label">订单状态</span>
            <span :style="{ color: orderStatusText(orderDetail.orderstatus).color }">{{ orderStatusText(orderDetail.orderstatus).text }}</span>
          </div>
          <div v-if="orderDetail.money != null" class="od-row">
            <span class="od-label">订单金额</span><span>￥{{ Number(orderDetail.money).toFixed(2) }}</span>
          </div>
          <div v-if="orderDetail.createtime" class="od-row">
            <span class="od-label">下单时间</span><span>{{ formatTime(orderDetail.createtime) }}</span>
          </div>
        </template>
        <div v-else-if="!orderLoading" class="od-empty">暂无订单信息</div>
      </div>
    </el-dialog>
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
.level-tag {
  flex-shrink: 0;
  font-size: 12px;
  color: #409eff;
}
.text-success {
  color: #67c23a;
}
.pagination {
  margin-top: 8px;
  justify-content: flex-end;
}
.order-detail {
  min-height: 60px;
}
.od-row {
  display: flex;
  gap: 10px;
  padding: 5px 0;
  font-size: 13px;
}
.od-label {
  width: 80px;
  flex-shrink: 0;
  color: #909399;
}
.od-empty {
  color: #909399;
  font-size: 13px;
  text-align: center;
  padding: 20px 0;
}
</style>
