<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { exportCashierTongji, getCashierTongji } from '../../api/cashier'
import type { CashierTongjiRow } from '../../api/cashier'

// 对齐点大：按商品统计 / 按规格统计 双 tab
const activeTab = ref(1)

const loading = ref(false)
const exporting = ref(false)
const tableData = ref<CashierTongjiRow[]>([])
const total = ref(0)
const totaldata = ref<{ num: number; totalprice: number; chengben: number; lirun: number } | null>(null)

const query = reactive({
  page: 1,
  pagesize: 20,
  ctime: null as [string, string] | null,
  paytime: null as [string, string] | null,
  field: '',
  order: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCashierTongji({
      type: activeTab.value,
      page: query.page,
      pagesize: query.pagesize,
      ctime_start: query.ctime?.[0] || undefined,
      ctime_end: query.ctime?.[1] || undefined,
      paytime_start: query.paytime?.[0] || undefined,
      paytime_end: query.paytime?.[1] || undefined,
      field: query.field || undefined,
      order: query.order || undefined,
    })
    tableData.value = data?.list || []
    total.value = data?.total || 0
    if (query.page === 1) {
      totaldata.value = data?.totaldata || null
    }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleTab(tab: number) {
  activeTab.value = tab
  query.page = 1
  fetchList()
}

function handleSearch() {
  query.page = 1
  fetchList()
}

// 服务端排序（对齐点大 autoSort:false）
function handleSortChange(sort: { prop: string; order: 'ascending' | 'descending' | null }) {
  if (!sort.order) {
    query.field = ''
    query.order = ''
  } else {
    query.field = sort.prop
    query.order = sort.order === 'ascending' ? 'asc' : 'desc'
  }
  query.page = 1
  fetchList()
}

// 导出（后端返回 title+data，前端生成带 BOM 的 CSV 下载）
function csvEscape(val: string): string {
  if (/[",\n]/.test(val)) return `"${val.replace(/"/g, '""')}"`
  return val
}
async function handleExport() {
  exporting.value = true
  try {
    const data = await exportCashierTongji({
      type: activeTab.value,
      ctime_start: query.ctime?.[0] || undefined,
      ctime_end: query.ctime?.[1] || undefined,
      paytime_start: query.paytime?.[0] || undefined,
      paytime_end: query.paytime?.[1] || undefined,
      field: query.field || undefined,
      order: query.order || undefined,
    })
    if (!data.count) {
      ElMessage.warning('没有可导出的数据')
      return
    }
    const lines = [data.title, ...data.data].map((row) => row.map((c) => csvEscape(String(c))).join(','))
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `订单统计_${activeTab.value === 1 ? '按商品' : '按规格'}_${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success(`已导出 ${data.count} 条记录`)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    exporting.value = false
  }
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never" class="tab-card">
      <div class="tabs">
        <span :class="['tab', activeTab === 1 ? 'active' : '']" @click="handleTab(1)">按商品统计</span>
        <span :class="['tab', activeTab === 2 ? 'active' : '']" @click="handleTab(2)">按规格统计</span>
      </div>
    </el-card>

    <el-card shadow="never" class="toolbar">
      <div class="toolbar-inner">
        <div class="toolbar-left">
          <el-button :loading="exporting" @click="handleExport">导出</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tf-label">下单时间</span>
          <el-date-picker
            v-model="query.ctime"
            type="daterange"
            range-separator="~"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 200px"
          />
          <span class="tf-label">付款时间</span>
          <el-date-picker
            v-model="query.paytime"
            type="daterange"
            range-separator="~"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 200px"
          />
          <button class="search-btn" title="搜索" @click="handleSearch">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M11.4 10.2a6 6 0 1 0-1.2 1.2l3.4 3.4 1.2-1.2-3.4-3.4zM7 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
          </button>
        </div>
      </div>
    </el-card>

    <!-- 汇总条（page=1 时后端返回 totaldata） -->
    <el-card shadow="never" class="summary">
      <div class="summary-inner">
        <span>总销售数量：<b>{{ totaldata?.num ?? 0 }}</b></span>
        <span>总销售金额：<b>{{ (totaldata?.totalprice ?? 0).toFixed(2) }}</b></span>
        <span>总商品成本：<b>{{ (totaldata?.chengben ?? 0).toFixed(2) }}</b></span>
        <span>毛利润：<b>{{ (totaldata?.lirun ?? 0).toFixed(2) }}</b></span>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @sort-change="handleSortChange">
        <el-table-column prop="ph" label="排名" width="70" />
        <el-table-column label="商品名称" min-width="300">
          <template #default="{ row }">
            <div class="goods-cell">
              <img :src="row.pic" class="goods-pic" />
              <span>{{ row.name }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column v-if="activeTab === 2" prop="ggname" label="商品规格" min-width="140" />
        <el-table-column prop="num" label="销售数量" width="110" sortable="custom" />
        <el-table-column prop="totalprice" label="销售金额" width="120" sortable="custom">
          <template #default="{ row }">{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="avgprice" label="平均单价" width="110" sortable="custom">
          <template #default="{ row }">{{ Number(row.avgprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="chengben" label="总商品成本" width="120" sortable="custom">
          <template #default="{ row }">{{ Number(row.chengben).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="lirun" label="毛利润" width="120" sortable="custom">
          <template #default="{ row }">{{ Number(row.lirun).toFixed(2) }}</template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager" background layout="total, sizes, prev, pager, next, jumper"
        :total="total" v-model:current-page="query.page" v-model:page-size="query.pagesize"
        :page-sizes="[10, 20, 50]" @current-change="fetchList" @size-change="handleSearch"
      />
    </el-card>
  </div>
</template>

<style scoped>
.tab-card { margin-bottom: 3px; }
.tabs { display: flex; gap: 24px; }
.tab { cursor: pointer; padding: 6px 4px; font-size: 14px; color: #606266; border-bottom: 2px solid transparent; }
.tab.active { color: #409eff; border-bottom-color: #409eff; font-weight: 600; }
.toolbar { margin-bottom: 3px; }
.toolbar-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap; }
.toolbar-left { display: flex; gap: 8px; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.tf-label { font-size: 13px; color: #606266; white-space: nowrap; }
.search-btn { width: 30px; height: 30px; border: none; background: #67c23a; color: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 3px; }
.summary { margin-bottom: 3px; }
.summary-inner { display: flex; gap: 60px; }
.summary-inner b { font-weight: 700; }
.el-card { border-radius: 0; }
.el-table :deep(th) { background: #e6e6e6; text-align: left; }
.el-table :deep(td .cell) { text-align: left; }
.pager { margin-top: 12px; justify-content: flex-end; }
.goods-cell { display: flex; align-items: center; gap: 8px; }
.goods-pic { width: 50px; height: 50px; border-radius: 4px; object-fit: cover; }
</style>
