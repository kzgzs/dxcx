<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteKechengCategory,
  getKechengCategoryList,
  saveKechengCategory,
} from '../../api/kecheng'
import type { KechengCategoryItem } from '../../api/kecheng'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'

// ============ 列表 ============
const loading = ref(false)
const rows = ref<KechengCategoryItem[]>([])
const checkedIds = ref<Set<number>>(new Set())

/** 两级树展示行：顶级行 + 子行（├ / └ 缩进，与截图一致） */
interface DisplayRow extends KechengCategoryItem {
  indent: number
  prefix: string
  isTop: boolean
}

const treeRows = computed<DisplayRow[]>(() => {
  const out: DisplayRow[] = []
  const tops = rows.value.filter((r) => Number(r.pid) === 0)
  tops.forEach((top) => {
    out.push({ ...top, indent: 0, prefix: '', isTop: true })
    const children = rows.value.filter((r) => Number(r.pid) === Number(top.id))
    children.forEach((child, idx) => {
      const isLast = idx === children.length - 1
      out.push({ ...child, indent: 1, prefix: isLast ? '└ ' : '├ ', isTop: false })
    })
  })
  return out
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getKechengCategoryList()
    rows.value = data.list || []
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

function onCheckChange(row: KechengCategoryItem, v: boolean) {
  if (v) checkedIds.value.add(row.id)
  else checkedIds.value.delete(row.id)
}

function onCheckAll(v: boolean) {
  if (v) treeRows.value.forEach((r) => checkedIds.value.add(r.id))
  else checkedIds.value.clear()
}

const allChecked = computed(
  () => treeRows.value.length > 0 && treeRows.value.every((r) => checkedIds.value.has(r.id)),
)

// ============ 删除 ============
function handleDelete(row: KechengCategoryItem) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteKechengCategory(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (!checkedIds.value.size) return
  ElMessageBox.confirm(`确定要删除选中的 ${checkedIds.value.size} 个分类吗？`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        for (const id of Array.from(checkedIds.value)) {
          await deleteKechengCategory(id)
        }
        checkedIds.value.clear()
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

// ============ 查看链接 ============
const linkVisible = ref(false)
const linkPath = ref('')
function openLink(row: KechengCategoryItem) {
  linkPath.value = `/activity/kecheng/cate?id=${row.id}`
  linkVisible.value = true
}

// ============ 增改平铺 ============
const editing = ref(false)
const editId = ref(0)
const submitting = ref(false)
const form = reactive({
  pid: 0,
  name: '',
  pic: '',
  sort: 0,
  status: 1,
})

function resetForm() {
  form.pid = 0
  form.name = ''
  form.pic = ''
  form.sort = 0
  form.status = 1
}

function openAdd() {
  resetForm()
  editId.value = 0
  editing.value = true
}

function openAddChild(parent: KechengCategoryItem) {
  resetForm()
  editId.value = 0
  form.pid = parent.id
  editing.value = true
}

function openEdit(row: KechengCategoryItem) {
  resetForm()
  editId.value = row.id
  form.pid = Number(row.pid) || 0
  form.name = row.name
  form.pic = row.pic || ''
  form.sort = Number(row.sort) || 0
  form.status = Number(row.status) === 0 ? 0 : 1
  editing.value = true
}

// 选图
const pickerVisible = ref(false)
function openPicker() {
  pickerVisible.value = true
}
function onPickImage(urls: string[]) {
  form.pic = urls[0] ?? ''
  pickerVisible.value = false
}

async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写分类名称')
    return
  }
  submitting.value = true
  try {
    await saveKechengCategory({
      id: editId.value || undefined,
      pid: form.pid,
      name: form.name.trim(),
      pic: form.pic,
      sort: form.sort,
      status: form.status,
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

onMounted(fetchList)
</script>

<template>
  <div class="page">
    <!-- 列表态 -->
    <template v-if="!editing">
      <div class="page-head"><span class="page-title">课程分类</span></div>

      <div class="toolbar">
        <el-button type="primary" @click="openAdd">添加</el-button>
        <el-button type="danger" :disabled="!checkedIds.size" @click="handleBatchDelete">
          删除
        </el-button>
      </div>

      <el-table v-loading="loading" :data="treeRows" border size="small" class="data-table">
        <el-table-column width="42">
          <template #header>
            <el-checkbox :model-value="allChecked" @change="(v: boolean) => onCheckAll(v)" />
          </template>
          <template #default="{ row }">
            <el-checkbox
              :model-value="checkedIds.has(row.id)"
              @change="(v: boolean) => onCheckChange(row, v)"
            />
          </template>
        </el-table-column>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="名称" min-width="240">
          <template #default="{ row }">
            <span v-if="row.prefix" class="tree-prefix">{{ row.prefix }}</span>{{ row.name }}
          </template>
        </el-table-column>
        <el-table-column label="图片" width="90">
          <template #default="{ row }">
            <img v-if="row.pic" :src="row.pic" class="cat-thumb" />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="序号" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span v-if="row.status === 1" class="st-on">显示</span>
            <span v-else class="st-off">隐藏</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="320">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openLink(row)">查看链接</el-button>
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            <el-button v-if="row.isTop" link type="primary" size="small" @click="openAddChild(row)">
              添加子分类
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </template>

    <!-- 增改平铺面板 -->
    <template v-else>
      <div class="goods-edit">
        <div class="ge-head">
          <span class="ge-title">{{ editId ? '编辑课程分类' : '添加课程分类' }}</span>
          <button class="ge-close" @click="editing = false">×</button>
        </div>

        <div class="ge-body">
          <div class="form-row">
            <label class="form-label"><span class="req">*</span>分类名称：</label>
            <el-input v-model="form.name" class="form-control" placeholder="请输入分类名称" />
          </div>

          <div class="form-row">
            <label class="form-label">分类图标：</label>
            <div>
              <el-button size="small" @click="openPicker">上传图片</el-button>
              <span class="hint">建议尺寸：400x400像素</span>
              <div v-if="form.pic" class="img-preview">
                <img :src="form.pic" />
              </div>
            </div>
          </div>

          <div class="form-row">
            <label class="form-label">序号：</label>
            <el-input-number v-model="form.sort" :min="0" :precision="0" controls-position="right" />
            <span class="hint">用于排序，越大越靠前</span>
          </div>

          <div class="form-row">
            <label class="form-label">状态：</label>
            <el-radio-group v-model="form.status">
              <el-radio :value="1">显示</el-radio>
              <el-radio :value="0">隐藏</el-radio>
            </el-radio-group>
          </div>

          <div class="form-row">
            <label class="form-label"></label>
            <el-button type="primary" :loading="submitting" @click="handleSubmit">提交</el-button>
          </div>
        </div>
      </div>
    </template>

    <ImgIconPicker v-model:visible="pickerVisible" @pick="onPickImage" />

    <LinkAddressModal v-model:visible="linkVisible" name="课程分类" :path="linkPath" />
  </div>
</template>

<style scoped>
.page { padding: 3px; }
.page-head { margin-bottom: 3px; }
.page-title { font-weight: 600; font-size: 15px; }
.toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 3px; flex-wrap: wrap; }
.data-table :deep(th) { background: #e6e6e6; }
.tree-prefix { color: #aaa; }
.cat-thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 3px; }
.st-on { color: #1ab0a0; }
.st-off { color: #909399; }

.goods-edit { border: 1px solid #e6e6e6; border-radius: 4px; background: #fff; }
.ge-head { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid #eee; }
.ge-title { font-weight: 600; }
.ge-close { border: none; background: transparent; font-size: 22px; cursor: pointer; color: #666; }
.ge-body { padding: 16px; }
.form-row { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
.form-label { width: 110px; text-align: right; color: #333; flex-shrink: 0; line-height: 32px; }
.form-control { flex: 0 1 340px; text-align: left; }
.req { color: #f56c6c; margin-right: 2px; }
.hint { color: #999; font-size: 12px; line-height: 32px; }
.img-preview img { width: 80px; height: 80px; object-fit: cover; border: 1px solid #eee; border-radius: 3px; margin-top: 6px; }
</style>
