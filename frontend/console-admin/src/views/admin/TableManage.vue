<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { ArrowDown } from '@element-plus/icons-vue'
import {
  createDiningTables,
  deleteDiningTable,
  getDiningTableCategoryList,
  getDiningTableList,
  getDiningTableQrcode,
  saveDiningTable,
  setDiningTableStatus,
} from '../../api/dining'
import type { DiningTableCategoryRow, DiningTableRow, DiningTableStatus } from '../../api/dining'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { formatTime } from '../../utils/time'

// ============ 状态字典 ============
const STATUS_OPTIONS: { value: DiningTableStatus; label: string; type: 'danger' | 'success' | 'warning' | 'primary' | 'info' }[] = [
  { value: 0, label: '空闲', type: 'success' },
  { value: 1, label: '预定', type: 'warning' },
  { value: 2, label: '入座', type: 'primary' },
  { value: 3, label: '清台', type: 'info' },
  { value: -1, label: '禁用', type: 'danger' },
]

function statusMeta(st: number) {
  return STATUS_OPTIONS.find((s) => s.value === st) || { value: st as DiningTableStatus, label: '未知', type: 'info' as const }
}

// ============ 分类选项 ============
const categoryOptions = ref<DiningTableCategoryRow[]>([])

async function fetchCategories() {
  try {
    const data = await getDiningTableCategoryList()
    categoryOptions.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<DiningTableRow[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  cid: '' as number | '',
  status: '' as string,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getDiningTableList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      cid: query.cid === '' ? undefined : query.cid,
      status: query.status === '' ? undefined : query.status,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.keyword = ''
  query.cid = ''
  query.status = ''
  query.page = 1
  fetchList()
}

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

// ============ 改状态（状态机：后端校验未结算订单） ============
function handleSetStatus(row: DiningTableRow, st: DiningTableStatus) {
  if (row.status === st) return
  const meta = statusMeta(st)
  ElMessageBox.confirm(`确定将「${row.name}」改为「${meta.label}」吗？`, '状态确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setDiningTableStatus(row.id, st)
        ElMessage.success(`已改为${meta.label}`)
        fetchList()
      } catch {
        // 未结算订单拦截等错误由拦截器弹出
      }
    })
    .catch(() => {})
}

