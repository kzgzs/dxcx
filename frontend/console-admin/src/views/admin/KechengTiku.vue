<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteKechengTiku,
  getKechengCourseList,
  getKechengTikuList,
  saveKechengTiku,
  setKechengTikuStatus,
} from '../../api/kecheng'
import type { KechengCourseItem, KechengTikuItem } from '../../api/kecheng'
import { formatTime } from '../../utils/time'

const OPTION_LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G']

// ============ 跨片契约 ============
const props = defineProps<{ embeddedProid?: number | string }>()
const emit = defineEmits<{ (e: 'back'): void }>()
const embedded = computed(
  () => props.embeddedProid !== undefined && props.embeddedProid !== null && props.embeddedProid !== '',
)
const embeddedKcid = computed(() => (embedded.value ? Number(props.embeddedProid) : 0))

// ============ 筛选与列表 ============
const loading = ref(false)
const tableData = ref<KechengTikuItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(20)

const filterKcid = ref<number | undefined>(undefined)
const filterStatus = ref('')
const keyword = ref('')

const courses = ref<KechengCourseItem[]>([])
// 嵌入模式也需拉全量课程，供「所属课程」下拉改选其它课程（默认仍选中当前嵌入课程）
async function fetchCourses() {
  try {
    const data = await getKechengCourseList({ page: 1, pagesize: 500 })
    courses.value = data.list || []
  } catch {
    /* 拦截器已提示 */
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getKechengTikuList({
      page: page.value,
      pagesize: pagesize.value,
      kcid: embedded.value ? embeddedKcid.value : filterKcid.value,
      keyword: embedded.value ? undefined : keyword.value || undefined,
      status: embedded.value ? undefined : filterStatus.value || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  page.value = 1
  fetchList()
}

// ============ 多选与批量 ============
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
    /* 拦截器已提示 */
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
    /* 取消或拦截器已提示 */
  }
}

async function handleToggleStatus(row: KechengTikuItem) {
  try {
    await setKechengTikuStatus([row.id], row.status === 1 ? 0 : 1)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    /* 拦截器已提示 */
  }
}

async function handleDelete(row: KechengTikuItem) {
  try {
    await ElMessageBox.confirm('确认删除该题目？', '提示', { type: 'warning' })
    await deleteKechengTiku([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    /* 取消或拦截器已提示 */
  }
}

// ============ 平铺增改面板 ============
interface OptionRow {
  value: string
  key: number
}

const editing = ref(false)
const submitting = ref(false)
const form = reactive({
  id: 0,
  kcid: 0 as number | undefined,
  type: 1,
  title: '',
  options: [] as OptionRow[],
  right_option: '',
  score: 10,
  jiexi: '',
  status: 1,
})

function resetForm() {
  form.id = 0
  form.kcid = embedded.value ? embeddedKcid.value : filterKcid.value
  form.type = 1
  form.title = ''
  form.options = [
    { value: '', key: 0 },
    { value: '', key: 0 },
    { value: '', key: 0 },
  ]
  form.right_option = ''
  form.score = 10
  form.jiexi = ''
  form.status = 1
}

function openAdd() {
  resetForm()
  editing.value = true
}

function openEdit(row: KechengTikuItem) {
  resetForm()
  form.id = row.id
  form.kcid = row.kcid
  form.type = row.type
  form.title = row.title
  form.score = Number(row.score)
  form.jiexi = row.jiexi
  form.status = row.status

  if (row.type === 1) {
    const opts = (row.options || []).map((v, i) => ({
      value: v,
      key: Array.isArray(row.right_option) && row.right_option.includes(i) ? 1 : 0,
    }))
    while (opts.length < 2) opts.push({ value: '', key: 0 })
    form.options = opts
  } else {
    form.right_option = String(row.right_option ?? '')
  }
  editing.value = true
}

function closeEdit() {
  editing.value = false
}

function addOption() {
  if (form.options.length >= 7) {
    ElMessage.warning('最多 7 个选项')
    return
  }
  form.options.push({ value: '', key: 0 })
}

function removeOption(index: number) {
  form.options.splice(index, 1)
}

function toggleRight(index: number) {
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
      mlid: 0,
      type: form.type,
      title: form.title,
      options: form.type === 1 ? form.options : undefined,
      right_option: form.type === 2 ? form.right_option : undefined,
      score: form.score,
      jiexi: form.jiexi,
      status: form.status,
    })
    ElMessage.success('保存成功')
    editing.value = false
    fetchList()
  } catch {
    /* 拦截器已提示 */
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  fetchCourses()
  fetchList()
})
</script>

