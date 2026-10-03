<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteFenhongLog, exportFenhongLog, getFenhongLogList } from '../../api/finance'
import type { FenhongLogItem, CommissionRecordStat } from '../../api/finance'
import { assetUrl } from '../../api/upload'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const exporting = ref(false)
const total = ref(0)
const tableData = ref<FenhongLogItem[]>([])
const selectedRows = ref<FenhongLogItem[]>([])
const stat = ref<CommissionRecordStat>({ count: 0, pending: 0, paid: 0, backed: 0, total_commission: 0 })

// 分红来源模块（order_type，对照点大 fenhonglog 搜索下拉）
const MODULE_OPTIONS = [
  { label: '商城订单', value: 'shop' },
  { label: '多人拼团', value: 'collage' },
  { label: '幸运拼团', value: 'lucky_collage' },
  { label: '砍价', value: 'kanjia' },
  { label: '秒杀', value: 'seckill' },
  { label: '预约', value: 'yuyue' },
  { label: '酒店', value: 'hotel' },
  { label: '餐饮', value: 'restaurant' },
  { label: '积分商城', value: 'scoreshop' },
]

const query = reactive({
  page: 1,
  pagesize: 10,
  order_type: 'shop',
  ordernum: '',
  mid: '',
  nickname: '',
  remark: '',
  status: '',
  timeRange: null as [string, string] | null,
})

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    ordernum: query.ordernum || undefined,
    order_type: query.ordernum ? query.order_type || 'shop' : undefined,
    mid: query.mid ? Number(query.mid) : undefined,
    nickname: query.nickname || undefined,
    remark: query.remark || undefined,
    status: query.status || undefined,
    start_date: query.timeRange?.[0] || undefined,
    end_date: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getFenhongLogList(buildParams())
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

function handlePageChange(page: number) { query.page = page; fetchList() }
function handleSizeChange(size: number) { query.pagesize = size; query.page = 1; fetchList() }
function handleSelectionChange(rows: FenhongLogItem[]) { selectedRows.value = rows }

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
    const blob = await exportFenhongLog(buildParams())
    downloadBlob(blob, `分红记录_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    exporting.value = false
  }
}

async function handleBatchDelete() {
  if (selectedRows.value.length === 0) { ElMessage.warning('请先勾选要删除的记录'); return }
  try {
    await ElMessageBox.confirm(`确定要删除选中的 ${selectedRows.value.length} 条分红记录吗？删除后无法恢复！`, '操作确认', {
      type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消',
    })
  } catch { return }
  try {
    await deleteFenhongLog({ ids: selectedRows.value.map((r) => r.id) })
    ElMessage.success('删除成功')
    fetchList()
  } catch { /* 拦截器弹出 */ }
}

async function handleRowDelete(row: FenhongLogItem) {
  try {
    await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
      type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消',
    })
  } catch { return }
  try {
    await deleteFenhongLog({ id: row.id })
    ElMessage.success('删除成功')
    fetchList()
  } catch { /* 拦截器弹出 */ }
}

// 订单状态着色（对照点大 fenhonglog.html 默认 shop 分支）
function orderStatus(row: FenhongLogItem): { text: string; color: string } {
  const s = row.orderstatus
  if (s === 0) return { text: '未支付', color: '#f56c6c' }
  if (s === 1) return { text: '已支付', color: '#67c23a' }
  if (s === 2) return { text: row.module === 'shop' ? '已发货' : '已确认', color: '#67c23a' }
  if (s === 3) return { text: '已收货', color: '#67c23a' }
  if (s === 4 || s === -1) return { text: '已关闭', color: '#999' }
  return { text: '-', color: '#909399' }
}

function statusText(row: FenhongLogItem): { text: string; color: string } {
  if (row.status === 0) return { text: '未发放', color: '#f56c6c' }
  if (row.status === 1) return { text: '已发放', color: '#67c23a' }
  return { text: '已退回', color: '#f56c6c' }
}

onMounted(() => { fetchList() })
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item>
          <el-button :disabled="selectedRows.length === 0" @click="handleBatchDelete">删除</el-button>
          <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
        </el-form-item>
        <el-form-item label="订单类型">
          <el-select v-model="query.order_type" style="width: 110px">
            <el-option v-for="m in MODULE_OPTIONS" :key="m.value" :label="m.label" :value="m.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" placeholder="请输入订单号" clearable style="width: 150px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="ID" clearable style="width: 80px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="昵称" clearable style="width: 100px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="query.remark" placeholder="备注" clearable style="width: 100px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 95px">
            <el-option label="未发放" value="0" />
            <el-option label="已发放" value="1" />
            <el-option label="已退回" value="2" />
          </el-select>
        </el-form-item>
        <el-form-item label="结算时间">
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
        <span class="stat-inline-item">未发放：<b>{{ stat.pending }}</b></span>
        <span class="stat-inline-item">已发放：<b>{{ stat.paid }}</b></span>
        <span class="stat-inline-item">已退回：<b>{{ stat.backed }}</b></span>
        <span class="stat-inline-item">分红合计：<b>￥{{ Number(stat.total_commission).toFixed(2) }}</b></span>
      </div>
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="mid" label="会员ID" width="80" />
        <el-table-column label="会员信息" min-width="150">
          <template #default="{ row }">
            <div class="member-cell">
              <img v-if="row.headimg" :src="assetUrl(row.headimg)" class="member-avatar" @error="onAvatarError" />
              <span>{{ row.nickname || ('会员' + row.mid) }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="分红金额" width="100">
          <template #default="{ row }">￥{{ Number(row.commission).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="佣金" width="90">
          <template #default="{ row }">￥{{ Number(row.send_commission).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="余额" width="90">
          <template #default="{ row }">￥{{ Number(row.send_money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="积分" width="80">
          <template #default="{ row }">{{ Number(row.send_score).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="结算时间" width="150">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ formatTime(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="来源订单编号" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.ordernum || '-' }}</template>
        </el-table-column>
        <el-table-column label="订单状态" width="90">
          <template #default="{ row }">
            <span :style="{ color: orderStatus(row).color }">{{ orderStatus(row).text }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :style="{ color: statusText(row).color }">{{ statusText(row).text }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <div class="row-ops">
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
.member-cell { display: flex; align-items: center; gap: 6px; }
.member-avatar { width: 26px; height: 26px; border-radius: 50%; object-fit: cover; border: 1px solid #ebeef5; flex-shrink: 0; }
.row-ops { display: flex; flex-direction: row; flex-wrap: wrap; gap: 2px 6px; white-space: nowrap; align-items: center; }
.row-ops :deep(.el-button) { height: auto; padding: 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 12px; justify-content: flex-end; }
</style>