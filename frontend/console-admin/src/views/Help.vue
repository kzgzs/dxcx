<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance } from 'element-plus'
import { deleteHelp, getHelpDetail, getHelpList, saveHelp, setHelpStatus } from '../api/help'
import type { HelpItem } from '../api/help'
import { formatTime } from '../utils/time'
// wangEditor Vue3 组件（注意：必须从 editor-for-vue 导入，而非 @wangeditor/editor 核心包）
import '@wangeditor/editor/dist/css/style.css'
import { Editor, Toolbar } from '@wangeditor/editor-for-vue'
import { VideoPlay, VideoPause } from '@element-plus/icons-vue'

// 帮助中心 / 文章管理（对照参考系统 WebHelp + web_help/index.html、edit.html）

// ============ 列表：筛选 + 排序 + 分页 ============
const loading = ref(false)
const tableData = ref<HelpItem[]>([])
const total = ref(0)
const selected = ref<HelpItem[]>([])

const filter = reactive({
  name: '',
  status: '' as '' | 0 | 1,
})
const query = reactive({
  page: 1,
  pagesize: 15,
  field: '',
  order: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getHelpList({
      page: query.page,
      pagesize: query.pagesize,
      name: filter.name || undefined,
      status: filter.status === '' ? undefined : filter.status,
      field: query.field || undefined,
      order: query.order || undefined,
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

function handleReset() {
  filter.name = ''
  filter.status = ''
  query.page = 1
  fetchList()
}

// 表头排序（参考系统 ID/阅读量/序号 列可排序）
function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  query.field = order ? prop : ''
  query.order = order === 'ascending' ? 'asc' : order === 'descending' ? 'desc' : ''
  fetchList()
}

function handleSelectionChange(rows: HelpItem[]) {
  selected.value = rows
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

// ============ 批量/行内操作（当页完成，不刷新不跳转） ============

// 取操作目标：行内传 row，批量取选中项（未选中提示「请选择数据」同参考系统）
function pickIds(row?: HelpItem): number[] | null {
  if (row) return [row.id]
  if (selected.value.length === 0) {
    ElMessage.warning('请选择数据')
    return null
  }
  return selected.value.map((item) => item.id)
}

// 删除（二次确认弹窗）
function handleDelete(row?: HelpItem) {
  const ids = pickIds(row)
  if (!ids) return
  ElMessageBox.confirm('删除后将无法恢复，确定要删除吗？', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteHelp(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 开启 / 关闭（批量或行内状态切换）
function handleSetStatus(st: 0 | 1, row?: HelpItem) {
  const ids = pickIds(row)
  if (!ids) return
  ElMessageBox.confirm(`确定要${st === 1 ? '开启' : '关闭'}吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setHelpStatus(ids, st)
        ElMessage.success('操作成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 视图切换：list 列表 / edit 编辑（铺满主区域） ============
const viewMode = ref<'list' | 'edit'>('list')
const saving = ref(false)

// ============ 编辑表单数据（铺满编辑视图） ============
const formRef = ref<FormInstance>()
const form = reactive({
  id: 0,
  name: '',
  content: '',
  readcount: 0,
  sort: 0,
  status: 1,
})

// 富文本编辑器相关
const editorRef = ref<any>(null)

// 编辑器配置
const toolbarConfig = reactive({
  placeholder: '',
})

const editorConfig = reactive({
  placeholder: '请输入文章内容',
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

function resetForm() {
  Object.assign(form, { id: 0, name: '', content: '', readcount: 0, sort: 0, status: 1 })
  editorRef.value?.destroy()
  editorRef.value = null
}

// 返回列表视图
function goBackToList() {
  resetForm()
  viewMode.value = 'list'
}

async function openAdd() {
  resetForm()
  viewMode.value = 'edit'
  // wangEditor 会在组件挂载时通过 @onCreated 初始化
}

async function openEdit(row: HelpItem) {
  resetForm()
  form.id = row.id
  form.name = row.name
  viewMode.value = 'edit'
  try {
    const detail = await getHelpDetail(row.id)
    form.name = detail.name
    form.content = detail.content || ''
    form.readcount = detail.readcount
    form.sort = detail.sort
    form.status = detail.status
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    saving.value = true
    try {
      await saveHelp({
        id: form.id,
        name: form.name,
        content: form.content,
        readcount: Number(form.readcount) || 0,
        sort: Number(form.sort) || 0,
        status: form.status,
      })
      ElMessage.success('操作成功')
      goBackToList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      saving.value = false
    }
  })
}

onMounted(fetchList)
</script>

<template>
  <el-card shadow="never">
    <!-- ============ 列表视图 ============ -->
    <div v-if="viewMode === 'list'">
      <!-- 操作按钮行（任务273：多余的删除按钮已去除；添加保留为唯一入口；行内删除保留） -->
      <div class="toolbar-btns">
        <el-button type="primary" @click="openAdd">+ 添加文章</el-button>
        <el-button @click="handleSetStatus(1)">开启</el-button>
        <el-button @click="handleSetStatus(0)">关闭</el-button>
      </div>

      <!-- 检索行（对照参考系统：标题 / 状态） -->
      <div class="toolbar-filter">
        <span class="filter-label">标题</span>
        <el-input
          v-model="filter.name"
          placeholder="请输入文章标题"
          style="width: 220px"
          clearable
          @keyup.enter="handleSearch"
        />
        <span class="filter-label">状态</span>
        <el-select v-model="filter.status" style="width: 110px" placeholder="全部">
          <el-option label="全部" value="" />
          <el-option label="开启" :value="1" />
          <el-option label="关闭" :value="0" />
        </el-select>
        <el-button class="btn-teal" @click="handleSearch">搜索</el-button>
        <el-button @click="handleReset">清空</el-button>
      </div>

      <!-- 文章列表（对照参考系统表头：ID / 文章标题 / 阅读量 / 序号 / 创建时间 / 状态 / 操作，border 竖线与通知公告统一） -->
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        border
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="60" sortable="custom" />
        <el-table-column prop="name" label="文章标题" min-width="260" show-overflow-tooltip />
        <el-table-column prop="readcount" label="阅读量" width="90" sortable="custom" />
        <el-table-column prop="sort" label="序号" width="80" sortable="custom" />
        <el-table-column label="创建时间" width="150" prop="createtime" sortable="custom">
          <template #default="{ row }">
            {{ formatTime(row.createtime) }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag
              :type="row.status === 1 ? 'success' : 'danger'"
              size="small"
              class="status-tag"
              @click="handleSetStatus(row.status === 1 ? 0 : 1, row)"
            >
              {{ row.status === 1 ? '已开启' : '已关闭' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>

        <template #empty>
          <el-empty description="暂无帮助文章" :image-size="90" />
        </template>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[15, 30, 50, 100]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </div>

    <!-- ============ 编辑视图（铺满主区域） ============ -->
    <div v-else class="edit-container">
      <!-- 顶部栏：右上角 X 关闭按钮（无圆圈无返回文字） -->
      <div class="edit-header">
        <h3>{{ form.id > 0 ? '编辑文章' : '+ 添加文章' }}</h3>
        <!-- 纯 X 关闭按钮，点击返回列表刷新 -->
        <el-button @click="goBackToList" class="close-btn">✕</el-button>
      </div>

      <!-- 表单内容（垂直排列） -->
      <el-form ref="formRef" :model="form" label-width="70px" class="edit-form">
        <el-form-item label="标题" prop="name" :rules="[{ required: true, message: '请输入文章标题', trigger: 'blur' }]">
          <el-input
            v-model="form.name"
            placeholder="请输入文章标题"
            maxlength="255"
            class="edit-title-input"
          />
        </el-form-item>

        <el-form-item label="阅读量" prop="readcount">
          <el-input-number v-model="form.readcount" :min="0" :step="1" controls-position="right" class="edit-input-number" />
        </el-form-item>

        <el-form-item label="序号" prop="sort">
          <el-input-number v-model="form.sort" :step="1" controls-position="right" class="edit-input-number" />
          <span class="form-tip">数值越大排序越靠前</span>
        </el-form-item>

        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1"><el-icon><VideoPlay /></el-icon> 开启</el-radio>
            <el-radio :value="0"><el-icon><VideoPause /></el-icon> 关闭</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="详情" prop="content">
          <div class="editor-wrapper">
            <Toolbar
              :editor="editorRef"
              :defaultConfig="toolbarConfig"
              mode="default"
              style="border-bottom: 1px solid #eaeaea"
            />
            <Editor
              v-model="form.content"
              :defaultConfig="editorConfig"
              mode="default"
              style="height: 500px; overflow-y: visible"
              @onCreated="handleCreated"
            />
          </div>
        </el-form-item>

        <!-- 提交/取消：流式排布在详情下方，不固定底部 -->
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSubmit">保存</el-button>
          <el-button @click="goBackToList">取消</el-button>
        </el-form-item>
      </el-form>
    </div>
  </el-card>
</template>

<style scoped>
.toolbar-btns {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.toolbar-filter {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.filter-label {
  font-size: 13px;
  color: var(--el-text-color-regular);
  margin-left: 6px;
}
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
.status-tag {
  cursor: pointer;
}
.form-tip {
  margin-left: 10px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

/* ============ 编辑视图样式（铺满主区域） ============ */
.edit-container {
  padding: 12px 20px 20px;
  background: #fff;
  border-radius: 4px;
}

/* 顶部栏：缩小高度与内边距，标题与关闭按钮同一水平线垂直居中 */
.edit-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  min-height: 32px;
  margin-bottom: 16px;
  padding: 2px 0 8px;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.edit-header h3 {
  margin: 0;
  font-size: 18px;
  font-weight: bold;
  line-height: 28px;
  color: var(--el-text-color-primary);
}

/* 关闭按钮：纯 X 无圆圈装饰，与标题等高垂直居中 */
.close-btn {
  height: 28px;
  margin-right: 0;
  padding: 0 4px;
  min-width: auto;
  font-size: 24px;
  line-height: 1;
  display: inline-flex;
  align-items: center;
  color: var(--el-text-color-secondary);
}

.close-btn:hover {
  background-color: var(--el-fill-color-light);
  color: var(--el-color-primary);
}

.edit-form {
  max-width: 800px;
}

.edit-title-input {
  width: 100%;
  max-width: 600px;
}

.edit-input-number {
  width: 150px;
}

.editor-wrapper {
  width: 100%;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 4px;
  overflow: visible;
}

:deep(.w-e-text-container) {
  padding: 0;
}

:deep(.w-e-toolbar-container) {
  border-bottom: 1px solid var(--el-border-color-lighter);
}

:deep(.w-e-toolbar) {
  flex-wrap: wrap;
  line-height: 36px;
}

:deep(.w-e-editor-container) {
  border: none;
}

:deep(.w-e-text-area) {
  min-height: 400px;
  padding: 12px;
}
</style>