<template>
  <div>
    <div v-if="embedded" class="back-bar" @click="emit('back')">← 返回课程列表</div>

    <!-- 平铺增改面板 -->
    <el-card v-if="editing" shadow="never" class="edit-card">
      <div class="edit-head">
        <span class="edit-title">✎ {{ form.id ? '编辑题库内容' : '添加题库内容' }}</span>
        <span class="edit-close" @click="closeEdit">✕</span>
      </div>
      <el-form label-width="80px" label-position="right" class="edit-form">
        <el-form-item label="题目">
          <el-input v-model="form.title" style="max-width: 420px" />
        </el-form-item>
        <el-form-item label="所属课程">
          <el-select v-model="form.kcid" placeholder="-请选择-" style="max-width: 320px">
            <el-option v-for="c in courses" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="题目类型">
          <el-radio-group v-model="form.type">
            <el-radio :value="1">选择题</el-radio>
            <el-radio :value="2">填空题</el-radio>
          </el-radio-group>
        </el-form-item>

        <template v-if="form.type === 1">
          <el-form-item label="选项">
            <div class="opt-list">
              <div v-for="(opt, index) in form.options" :key="index" class="opt-row">
                <span
                  class="opt-badge"
                  :class="{ right: opt.key === 1 }"
                  @click="toggleRight(index)"
                >
                  {{ OPTION_LETTERS[index] }}<span v-if="opt.key === 1">✓</span>
                </span>
                <el-input v-model="opt.value" style="width: 280px" />
                <el-button v-if="index === 0" @click="addOption">添加</el-button>
                <el-button v-else @click="removeOption(index)">删除</el-button>
              </div>
            </div>
          </el-form-item>
        </template>
        <template v-else>
          <el-form-item label="正确答案">
            <el-input v-model="form.right_option" style="max-width: 320px" placeholder="请输入答案" />
          </el-form-item>
        </template>

        <el-form-item label="分数">
          <el-input v-model="form.score" style="width: 120px" />
          <span class="form-tip">分，答对此题获得对应分数</span>
        </el-form-item>
        <el-form-item label="题目解析">
          <el-input v-model="form.jiexi" type="textarea" :rows="4" style="max-width: 420px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">已开启</el-radio>
            <el-radio :value="0">未开启</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="handleSave">提交</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <template v-else>
      <el-card shadow="never">
        <div class="toolbar-row">
          <div class="tb-left">
            <el-button type="primary" @click="openAdd">添加</el-button>
            <el-button @click="handleBatchDelete">删除</el-button>
            <el-button @click="handleBatchStatus(1)">上架</el-button>
            <el-button @click="handleBatchStatus(0)">下架</el-button>
          </div>
          <div class="tb-right">
            <template v-if="!embedded">
              <span class="tb-label">标题</span>
              <el-input v-model="keyword" style="width: 160px" @keyup.enter="handleSearch" />
              <span class="tb-label">状态</span>
              <el-select v-model="filterStatus" placeholder="全部" clearable style="width: 100px" @change="handleSearch">
                <el-option label="已开启" value="1" />
                <el-option label="未开启" value="0" />
              </el-select>
            </template>
            <el-button type="primary" @click="handleSearch">🔍</el-button>
          </div>
        </div>

        <el-table v-loading="loading" :data="tableData" @selection-change="handleSelectionChange">
          <el-table-column type="selection" width="40" />
          <el-table-column prop="id" label="ID" width="70" sortable />
          <el-table-column prop="kcname" label="所属课程" min-width="140" show-overflow-tooltip />
          <el-table-column prop="title" label="题目" min-width="180" show-overflow-tooltip />
          <el-table-column label="正确答案" width="110">
            <template #default="{ row }">
              {{ row.type === 1 ? row.right_option_text : row.right_option }}
            </template>
          </el-table-column>
          <el-table-column prop="tname" label="题目类型" width="90" />
          <el-table-column prop="score" label="分数" width="70" />
          <el-table-column label="创建时间" width="150">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <span :class="row.status === 1 ? 'st-on' : 'st-off'">
                {{ row.status === 1 ? '已开启' : '已关闭' }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="160">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="primary" @click="handleDelete(row)">删除</el-button>
                <el-button link type="primary" @click="handleToggleStatus(row)">
                  {{ row.status === 1 ? '关闭' : '开启' }}
                </el-button>
              </div>
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
    </template>
  </div>
</template>

<style scoped>
.back-bar {
  cursor: pointer;
  color: #409eff;
  font-size: 14px;
  padding: 6px 2px;
  margin-bottom: 3px;
}

.back-bar:hover {
  text-decoration: underline;
}

.toolbar-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: nowrap;
  gap: 8px;
}

.tb-left,
.tb-right {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: nowrap;
}

.tb-label {
  font-size: 13px;
  color: #606266;
  white-space: nowrap;
}

.pager {
  margin-top: 6px;
  justify-content: flex-end;
}

.st-on {
  color: #67c23a;
}

.st-off {
  color: #f56c6c;
}

.edit-card {
  position: relative;
}

.edit-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 8px;
  border-bottom: 1px solid #ebeef5;
  margin-bottom: 8px;
}

.edit-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.edit-close {
  cursor: pointer;
  font-size: 18px;
  color: #909399;
}

.edit-close:hover {
  color: #f56c6c;
}

.edit-form :deep(.el-form-item) {
  margin-bottom: 14px;
}

.opt-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.opt-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.opt-badge {
  width: 26px;
  height: 26px;
  line-height: 24px;
  text-align: center;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  font-size: 13px;
  color: #606266;
  cursor: pointer;
  background: #f5f7fa;
}

.opt-badge.right {
  border-color: #67c23a;
  background: #f0f9eb;
  color: #67c23a;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
