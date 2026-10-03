<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getExpressRecordList,
  getExpressRecordTrace,
} from '../../api/express'
import type { ExpressRecordItem, TraceResult } from '../../api/express'
import { formatTime } from '../../utils/time'

// 租户后台 - 扩展·同城配送·快递鸟记录（片C；列表 + 查看物流弹窗复用 Kdniao trace）

const loading = ref(false)
const tableData = ref<ExpressRecordItem[]>([])
const total = ref(0)
const query = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  mid: '',
  keyword: '',
  status: '',
})

// 物流弹窗
const traceVisible = ref(false)
const traceLoading = ref(false)
const trace = ref<TraceResult>({ order_status: '', state: '', steps: [], trace_msg: '', is_mock: false })

async function fetchList() {
  loading.value = true
  try {
    const data = await getExpressRecordList({
      page: query.page,
      pagesize: query.pagesize,
      ordernum: query.ordernum || undefined,
      mid: query.mid || undefined,
      keyword: query.keyword || undefined,
      status: query.status === '' ? undefined : query.status,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

async function handleViewTrace(row: ExpressRecordItem) {
  traceVisible.value = true
  traceLoading.value = true
  trace.value = { order_status: '', state: '', steps: [], trace_msg: '', is_mock: false }
  try {
    trace.value = await getExpressRecordTrace(row.id)
  } catch {
    /* 拦截器已提示 */
  } finally {
    traceLoading.value = false
  }
}

function exportExcel() {
  if (!tableData.value.length) {
    return ElMessage.warning('暂无数据可导出')
  }
  const head = ['下单人', '订单编号', '下单时间', '快递鸟配送编号', '商品信息', '收货人', '收货电话', '收货地址', '重量', '状态']
  const rows = tableData.value.map((r) => [
    r.member?.nickname || '',
    r.ordernum,
    r.createtime ? formatTime(r.createtime) : '',
    r.kuaidinum,
    r.cargo || '',
    r.recManName,
    r.recManMobile,
    r.recManPrintAddr,
    r.weight,
    r.status_name,
  ])
  const csv = [head, ...rows]
    .map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(','))
    .join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = '快递鸟即时配送记录.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- 工具栏：导出按钮左 + 搜索右（同行不换行，框收窄，绿色圆形搜索钮） -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-left">
        <el-button @click="exportExcel">导出EXCEL</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item label="订单信息">
          <el-input v-model="query.ordernum" placeholder="订单编号/快递鸟配送编号" clearable style="width: 170px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="下单人ID">
          <el-input v-model="query.mid" placeholder="下单人ID" clearable style="width: 90px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="关键字">
          <el-input v-model="query.keyword" placeholder="下单人昵称/姓名/手机号" clearable style="width: 150px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 100px">
            <el-option label="待接单" :value="0" />
            <el-option label="已接单" :value="1" />
            <el-option label="配送中" :value="2" />
            <el-option label="已完成" :value="3" />
            <el-option label="已取消" :value="10" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border size="small">
        <el-table-column label="下单人" width="120">
          <template #default="{ row }">
            <div class="member-name">{{ row.member?.nickname || '-' }}</div>
            <div class="sub-text">ID:{{ row.mid }}</div>
          </template>
        </el-table-column>
        <el-table-column label="订单编号/下单时间" min-width="150">
          <template #default="{ row }">
            <div class="orderno">{{ row.ordernum }}</div>
            <div class="sub-text">{{ row.createtime ? formatTime(row.createtime) : '' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="快递鸟配送编号/推单时间" min-width="170">
          <template #default="{ row }">
            <div class="orderno">{{ row.kuaidinum || '-' }}</div>
            <div class="sub-text">{{ row.createtime ? formatTime(row.createtime) : '' }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="cargo" label="商品信息" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.cargo || '-' }}</template>
        </el-table-column>
        <el-table-column label="收货信息" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.recManName }} {{ row.recManMobile }}<br />{{ row.recManPrintAddr }}
          </template>
        </el-table-column>
        <el-table-column label="总重量" width="90">
          <template #default="{ row }">{{ row.weight ? row.weight + 'kg' : '-' }}</template>
        </el-table-column>
        <el-table-column prop="status_name" label="状态" width="110" />
        <el-table-column label="操作" width="100">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="handleViewTrace(row)">查看物流</el-button>
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
        :page-sizes="[10, 20, 50]"
        @current-change="(p: number) => { query.page = p; fetchList() }"
        @size-change="(s: number) => { query.pagesize = s; query.page = 1; fetchList() }"
      />
    </el-card>

    <!-- 查看物流弹窗（快递公司/单号 + 纵向时间线，最新高亮） -->
    <el-dialog v-model="traceVisible" title="查看物流" width="440px" destroy-on-close>
      <div v-loading="traceLoading">
        <div class="order-status">订单状态：{{ trace.order_status || '-' }}</div>
        <div class="trace-base">
          <span>快递公司：{{ trace.company_name || '-' }}</span>
          <span>运单号：{{ trace.logistic_no || '-' }}</span>
        </div>
        <div v-if="!trace.steps.length && !traceLoading" class="no-trace">
          {{ trace.trace_msg || '暂无物流轨迹' }}
        </div>
        <div v-else class="trace-timeline">
          <div
            v-for="(s, i) in trace.steps"
            :key="i"
            class="trace-item"
            :class="{ latest: i === trace.steps.length - 1 }"
          >
            <div class="dot">
              <svg v-if="i === trace.steps.length - 1" viewBox="0 0 1024 1024" width="10" height="10" fill="#fff"><path d="M382 686 204 508l-46 46 224 224 480-480-46-46z" /></svg>
            </div>
            <div class="trace-body">
              <div class="trace-text">{{ s.title || s.text }}</div>
              <div class="trace-time">{{ s.time }}</div>
            </div>
          </div>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 12px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
  gap: 8px;
  flex: 0 0 auto;
}
.toolbar-left .el-button {
  margin: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  margin: 0;
  min-width: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 6px;
  flex-shrink: 0;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.toolbar-search :deep(.el-form-item__label) {
  font-size: 12px;
}
.search-btn {
  padding: 8px 14px;
}
.search-btn svg {
  display: block;
}
.sub-text {
  color: #909399;
  font-size: 12px;
}
.member-name {
  font-size: 13px;
}
.orderno {
  color: #2d8cf0;
  font-size: 13px;
}
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
.order-status {
  background: #f5f5f5;
  padding: 10px 12px;
  border-radius: 4px;
  margin-bottom: 10px;
  color: #333;
}
.trace-base {
  display: flex;
  gap: 18px;
  font-size: 13px;
  color: #606266;
  margin-bottom: 18px;
}
.no-trace {
  color: #999;
  text-align: center;
  padding: 20px 0;
}
.trace-timeline {
  padding-left: 4px;
}
.trace-item {
  position: relative;
  padding-left: 22px;
  padding-bottom: 20px;
}
.trace-item::before {
  content: '';
  position: absolute;
  left: 5px;
  top: 16px;
  bottom: 0;
  width: 2px;
  background: #e4e7ed;
}
.trace-item:last-child::before {
  display: none;
}
.dot {
  position: absolute;
  left: 0;
  top: 2px;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #2d8cf0;
  border: 2px solid #fff;
  box-shadow: 0 0 0 1px #2d8cf0;
}
.trace-item.latest .dot {
  background: #2d8cf0;
  box-shadow: 0 0 0 2px #2d8cf0;
}
.trace-text {
  font-size: 13px;
  color: #606266;
}
.trace-item.latest .trace-text {
  color: #2d8cf0;
  font-weight: 600;
}
.trace-time {
  font-size: 12px;
  color: #999;
  margin-top: 4px;
}
</style>
