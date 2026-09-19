<script setup lang="ts">
import { ref, reactive, computed, onBeforeUnmount } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, TableInstance } from 'element-plus'
import request from '../api/request'
// wangEditor Vue3 组件（必须从 editor-for-vue 导入，而非 @wangeditor/editor 核心包）
import '@wangeditor/editor/dist/css/style.css'
import { Editor, Toolbar } from '@wangeditor/editor-for-vue'

// ============ API 接口 ============
const api = {
  list: (params: any) => request.get<unknown, any>('/console/webnotice/list', { params }),
  save: (data: any) => request.post<unknown, any>('/console/webnotice/save', data),
  detail: (id: number) => request.get<unknown, any>('/console/webnotice/detail', { params: { id } }),
  delete: (ids: number[]) => request.post<unknown, any>('/console/webnotice/delete', { ids }),
  userList: () => request.get<unknown, any>('/console/webnotice/userList'),
}

// ============ 表格数据 ============
interface NoticeItem {
  id: number
  username: string
  title: string
  content: string
  createtime: number
  isread: number // 0 未读 1 已读
}

const tableData = ref<NoticeItem[]>([])
const loading = ref(false)
const total = ref(0)

// ============ 表格选中（批量删除用） ============
const selectedIds = ref<number[]>([])
const tableRef = ref<TableInstance>()

/** 清空表格勾选 */
function clearSelection() {
  selectedIds.value = []
  tableRef.value?.clearSelection()
}

// ============ 筛选条件 ============
const filterForm = reactive({
  un: '',           // 账号（接收账号）
  title: '',        // 标题
  isread: '',       // 状态（全部/未读/已读）
  start_date: '',   // 发送时间范围开始
  end_date: '',     // 发送时间范围结束
})

// ============ 分页 ============
const pagination = reactive({
  page: 1,
  pageSize: 15,
  total: 0,
})

// 日期范围（用于 v-model 绑定）
const dateRange = ref<[string, string] | null>(null)

// ============ 排序 ============
const sortBy = ref({ prop: '', order: '' })

/** 加载列表（request 拦截器已解包，直接返回 data） */
async function loadList() {
  loading.value = true
  try {
    const data = await api.list({
      page: pagination.page,
      pagesize: pagination.pageSize,
      ...filterForm,
      field: sortBy.value.prop || '',
      order: sortBy.value.order === 'ascending' ? 'asc' : sortBy.value.order === 'descending' ? 'desc' : '',
    })
    tableData.value = data?.list || []
    total.value = data?.total || 0
    pagination.total = data?.total || 0
  } finally {
    loading.value = false
  }
}

/** 搜索 */
function handleSearch() {
  pagination.page = 1
  loadList()
}

/** 重置筛选 */
function handleReset() {
  Object.assign(filterForm, { un: '', title: '', isread: '', start_date: '', end_date: '' })
  pagination.page = 1
  loadList()
}

// ============ 视图切换：list 列表 / edit 编辑（铺满主区域） ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 新增 / 编辑表单数据（铺满编辑视图） ============
const formRef = ref<FormInstance>()
const editorRef = ref<any>(null)

const form = reactive<any>({
  sendtype: '1',                    // 接收范围：1 所有账号 2 平台主账号 3 平台主账号 + 分账号 4 商家主账号 5 商家主账号 + 分账号
  senduser1: '0',                   // 所有账号 - 单个账号
  senduser2: '0',                   // 平台主账号 - 单个账号
  senduser3: '0',                   // 平台主账号 + 分账号 - 单个账号
  senduser4: '0',                   // 商家主账号 - 单个账号
  senduser5: '0',                   // 商家主账号 + 分账号 - 单个账号
  info: {
    title: '',                      // 标题
    content: '',                    // 内容（富文本）
  },
})

// ============ 富文本编辑器相关 ============
const toolbarConfig = reactive({
  placeholder: '',
})

const editorConfig = reactive({
  placeholder: '请输入公告内容',
  MENU_CONF: {},
})

// wangEditor 实例创建回调：绑定到 editorRef 供 Toolbar 使用
function handleCreated(editor: any) {
  editorRef.value = editor
}

// 组件卸载时销毁编辑器，避免内存泄漏
onBeforeUnmount(() => {
  const editor = editorRef.value
  if (editor) editor.destroy()
})

