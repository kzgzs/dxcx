<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteYuyueComment,
  getYuyueCommentDetail,
  getYuyueCommentList,
  replyYuyueComment,
} from '../../api/yuyue'
import type { YuyueCommentItem, YuyueCommentListResult } from '../../api/types'

// 商品评价（对齐点大 扩展-预约服务-商品评价：列表 + 详情弹窗 + 回复弹窗；回复落库并转已审）
const loading = ref(false)
const tableData = ref<YuyueCommentItem[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  content: '',
  status: '' as number | '',
  ctime: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const params: Record<string, unknown> = {
      page: query.page,
      pagesize: query.pagesize,
      content: query.content || undefined,
      status: query.status === '' ? undefined : query.status,
    }
    if (query.ctime && query.ctime.length === 2) {
      params.start_date = query.ctime[0]
      params.end_date = query.ctime[1]
    }
    const data: YuyueCommentListResult = await getYuyueCommentList(params as never)
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 拦截器统一提示
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function filterAudited() {
  query.status = 1
  handleSearch()
}

function filterUnaudited() {
  query.status = 0
  handleSearch()
}

function handlePageChange(p: number) {
  query.page = p
  fetchList()
}

// ============ 详情弹窗 ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<Record<string, any>>({})

async function openDetail(row: YuyueCommentItem) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = {}
  try {
    detail.value = await getYuyueCommentDetail(row.id)
  } catch {
    // 拦截器统一提示
  } finally {
    detailLoading.value = false
  }
}

// ============ 回复弹窗 ============
const replyVisible = ref(false)
const replySubmitting = ref(false)
const replyTarget = ref<YuyueCommentItem | null>(null)
const replyContent = ref('')

function openReply(row: YuyueCommentItem) {
  replyTarget.value = row
  replyContent.value = row.reply_content || ''
  replyVisible.value = true
}

async function handleReplySubmit() {
  if (!replyTarget.value) return
  if (!replyContent.value.trim()) {
    ElMessage.warning('请输入回复内容')
    return
  }
  replySubmitting.value = true
  try {
    await replyYuyueComment(replyTarget.value.id, replyContent.value.trim())
    ElMessage.success('回复成功')
    replyVisible.value = false
    fetchList()
  } catch {
    // 拦截器统一提示
  } finally {
    replySubmitting.value = false
  }
}

