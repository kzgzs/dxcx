<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteYuyueWorkerCategory,
  getYuyueWorkerCategoryList,
  saveYuyueWorkerCategory,
  setYuyueWorkerCategoryStatus,
} from '../../api/yuyue'
import type { YuyueWorkerCategoryItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// 预约 - 人员类型（任务222，对照参考系统 YuyueWorkerCategory）
const loading = ref(false)
const tableData = ref<YuyueWorkerCategoryItem[]>([])

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueWorkerCategoryList()
    tableData.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function parentName(pid: number): string {
  if (!pid) return '-'
  const p = tableData.value.find((it) => it.id === pid)
  return p ? p.name : `#${pid}`
}

// ============ 状态切换 ============
async function handleToggleStatus(row: YuyueWorkerCategoryItem) {
  const st = row.status === 1 ? 0 : 1
  try {
    await setYuyueWorkerCategoryStatus([row.id], st)
    ElMessage.success(st === 1 ? '已显示' : '已隐藏')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 删除 ============
function handleDelete(row: YuyueWorkerCategoryItem) {
  ElMessageBox.confirm(`确定要删除类型「${row.name}」吗？类型下有人员时无法删除。`, '删除确认', {
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

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  pid: 0,
  name: '',
  pic: '',
  sort: 1,
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入类型名称', trigger: 'blur' }],
}

// 上级选项：排除自身，避免 pid==id
const parentOptions = computed(() =>
  tableData.value.filter((it) => it.id !== form.id),
)

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, pid: 0, name: '', pic: '', sort: 1, status: 1 })
  dialogVisible.value = true
}

function openEdit(row: YuyueWorkerCategoryItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    pid: row.pid || 0,
    name: row.name,
    pic: row.pic || '',
    sort: row.sort,
    status: row.status,
  })
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveYuyueWorkerCategory({ ...form })
      ElMessage.success('保存成功')
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- 工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item style="float: right">
          <el-button type="success" @click="openAdd">新增类型</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="类型名称" min-width="160" show-overflow-tooltip />
        <el-table-column label="上级类型" min-width="120">
          <template #default="{ row }">{{ parentName(row.pid) }}</template>
        </el-table-column>
        <el-table-column label="图标" width="90">
          <template #default="{ row }">
            <el-image v-if="row.pic" :src="row.pic" style="width: 40px; height: 40px" fit="cover" />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="80" />
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
        <el-table-column label="操作" width="190" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleToggleStatus(row)">
              {{ row.status === 1 ? '隐藏' : '显示' }}
            </el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑人员类型' : '新增人员类型'" width="480px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" label-position="left">
        <el-form-item label="上级类型">
          <el-select v-model="form.pid" placeholder="顶级类型" clearable style="width: 100%">
            <el-option label="顶级类型" :value="0" />
            <el-option v-for="opt in parentOptions" :key="opt.id" :label="opt.name" :value="opt.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="类型名称" prop="name">
          <el-input v-model="form.name" placeholder="如：美发师" />
        </el-form-item>
        <el-form-item label="图标">
          <el-input v-model="form.pic" placeholder="图片地址（可选）" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}
</style>
