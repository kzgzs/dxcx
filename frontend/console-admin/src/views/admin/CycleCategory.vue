<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance } from 'element-plus'
import {
  getCycleCategoryList,
  saveCycleCategory,
  deleteCycleCategory,
} from '../../api/cycle'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

interface CateRow {
  id: number
  name: string
  pic?: string
  sort?: number
  status: number
}

/* ================= 列表 ================= */
const loading = ref(false)
const tableData = ref<CateRow[]>([])
const selectedRows = ref<CateRow[]>([])

async function fetchList() {
  loading.value = true
  try {
    const data = await getCycleCategoryList()
    tableData.value = (data.list || []) as CateRow[]
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

function handleSelectionChange(rows: CateRow[]) {
  selectedRows.value = rows
}

function handleDelete(row: CateRow) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCycleCategory(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (!selectedRows.value.length) return
  ElMessageBox.confirm(`确定要删除选中的 ${selectedRows.value.length} 个分类吗？删除后不可恢复。`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        // 逐个删除（后端已有商品时拒绝）
        for (const r of selectedRows.value) {
          await deleteCycleCategory(r.id)
        }
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示（可能有商品占用） */
        fetchList()
      }
    })
    .catch(() => {})
}

/* ================= 编辑（平铺整页 + 右上X） ================= */
const viewMode = ref<'list' | 'edit'>('list')
const isEdit = ref(false)
const submitting = ref(false)
const formRef = ref<FormInstance>()

const form = reactive({
  id: 0,
  name: '',
  pic: '',
  sort: 0,
  status: 1,
})

function resetForm() {
  form.id = 0
  form.name = ''
  form.pic = ''
  form.sort = 0
  form.status = 1
}

function openAdd() {
  isEdit.value = false
  resetForm()
  viewMode.value = 'edit'
}

function openEdit(row: CateRow) {
  isEdit.value = true
  form.id = row.id
  form.name = row.name
  form.pic = row.pic || ''
  form.sort = Number(row.sort ?? 0)
  form.status = row.status === 1 ? 1 : 0
  viewMode.value = 'edit'
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

async function submitForm() {
  await formRef.value?.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveCycleCategory({
        id: form.id || undefined,
        name: form.name,
        pic: form.pic,
        sort: Number(form.sort) || 0,
        status: form.status,
      })
      ElMessage.success('保存成功')
      backToList()
    } catch {
      /* 拦截器统一提示 */
    } finally {
      submitting.value = false
    }
  })
}

/* ================= 选图 ================= */
const imgPickerVisible = ref(false)
function onPick(urls: string[]) {
  if (urls[0]) form.pic = urls[0]
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <!-- 列表 -->
  <div v-if="viewMode === 'list'">
    <el-card shadow="never" class="head-card">
      <div class="head-row">
        <span class="head-title">商品分类</span>
      </div>
      <div class="toolbar-left">
        <el-button type="success" @click="openAdd">添加</el-button>
        <el-button @click="handleBatchDelete">删除</el-button>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" align="center" />
        <el-table-column prop="id" label="ID" width="90" align="center" />
        <el-table-column prop="name" label="名称" min-width="220" />
        <el-table-column label="图片" width="140" align="center">
          <template #default="{ row }">
            <el-image v-if="row.pic" :src="row.pic" fit="cover" class="cate-img" />
            <span v-else class="cate-img-empty">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="序号" width="100" align="center" />
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '显示' : '隐藏' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" align="center">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onPick" />
  </div>

  <!-- 平铺编辑主区域 + 右上X -->
  <div v-else class="cate-edit">
    <div class="ge-head">
      <div class="ge-head-title">{{ isEdit ? '编辑商品分类' : '+ 添加商品分类' }}</div>
      <div class="ge-head-close" @click="backToList">
        <svg viewBox="0 0 1024 1024" width="18" height="18" fill="currentColor">
          <path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" />
        </svg>
      </div>
    </div>

    <el-form ref="formRef" :model="form" :rules="{ name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }] }" label-width="100px" class="ge-form">
      <el-form-item label="分类名称" required>
        <el-input v-model="form.name" style="width: 320px" maxlength="60" />
      </el-form-item>

      <el-form-item label="分类图标">
        <el-button @click="imgPickerVisible = true">上传图片</el-button>
        <span class="ge-tip">建议尺寸：400×400像素</span>
        <div v-if="form.pic" class="ge-upload-box">
          <el-image :src="form.pic" fit="cover" class="main-pic" />
          <span class="ge-upload-del" @click="form.pic = ''">×</span>
        </div>
        <div v-else class="ge-upload-box"><div class="ge-upload-empty"></div></div>
      </el-form-item>

      <el-form-item label="序号">
        <el-input-number v-model="form.sort" :min="0" :precision="0" :controls="false" style="width: 200px" />
        <span class="ge-tip">用于排序，越大越靠前</span>
      </el-form-item>

      <el-form-item label="状态">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">隐藏</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item>
        <el-button type="success" :loading="submitting" @click="submitForm">提交</el-button>
      </el-form-item>
    </el-form>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onPick" />
  </div>
</template>

<style scoped>
.head-card { margin-bottom: 3px; }
.head-card :deep(.el-card__body) { padding: 12px 16px; }
.head-row { margin-bottom: 10px; }
.head-title { font-size: 15px; font-weight: 600; color: #303133; }
.toolbar-left { display: flex; gap: 8px; }
.toolbar-left .el-button { margin: 0; }

.cate-img { width: 50px; height: 50px; border-radius: 4px; border: 1px solid #ebeef5; }
.cate-img-empty { color: #c0c4cc; }
.status-on { color: #21ba45; }
.status-off { color: #f56c6c; }

.cate-edit { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.ge-head-title { font-size: 16px; font-weight: 600; color: #303133; flex: 1; }
.ge-head-close { cursor: pointer; color: #909399; display: flex; align-items: center; }
.ge-head-close:hover { color: #409eff; }
.ge-form :deep(.el-form-item) { margin-bottom: 18px; }
.ge-tip { color: #909399; font-size: 12px; margin-left: 8px; }
.ge-upload-box { margin-top: 8px; }
.ge-upload-empty { width: 100px; height: 100px; border: 1px solid #e4e7ed; background: #fafafa; }
.main-pic { width: 100px; height: 100px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }
.ge-upload-del { position: relative; margin-left: 6px; color: #f56c6c; cursor: pointer; font-size: 16px; }
</style>
