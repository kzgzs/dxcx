<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteDiningPrinter,
  getDiningPrinterList,
  saveDiningPrinter,
  testDiningPrinter,
} from '../../api/dining'
import type { DiningPrinterRow } from '../../api/dining'

// ============ 类型字典 ============
const TYPE_OPTIONS = [
  { value: 0, label: '易联云' },
  { value: 1, label: '飞鹅' },
  { value: 3, label: '大趣' },
  { value: 4, label: '芯烨' },
]

const VOICE_OPTIONS = [
  { value: 0, label: '不播报' },
  { value: 1, label: '低音' },
  { value: 2, label: '中音' },
  { value: 3, label: '高音' },
]

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<DiningPrinterRow[]>([])

async function fetchList() {
  loading.value = true
  try {
    const data = await getDiningPrinterList()
    tableData.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// ============ 删除 ============
function handleDelete(row: DiningPrinterRow) {
  ElMessageBox.confirm(`确定要删除打印机「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteDiningPrinter(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 测试（一期仅配置完整性校验） ============
async function handleTest(row: DiningPrinterRow) {
  try {
    await testDiningPrinter(row.id)
    ElMessageBox.alert('配置校验通过，云打印在线测试将在二期开放。', '测试打印机', {
      confirmButtonText: '知道了',
      type: 'success',
    })
  } catch {
    // 配置不完整等错误由拦截器弹出
  }
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  title: '',
  type: 0,
  client_id: '',
  client_secret: '',
  machine_code: '',
  msign: '',
  status: 1,
  autoprint: 1,
  voice: 2,
  print_ps: 1,
  tmpltype: 0,
  tmplcontent: '',
  width: 40,
  height: 30,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入打印机名称', trigger: 'blur' }],
  type: [{ required: true, message: '请选择打印机类型', trigger: 'change' }],
  client_id: [{ required: true, message: '请输入应用 client_id', trigger: 'blur' }],
  machine_code: [{ required: true, message: '请输入终端号', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, {
    id: 0,
    name: '',
    title: '',
    type: 0,
    client_id: '',
    client_secret: '',
    machine_code: '',
    msign: '',
    status: 1,
    autoprint: 1,
    voice: 2,
    print_ps: 1,
    tmpltype: 0,
    tmplcontent: '',
    width: 40,
    height: 30,
  })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

function openEdit(row: DiningPrinterRow) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    name: row.name,
    title: row.title,
    type: row.type,
    client_id: row.client_id,
    client_secret: '', // 密钥不回显，留空表示沿用旧值
    machine_code: row.machine_code,
    msign: row.msign,
    status: row.status,
    autoprint: row.autoprint,
    voice: row.voice,
    print_ps: row.print_ps,
    tmpltype: row.tmpltype,
    tmplcontent: row.tmplcontent,
    width: row.width,
    height: row.height,
  })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if (!isEdit.value && !form.client_secret.trim()) {
      ElMessage.warning('请填写应用 client_secret')
      return
    }
    submitting.value = true
    try {
      await saveDiningPrinter({
        id: form.id,
        name: form.name,
        title: form.title,
        type: form.type,
        client_id: form.client_id,
        client_secret: form.client_secret,
        machine_code: form.machine_code,
        msign: form.msign,
        status: form.status,
        autoprint: form.autoprint,
        voice: form.voice,
        print_ps: form.print_ps,
        tmpltype: form.tmpltype,
        tmplcontent: form.tmplcontent,
        width: form.width,
        height: form.height,
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
        <span class="card-title">小票打印机</span>
        <el-button type="primary" @click="openAdd">新增打印机</el-button>
      </div>
    </template>

    <el-table v-loading="loading" :data="tableData" stripe border size="small">
      <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
      <el-table-column prop="name" label="打印机名称" min-width="130" show-overflow-tooltip />
      <el-table-column prop="type_text" label="类型" width="80" class-name="compact-col" />
      <el-table-column prop="machine_code" label="终端号" width="130" class-name="compact-col" show-overflow-tooltip />
      <el-table-column label="自动打印" width="84" class-name="compact-col">
        <template #default="{ row }">
          <span :class="row.autoprint === 1 ? 'status-on' : 'status-off'">{{ row.autoprint === 1 ? '开启' : '关闭' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="语音播报" width="80" class-name="compact-col">
        <template #default="{ row }">{{ VOICE_OPTIONS.find((v) => v.value === row.voice)?.label || '-' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="72" class-name="compact-col">
        <template #default="{ row }">
          <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '启用' : '停用' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="150" fixed="right" class-name="compact-col">
        <template #default="{ row }">
          <div class="op-wrap">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="handleTest(row)">测试</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <!-- 新增 / 编辑弹窗 -->
  <el-dialog v-model="dialogVisible" :title="form.id > 0 ? '编辑打印机' : '新增打印机'" width="620px" destroy-on-close>
    <el-form ref="formRef" :model="form" :rules="rules" label-width="120px" class="compact-form">
      <el-form-item label="打印机名称" prop="name">
        <el-input v-model="form.name" placeholder="如：前台打印机" maxlength="50" />
      </el-form-item>
      <el-form-item label="打印机类型" prop="type">
        <el-select v-model="form.type" style="width: 220px">
          <el-option v-for="t in TYPE_OPTIONS" :key="t.value" :label="t.label" :value="t.value" />
        </el-select>
      </el-form-item>
      <el-form-item label="应用 client_id" prop="client_id">
        <el-input v-model="form.client_id" placeholder="云平台应用 ID" maxlength="100" />
      </el-form-item>
      <el-form-item label="client_secret" :required="!isEdit">
        <el-input
          v-model="form.client_secret"
          type="password"
          show-password
          :placeholder="isEdit ? '留空表示不修改' : '云平台应用密钥'"
          maxlength="100"
        />
      </el-form-item>
      <el-form-item label="终端号" prop="machine_code">
        <el-input v-model="form.machine_code" placeholder="打印机机身编号" maxlength="100" />
      </el-form-item>
      <el-form-item label="终端密钥 msign">
        <el-input v-model="form.msign" placeholder="选填（飞鹅为 KEY）" maxlength="100" />
      </el-form-item>
      <el-form-item label="小票标题">
        <el-input v-model="form.title" placeholder="打印小票顶部文字" maxlength="50" />
      </el-form-item>
      <el-form-item label="自动打印">
        <el-radio-group v-model="form.autoprint">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="语音播报">
        <el-select v-model="form.voice" style="width: 160px">
          <el-option v-for="v in VOICE_OPTIONS" :key="v.value" :label="v.label" :value="v.value" />
        </el-select>
      </el-form-item>
      <el-form-item label="配送单打印">
        <el-radio-group v-model="form.print_ps">
          <el-radio :value="1">打印</el-radio>
          <el-radio :value="0">不打印</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="状态">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">启用</el-radio>
          <el-radio :value="0">停用</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="模板类型">
        <el-radio-group v-model="form.tmpltype">
          <el-radio :value="0">默认模板</el-radio>
          <el-radio :value="1">自定义模板</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="form.tmpltype === 1" label="模板内容">
        <el-input v-model="form.tmplcontent" type="textarea" :rows="4" placeholder="自定义小票模板（二期开放变量替换）" />
      </el-form-item>
      <el-form-item label="纸张宽度">
        <el-input-number v-model="form.width" :min="30" :max="80" />
        <span class="form-tip">mm</span>
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
