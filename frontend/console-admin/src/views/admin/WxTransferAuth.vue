<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getWxTransferAuthList, queryWxTransferAuth } from '../../api/finance'
import type { FinPageQuery, WxTransferAuthItem } from '../../api/finance'
import { assetUrl } from '../../api/upload'
import { formatTime } from '../../utils/time'

// 授权状态筛选（契约第6页 status 文案：已确认/已关闭/待用户确认）
const STATE_OPTIONS = [
  { label: '已确认授权', value: 'CONFIRMED' },
  { label: '已关闭授权', value: 'CLOSED' },
  { label: '待用户确认', value: 'WAIT_USER_CONFIRM' },
]

const loading = ref(false)
const querying = ref(false)
const tableData = ref<WxTransferAuthItem[]>([])
const total = ref(0)
// 通道是否接入：后端返回 available:false 时渲染空态，不编造数据
const available = ref(true)
const note = ref('')

const query = reactive({
  page: 1,
  pagesize: 10,
  mid: '',
  state: '',
  timeRange: null as [string, string] | null,
})

function buildParams() {
  // FinPageQuery 仅 page/pagesize/start_date/end_date；mid/state 随骨架透传，未接入通道后端忽略
  const params: Record<string, unknown> = {
    page: query.page,
    pagesize: query.pagesize,
    start_date: query.timeRange?.[0] || undefined,
    end_date: query.timeRange?.[1] || undefined,
  }
  if (query.mid) params.mid = Number(query.mid)
  if (query.state) params.state = query.state
  return params as unknown as FinPageQuery
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getWxTransferAuthList(buildParams())
    available.value = data.available !== false
    note.value = data.note || ''
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// 顶栏[一键查询]：主动向后端查询授权通道状态
async function handleQueryAll() {
  querying.value = true
  try {
    const res = await queryWxTransferAuth({})
    if (res.available === false) {
      ElMessage.warning(res.msg || '微信转账授权通道未接入')
      available.value = false
    } else {
      ElMessage.success(res.msg || '查询完成')
      fetchList()
    }
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    querying.value = false
  }
}

// 行[查询]：按行 id 回查
async function handleRowQuery(row: WxTransferAuthItem) {
  try {
    const res = await queryWxTransferAuth({ id: row.id })
    ElMessage[res.available === false ? 'warning' : 'success'](res.msg || (res.available === false ? '通道未接入' : '查询完成'))
  } catch {
    // 错误提示已由拦截器弹出
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

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div class="fin-page">
    <el-card shadow="never" class="main-card">
      <!-- 工具栏：顶栏[一键查询] + 搜索 同一行 -->
      <div class="toolbar">
        <div class="toolbar-btns">
          <el-button size="small" :loading="querying" @click="handleQueryAll">一键查询</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="会员ID">
            <el-input v-model="query.mid" placeholder="会员ID" clearable style="width: 110px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="query.state" placeholder="全部" clearable style="width: 140px">
              <el-option v-for="o in STATE_OPTIONS" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
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
            <el-button type="success" class="search-icon-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 表格：无 fixed 列，整体横向滚动 -->
      <el-table v-loading="loading" :data="tableData" border>
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="mid" label="会员ID" width="80" />
        <el-table-column label="会员信息" min-width="160">
          <template #default="{ row }">
            <div class="member-info">
              <img v-if="row.headimg" :src="assetUrl(row.headimg)" class="member-avatar" @error="onAvatarError" />
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="appid" label="APPID" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.appid || '-' }}</template>
        </el-table-column>
        <el-table-column prop="platform" label="授权平台" width="120">
          <template #default="{ row }">{{ row.platform || '微信小程序' }}</template>
        </el-table-column>
        <el-table-column prop="ordernum" label="平台订单号" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.ordernum || '-' }}</template>
        </el-table-column>
        <el-table-column label="创建时间" width="140">
          <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
        </el-table-column>
        <el-table-column label="授权时间" width="140">
          <template #default="{ row }">{{ row.confirm_time ? formatTime(row.confirm_time) : '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">{{ row.status_text || row.status || '-' }}</template>
        </el-table-column>
        <el-table-column prop="transfer_bill_no" label="微信授权单号" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.transfer_bill_no || '-' }}</template>
        </el-table-column>
        <el-table-column prop="message" label="错误信息" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.message || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <div class="op-row">
              <el-button link type="primary" @click="handleRowQuery(row)">查询</el-button>
            </div>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty :description="available ? '暂无数据' : '微信转账授权通道未接入'" />
        </template>
      </el-table>
      <div v-if="!available && note" class="note-tip">{{ note }}</div>

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
.fin-page {
  padding: 3px;
}
.main-card :deep(.el-card__body) {
  padding: 3px;
}
.toolbar {
  display: flex;
  align-items: center;
  margin-bottom: 3px;
  flex-wrap: nowrap;
}
.toolbar-btns {
  display: flex;
  gap: 8px;
  margin-right: 12px;
  flex-shrink: 0;
}
.toolbar-search {
  flex: 1;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 10px;
}
.toolbar-search :deep(.el-input__inner) {
  text-align: left;
}
.search-icon-btn {
  border-radius: 50%;
  padding: 8px 13px;
}
.search-icon-btn svg {
  display: block;
}
:deep(.el-table th.el-table__cell) {
  background: #e6e6e6;
  text-align: left;
}
:deep(.el-table .cell) {
  text-align: left;
  padding-left: 6px;
  padding-right: 6px;
}
:deep(.el-table td.el-table__cell) {
  padding: 3px 0;
}
.member-info {
  display: flex;
  align-items: center;
  gap: 6px;
}
.member-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  object-fit: cover;
}
.op-row {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 2px 6px;
  white-space: nowrap;
}
.op-row :deep(.el-button) {
  margin: 0;
}
.note-tip {
  font-size: 12px;
  color: #909399;
  padding: 4px 2px 0;
}
.pagination {
  margin-top: 3px;
  justify-content: flex-end;
}
</style>
