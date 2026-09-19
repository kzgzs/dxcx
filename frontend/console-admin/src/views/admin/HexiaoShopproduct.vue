<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { exportHexiaoShopproduct, getHexiaoShopproductList } from '../../api/finance'
import type { HexiaoShopproductItem, HexiaoShopproductStat } from '../../api/finance'
import { getMerchantList } from '../../api/merchant'
import type { MerchantItem } from '../../api/types'
import { formatTime } from '../../utils/time'

/**
 * 计次核销记录（hexiao_shopproduct，任务177）
 * join member 显示会员；按 bid 商户维度过滤（全部/自营/商户/指定商户）；汇总统计与导出
 */
const loading = ref(false)
const exporting = ref(false)
const total = ref(0)
const tableData = ref<HexiaoShopproductItem[]>([])
const stat = ref<HexiaoShopproductStat>({ count: 0, total_num: 0 })
const merchantOptions = ref<MerchantItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  bid: '',
  nickname: '',
  title: '',
  mid: '',
  status: '',
  timeRange: null as [string, string] | null,
})

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    bid: query.bid || undefined,
    nickname: query.nickname || undefined,
    title: query.title || undefined,
    mid: query.mid ? Number(query.mid) : undefined,
    status: query.status || undefined,
    start_date: query.timeRange?.[0] || undefined,
    end_date: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getHexiaoShopproductList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { count: 0, total_num: 0 }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function loadMerchants() {
  try {
    const data = await getMerchantList({ page: 1, pagesize: 200 })
    merchantOptions.value = data.list || []
  } catch {
    // 商户选项加载失败不阻塞列表
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.bid = ''
  query.nickname = ''
  query.title = ''
  query.mid = ''
  query.status = ''
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
    const blob = await exportHexiaoShopproduct(buildParams())
    downloadBlob(blob, `计次核销记录_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    exporting.value = false
  }
}

function shortDate(ts: number): string {
  if (!ts) return '-'
  const full = formatTime(ts)
  return full.length >= 10 ? full.slice(2, 10) : full
}

onMounted(() => {
  loadMerchants()
  fetchList()
})
</script>

<template>
  <div>
    <el-row :gutter="12" class="stat-row">
      <el-col :span="12">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">核销记录数</div>
          <div class="stat-value primary">{{ stat.count }}</div>
        </el-card>
      </el-col>
      <el-col :span="12">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">核销总次数</div>
          <div class="stat-value success">{{ stat.total_num }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="商户">
          <el-select v-model="query.bid" placeholder="全部" clearable style="width: 150px">
            <el-option label="自营" value="0" />
            <el-option label="全部商户" value="gt0" />
            <el-option v-for="m in merchantOptions" :key="m.id" :label="m.name" :value="String(m.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="会员昵称" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="商品">
          <el-input v-model="query.title" placeholder="商品名称" clearable style="width: 140px" @keyup.enter="handleSearch" />
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
          <el-button type="success" :loading="exporting" @click="handleExport">导出</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="64" class-name="compact-col" />
        <el-table-column label="核销商家" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">
            <el-tag v-if="row.bid === 0" size="small" type="info">平台自营</el-tag>
            <span v-else>{{ row.bname || `商户${row.bid}` }}</span>
          </template>
        </el-table-column>
        <el-table-column label="会员" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.nickname || `会员${row.mid}` }}
            <span class="sub-text">ID:{{ row.mid }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="ordernum" label="订单号" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.ordernum || '-' }}</template>
        </el-table-column>
        <el-table-column prop="title" label="商品名称" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title || '-' }}</template>
        </el-table-column>
        <el-table-column label="核销数量" width="84" class-name="compact-col">
          <template #default="{ row }">
            <span class="num-strong">{{ row.num }}</span>
          </template>
        </el-table-column>
        <el-table-column label="核销时间" width="92" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ shortDate(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="配送备注" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.delivery_remark">
              {{ row.delivery_remark }}
              <span v-if="row.delivery_time" class="sub-text">{{ shortDate(row.delivery_time) }}</span>
            </span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip>
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

.stat-value.success {
  color: #67c23a;
}

.toolbar-card {
  margin-bottom: 12px;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 4px;
}

.num-strong {
  color: #409eff;
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
