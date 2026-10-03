<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deletePayorder, exportPayorder, getPayorderList, payorderDatasettle, payorderDatapushorder } from '../../api/finance'
import type { PayorderItem, PayorderStat } from '../../api/finance'
import { assetUrl } from '../../api/upload'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const exporting = ref(false)
const settling = ref(false)
const pushing = ref(false)
const total = ref(0)
const tableData = ref<PayorderItem[]>([])
const selectedRows = ref<PayorderItem[]>([])
const stat = ref<PayorderStat>({ total_received: 0, total_refund: 0, total_cost: 0 })

const PAYTYPE_OPTIONS = [
  { label: '余额支付', value: 1 },
  { label: '微信支付', value: 2 },
  { label: '支付宝支付', value: 3 },
  { label: '货到付款', value: 4 },
  { label: '百度小程序', value: 11 },
  { label: '头条小程序', value: 12 },
]

const query = reactive({
  page: 1,
  pagesize: 10,
  bname: '',
  mid: '',
  tel: '',
  nickname: '',
  paytypeid: '' as number | '',
  ordernum: '',
  timeRange: null as [string, string] | null,
})

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    bname: query.bname || undefined,
    mid: query.mid ? Number(query.mid) : undefined,
    tel: query.tel || undefined,
    nickname: query.nickname || undefined,
    paytypeid: query.paytypeid === '' ? undefined : query.paytypeid,
    ordernum: query.ordernum || undefined,
    start_date: query.timeRange?.[0] || undefined,
    end_date: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getPayorderList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { total_received: 0, total_refund: 0, total_cost: 0 }
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

