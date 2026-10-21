<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteLipinCategory,
  getLipinCategoryList,
  saveLipinCategory,
} from '../../api/lipin'
import type { LipinCategoryItem } from '../../api/lipin'

const loading = ref(false)
const list = ref<LipinCategoryItem[]>([])
const selected = ref<LipinCategoryItem[]>([])

function onSelectionChange(rows: LipinCategoryItem[]) {
  selected.value = rows
}

async function fetchList() {
  loading.value = true
  try {
    const res = await getLipinCategoryList()
    list.value = res.list || []
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
  name: '',
  status: 1,
  sort: 0,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', status: 1, sort: 0 })
  dialogVisible.value = true
}

function openEdit(row: LipinCategoryItem) {
  isEdit.value = true
  Object.assign(form, { id: row.id, name: row.name, status: row.status, sort: row.sort })
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveLipinCategory({
        id: form.id || undefined,
        pid: 0,
        name: form.name,
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

function handleDelete(row: LipinCategoryItem) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLipinCategory(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (selected.value.length === 0) {
    ElMessage.warning('请先勾选分类')
    return
  }
  const ids = selected.value.map((r) => r.id)
  ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个分类吗？`, '删除确认', { type: 'warning' })
    .then(async () => {
      try {
        for (const id of ids) {
          await deleteLipinCategory(id)
        }
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <!-- 工具栏一行 -->
      <div class="toolbar">
        <el-button type="success" @click="openAdd">添加</el-button>
        <el-button @click="handleBatchDelete">删除</el-button>
      </div>

      <el-table v-loading="loading" :data="list" stripe row-key="id" @selection-change="onSelectionChange">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="名称" min-width="200" />
        <el-table-column prop="sort" label="序号" width="120" />
        <el-table-column label="状态" width="120">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '显示' : '隐藏' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="160">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑礼品卡分类' : '添加礼品卡分类'" width="480px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" label-position="left">
        <el-form-item label="分类名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入分类名称" maxlength="30" />
        </el-form-item>
        <el-form-item label="序号">
          <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
          <span class="form-tip">用于排序，越大越靠前</span>
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">隐藏</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="success" :loading="submitting" @click="handleSubmit">提交</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  gap: 3px;
  margin-bottom: 8px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
