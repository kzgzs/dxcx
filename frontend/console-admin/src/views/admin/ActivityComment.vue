<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getCollageCommentList,
  replyCollageComment,
  setCollageCommentSt,
  deleteCollageComment,
} from '../../api/collage'
import {
  getSeckillCommentList,
  replySeckillComment,
  setSeckillCommentStatus,
  deleteSeckillComment,
} from '../../api/seckill'
import { formatTime } from '../../utils/time'

type Props = { type: 'collage' | 'seckill' }
const props = defineProps<Props>()

const isSeckill = props.type === 'seckill'
// 拼团/秒杀均采用点大参考图版式（工具栏+搜索+表格+回复弹窗）
const useNewLayout = true
const title = isSeckill ? '用户评价' : '商品评价'

/* ==================== 列表 ==================== */
const loading = ref(false)
const tableData = ref<any[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  content: '',
  ctime: [] as string[],
  status: -1,
})

async function fetchList() {
  loading.value = true
  try {
    if (isSeckill) {
      const data = await getSeckillCommentList({
        page: query.page,
        pagesize: query.pagesize,
        content: query.content || undefined,
        status: query.status >= 0 ? query.status : undefined,
        start_date: query.ctime?.[0] || undefined,
        end_date: query.ctime?.[1] || undefined,
      })
      tableData.value = (data.list || []).map((r: any) => ({ ...r, createtime_text: formatTime(r.createtime) }))
      total.value = data.total || 0
    } else {
      const data = await getCollageCommentList({
        page: query.page,
        pagesize: query.pagesize,
        content: query.content || undefined,
        st: query.status >= 0 ? query.status : undefined,
        start_time: query.ctime?.[0] ? new Date(query.ctime[0]).getTime() / 1000 : undefined,
        end_time: query.ctime?.[1] ? new Date(query.ctime[1]).getTime() / 1000 : undefined,
      })
      tableData.value = (data.list || []).map((r: any) => ({ ...r, createtime_text: formatTime(r.createtime) }))
      total.value = data.total || 0
    }
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() { query.page = 1; fetchList() }

// ============ 选择 ============
const selectedIds = ref<number[]>([])
function handleSelectionChange(rows: any[]) { selectedIds.value = rows.map((r) => r.id) }

// ============ 审核状态 ============
async function batchSetst(st: number, row?: any) {
  let ids: number[] = []
  if (row) ids = [row.id]
  else {
    if (!selectedIds.value.length) { ElMessage.warning('请选择数据'); return }
    ids = selectedIds.value
  }
  try {
    await ElMessageBox.confirm(`确定要改为${st === 1 ? '已审核' : '未审核'}吗?`, '操作确认', {
      confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning',
    })
  } catch { return }
  try {
    if (isSeckill) await setSeckillCommentStatus(ids, st)
    else await setCollageCommentSt({ ids, status: st })
    ElMessage.success('操作成功')
    fetchList()
  } catch { /* 拦截器统一提示 */ }
}

// ============ 回复弹窗 ============
const replyVisible = ref(false)
const replyRow = ref<any>(null)
const replyForm = reactive({ content: '' })
function openReply(row: any) {
  replyRow.value = row
  replyForm.content = row.reply_content || ''
  replyVisible.value = true
}
async function submitReply() {
  if (!replyRow.value) return
  if (!replyForm.content.trim()) { ElMessage.warning('请输入回复内容'); return }
  try {
    if (isSeckill) await replySeckillComment(replyRow.value.id, replyForm.content.trim())
    else await replyCollageComment(replyRow.value.id, replyForm.content)
    ElMessage.success('回复成功')
    replyVisible.value = false
    fetchList()
  } catch { /* 拦截器统一提示 */ }
}

// ============ 删除 ============
function batchDelete(row?: any) {
  let ids: number[] = []
  if (row) ids = [row.id]
  else {
    if (!selectedIds.value.length) { ElMessage.warning('请选择数据'); return }
    ids = selectedIds.value
  }
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '删除确认', {
    confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    try {
      if (isSeckill) await deleteSeckillComment(ids)
      else await deleteCollageComment({ ids })
      ElMessage.success('删除成功')
      fetchList()
    } catch { /* 拦截器统一提示 */ }
  }).catch(() => {})
}

// ============ 详情 ============
const detailVisible = ref(false)
const detailRow = ref<any>(null)
function openDetail(row: any) { detailRow.value = row; detailVisible.value = true }
function detailPics(row: any): string[] {
  if (!row.content_pic) return []
  return String(row.content_pic).split(',').filter(Boolean)
}

onMounted(fetchList)
</script>

<template>
  <div v-if="useNewLayout" class="comment-page">
    <el-card shadow="never">
      <div class="page-title">{{ title }}</div>
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button size="small" @click="batchDelete()">删除</el-button>
          <el-button size="small" @click="batchSetst(1)">已审</el-button>
          <el-button size="small" @click="batchSetst(0)">未审</el-button>
        </div>
        <div class="toolbar-right">
          <span class="search-label">评价内容</span>
          <el-input v-model="query.content" placeholder="" style="width: 160px" @keyup.enter="handleSearch" />
          <span class="search-label">评价时间</span>
          <el-date-picker
            v-model="query.ctime"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 240px"
          />
          <button class="search-btn" @click="handleSearch" title="搜索">
            <svg viewBox="0 0 1024 1024" width="15" height="15"><path fill="#fff" d="M795.904 750.72l124.992 124.928a32 32 0 1 1-45.269 45.269l-124.928-124.992a416 416 0 1 1 45.269-45.269zM480 832a352 352 0 1 0 0-704 352 352 0 0 0 0 704z"/></svg>
          </button>
        </div>
      </div>

      <el-table v-loading="loading" :data="tableData" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="商品信息" min-width="240">
          <template #default="{ row }">
            <div class="goods-cell">
              <el-image v-if="row.propic" :src="row.propic" fit="cover" class="goods-thumb" />
              <div class="goods-info">
                <div class="goods-name">{{ row.proname }}</div>
                <div class="goods-gg">{{ row.ggname || '默认规格' }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评价人信息" width="150">
          <template #default="{ row }">
            <div class="buyer-cell">
              <el-avatar :size="32" :src="row.headimg">{{ (row.nickname || '?').slice(0, 1) }}</el-avatar>
              <span>{{ row.nickname }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评分" width="80">
          <template #default="{ row }">{{ row.score }}星</template>
        </el-table-column>
        <el-table-column label="评价内容" min-width="180">
          <template #default="{ row }">{{ row.content }}</template>
        </el-table-column>
        <el-table-column label="评价时间" width="150">
          <template #default="{ row }">{{ row.createtime_text }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <button
              class="status-btn"
              :class="row.status === 1 ? 'st-ok' : 'st-no'"
              @click="batchSetst(row.status === 1 ? 0 : 1, row)"
            >
              {{ row.status === 1 ? '已审核' : '未审核' }}
            </button>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="160">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openDetail(row)">详情</el-button>
              <el-button link type="primary" @click="openReply(row)">回复</el-button>
              <el-button link type="danger" @click="batchDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="prev, pager, next, jumper, total, sizes"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50]"
        @current-change="(p: number) => ((query.page = p), fetchList())"
        @size-change="(s: number) => ((query.pagesize = s), (query.page = 1), fetchList())"
      />
    </el-card>

    <!-- 回复弹窗 -->
    <el-dialog v-model="replyVisible" title="回复评价" width="420px" align-center>
      <div class="reply-form">
        <span class="reply-label">回复内容</span>
        <el-input v-model="replyForm.content" type="textarea" :rows="4" />
      </div>
      <template #footer>
        <el-button type="primary" @click="submitReply">确定</el-button>
        <el-button @click="replyVisible = false">取消</el-button>
      </template>
    </el-dialog>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="评价详情" width="480px" align-center>
      <div v-if="detailRow" class="detail-view">
        <div class="d-item">
          <span class="d-label">评价人</span>
          <div class="d-val">
            <el-avatar :size="40" :src="detailRow.headimg" />
            <span>{{ detailRow.nickname }}</span>
          </div>
        </div>
        <div class="d-item"><span class="d-label">评价时间</span><span class="d-val">{{ detailRow.createtime_text }}</span></div>
        <div class="d-item"><span class="d-label">内容</span><span class="d-val">{{ detailRow.content }}</span></div>
        <div v-if="detailPics(detailRow).length" class="d-item">
          <span class="d-label">图片</span>
          <div class="d-val pics">
            <el-image
              v-for="(p, i) in detailPics(detailRow)"
              :key="i"
              :src="p"
              fit="cover"
              class="detail-pic"
              :preview-src-list="detailPics(detailRow)"
              :initial-index="i"
              preview-teleported
            />
          </div>
        </div>
        <div v-if="detailRow.reply_content" class="d-item">
          <span class="d-label">回复内容</span><span class="d-val">{{ detailRow.reply_content }}</span>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.comment-page { margin: 3px; }
.page-title { font-size: 14px; font-weight: 600; margin-bottom: 12px; color: #303133; }
.toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.toolbar-left { display: flex; gap: 8px; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.search-label { font-size: 13px; color: #606266; white-space: nowrap; }
.search-btn { width: 32px; height: 32px; border: none; border-radius: 4px; background: #009688; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
.goods-cell { display: flex; align-items: center; gap: 8px; }
.goods-thumb { width: 40px; height: 40px; border-radius: 4px; flex-shrink: 0; }
.goods-info { min-width: 0; }
.goods-name { font-size: 13px; color: #303133; line-height: 1.4; }
.goods-gg { font-size: 12px; color: #ff6600; margin-top: 2px; }
.buyer-cell { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.status-btn { border: none; border-radius: 4px; padding: 4px 12px; font-size: 12px; cursor: pointer; color: #fff; }
.st-ok { background: #1e9fff; }
.st-no { background: #ff5722; }
.row-ops { display: flex; flex-direction: row; align-items: center; gap: 4px; }
.pagination { margin-top: 16px; justify-content: flex-end; }
.reply-form { display: flex; gap: 10px; }
.reply-label { font-size: 13px; color: #606266; white-space: nowrap; line-height: 32px; }
.detail-view { line-height: 1.8; }
.d-item { display: flex; margin-bottom: 10px; align-items: flex-start; }
.d-label { width: 80px; flex-shrink: 0; color: #909399; font-size: 13px; }
.d-val { flex: 1; font-size: 13px; }
.d-val.pics { display: flex; flex-wrap: wrap; gap: 6px; }
.detail-pic { width: 60px; height: 60px; border-radius: 4px; }
:deep(.el-table__header th) { background: #e6e6e6; }
:deep(.el-table .cell) { text-align: left; }
</style>
