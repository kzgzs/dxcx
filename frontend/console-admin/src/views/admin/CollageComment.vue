<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteCollageComment,
  getCollageCommentList,
  replyCollageComment,
  setCollageCommentSt,
  type CollageCommentItem,
} from '../../api/collage'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<CollageCommentItem[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  content: '',
  ctime: [] as string[],
})

async function fetchList() {
  loading.value = true
  try {
    const params: Record<string, unknown> = {
      page: query.page,
      pagesize: query.pagesize,
      content: query.content || undefined,
    }
    if (query.ctime && query.ctime.length === 2) {
      params.start_time = query.ctime[0]
      params.end_time = query.ctime[1]
    }
    const data = await getCollageCommentList(params)
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.content = ''
  query.ctime = []
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: CollageCommentItem[]) {
  selectedIds.value = rows.map((r) => r.id)
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

// 批量审核
async function batchSetst(status: number) {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先选择评价')
    return
  }
  try {
    await setCollageCommentSt({ ids: selectedIds.value, status })
    ElMessage.success(status === 1 ? '已通过' : '已隐藏')
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  }
}

// 单条审核切换
async function toggleSt(row: CollageCommentItem) {
  const next = row.status === 1 ? 0 : 1
  try {
    await setCollageCommentSt({ id: row.id, status: next })
    ElMessage.success(next === 1 ? '已通过' : '已隐藏')
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  }
}

// 批量删除
function batchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先选择评价')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${selectedIds.value.length} 条评价吗？`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCollageComment({ ids: selectedIds.value })
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

// 单条删除
function handleDelete(row: CollageCommentItem) {
  ElMessageBox.confirm(`确定要删除该评价吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCollageComment({ id: row.id })
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

// 回复
const replyVisible = ref(false)
const replyRow = ref<CollageCommentItem | null>(null)
const replyForm = reactive({ content: '' })
function openReply(row: CollageCommentItem) {
  replyRow.value = row
  replyForm.content = row.reply_content || ''
  replyVisible.value = true
}
async function submitReply() {
  if (!replyRow.value) return
  if (!replyForm.content.trim()) {
    ElMessage.warning('请输入回复内容')
    return
  }
  try {
    await replyCollageComment(replyRow.value.id, replyForm.content)
    ElMessage.success('回复成功')
    replyVisible.value = false
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  }
}

// 详情
const detailVisible = ref(false)
const detailRow = ref<CollageCommentItem | null>(null)
function openDetail(row: CollageCommentItem) {
  detailRow.value = row
  detailVisible.value = true
}

onMounted(fetchList)
</script>

<template>
  <div class="collage-comment">
    <el-card shadow="never">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button type="success" :disabled="!selectedIds.length" @click="batchDelete">删除</el-button>
          <el-button type="success" :disabled="!selectedIds.length" @click="batchSetst(1)">已审</el-button>
          <el-button type="success" :disabled="!selectedIds.length" @click="batchSetst(0)">未审</el-button>
        </div>
        <div class="toolbar-search">
          <el-input
            v-model="query.content"
            placeholder="评价内容"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
          <el-date-picker
            v-model="query.ctime"
            type="daterange"
            size="small"
            range-separator="~"
            start-placeholder="评价时间"
            end-placeholder="~"
            value-format="YYYY-MM-DD"
            style="width: 240px"
          />
          <button class="search-btn" @click="handleSearch" title="搜索">
            <svg viewBox="0 0 1024 1024" width="16" height="16"><path fill="#fff" d="M795.904 750.72l124.992 124.928a32 32 0 1 1-45.269 45.269l-124.928-124.992a416 416 0 1 1 45.269-45.269zM480 832a352 352 0 1 0 0-704 352 352 0 0 0 0 704z"/></svg>
          </button>
          <el-button link @click="handleReset">重置</el-button>
        </div>
      </div>

      <el-table
        v-loading="loading"
        :data="tableData"
        border
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="商品信息" min-width="180">
          <template #default="{ row }">
            <div class="goods-cell">
              <el-image v-if="row.propic" :src="row.propic" fit="cover" class="goods-thumb" />
              <div>
                <div class="goods-name">{{ row.proname }}</div>
                <div v-if="row.ggname" class="cell-sub">{{ row.ggname }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评价人" width="120">
          <template #default="{ row }">
            <div class="buyer-cell">
              <el-avatar :size="24" :src="row.headimg">{{ (row.nickname || '?').slice(0, 1) }}</el-avatar>
              <span>{{ row.nickname || '-' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评分" width="90">
          <template #default="{ row }">
            <el-rate :model-value="row.score" disabled :colors="['#F56C6C']" />
          </template>
        </el-table-column>
        <el-table-column label="评价内容" min-width="200">
          <template #default="{ row }">
            <div>{{ row.content }}</div>
            <div v-if="row.reply_content" class="reply-tip">商家回复：{{ row.reply_content }}</div>
          </template>
        </el-table-column>
        <el-table-column label="评价时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-button link type="primary" @click="toggleSt(row)">
              {{ row.status === 1 ? '已审核' : '未审核' }}
            </el-button>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openDetail(row)">详情</el-button>
              <el-button link type="warning" @click="openReply(row)">回复</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
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

    <!-- 回复弹窗 -->
    <el-dialog v-model="replyVisible" title="回复评价" width="460px">
      <el-form label-width="80px">
        <el-form-item label="评价人"><span>{{ replyRow?.nickname }}</span></el-form-item>
        <el-form-item label="评价内容">
          <div class="view-content">{{ replyRow?.content }}</div>
        </el-form-item>
        <el-form-item label="回复内容">
          <el-input v-model="replyForm.content" type="textarea" :rows="3" placeholder="请输入回复内容" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="replyVisible = false">取消</el-button>
        <el-button type="primary" @click="submitReply">提交回复</el-button>
      </template>
    </el-dialog>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="评价详情" width="520px">
      <div v-if="detailRow" class="detail-view">
        <div class="detail-row"><span class="label">商品：</span>{{ detailRow.proname }} {{ detailRow.ggname }}</div>
        <div class="detail-row"><span class="label">评价人：</span>{{ detailRow.nickname }}</div>
        <div class="detail-row"><span class="label">评分：</span><el-rate :model-value="detailRow.score" disabled /></div>
        <div class="detail-row"><span class="label">评价内容：</span>{{ detailRow.content }}</div>
        <div v-if="detailRow.content_pic" class="detail-row">
          <span class="label">评价图片：</span>
          <el-image v-for="(p, i) in detailRow.content_pic.split(',').filter(Boolean)" :key="i" :src="p" fit="cover" class="detail-img" />
        </div>
        <div v-if="detailRow.reply_content" class="detail-row"><span class="label">商家回复：</span>{{ detailRow.reply_content }}</div>
        <div class="detail-row"><span class="label">评价时间：</span>{{ formatTime(detailRow.createtime) }}</div>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.collage-comment {
  margin: 3px;
}
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.toolbar-left {
  display: flex;
  gap: 8px;
}
.toolbar-search {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.search-btn {
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 4px;
  background: #67c23a;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
}
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
.goods-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.goods-thumb {
  width: 40px;
  height: 40px;
  border-radius: 4px;
}
.goods-name {
  font-size: 13px;
  font-weight: 500;
}
.cell-sub {
  font-size: 12px;
  color: #909399;
  margin-top: 2px;
}
.buyer-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}
.reply-tip {
  font-size: 12px;
  color: #67c23a;
  margin-top: 4px;
}
.row-ops {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
}
.detail-view {
  line-height: 1.8;
}
.detail-row {
  margin-bottom: 8px;
}
.detail-row .label {
  color: #909399;
}
.detail-img {
  width: 60px;
  height: 60px;
  border-radius: 4px;
  margin-right: 6px;
}
.view-content {
  background: #f5f7fa;
  padding: 8px;
  border-radius: 4px;
}
:deep(.el-table__header th) {
  background: #e6e6e6;
}
:deep(.el-table .cell) {
  text-align: left;
}
</style>
