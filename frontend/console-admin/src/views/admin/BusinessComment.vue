<script setup lang="ts">
/**
 * 店铺评价 / 商户评价（对照参考系统 business_comment/index.html）
 * - 系统组「店铺评价」：全量；商户组「商户评价」由 merchantViewOf 注入 bid='gt0'（showtype=2，仅商户店铺评价）
 * - 工具栏：删除 / 已审 / 未审；搜索：所属商户（商户视图）、评价内容、评价时间
 * - 列：ID / 所属商户 / 评价人信息 / 评分(X星,可排序) / 评价内容 / 评价时间(可排序) / 状态(行内审核切换) / 操作
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import {
  deleteBusinessComment,
  getBusinessCommentList,
  replyBusinessComment,
  setBusinessCommentSt,
  type BusinessCommentItem,
} from '../../api/system'
import { assetUrl } from '../../api/upload'

// 商户菜单「商户评价」以 merchantViewOf 注入 bid='gt0'（对照参考系统 BusinessComment/index&showtype=2）
const props = defineProps<{ bid?: string }>()
const isMerchantView = computed(() => props.bid === 'gt0')

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<BusinessCommentItem[]>([])
const total = ref(0)
const businessOptions = ref<{ id: number; name: string }[]>([])
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  bid: (props.bid === 'gt0' ? 'gt0' : '') as number | string,
  content: '',
  range: [] as string[],
  field: '',
  order: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getBusinessCommentList({
      page: query.page,
      pagesize: query.pagesize,
      bid: query.bid === '' ? undefined : query.bid,
      content: query.content || undefined,
      start: query.range?.[0] || undefined,
      end: query.range?.[1] || undefined,
      field: query.field || undefined,
      order: query.order || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    businessOptions.value = data.business_options || []
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

/** 商户下拉：选中具体商户按商户过滤，清空回到全部商户（gt0）/全部（系统组） */
function onBusinessChange(v: number | '') {
  query.bid = v === '' ? (isMerchantView.value ? 'gt0' : '') : v
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

function handleSelectionChange(rows: BusinessCommentItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

/** 表头排序（对照参考系统：评分/评价时间，field/order 交后端白名单排序） */
function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  query.field = order ? prop : ''
  query.order = order === 'ascending' ? 'asc' : order === 'descending' ? 'desc' : ''
  query.page = 1
  fetchList()
}

// ============ 审核（setst 回算均分） ============
function setst(ids: number[], status: number) {
  if (!ids.length) {
    ElMessage.warning('请选择数据')
    return
  }
  const label = status === 1 ? '已审核' : '未审核'
  ElMessageBox.confirm(`确定要改为${label}吗?`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setBusinessCommentSt(ids, status)
        ElMessage.success('操作成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 删除 ============
function handleDelete(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteBusinessComment(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 时间格式化（对照参考系统 Y-m-d H:i） ============
function fmtDateTime(t: unknown): string {
  const n = Number(t || 0)
  if (!n) return '--'
  const d = new Date(n * 1000)
  const p = (v: number) => String(v).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// ============ 详情弹窗 ============
const detailVisible = ref(false)
const detailRow = ref<BusinessCommentItem | null>(null)

function openDetail(row: BusinessCommentItem) {
  detailRow.value = row
  detailVisible.value = true
}

// 详情图片列表
function detailPics(): string[] {
  const pic = detailRow.value?.content_pic || ''
  return String(pic)
    .split(',')
    .filter(Boolean)
    .map((u) => assetUrl(u))
}

// ============ 回复弹窗 ============
const replyVisible = ref(false)
const replySaving = ref(false)
const replyTarget = ref<BusinessCommentItem | null>(null)
const replyContent = ref('')

function openReply(row: BusinessCommentItem) {
  replyTarget.value = row
  replyContent.value = row.reply_content || ''
  replyVisible.value = true
}

async function submitReply() {
  if (!replyTarget.value) return
  if (!replyContent.value.trim()) {
    ElMessage.warning('请输入回复内容')
    return
  }
  replySaving.value = true
  try {
    await replyBusinessComment(replyTarget.value.id, replyContent.value.trim())
    ElMessage.success('回复成功')
    replyVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    replySaving.value = false
  }
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div class="comment-page">
    <!-- 工具栏：左侧批量操作，右侧搜索（对照参考系统） -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button @click="handleDelete(selectedIds)">删除</el-button>
          <el-button @click="setst(selectedIds, 1)">已审</el-button>
          <el-button @click="setst(selectedIds, 0)">未审</el-button>
        </div>
        <div class="toolbar-right">
          <el-select
            :model-value="query.bid === 'gt0' || query.bid === '' ? '' : Number(query.bid)"
            placeholder="全部"
            clearable
            filterable
            class="biz-select"
            @change="onBusinessChange"
          >
            <el-option v-for="b in businessOptions" :key="b.id" :label="b.name" :value="b.id" />
          </el-select>
          <el-input
            v-model="query.content"
            placeholder="评价内容"
            clearable
            class="content-input"
            @keyup.enter="handleSearch"
          />
          <el-date-picker
            v-model="query.range"
            type="daterange"
            range-separator="~"
            start-placeholder="评价时间"
            end-placeholder="评价时间"
            value-format="YYYY-MM-DD"
            class="date-range"
          />
          <el-button type="primary" :icon="Search" @click="handleSearch" />
        </div>
      </div>
    </el-card>

    <!-- 评价列表 -->
    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        stripe
        size="small"
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="bname" label="所属商户" width="160" show-overflow-tooltip />
        <el-table-column label="评价人信息" width="150">
          <template #default="{ row }">
            <div class="commenter">
              <el-image
                v-if="row.headimg"
                :src="assetUrl(row.headimg)"
                class="commenter-avatar"
                fit="cover"
              >
                <template #error>
                  <div class="avatar-fallback">{{ (row.nickname || '会').slice(0, 1) }}</div>
                </template>
              </el-image>
              <div v-else class="avatar-fallback">{{ (row.nickname || '会').slice(0, 1) }}</div>
              <span class="commenter-name" :title="row.nickname">{{ row.nickname || '--' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="score" label="评分" width="80" sortable="custom" align="center">
          <template #default="{ row }">{{ row.score }}星</template>
        </el-table-column>
        <el-table-column prop="content" label="评价内容" min-width="200" show-overflow-tooltip />
        <el-table-column
          prop="createtime"
          label="评价时间"
          width="150"
          sortable="custom"
          :formatter="(row: BusinessCommentItem) => fmtDateTime(row.createtime)"
        />
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 1"
              type="primary"
              size="small"
              @click="setst([row.id], 0)"
            >
              已审核
            </el-button>
            <el-button v-else type="danger" size="small" @click="setst([row.id], 1)">
              未审核
            </el-button>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
            <el-button link type="primary" size="small" @click="openReply(row)">回复</el-button>
            <el-button link type="danger" size="small" @click="handleDelete([row.id])">
              删除
            </el-button>
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

    <!-- 详情弹窗（对照参考系统 detailtpl：评价人/评价时间/内容/图片/回复内容） -->
    <el-dialog v-model="detailVisible" title="评价详情" width="520px">
      <template v-if="detailRow">
        <div class="detail-item">
          <span class="detail-label">评价人</span>
          <span class="detail-value">
            <el-image
              v-if="detailRow.headimg"
              :src="assetUrl(detailRow.headimg)"
              class="detail-avatar"
              fit="cover"
            />
            {{ detailRow.nickname || '--' }}
          </span>
        </div>
        <div class="detail-item">
          <span class="detail-label">所属商户</span>
          <span class="detail-value">{{ detailRow.bname || '--' }}</span>
        </div>
        <div class="detail-item">
          <span class="detail-label">评分</span>
          <span class="detail-value">{{ detailRow.score }}星</span>
        </div>
        <div class="detail-item">
          <span class="detail-label">评价时间</span>
          <span class="detail-value">{{ fmtDateTime(detailRow.createtime) }}</span>
        </div>
        <div class="detail-item detail-block">
          <span class="detail-label">内容</span>
          <span class="detail-value">{{ detailRow.content || '--' }}</span>
        </div>
        <div v-if="detailPics().length" class="detail-item detail-block">
          <span class="detail-label">图片</span>
          <span class="detail-value detail-pics">
            <el-image
              v-for="(pic, idx) in detailPics()"
              :key="idx"
              :src="pic"
              :preview-src-list="detailPics()"
              fit="cover"
              class="detail-pic"
            />
          </span>
        </div>
        <div class="detail-item detail-block">
          <span class="detail-label">回复内容</span>
          <span class="detail-value">{{ detailRow.reply_content || '未回复' }}</span>
        </div>
      </template>
    </el-dialog>

    <!-- 回复弹窗 -->
    <el-dialog v-model="replyVisible" title="回复评价" width="480px">
      <el-input
        v-model="replyContent"
        type="textarea"
        :rows="4"
        maxlength="500"
        show-word-limit
        placeholder="请输入回复内容"
      />
      <template #footer>
        <el-button @click="replyVisible = false">取消</el-button>
        <el-button type="primary" :loading="replySaving" @click="submitReply">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.comment-page {
  /* 主区域四边边距由 AdminLayout .admin-main 统一 3px，页面不再自带 padding */
}

.toolbar-card {
  margin-bottom: 3px;
}

.toolbar-card :deep(.el-card__body) {
  padding: 12px 14px;
}

.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: nowrap;
  gap: 8px;
}

.toolbar-left {
  display: flex;
  gap: 8px;
  flex: 0 0 auto;
}

.toolbar-right {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: nowrap;
  flex: 1 1 auto;
  justify-content: flex-end;
  min-width: 0;
}

.biz-select {
  width: 130px;
  flex: none;
}

.content-input {
  width: 150px;
  flex: none;
}

.date-range {
  width: 200px;
  flex: none;
}

.commenter {
  display: flex;
  align-items: center;
  gap: 6px;
}

.commenter-avatar,
.avatar-fallback {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  flex: none;
}

.avatar-fallback {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f5f7fa;
  color: #909399;
  font-size: 13px;
}

.commenter-name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.detail-item {
  display: flex;
  font-size: 13px;
  line-height: 24px;
  padding: 6px 0;
}

.detail-block {
  align-items: flex-start;
}

.detail-label {
  width: 70px;
  flex: none;
  color: #303133;
}

.detail-value {
  flex: 1;
  color: #606266;
  word-break: break-all;
}

.detail-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  vertical-align: middle;
  margin-right: 6px;
}

.detail-pics {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.detail-pic {
  width: 50px;
  height: 50px;
  border-radius: 4px;
}
</style>
