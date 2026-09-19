<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteCollageCategory,
  getCollageCategoryList,
  saveCollageCategory,
} from '../../api/collageCategory'
import type { CollageCategoryItem } from '../../api/collageCategory'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const tableData = ref<CollageCategoryItem[]>([])

async function fetchList() {
  loading.value = true
  try {
    const data = await getCollageCategoryList()
    tableData.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// 一级分类（供上级分类下拉）
const parentOptions = computed(() => tableData.value.filter((v) => v.pid === 0))

function parentName(pid: number): string {
  if (!pid) return '-'
  const p = tableData.value.find((v) => v.id === pid)
  return p ? p.name : `#${pid}`
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const submitting = ref(false)
const form = reactive({
  id: 0,
  pid: 0,
  name: '',
  pic: '',
  status: 1,
  sort: 1,
})

function openAdd() {
  Object.assign(form, { id: 0, pid: 0, name: '', pic: '', status: 1, sort: 1 })
  dialogVisible.value = true
}

function openEdit(row: CollageCategoryItem) {
  Object.assign(form, {
    id: row.id,
    pid: row.pid,
    name: row.name,
    pic: row.pic,
    status: row.status,
    sort: row.sort,
  })
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请输入分类名称')
    return
  }
  submitting.value = true
  try {
    await saveCollageCategory({ ...form, id: form.id || undefined })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

function handleDelete(row: CollageCategoryItem) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？`, '删除确认', {
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
        // 错误提示已由 axios 拦截器统一弹出（有子分类/商品占用会被后端拒绝）
      }
    })
    .catch(() => {})
}

// ============ 选图（ImgIconPicker 统一选图） ============
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

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>拼团商品分类</span>
          <el-button type="success" @click="openAdd">新增分类</el-button>
        </div>
      </template>

      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="图标" width="90">
          <template #default="{ row }">
            <el-image
              v-if="row.pic"
              :src="row.pic"
              fit="cover"
              style="width: 40px; height: 40px; border-radius: 4px"
            />
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="分类名称" min-width="160" />
        <el-table-column label="上级分类" width="130">
          <template #default="{ row }">{{ parentName(row.pid) }}</template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="90" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '显示' : '隐藏' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑分类' : '新增分类'" width="440px">
      <el-form label-width="90px" label-position="left">
        <el-form-item label="上级分类">
          <el-select v-model="form.pid" placeholder="顶级分类" clearable style="width: 100%">
            <el-option
              v-for="p in parentOptions.filter((v) => v.id !== form.id)"
              :key="p.id"
              :label="p.name"
              :value="p.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="分类名称">
          <el-input v-model="form.name" placeholder="请输入分类名称" />
        </el-form-item>
        <el-form-item label="图标">
          <el-image
            v-if="form.pic"
            :src="form.pic"
            fit="cover"
            style="width: 80px; height: 80px; border-radius: 4px; margin-right: 8px"
          />
          <el-button size="small" @click="openImgPicker((u) => (form.pic = u))">
            {{ form.pic ? '更换' : '选择图片' }}
          </el-button>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
          <span class="form-tip">数值越大越靠前</span>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.text-muted {
  color: #909399;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
