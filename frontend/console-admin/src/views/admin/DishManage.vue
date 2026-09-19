<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules, UploadProps, UploadUserFile } from 'element-plus'
import { ArrowDown } from '@element-plus/icons-vue'
import {
  deleteDiningDish,
  getDiningDishDetail,
  getDiningDishList,
  getDiningDishOptions,
  saveDiningDish,
  setDiningDishStatus,
  setDiningDishStock,
} from '../../api/dining'
import type { DiningDishRow, DiningGuigeRow } from '../../api/dining'
import type { UploadRawResponse } from '../../api/types'
import { useAuthStore } from '../../stores/auth'

const authStore = useAuthStore()

// ============ 双视图模式 ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 选项（分类/区域） ============
const categoryOptions = ref<{ id: number; pid: number; name: string; status: number }[]>([])
const areaOptions = ref<{ id: number; name: string }[]>([])

async function fetchOptions() {
  try {
    const data = await getDiningDishOptions()
    categoryOptions.value = data.categories || []
    areaOptions.value = data.areas || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<DiningDishRow[]>([])
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
    const data = await getDiningDishList({
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

// ============ 上/下架 ============
async function handleToggleStatus(row: DiningDishRow) {
  const st = row.status === 1 ? 0 : 1
  try {
    await setDiningDishStatus(row.id, st)
    ElMessage.success(st === 1 ? '已上架' : '已下架')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 删除 ============
function handleDelete(row: DiningDishRow) {
  ElMessageBox.confirm(`确定要删除菜品「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteDiningDish(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 改库存弹窗 ============
const stockVisible = ref(false)
const stockSubmitting = ref(false)
const stockForm = reactive({ id: 0, name: '', stock: 0, stock_daily: 0 })

function openStock(row: DiningDishRow) {
  Object.assign(stockForm, { id: row.id, name: row.name, stock: row.stock, stock_daily: row.stock_daily })
  stockVisible.value = true
}

async function handleStockSubmit() {
  stockSubmitting.value = true
  try {
    await setDiningDishStock(stockForm.id, stockForm.stock, stockForm.stock_daily)
    ElMessage.success('库存已更新')
    stockVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    stockSubmitting.value = false
  }
}

// ============ 编辑表单（铺满主区域） ============
const formRef = ref<FormInstance>()
const editSubmitting = ref(false)
const detailLoading = ref(false)

const WEEK_OPTIONS = [
  { value: '1', label: '周一' },
  { value: '2', label: '周二' },
  { value: '3', label: '周三' },
  { value: '4', label: '周四' },
  { value: '5', label: '周五' },
  { value: '6', label: '周六' },
  { value: '7', label: '周日' },
]

const form = reactive({
  id: 0,
  name: '',
  procode: '',
  sellpoint: '',
  pic: '',
  pics: [] as string[],
  cid: [] as number[],
  area_id: 0,
  market_price: 0,
  sell_price: 0,
  cost_price: 0,
  pack_fee: 0,
  status: 1,
  status_week: ['1', '2', '3', '4', '5', '6', '7'],
  start_time: '',
  end_time: '',
  start_hours: '09:00',
  end_hours: '21:00',
  stock: 100,
  stock_daily: 0,
  sales: 0,
  limit_per: 0,
  limit_takeaway: 0,
  limit_start: 0,
  sort: 0,
  detail: '',
})

const guigeList = ref<DiningGuigeRow[]>([])

const rules: FormRules = {
  name: [{ required: true, message: '请输入菜品名称', trigger: 'blur' }],
  cid: [{ required: true, message: '请选择菜品分类', trigger: 'change' }],
  sell_price: [{ required: true, message: '请输入售价', trigger: 'blur' }],
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    name: '',
    procode: '',
    sellpoint: '',
    pic: '',
    pics: [],
    cid: [],
    area_id: 0,
    market_price: 0,
    sell_price: 0,
    cost_price: 0,
    pack_fee: 0,
    status: 1,
    status_week: ['1', '2', '3', '4', '5', '6', '7'],
    start_time: '',
    end_time: '',
    start_hours: '09:00',
    end_hours: '21:00',
    stock: 100,
    stock_daily: 0,
    sales: 0,
    limit_per: 0,
    limit_takeaway: 0,
    limit_start: 0,
    sort: 0,
    detail: '',
  })
  guigeList.value = []
  formRef.value?.clearValidate()
}

function openAdd() {
  resetForm()
  viewMode.value = 'edit'
}

async function openEdit(row: DiningDishRow) {
  resetForm()
  viewMode.value = 'edit'
  detailLoading.value = true
  try {
    const d = await getDiningDishDetail(row.id)
    Object.assign(form, {
      id: d.id,
      name: d.name,
      procode: d.procode,
      sellpoint: d.sellpoint,
      pic: d.pic,
      pics: d.pics || [],
      cid: d.cid || [],
      area_id: d.area_id,
      market_price: d.market_price,
      sell_price: d.sell_price,
      cost_price: d.cost_price,
      pack_fee: d.pack_fee,
      status: d.status,
      status_week: d.status_week && d.status_week.length > 0 ? d.status_week : ['1', '2', '3', '4', '5', '6', '7'],
      start_time: d.start_time,
      end_time: d.end_time,
      start_hours: d.start_hours || '09:00',
      end_hours: d.end_hours || '21:00',
      stock: d.stock,
      stock_daily: d.stock_daily,
      sales: d.sales,
      limit_per: d.limit_per,
      limit_takeaway: d.limit_takeaway,
      limit_start: d.limit_start,
      sort: d.sort,
      detail: d.detail || '',
    })
    guigeList.value = (d.guige || []).map((g) => ({ ...g }))
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    detailLoading.value = false
  }
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

function cancelEdit() {
  viewMode.value = 'list'
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    // 规格行必填校验
    for (const g of guigeList.value) {
      if (!g.name.trim()) {
        ElMessage.warning('规格名称不能为空，请补全或删除空规格行')
        return
      }
    }
    editSubmitting.value = true
    try {
      await saveDiningDish({
        id: form.id,
        name: form.name,
        procode: form.procode,
        sellpoint: form.sellpoint,
        pic: form.pic,
        pics: form.pics,
        cid: form.cid,
        area_id: form.area_id,
        market_price: form.market_price,
        sell_price: form.sell_price,
        cost_price: form.cost_price,
        pack_fee: form.pack_fee,
        status: form.status,
        status_week: form.status_week,
        start_time: form.start_time,
        end_time: form.end_time,
        start_hours: form.start_hours,
        end_hours: form.end_hours,
        stock: form.stock,
        stock_daily: form.stock_daily,
        sales: form.sales,
        limit_per: form.limit_per,
        limit_takeaway: form.limit_takeaway,
        limit_start: form.limit_start,
        sort: form.sort,
        detail: form.detail,
        guige: guigeList.value,
      })
      ElMessage.success('保存成功')
      backToList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      editSubmitting.value = false
    }
  })
}

// ============ 规格动态行 ============
function addGuige() {
  guigeList.value.push({
    name: '',
    pic: '',
    market_price: 0,
    cost_price: 0,
    sell_price: 0,
    stock: 0,
    stock_daily: 0,
    procode: '',
  })
}

function removeGuige(index: number) {
  guigeList.value.splice(index, 1)
}

// ============ 上传（主图/图集） ============
const uploadHeaders = computed(() => ({
  Authorization: `Bearer ${authStore.token}`,
}))

function parseUploadResponse(response: UploadRawResponse): string {
  if (response && response.code === 0 && response.data?.url) {
    return response.data.url
  }
  ElMessage.error(response?.msg || '上传失败')
  return ''
}

const mainFileList = computed<UploadUserFile[]>(() => (form.pic ? [{ name: 'pic', url: form.pic }] : []))

const onMainSuccess: UploadProps['onSuccess'] = (response) => {
  const url = parseUploadResponse(response as UploadRawResponse)
  if (url) form.pic = url
}

const onMainRemove = () => {
  form.pic = ''
}

const picsFileList = computed<UploadUserFile[]>(() =>
  form.pics.map((url, i) => ({ name: `pic-${i}`, url })),
)

const onPicsSuccess: UploadProps['onSuccess'] = (response) => {
  const url = parseUploadResponse(response as UploadRawResponse)
  if (url) form.pics.push(url)
}

const onPicsRemove = (file: UploadUserFile) => {
  form.pics = form.pics.filter((u) => u !== file.url)
}

const onUploadError: UploadProps['onError'] = () => {
  ElMessage.error('上传失败，请重试')
}

onMounted(() => {
  fetchOptions()
  fetchList()
})
</script>

<template>
  <!-- ============ 列表视图 ============ -->
  <el-card v-if="viewMode === 'list'" shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">菜品管理</span>
        <el-button type="primary" @click="openAdd">新增菜品</el-button>
      </div>
    </template>

    <!-- 搜索条 -->
    <div class="search-bar">
      <el-input v-model="query.keyword" placeholder="菜品名称/货号" clearable style="width: 180px" @keyup.enter="handleSearch" />
      <el-select v-model="query.cid" placeholder="全部分类" clearable style="width: 150px">
        <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
      </el-select>
      <el-select v-model="query.status" placeholder="上架状态" clearable style="width: 140px">
        <el-option label="当前在售" value="1" />
        <el-option label="当前未在售" value="0" />
      </el-select>
      <el-button type="primary" @click="handleSearch">查询</el-button>
      <el-button @click="handleReset">重置</el-button>
    </div>

    <el-table v-loading="loading" :data="tableData" stripe border size="small">
      <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
      <el-table-column label="菜品" min-width="150" show-overflow-tooltip>
        <template #default="{ row }">
          <div class="dish-cell">
            <el-image v-if="row.pic" :src="row.pic" fit="cover" class="cell-pic" />
            <div class="dish-cell-text">
              <div class="dish-name">{{ row.name }}</div>
              <div v-if="row.sellpoint" class="dish-sellpoint">{{ row.sellpoint }}</div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column prop="cid_names" label="分类" width="110" class-name="compact-col" show-overflow-tooltip>
        <template #default="{ row }">{{ row.cid_names || '-' }}</template>
      </el-table-column>
      <el-table-column label="售价" width="76" class-name="compact-col">
        <template #default="{ row }">{{ Number(row.sell_price).toFixed(2) }}</template>
      </el-table-column>
      <el-table-column prop="stock" label="库存" width="62" class-name="compact-col" />
      <el-table-column prop="sales" label="销量" width="62" class-name="compact-col" />
      <el-table-column label="上架方式" width="84" class-name="compact-col">
        <template #default="{ row }">
          <el-tag v-if="row.onsale === 1" type="success" size="small">{{ row.status_text }}</el-tag>
          <el-tag v-else type="info" size="small">{{ row.status_text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="当前在售" width="80" class-name="compact-col">
        <template #default="{ row }">
          <span :class="row.onsale === 1 ? 'status-on' : 'status-off'">{{ row.onsale === 1 ? '在售' : '未在售' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="196" fixed="right" class-name="compact-col">
        <template #default="{ row }">
          <div class="op-wrap">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="handleToggleStatus(row)">{{ row.status === 1 ? '下架' : '上架' }}</el-button>
            <el-dropdown trigger="click" @command="(cmd: string) => (cmd === 'stock' ? openStock(row) : handleDelete(row))">
              <el-button link type="primary">
                更多<el-icon class="more-arrow"><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="stock">改库存</el-dropdown-item>
                  <el-dropdown-item command="del">删除</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
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

  <!-- ============ 编辑视图（铺满主区域，紧凑行距） ============ -->
  <el-card v-else shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">{{ form.id > 0 ? '编辑菜品' : '新增菜品' }}</span>
        <div>
          <el-button @click="cancelEdit">取消</el-button>
          <el-button type="primary" :loading="editSubmitting" @click="handleSubmit">保存</el-button>
        </div>
      </div>
    </template>

    <el-form
      v-loading="detailLoading"
      ref="formRef"
      :model="form"
      :rules="rules"
      label-width="100px"
      label-position="right"
      class="compact-form"
    >
      <!-- 基本信息 -->
      <div class="section-title">基本信息</div>
      <el-row :gutter="16">
        <el-col :span="12">
          <el-form-item label="菜品名称" prop="name">
            <el-input v-model="form.name" placeholder="请输入菜品名称" maxlength="100" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="货号">
            <el-input v-model="form.procode" placeholder="选填" maxlength="50" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="菜品分类" prop="cid">
            <el-select v-model="form.cid" multiple placeholder="支持多分类" style="width: 100%">
              <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="出餐区域">
            <el-select v-model="form.area_id" placeholder="不指定" clearable style="width: 100%">
              <el-option v-for="a in areaOptions" :key="a.id" :label="a.name" :value="a.id" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="24">
          <el-form-item label="卖点">
            <el-input v-model="form.sellpoint" placeholder="选填，展示在菜品名称下方" maxlength="100" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="主图">
            <el-upload
              action="/admin/upload"
              name="file"
              :headers="uploadHeaders"
              list-type="picture-card"
              :file-list="mainFileList"
              :limit="1"
              :on-success="onMainSuccess"
              :on-remove="onMainRemove"
              :on-error="onUploadError"
              accept="image/*"
            >
              <span>上传</span>
            </el-upload>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="详情图集">
            <el-upload
              action="/admin/upload"
              name="file"
              :headers="uploadHeaders"
              list-type="picture-card"
              :file-list="picsFileList"
              :limit="9"
              :on-success="onPicsSuccess"
              :on-remove="onPicsRemove"
              :on-error="onUploadError"
              accept="image/*"
            >
              <span>上传</span>
            </el-upload>
          </el-form-item>
        </el-col>
      </el-row>

      <!-- 价格库存 -->
      <div class="section-title">价格与库存</div>
      <el-row :gutter="16">
        <el-col :span="8">
          <el-form-item label="售价" prop="sell_price">
            <el-input-number v-model="form.sell_price" :min="0" :precision="2" :step="1" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="市场价">
            <el-input-number v-model="form.market_price" :min="0" :precision="2" :step="1" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="成本价">
            <el-input-number v-model="form.cost_price" :min="0" :precision="2" :step="1" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="打包费">
            <el-input-number v-model="form.pack_fee" :min="0" :precision="2" :step="0.5" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="库存">
            <el-input-number v-model="form.stock" :min="0" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="每日库存">
            <el-input-number v-model="form.stock_daily" :min="0" style="width: 100%" />
            <div class="form-tip">0 为不限制；有规格时以规格合计为准</div>
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="显示销量">
            <el-input-number v-model="form.sales" :min="0" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="每桌限购">
            <el-input-number v-model="form.limit_per" :min="0" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="外卖限购">
            <el-input-number v-model="form.limit_takeaway" :min="0" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="起售数量">
            <el-input-number v-model="form.limit_start" :min="0" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="排序">
            <el-input-number v-model="form.sort" :min="0" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>

      <!-- 规格 -->
      <div class="section-title">
        规格
        <el-button link type="primary" class="section-action" @click="addGuige">+ 添加规格</el-button>
        <span class="form-tip">设置规格后，售价取最低规格价，库存为规格合计</span>
      </div>
      <el-table v-if="guigeList.length > 0" :data="guigeList" border size="small" class="guige-table">
        <el-table-column label="规格名称" min-width="130">
          <template #default="{ row }">
            <el-input v-model="row.name" placeholder="如：大份" size="small" />
          </template>
        </el-table-column>
        <el-table-column label="售价" width="120">
          <template #default="{ row }">
            <el-input-number v-model="row.sell_price" :min="0" :precision="2" size="small" controls-position="right" style="width: 100%" />
          </template>
        </el-table-column>
        <el-table-column label="市场价" width="120">
          <template #default="{ row }">
            <el-input-number v-model="row.market_price" :min="0" :precision="2" size="small" controls-position="right" style="width: 100%" />
          </template>
        </el-table-column>
        <el-table-column label="成本价" width="120">
          <template #default="{ row }">
            <el-input-number v-model="row.cost_price" :min="0" :precision="2" size="small" controls-position="right" style="width: 100%" />
          </template>
        </el-table-column>
        <el-table-column label="库存" width="100">
          <template #default="{ row }">
            <el-input-number v-model="row.stock" :min="0" size="small" controls-position="right" style="width: 100%" />
          </template>
        </el-table-column>
        <el-table-column label="每日库存" width="100">
          <template #default="{ row }">
            <el-input-number v-model="row.stock_daily" :min="0" size="small" controls-position="right" style="width: 100%" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="64">
          <template #default="{ $index }">
            <el-button link type="danger" @click="removeGuige($index)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <!-- 上架设置 -->
      <div class="section-title">上架设置</div>
      <el-form-item label="上架方式">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">直接上架</el-radio>
          <el-radio :value="2">定时上架</el-radio>
          <el-radio :value="3">时段上架</el-radio>
          <el-radio :value="0">下架</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="form.status === 2" label="定时区间">
        <el-date-picker
          v-model="form.start_time"
          type="datetime"
          placeholder="开始时间"
          value-format="YYYY-MM-DD HH:mm:ss"
          style="width: 220px"
        />
        <span class="time-sep">至</span>
        <el-date-picker
          v-model="form.end_time"
          type="datetime"
          placeholder="结束时间"
          value-format="YYYY-MM-DD HH:mm:ss"
          style="width: 220px"
        />
      </el-form-item>
      <el-form-item v-if="form.status === 3" label="营业时段">
        <el-time-picker v-model="form.start_hours" format="HH:mm" value-format="HH:mm" placeholder="开始" style="width: 130px" />
        <span class="time-sep">至</span>
        <el-time-picker v-model="form.end_hours" format="HH:mm" value-format="HH:mm" placeholder="结束" style="width: 130px" />
        <span class="form-tip">支持跨天（如 18:00 至 02:00）</span>
      </el-form-item>
      <el-form-item label="周几上架">
        <el-checkbox-group v-model="form.status_week">
          <el-checkbox v-for="w in WEEK_OPTIONS" :key="w.value" :value="w.value">{{ w.label }}</el-checkbox>
        </el-checkbox-group>
      </el-form-item>

      <!-- 详情 -->
      <div class="section-title">菜品详情</div>
      <el-form-item label="详情文字">
        <el-input v-model="form.detail" type="textarea" :rows="3" placeholder="选填" maxlength="2000" show-word-limit />
      </el-form-item>
    </el-form>
  </el-card>

  <!-- 改库存弹窗 -->
  <el-dialog v-model="stockVisible" :title="`改库存 - ${stockForm.name}`" width="420px" destroy-on-close>
    <el-form label-width="90px" class="compact-form">
      <el-form-item label="总库存">
        <el-input-number v-model="stockForm.stock" :min="0" style="width: 220px" />
      </el-form-item>
      <el-form-item label="每日库存">
        <el-input-number v-model="stockForm.stock_daily" :min="0" style="width: 220px" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="stockVisible = false">取消</el-button>
      <el-button type="primary" :loading="stockSubmitting" @click="handleStockSubmit">保存</el-button>
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

.dish-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.cell-pic {
  width: 36px;
  height: 36px;
  border-radius: 4px;
  flex-shrink: 0;
}

.dish-name {
  font-weight: 500;
  line-height: 1.3;
}

.dish-sellpoint {
  color: #909399;
  font-size: 12px;
  line-height: 1.3;
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

/* 编辑视图紧凑行距 */
.compact-form :deep(.el-form-item) {
  margin-bottom: 10px;
}

.compact-form :deep(.el-form-item__label) {
  line-height: 32px;
}

.section-title {
  margin: 6px 0 12px;
  padding-left: 8px;
  border-left: 3px solid var(--el-color-primary);
  font-weight: 600;
  font-size: 14px;
}

.section-action {
  margin-left: 12px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
  font-weight: normal;
}

.time-sep {
  margin: 0 8px;
  color: #606266;
}

.guige-table {
  margin-bottom: 14px;
}
</style>
