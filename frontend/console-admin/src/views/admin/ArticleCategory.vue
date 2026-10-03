<script setup lang="ts">
// 文章分类（对齐点大「扩展→文章管理→文章分类」截图）：
// 列表为平铺树表（├ └ 层级缩进），添加/编辑平铺在主区域 + 右上 X
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteArticleCategory,
  getArticleCategoryList,
  saveArticleCategory,
  type ArticleCategoryItem,
} from '../../api/article'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

interface CatRow extends ArticleCategoryItem {
  children?: CatRow[]
}

const loading = ref(false)
const flatList = ref<ArticleCategoryItem[]>([])

const tree = computed<CatRow[]>(() => {
  const tops = flatList.value
    .filter((c) => c.pid === 0)
    .sort((a, b) => b.sort - a.sort || b.id - a.id)
  return tops.map((t) => {
    const children = flatList.value
      .filter((c) => c.pid === t.id)
      .sort((a, b) => b.sort - a.sort || b.id - a.id)
    return { ...t, children }
  })
})

/** 平铺展开树：每行带层级缩进标记（├ / └） */
interface FlatRow extends ArticleCategoryItem {
  marker: string
  hasChildren: boolean
}
const displayList = computed<FlatRow[]>(() => {
  const out: FlatRow[] = []
  tree.value.forEach((t) => {
    out.push({ ...t, marker: '', hasChildren: !!(t.children && t.children.length) })
    const kids = t.children || []
    kids.forEach((k, i) => {
      const isLast = i === kids.length - 1
      out.push({ ...k, marker: isLast ? '└' : '├', hasChildren: false })
    })
  })
  return out
})

async function load() {
  loading.value = true
  try {
    const res = await getArticleCategoryList()
    flatList.value = res.list || []
  } finally {
    loading.value = false
  }
}

// ============ 平铺编辑 ============
const viewMode = ref<'list' | 'edit'>('list')
const saving = ref(false)
const form = reactive({ id: 0, pid: 0, name: '', sort: 0, pic: '', status: 1 })

const topOptions = computed(() => flatList.value.filter((c) => c.pid === 0))

function openAdd(pid = 0) {
  form.id = 0
  form.pid = pid
  form.name = ''
  form.sort = 0
  form.pic = ''
  form.status = 1
  viewMode.value = 'edit'
}
function openEdit(row: ArticleCategoryItem) {
  form.id = row.id
  form.pid = row.pid
  form.name = row.name
  form.sort = Number(row.sort) || 0
  form.pic = row.pic || ''
  form.status = Number(row.status ?? 1)
  viewMode.value = 'edit'
}
function backToList() {
  viewMode.value = 'list'
  load()
}
async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写分类名称')
    return
  }
  saving.value = true
  try {
    await saveArticleCategory({ ...form, id: form.id || undefined })
    ElMessage.success('保存成功')
    backToList()
  } finally {
    saving.value = false
  }
}

// 批量删除
const selected = ref<ArticleCategoryItem[]>([])
async function handleBatchDelete() {
  if (!selected.value.length) {
    ElMessage.warning('请选择数据')
    return
  }
  await ElMessageBox.confirm(`确定删除选中的 ${selected.value.length} 个分类？其子分类将一并删除`, '删除确认', { type: 'warning' })
  await deleteArticleCategory(selected.value.map((r) => r.id))
  ElMessage.success('删除成功')
  selected.value = []
  load()
}
async function handleDelete(row: ArticleCategoryItem) {
  await ElMessageBox.confirm(`确定删除分类「${row.name}」？其子分类将一并删除`, '删除确认', { type: 'warning' })
  await deleteArticleCategory([row.id])
  ElMessage.success('删除成功')
  load()
}

// 选图
const imgPickerVisible = ref(false)
let imgPickerSetter: ((url: string) => void) | null = null
function openImgPicker(setter: (url: string) => void) {
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) imgPickerSetter?.(urls[0])
  imgPickerSetter = null
}

onMounted(load)
</script>

