<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteLuckyCollageComment,
  getLuckyCollageCommentDetail,
  getLuckyCollageCommentList,
  replyLuckyCollageComment,
  setLuckyCollageCommentSt,
} from '../../api/luckyCollage'
import type { LuckyCollageCommentItem } from '../../api/luckyCollage'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<LuckyCollageCommentItem[]>([])
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
    const params: Record<string, any> = {
      page: query.page,
      pagesize: query.pagesize,
      content: query.content || undefined,
    }
    if (query.ctime && query.ctime.length === 2) {
      params.ctime_start = query.ctime[0]
      params.ctime_end = query.ctime[1]
    }
    const data = await getLuckyCollageCommentList(params)
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
function handleSelectionChange(rows: LuckyCollageCommentItem[]) {
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

async function batchSetst(status: number) {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先选择评价')
    return
  }
  try {
    await setLuckyCollageCommentSt({ ids: selectedIds.value, status })
    ElMessage.success(status === 1 ? '已审核' : '未审核')
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  }
}

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
        for (const id of selectedIds.value) {
          await deleteLuckyCollageComment(id)
        }
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function handleDelete(row: LuckyCollageCommentItem) {
  ElMessageBox.confirm('确定要删除该评价吗？', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLuckyCollageComment(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function picList(pics: string) {
  return pics.split(',').map((s) => s.trim()).filter(Boolean)
}

// ============ 回复弹窗 ============
const replyVisible = ref(false)
const replyRow = ref<LuckyCollageCommentItem | null>(null)
const replyContent = ref('')
const replySubmitting = ref(false)

function openReply(row: LuckyCollageCommentItem) {
  replyRow.value = row
  replyContent.value = row.reply_content || ''
  replyVisible.value = true
}

async function submitReply() {
  if (!replyRow.value) return
  replySubmitting.value = true
  try {
    await replyLuckyCollageComment(replyRow.value.id, replyContent.value.trim())
    ElMessage.success('回复成功')
    replyVisible.value = false
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  } finally {
    replySubmitting.value = false
  }
}

// ============ 详情 ============
const detailVisible = ref(false)
const detailRow = ref<LuckyCollageCommentItem | null>(null)
async function openDetail(row: LuckyCollageCommentItem) {
  try {
    detailRow.value = await getLuckyCollageCommentDetail(row.id)
  } catch {
    detailRow.value = row
  }
  detailVisible.value = true
}

onMounted(fetchList)
</script>

<template>
  <div class="lucky-comment">
    <div class="page-head"><span class="head-title">商品评价</span></div>
    <div class="toolbar">
      <div class="toolbar-left">
        <el-button :disabled="!selectedIds.length" @click="batchDelete">删除</el-button>
        <el-button :disabled="!selectedIds.length" @click="batchSetst(1)">已审</el-button>
        <el-button :disabled="!selectedIds.length" @click="batchSetst(0)">未审</el-button>
      </div>
      <div class="toolbar-search">
        <el-input v-model="query.content" placeholder="评价内容" clearable style="width: 160px" @keyup.enter="handleSearch" />
        <el-date-picker
          v-model="query.ctime"
          type="daterange"
          range-separator="~"
          start-placeholder="评价时间"
          end-placeholder="~"
          value-format="YYYY-MM-DD"
          style="width: 220px"
        />
        <button class="search-btn" @click="handleSearch" title="搜索">
          <svg viewBox="0 0 1024 1024" width="16" height="16"><path fill="#fff" d="M795.904 750.72l124.992 124.928a32 32 0 1 1-45.269 45.269l-124.928-124.992a416 416 0 1 1 45.269-45.269zM480 832a352 352 0 1 0 0-704 352 352 0 0 0 0 704z"/></svg>
        </button>
      </div>
    </div>

    <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
      <el-table-column type="selection" width="40" />
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="商品信息" min-width="170">
        <template #default="{ row }">
          <div class="comment-product">
            <el-image v-if="row.propic" :src="row.propic" fit="cover" class="prod-thumb" />
            <div>
              <div class="product-name">{{ row.proname }}</div>
              <div v-if="row.ggname" class="cell-sub">{{ row.ggname }}</div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="评价人信息" width="140">
        <template #default="{ row }">
          <div class="member-cell">
            <el-avatar :size="26" :src="row.headimg || undefined">{{ (row.nickname || '?').slice(0, 1) }}</el-avatar>
            <span>{{ row.nickname || '会员' }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="评分" width="80" sortable>
        <template #default="{ row }"><span>{{ row.score }}星</span></template>
      </el-table-column>
      <el-table-column label="评价内容" min-width="200">
        <template #default="{ row }">
          <div>{{ row.content || '（未填写内容）' }}</div>
          <div v-if="picList(row.content_pic).length" class="pic-row">
            <el-image v-for="(p, i) in picList(row.content_pic)" :key="i" :src="p" fit="cover" :preview-src-list="picList(row.content_pic)" class="pic-thumb" />
          </div>
          <div v-if="row.reply_content" class="reply-tip">商家回复：{{ row.reply_content }}</div>
        </template>
      </el-table-column>
      <el-table-column label="评价时间" width="160" sortable>
        <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'primary' : 'warning'" size="small">
            {{ row.status === 1 ? '已审核' : '未审核' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="130">
        <template #default="{ row }">
          <div class="row-ops">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button link type="primary" @click="openReply(row)">回复</el-button>
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

    <!-- 回复评价弹窗 -->
    <el-dialog v-model="replyVisible" title="回复评价" width="440px">
      <el-form label-width="80px">
        <el-form-item label="回复内容">
          <el-input v-model="replyContent" type="textarea" :rows="4" placeholder="请输入回复内容" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="replyVisible = false">取消</el-button>
        <el-button type="primary" :loading="replySubmitting" @click="submitReply">确定</el-button>
      </template>
    </el-dialog>

    <!-- 评价详情 -->
    <el-dialog v-model="detailVisible" title="评价详情" width="560px">
      <el-descriptions v-if="detailRow" :column="1" border size="small">
        <el-descriptions-item label="商品">{{ detailRow.proname }}（{{ detailRow.ggname || '默认规格' }}）</el-descriptions-item>
        <el-descriptions-item label="会员">{{ detailRow.nickname }}</el-descriptions-item>
        <el-descriptions-item label="评分">{{ detailRow.score }}星</el-descriptions-item>
        <el-descriptions-item label="内容">{{ detailRow.content || '（无内容）' }}</el-descriptions-item>
        <el-descriptions-item v-if="picList(detailRow.content_pic).length" label="晒图">
          <el-image v-for="(p,i) in picList(detailRow.content_pic)" :key="i" :src="p" fit="cover" class="pic-thumb" :preview-src-list="picList(detailRow.content_pic)" />
        </el-descriptions-item>
        <el-descriptions-item label="商家回复">{{ detailRow.reply_content || '（未回复）' }}</el-descriptions-item>
        <el-descriptions-item label="时间">{{ formatTime(detailRow.createtime) }}</el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.lucky-comment { margin: 3px; background: #fff; padding: 12px 16px; }
.page-head { margin-bottom: 8px; }
.head-title { font-size: 15px; font-weight: 600; }
.toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.toolbar-left { display: flex; gap: 8px; }
.toolbar-search { display: flex; align-items: center; gap: 8px; }
.search-btn { width: 32px; height: 32px; border: none; border-radius: 4px; background: #67c23a; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
.pagination { margin-top: 8px; justify-content: flex-end; }
.comment-product { display: flex; align-items: center; gap: 8px; }
.prod-thumb { width: 36px; height: 36px; border-radius: 4px; }
.product-name { font-size: 13px; }
.cell-sub { font-size: 12px; color: #909399; margin-top: 2px; }
.member-cell { display: flex; align-items: center; gap: 6px; }
.pic-row { display: flex; gap: 4px; margin-top: 4px; flex-wrap: wrap; }
.pic-thumb { width: 36px; height: 36px; border-radius: 4px; }
.reply-tip { margin-top: 4px; color: #67c23a; font-size: 12px; }
.row-ops { display: flex; flex-direction: row; gap: 6px; }
:deep(.el-table__header th) { background: #e6e6e6; }
:deep(.el-table .cell) { text-align: left; }
</style>
