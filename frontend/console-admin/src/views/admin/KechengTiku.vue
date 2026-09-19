<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadFile } from 'element-plus'
import {
  deleteKechengTiku,
  exportKechengTiku,
  getKechengCourseList,
  getKechengTikuChapters,
  getKechengTikuList,
  importKechengTiku,
  saveKechengTiku,
  setKechengTikuStatus,
} from '../../api/kecheng'
import type { KechengCourseItem, KechengTikuItem } from '../../api/kecheng'
import { formatTime } from '../../utils/time'

const OPTION_LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G']

// ============ 筛选与列表 ============
const loading = ref(false)
const tableData = ref<KechengTikuItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(20)

const filterKcid = ref<number | undefined>(undefined)
const filterMlid = ref<number | undefined>(undefined)
const filterType = ref<string>('')
const filterStatus = ref<string>('')
const keyword = ref('')

const courses = ref<KechengCourseItem[]>([])
const filterChapters = ref<{ id: number; name: string }[]>([])

async function fetchCourses() {
  try {
    const data = await getKechengCourseList({ page: 1, pagesize: 500 })
    courses.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function fetchFilterChapters() {
  filterMlid.value = undefined
  filterChapters.value = []
  if (!filterKcid.value) return
  try {
    const data = await getKechengTikuChapters(filterKcid.value)
    filterChapters.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getKechengTikuList({
      page: page.value,
      pagesize: pagesize.value,
      kcid: filterKcid.value,
      mlid: filterMlid.value,
      keyword: keyword.value,
      type: filterType.value,
      status: filterStatus.value,
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
  page.value = 1
  fetchList()
}

// ============ 多选与批量操作 ============
const selectedRows = ref<KechengTikuItem[]>([])
const selectedIds = computed(() => selectedRows.value.map((r) => r.id))

function handleSelectionChange(rows: KechengTikuItem[]) {
  selectedRows.value = rows
}

async function handleBatchStatus(status: number) {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选题目')
    return
  }
  try {
    await setKechengTikuStatus(selectedIds.value, status)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleBatchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选题目')
    return
  }
  try {
    await ElMessageBox.confirm(`确认删除选中的 ${selectedIds.value.length} 道题目？`, '提示', {
      type: 'warning',
    })
    await deleteKechengTiku(selectedIds.value)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 取消或错误提示已由拦截器弹出
  }
}

// ============ 编辑弹窗 ============
interface OptionRow {
  value: string
  key: number
}

const dialogVisible = ref(false)
const submitting = ref(false)

const form = reactive({
  id: 0,
  kcid: undefined as number | undefined,
  mlid: undefined as number | undefined,
  type: 1,
  title: '',
  options: [] as OptionRow[],
  right_option: '',
  score: 10,
  give_score: 0,
  jiexi: '',
  status: 1,
})

const editChapters = ref<{ id: number; name: string }[]>([])

async function fetchEditChapters() {
  editChapters.value = []
  form.mlid = undefined
  if (!form.kcid) return
  try {
    const data = await getKechengTikuChapters(form.kcid)
    editChapters.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function resetForm() {
  form.id = 0
  form.kcid = filterKcid.value
  form.mlid = undefined
  form.type = 1
  form.title = ''
  form.options = [
    { value: '', key: 0 },
    { value: '', key: 0 },
    { value: '', key: 0 },
    { value: '', key: 0 },
  ]
  form.right_option = ''
  form.score = 10
  form.give_score = 0
  form.jiexi = ''
  form.status = 1
}

async function openAdd() {
  resetForm()
  dialogVisible.value = true
  await fetchEditChapters()
  if (filterMlid.value) form.mlid = filterMlid.value
}

async function openEdit(row: KechengTikuItem) {
  resetForm()
  form.id = row.id
  form.kcid = row.kcid
  form.mlid = row.mlid || undefined
  form.type = row.type
  form.title = row.title
  form.score = Number(row.score)
  form.give_score = Number(row.give_score || 0)
  form.jiexi = row.jiexi
  form.status = row.status

  if (row.type === 1) {
    const opts = (row.options || []).map((v, i) => ({
      value: v,
      key: Array.isArray(row.right_option) && row.right_option.includes(i) ? 1 : 0,
    }))
    while (opts.length < 4) opts.push({ value: '', key: 0 })
    form.options = opts
  } else {
    form.right_option = String(row.right_option ?? '')
  }

  dialogVisible.value = true
  await fetchEditChapters()
  form.mlid = row.mlid || undefined
}

function addOption() {
  if (form.options.length >= 7) {
    ElMessage.warning('最多 7 个选项')
    return
  }
  form.options.push({ value: '', key: 0 })
}

function removeOption(index: number) {
  if (form.options.length <= 2) {
    ElMessage.warning('至少保留两个选项')
    return
  }
  form.options.splice(index, 1)
}

function toggleRight(index: number) {
  // 单选题：互斥；多选题：允许多个（由题目类型自动判定：正确答案数量>1 即多选）
  form.options[index].key = form.options[index].key === 1 ? 0 : 1
}

async function handleSave() {
  if (!form.kcid) {
    ElMessage.warning('请选择所属课程')
    return
  }
  if (!form.title.trim()) {
    ElMessage.warning('请填写题目')
    return
  }

  submitting.value = true
  try {
    await saveKechengTiku({
      id: form.id || undefined,
      kcid: form.kcid,
      mlid: form.mlid || 0,
      type: form.type,
      title: form.title,
      options: form.type === 1 ? form.options : undefined,
      right_option: form.type === 2 ? form.right_option : undefined,
      score: form.score,
      give_score: form.give_score,
      jiexi: form.jiexi,
      status: form.status,
    })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

async function handleDelete(row: KechengTikuItem) {
  try {
    await ElMessageBox.confirm('确认删除该题目？', '提示', { type: 'warning' })
    await deleteKechengTiku([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 取消或错误提示已由拦截器弹出
  }
}

async function handleToggleStatus(row: KechengTikuItem) {
  try {
    await setKechengTikuStatus([row.id], row.status === 1 ? 0 : 1)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 导入 / 导出 ============
const importVisible = ref(false)
const importFile = ref<File | null>(null)
const importing = ref(false)

function handleImportFileChange(file: UploadFile) {
  importFile.value = file.raw || null
}

async function submitImport() {
  if (!importFile.value) {
    ElMessage.warning('请上传表格文件')
    return
  }
  importing.value = true
  try {
    const fd = new FormData()
    fd.append('file', importFile.value)
    const result = await importKechengTiku(fd)
    ElMessage.success(`成功导入 ${result.success} 条数据`)
    if (result.errors?.length) {
      ElMessageBox.alert(result.errors.join('<br/>'), '部分数据未导入', {
        dangerouslyUseHTMLString: true,
      })
    }
    importVisible.value = false
    importFile.value = null
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    importing.value = false
  }
}

const exporting = ref(false)

function downloadBlob(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = fileName
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

async function handleExport() {
  exporting.value = true
  try {
    const blob = await exportKechengTiku({ kcid: filterKcid.value, mlid: filterMlid.value })
    downloadBlob(blob, `题库导出_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    exporting.value = false
  }
}

onMounted(() => {
  fetchCourses()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="课程">
          <el-select
            v-model="filterKcid"
            placeholder="全部课程"
            clearable
            filterable
            style="width: 200px"
            @change="fetchFilterChapters"
          >
            <el-option v-for="c in courses" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="章节">
          <el-select
            v-model="filterMlid"
            placeholder="全部章节"
            clearable
            filterable
            style="width: 180px"
            :disabled="!filterKcid"
          >
            <el-option v-for="c in filterChapters" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="题型">
          <el-select v-model="filterType" placeholder="全部" clearable style="width: 110px">
            <el-option label="选择题" value="1" />
            <el-option label="填空题" value="2" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="filterStatus" placeholder="全部" clearable style="width: 100px">
            <el-option label="启用" value="1" />
            <el-option label="停用" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="关键词">
          <el-input
            v-model="keyword"
            placeholder="题目关键词"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">查询</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <div class="btn-row">
        <el-button type="primary" @click="openAdd">新增题目</el-button>
        <el-button @click="importVisible = true">Excel 导入</el-button>
        <el-button :loading="exporting" @click="handleExport">导出</el-button>
        <el-button @click="handleBatchStatus(1)">批量启用</el-button>
        <el-button @click="handleBatchStatus(0)">批量停用</el-button>
        <el-button type="danger" plain @click="handleBatchDelete">批量删除</el-button>
      </div>

      <el-table v-loading="loading" :data="tableData" stripe @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="title" label="题目" min-width="220" show-overflow-tooltip />
        <el-table-column prop="kcname" label="课程" min-width="140" show-overflow-tooltip />
        <el-table-column prop="mlname" label="章节" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.mlname || '-' }}</template>
        </el-table-column>
        <el-table-column prop="tname" label="题型" width="80" />
        <el-table-column label="正确答案" width="130">
          <template #default="{ row }">
            {{ row.type === 1 ? row.right_option_text : row.right_option }}
          </template>
        </el-table-column>
        <el-table-column prop="score" label="分值" width="80" />
        <el-table-column label="送积分" width="80">
          <template #default="{ row }">{{ row.give_score || 0 }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="170" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="warning" @click="handleToggleStatus(row)">
              {{ row.status === 1 ? '停用' : '启用' }}
            </el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager"
        v-model:current-page="page"
        v-model:page-size="pagesize"
        :total="total"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </el-card>

    <!-- 新增/编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑题目' : '新增题目'" width="680px" destroy-on-close>
      <el-form label-width="90px">
        <el-form-item label="所属课程" required>
          <el-select
            v-model="form.kcid"
            placeholder="请选择课程"
            filterable
            style="width: 260px"
            @change="fetchEditChapters"
          >
            <el-option v-for="c in courses" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="所属章节">
          <el-select
            v-model="form.mlid"
            placeholder="不限制章节（整卷出题）"
            clearable
            filterable
            style="width: 260px"
            :disabled="!form.kcid"
          >
            <el-option v-for="c in editChapters" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="题型" required>
          <el-radio-group v-model="form.type">
            <el-radio :value="1">选择题</el-radio>
            <el-radio :value="2">填空题</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="题目" required>
          <el-input v-model="form.title" type="textarea" :rows="2" placeholder="请输入题目内容" />
        </el-form-item>
        <template v-if="form.type === 1">
          <el-form-item
            v-for="(opt, index) in form.options"
            :key="index"
            :label="`选项 ${OPTION_LETTERS[index]}`"
          >
            <div class="option-row">
              <el-input v-model="opt.value" placeholder="选项内容（留空则忽略）" />
              <el-checkbox
                :model-value="opt.key === 1"
                @change="toggleRight(index)"
                class="option-right"
              >
                正确答案
              </el-checkbox>
              <el-button link type="danger" @click="removeOption(index)">删除</el-button>
            </div>
          </el-form-item>
          <el-form-item>
            <el-button link type="primary" @click="addOption">+ 添加选项</el-button>
            <span class="form-tip">勾选多个正确答案即为多选题</span>
          </el-form-item>
        </template>
        <template v-else>
          <el-form-item label="答案" required>
            <el-input v-model="form.right_option" placeholder="请输入填空题答案" />
          </el-form-item>
        </template>
        <el-form-item label="分值" required>
          <el-input-number v-model="form.score" :min="1" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="答对送积分">
          <el-input-number v-model="form.give_score" :min="0" :precision="0" :step="1" />
          <span class="form-tip">答对本题赠送积分（0 不赠送）</span>
        </el-form-item>
        <el-form-item label="解析">
          <el-input v-model="form.jiexi" type="textarea" :rows="2" placeholder="答案解析（可选）" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- Excel 导入弹窗 -->
    <el-dialog v-model="importVisible" title="Excel 批量导入题库" width="560px" destroy-on-close>
      <div class="import-tip">
        <p>支持 xlsx/xls/csv，两种格式（自动识别）：</p>
        <p>1. 课程id / 题目 / 选项A / 选项B / 选项C / 选项D / 正确答案 / 分值 / 解析</p>
        <p>2. 课程id / 章节id / 题型 / 题目 / 选项A / 选项B / 选项C / 选项D / 正确答案 / 分值 / 解析</p>
        <p>正确答案支持字母（如 A 或 A,B）或选项下标（如 0 或 0,1）</p>
      </div>
      <el-upload
        drag
        :auto-upload="false"
        :limit="1"
        accept=".xlsx,.xls,.csv"
        @change="handleImportFileChange"
      >
        <div class="el-upload__text">将文件拖到此处，或<em>点击上传</em></div>
      </el-upload>
      <template #footer>
        <el-button @click="importVisible = false">取消</el-button>
        <el-button type="primary" :loading="importing" @click="submitImport">开始导入</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.btn-row {
  margin-bottom: 14px;
  display: flex;
  gap: 8px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.option-row {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
}

.option-right {
  white-space: nowrap;
}

.form-tip {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}

.import-tip {
  margin-bottom: 12px;
  color: #606266;
  font-size: 13px;
  line-height: 1.8;
}

.import-tip p {
  margin: 0;
}
</style>
