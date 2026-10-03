<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteYuyueWorkerCategory,
  getYuyueWorkerCategoryList,
  saveYuyueWorkerCategory,
} from '../../api/yuyue'
import type { YuyueWorkerCategoryItem } from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 人员类型列表 ============
const loading = ref(false)
const tableData = ref<YuyueWorkerCategoryItem[]>([])
const selected = ref<YuyueWorkerCategoryItem[]>([])

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueWorkerCategoryList()
    tableData.value = (data?.list || data || []) as YuyueWorkerCategoryItem[]
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSelectionChange(rows: YuyueWorkerCategoryItem[]) {
  selected.value = rows
}

// ============ 平铺增改（.goods-edit + 右上×） ============
const editing = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  pic: '',
  sort: 0,
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入类型名称', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', pic: '', sort: 0, status: 1 })
  editing.value = true
}

function openEdit(row: YuyueWorkerCategoryItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    name: row.name || '',
    pic: row.pic || '',
    sort: row.sort ?? 0,
    status: row.status ?? 1,
  })
  editing.value = true
}

function closeEdit() {
  editing.value = false
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveYuyueWorkerCategory({ ...form, pid: 0 })
      ElMessage.success(isEdit.value ? '保存成功' : '添加成功')
      editing.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

// ============ 图片选择 ============
const imgPickerVisible = ref(false)
function openImgPicker() {
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls.length) form.pic = urls[0]
}

// ============ 删除 ============
function handleBatchDelete() {
  if (!selected.value.length) {
    ElMessage.warning('请先勾选要删除的人员类型')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${selected.value.length} 个人员类型吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueWorkerCategory(selected.value.map((r) => r.id))
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleDelete(row: YuyueWorkerCategoryItem) {
  ElMessageBox.confirm(`确定要删除人员类型「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueWorkerCategory([row.id])
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
  <div v-show="!editing">
    <!-- 批量按钮行（与截图一致：仅 添加/删除，无搜索框） -->
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-inner">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleBatchDelete">删除</el-button>
        </div>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="名称" min-width="160" />
        <el-table-column label="图片" width="100">
          <template #default="{ row }">
            <el-image v-if="row.pic" :src="row.pic" fit="cover" class="cat-img" :preview-src-list="[row.pic]" preview-teleported />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="序号" width="90" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'tag-green' : 'tag-gray'">{{ row.status === 1 ? '显示' : '隐藏' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="140">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 选图弹窗 -->
    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="(urls: string[]) => onImgPick(urls)" />
  </div>

  <!-- 平铺增改 .goods-edit + 右上× -->
  <div v-if="editing" class="goods-edit">
    <div class="ge-head">
      <span class="ge-title">{{ isEdit ? '编辑人员类型' : '添加人员类型' }}</span>
      <span class="ge-close" @click="closeEdit">×</span>
    </div>
    <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" label-position="right" class="ge-body">
      <el-form-item label="类型名称" prop="name">
        <el-input v-model="form.name" placeholder="请输入类型名称" style="width: 320px" />
      </el-form-item>
      <el-form-item label="类型图标">
        <div class="img-pick-row">
          <el-image v-if="form.pic" :src="form.pic" class="img-pick-thumb" fit="cover" :preview-src-list="[form.pic]" preview-teleported />
          <el-button size="small" @click="openImgPicker">{{ form.pic ? '更换图片' : '上传图片' }}</el-button>
          <span class="img-tip">建议尺寸：400×400像素</span>
        </div>
      </el-form-item>
      <el-form-item label="序号">
        <el-input-number v-model="form.sort" :min="0" :precision="0" />
        <span class="img-tip">用于排序,越大越靠前</span>
      </el-form-item>
      <el-form-item label="状态">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">隐藏</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item>
        <el-button type="success" :loading="submitting" @click="handleSubmit">提交</el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
.toolbar-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.toolbar-left { display: flex; gap: 8px; }
.toolbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; }

.el-card { border-radius: 0; }
.el-table { width: 100%; }
.el-table :deep(th) { background: #e6e6e6; color: #333; text-align: left; }
.el-table :deep(td .cell) { text-align: left; }

.cat-img { width: 40px; height: 40px; border-radius: 4px; }
.row-ops { display: flex; gap: 12px; flex-wrap: nowrap; }
.tag-green { color: #67c23a; }
.tag-gray { color: #909399; }

.goods-edit { margin: 3px; }
.ge-head { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; margin-bottom: 12px; }
.ge-title { font-size: 15px; font-weight: 600; }
.ge-close { font-size: 22px; cursor: pointer; color: #909399; line-height: 1; }
.ge-close:hover { color: #333; }
.ge-body { padding: 8px 0; }

.img-pick-row { display: flex; align-items: center; gap: 10px; }
.img-pick-thumb { width: 72px; height: 72px; border-radius: 4px; border: 1px solid #ebeef5; }
.img-tip { color: #909399; font-size: 12px; margin-left: 8px; }
</style>
