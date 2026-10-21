<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteYuyueWorkerComment,
  getYuyueWorkerCommentDetail,
  getYuyueWorkerCommentList,
  getYuyueWorkerList,
} from '../../api/yuyue'
import { formatTime } from '../../utils/time'

interface CommentRow {
  id: number
  worker_id: number
  worker_name?: string
  worker_tel?: string
  bname?: string
  nickname: string
  headimg: string
  score: number
  content: string
  createtime: number
  content_pic?: string
  reply_content?: string
  ordernum?: string
  mid: number
}

const loading = ref(false)
const tableData = ref<CommentRow[]>([])
const total = ref(0)
const selected = ref<CommentRow[]>([])
const workerOptions = ref<{ id: number; name: string }[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  worker_id: '' as number | '',
  content: '',
  timeRange: null as [string, string] | null,
})

async function fetchWorkers() {
  try {
    const data = await getYuyueWorkerList({ page: 1, pagesize: 100 })
    const list = ((data as unknown as { list?: Record<string, unknown>[] }).list || []) as Record<string, unknown>[]
    workerOptions.value = list.map((w) => ({ id: Number(w.id), name: String(w.realname || w.name || '') }))
  } catch { /* 拦截器 */ }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueWorkerCommentList({
      page: query.page,
      pagesize: query.pagesize,
      worker_id: query.worker_id === '' ? undefined : query.worker_id,
      content: query.content || undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
    })
    tableData.value = (data.list || []) as CommentRow[]
    total.value = data.total || 0
  } catch { /* 拦截器 */ } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}
function handleSelectionChange(rows: CommentRow[]) {
  selected.value = rows
}

// 详情
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<Record<string, unknown>>({})
async function openDetail(row: CommentRow) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = { ...row }
  try {
    detail.value = await getYuyueWorkerCommentDetail(row.id)
  } catch { /* 拦截器 */ } finally {
    detailLoading.value = false
  }
}

// 删除
function doDelete(ids: number[]) {
  return deleteYuyueWorkerComment(ids)
}
function handleBatchDelete() {
  if (!selected.value.length) {
    ElMessage.warning('请先勾选评价')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${selected.value.length} 条评价吗？`, '删除确认', { type: 'warning' })
    .then(async () => {
      await doDelete(selected.value.map((r) => r.id))
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}
function handleDelete(row: CommentRow) {
  ElMessageBox.confirm('确定删除这条评价吗？', '删除确认', { type: 'warning' })
    .then(async () => {
      await doDelete([row.id])
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

onMounted(() => {
  fetchWorkers()
  fetchList()
})
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-inner">
        <div class="toolbar-left">
          <el-button @click="handleBatchDelete">删除</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tf-label">人员</span>
          <el-select v-model="query.worker_id" placeholder="全部" clearable style="width: 130px">
            <el-option v-for="w in workerOptions" :key="w.id" :label="w.name" :value="w.id" />
          </el-select>
          <span class="tf-label">评价内容</span>
          <el-input v-model="query.content" placeholder="请输入" style="width: 140px" @keyup.enter="handleSearch" />
          <span class="tf-label">评价时间</span>
          <el-date-picker v-model="query.timeRange" type="daterange" range-separator="至" start-placeholder="开始" end-placeholder="结束" value-format="YYYY-MM-DD" style="width: 240px" />
          <button class="search-btn" title="搜索" @click="handleSearch">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M11.4 10.2a6 6 0 1 0-1.2 1.2l3.4 3.4 1.2-1.2-3.4-3.4zM7 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
          </button>
        </div>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="人员" min-width="130">
          <template #default="{ row }">
            <span v-if="row.worker_name">{{ row.worker_name }}</span>
            <span v-if="row.worker_tel" class="sub">{{ row.worker_tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="商家" min-width="110">
          <template #default="{ row }">{{ row.bname || '鼎极商城' }}</template>
        </el-table-column>
        <el-table-column label="评价人信息" min-width="150">
          <template #default="{ row }">
            <div class="member-cell">
              <el-image v-if="row.headimg" :src="row.headimg" fit="cover" class="avatar" />
              <span>{{ row.nickname }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评分" width="90">
          <template #default="{ row }">
            <el-rate :model-value="Number(row.score)" disabled size="small" />
          </template>
        </el-table-column>
        <el-table-column prop="content" label="评价内容" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">{{ row.content || '-' }}</template>
        </el-table-column>
        <el-table-column label="评价时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" min-width="120">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openDetail(row)">详情</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager" background layout="total, sizes, prev, pager, next, jumper"
        :total="total" v-model:current-page="query.page" v-model:page-size="query.pagesize"
        :page-sizes="[10, 20, 50]" @current-change="fetchList" @size-change="handleSearch"
      />
    </el-card>

    <el-dialog v-model="detailVisible" title="评价详情" width="520px">
      <div v-loading="detailLoading" class="detail-body">
        <div class="drow"><span class="dlab">人员</span><span>{{ detail.worker_name || '-' }}<span v-if="detail.worker_tel" class="sub">（{{ detail.worker_tel }}）</span></span></div>
        <div class="drow"><span class="dlab">商家</span><span>{{ detail.bname || '鼎极商城' }}</span></div>
        <div class="drow"><span class="dlab">评价人</span><span>{{ detail.nickname || '-' }}</span></div>
        <div class="drow"><span class="dlab">订单号</span><span>{{ detail.ordernum || '-' }}</span></div>
        <div class="drow"><span class="dlab">评分</span><el-rate :model-value="Number(detail.score || 0)" disabled size="small" /></div>
        <div class="drow"><span class="dlab">评价内容</span><span>{{ detail.content || '-' }}</span></div>
        <div class="drow"><span class="dlab">回复</span><span>{{ detail.reply_content || '-' }}</span></div>
        <div class="drow"><span class="dlab">评价时间</span><span>{{ detail.createtime ? formatTime(Number(detail.createtime)) : '-' }}</span></div>
      </div>
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
.toolbar-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap; }
.toolbar-left { display: flex; gap: 8px; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.tf-label { font-size: 13px; color: #606266; white-space: nowrap; }
.search-btn { width: 30px; height: 30px; border: none; background: #67c23a; color: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 3px; }
.el-card { border-radius: 0; }
.el-table :deep(th) { background: #e6e6e6; text-align: left; }
.el-table :deep(td .cell) { text-align: left; }
.pager { margin-top: 12px; justify-content: flex-end; }
.avatar { width: 32px; height: 32px; border-radius: 50%; }
.member-cell { display: flex; align-items: center; gap: 6px; }
.row-ops { display: flex; gap: 10px; flex-wrap: nowrap; }
.sub { color: #909399; font-size: 12px; }
.detail-body { min-height: 80px; }
.drow { display: flex; gap: 8px; padding: 6px 0; font-size: 13px; }
.dlab { color: #909399; width: 70px; flex-shrink: 0; }
</style>