function handleSelectionChange(rows: PayorderItem[]) {
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
    const blob = await exportPayorder(buildParams())
    downloadBlob(blob, `消费明细_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    exporting.value = false
  }
}

async function handleBatchDelete() {
  if (selectedRows.value.length === 0) {
    ElMessage.warning('请先勾选要删除的记录')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确认删除选中的 ${selectedRows.value.length} 条消费明细？删除后无法恢复！`,
      '删除确认',
      { type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  try {
    await deletePayorder({ ids: selectedRows.value.map((r) => r.id) })
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  }
}

async function handleRowDelete(row: PayorderItem) {
  try {
    await ElMessageBox.confirm('确定要删除该条消费明细吗？删除后无法恢复！', '删除确认', {
      type: 'warning',
      confirmButtonText: '确定删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  try {
    await deletePayorder({ id: row.id })
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  }
}

// ---- 抖音分账（批量 + 单行） ----
async function doSettle(ids: number[]) {
  settling.value = true
  try {
    const res = await payorderDatasettle(ids)
    ElMessage.success(`成功处理分账${res.success}条`)
    fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    settling.value = false
  }
}

async function handleBatchSettle() {
  if (selectedRows.value.length === 0) {
    ElMessage.warning('请先勾选要分账的记录')
    return
  }
  try {
    await ElMessageBox.confirm(
      '抖音小程序支付分账用于确认一笔在途资金，将其转化为可提现资金，确定要执行分账吗?',
      '操作确认',
      { type: 'warning', confirmButtonText: '确定', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  await doSettle(selectedRows.value.map((r) => r.id))
}

async function handleRowSettle(row: PayorderItem) {
  try {
    await ElMessageBox.confirm(
      '抖音小程序支付分账用于确认一笔在途资金，将其转化为可提现资金，确定要执行分账吗?',
      '操作确认',
      { type: 'warning', confirmButtonText: '确定', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  await doSettle([row.id])
}

// ---- 抖音核销（批量 + 单行） ----
async function doPushorder(ids: number[]) {
  pushing.value = true
  try {
    const res = await payorderDatapushorder(ids)
    ElMessage.success(`成功核销${res.success}条`)
    fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    pushing.value = false
  }
}

async function handleBatchPushorder() {
  if (selectedRows.value.length === 0) {
    ElMessage.warning('请先勾选要核销的记录')
    return
  }
  try {
    await ElMessageBox.confirm(
      '抖音小程序支付核销用于将订单信息推送到抖音订单中心，确定要执行核销吗?',
      '操作确认',
      { type: 'warning', confirmButtonText: '确定', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  await doPushorder(selectedRows.value.map((r) => r.id))
}

async function handleRowPushorder(row: PayorderItem) {
  try {
    await ElMessageBox.confirm(
      '抖音小程序支付核销用于将订单信息推送到抖音订单中心，确定要执行核销吗?',
      '操作确认',
      { type: 'warning', confirmButtonText: '确定', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  await doPushorder([row.id])
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent class="toolbar-form">
        <el-form-item class="batch-actions">
          <el-button @click="handleBatchDelete">删除</el-button>
          <el-button :loading="settling" @click="handleBatchSettle">抖音分账</el-button>
          <el-button :loading="pushing" @click="handleBatchPushorder">抖音核销</el-button>
          <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
        </el-form-item>
        <el-form-item label="商户">
          <el-input v-model="query.bname" placeholder="请输入商户名称/商户ID" clearable style="width: 170px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="ID" clearable style="width: 80px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input v-model="query.tel" placeholder="手机号" clearable style="width: 115px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="昵称" clearable style="width: 105px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="支付方式">
          <el-select v-model="query.paytypeid" placeholder="全部" style="width: 120px">
            <el-option label="全部" :value="''" />
            <el-option v-for="p in PAYTYPE_OPTIONS" :key="p.value" :label="p.label" :value="p.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" placeholder="订单号" clearable style="width: 145px" @keyup.enter="handleSearch" />
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
        <span class="stat-inline-item">总收款金额：<b>￥{{ Number(stat.total_received).toFixed(2) }}</b></span>
        <span class="stat-inline-item">总退款金额：<b>￥{{ Number(stat.total_refund).toFixed(2) }}</b></span>
        <span class="stat-inline-item">总消费金额：<b>￥{{ Number(stat.total_cost).toFixed(2) }}</b></span>
      </div>
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="mid" label="会员ID" width="80" />
        <el-table-column label="会员信息" min-width="160">
          <template #default="{ row }">
            <div class="member-cell">
              <img v-if="row.headimg" :src="assetUrl(row.headimg)" class="member-avatar" @error="onAvatarError" />
              <span>{{ row.nickname || ('会员' + row.mid) }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="bname" label="商户名称" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.bname || '-' }}</template>
        </el-table-column>
        <el-table-column prop="type_text" label="订单类型" width="95" show-overflow-tooltip>
          <template #default="{ row }">{{ row.type_text || '-' }}</template>
        </el-table-column>
        <el-table-column prop="ordernum" label="订单号" min-width="160" show-overflow-tooltip />
        <el-table-column prop="title" label="支付项目" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title || '-' }}</template>
        </el-table-column>
        <el-table-column label="支付金额" width="100" sortable="custom">
          <template #default="{ row }">￥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="支付方式" width="120" show-overflow-tooltip>
          <template #default="{ row }">
            <div v-if="row.paytypeid === 12">
              <div>{{ row.paytype_text || row.paytype || '-' }}</div>
              <span v-if="row.issettle === 0" class="danger-text">未分账</span>
              <span v-else-if="row.issettle === 2" class="danger-text">已核销未分账</span>
            </div>
            <span v-else>{{ row.paytype_text || row.paytype || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="发起时间" width="140" sortable="custom">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ formatTime(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="付款时间" width="140" sortable="custom">
          <template #default="{ row }">
            <el-tooltip v-if="row.paytime" :content="formatTime(row.paytime)" placement="top">
              <span>{{ formatTime(row.paytime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="退款金额" width="100" sortable="custom">
          <template #default="{ row }">
            <span v-if="Number(row.refund_money) > 0" class="danger-text">￥{{ Number(row.refund_money).toFixed(2) }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="最后退款时间" width="140" sortable="custom">
          <template #default="{ row }">
            <el-tooltip v-if="row.refund_time" :content="formatTime(row.refund_time)" placement="top">
              <span>{{ formatTime(row.refund_time) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
              <el-button
                v-if="row.paytypeid === 12 && row.issettle === 0"
                link type="primary"
                @click="handleRowPushorder(row)"
              >核销</el-button>
              <el-button
                v-if="row.paytypeid === 12 && (row.issettle === 0 || row.issettle === 2)"
                link type="warning"
                @click="handleRowSettle(row)"
              >分账</el-button>
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
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 3px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 8px 12px;
}
.toolbar-form {
  flex-wrap: nowrap;
  overflow-x: auto;
}
.toolbar-card :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
  flex-shrink: 0;
}
.toolbar-card :deep(.el-form-item__label) {
  padding-right: 6px;
}
.batch-actions :deep(.el-form-item__content) {
  display: flex;
  gap: 4px;
}
.search-icon-btn {
  padding: 8px 14px;
}
.search-icon-btn svg {
  display: block;
}
.table-card :deep(.el-card__body) {
  padding: 5px;
}
.table-card :deep(.el-table__header-wrapper th.el-table__cell) {
  background: #e6e6e6;
}
.table-card :deep(.el-table .cell) {
  text-align: left;
  padding-left: 6px;
  padding-right: 6px;
}
.table-card :deep(.el-table td.el-table__cell) {
  padding: 3px 0;
}
.stat-inline {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 6px 18px;
  padding: 2px 6px 6px;
  font-size: 13px;
  color: #606266;
}
.stat-inline b {
  color: #303133;
  font-weight: 700;
}
.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
.member-avatar {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  object-fit: cover;
  border: 1px solid #ebeef5;
  flex-shrink: 0;
}
.row-ops {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 2px 6px;
  white-space: nowrap;
  align-items: center;
}
.row-ops :deep(.el-button) {
  height: auto;
  padding: 0;
  margin: 0;
  font-size: 12px;
}
.danger-text {
  color: #f56c6c;
  font-weight: 600;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
</style>
