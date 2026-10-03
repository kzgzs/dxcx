<script setup lang="ts">
/**
 * 扩展·预约服务 - 服务类目
 * 对齐截图：列表（主类目+子类目两级树，子类目缩进、仅主类目有「添加子分类」）
 * 平铺增改（上级分类/分类名称/分类图标/序号/状态）+ 右上X关闭
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getYuyueCategoryList,
  addYuyueCategory,
  editYuyueCategory,
  deleteYuyueCategory,
} from '../../api/yuyue'
import type { YuyueCategoryItem } from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'

interface FlatRow extends YuyueCategoryItem {
  depth: number
}

const loading = ref(false)
const list = ref<YuyueCategoryItem[]>([])
const checkedIds = ref<Set<number>>(new Set())

/* 平铺增改面板 */
const showEdit = ref(false)
const editId = ref<number>(0)
const form = reactive({
  pid: 0,
  name: '',
  pic: '',
  sort: 0,
  status: 1,
})

/* 图片选择器 */
const pickerVisible = ref(false)

/* 查看链接 */
const linkVisible = ref(false)
const linkPath = ref('')

/** 拍平树为带 depth 的行（用于 el-table 按缩进渲染） */
function flatten(): FlatRow[] {
  const rows: FlatRow[] = []
  for (const item of list.value) {
    rows.push({ ...item, depth: item.depth ?? 0 })
    for (const child of item.children ?? []) {
      rows.push({ ...child, depth: child.depth ?? 1 })
    }
  }
  return rows
}

function onCheckChange(row: FlatRow, checked: boolean) {
  if (checked) checkedIds.value.add(row.id)
  else checkedIds.value.delete(row.id)
}

async function loadData() {
  loading.value = true
  try {
    const res = await getYuyueCategoryList()
    list.value = (res.list ?? []).map((item) => ({
      ...item,
      depth: item.depth ?? 0,
      children: (item.children ?? []).map((c) => ({ ...c, depth: c.depth ?? 1 })),
    }))
  } finally {
    loading.value = false
  }
}

function openAdd(parentId = 0) {
  editId.value = 0
  form.pid = parentId
  form.name = ''
  form.pic = ''
  form.sort = 0
  form.status = 1
  showEdit.value = true
}

function openEdit(row: FlatRow) {
  editId.value = row.id
  form.pid = row.pid ?? 0
  form.name = row.name
  form.pic = row.pic ?? ''
  form.sort = row.sort
  form.status = row.status ?? 1
  showEdit.value = true
}

function onPickImage(urls: string[]) {
  form.pic = urls[0] ?? ''
  pickerVisible.value = false
}

async function submit() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写分类名称')
    return
  }
  const payload = {
    id: editId.value || undefined,
    pid: form.pid,
    name: form.name.trim(),
    pic: form.pic,
    sort: Number(form.sort) || 0,
    status: form.status,
  }
  if (editId.value) {
    await editYuyueCategory(payload)
    ElMessage.success('保存成功')
  } else {
    await addYuyueCategory(payload)
    ElMessage.success('添加成功')
  }
  showEdit.value = false
  await loadData()
}

async function removeRow(row: FlatRow) {
  await ElMessageBox.confirm(`确定删除分类「${row.name}」？`, '提示', { type: 'warning' })
  await deleteYuyueCategory(row.id)
  ElMessage.success('删除成功')
  await loadData()
}

