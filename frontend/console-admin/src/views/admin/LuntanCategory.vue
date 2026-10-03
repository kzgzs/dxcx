<script setup lang="ts">
// 用户论坛 - 分类管理（对齐点大 LuntanCategory/index + edit）
// - 列表：添加/删除；列 ID/名称/图片/序号/状态
// - 添加/编辑平铺主区域、右上角 X 关闭
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteLuntanCategory,
  getLuntanCategoryList,
  saveLuntanCategory,
} from '../../api/luntan'
import type { LuntanCategoryItem } from '../../api/luntan'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const viewMode = ref<'list' | 'edit'>('list')

// ============ 列表（拍平树：父级在前，子级缩进） ============
interface FlatRow extends LuntanCategoryItem {
  _level: number
}
const loading = ref(false)
const flatList = ref<FlatRow[]>([])
const selected = ref<FlatRow[]>([])

function flatten(tree: LuntanCategoryItem[]): FlatRow[] {
  const out: FlatRow[] = []
  const walk = (rows: LuntanCategoryItem[], level: number) => {
    rows.forEach((r) => {
      out.push({ ...r, _level: level })
      if (r.children && r.children.length) walk(r.children, level + 1)
    })
  }
  walk(tree, 0)
  return out
}

async function load() {
  loading.value = true
  try {
    const tree = await getLuntanCategoryList()
    flatList.value = flatten(tree || [])
  } finally {
    loading.value = false
  }
}

function handleBatchDelete() {
  const ids = selected.value.map((r) => r.id)
  if (ids.length === 0) {
    ElMessage.warning('请选择分类')
    return
  }
  ElMessageBox.confirm('确定要删除选中的分类吗？', '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteLuntanCategory(ids)
      ElMessage.success('删除成功')
      load()
    })
    .catch(() => {})
}

