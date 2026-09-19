<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  checkTuangouProduct,
  deleteTuangouProduct,
  getTuangouCategoryList,
  getTuangouOrderList,
  getTuangouProductDetail,
  getTuangouProductList,
  refundTuangouProduct,
  saveTuangouProduct,
  setTuangouProductStatus,
} from '../../api/tuangou'
import type {
  TuangouCategoryItem,
  TuangouOrderItem,
  TuangouPriceTier,
  TuangouProductItem,
} from '../../api/tuangou'
import { formatDate, formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// 商户视图：bid 有值；自营视图：bid 无值
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchant = computed(() => !!props.bid)

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<TuangouProductItem[]>([])
const total = ref(0)
const selectedRows = ref<TuangouProductItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as number | '',
  checked: '' as number | '',
  cid: '' as number | '',
  orderField: '',
  orderType: 'desc' as 'asc' | 'desc' | '',
})

const categories = ref<TuangouCategoryItem[]>([])

async function fetchCategories() {
  try {
    categories.value = await getTuangouCategoryList()
  } catch {
    // 拦截器统一提示
  }
}

function handleSelectionChange(rows: TuangouProductItem[]) {
  selectedRows.value = rows
}

function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  query.orderField = order ? prop : ''
  query.orderType = order === 'ascending' ? 'asc' : 'desc'
  query.page = 1
  fetchList()
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getTuangouProductList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      status: query.status === '' ? undefined : query.status,
      checked: isMerchant.value && query.checked !== '' ? query.checked : undefined,
      cid: query.cid === '' ? undefined : query.cid,
      bid: props.bid || undefined,
      orderField: query.orderField || undefined,
      orderType: query.orderField ? query.orderType : undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 拦截器统一提示
  } finally {
    loading.value = false
  }
}

