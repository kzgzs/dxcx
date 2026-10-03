<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteTuangouCategory,
  getTuangouCategoryList,
  saveTuangouCategory,
} from '../../api/tuangou'
import type { TuangouCategoryItem } from '../../api/tuangou'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const list = ref<TuangouCategoryItem[]>([])
const viewMode = ref<'list' | 'edit'>('list')
const isEdit = ref(false)
const submitting = ref(false)

const form = reactive({ id: 0, name: '', pic: '', sort: 0, status: 1 })

async function fetchList() {
  loading.value = true
  try { list.value = await getTuangouCategoryList() } catch { /* 拦截器 */ } finally { loading.value = false }
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', pic: '', sort: 0, status: 1 })
  viewMode.value = 'edit'
}
function openEdit(row: TuangouCategoryItem) {
  isEdit.value = true
  Object.assign(form, { id: row.id, name: row.name, pic: row.pic, sort: row.sort, status: row.status })
  viewMode.value = 'edit'
}
function back() { viewMode.value = 'list'; fetchList() }

async function submit() {
  if (!form.name.trim()) { ElMessage.warning('请输入分类名称'); return }
  submitting.value = true
  try {
    await saveTuangouCategory({ id: form.id || undefined, name: form.name, pic: form.pic, sort: form.sort, status: form.status })
    ElMessage.success('保存成功'); back()
  } catch { /* 拦截器 */ } finally { submitting.value = false }
}

function handleDelete(row: TuangouCategoryItem) {
  ElMessageBox.confirm(`确定删除分类「${row.name}」吗？`, '删除确认', { type: 'warning' }).then(async () => {
    await deleteTuangouCategory(row.id); ElMessage.success('删除成功'); fetchList()
  }).catch(() => {})
}

const imgPickerVisible = ref(false)
function onPick(urls: string[]) { if (urls[0]) form.pic = urls[0] }

onMounted(fetchList)
</script>

<template>
  <div v-if="viewMode === 'list'">
    <el-card class="toolbar" shadow="never">
      <div class="toolbar-left">
        <el-button type="success" @click="openAdd">添加</el-button>
        <el-button>删除</el-button>
      </div>
    </el-card>
    <el-card shadow="never">
      <el-table v-loading="loading" :data="list" border>
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column prop="name" label="名称" min-width="160" />
        <el-table-column label="图片" width="100">
          <template #default="{ row }">
            <el-image v-if="row.pic" :src="row.pic" fit="cover" style="width:40px;height:40px" />
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="序号" width="90" align="center" />
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.status === 1" type="success" size="small">显示</el-tag>
            <el-tag v-else type="info" size="small">隐藏</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>

  <div v-else class="cat-edit">
    <div class="ce-head">
      <div class="ce-title">{{ isEdit ? '编辑商品分类' : '添加商品分类' }}</div>
      <div class="ce-close" @click="back">✕</div>
    </div>
    <el-form :model="form" label-width="100px" class="ce-form">
      <el-form-item label="分类名称" required>
        <el-input v-model="form.name" style="width: 280px" />
      </el-form-item>
      <el-form-item label="分类图标">
        <el-button @click="imgPickerVisible = true">上传图片</el-button>
        <span class="hint">建议尺寸：400×400像素</span>
        <el-image v-if="form.pic" :src="form.pic" fit="cover" class="cat-pic" />
      </el-form-item>
      <el-form-item label="序号">
        <el-input-number v-model="form.sort" :min="0" :precision="0" />
        <span class="hint">用于排序,越大越靠前</span>
      </el-form-item>
      <el-form-item label="状态">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">隐藏</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item>
        <el-button type="success" :loading="submitting" @click="submit">提交</el-button>
      </el-form-item>
    </el-form>
    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onPick" />
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
.toolbar :deep(.el-card__body) { padding: 6px; }
.toolbar-left { display: flex; gap: 8px; }
:deep(th.el-table__cell) { background: #e6e6e6 !important; }
:deep(.cell) { text-align: left; }
.muted { color: #c0c4cc; }
.cat-edit { background: #fff; padding: 20px; }
.ce-head { display: flex; align-items: center; margin-bottom: 16px; }
.ce-title { font-size: 16px; font-weight: 600; flex: 1; }
.ce-close { cursor: pointer; font-size: 18px; color: #909399; }
.hint { color: #909399; font-size: 12px; margin-left: 8px; }
.cat-pic { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; margin-left: 10px; }
</style>
