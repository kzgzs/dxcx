<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteHexiaoShopproduct, exportHexiaoShopproduct, getHexiaoShopproductList } from '../../api/finance'
import type { HexiaoShopproductItem, HexiaoShopproductQuery, HexiaoShopproductStat } from '../../api/finance'
import { getMerchantList } from '../../api/merchant'
import type { MerchantItem } from '../../api/types'
import { assetUrl } from '../../api/upload'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const exporting = ref(false)
const total = ref(0)
const tableData = ref<HexiaoShopproductItem[]>([])
const selectedRows = ref<HexiaoShopproductItem[]>([])
const stat = ref<HexiaoShopproductStat>({ count: 0, total_num: 0 })
const merchantOptions = ref<MerchantItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  bid: '',
  mid: '',
  nickname: '',
  title: '',
  timeRange: null as [string, string] | null,
})

function buildParams(): HexiaoShopproductQuery {
  return {
    page: query.page,
    pagesize: query.pagesize,
    bid: query.bid || undefined,
    mid: query.mid ? Number(query.mid) : undefined,
    nickname: query.nickname || undefined,
    title: query.title || undefined,
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

function handleSearch() { query.page = 1; fetchList() }
function handlePageChange(page: number) { query.page = page; fetchList() }
function handleSizeChange(size: number) { query.pagesize = size; query.page = 1; fetchList() }
function handleSelectionChange(rows: HexiaoShopproductItem[]) { selectedRows.value = rows }

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

async function handleBatchDelete() {
  if (selectedRows.value.length === 0) { ElMessage.warning('请先勾选要删除的记录'); return }
  try {
    await ElMessageBox.confirm(`确定要删除选中的 ${selectedRows.value.length} 条记录吗？删除后无法恢复！`, '操作确认', {
      type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消',
    })
  } catch { return }
  try {
    await deleteHexiaoShopproduct({ ids: selectedRows.value.map((r) => r.id) })
    ElMessage.success('删除成功')
    fetchList()
  } catch { /* 拦截器弹出 */ }
}

async function handleRowDelete(row: HexiaoShopproductItem) {
  try {
    await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
      type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消',
    })
  } catch { return }
  try {
    await deleteHexiaoShopproduct({ id: row.id })
    ElMessage.success('删除成功')
    fetchList()
  } catch { /* 拦截器弹出 */ }
}

onMounted(() => {
  loadMerchants()
  fetchList()
})
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item>
          <el-button :disabled="selectedRows.length === 0" @click="handleBatchDelete">删除</el-button>
          <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
        </el-form-item>
        <el-form-item label="所属商户">
          <el-select v-model="query.bid" placeholder="全部" clearable style="width: 140px">
            <el-option label="[平台]" value="0" />
            <el-option v-for="m in merchantOptions" :key="m.id" :label="m.name" :value="String(m.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="ID" clearable style="width: 80px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="昵称" clearable style="width: 110px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="商品名称">
          <el-input v-model="query.title" placeholder="商品名称" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="核销时间">
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
        <span class="stat-inline-item">共计核销数量：<b>{{ stat.total_num }}</b></span>
        <span class="stat-inline-item">记录数：<b>{{ stat.count }}</b></span>
      </div>
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="所属商户" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">
            <el-tag v-if="row.bid === 0" size="small" type="info">[平台]</el-tag>
            <span v-else>{{ row.bname || ('商户' + row.bid) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="ordernum" label="订单号" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.ordernum || '-' }}</template>
        </el-table-column>
        <el-table-column prop="mid" label="会员ID" width="80" />
        <el-table-column label="会员信息" min-width="150">
          <template #default="{ row }">
            <div class="member-cell">
              <img v-if="row.headimg" :src="assetUrl(row.headimg)" class="member-avatar" @error="onAvatarError" />
              <span>{{ row.nickname || ('会员' + row.mid) }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="商品名称" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title || '-' }}</template>
        </el-table-column>
        <el-table-column label="核销数量" width="90">
          <template #default="{ row }">
            <span class="num-strong">{{ row.num }}</span>
          </template>
        </el-table-column>
        <el-table-column label="核销时间" width="150">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ formatTime(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
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
.num-strong { color: #409eff; font-weight: 600; }
.row-ops { display: flex; flex-direction: row; flex-wrap: wrap; gap: 2px 6px; white-space: nowrap; align-items: center; }
.row-ops :deep(.el-button) { height: auto; padding: 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 12px; justify-content: flex-end; }
</style>