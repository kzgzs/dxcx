<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules, UploadProps } from 'element-plus'
import {
  deleteTuangouCode,
  exportTuangouCode,
  getTuangouCodeList,
  getTuangouProductList,
  importTuangouCode,
  makeTuangouCode,
} from '../../api/tuangou'
import type { TuangouCodeItem, TuangouProductItem } from '../../api/tuangou'

// ============ 商品选项（供生成/导入选择） ============
const products = ref<TuangouProductItem[]>([])

async function fetchProducts() {
  try {
    const data = await getTuangouProductList({ page: 1, pagesize: 200 })
    products.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function productName(proid: number) {
  const item = products.value.find((p) => p.id === proid)
  return item ? item.name : `商品${proid}`
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<TuangouCodeItem[]>([])
const total = ref(0)
const selectedRows = ref<TuangouCodeItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 20,
  proid: '' as number | '',
  content: '',
  ordernum: '',
  nickname: '',
  status: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getTuangouCodeList({
      page: query.page,
      pagesize: query.pagesize,
      proid: query.proid === '' ? undefined : query.proid,
      content: query.content || undefined,
      ordernum: query.ordernum || undefined,
      nickname: query.nickname || undefined,
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
  query.proid = ''
  query.content = ''
  query.ordernum = ''
  query.nickname = ''
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

function handleSelectionChange(rows: TuangouCodeItem[]) {
  selectedRows.value = rows
}

async function handleExport() {
  try {
    await exportTuangouCode(query.proid === '' ? 0 : query.proid)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 批量删除（仅未售出） ============
function handleBatchDelete() {
  const rows = selectedRows.value.filter((r) => r.status === 0)
  if (rows.length === 0) {
    ElMessage.warning('请勾选未售出的卡密')
    return
  }
  if (rows.length !== selectedRows.value.length) {
    ElMessage.warning('已选中的售出卡密将被跳过')
  }
  ElMessageBox.confirm(`确定删除选中的 ${rows.length} 条未售出卡密吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteTuangouCode(rows.map((r) => r.id))
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 生成卡密 ============
const makeVisible = ref(false)
const makeFormRef = ref<FormInstance>()
const makeSubmitting = ref(false)
const makeForm = reactive({
  proid: 0,
  num: 100,
  length: 12,
  type: 5,
})

const makeRules: FormRules = {
  proid: [{ required: true, message: '请选择商品', trigger: 'change' }],
}

const codeTypeOptions = [
  { value: 1, label: '纯数字' },
  { value: 2, label: '大小写字母' },
  { value: 3, label: '小写字母' },
  { value: 4, label: '大写字母' },
  { value: 5, label: '数字+小写字母' },
  { value: 6, label: '数字+大写字母' },
]

function openMake() {
  makeForm.proid = query.proid === '' ? 0 : query.proid
  makeVisible.value = true
}

async function handleMakeSubmit() {
  if (!makeFormRef.value) return
  await makeFormRef.value.validate(async (valid) => {
    if (!valid) return
    makeSubmitting.value = true
    try {
      const res = await makeTuangouCode({
        proid: makeForm.proid,
        num: makeForm.num,
        length: makeForm.length,
        type: makeForm.type,
      })
      ElMessage.success(`生成成功，新增 ${res.created} 条`)
      makeVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      makeSubmitting.value = false
    }
  })
}

// ============ 导入卡密 ============
const importVisible = ref(false)
const importProid = ref<number>(0)
const importing = ref(false)

function openImport() {
  importProid.value = query.proid === '' ? 0 : query.proid
  importVisible.value = true
}

const handleImportUpload: UploadProps['httpRequest'] = async (options) => {
  if (!importProid.value) {
    ElMessage.warning('请先选择商品')
    throw new Error('no product')
  }
  importing.value = true
  try {
    const res = await importTuangouCode(importProid.value, options.file as File)
    ElMessage.success(`导入成功 ${res.inserted} 条，重复跳过 ${res.duplicated} 条`)
    importVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    importing.value = false
  }
}

onMounted(() => {
  fetchProducts()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="商品">
          <el-select v-model="query.proid" placeholder="全部" clearable filterable style="width: 200px">
            <el-option v-for="p in products" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="卡密">
          <el-input v-model="query.content" placeholder="卡密内容" clearable style="width: 140px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" placeholder="订单号" clearable style="width: 160px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="买家">
          <el-input v-model="query.nickname" placeholder="买家昵称" clearable style="width: 140px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="未售出" :value="0" />
            <el-option label="已售出" :value="1" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button @click="handleBatchDelete">批量删除</el-button>
          <el-button @click="openImport">导入卡密</el-button>
          <el-button @click="handleExport">导出卡密</el-button>
          <el-button type="success" @click="openMake">生成卡密</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 卡密列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="商品" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ productName(row.proid) }}</template>
        </el-table-column>
        <el-table-column prop="content" label="卡密内容" min-width="180" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '已售出' : '未售出' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="ordernum" label="售出订单" width="190" />
        <el-table-column prop="nickname" label="买家" width="120" />
        <el-table-column prop="create_time" label="生成时间" width="160" />
        <el-table-column prop="buy_time" label="售出时间" width="160" />
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[20, 50, 100, 200]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 生成卡密弹窗 -->
    <el-dialog v-model="makeVisible" title="生成卡密" width="460px">
      <el-form
        ref="makeFormRef"
        :model="makeForm"
        :rules="makeRules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="商品" prop="proid">
          <el-select v-model="makeForm.proid" placeholder="请选择商品" filterable style="width: 100%">
            <el-option v-for="p in products" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="生成数量">
          <el-input-number v-model="makeForm.num" :min="1" :max="5000" :precision="0" :step="100" />
        </el-form-item>
        <el-form-item label="卡密长度">
          <el-input-number v-model="makeForm.length" :min="1" :max="52" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="字符类型">
          <el-select v-model="makeForm.type" style="width: 100%">
            <el-option v-for="opt in codeTypeOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="makeVisible = false">取消</el-button>
        <el-button type="primary" :loading="makeSubmitting" @click="handleMakeSubmit">生成</el-button>
      </template>
    </el-dialog>

    <!-- 导入卡密弹窗 -->
    <el-dialog v-model="importVisible" title="导入卡密" width="460px">
      <el-form label-width="90px" label-position="left">
        <el-form-item label="商品">
          <el-select v-model="importProid" placeholder="请选择商品" filterable style="width: 100%">
            <el-option v-for="p in products" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="文件">
          <el-upload
            drag
            accept=".xlsx,.xls,.csv"
            :show-file-list="false"
            :http-request="handleImportUpload"
          >
            <div class="upload-drag">
              <div>点击或拖拽文件到此处上传</div>
              <div class="text-muted">支持 xlsx/xls/csv，首列为卡密内容</div>
            </div>
          </el-upload>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :loading="importing" @click="importVisible = false">关闭</el-button>
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

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.text-muted {
  color: #909399;
  font-size: 12px;
}

.upload-drag {
  padding: 12px 0;
}
</style>
