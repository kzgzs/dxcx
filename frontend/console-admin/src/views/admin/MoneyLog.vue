<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteMoneyLog, exportMoneyLog, getMoneyLogList } from '../../api/finance'
import type { MoneyLogItem } from '../../api/finance'
import { assetUrl } from '../../api/upload'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const exporting = ref(false)
const total = ref(0)
const tableData = ref<MoneyLogItem[]>([])
const selectedRows = ref<MoneyLogItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  mid: '',
  tel: '',
  nickname: '',
  remark: '',
  timeRange: null as [string, string] | null,
})

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    mid: query.mid ? Number(query.mid) : undefined,
    tel: query.tel || undefined,
    nickname: query.nickname || undefined,
    remark: query.remark || undefined,
    start_date: query.timeRange?.[0] || undefined,
    end_date: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getMoneyLogList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
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

function handleSelectionChange(rows: MoneyLogItem[]) {
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
    const blob = await exportMoneyLog(buildParams())
    downloadBlob(blob, `余额明细_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
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
      `确认删除选中的 ${selectedRows.value.length} 条余额明细？删除后无法恢复！`,
      '删除确认',
      { type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  try {
    await deleteMoneyLog({ ids: selectedRows.value.map((r) => r.id) })
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  }
}

async function handleRowDelete(row: MoneyLogItem) {
  try {
    await ElMessageBox.confirm('确定要删除该条余额明细吗？删除后无法恢复！', '删除确认', {
      type: 'warning',
      confirmButtonText: '确定删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  try {
    await deleteMoneyLog({ id: row.id })
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  }
}

function paytypeText(p: string): string {
  if (p === 'wxpay') return '微信'
  if (p === 'alipay') return '支付宝'
  if (p === 'cash') return '现金'
  if (p === 'bank') return '银行卡'
  return p || '-'
}

function showRecharge(row: MoneyLogItem) {
  if (!row.rechargeid) return
  ElMessageBox.alert(`充值记录 ID：${row.rechargeid}（该笔余额变动来自充值单）`, '充值明细', {
    confirmButtonText: '知道了',
  })
}

onMounted(() => {
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
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="ID" clearable style="width: 80px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input v-model="query.tel" placeholder="手机号" clearable style="width: 115px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="昵称" clearable style="width: 110px" @keyup.enter="handleSearch" />
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
        <el-form-item label="备注">
          <el-input v-model="query.remark" placeholder="备注" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" class="table-card">
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
        <el-table-column label="变更金额" width="110" sortable="custom">
          <template #default="{ row }">
            <span :class="Number(row.money) >= 0 ? 'success-text' : 'danger-text'">
              {{ Number(row.money) >= 0 ? '+' : '' }}{{ Number(row.money).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="变更后金额" width="110" sortable="custom">
          <template #default="{ row }">{{ Number(row.after).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="变更时间" width="150" sortable="custom">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ formatTime(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="支付方式" width="100">
          <template #default="{ row }">{{ paytypeText(row.paytype) }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作员" width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.un || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button v-if="row.rechargeid" link type="primary" @click="showRecharge(row)">充值明细</el-button>`r`n              <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
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
  margin-bottom: 8px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 8px 12px;
}
.toolbar-card :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-card :deep(.el-form-item__label) {
  padding-right: 6px;
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
.success-text {
  color: #67c23a;
  font-weight: 600;
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