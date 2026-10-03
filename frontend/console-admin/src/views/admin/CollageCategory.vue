<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Close } from '@element-plus/icons-vue'
import {
  deleteCollageCategory,
  getCollageCategoryList,
  saveCollageCategory,
} from '../../api/collageCategory'
import type { CollageCategoryItem } from '../../api/collageCategory'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const tableData = ref<CollageCategoryItem[]>([])
const selectedIds = ref<number[]>([])

async function fetchList() {
  loading.value = true
  try {
    const data = await getCollageCategoryList()
    tableData.value = data.list || []
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

function handleSelectionChange(rows: CollageCategoryItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

// ============ 平铺编辑表单（添加/编辑共用） ============
const editing = ref(false)
const submitting = ref(false)
const form = reactive({
  id: 0,
  name: '',
  pic: '',
  status: 1,
  sort: 0,
})

function openAdd() {
  Object.assign(form, { id: 0, name: '', pic: '', status: 1, sort: 0 })
  editing.value = true
}

function openEdit(row: CollageCategoryItem) {
  Object.assign(form, {
    id: row.id,
    name: row.name,
    pic: row.pic,
    status: row.status,
    sort: row.sort,
  })
  editing.value = true
}

function closeEdit() {
  editing.value = false
}

async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请输入分类名称')
    return
  }
  submitting.value = true
  try {
    await saveCollageCategory({
      id: form.id || undefined,
      name: form.name,
      pic: form.pic,
      status: form.status,
      sort: form.sort,
    })
    ElMessage.success('保存成功')
    editing.value = false
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  } finally {
    submitting.value = false
  }
}

// ============ 删除 ============
function handleDelete(row: CollageCategoryItem) {
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCollageCategory(row.id)
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
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        for (const id of selectedIds.value) {
          await deleteCollageCategory(id)
        }
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

// ============ 选图 ============
const imgPickerVisible = ref(false)
function openImgPicker() {
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) form.pic = urls[0]
}

onMounted(fetchList)
</script>

<template>
  <div class="collage-category">
    <!-- 列表视图 -->
    <el-card v-if="!editing" shadow="never">
      <div class="page-title">商品分类</div>
      <div class="toolbar">
        <el-button type="success" size="small" @click="openAdd">添加</el-button>
        <el-button size="small" @click="batchDelete">删除</el-button>
      </div>

      <el-table
        v-loading="loading"
        :data="tableData"
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="名称" min-width="200" />
        <el-table-column label="图片" width="100">
          <template #default="{ row }">
            <el-image v-if="row.pic" :src="row.pic" fit="cover" class="cat-thumb" />
            <span v-else class="cell-sub">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="序号" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :style="{ color: row.status === 1 ? 'green' : 'red' }">
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
    </el-card>

    <!-- 平铺编辑表单 -->
    <el-card v-else shadow="never" class="edit-card">
      <div class="edit-head">
        <span class="edit-title">{{ form.id ? '编辑商品分类' : '添加商品分类' }}</span>
        <el-button link @click="closeEdit">
          <el-icon :size="18"><Close /></el-icon>
        </el-button>
      </div>
      <el-form label-width="100px" label-position="left" class="edit-form">
        <el-form-item label="分类名称">
          <el-input v-model="form.name" placeholder="请输入分类名称" style="width: 220px" />
        </el-form-item>
        <el-form-item label="分类图标">
          <div class="pic-row">
            <el-button size="small" @click="openImgPicker">上传图片</el-button>
            <span class="form-tip">建议尺寸：400×400像素</span>
          </div>
          <el-image v-if="form.pic" :src="form.pic" fit="cover" class="cat-thumb" style="margin-top: 8px" />
        </el-form-item>
        <el-form-item label="序号">
          <el-input v-model.number="form.sort" type="number" style="width: 220px" />
          <span class="form-tip">用于排序,越大越靠前</span>
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
    </el-card>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.collage-category {
  margin: 3px;
}
.page-title {
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 12px;
  color: #303133;
}
.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}
.cat-thumb {
  width: 50px;
  height: 50px;
  border-radius: 4px;
}
.cell-sub {
  color: #909399;
}
.row-ops {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 4px;
}
.edit-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.edit-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.edit-form {
  max-width: 600px;
}
.pic-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.form-tip {
  color: #909399;
  font-size: 12px;
}
:deep(.el-table__header th) {
  background: #e6e6e6;
}
:deep(.el-table .cell) {
  text-align: left;
}
</style>
