<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteTuangouCategory,
  getTuangouCategoryList,
  saveTuangouCategory,
} from '../../api/tuangou'
import type { TuangouCategoryItem } from '../../api/tuangou'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 列表 ============
const loading = ref(false)
const categories = ref<TuangouCategoryItem[]>([])

// 两级树形展示：一级分类在前，其子分类紧随其后缩进显示
const tableData = computed(() => {
  const tops = categories.value.filter((c) => c.pid === 0)
  const rows: Array<TuangouCategoryItem & { level: number }> = []
  tops.forEach((top) => {
    rows.push({ ...top, level: 0 })
    categories.value
      .filter((c) => c.pid === top.id)
      .forEach((child) => rows.push({ ...child, level: 1 }))
  })
  // 未挂到任何一级下的脏数据兜底显示
  categories.value
    .filter((c) => c.pid !== 0 && !tops.some((t) => t.id === c.pid))
    .forEach((c) => rows.push({ ...c, level: 0 }))
  return rows
})

async function fetchList() {
  loading.value = true
  try {
    categories.value = await getTuangouCategoryList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  pid: 0,
  name: '',
  pic: '',
  status: 1,
  sort: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
}

const topCategories = computed(() => categories.value.filter((c) => c.pid === 0))

function resetForm() {
  Object.assign(form, { id: 0, pid: 0, name: '', pic: '', status: 1, sort: 1 })
}

function openAdd(pid = 0) {
  isEdit.value = false
  resetForm()
  form.pid = pid
  dialogVisible.value = true
}

function openEdit(row: TuangouCategoryItem) {
  isEdit.value = true
  resetForm()
  Object.assign(form, {
    id: row.id,
    pid: row.pid,
    name: row.name,
    pic: row.pic,
    status: row.status,
    sort: row.sort,
  })
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveTuangouCategory({
        id: form.id || undefined,
        pid: form.pid,
        name: form.name,
        pic: form.pic,
        status: form.status,
        sort: form.sort,
      })
      ElMessage.success('保存成功')
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

// ============ 删除 ============
function handleDelete(row: TuangouCategoryItem) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteTuangouCategory(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.pic = urls[0]
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>团购商品分类（两级）</span>
          <el-button type="success" @click="openAdd(0)">新增一级分类</el-button>
        </div>
      </template>

      <el-table v-loading="loading" :data="tableData" stripe row-key="id">
        <el-table-column label="分类名称" min-width="220">
          <template #default="{ row }">
            <span :style="{ paddingLeft: row.level * 24 + 'px' }">
              <span v-if="row.level > 0" class="text-muted">└ </span>
              {{ row.name }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="图标" width="90">
          <template #default="{ row }">
            <el-image
              v-if="row.pic"
              :src="row.pic"
              fit="cover"
              style="width: 32px; height: 32px; border-radius: 4px"
            />
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '显示' : '隐藏' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="240" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.level === 0" link type="primary" @click="openAdd(row.id)">
              加子分类
            </el-button>
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑分类' : '新增分类'"
      width="480px"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="上级分类">
          <el-select v-model="form.pid" placeholder="一级分类" clearable style="width: 100%">
            <el-option
              v-for="c in topCategories"
              :key="c.id"
              :label="c.name"
              :value="c.id"
              :disabled="c.id === form.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="分类名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入分类名称" />
        </el-form-item>
        <el-form-item label="图标">
          <div class="img-pick-row">
            <el-image v-if="form.pic" :src="form.pic" class="img-pick-thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker">{{ form.pic ? '更换' : '选择图片' }}</el-button>
            <el-button v-if="form.pic" size="small" link type="danger" @click="form.pic = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">隐藏后 C 端不显示</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 选图弹窗（任务294） -->
    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.text-muted {
  color: #909399;
  font-size: 12px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.img-pick-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.img-pick-thumb {
  width: 72px;
  height: 72px;
  border-radius: 6px;
  border: 1px solid #ebeef5;
}
</style>