// ============ 接收账号选项（对照参考系统 edit：按接收范围 5 组） ============
interface UserOption {
  id: number
  un: string
}
const userLists = reactive<Record<string, UserOption[]>>({
  userlist: [],          // sendtype=1 所有账号
  adminuserlist: [],     // sendtype=2 平台主账号
  adminuserlist2: [],    // sendtype=3 平台主账号 + 分账号
  businessuserlist: [],  // sendtype=4 商家主账号
  businessuserlist2: [], // sendtype=5 商家主账号 + 分账号
})
// 接收范围 -> 账号列表 key 与默认项文案（参考系统 option value=0）
const sendtypeMap: Record<string, { key: string; allLabel: string }> = {
  '1': { key: 'userlist', allLabel: '全部账号' },
  '2': { key: 'adminuserlist', allLabel: '全部平台主账号' },
  '3': { key: 'adminuserlist2', allLabel: '全部平台主账号+分账号' },
  '4': { key: 'businessuserlist', allLabel: '全部商家主账号' },
  '5': { key: 'businessuserlist2', allLabel: '全部商家主账号+分账号' },
}
// 空值保护：始终返回数组，避免渲染阶段访问 undefined
const currentUserOptions = computed<UserOption[]>(() => {
  const key = sendtypeMap[form.sendtype]?.key || 'userlist'
  return userLists[key] || []
})
const currentAllLabel = computed(() => sendtypeMap[form.sendtype]?.allLabel || '全部账号')

/** 加载接收账号选项（request 拦截器已解包，直接返回 data；加空值保护） */
async function loadUserOptions() {
  try {
    const data = (await api.userList()) || {}
    Object.assign(userLists, {
      userlist: data.userlist || [],
      adminuserlist: data.adminuserlist || [],
      adminuserlist2: data.adminuserlist2 || [],
      businessuserlist: data.businessuserlist || [],
      businessuserlist2: data.businessuserlist2 || [],
    })
  } catch (err: any) {
    ElMessage.error(err.message || '加载接收账号失败')
  }
}

async function resetForm() {
  Object.assign(form, {
    sendtype: '1',
    senduser1: '0',
    senduser2: '0',
    senduser3: '0',
    senduser4: '0',
    senduser5: '0',
    info: { title: '', content: '' },
  })
  editorRef.value?.destroy()
  editorRef.value = null
}

async function openAdd() {
  resetForm()
  viewMode.value = 'edit'
  loadUserOptions()
}

/** 返回列表 */
function goBackToList() {
  viewMode.value = 'list'
  editorRef.value?.destroy()
  editorRef.value = null
}

/** 保存（发布/更新）*/
async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return

    try {
      const payload: any = {
        sendtype: parseInt(form.sendtype),
        info: {
          title: form.info.title,
          content: form.info.content,
        },
      }
      // 对照参考系统：每个范围各自的 senduserN，0 = 该范围全部账号
      const key = `senduser${form.sendtype}` as string
      payload[key] = parseInt(form[key] || '0')
      await api.save(payload)
      ElMessage.success('发布公告成功')
      goBackToList()
      loadList()
    } catch (err: any) {
      ElMessage.error(err.message || '发布失败')
    }
  })
}

// ============ 查看弹窗（对照参考系统 detail.html：标题 + 时间 + 内容 HTML） ============
const detailVisible = ref(false)
const detailInfo = reactive<{ title: string; create_time: string; content: string }>({
  title: '',
  create_time: '',
  content: '',
})

/** 查看公告详情（对应参考系统「查看」按钮）*/
async function handleView(row: NoticeItem) {
  try {
    const data = await api.detail(row.id)
    const info = data?.info || {}
    detailInfo.title = info.title || ''
    detailInfo.create_time = info.create_time || ''
    detailInfo.content = info.content || ''
    detailVisible.value = true
  } catch (err: any) {
    ElMessage.error(err.message || '查看失败')
  }
}

/** 删除（二次确认）*/
function handleDelete(row: NoticeItem) {
  const idsToDelete = [row.id]
  handleBatchDelete(idsToDelete)
}

/** 批量删除（对应删除按钮：无勾选提示「请先勾选要删除的公告」）*/
function handleDeleteSelected() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选要删除的公告')
    return
  }
  handleBatchDelete([...selectedIds.value])
}

