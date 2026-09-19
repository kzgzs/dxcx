<script setup lang="ts">
// 文章分类（批次5-A 任务194）：二级分类 CRUD（树形展示）
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteArticleCategory,
  getArticleCategoryList,
  saveArticleCategory,
  type ArticleCategoryItem,
} from '../../api/article'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

interface CategoryRow extends ArticleCategoryItem {
  children?: CategoryRow[]
}

const loading = ref(false)
const flatList = ref<ArticleCategoryItem[]>([])

const tree = computed<CategoryRow[]>(() => {
  const tops = flatList.value.filter((c) => c.pid === 0)
  return tops.map((t) => {
    const children = flatList.value.filter((c) => c.pid === t.id)
    return { ...t, children: children.length ? children : undefined }
  })
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

// ============ 编辑弹窗 ============
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive({ id: 0, pid: 0, name: '', sort: 1, pic: '', status: 1 })

const topOptions = computed(() => flatList.value.filter((c) => c.pid === 0))

function openAdd(pid = 0) {
  form.id = 0
  form.pid = pid
  form.name = ''
  form.sort = 1
  form.pic = ''
  form.status = 1
  dialogVisible.value = true
}
function openEdit(row: ArticleCategoryItem & { status?: number }) {
  form.id = row.id
  form.pid = row.pid
  form.name = row.name
  form.sort = row.sort
  form.pic = row.pic
  form.status = Number(row.status ?? 1)
  dialogVisible.value = true
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
    dialogVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}
async function handleDelete(row: ArticleCategoryItem) {
  await ElMessageBox.confirm(`确定删除分类「${row.name}」？其子分类将一并删除`, '提示', { type: 'warning' })
  await deleteArticleCategory([row.id])
  ElMessage.success('删除成功')
  load()
}
// 选图（ImgIconPicker 统一选图，任务293）
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
    <div class="toolbar">
      <el-button type="primary" @click="openAdd(0)">新增顶级分类</el-button>
    </div>

    <el-table v-loading="loading" :data="tree" border row-key="id" default-expand-all>
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column label="分类图" width="90">
        <template #default="{ row }">
          <el-image v-if="row.pic" :src="row.pic" style="width: 56px; height: 40px" fit="cover" />
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column prop="name" label="分类名称" min-width="200" />
      <el-table-column label="层级" width="100">
        <template #default="{ row }">
          <el-tag v-if="row.pid === 0">一级</el-tag>
          <el-tag v-else type="info">二级</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="90" />
      <el-table-column label="操作" width="260" fixed="right">
        <template #default="{ row }">
          <el-button v-if="row.pid === 0" link type="primary" @click="openAdd(row.id)">添加子分类</el-button>
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
      <template #empty>暂无数据</template>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑分类' : '新增分类'" width="520px" destroy-on-close>
      <el-form label-width="90px">
        <el-form-item label="上级分类">
          <el-select v-model="form.pid" style="width: 260px" :disabled="form.id > 0">
            <el-option label="顶级分类" :value="0" />
            <el-option v-for="t in topOptions" :key="t.id" :label="t.name" :value="t.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="分类名称" required>
          <el-input v-model="form.name" maxlength="30" show-word-limit />
        </el-form-item>
        <el-form-item label="分类图">
          <div style="display: flex; align-items: center; gap: 12px">
            <el-image v-if="form.pic" :src="form.pic" style="width: 100px; height: 66px" fit="cover" />
            <el-button size="small" @click="openImgPicker((u) => (form.pic = u))">{{ form.pic ? '更换' : '选择图片' }}</el-button>
          </div>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="9999" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.article-category {
  padding: 12px;
}
.toolbar {
  margin-bottom: 12px;
}
</style>