<template>
  <div class="article-category">
    <!-- ============ 列表 ============ -->
    <div v-if="viewMode === 'list'">
      <div class="page-title">文章分类</div>
      <el-card shadow="never" class="toolbar-card">
        <el-button type="success" @click="openAdd(0)">添加</el-button>
        <el-button @click="handleBatchDelete">删除</el-button>
      </el-card>

      <el-card shadow="never">
        <el-table
          v-loading="loading"
          :data="displayList"
          border
          :header-cell-style="{ background: '#e6e6e6' }"
          @selection-change="(rows: ArticleCategoryItem[]) => (selected = rows)"
        >
          <el-table-column type="selection" width="45" />
          <el-table-column prop="id" label="ID" width="80" align="left" header-align="left" />
          <el-table-column label="名称" min-width="260" align="left" header-align="left">
            <template #default="{ row }">
              <span v-if="row.marker" class="tree-marker">{{ row.marker }}</span>
              <span>{{ row.name }}</span>
            </template>
          </el-table-column>
          <el-table-column label="图片" width="110" align="left" header-align="left">
            <template #default="{ row }">
              <el-image v-if="row.pic" :src="row.pic" class="cat-pic" fit="cover" />
              <span v-else class="cat-pic-empty">-</span>
            </template>
          </el-table-column>
          <el-table-column prop="sort" label="序号" width="90" align="left" header-align="left" />
          <el-table-column label="状态" width="90" align="left" header-align="left">
            <template #default="{ row }">
              <span :class="Number(row.status ?? 1) === 1 ? 'st-on' : 'st-off'">
                {{ Number(row.status ?? 1) === 1 ? '显示' : '隐藏' }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="230" align="left" header-align="left">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="primary" @click="handleDelete(row)">删除</el-button>
                <el-button v-if="!row.marker" link type="primary" @click="openAdd(row.id)">添加子分类</el-button>
              </div>
            </template>
          </el-table-column>
          <template #empty>暂无数据</template>
        </el-table>
      </el-card>
    </div>

    <!-- ============ 平铺编辑 ============ -->
    <div v-else class="edit-panel">
      <div class="edit-head">
        <span class="edit-title">{{ form.id ? '编辑文章分类' : '＋添加文章分类' }}</span>
        <el-button class="edit-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>
      <el-form label-width="90px" class="edit-form">
        <el-form-item label="上级分类:">
          <el-select v-model="form.pid" style="width: 240px" :disabled="form.id > 0">
            <el-option label="--作为顶级分类--" :value="0" />
            <el-option v-for="t in topOptions" :key="t.id" :label="t.name" :value="t.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="分类名称:">
          <el-input v-model="form.name" maxlength="30" style="width: 240px" />
        </el-form-item>
        <el-form-item label="分类图标:">
          <el-button @click="openImgPicker((u) => (form.pic = u))">上传图片</el-button>
          <span class="hint">建议尺寸：400×400像素</span>
          <div class="icon-preview">
            <el-image v-if="form.pic" :src="form.pic" class="icon-preview-img" fit="cover" />
            <div v-else class="icon-preview-empty"></div>
          </div>
        </el-form-item>
        <el-form-item label="序号:">
          <el-input-number v-model="form.sort" :min="0" :controls="false" style="width: 240px" />
          <span class="hint">用于排序，越大越靠前</span>
        </el-form-item>
        <el-form-item label="状态:">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">隐藏</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </div>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.article-category {
  padding: 3px;
}
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding: 4px 2px 8px;
}
.toolbar-card {
  margin-bottom: 3px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 10px 14px;
}
.toolbar-card .el-button {
  margin-left: 0;
}
:deep(.el-table) {
  border-top: none;
}
.tree-marker {
  color: #909399;
  margin-right: 2px;
}
.cat-pic {
  width: 48px;
  height: 48px;
  border-radius: 4px;
}
.cat-pic-empty {
  color: #c0c4cc;
}
.st-on {
  color: #67c23a;
}
.st-off {
  color: #909399;
}
.row-ops {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
}
.row-ops .el-button {
  margin: 0;
}
/* 平铺编辑 */
.edit-panel {
  background: #fff;
  border: 1px solid #e6e6e6;
  padding: 16px 20px;
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
.edit-close {
  border: none;
  background: transparent;
  color: #909399;
}
.edit-close:hover {
  color: #409eff;
  background: transparent;
}
.edit-form :deep(.el-form-item) {
  margin-bottom: 16px;
}
.hint {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
}
.icon-preview {
  margin-top: 8px;
}
.icon-preview-img {
  width: 100px;
  height: 100px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}
.icon-preview-empty {
  width: 100px;
  height: 100px;
  border: 1px dashed #dcdfe6;
  background: #fafafa;
}
</style>