/** 批量删除 */
function handleBatchDelete(ids: number[]) {
  ElMessageBox.confirm(`删除后将无法恢复，确定要删除吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await api.delete(ids)
        ElMessage.success('删除成功')
        clearSelection()
        await loadList()
      } catch (err: any) {
        ElMessage.error(err.message || '删除失败')
      }
    })
    .catch(() => {})
}

/** 表格选中变化，收集选中 id */
function handleSelectionChange(selection: NoticeItem[]) {
  selectedIds.value = selection.map((item) => item.id)
}

/** 排序 */
function handleSortChange(data: any) {
  sortBy.value.prop = data.prop as string
  sortBy.value.order = data.order === 'ascending' ? 'asc' : data.order === 'descending' ? 'desc' : ''
  loadList()
}

/** 分页大小变化 */
function onPageSizeChange(size: number) {
  pagination.pageSize = size
  pagination.page = 1
  loadList()
}

/** 分页页码变化 */
function onPageChange(page: number) {
  pagination.page = page
  loadList()
}

/** 日期范围变化 */
function onDateRangeChange(val: [string, string] | null) {
  if (val && val.length === 2) {
    filterForm.start_date = val[0]
    filterForm.end_date = val[1]
  } else {
    filterForm.start_date = ''
    filterForm.end_date = ''
  }
}

// 初始化
loadList()
</script>

<template>
  <el-card shadow="never">
    <!-- 顶部栏：仅保留标题（任务273：右上角添加/删除按钮多余，已去除） -->
    <template #header>
      <div class="card-header">
        <span>通知公告管理</span>
      </div>
    </template>

    <!-- ============ 列表视图 ============ -->
    <div v-if="viewMode === 'list'">
      <!-- 表格区左上操作入口（任务274：添加 + 删除，删除=勾选后批量删除） -->
      <div class="list-toolbar">
        <el-button type="primary" @click="openAdd">添加</el-button>
        <el-button danger @click="handleDeleteSelected">删除</el-button>
      </div>

      <!-- 筛选行：账号/标题/状态/发送时间 -->
      <el-form inline :model="filterForm" style="margin-bottom: 16px">
      <el-form-item label="账号">
        <el-input v-model="filterForm.un" placeholder="接收账号" clearable style="width: 160px" />
      </el-form-item>
      <el-form-item label="标题">
        <el-input v-model="filterForm.title" placeholder="公告标题" clearable style="width: 160px" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="filterForm.isread" placeholder="全部" clearable style="width: 120px">
          <el-option label="全部" value="" />
          <el-option label="未读" :value="0" />
          <el-option label="已读" :value="1" />
        </el-select>
      </el-form-item>
      <el-form-item label="发送时间">
        <el-date-picker
          v-model="dateRange"
          type="daterange"
          range-separator="~"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          value-format="YYYY-MM-DD"
          style="width: 240px"
          @change="onDateRangeChange"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="handleSearch">搜索</el-button>
        <el-button @click="handleReset">重置</el-button>
      </el-form-item>
    </el-form>

      <!-- 表格 -->
      <el-table
        ref="tableRef"
        v-loading="loading"
        :data="tableData"
        stripe
        border
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
      <el-table-column type="selection" width="55" align="center" />
      <el-table-column prop="id" label="ID" width="80" sortable="custom" align="center" />
      <el-table-column prop="username" label="接收账号" min-width="180" show-overflow-tooltip align="center" />
      <el-table-column prop="title" label="标题" min-width="240" show-overflow-tooltip align="center" />
      <el-table-column label="发送时间" prop="createtime" width="180" sortable="custom" align="center">
        <template #default="{ row }">
          {{ new Date(row.createtime * 1000).toLocaleString('zh-CN') }}
        </template>
      </el-table-column>
      <el-table-column label="状态" prop="isread" width="100" align="center">
        <template #default="{ row }">
          <el-tag :type="row.isread === 0 ? '' : 'info'" size="small">
            {{ row.isread === 0 ? '未读' : '已读' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right" align="center">
        <template #default="{ row }">
          <el-button link type="primary" @click="handleView(row)">查看</el-button>
          <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>

        <template #empty>
          <el-empty description="还没有公告，点击右上角添加一条吧" :image-size="90" />
        </template>
      </el-table>

      <!-- 分页 -->
      <el-pagination
        v-model:current-page="pagination.page"
        v-model:page-size="pagination.pageSize"
        :total="pagination.total"
        layout="total, sizes, prev, pager, next, jumper"
        :page-sizes="[15, 30, 50, 100]"
        @size-change="onPageSizeChange"
        @current-change="onPageChange"
        style="margin-top: 16px; justify-content: flex-end"
      />
    </div>

    <!-- ============ 编辑视图（铺满主区域） ============ -->
    <div v-else class="edit-container">
      <!-- 顶部栏：左侧标题 + 右上角关闭 X（点击返回通知公告列表） -->
      <div class="edit-header">
        <h3>+ 添加通知公告</h3>
        <button class="edit-close-btn" title="返回列表" @click.stop="goBackToList">✕</button>
      </div>

      <el-form ref="formRef" :model="form" label-width="100px" :rules="{
        sendtype: [{ required: true, message: '请选择接收范围', trigger: 'change' }],
        info: [
          { required: true, message: '请输入标题', path: 'title' },
          { required: true, message: '请填写内容', path: 'content' }
        ]
      }">
        <!-- 接收范围 -->
        <el-form-item label="接收范围" prop="sendtype">
          <el-radio-group v-model="form.sendtype">
            <el-radio label="1">所有账号</el-radio>
            <el-radio label="2">平台主账号</el-radio>
            <el-radio label="3">平台主账号 + 分账号</el-radio>
            <el-radio label="4">商家主账号</el-radio>
            <el-radio label="5">商家主账号 + 分账号</el-radio>
          </el-radio-group>
        </el-form-item>

        <!-- 接收账号 -->
        <el-form-item label="接收账号">
          <el-select
            v-model="form[`senduser${form.sendtype}`]"
            :placeholder="currentAllLabel"
            filterable
            clearable
            style="width: 100%"
            @clear="form[`senduser${form.sendtype}`] = '0'"
          >
            <el-option :label="currentAllLabel" value="0" />
            <el-option
              v-for="user in currentUserOptions"
              :key="user.id"
              :label="user.un"
              :value="String(user.id)"
            />
          </el-select>
        </el-form-item>

        <!-- 标题 -->
        <el-form-item label="标题" :path="['info', 'title']">
          <el-input v-model="form.info.title" placeholder="请输入公告标题" maxlength="200" />
        </el-form-item>

        <!-- 内容（富文本编辑器双行工具栏） -->
        <el-form-item label="内容" :path="['info', 'content']">
          <div class="editor-box">
            <Toolbar
              :editor="editorRef"
              :defaultConfig="toolbarConfig"
              mode="default"
              style="border-bottom: 1px solid #eaeaea; min-height: 40px"
            />
            <Editor
              v-model="form.info.content"
              :defaultConfig="editorConfig"
              mode="default"
              style="height: 500px; overflow-y: hidden"
              @onCreated="handleCreated"
            />
          </div>
        </el-form-item>

        <!-- 提交/取消：流式排布在详情下方 -->
        <el-form-item>
          <el-button type="primary" @click="handleSubmit">保存</el-button>
          <el-button @click="goBackToList">取消</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 查看弹窗（对照参考系统 detail.html：标题 + 时间 + 内容 HTML 只读） -->
    <el-dialog v-model="detailVisible" title="通知详情" width="640px">
      <div class="notice-detail">
        <p class="notice-detail-title">{{ detailInfo.title }}</p>
        <p class="notice-detail-time">{{ detailInfo.create_time }}</p>
        <div class="notice-detail-content" v-html="detailInfo.content"></div>
      </div>
      <template #footer>
        <el-button @click="detailVisible = false">返回</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

/* 列表区左上添加入口 */
.list-toolbar {
  margin-bottom: 12px;
}

/* 编辑视图右上角关闭 X */
.edit-close-btn {
  width: 32px;
  height: 32px;
  border: none;
  background: transparent;
  cursor: pointer;
  color: #909399;
  font-size: 20px;
  line-height: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 4px;
  transition: all 0.2s;
}

.edit-close-btn:hover {
  background-color: #f5f7fa;
  color: #f56c6c;
}

/* ============ 编辑视图样式（铺满主区域） ============ */
.edit-container {
  padding: 20px;
  background: #fff;
  border-radius: 4px;
}

.edit-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.edit-header h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 500;
  color: var(--el-text-color-primary);
}

.editor-box {
  width: 100%;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 4px;
}

/* 查看弹窗（对照参考系统 detail：标题大字 + 时间 + 内容 HTML） */
.notice-detail-title {
  font-size: 20px;
  margin: 0 0 8px;
}

.notice-detail-time {
  color: #909399;
  margin: 0 0 16px;
}

.notice-detail-content {
  line-height: 1.7;
  word-break: break-all;
}

.notice-detail-content :deep(img) {
  max-width: 100%;
}

/* 富文本编辑器样式 */
:deep(.w-e-text-container) {
  padding: 0;
}

:deep(.w-e-toolbar-container) {
  border-bottom: 1px solid var(--el-border-color-lighter);
}

:deep(.w-e-toolbar) {
  flex-wrap: wrap;
  line-height: 36px;
  min-height: 40px;
}

:deep(.w-e-editor-container) {
  border: none;
}

:deep(.w-e-text-area) {
  min-height: 400px;
  padding: 12px;
}
</style>