// ============ 删除 ============
function handleDelete(row: DiningTableRow) {
  ElMessageBox.confirm(`确定要删除餐桌「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteDiningTable(row.id)
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
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  cid: '' as number | '',
  pic: '',
  seat: 4,
  canbook: 1,
  sort: 0,
  status: 0,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入餐桌名称', trigger: 'blur' }],
  cid: [{ required: true, message: '请选择餐桌分类', trigger: 'change' }],
}

function openAdd() {
  Object.assign(form, { id: 0, name: '', cid: '', pic: '', seat: 4, canbook: 1, sort: 0, status: 0 })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

function openEdit(row: DiningTableRow) {
  Object.assign(form, {
    id: row.id,
    name: row.name,
    cid: row.cid,
    pic: row.pic,
    seat: row.seat,
    canbook: row.canbook,
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
      await saveDiningTable({
        id: form.id,
        name: form.name,
        cid: Number(form.cid),
        pic: form.pic,
        seat: form.seat,
        canbook: form.canbook,
        sort: form.sort,
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

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.pic = urls[0]
}

// ============ 批量建桌弹窗 ============
const batchVisible = ref(false)
const batchRef = ref<FormInstance>()
const batchSubmitting = ref(false)

const batchForm = reactive({
  makecount: 10,
  prefix: 'A',
  start: 1,
  not_have_number: '',
  cid: '' as number | '',
  seat: 4,
  canbook: 1,
})

const batchRules: FormRules = {
  makecount: [{ required: true, message: '请输入桌台数量', trigger: 'blur' }],
  start: [{ required: true, message: '请输入起始编号', trigger: 'blur' }],
  cid: [{ required: true, message: '请选择餐桌分类', trigger: 'change' }],
}

function openBatch() {
  Object.assign(batchForm, { makecount: 10, prefix: 'A', start: 1, not_have_number: '', cid: '', seat: 4, canbook: 1 })
  batchRef.value?.clearValidate()
  batchVisible.value = true
}

async function handleBatchSubmit() {
  if (!batchRef.value) return
  await batchRef.value.validate(async (valid) => {
    if (!valid) return
    batchSubmitting.value = true
    try {
      const data = await createDiningTables({
        makecount: batchForm.makecount,
        prefix: batchForm.prefix,
        start: batchForm.start,
        not_have_number: batchForm.not_have_number,
        cid: Number(batchForm.cid),
        seat: batchForm.seat,
        canbook: batchForm.canbook,
      })
      ElMessage.success(`成功创建 ${data.count || batchForm.makecount} 张餐桌`)
      batchVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      batchSubmitting.value = false
    }
  })
}

// ============ 二维码弹窗（桌码内容：点餐页 URL） ============
const qrcodeVisible = ref(false)
const qrcodeLoading = ref(false)
const qrcodeInfo = reactive({ id: 0, name: '', qrcode: '' })

async function openQrcode(row: DiningTableRow) {
  qrcodeInfo.id = row.id
  qrcodeInfo.name = row.name
  qrcodeInfo.qrcode = row.qrcode || ''
  qrcodeVisible.value = true
  if (!qrcodeInfo.qrcode) {
    qrcodeLoading.value = true
    try {
      const data = await getDiningTableQrcode(row.id)
      qrcodeInfo.qrcode = data.qrcode || ''
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      qrcodeLoading.value = false
    }
  }
}

async function copyQrcode() {
  try {
    await navigator.clipboard.writeText(qrcodeInfo.qrcode)
    ElMessage.success('已复制桌码链接')
  } catch {
    ElMessage.error('复制失败，请手动选择复制')
  }
}

onMounted(() => {
  fetchCategories()
  fetchList()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">餐桌管理</span>
        <div>
          <el-button @click="openBatch">批量建桌</el-button>
          <el-button type="primary" @click="openAdd">新增餐桌</el-button>
        </div>
      </div>
    </template>

    <!-- 搜索条 -->
    <div class="search-bar">
      <el-input v-model="query.keyword" placeholder="餐桌名称" clearable style="width: 180px" @keyup.enter="handleSearch" />
      <el-select v-model="query.cid" placeholder="全部分类" clearable style="width: 150px">
        <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
      </el-select>
      <el-select v-model="query.status" placeholder="全部状态" clearable style="width: 130px">
        <el-option v-for="s in STATUS_OPTIONS" :key="s.value" :label="s.label" :value="String(s.value)" />
      </el-select>
      <el-button type="primary" @click="handleSearch">查询</el-button>
      <el-button @click="handleReset">重置</el-button>
    </div>

    <!-- 列表（border 竖线 + 紧凑列宽） -->
    <el-table v-loading="loading" :data="tableData" stripe border size="small">
      <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
      <el-table-column prop="name" label="餐桌名称" min-width="110" show-overflow-tooltip />
      <el-table-column prop="category_name" label="分类" width="100" class-name="compact-col" show-overflow-tooltip>
        <template #default="{ row }">{{ row.category_name || '-' }}</template>
      </el-table-column>
      <el-table-column prop="seat" label="座位" width="60" class-name="compact-col" />
      <el-table-column label="可预定" width="70" class-name="compact-col">
        <template #default="{ row }">
          <span :class="row.canbook === 1 ? 'status-on' : 'status-off'">{{ row.canbook === 1 ? '是' : '否' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="76" class-name="compact-col">
        <template #default="{ row }">
          <el-tag :type="statusMeta(row.status).type" size="small">{{ statusMeta(row.status).label }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="占用订单" width="80" class-name="compact-col">
        <template #default="{ row }">{{ row.orderid > 0 ? `#${row.orderid}` : '-' }}</template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="60" class-name="compact-col" />
      <el-table-column label="创建时间" width="88" class-name="compact-col">
        <template #default="{ row }">
          <el-tooltip v-if="row.create_time" :content="formatTime(row.create_time)" placement="top">
            <span>{{ formatTime(row.create_time).slice(0, 10) }}</span>
          </el-tooltip>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="216" fixed="right" class-name="compact-col">
        <template #default="{ row }">
          <div class="op-wrap">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="openQrcode(row)">桌码</el-button>
            <el-dropdown trigger="click" @command="(cmd: string) => handleSetStatus(row, Number(cmd) as DiningTableStatus)">
              <el-button link type="primary">
                状态<el-icon class="more-arrow"><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item v-for="s in STATUS_OPTIONS" :key="s.value" :command="String(s.value)">
                    设为{{ s.label }}
                  </el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next, jumper"
        background
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </div>
  </el-card>

  <!-- 新增 / 编辑弹窗 -->
  <el-dialog v-model="dialogVisible" :title="form.id > 0 ? '编辑餐桌' : '新增餐桌'" width="520px" destroy-on-close>
    <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" class="compact-form">
      <el-form-item label="餐桌名称" prop="name">
        <el-input v-model="form.name" placeholder="如：A01" maxlength="50" />
      </el-form-item>
      <el-form-item label="餐桌分类" prop="cid">
        <el-select v-model="form.cid" placeholder="请选择分类" style="width: 100%">
          <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="桌台图片">
        <div class="img-pick-row">
          <el-image v-if="form.pic" :src="form.pic" class="img-pick-thumb" fit="cover" />
          <el-button size="small" @click="openImgPicker">{{ form.pic ? '更换' : '选择图片' }}</el-button>
          <el-button v-if="form.pic" size="small" link type="danger" @click="form.pic = ''">删除</el-button>
        </div>
      </el-form-item>
      <el-form-item label="座位数">
        <el-input-number v-model="form.seat" :min="0" :max="999" />
      </el-form-item>
      <el-form-item label="可否预定">
        <el-radio-group v-model="form.canbook">
          <el-radio :value="1">可预定</el-radio>
          <el-radio :value="0">不可预定</el-radio>
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

  <!-- 批量建桌弹窗 -->
  <el-dialog v-model="batchVisible" title="批量建桌" width="560px" destroy-on-close>
    <el-form ref="batchRef" :model="batchForm" :rules="batchRules" label-width="110px" class="compact-form">
      <el-form-item label="桌台数量" prop="makecount">
        <el-input-number v-model="batchForm.makecount" :min="1" :max="200" />
        <span class="form-tip">单次最多 200 张</span>
      </el-form-item>
      <el-form-item label="名称前缀">
        <el-input v-model="batchForm.prefix" placeholder="如：A" maxlength="10" style="width: 160px" />
      </el-form-item>
      <el-form-item label="起始编号" prop="start">
        <el-input-number v-model="batchForm.start" :min="1" />
      </el-form-item>
      <el-form-item label="排除数字">
        <el-input v-model="batchForm.not_have_number" placeholder="如：4,7（含该数字的编号跳过）" style="width: 260px" />
      </el-form-item>
      <el-form-item label="餐桌分类" prop="cid">
        <el-select v-model="batchForm.cid" placeholder="请选择分类" style="width: 100%">
          <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="座位数">
        <el-input-number v-model="batchForm.seat" :min="0" :max="999" />
      </el-form-item>
      <el-form-item label="可否预定">
        <el-radio-group v-model="batchForm.canbook">
          <el-radio :value="1">可预定</el-radio>
          <el-radio :value="0">不可预定</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="示例：前缀 A、起始 1、数量 5、排除 4 → A01、A02、A03、A05、A06"
      />
    </el-form>
    <template #footer>
      <el-button @click="batchVisible = false">取消</el-button>
      <el-button type="primary" :loading="batchSubmitting" @click="handleBatchSubmit">创建</el-button>
    </template>
  </el-dialog>

  <!-- 桌码弹窗 -->
  <el-dialog v-model="qrcodeVisible" :title="`桌码 - ${qrcodeInfo.name}`" width="460px" destroy-on-close>
    <div v-loading="qrcodeLoading" class="qrcode-box">
      <p class="qrcode-tip">桌码内容（点餐页链接，携带餐桌ID）：</p>
      <el-input v-model="qrcodeInfo.qrcode" type="textarea" :rows="3" readonly />
      <p class="qrcode-tip">可将该链接生成二维码打印贴于桌面，顾客扫码进入点餐页。</p>
    </div>
    <template #footer>
      <el-button @click="qrcodeVisible = false">关闭</el-button>
      <el-button type="primary" :disabled="!qrcodeInfo.qrcode" @click="copyQrcode">复制链接</el-button>
    </template>
  </el-dialog>

  <!-- 选图弹窗（任务294） -->
  <ImgIconPicker
    v-model:visible="imgPickerVisible"
    default-tab="library"
    @pick="(urls: string[]) => onImgPick(urls)"
  />
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

.search-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
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

.more-arrow {
  margin-left: 2px;
  font-size: 12px;
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

.qrcode-box {
  min-height: 120px;
}

.qrcode-tip {
  margin: 6px 0;
  color: #606266;
  font-size: 13px;
}

.img-pick-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.img-pick-thumb {
  width: 72px;
  height: 72px;
  border-radius: 6px;
  border: 1px solid #ebeef5;
}
</style>
