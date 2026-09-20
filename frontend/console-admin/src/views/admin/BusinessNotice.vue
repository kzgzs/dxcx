<script setup lang="ts">
/**
 * 商户 - 通知公告管理（admin_notice type=2 商家公告）
 * 对标参考系统 business_notice：
 * - 顶栏：添加 / 删除 / 开启 / 关闭；右侧搜索 账号 / 标题 / 状态(未读已读) / 发送时间范围
 * - 表格：多选、ID(可排序)、接收账号、标题、发送时间、状态、操作(查看/删除)
 * - 添加弹窗：接收范围(4商家主账号 / 5主账号+分账号) 联动 接收账号下拉(全部或指定)、标题、富文本内容
 * - 开启/关闭：公告上下架（status 1/0，批量）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteBusinessNotice,
  getBusinessNoticeDetail,
  getBusinessNoticeList,
  getBusinessNoticeReceivers,
  saveBusinessNotice,
  setBusinessNoticeStatus,
  type BusinessNoticeItem,
  type BusinessNoticeReceiver,
} from '../../api/business'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<BusinessNoticeItem[]>([])
const total = ref(0)
const selectedRows = ref<BusinessNoticeItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  un: '',
  title: '',
  isread: '' as number | '',
  timerange: [] as string[],
  field: 'id',
  order: 'desc' as 'asc' | 'desc',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getBusinessNoticeList({
      page: query.page,
      pagesize: query.pagesize,
      un: query.un || undefined,
      title: query.title || undefined,
      isread: query.isread === '' ? undefined : query.isread,
      start: query.timerange?.[0] || undefined,
      end: query.timerange?.[1] || undefined,
      field: query.field,
      order: query.order,
    })
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

/** 表头排序（ID） */
function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  if (!order) {
    query.field = 'id'
    query.order = 'desc'
  } else {
    query.field = prop
    query.order = order === 'ascending' ? 'asc' : 'desc'
  }
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

function handleSelectionChange(rows: BusinessNoticeItem[]) {
  selectedRows.value = rows
}

/** 发送时间格式 Y-m-d H:i */
function fmtTime(ts: number): string {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// ============ 添加通知公告 ============
const publishVisible = ref(false)
const publishing = ref(false)
const receiversMain = ref<BusinessNoticeReceiver[]>([])
const receiversAll = ref<BusinessNoticeReceiver[]>([])
const publishForm = reactive({
  sendtype: 4,
  senduser: 0,
  title: '',
  content: '',
})

/** 接收账号下拉（随接收范围联动，首项为“全部”） */
const receiverOptions = computed(() => {
  const list = publishForm.sendtype === 4 ? receiversMain.value : receiversAll.value
  const allLabel = publishForm.sendtype === 4 ? '全部商家主账号' : '全部商家主账号+分账号'
  return [{ id: 0, un: allLabel }, ...list]
})

async function openPublish() {
  publishForm.sendtype = 4
  publishForm.senduser = 0
  publishForm.title = ''
  publishForm.content = ''
  publishVisible.value = true
  // 拉取接收账号（两组），失败不阻塞弹窗
  try {
    const r = await getBusinessNoticeReceivers()
    receiversMain.value = r.main || []
    receiversAll.value = r.all || []
  } catch {
    // 忽略，下拉退化为仅“全部”
  }
}

function changeSendtype() {
  // 切换接收范围时重置为“全部”
  publishForm.senduser = 0
}

async function submitPublish() {
  if (!publishForm.title.trim()) {
    ElMessage.warning('请填写标题')
    return
  }
  if (!publishForm.content.replace(/<[^>]*>/g, '').trim()) {
    ElMessage.warning('请填写内容')
    return
  }
  publishing.value = true
  try {
    const data = await saveBusinessNotice({
      title: publishForm.title.trim(),
      content: publishForm.content,
      sendtype: publishForm.sendtype,
      senduser: publishForm.senduser,
    })
    ElMessage.success(`发布成功，共推送 ${data.count} 个账号`)
    publishVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    publishing.value = false
  }
}

// ============ 详情（查看） ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<BusinessNoticeItem | null>(null)

async function openDetail(row: BusinessNoticeItem) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await getBusinessNoticeDetail(row.id)
  } catch {
    detailVisible.value = false
  } finally {
    detailLoading.value = false
  }
}

// ============ 批量删除 / 开启 / 关闭 ============
function selectedIds(): number[] {
  return selectedRows.value.map((r) => r.id)
}

