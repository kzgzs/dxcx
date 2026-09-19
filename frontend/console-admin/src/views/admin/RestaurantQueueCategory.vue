<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteQueueCategory,
  getQueueCategoryList,
  saveQueueCategory,
} from '../../api/queue'
import type { QueueCategoryRow } from '../../api/queue'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<QueueCategoryRow[]>([])

async function fetchList() {
  loading.value = true
  try {
    const data = await getQueueCategoryList()
    tableData.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// ============ 删除 ============
function handleDelete(row: QueueCategoryRow) {
  ElMessageBox.confirm(
    `确定要删除分类「${row.name}」吗？删除后该分类下的排队记录将一并删除。`,
    '删除确认',
    { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await deleteQueueCategory(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误由拦截器弹出
      }
    })
    .catch(() => {})
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  code: '',
  seat_min: 1,
  seat_max: 4,
  per_minute: 3,
  call_text: '请[排队号]号顾客前往就餐',
  sort: 1,
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
  code: [{ required: true, message: '请输入队列前缀', trigger: 'blur' }],
}

function openAdd() {
  Object.assign(form, {
    id: 0,
    name: '',
    code: '',
    seat_min: 1,
    seat_max: 4,
    per_minute: 3,
    call_text: '请[排队号]号顾客前往就餐',
    sort: 1,
    status: 1,
  })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

function openEdit(row: QueueCategoryRow) {
  Object.assign(form, {
    id: row.id,
    name: row.name,
    code: row.code,
    seat_min: row.seat_min,
    seat_max: row.seat_max,
    per_minute: row.per_minute,
    call_text: row.call_text,
    sort: row.sort,
    status: row.status,
  })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveQueueCategory({
        id: form.id,
        name: form.name,
        code: form.code,
        seat_min: form.seat_min,
        seat_max: form.seat_max,
        per_minute: form.per_minute,
        call_text: form.call_text,
        sort: form.sort,
        status: form.status,
      })
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
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">队列设置</span>
        <el-button type="primary" @click="openAdd">新增分类</el-button>
      </div>
    </template>

    <el-table v-loading="loading" :data="tableData" stripe border size="small">
      <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
      <el-table-column prop="name" label="分类名称" min-width="110" show-overflow-tooltip />
      <el-table-column prop="code" label="队列前缀" width="80" class-name="compact-col" />
      <el-table-column label="人数段" width="90" class-name="compact-col">
        <template #default="{ row }">{{ row.seat_min }} - {{ row.seat_max }} 人</template>
      </el-table-column>
      <el-table-column label="每号约等" width="90" class-name="compact-col">
        <template #default="{ row }">{{ row.per_minute }} 分钟</template>
      </el-table-column>
      <el-table-column prop="call_text" label="叫号模板" min-width="160" show-overflow-tooltip />
      <el-table-column label="今日排队" width="84" class-name="compact-col">
        <template #default="{ row }">{{ row.waitnum || 0 }} 桌</template>
      </el-table-column>
      <el-table-column label="状态" width="72" class-name="compact-col">
        <template #default="{ row }">
          <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '启用' : '停用' }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="60" class-name="compact-col" />
      <el-table-column label="操作" width="110" fixed="right" class-name="compact-col">
        <template #default="{ row }">
          <div class="op-wrap">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <!-- 新增 / 编辑弹窗 -->
  <el-dialog v-model="dialogVisible" :title="form.id > 0 ? '编辑分类' : '新增分类'" width="560px" destroy-on-close>
    <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" class="compact-form">
      <el-form-item label="分类名称" prop="name">
        <el-input v-model="form.name" placeholder="如：小桌、中桌、大桌" maxlength="50" />
      </el-form-item>
      <el-form-item label="队列前缀" prop="code">
        <el-input v-model="form.code" placeholder="如：A，取号后为 A01/A02" maxlength="4" style="width: 160px" />
        <span class="form-tip">取号 = 前缀 + 当日序号（两位）</span>
      </el-form-item>
      <el-form-item label="人数段">
        <el-input-number v-model="form.seat_min" :min="0" :max="99" />
        <span class="form-tip">至</span>
        <el-input-number v-model="form.seat_max" :min="0" :max="99" />
        <span class="form-tip">人</span>
      </el-form-item>
      <el-form-item label="每号约等">
        <el-input-number v-model="form.per_minute" :min="1" :max="999" />
        <span class="form-tip">分钟，用于预计等待时长</span>
      </el-form-item>
      <el-form-item label="叫号模板">
        <el-input v-model="form.call_text" placeholder="请[排队号]号顾客前往就餐" maxlength="100" />
        <span class="form-tip">支持变量 [排队号]，语音播报走浏览器 TTS</span>
      </el-form-item>
      <el-form-item label="状态">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">启用</el-radio>
          <el-radio :value="0">停用</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="排序">
        <el-input-number v-model="form.sort" :min="0" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-title {
  font-weight: 600;
}

/* 操作列：link 文字按钮单行排布（紧凑间距） */
.op-wrap {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 2px;
  white-space: nowrap;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}

.status-on {
  color: #67c23a;
}

.status-off {
  color: #909399;
}

.compact-form :deep(.el-form-item) {
  margin-bottom: 10px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
