<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteSeckillComment,
  getSeckillCommentList,
  replySeckillComment,
  setSeckillCommentStatus,
  type SeckillCommentItem,
} from '../../api/seckill'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<SeckillCommentItem[]>([])
const total = ref(0)
const selected = ref<SeckillCommentItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  status: '' as number | '',
  content: '',
  dateRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getSeckillCommentList({
      page: query.page,
      pagesize: query.pagesize,
      status: query.status === '' ? undefined : query.status,
      content: query.content || undefined,
      start_date: query.dateRange ? query.dateRange[0] : undefined,
      end_date: query.dateRange ? query.dateRange[1] : undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 错误已由拦截器弹出 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}
function handlePageChange(p: number) {
  query.page = p
  fetchList()
}
function handleSizeChange(s: number) {
  query.pagesize = s
  query.page = 1
  fetchList()
}
function onSelectionChange(rows: SeckillCommentItem[]) {
  selected.value = rows
}

// 批量：删除 / 已审 / 未审
function batchDelete() {
  if (!selected.value.length) return ElMessage.warning('请先选择评价')
  ElMessageBox.confirm(`确定删除选中的 ${selected.value.length} 条评价吗？`, '批量删除', {
    confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    await deleteSeckillComment(selected.value.map((r) => r.id))
    ElMessage.success('删除成功')
    fetchList()
  }).catch(() => {})
}
function batchSet(status: number) {
  if (!selected.value.length) return ElMessage.warning('请先选择评价')
  setSeckillCommentStatus(selected.value.map((r) => r.id), status).then(() => {
    ElMessage.success('操作成功')
    fetchList()
  })
}

// 单条：状态切换 / 回复 / 删除 / 详情
function toggleStatus(row: SeckillCommentItem) {
  const next = row.status === 1 ? 2 : 1
  setSeckillCommentStatus(row.id, next).then(() => {
    ElMessage.success('操作成功')
    fetchList()
  })
}
function handleReply(row: SeckillCommentItem) {
  ElMessageBox.prompt('请输入回复内容', '回复评价', {
    confirmButtonText: '回复', cancelButtonText: '取消',
    inputType: 'textarea', inputValue: row.reply_content || '',
    inputPlaceholder: '请填写回复内容',
  }).then(async ({ value }) => {
    await replySeckillComment(row.id, (value || '').trim())
    ElMessage.success('回复成功')
    fetchList()
  }).catch(() => {})
}
function handleDelete(row: SeckillCommentItem) {
  ElMessageBox.confirm('确定删除该评价吗？删除后无法恢复。', '删除确认', {
    confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    await deleteSeckillComment(row.id)
    ElMessage.success('删除成功')
    fetchList()
  }).catch(() => {})
}

// 详情（小弹窗，仅图片/链接）
const detailVisible = ref(false)
const detailRow = ref<SeckillCommentItem | null>(null)
function openDetail(row: SeckillCommentItem) {
  detailRow.value = row
  detailVisible.value = true
}

function stars(score: number) {
  return '★'.repeat(score) + '☆'.repeat(Math.max(0, 5 - score))
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-left">
        <el-button @click="batchDelete">删除</el-button>
        <el-button @click="batchSet(1)">已审</el-button>
        <el-button @click="batchSet(2)">未审</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item label="评价内容">
          <el-input v-model="query.content" placeholder="评价内容" clearable style="width: 150px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="评价时间">
          <el-date-picker
            v-model="query.dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            style="width: 230px"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="待审核" :value="0" />
            <el-option label="已显示" :value="1" />
            <el-option label="已驳回" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="onSelectionChange">
        <el-table-column type="selection" width="42" align="center" />
        <el-table-column prop="id" label="ID" width="60" align="center" />
        <el-table-column label="商品信息" min-width="200">
          <template #default="{ row }">
            <div class="goods-info">
              <el-image v-if="row.propic" :src="row.propic" :preview-src-list="[row.propic]" fit="cover" class="goods-img" preview-teleported />
              <div v-else class="goods-img goods-img-empty">无图</div>
              <div class="goods-name">{{ row.proname }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评价人" width="140">
          <template #default="{ row }">
            <div class="nick">
              <el-image v-if="row.headimg" :src="row.headimg" fit="cover" class="headimg" />
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评分" width="90" align="center">
          <template #default="{ row }">
            <span class="stars">{{ stars(row.score) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="评价内容" min-width="200">
          <template #default="{ row }">
            <div class="content">{{ row.content }}</div>
            <div v-if="row.reply_content" class="reply">商家回复：{{ row.reply_content }}</div>
          </template>
        </el-table-column>
        <el-table-column label="评价时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-button link :type="row.status === 1 ? 'success' : 'warning'" @click="toggleStatus(row)">
              {{ row.status_text }}
            </el-button>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openDetail(row)">详情</el-button>
              <el-button link type="primary" @click="handleReply(row)">回复</el-button>
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

    <!-- 详情小弹窗 -->
    <el-dialog v-model="detailVisible" title="评价详情" width="480px">
      <template v-if="detailRow">
        <el-descriptions :column="1" border size="small">
          <el-descriptions-item label="商品">{{ detailRow.proname }}</el-descriptions-item>
          <el-descriptions-item label="评价人">{{ detailRow.nickname || `会员${detailRow.mid}` }}</el-descriptions-item>
          <el-descriptions-item label="评分">{{ stars(detailRow.score) }}</el-descriptions-item>
          <el-descriptions-item label="评价内容">{{ detailRow.content }}</el-descriptions-item>
          <el-descriptions-item v-if="detailRow.content_pic" label="晒图">
            <el-image :src="detailRow.content_pic" :preview-src-list="[detailRow.content_pic]" fit="cover" style="width: 120px; height: 120px" preview-teleported />
          </el-descriptions-item>
          <el-descriptions-item label="评价时间">{{ formatTime(detailRow.createtime) }}</el-descriptions-item>
          <el-descriptions-item v-if="detailRow.reply_content" label="商家回复">{{ detailRow.reply_content }}</el-descriptions-item>
        </el-descriptions>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
.toolbar :deep(.el-card__body) { padding: 3px 6px; display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: nowrap; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; flex: 1 1 auto; justify-content: flex-end; flex-wrap: nowrap; min-width: 0; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 8px; }
.toolbar-search :deep(.el-form-item:last-child) { margin-right: 0; }
.toolbar-search :deep(.el-form-item__label) { padding-right: 6px; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }
:deep(th.el-table__cell) { background: #e6e6e6 !important; }
:deep(.cell) { text-align: left; }
.goods-info { display: flex; align-items: center; gap: 10px; }
.goods-img { width: 44px; height: 44px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.goods-img-empty { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 12px; background: #fafafa; }
.goods-name { color: #303133; font-size: 13px; }
.nick { display: flex; align-items: center; gap: 6px; }
.headimg { width: 30px; height: 30px; border-radius: 50%; }
.stars { color: #ff9900; font-size: 13px; letter-spacing: 1px; }
.content { color: #303133; font-size: 13px; word-break: break-all; }
.reply { color: #909399; font-size: 12px; margin-top: 4px; }
.row-ops { display: flex; flex-direction: row; flex-wrap: wrap; gap: 2px 8px; line-height: 1.6; }
.row-ops .el-button { margin: 0; height: auto; min-height: 0; padding: 0; font-size: 12px; }
.row-ops :deep(.el-button) { height: auto; padding: 1px 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 12px; justify-content: flex-end; }
</style>