function handleDelete(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${ids.length} 条公告吗？删除后无法恢复！`, '操作确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await deleteBusinessNotice(ids)
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function handleSetst(st: number) {
  const ids = selectedIds()
  if (!ids.length) {
    ElMessage.warning('请选择数据')
    return
  }
  const verb = st === 1 ? '开启' : '关闭'
  ElMessageBox.confirm(`确定要${verb}选中的 ${ids.length} 条公告吗？`, '操作确认', {
    confirmButtonText: verb,
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await setBusinessNoticeStatus(ids, st)
      ElMessage.success(`已${verb}`)
      fetchList()
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div class="bn-root">
    <!-- 顶栏：左操作按钮，右搜索 -->
    <el-card shadow="never" class="bn-toolbar-card">
      <div class="bn-toolbar">
        <div class="bn-actions">
          <el-button type="primary" @click="openPublish">添加</el-button>
          <el-button @click="handleDelete(selectedIds())">删除</el-button>
          <el-button @click="handleSetst(1)">开启</el-button>
          <el-button @click="handleSetst(0)">关闭</el-button>
        </div>
        <el-form inline class="bn-search" @submit.prevent>
          <el-form-item>
            <el-input v-model="query.un" placeholder="账号" clearable style="width: 120px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item>
            <el-input v-model="query.title" placeholder="标题" clearable style="width: 130px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item>
            <el-select v-model="query.isread" placeholder="状态" clearable style="width: 92px">
              <el-option label="未读" :value="0" />
              <el-option label="已读" :value="1" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-date-picker
              v-model="query.timerange"
              type="daterange"
              range-separator="~"
              start-placeholder="发送开始"
              end-placeholder="发送结束"
              value-format="YYYY-MM-DD"
              style="width: 210px"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="success" @click="handleSearch">搜索</el-button>
          </el-form-item>
        </el-form>
      </div>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="90" sortable="custom" :order="query.field === 'id' ? (query.order === 'asc' ? 'ascending' : 'descending') : null" />
        <el-table-column prop="un" label="接收账号" width="200" show-overflow-tooltip />
        <el-table-column prop="title" label="标题" min-width="200" show-overflow-tooltip />
        <el-table-column label="发送时间" width="160">
          <template #default="{ row }">{{ fmtTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <span :class="row.isread === 1 ? 'bn-read' : 'bn-unread'">{{ row.isread === 1 ? '已读' : '未读' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">查看</el-button>
            <el-button link type="danger" @click="handleDelete([row.id])">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="bn-pagination"
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

    <!-- 添加通知公告 -->
    <el-dialog v-model="publishVisible" title="+添加通知公告" width="820px" :close-on-click-modal="false" append-to-body>
      <el-form label-width="110px" class="bn-form">
        <el-form-item label="接收范围：" required>
          <el-radio-group v-model="publishForm.sendtype" @change="changeSendtype">
            <el-radio :value="4">商家主账号</el-radio>
            <el-radio :value="5">商家主账号+分账号</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="接收账号：">
          <el-select v-model="publishForm.senduser" filterable style="width: 240px">
            <el-option v-for="u in receiverOptions" :key="u.id" :label="u.un" :value="u.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="标题：" required>
          <el-input v-model="publishForm.title" placeholder="请输入公告标题" maxlength="100" style="width: 500px" />
        </el-form-item>
        <el-form-item label="内容：" required>
          <SimpleRichEditor v-model="publishForm.content" class="bn-editor" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="publishVisible = false">取消</el-button>
        <el-button type="danger" :loading="publishing" @click="submitPublish">提 交</el-button>
      </template>
    </el-dialog>

    <!-- 查看详情 -->
    <el-dialog v-model="detailVisible" title="通知公告详情" width="720px" append-to-body>
      <div v-loading="detailLoading">
        <template v-if="detail">
          <h3 class="bn-detail-title">{{ detail.title }}</h3>
          <div class="bn-detail-meta">
            接收账号：{{ detail.un || '-' }}　发送时间：{{ fmtTime(detail.createtime) }}
          </div>
          <div class="bn-detail-content" v-html="detail.content"></div>
        </template>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.bn-toolbar-card {
  margin-bottom: 3px;
}
.bn-toolbar-card :deep(.el-card__body) {
  padding: 10px 12px;
}

.bn-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: nowrap;
  gap: 12px;
}

.bn-actions {
  display: flex;
  gap: 8px;
  flex: 0 0 auto;
}

.bn-actions .el-button {
  margin: 0;
}

.bn-search {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  min-width: 0;
  margin: 0;
}

.bn-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}

.bn-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}

.bn-pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.bn-unread {
  color: #303133;
}

.bn-read {
  color: #909399;
}

.bn-editor {
  width: 640px;
}

.bn-editor :deep(.sre-editor) {
  min-height: 320px !important;
  max-height: 440px !important;
}

.bn-detail-title {
  margin: 0 0 8px;
  font-size: 16px;
  color: #303133;
}

.bn-detail-meta {
  font-size: 12px;
  color: #909399;
  padding-bottom: 12px;
  border-bottom: 1px solid #ebeef5;
  margin-bottom: 14px;
}

.bn-detail-content {
  font-size: 14px;
  color: #606266;
  line-height: 1.7;
  word-break: break-word;
}
</style>