function handleSearch() {
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

// 活动状态：未上架/未开始/进行中/已结束
function actStatus(row: TuangouProductItem): { text: string; cls: string } {
  if (row.status !== 1) return { text: '未上架', cls: 'status-off' }
  const now = Math.floor(Date.now() / 1000)
  if (row.starttime > 0 && now < row.starttime) return { text: '未开始', cls: 'activity-empty' }
  if (row.endtime > 0 && now > row.endtime) return { text: '已结束', cls: 'activity-empty' }
  return { text: '进行中', cls: 'status-off' }
}

function checkState(row: TuangouProductItem): { text: string; cls: string } {
  if (row.ischecked === 1) return { text: '已通过', cls: 'status-on' }
  if (row.ischecked === 2) return { text: '已驳回', cls: 'status-off' }
  return { text: '待审核', cls: 'check-pending' }
}

// ============ 行操作 ============
function handleRowStatus(row: TuangouProductItem) {
  const target = row.status === 1 ? 0 : 1
  const tip = target === 1 ? '上架' : '下架'
  ElMessageBox.confirm(`确定要${tip}商品「${row.name}」吗？`, `${tip}确认`, {
    confirmButtonText: tip,
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setTuangouProductStatus([row.id], target)
        row.status = target
        ElMessage.success(`已${tip}`)
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleDelete(row: TuangouProductItem) {
  ElMessageBox.confirm(`确定要删除商品「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteTuangouProduct(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (!selectedRows.value.length) return
  ElMessageBox.confirm(`确定要删除选中的 ${selectedRows.value.length} 个商品吗？删除后不可恢复。`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteTuangouProduct(selectedRows.value.map((r) => r.id))
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleBatchStatus(status: number) {
  if (!selectedRows.value.length) return
  const tip = status === 1 ? '上架' : '下架'
  ElMessageBox.confirm(`确定要${tip}选中的 ${selectedRows.value.length} 个商品吗？`, `批量${tip}`, {
    confirmButtonText: tip,
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setTuangouProductStatus(selectedRows.value.map((r) => r.id), status)
        ElMessage.success(`已${tip}`)
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleCheck(row: TuangouProductItem, pass: boolean) {
  if (pass) {
    ElMessageBox.confirm(`确定通过「${row.name}」的审核吗？`, '审核通过', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'success',
    })
      .then(async () => {
        try {
          await checkTuangouProduct(row.id, 1)
          ElMessage.success('审核通过')
          fetchList()
        } catch {
          // 拦截器统一提示
        }
      })
      .catch(() => {})
    return
  }
  ElMessageBox.prompt('请输入驳回原因', '驳回审核', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputPlaceholder: '请输入驳回原因',
    inputValidator: (v) => (v && v.trim() ? true : '驳回原因不能为空'),
  })
    .then(async ({ value }) => {
      try {
        await checkTuangouProduct(row.id, 2, value.trim())
        ElMessage.success('已驳回')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleRefundDiff(row: TuangouProductItem) {
  ElMessageBox.confirm(`确定要对「${row.name}」执行退差价吗？将按当前已达成价格对已支付订单逐单退还差额。`, '退差价', {
    confirmButtonText: '确定退差价',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        const res = await refundTuangouProduct(row.id)
        ElMessage.success(`退差价完成，当前价 ${res.money} 元，共退 ${res.total} 元`)
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

// ============ 查看订单（大弹窗） ============
const orderDialogVisible = ref(false)
const orderLoading = ref(false)
const orderList = ref<TuangouOrderItem[]>([])
const orderTotal = ref(0)
const orderRow = ref<TuangouProductItem | null>(null)
const orderQuery = reactive({ page: 1, pagesize: 10 })

async function openOrders(row: TuangouProductItem) {
  orderRow.value = row
  orderQuery.page = 1
  orderDialogVisible.value = true
  await fetchOrders()
}

async function fetchOrders() {
  if (!orderRow.value) return
  orderLoading.value = true
  try {
    const data = await getTuangouOrderList({
      page: orderQuery.page,
      pagesize: orderQuery.pagesize,
      keyword: orderRow.value.name,
      bid: props.bid || undefined,
    })
    orderList.value = data.list || []
    orderTotal.value = data.total || 0
  } catch {
    // 拦截器统一提示
  } finally {
    orderLoading.value = false
  }
}

// ============ 视图切换：列表 / 整页编辑 ============
const viewMode = ref<'list' | 'edit'>('list')
const activeTab = ref<'info' | 'detail'>('info')
const isEdit = ref(false)
const submitting = ref(false)
const formRef = ref<FormInstance>()

const form = reactive({
  id: 0,
  name: '',
  pic: '',
  pics: [] as string[],
  cid: '' as number | '',
  sellpoint: '',
  market_price: 0,
  sell_price: 0,
  cost_price: 0,
  weight: 100,
  stock: 100,
  perlimit: 0,
  starttime: '' as string,
  endtime: '' as string,
  sort: 0,
  status: 1,
  freighttype: 0,
  detail: '',
})

const pricedata = ref<TuangouPriceTier[]>([])

const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  cid: [{ required: true, message: '请选择商品分类', trigger: 'change' }],
  sell_price: [{ required: true, message: '请输入团购起价', trigger: 'blur' }],
}))

// ============ 选图弹窗 ============
const imgPickerVisible = ref(false)
const imgPickerMulti = ref(false)
let imgPickerSetter: ((urls: string[]) => void) | null = null
function openImgPicker(setter: (urls: string[]) => void, multi = false) {
  imgPickerMulti.value = multi
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  imgPickerSetter?.(urls)
  imgPickerSetter = null
}

function resetForm() {
  form.id = 0
  form.name = ''
  form.pic = ''
  form.pics = []
  form.cid = ''
  form.sellpoint = ''
  form.market_price = 0
  form.sell_price = 0
  form.cost_price = 0
  form.weight = 100
  form.stock = 100
  form.perlimit = 0
  form.starttime = ''
  form.endtime = ''
  form.sort = 0
  form.status = 1
  form.freighttype = 0
  form.detail = ''
  pricedata.value = [{ num: 2, money: 0 }]
}

function addTier() {
  pricedata.value.push({ num: 0, money: 0 })
}
function removeTier(index: number) {
  pricedata.value.splice(index, 1)
}

function openAdd() {
  isEdit.value = false
  resetForm()
  activeTab.value = 'info'
  viewMode.value = 'edit'
}

async function openEdit(row: TuangouProductItem) {
  isEdit.value = true
  try {
    const d = await getTuangouProductDetail(row.id)
    form.id = d.id
    form.name = d.name
    form.pic = d.pic || ''
    form.pics = d.pics ? String(d.pics).split(',').filter((s) => s !== '') : []
    form.cid = d.cid || ''
    form.sellpoint = d.sellpoint || ''
    form.market_price = d.market_price || 0
    form.sell_price = d.sell_price || 0
    form.cost_price = d.cost_price || 0
    form.weight = d.weight ?? 100
    form.stock = d.stock ?? 0
    form.perlimit = d.perlimit ?? 0
    form.starttime = d.starttime > 0 ? formatTime(d.starttime) : ''
    form.endtime = d.endtime > 0 ? formatTime(d.endtime) : ''
    form.sort = d.sort ?? 0
    form.status = d.status ?? 1
    form.freighttype = d.freighttype ?? 0
    form.detail = d.detail || ''
    pricedata.value =
      Array.isArray(d.pricedata) && d.pricedata.length ? d.pricedata.map((t) => ({ num: t.num, money: t.money })) : [{ num: 2, money: 0 }]
    activeTab.value = 'info'
    viewMode.value = 'edit'
  } catch {
    // 拦截器统一提示
  }
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

function toTs(v: string): number {
  if (!v) return 0
  const t = Math.floor(new Date(v.replace(/-/g, '/')).getTime() / 1000)
  return Number.isFinite(t) ? t : 0
}

async function submitForm() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveTuangouProduct({
        id: form.id || undefined,
        name: form.name,
        pic: form.pic,
        pics: form.pics.join(','),
        cid: form.cid,
        sellpoint: form.sellpoint,
        market_price: Number(form.market_price) || 0,
        sell_price: Number(form.sell_price) || 0,
        cost_price: Number(form.cost_price) || 0,
        weight: Number(form.weight) || 0,
        stock: Number(form.stock) || 0,
        perlimit: Number(form.perlimit) || 0,
        starttime: toTs(form.starttime),
        endtime: toTs(form.endtime),
        sort: Number(form.sort) || 0,
        status: form.status,
        freighttype: Number(form.freighttype) || 0,
        detail: form.detail,
        pricedata: pricedata.value
          .filter((t) => Number(t.num) > 0)
          .map((t) => ({ num: Number(t.num), money: Number(t.money) || 0 })),
      })
      ElMessage.success('保存成功')
      backToList()
    } catch {
      // 拦截器统一提示
    } finally {
      submitting.value = false
    }
  })
}

onMounted(() => {
  fetchCategories()
  fetchList()
})
</script>

<template>
  <div v-if="viewMode === 'list'">
    <!-- 操作 + 搜索 同一行 -->
    <el-card class="toolbar" shadow="never">
      <div class="toolbar-left">
        <el-button v-if="!isMerchant" type="success" @click="openAdd">添加</el-button>
        <el-button @click="handleBatchDelete">删除</el-button>
        <el-button @click="handleBatchStatus(1)">上架</el-button>
        <el-button @click="handleBatchStatus(0)">下架</el-button>
      </div>
      <el-form :inline="true" class="toolbar-search" @submit.prevent>
        <el-form-item label="商品名称">
          <el-input v-model="query.keyword" placeholder="商品名称" clearable style="width: 120px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="query.cid" placeholder="全部" clearable style="width: 110px">
            <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 90px">
            <el-option label="已上架" :value="1" />
            <el-option label="未上架" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="isMerchant" label="审核状态">
          <el-select v-model="query.checked" placeholder="全部" clearable style="width: 100px">
            <el-option label="待审核" :value="0" />
            <el-option label="已通过" :value="1" />
            <el-option label="已驳回" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor">
              <path d="M795.904 750.72l124.928 124.928a32 32 0 0 1-45.248 45.248L750.656 795.904a416 416 0 1 1 45.248-45.248zM480 832a352 352 0 1 0 0-704 352 352 0 0 0 0 704z" />
            </svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 表格 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange" @sort-change="handleSortChange">
        <el-table-column type="selection" width="40" align="center" />
        <el-table-column prop="id" label="ID" width="60" sortable="custom" align="center" />
        <el-table-column v-if="isMerchant" label="所属商户" width="120" align="center">
          <template #default="{ row }">{{ row.bname || '平台自营' }}</template>
        </el-table-column>
        <el-table-column label="商品信息" min-width="240">
          <template #default="{ row }">
            <div class="goods-info">
              <el-image v-if="row.pic" :src="row.pic" fit="cover" class="goods-info-img" />
              <div v-else class="goods-info-img goods-info-img-empty">无图</div>
              <div class="goods-info-meta">
                <div class="goods-info-name">
                  <span v-if="row.cname" class="goods-info-cat">{{ row.cname }} / </span>{{ row.name }}
                </div>
                <div class="goods-info-price">¥{{ row.sell_price }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="团购价格" min-width="180">
          <template #default="{ row }">
            <div v-for="(tier, i) in row.pricedata" :key="i" class="tier-line">
              <span>满{{ tier.num }}人次 {{ tier.money }}元</span>
              <span v-if="row.buynum >= tier.num" class="tier-reached">已达成</span>
              <span v-else class="tier-left">还差{{ tier.num - row.buynum }}人次</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="stock" label="库存" width="80" sortable="custom" align="center" />
        <el-table-column prop="buynum" label="参与人数" width="80" align="center" />
        <el-table-column prop="sort" label="序号" width="70" sortable="custom" align="center" />
        <el-table-column label="创建时间" width="150" sortable="custom" align="center">
          <template #default="{ row }">{{ formatDate(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <span :class="actStatus(row).cls">{{ actStatus(row).text }}</span>
          </template>
        </el-table-column>
        <el-table-column v-if="isMerchant" label="审核状态" width="86" align="center">
          <template #default="{ row }">
            <span :class="checkState(row).cls" :title="row.ischecked === 2 ? row.check_reason : ''">
              {{ checkState(row).text }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right" align="center" header-align="center">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleRowStatus(row)">
                {{ row.status === 1 ? '下架' : '上架' }}
              </el-button>
              <template v-if="isMerchant">
                <el-button v-if="row.ischecked === 0 || row.ischecked === 2" link type="success" @click="handleCheck(row, true)">通过</el-button>
                <el-button v-if="row.ischecked === 0 || row.ischecked === 1" link type="warning" @click="handleCheck(row, false)">驳回</el-button>
              </template>
              <el-button link type="primary" @click="openOrders(row)">查看订单</el-button>
              <el-button link type="warning" @click="handleRefundDiff(row)">退差价</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pagination"
        :current-page="query.page"
        :page-size="query.pagesize"
        :total="total"
        layout="total, sizes, prev, pager, next, jumper"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 查看订单大弹窗 -->
    <el-dialog v-model="orderDialogVisible" :title="`查看订单 - ${orderRow?.name || ''}`" width="90%" top="5vh">
      <el-table v-loading="orderLoading" :data="orderList" border>
        <el-table-column prop="ordernum" label="订单号" width="180" />
        <el-table-column label="买家" width="120">
          <template #default="{ row }">{{ row.buyer?.nickname || '-' }}</template>
        </el-table-column>
        <el-table-column prop="num" label="数量" width="70" />
        <el-table-column prop="product_price" label="商品金额" width="90" />
        <el-table-column prop="totalprice" label="订单金额" width="90" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">{{ row.status_text }}</template>
        </el-table-column>
        <el-table-column prop="linkman" label="联系人" width="100" />
        <el-table-column prop="tel" label="手机号" width="120" />
        <el-table-column label="下单时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pagination"
        :current-page="orderQuery.page"
        :page-size="orderQuery.pagesize"
        :total="orderTotal"
        layout="total, prev, pager, next"
        @current-change="(p: number) => { orderQuery.page = p; fetchOrders() }"
      />
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="imgPickerMulti" default-tab="library" @pick="onImgPick" />
  </div>

  <!-- 整页编辑 -->
  <div v-else class="goods-edit">
    <div class="ge-head">
      <div class="ge-head-title">{{ isEdit ? '编辑商品' : '添加商品' }}</div>
      <div class="ge-head-close" @click="backToList">
        <svg viewBox="0 0 1024 1024" width="18" height="18" fill="currentColor">
          <path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" />
        </svg>
      </div>
    </div>

    <el-tabs v-model="activeTab" class="ge-tabs">
      <el-tab-pane label="商品基本信息" name="info">
        <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" class="ge-form">
          <el-form-item label="商品名称" required>
            <el-input v-model="form.name" style="width: 420px" maxlength="60" show-word-limit />
          </el-form-item>
          <el-form-item label="商品主图" required>
            <el-button @click="openImgPicker((urls) => { if (urls[0]) form.pic = urls[0] })">上传图片</el-button>
            <div class="ge-upload-box">
              <template v-if="form.pic">
                <el-image :src="form.pic" fit="cover" class="main-pic" />
                <span class="ge-upload-del" @click="form.pic = ''">×</span>
              </template>
              <div v-else class="ge-upload-add" @click="openImgPicker((urls) => { if (urls[0]) form.pic = urls[0] })">+</div>
            </div>
          </el-form-item>
          <el-form-item label="商品图片">
            <el-button @click="openImgPicker((urls) => { urls.forEach((u) => { if (!form.pics.includes(u)) form.pics.push(u) }) }, true)">
              批量上传
            </el-button>
            <div class="ge-thumb-row">
              <div v-for="(url, i) in form.pics" :key="url" class="ge-thumb-item">
                <el-image :src="url" fit="cover" class="pic-thumb" />
                <span class="ge-upload-del" @click="form.pics.splice(i, 1)">×</span>
              </div>
            </div>
          </el-form-item>
          <el-form-item label="商品分类" required>
            <el-select v-model="form.cid" placeholder="-请选择-" style="width: 300px">
              <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="商品卖点">
            <el-input v-model="form.sellpoint" style="width: 420px" maxlength="60" show-word-limit />
          </el-form-item>
          <el-form-item label="团购起价" required>
            <el-input-number v-model="form.sell_price" :min="0" :precision="2" :step="0.1" />
            <span class="ge-hint">成团后的初始价格（最高价）</span>
          </el-form-item>
          <el-form-item label="市场价">
            <el-input-number v-model="form.market_price" :min="0" :precision="2" :step="0.1" />
          </el-form-item>
          <el-form-item label="成本价">
            <el-input-number v-model="form.cost_price" :min="0" :precision="2" :step="0.1" />
          </el-form-item>
          <el-form-item label="库存">
            <el-input-number v-model="form.stock" :min="0" :precision="0" />
          </el-form-item>
          <el-form-item label="重量(g)">
            <el-input-number v-model="form.weight" :min="0" :precision="0" />
          </el-form-item>
          <el-form-item label="每人限购">
            <el-input-number v-model="form.perlimit" :min="0" :precision="0" />
            <span class="ge-hint">0 为不限购</span>
          </el-form-item>
          <el-form-item label="活动时间">
            <el-date-picker v-model="form.starttime" type="datetime" placeholder="开始时间" value-format="YYYY-MM-DD HH:mm:ss" />
            <span class="ge-hint">至</span>
            <el-date-picker v-model="form.endtime" type="datetime" placeholder="结束时间" value-format="YYYY-MM-DD HH:mm:ss" />
          </el-form-item>
          <el-form-item label="排序">
            <el-input-number v-model="form.sort" :min="0" :precision="0" />
          </el-form-item>
          <el-form-item label="状态">
            <el-radio-group v-model="form.status">
              <el-radio :value="1">上架</el-radio>
              <el-radio :value="0">下架</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="阶梯价设置">
            <div class="ge-tier">
              <div v-for="(tier, i) in pricedata" :key="i" class="ge-tier-row">
                <span>满</span>
                <el-input-number v-model="tier.num" :min="1" :precision="0" size="small" />
                <span>人次</span>
                <el-input-number v-model="tier.money" :min="0" :precision="2" :step="0.1" size="small" />
                <span>元</span>
                <el-button link type="danger" @click="removeTier(i)">删除</el-button>
              </div>
              <el-button size="small" @click="addTier">添加阶梯价</el-button>
            </div>
          </el-form-item>
        </el-form>
      </el-tab-pane>

      <el-tab-pane label="商品详情" name="detail">
        <el-form label-width="110px" class="ge-form">
          <el-form-item label="商品详情">
            <el-input v-model="form.detail" type="textarea" :rows="12" placeholder="请输入商品详情" style="width: 720px" />
          </el-form-item>
        </el-form>
      </el-tab-pane>
    </el-tabs>

    <div class="tab-footer">
      <el-button @click="backToList">取消</el-button>
      <el-button type="primary" :loading="submitting" @click="submitForm">保存</el-button>
    </div>

    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="imgPickerMulti" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 12px; }
.toolbar :deep(.el-card__body) { padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; flex: 1 1 auto; justify-content: flex-end; flex-wrap: nowrap; min-width: 0; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 8px; }
.toolbar-search :deep(.el-form-item:last-child) { margin-right: 0; }
.toolbar-search :deep(.el-form-item__label) { padding-right: 6px; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }

.goods-info { display: flex; align-items: center; gap: 10px; }
.goods-info-img { width: 70px; height: 70px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.goods-info-img-empty { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 12px; background: #fafafa; }
.goods-info-meta { min-width: 0; flex: 1; }
.goods-info-name { color: #303133; font-size: 13px; line-height: 1.5; word-break: break-all; }
.goods-info-cat { color: #f56c6c; margin-right: 4px; }
.goods-info-price { color: #ff6600; font-weight: 600; font-size: 14px; margin-top: 6px; }

.tier-line { font-size: 12px; line-height: 1.7; }
.tier-reached { color: #f56c6c; margin-left: 6px; }
.tier-left { color: #c0c4cc; margin-left: 6px; }

.status-on { color: #21ba45; }
.status-off { color: #f56c6c; }
.check-pending { color: #409eff; }
.activity-empty { color: #c0c4cc; }

.row-ops { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; line-height: 1.5; }
.row-ops .el-button { margin: 0; height: auto; min-height: 0; padding: 0; font-size: 12px; }
.row-ops :deep(.el-button) { height: auto; padding: 1px 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 16px; justify-content: flex-end; }

/* 整页编辑 */
.goods-edit { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; }
.ge-head-title { font-size: 16px; font-weight: 600; color: #303133; flex: 1; }
.ge-head-close { cursor: pointer; color: #909399; display: flex; align-items: center; }
.ge-head-close:hover { color: #409eff; }
.ge-tabs { margin-bottom: 8px; }
.ge-form :deep(.el-form-item) { margin-bottom: 15px; }
.ge-hint { color: #909399; font-size: 12px; margin-left: 8px; }
.ge-upload-box { margin-top: 8px; }
.ge-upload-add { width: 80px; height: 80px; border: 1px dashed #dcdfe6; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #909399; font-size: 24px; cursor: pointer; }
.ge-upload-del { position: absolute; margin-left: -18px; color: #f56c6c; cursor: pointer; font-size: 16px; }
.main-pic { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }
.ge-thumb-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
.ge-thumb-item { display: flex; flex-direction: column; align-items: center; }
.pic-thumb { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }
.ge-tier { display: flex; flex-direction: column; gap: 8px; }
.ge-tier-row { display: flex; align-items: center; gap: 8px; }
.tab-footer { display: flex; justify-content: center; gap: 14px; padding: 16px 0 8px; border-top: 1px solid #ebeef5; }
</style>
