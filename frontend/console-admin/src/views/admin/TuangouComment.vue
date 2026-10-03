<template>
  <div class="tg-comment">
    <!-- 工具栏一行：左 删除/已审/未审；右 评价内容+评价时间+搜索 -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="seg">
          <div class="seg-item" :class="{ active: tab === 'deleted' }" @click="switchTab('deleted')">删除</div>
          <div class="seg-item" :class="{ active: tab === 'approved' }" @click="switchTab('approved')">已审</div>
          <div class="seg-item" :class="{ active: tab === 'pending' }" @click="switchTab('pending')">未审</div>
        </div>
      </div>
      <div class="toolbar-right">
        <el-input v-model="query.content" placeholder="评价内容" size="small" clearable @keyup.enter="reload" />
        <el-date-picker
          v-model="query.ctimeRange"
          type="daterange"
          size="small"
          range-separator="-"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          value-format="YYYY-MM-DD"
          style="width: 240px"
        />
        <el-button type="primary" size="small" circle :icon="Search" @click="reload" />
      </div>
    </div>

    <el-table v-loading="loading" :data="list" border size="small" class="c-table" @sort-change="onSortChange" @selection-change="onSelection">
      <el-table-column type="selection" width="40" />
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="商品信息" min-width="180">
        <template #default="{ row }">
          <div class="goods-cell">
            <el-image :src="row.propic" fit="cover" class="goods-img" />
            <span>{{ row.proname }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="评价人信息" min-width="140">
        <template #default="{ row }">
          <div class="buyer-cell">
            <el-avatar :src="row.headimg" :size="24">{{ (row.nickname || '?').slice(0, 1) }}</el-avatar>
            <span>{{ row.nickname || '-' }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="评分" width="120">
        <template #default="{ row }"><el-rate :model-value="row.score" disabled /></template>
      </el-table-column>
      <el-table-column label="评价内容" min-width="200">
        <template #default="{ row }">
          <div>{{ row.content }}</div>
          <div v-if="row.reply_content" class="muted">商家回复：{{ row.reply_content }}</div>
        </template>
      </el-table-column>
      <el-table-column label="评价时间" width="160" sortable="custom">
        <template #default="{ row }">{{ fmtTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag size="small" :type="row.status === 1 ? 'success' : row.status === 2 ? 'info' : 'warning'">{{ row.status_text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="200">
        <template #default="{ row }">
          <div class="ops">
            <el-button link type="primary" size="small" @click="openDetail(row)">查看详情</el-button>
            <el-button link type="primary" size="small" @click="handleReply(row)">回复</el-button>
            <el-button link type="success" size="small" v-if="row.status === 0" @click="setStatus(row, 1)">审核</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </div>
        </template>
      </el-table-column>
      <template #empty><el-empty description="无数据" /></template>
    </el-table>

    <el-pagination
      class="pager"
      :current-page="page"
      :page-size="pagesize"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="(p: number) => { page = p; load() }"
    />

    <!-- 查看详情 -->
    <el-dialog v-model="detailVisible" title="评价详情" width="560px" append-to-body>
      <el-descriptions v-if="detail" :column="1" border size="small">
        <el-descriptions-item label="商品">{{ detail.proname }}</el-descriptions-item>
        <el-descriptions-item label="评价人">{{ detail.nickname }}</el-descriptions-item>
        <el-descriptions-item label="评分">{{ detail.score }}星</el-descriptions-item>
        <el-descriptions-item label="评价内容">{{ detail.content }}</el-descriptions-item>
        <el-descriptions-item label="商家回复">{{ detail.reply_content || '-' }}</el-descriptions-item>
        <el-descriptions-item label="评价时间">{{ fmtTime(detail.createtime) }}</el-descriptions-item>
      </el-descriptions>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import {
  getTuangouCommentList,
  deleteTuangouComment,
  replyTuangouComment,
  setTuangouCommentStatus,
} from '../../api/tuangou'

interface Row {
  id: number
  proid: number
  proname: string
  propic: string
  nickname: string
  headimg: string
  score: number
  content: string
  reply_content: string
  status: number
  status_text: string
  createtime: number
}

const loading = ref(false)
const list = ref<Row[]>([])
const page = ref(1)
const pagesize = ref(10)
const total = ref(0)
const tab = ref<'pending' | 'approved' | 'deleted'>('pending')
const selected = ref<Row[]>([])
const sortorder = ref('desc')

const query = ref({ content: '', ctimeRange: [] as string[] })

function fmtTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function statusParam() {
  if (tab.value === 'pending') return '0'
  if (tab.value === 'approved') return '1'
  return '99' // 删除(回收站)：硬删除无数据，固定空集
}

async function load() {
  loading.value = true
  try {
    const [st, et] = query.value.ctimeRange || []
    const res = await getTuangouCommentList({
      page: page.value,
      pagesize: pagesize.value,
      status: statusParam(),
      content: query.value.content,
      starttime: st || '',
      endtime: et || '',
      sortorder: sortorder.value,
    } as any)
    list.value = res.list || []
    total.value = res.total || 0
  } finally { loading.value = false }
}
function reload() { page.value = 1; load() }
function switchTab(t: 'pending' | 'approved' | 'deleted') { tab.value = t; reload() }
function onSortChange({ order }: { order: string | null }) {
  sortorder.value = order === 'ascending' ? 'asc' : 'desc'
  load()
}
function onSelection(rows: Row[]) { selected.value = rows }

const detailVisible = ref(false)
const detail = ref<Row | null>(null)
function openDetail(row: Row) { detail.value = row; detailVisible.value = true }

async function setStatus(row: Row, status: number) {
  await setTuangouCommentStatus(row.id, status)
  ElMessage.success('已通过审核')
  load()
}
function handleReply(row: Row) {
  ElMessageBox.prompt('请输入回复内容', '商家回复', { inputType: 'textarea', inputValue: row.reply_content })
    .then(async ({ value }) => {
      if (!(value || '').trim()) { ElMessage.warning('回复内容不能为空'); return }
      await replyTuangouComment(row.id, value.trim())
      ElMessage.success('回复成功')
      load()
    }).catch(() => {})
}
async function handleDelete(row: Row) {
  await ElMessageBox.confirm('确定删除该评价？', '提示', { type: 'warning' })
  await deleteTuangouComment(row.id)
  ElMessage.success('删除成功')
  load()
}

onMounted(load)
</script>

<style scoped>
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px; gap: 8px; }
.toolbar-left { flex-shrink: 0; }
.toolbar-right { display: flex; align-items: center; gap: 6px; }
.toolbar-right .el-input { width: 160px; }
.seg { display: inline-flex; border: 1px solid #dcdfe6; border-radius: 4px; overflow: hidden; }
.seg-item { padding: 6px 18px; font-size: 13px; color: #606266; cursor: pointer; }
.seg-item.active { background: #409eff; color: #fff; }
.c-table { width: 100%; }
.c-table :deep(th) { background: #e6e6e6; }
.goods-cell { display: flex; align-items: center; gap: 6px; font-size: 12px; }
.goods-img { width: 36px; height: 36px; border-radius: 4px; }
.buyer-cell { display: flex; align-items: center; gap: 4px; font-size: 12px; }
.muted { color: #999; font-size: 12px; }
.ops { display: flex; gap: 2px; white-space: nowrap; }
.pager { margin-top: 10px; justify-content: flex-end; }
</style>