// ============ 删除 ============
function handleDeleteRow(row: YuyueCommentItem) {
  ElMessageBox.confirm('确认删除这条评价？', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueComment([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选要删除的评价')
    return
  }
  ElMessageBox.confirm(`确认删除选中的 ${selectedIds.value.length} 条评价？`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueComment(selectedIds.value)
        ElMessage.success('删除成功')
        selectedIds.value = []
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function fmtTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

onMounted(fetchList)
</script>

<template>
  <div class="yuyue-comment">
    <!-- 工具栏：删除/已审/未审 与 搜索 同行 -->
    <div class="toolbar">
      <div class="btn-row">
        <el-button type="danger" @click="handleBatchDelete">删除</el-button>
        <el-button @click="filterAudited">已审</el-button>
        <el-button @click="filterUnaudited">未审</el-button>
      </div>
      <div class="search-row">
        <span class="lbl">评价内容</span>
        <el-input v-model="query.content" clearable class="w160" @keyup.enter="handleSearch" />
        <span class="lbl">评价时间</span>
        <el-date-picker
          v-model="query.ctime"
          type="daterange"
          range-separator="~"
          start-placeholder="开始"
          end-placeholder="结束"
          value-format="YYYY-MM-DD"
          class="w240"
        />
        <el-button type="primary" @click="handleSearch">搜索</el-button>
      </div>
    </div>

    <!-- 列表 -->
    <el-table v-loading="loading" :data="tableData" class="data-table" @selection-change="(rows: YuyueCommentItem[]) => (selectedIds = rows.map(r => r.id))">
      <el-table-column type="selection" width="40" />
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="商品信息" min-width="180">
        <template #default="{ row }">
          <div class="goods-cell">
            <el-image v-if="row.propic" :src="row.propic" fit="cover" class="goods-pic" />
            <div class="goods-info">
              <div class="goods-name">{{ row.proname }}</div>
              <div v-if="row.ggname" class="goods-spec">{{ row.ggname }}</div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="评价人信息" min-width="140">
        <template #default="{ row }">
          <div class="member-cell">
            <el-avatar :src="row.headimg" :size="28">{{ (row.nickname || '?').slice(0, 1) }}</el-avatar>
            <span>{{ row.nickname || '-' }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="评分" width="80">
        <template #default="{ row }">{{ row.score }}星</template>
      </el-table-column>
      <el-table-column prop="content" label="评价内容" min-width="160" show-overflow-tooltip />
      <el-table-column label="评价时间" width="150">
        <template #default="{ row }">{{ fmtTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <span :class="['status-badge', row.status === 1 ? 'audited' : 'unaudited']">
            {{ row.status === 1 ? '已审核' : '未审核' }}
          </span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="170">
        <template #default="{ row }">
          <el-button link type="primary" @click="openDetail(row)">详情</el-button>
          <el-button link type="primary" @click="openReply(row)">回复</el-button>
          <el-button link type="danger" @click="handleDeleteRow(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      class="pager"
      background
      layout="total, sizes, prev, pager, next, jumper"
      :total="total"
      :current-page="query.page"
      :page-size="query.pagesize"
      :page-sizes="[10, 20, 50]"
      @current-change="handlePageChange"
      @size-change="(s: number) => { query.pagesize = s; handleSearch() }"
    />

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="评价详情" width="440px" append-to-body>
      <div v-loading="detailLoading" class="detail-box">
        <div class="d-block">
          <div class="d-label">评价人</div>
          <div class="member-cell">
            <el-avatar :src="detail.headimg" :size="36">{{ (detail.nickname || '?').slice(0, 1) }}</el-avatar>
            <span>{{ detail.nickname || '-' }}</span>
          </div>
        </div>
        <div class="d-block">
          <div class="d-label">评价时间</div>
          <div>{{ fmtTime(detail.createtime) }}</div>
        </div>
        <div class="d-block">
          <div class="d-label">内容</div>
          <div>{{ detail.content }}</div>
        </div>
      </div>
    </el-dialog>

    <!-- 回复弹窗 -->
    <el-dialog v-model="replyVisible" title="回复评价" width="440px" append-to-body>
      <div class="reply-box">
        <span class="reply-label">回复内容</span>
        <el-input v-model="replyContent" type="textarea" :rows="4" placeholder="请输入回复内容" />
      </div>
      <template #footer>
        <el-button type="primary" :loading="replySubmitting" @click="handleReplySubmit">确定</el-button>
        <el-button @click="replyVisible = false">取消</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.yuyue-comment {
  margin: 3px;
}

.toolbar {
  background: #fff;
  padding: 8px 10px;
  margin-bottom: 3px;
  display: flex;
  flex-direction: row;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.btn-row,
.search-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.lbl {
  font-size: 13px;
  color: #666;
  white-space: nowrap;
}

.w160 { width: 160px; }
.w240 { width: 240px; }

.data-table :deep(th.el-table__cell) {
  background: #e6e6e6;
  text-align: left;
}

.data-table :deep(.cell) {
  text-align: left;
}

.goods-cell {
  display: flex;
  gap: 8px;
}

.goods-pic {
  width: 40px;
  height: 40px;
  border-radius: 4px;
  flex-shrink: 0;
}

.goods-info {
  font-size: 12px;
  line-height: 1.5;
}

.goods-name {
  color: #333;
}

.goods-spec {
  color: #f60;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.status-badge {
  padding: 2px 8px;
  border-radius: 3px;
  font-size: 12px;
  color: #fff;
}

.status-badge.audited {
  background: #409eff;
}

.status-badge.unaudited {
  background: #f56c6c;
}

.pager {
  margin-top: 10px;
  justify-content: flex-end;
}

.detail-box {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.d-block {
  font-size: 13px;
}

.d-label {
  color: #999;
  margin-bottom: 6px;
}

.reply-box {
  display: flex;
  gap: 8px;
}

.reply-label {
  font-size: 13px;
  line-height: 32px;
  flex-shrink: 0;
}
</style>
