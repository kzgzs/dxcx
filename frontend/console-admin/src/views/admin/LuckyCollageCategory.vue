<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteLuckyCollageCategory,
  getLuckyCollageCategoryList,
  saveLuckyCollageCategory,
} from '../../api/luckyCollage'
import type { LuckyCollageCategoryItem } from '../../api/luckyCollage'

const loading = ref(false)
const tableData = ref<LuckyCollageCategoryItem[]>([])
const selectedIds = ref<number[]>([])

async function fetchList() {
  loading.value = true
  try {
    const data = await getLuckyCollageCategoryList()
    tableData.value = data.list || []
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

function handleSelectionChange(rows: LuckyCollageCategoryItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

// ============ 平铺新增 / 编辑 ============
const isEdit = ref(false)
const submitting = ref(false)
const form = reactive({
  id: 0,
  name: '',
  status: 1,
  sort: 0,
})

function openAdd() {
  Object.assign(form, { id: 0, name: '', status: 1, sort: 0 })
  isEdit.value = true
}

function openEdit(row: LuckyCollageCategoryItem) {
  Object.assign(form, {
    id: row.id,
    name: row.name,
    status: row.status,
    sort: row.sort,
  })
  isEdit.value = true
}

function backToList() {
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', status: 1, sort: 0 })
}

async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请输入分类名称')
    return
  }
  submitting.value = true
  try {
    await saveLuckyCollageCategory({ ...form, id: form.id || undefined })
    ElMessage.success('保存成功')
    isEdit.value = false
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  } finally {
    submitting.value = false
  }
}

function handleDelete(row: LuckyCollageCategoryItem) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLuckyCollageCategory(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function batchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先选择分类')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${selectedIds.value.length} 个分类吗？`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        for (const id of selectedIds.value) {
          await deleteLuckyCollageCategory(id)
        }
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <!-- ============ 列表视图 ============ -->
  <div v-if="!isEdit" class="lucky-category">
    <div class="page-head"><span class="head-title">商品分类</span></div>
    <div class="toolbar">
      <div class="toolbar-left">
        <el-button type="success" @click="openAdd">添加</el-button>
        <el-button :disabled="!selectedIds.length" @click="batchDelete">删除</el-button>
      </div>
    </div>

    <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
      <el-table-column type="selection" width="40" />
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column prop="name" label="名称" min-width="200" />
      <el-table-column prop="sort" label="序号" width="90" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <span :class="row.status === 1 ? 'status-on' : 'status-off'">
            {{ row.status === 1 ? '显示' : '隐藏' }}
          </span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="120">
        <template #default="{ row }">
          <div class="row-ops">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>
  </div>

  <!-- ============ 平铺编辑视图 ============ -->
  <div v-else class="cat-edit">
    <div class="edit-head">
      <span class="edit-title">{{ form.id ? '编辑商品分类' : '添加商品分类' }}</span>
      <button class="edit-close" @click="backToList">✕</button>
    </div>
    <el-form label-width="90px" label-position="right" style="max-width: 560px">
      <el-form-item label="分类名称" required>
        <el-input v-model="form.name" placeholder="请输入分类名称" style="width: 240px" />
      </el-form-item>
      <el-form-item label="序号">
        <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
        <span class="form-tip">用于排序,越大越靠前</span>
      </el-form-item>
      <el-form-item label="状态">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">隐藏</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item>
        <el-button type="success" :loading="submitting" @click="handleSubmit">提 交</el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.lucky-category { margin: 3px; background: #fff; padding: 12px 16px; }
.page-head { margin-bottom: 8px; }
.head-title { font-size: 15px; font-weight: 600; }
.toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.toolbar-left { display: flex; gap: 8px; }
.row-ops { display: flex; flex-direction: row; gap: 6px; }
.status-on { color: #21ba45; }
.status-off { color: #f56c6c; }
:deep(.el-table__header th) { background: #e6e6e6; }
:deep(.el-table .cell) { text-align: left; }

.cat-edit { background: #fff; padding: 16px 20px; margin: 3px; }
.edit-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.edit-title { font-size: 15px; font-weight: 600; }
.edit-close { border: none; background: transparent; color: #909399; font-size: 18px; cursor: pointer; }
.form-tip { color: #909399; font-size: 12px; margin-left: 8px; }
</style>