function handleRowDelete(row: FlatRow) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？`, '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteLuntanCategory([row.id])
      ElMessage.success('删除成功')
      load()
    })
    .catch(() => {})
}

// ============ 整页编辑 ============
const saving = ref(false)
const form = reactive({
  id: 0,
  name: '',
  pic: '',
  banner: '',
  sort: 0,
  status: 1 as number,
})

function resetForm() {
  Object.assign(form, { id: 0, name: '', pic: '', banner: '', sort: 0, status: 1 })
}

function openAdd() {
  resetForm()
  viewMode.value = 'edit'
}

function openEdit(row: LuntanCategoryItem) {
  Object.assign(form, {
    id: row.id,
    name: row.name || '',
    pic: row.pic || '',
    banner: row.banner || '',
    sort: row.sort ?? 0,
    status: row.status ? 1 : 0,
  })
  viewMode.value = 'edit'
}

function backToList() {
  viewMode.value = 'list'
  resetForm()
  load()
}

// 图标 / banner 选图
const iconPickerVisible = ref(false)
const bannerPickerVisible = ref(false)
function onIconPick(urls: string[]) {
  if (urls[0]) form.pic = urls[0]
}
function onBannerPick(urls: string[]) {
  if (urls[0]) form.banner = urls[0]
}

async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写分类名称')
    return
  }
  saving.value = true
  try {
    await saveLuntanCategory({
      id: form.id || undefined,
      pid: 0,
      name: form.name.trim(),
      pic: form.pic,
      banner: form.banner,
      sort: form.sort,
      status: form.status,
    })
    ElMessage.success('保存成功')
    backToList()
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <!-- ============ 列表视图 ============ -->
    <div v-if="viewMode === 'list'">
      <div class="page-title">帖子分类</div>

      <el-card shadow="never" class="toolbar">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleBatchDelete">删除</el-button>
        </div>
      </el-card>

      <el-card shadow="never" class="table-card">
        <el-table v-loading="loading" :data="flatList" border size="small" @selection-change="(rows: FlatRow[]) => (selected = rows)">
          <el-table-column type="selection" width="40" />
          <el-table-column prop="id" label="ID" width="80" />
          <el-table-column label="名称" min-width="200">
            <template #default="{ row }">
              <span :style="{ paddingLeft: row._level * 20 + 'px' }">{{ row.name }}</span>
            </template>
          </el-table-column>
          <el-table-column label="图片" width="120">
            <template #default="{ row }">
              <el-image
                v-if="row.pic"
                :src="row.pic"
                :preview-src-list="[row.pic]"
                preview-teleported
                fit="cover"
                class="cate-img"
              />
              <span v-else class="no-img">暂无图片</span>
            </template>
          </el-table-column>
          <el-table-column prop="sort" label="序号" width="90" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <span :class="row.status ? 'st-on' : 'st-off'">{{ row.status ? '显示' : '隐藏' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="140">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
              </div>
            </template>
          </el-table-column>
          <template #empty>暂无数据</template>
        </el-table>
      </el-card>
    </div>

    <!-- ============ 整页编辑 ============ -->
    <div v-else class="edit-page">
      <div class="edit-head">
        <div class="edit-title">＋ {{ form.id ? '编辑分类' : '添加分类' }}</div>
        <el-button class="edit-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <el-form label-width="90px" class="edit-form">
        <el-form-item label="分类名称">
          <el-input v-model="form.name" placeholder="请输入分类名称" style="width: 240px" />
        </el-form-item>
        <el-form-item label="分类图标">
          <el-button @click="iconPickerVisible = true">上传图片</el-button>
          <span class="field-tip">建议尺寸：400×400像素</span>
          <div class="pic-preview">
            <el-image v-if="form.pic" :src="form.pic" fit="cover" class="preview-box" />
            <div v-else class="preview-box preview-empty">暂无图片</div>
          </div>
        </el-form-item>
        <el-form-item label="banner图">
          <el-button @click="bannerPickerVisible = true">上传图片</el-button>
          <span class="field-tip">建议尺寸：690×260像素</span>
          <div class="pic-preview">
            <el-image v-if="form.banner" :src="form.banner" fit="cover" class="preview-banner" />
            <div v-else class="preview-banner preview-empty">暂无图片</div>
          </div>
        </el-form-item>
        <el-form-item label="序号">
          <el-input-number v-model="form.sort" :min="0" :controls="false" style="width: 200px" />
          <span class="field-tip">用于排序,越大越靠前</span>
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">隐藏</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </div>

    <ImgIconPicker v-model:visible="iconPickerVisible" @pick="onIconPick" />
    <ImgIconPicker v-model:visible="bannerPickerVisible" @pick="onBannerPick" />
  </div>
</template>

<style scoped>
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding: 2px 4px 8px;
}
.toolbar {
  margin-bottom: 3px;
}
.toolbar :deep(.el-card__body) {
  padding: 10px 14px;
}
.toolbar-left {
  display: flex;
  gap: 8px;
}
.table-card {
  margin-top: 3px;
}
.cate-img {
  width: 40px;
  height: 40px;
  border-radius: 4px;
}
.no-img {
  color: #c0c4cc;
  font-size: 12px;
}
.st-on {
  color: #67c23a;
}
.st-off {
  color: #909399;
}
.row-ops {
  display: flex;
  flex-direction: row;
  column-gap: 8px;
}
.row-ops .el-button {
  margin: 0;
  padding: 0;
  height: auto;
  font-size: 12px;
}
/* 整页编辑 */
.edit-page {
  background: #fff;
  border-radius: 6px;
  padding: 16px 20px;
}
.edit-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.edit-title {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}
.edit-close {
  border: none;
  background: transparent;
  color: #909399;
}
.field-tip {
  color: #909399;
  font-size: 12px;
  margin-left: 8px;
}
.pic-preview {
  margin-top: 8px;
}
.preview-box {
  width: 120px;
  height: 120px;
  border-radius: 4px;
}
.preview-banner {
  width: 240px;
  height: 90px;
  border-radius: 4px;
}
.preview-empty {
  background: #f5f7fa;
  border: 1px solid #ebeef5;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
}
</style>