async function batchDelete() {
  const ids = Array.from(checkedIds.value)
  if (!ids.length) {
    ElMessage.warning('请先勾选要删除的分类')
    return
  }
  await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个分类？`, '提示', { type: 'warning' })
  for (const id of ids) {
    await deleteYuyueCategory(id)
  }
  checkedIds.value.clear()
  ElMessage.success('批量删除成功')
  await loadData()
}

function openLink(row: FlatRow) {
  linkPath.value = `/yuyue/yuyue/category?id=${row.id}`
  linkVisible.value = true
}

/** 上级分类下拉选项：顶级 =0，其余主类目可作为父级 */
function parentOptions() {
  return (list.value ?? [])
    .filter((item) => (item.depth ?? 0) === 0 && item.id !== editId.value)
    .map((item) => ({ label: item.name, value: item.id }))
}

onMounted(loadData)
</script>

<template>
  <div class="page">
    <template v-if="!showEdit">
    <div class="page-head">
      <span class="page-title">服务类目</span>
    </div>

    <div class="toolbar">
      <el-button type="primary" @click="openAdd(0)">添加</el-button>
      <el-button type="danger" :disabled="!checkedIds.size" @click="batchDelete">删除</el-button>
    </div>

    <el-table v-loading="loading" :data="flatten()" border size="small" class="data-table">
      <el-table-column width="42">
        <template #default="{ row }">
          <el-checkbox
            :model-value="checkedIds.has(row.id)"
            @change="(v: boolean) => onCheckChange(row, v)"
          />
        </template>
      </el-table-column>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="名称" min-width="220">
        <template #default="{ row }">
          <span :style="{ paddingLeft: (row.depth * 24) + 'px' }">
            <span v-if="row.depth" class="child-mark">└</span>
            {{ row.name }}
          </span>
        </template>
      </el-table-column>
      <el-table-column label="图片" width="90">
        <template #default="{ row }">
          <img v-if="row.pic" :src="row.pic" class="thumb" />
          <span v-else class="muted">-</span>
        </template>
      </el-table-column>
      <el-table-column prop="sort" label="序号" width="90" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag v-if="row.status === 1" type="success" size="small">显示</el-tag>
          <el-tag v-else type="info" size="small">隐藏</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="280">
        <template #default="{ row }">
          <el-button link type="primary" size="small" @click="openLink(row)">查看链接</el-button>
          <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" size="small" @click="removeRow(row)">删除</el-button>
          <el-button
            v-if="row.depth === 0"
            link
            type="primary"
            size="small"
            @click="openAdd(row.id)"
          >添加子分类</el-button>
        </template>
      </el-table-column>
    </el-table>
    </template>

    <!-- 平铺增改主区域（进入时隐藏列表，X 返回列表） -->
    <template v-else>
      <div class="goods-edit">
        <div class="ge-head">
          <span class="ge-title">{{ editId ? '编辑服务类目' : '+ 添加服务类目' }}</span>
          <button class="ge-close" @click="showEdit = false">×</button>
        </div>

        <div class="ge-body">
          <div class="form-row">
            <label class="form-label">上级分类</label>
            <el-select v-model="form.pid" class="form-control" placeholder="-作为顶级分类-">
              <el-option label="-作为顶级分类-" :value="0" />
              <el-option
                v-for="opt in parentOptions()"
                :key="opt.value"
                :label="opt.label"
                :value="opt.value"
              />
            </el-select>
          </div>

          <div class="form-row">
            <label class="form-label">分类名称</label>
            <el-input v-model="form.name" class="form-control" placeholder="请输入分类名称" />
          </div>

          <div class="form-row">
            <label class="form-label">分类图标</label>
            <div class="form-control">
              <el-button size="small" @click="pickerVisible = true">上传图片</el-button>
              <span class="hint">建议尺寸：400x400像素</span>
              <div v-if="form.pic" class="icon-preview">
                <img :src="form.pic" />
              </div>
            </div>
          </div>

          <div class="form-row">
            <label class="form-label">序号</label>
            <el-input-number v-model="form.sort" :min="0" class="form-control" />
            <span class="hint">用于排序,越大越靠前</span>
          </div>

          <div class="form-row">
            <label class="form-label">状态</label>
            <el-radio-group v-model="form.status">
              <el-radio :value="1">显示</el-radio>
              <el-radio :value="0">隐藏</el-radio>
            </el-radio-group>
          </div>

          <div class="form-row">
            <label class="form-label"></label>
            <el-button type="primary" @click="submit">提交</el-button>
          </div>
        </div>
      </div>
    </template>

    <ImgIconPicker v-model:visible="pickerVisible" @pick="onPickImage" />

    <LinkAddressModal
      v-model:visible="linkVisible"
      name="服务类目"
      :path="linkPath"
    />
  </div>
</template>

<style scoped>
.page {
  padding: 3px;
}
.page-head {
  margin-bottom: 3px;
}
.page-title {
  font-weight: 600;
  font-size: 15px;
}
.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 3px;
}
.data-table :deep(th) {
  background: #e6e6e6;
}
.data-table {
  text-align: left;
}
.thumb {
  width: 40px;
  height: 40px;
  object-fit: cover;
  border-radius: 3px;
}
.child-mark {
  color: #999;
  margin-right: 4px;
}
.muted {
  color: #bbb;
}

/* 平铺增改面板 */
.goods-edit {
  margin-top: 3px;
  border: 1px solid #e6e6e6;
  border-radius: 4px;
  background: #fff;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  border-bottom: 1px solid #eee;
}
.ge-title {
  font-weight: 600;
}
.ge-close {
  border: none;
  background: transparent;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
  color: #666;
}
.ge-body {
  padding: 14px;
}
.form-row {
  display: flex;
  align-items: flex-start;
  margin-bottom: 14px;
  gap: 10px;
}
.form-label {
  width: 90px;
  text-align: right;
  color: #333;
  flex-shrink: 0;
  line-height: 32px;
}
.form-control {
  flex: 0 1 360px;
  text-align: left;
}
.hint {
  margin-left: 8px;
  color: #999;
  font-size: 12px;
  line-height: 32px;
}
.icon-preview {
  margin-top: 8px;
}
.icon-preview img {
  width: 80px;
  height: 80px;
  object-fit: cover;
  border: 1px solid #eee;
  border-radius: 3px;
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
