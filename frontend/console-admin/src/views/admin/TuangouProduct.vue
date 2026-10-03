<script setup lang="ts">
import { computed, inject, onMounted, reactive, ref } from 'vue'
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
  getTuangouAgentLevels,
  getTuangouFreightList,
} from '../../api/tuangou'
import type {
  TuangouCategoryItem,
  TuangouOrderItem,
  TuangouPriceTier,
  TuangouProductItem,
} from '../../api/tuangou'
import { getGoodsOptions } from '../../api/cuxiao'
import type { GoodsOption } from '../../api/cuxiao'
import type { DesignerModule } from '../../api/designer'
import { formatDate, formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'

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
  } catch { /* 拦截器统一提示 */ }
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
  } catch { /* 拦截器统一提示 */ } finally {
    loading.value = false
  }
}

function handleSearch() { query.page = 1; fetchList() }
function handlePageChange(page: number) { query.page = page; fetchList() }
function handleSizeChange(size: number) { query.pagesize = size; query.page = 1; fetchList() }

function actStatus(row: TuangouProductItem): { text: string; cls: string } {
  if (row.status !== 1) return { text: '未上架', cls: 'status-off' }
  const now = Math.floor(Date.now() / 1000)
  if (row.starttime > 0 && now < row.starttime) return { text: '未开始', cls: 'activity-empty' }
  if (row.endtime > 0 && now > row.endtime) return { text: '已结束', cls: 'activity-empty' }
  return { text: '进行中', cls: 'status-on' }
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
    confirmButtonText: tip, cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    try {
      await setTuangouProductStatus([row.id], target)
      row.status = target
      ElMessage.success(`已${tip}`)
    } catch { /* 拦截器 */ }
  }).catch(() => {})
}

function handleDelete(row: TuangouProductItem) {
  ElMessageBox.confirm(`确定要删除商品「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    await deleteTuangouProduct(row.id)
    ElMessage.success('删除成功'); fetchList()
  }).catch(() => {})
}

function handleBatchDelete() {
  if (!selectedRows.value.length) return
  ElMessageBox.confirm(`确定要删除选中的 ${selectedRows.value.length} 个商品吗？`, '批量删除', {
    confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    await deleteTuangouProduct(selectedRows.value.map((r) => r.id))
    ElMessage.success('删除成功'); fetchList()
  }).catch(() => {})
}

function handleBatchStatus(status: number) {
  if (!selectedRows.value.length) return
  const tip = status === 1 ? '上架' : '下架'
  ElMessageBox.confirm(`确定要${tip}选中的 ${selectedRows.value.length} 个商品吗？`, `批量${tip}`, {
    confirmButtonText: tip, cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    await setTuangouProductStatus(selectedRows.value.map((r) => r.id), status)
    ElMessage.success(`已${tip}`); fetchList()
  }).catch(() => {})
}

function handleCheck(row: TuangouProductItem, pass: boolean) {
  if (pass) {
    ElMessageBox.confirm(`确定通过「${row.name}」的审核吗？`, '审核通过', {
      confirmButtonText: '通过', cancelButtonText: '取消', type: 'success',
    }).then(async () => {
      await checkTuangouProduct(row.id, 1)
      ElMessage.success('审核通过'); fetchList()
    }).catch(() => {})
    return
  }
  ElMessageBox.prompt('请输入驳回原因', '驳回审核', {
    confirmButtonText: '驳回', cancelButtonText: '取消',
    inputPlaceholder: '请输入驳回原因',
    inputValidator: (v) => (v && v.trim() ? true : '驳回原因不能为空'),
  }).then(async ({ value }) => {
    await checkTuangouProduct(row.id, 2, value.trim())
    ElMessage.success('已驳回'); fetchList()
  }).catch(() => {})
}

function handleRefundDiff(row: TuangouProductItem) {
  ElMessageBox.confirm(`确定要对「${row.name}」执行退差价吗？`, '退差价', {
    confirmButtonText: '确定退差价', cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    const res = await refundTuangouProduct(row.id)
    ElMessage.success(`退差价完成，共退 ${res.total} 元`); fetchList()
  }).catch(() => {})
}

// ============ 查看订单 ============
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
    const data = await getTuangouOrderList({ page: orderQuery.page, pagesize: orderQuery.pagesize, keyword: orderRow.value.name, bid: props.bid || undefined })
    orderList.value = data.list || []
    orderTotal.value = data.total || 0
  } catch { /* 拦截器 */ } finally { orderLoading.value = false }
}

// ============ 编辑视图 ============
const viewMode = ref<'list' | 'edit'>('list')
const activeTab = ref<'info' | 'detail'>('info')
const isEdit = ref(false)
const submitting = ref(false)
const formRef = ref<FormInstance>()

// 可分销会员等级
const agentLevels = ref<{ id: number; name: string; can_agent: number }[]>([])
// 运费模板
const freightList = ref<{ id: number; name: string }[]>([])
const chosenFreight = ref<number[]>([])

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
  stock: 1000,
  sales: 0,
  perlimit: 0,
  starttime: '',
  endtime: '',
  sort: 0,
  status: 1,
  // 配送模板
  freighttype: 1 as number,
  freightdata: '',
  freightcontent: '',
  contact_require: 1,
  // 会员等级折扣 0跟随系统 1参与 2不参与
  vipdiscount: 0,
  // 分销
  commissionset: 0 as number,
  commissiondata1: {} as Record<string, Record<string, string>>,
  commissiondata2: {} as Record<string, Record<string, string>>,
  commissiondata3: {} as Record<string, Record<string, string>>,
  // 积分抵扣 0跟随 1比例 2金额 -1不可用
  scoredkmaxset: 0 as number,
  scoredkmaxval: 0,
  // 显示/购买条件
  showtj: ['-1'] as string[],
  gettj: ['-1'] as string[],
  detail: '',
})

const detailModules = ref<DesignerModule[]>([])
const pricedata = ref<TuangouPriceTier[]>([])

const tjOptions = computed(() => {
  const opts: { value: string; label: string }[] = [
    { value: '-1', label: '所有人' },
    { value: '0', label: '关注用户' },
  ]
  agentLevels.value.forEach((l) => opts.push({ value: String(l.id), label: l.name }))
  return opts
})

const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
}))

// ============ 选图 ============
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

// ============ 选择商城商品 ============
const goodsPickerVisible = ref(false)
const goodsOptions = ref<GoodsOption[]>([])
async function openGoodsPicker() {
  goodsPickerVisible.value = true
  try {
    const res = await getGoodsOptions('')
    goodsOptions.value = res.list || []
  } catch { /* 拦截器 */ }
}
function pickGoods(g: GoodsOption) {
  form.name = g.name
  form.sell_price = Number(g.sell_price) || 0
  goodsPickerVisible.value = false
}

function resetForm() {
  Object.assign(form, {
    id: 0, name: '', pic: '', pics: [], cid: '', sellpoint: '',
    market_price: 0, sell_price: 0, cost_price: 0, weight: 100, stock: 1000, sales: 0,
    perlimit: 0, starttime: '', endtime: '', sort: 0, status: 1,
    freighttype: 1, freightdata: '', freightcontent: '', contact_require: 1,
    vipdiscount: 0, commissionset: 0,
    commissiondata1: {}, commissiondata2: {}, commissiondata3: {},
    scoredkmaxset: 0, scoredkmaxval: 0, showtj: ['-1'], gettj: ['-1'], detail: '',
  })
  detailModules.value = []
  chosenFreight.value = []
  pricedata.value = [{ num: 1, money: 0 }]
}

function addTier() { pricedata.value.push({ num: 0, money: 0 }) }
function removeTier(i: number) { pricedata.value.splice(i, 1) }

async function openAdd() {
  isEdit.value = false
  resetForm()
  activeTab.value = 'info'
  viewMode.value = 'edit'
  await loadAux()
}

async function loadAux() {
  try { agentLevels.value = await getTuangouAgentLevels() } catch { agentLevels.value = [] }
  try {
    const fl: any = await getTuangouFreightList()
    // 后端 /admin/freight/list 返回 {list:[...], map_config:{...}}，这里只取模板数组；
    // 历史 bug：把整个对象塞给 el-table 导致 "rows is not iterable" 渲染错误，整表单卡死。
    freightList.value = Array.isArray(fl) ? fl : (Array.isArray(fl?.list) ? fl.list : [])
  } catch { freightList.value = [] }
}

function toggleFreight(id: number) {
  const i = chosenFreight.value.indexOf(id)
  if (i >= 0) chosenFreight.value.splice(i, 1)
  else chosenFreight.value.push(id)
}

// 跳转到 平台-配送方式 管理运费模板（添加模板后回来刷新列表）
const navigateByKey = inject<(key: string) => boolean>('adminNavigateByKey', () => false)
function openFreightManage() {
  const ok = navigateByKey('freight-index')
  if (!ok) ElMessage.info('请在 平台-配送方式 中添加运费模板')
}

async function openEdit(row: TuangouProductItem) {
  isEdit.value = true
  try {
    const d = await getTuangouProductDetail(row.id)
    Object.assign(form, {
      id: d.id,
      name: d.name,
      pic: d.pic || '',
      pics: d.pics ? String(d.pics).split(',').filter((s) => s !== '') : [],
      cid: d.cid || '',
      sellpoint: d.sellpoint || '',
      market_price: d.market_price || 0,
      sell_price: d.sell_price || 0,
      cost_price: d.cost_price || 0,
      weight: d.weight ?? 100,
      stock: d.stock ?? 0,
      sales: d.sales ?? 0,
      perlimit: d.perlimit ?? 0,
      starttime: d.starttime > 0 ? formatTime(d.starttime) : '',
      endtime: d.endtime > 0 ? formatTime(d.endtime) : '',
      sort: d.sort ?? 0,
      status: d.status ?? 1,
      freighttype: d.freighttype ?? 1,
      freightdata: d.freightdata || '',
      freightcontent: d.freightcontent || '',
      contact_require: d.contact_require ?? 1,
      vipdiscount: d.vipdiscount ?? 0,
      commissionset: d.commissionset ?? 0,
      commissiondata1: (d.commissiondata1 && typeof d.commissiondata1 === 'object') ? d.commissiondata1 : {},
      commissiondata2: (d.commissiondata2 && typeof d.commissiondata2 === 'object') ? d.commissiondata2 : {},
      commissiondata3: (d.commissiondata3 && typeof d.commissiondata3 === 'object') ? d.commissiondata3 : {},
      scoredkmaxset: d.scoredkmaxset ?? 0,
      scoredkmaxval: d.scoredkmaxval ?? 0,
      showtj: Array.isArray(d.showtj_arr) ? [...d.showtj_arr] : ['-1'],
      gettj: Array.isArray(d.gettj_arr) ? [...d.gettj_arr] : ['-1'],
      detail: d.detail || '',
    })
    chosenFreight.value = form.freightdata ? form.freightdata.split(',').filter(Boolean).map(Number) : []
    try {
      const parsed = JSON.parse(d.detail || '[]')
      detailModules.value = Array.isArray(parsed) ? parsed : []
    } catch { detailModules.value = [] }
    pricedata.value = Array.isArray(d.pricedata) && d.pricedata.length
      ? d.pricedata.map((t) => ({ num: t.num, money: t.money }))
      : [{ num: 1, money: 0 }]
    activeTab.value = 'info'
    viewMode.value = 'edit'
    await loadAux()
  } catch { /* 拦截器 */ }
}

function backToList() { viewMode.value = 'list'; fetchList() }
function toTs(v: string): number {
  if (!v) return 0
  const t = Math.floor(new Date(v.replace(/-/g, '/')).getTime() / 1000)
  return Number.isFinite(t) ? t : 0
}

function commissionRow(key: 'commissiondata1' | 'commissiondata2' | 'commissiondata3', levelId: number, tier: 1 | 2 | 3): string {
  return (form[key]?.[String(levelId)]?.[`commission${tier}`]) || ''
}
function setCommissionRow(key: 'commissiondata1' | 'commissiondata2' | 'commissiondata3', levelId: number, tier: 1 | 2 | 3, v: string) {
  const k = String(levelId)
  if (!form[key][k]) form[key][k] = {}
  form[key][k][`commission${tier}`] = v
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
        sales: Number(form.sales) || 0,
        perlimit: Number(form.perlimit) || 0,
        starttime: toTs(form.starttime),
        endtime: toTs(form.endtime),
        sort: Number(form.sort) || 0,
        status: form.status,
        freighttype: Number(form.freighttype),
        freightdata: chosenFreight.value.join(','),
        freightcontent: form.freightcontent,
        contact_require: form.contact_require === 1 ? 1 : 0,
        vipdiscount: Number(form.vipdiscount),
        commissionset: Number(form.commissionset),
        commissiondata1: form.commissiondata1,
        commissiondata2: form.commissiondata2,
        commissiondata3: form.commissiondata3,
        scoredkmaxset: Number(form.scoredkmaxset),
        scoredkmaxval: Number(form.scoredkmaxval) || 0,
        showtj: form.showtj,
        gettj: form.gettj,
        detail: JSON.stringify(detailModules.value || []),
        pricedata: pricedata.value.filter((t) => Number(t.num) > 0).map((t) => ({ num: Number(t.num), money: Number(t.money) || 0 })),
      })
      ElMessage.success('保存成功')
      backToList()
    } catch { /* 拦截器 */ } finally { submitting.value = false }
  })
}

onMounted(() => { fetchCategories(); fetchList() })
</script>

<template>
  <div v-if="viewMode === 'list'">
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
        <el-form-item v-if="isMerchant" label="审核">
          <el-select v-model="query.checked" placeholder="全部" clearable style="width: 100px">
            <el-option label="待审核" :value="0" />
            <el-option label="已通过" :value="1" />
            <el-option label="已驳回" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M795.904 750.72l124.928 124.928a32 32 0 0 1-45.248 45.248L750.656 795.904a416 416 0 1 1 45.248-45.248zM480 832a352 352 0 1 0 0-704 352 352 0 0 0 0 704z" /></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

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
                <div class="goods-info-name">{{ row.name }}</div>
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
          <template #default="{ row }"><span :class="actStatus(row).cls">{{ actStatus(row).text }}</span></template>
        </el-table-column>
        <el-table-column v-if="isMerchant" label="审核" width="86" align="center">
          <template #default="{ row }"><span :class="checkState(row).cls">{{ checkState(row).text }}</span></template>
        </el-table-column>
        <el-table-column label="操作" width="240">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleRowStatus(row)">{{ row.status === 1 ? '下架' : '上架' }}</el-button>
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
      <el-pagination class="pagination" :current-page="query.page" :page-size="query.pagesize" :total="total"
        layout="total, sizes, prev, pager, next, jumper" @current-change="handlePageChange" @size-change="handleSizeChange" />
    </el-card>

    <el-dialog v-model="orderDialogVisible" :title="`查看订单 - ${orderRow?.name || ''}`" width="90%" top="5vh">
      <el-table v-loading="orderLoading" :data="orderList" border>
        <el-table-column prop="ordernum" label="订单号" width="180" />
        <el-table-column label="买家" width="120"><template #default="{ row }">{{ row.buyer?.nickname || '-' }}</template></el-table-column>
        <el-table-column prop="num" label="数量" width="70" />
        <el-table-column prop="totalprice" label="订单金额" width="90" />
        <el-table-column label="状态" width="90"><template #default="{ row }">{{ row.status_text }}</template></el-table-column>
        <el-table-column prop="linkman" label="联系人" width="100" />
        <el-table-column prop="tel" label="手机号" width="120" />
        <el-table-column label="下单时间" width="150"><template #default="{ row }">{{ formatTime(row.createtime) }}</template></el-table-column>
      </el-table>
      <el-pagination class="pagination" :current-page="orderQuery.page" :page-size="orderQuery.pagesize" :total="orderTotal"
        layout="total, prev, pager, next" @current-change="(p: number) => { orderQuery.page = p; fetchOrders() }" />
    </el-dialog>

    <el-dialog v-model="goodsPickerVisible" title="选择商城商品" width="720px">
      <el-table :data="goodsOptions" border height="400" @row-dblclick="pickGoods">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="商品名称" />
        <el-table-column prop="sell_price" label="价格" width="100" />
        <el-table-column label="操作" width="90"><template #default="{ row }"><el-button link type="primary" @click="pickGoods(row)">选择</el-button></template></el-table-column>
      </el-table>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="imgPickerMulti" default-tab="library" @pick="onImgPick" />
  </div>

  <!-- 整页编辑 -->
  <div v-else class="goods-edit">
    <div class="ge-head">
      <div class="ge-head-title">{{ isEdit ? '编辑商品' : '添加商品' }}</div>
      <div class="ge-head-close" @click="backToList">
        <svg viewBox="0 0 1024 1024" width="18" height="18" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
      </div>
    </div>

    <el-tabs v-model="activeTab" class="ge-tabs">
      <el-tab-pane label="商品基本信息" name="info">
        <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" class="ge-form">
          <el-form-item label="商品名称" required>
            <el-input v-model="form.name" style="width: 320px" maxlength="60" />
            <el-button style="margin-left: 10px" @click="openGoodsPicker">选择商城商品</el-button>
          </el-form-item>
          <el-form-item label="商品主图" required>
            <el-button @click="openImgPicker((urls) => { if (urls[0]) form.pic = urls[0] })">上传图片</el-button>
            <span class="ge-hint">建议尺寸：640×640像素</span>
            <div class="ge-upload-box">
              <el-image v-if="form.pic" :src="form.pic" fit="cover" class="main-pic" />
              <div v-else class="ge-upload-add" @click="openImgPicker((urls) => { if (urls[0]) form.pic = urls[0] })">+</div>
            </div>
          </el-form-item>
          <el-form-item label="商品图片">
            <el-button @click="openImgPicker((urls) => { urls.forEach((u) => { if (!form.pics.includes(u)) form.pics.push(u) }) }, true)">批量上传</el-button>
            <span class="ge-hint">建议尺寸：640×640像素</span>
            <div class="ge-thumb-row">
              <div v-for="(url, i) in form.pics" :key="url" class="ge-thumb-item">
                <el-image :src="url" fit="cover" class="pic-thumb" />
                <span class="ge-upload-del" @click="form.pics.splice(i, 1)">×</span>
              </div>
            </div>
          </el-form-item>
          <el-form-item label="商品卖点">
            <el-input v-model="form.sellpoint" style="width: 320px" maxlength="60" />
            <span class="ge-hint">在商品详情页标题下面展示卖点信息，建议60字以内</span>
          </el-form-item>
          <el-form-item label="商品分类">
            <el-select v-model="form.cid" placeholder="-请选择-" style="width: 240px">
              <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
            </el-select>
          </el-form-item>

          <!-- ★配送模板 -->
          <el-form-item label="配送模板">
            <el-radio-group v-model="form.freighttype">
              <el-radio :value="1">全部模板</el-radio>
              <el-radio :value="0">选择模板</el-radio>
              <el-radio :value="3">自动发货</el-radio>
              <el-radio :value="4">在线卡密</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="form.freighttype === 0" label=" ">
            <div class="freight-pick">
              <el-table :data="freightList" border size="small" style="width: 460px">
                <el-table-column prop="id" label="模板ID" width="100" />
                <el-table-column prop="name" label="模板名称" />
                <el-table-column label="操作" width="90">
                  <template #default="{ row }">
                    <el-button link type="primary" @click="toggleFreight(row.id)">{{ chosenFreight.includes(row.id) ? '取消选择' : '选择' }}</el-button>
                  </template>
                </el-table-column>
              </el-table>
              <el-button size="small" class="freight-add-btn" @click="openFreightManage">添加</el-button>
            </div>
          </el-form-item>
          <el-form-item v-if="form.freighttype === 3 || form.freighttype === 4" label=" ">
            <el-checkbox v-model="form.contact_require" :true-value="1" :false-value="0">必填联系人、手机号信息</el-checkbox>
          </el-form-item>
          <el-form-item v-if="form.freighttype === 3" label=" ">
            <el-input v-model="form.freightcontent" type="textarea" :rows="3" placeholder="请输入发货信息" style="width: 420px" />
          </el-form-item>
          <el-form-item v-if="form.freighttype === 4" label=" ">
            <span class="ge-hint">请保存商品信息后在商品列表中上传卡密信息，用户购买几件将发送几个卡密信息</span>
          </el-form-item>

          <el-form-item label="市场价"><el-input-number v-model="form.market_price" :min="0" :precision="2" :step="0.1" /><span class="ge-unit">元</span></el-form-item>
          <el-form-item label="成本价"><el-input-number v-model="form.cost_price" :min="0" :precision="2" :step="0.1" /><span class="ge-unit">元</span></el-form-item>
          <el-form-item label="起始价格"><el-input-number v-model="form.sell_price" :min="0" :precision="2" :step="0.1" /><span class="ge-unit">元</span></el-form-item>

          <el-form-item label="团购设置">
            <div class="ge-tier">
              <div v-for="(tier, i) in pricedata" :key="i" class="ge-tier-row">
                <span>满</span><el-input-number v-model="tier.num" :min="1" :precision="0" size="small" /><span>人,价格</span>
                <el-input-number v-model="tier.money" :min="0" :precision="2" :step="0.1" size="small" /><span>元</span>
                <el-button v-if="i === 0" size="small" type="primary" @click="addTier">新增</el-button>
                <el-button v-else size="small" @click="removeTier(i)">删除</el-button>
              </div>
            </div>
          </el-form-item>
          <el-form-item label="开始时间">
            <el-date-picker v-model="form.starttime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" />
          </el-form-item>
          <el-form-item label="结束时间">
            <el-date-picker v-model="form.endtime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" />
          </el-form-item>

          <!-- 会员等级折扣 -->
          <el-form-item label="会员等级折扣">
            <el-radio-group v-model="form.vipdiscount">
              <el-radio :value="0">跟随系统</el-radio>
              <el-radio :value="1">参与</el-radio>
              <el-radio :value="2">不参与</el-radio>
            </el-radio-group>
            <span class="ge-hint">跟随系统：团购活动-系统设置-会员等级折扣</span>
          </el-form-item>

          <el-form-item label="重量"><el-input-number v-model="form.weight" :min="0" :precision="0" /><span class="ge-unit">克</span></el-form-item>
          <el-form-item label="库存"><el-input-number v-model="form.stock" :min="0" :precision="0" /></el-form-item>
          <el-form-item label="销量"><el-input-number v-model="form.sales" :min="0" :precision="0" /></el-form-item>
          <el-form-item label="每人限购"><el-input-number v-model="form.perlimit" :min="0" :precision="0" /><span class="ge-hint">每人最多可购买多少件，0表示不限购</span></el-form-item>
          <el-form-item label="序号"><el-input-number v-model="form.sort" :min="0" :precision="0" /><span class="ge-hint">用于排序,越大越靠前</span></el-form-item>

          <!-- ★分销设置 -->
          <el-form-item label="分销设置">
            <el-radio-group v-model="form.commissionset">
              <el-radio :value="0">按照会员等级</el-radio>
              <el-radio :value="1">单独设置提成比例</el-radio>
              <el-radio :value="2">单独设置提成金额</el-radio>
              <el-radio :value="3">分销送积分</el-radio>
              <el-radio :value="-1">不参与分销</el-radio>
            </el-radio-group>
            <div class="ge-hint-block">
              <div>按照会员等级：按照会员等级中设置的提成比例进行提成分配，提成=对应会员等级的提成比例×销售价×购买数量</div>
              <div>单独设置提成比例：按照单独设置的提成比例进行提成分配，提成=设置的百分比×销售价×购买数量</div>
              <div>单独设置提成金额：按照单独设置的提成金额进行提成分配，提成=提成金额×购买数量</div>
              <div>分销送积分：分销提成不给佣金而是给积分，得到的积分=提成积分×购买数量</div>
            </div>
          </el-form-item>
          <el-form-item v-if="form.commissionset === 1 || form.commissionset === 2 || form.commissionset === 3" label=" ">
            <div class="commission-block">
              <div v-if="!agentLevels.length" class="ge-hint">未设置具有分销权限的会员等级，请先在[会员]-[等级及分销]设置分销权限</div>
              <div v-for="l in agentLevels" :key="l.id" class="commission-row">
                <span class="commission-label">{{ l.name }}：</span>
                <label class="commission-field">
                  <span>一级({{ form.commissionset === 1 ? '%' : form.commissionset === 2 ? '元' : '积分' }})</span>
                  <el-input :model-value="commissionRow(form.commissionset === 1 ? 'commissiondata1' : form.commissionset === 2 ? 'commissiondata2' : 'commissiondata3', l.id, 1)"
                    @update:model-value="(v: string) => setCommissionRow(form.commissionset === 1 ? 'commissiondata1' : form.commissionset === 2 ? 'commissiondata2' : 'commissiondata3', l.id, 1, v)"
                    style="width: 140px" />
                </label>
                <label v-if="l.can_agent > 1" class="commission-field">
                  <span>二级({{ form.commissionset === 1 ? '%' : form.commissionset === 2 ? '元' : '积分' }})</span>
                  <el-input :model-value="commissionRow(form.commissionset === 1 ? 'commissiondata1' : form.commissionset === 2 ? 'commissiondata2' : 'commissiondata3', l.id, 2)"
                    @update:model-value="(v: string) => setCommissionRow(form.commissionset === 1 ? 'commissiondata1' : form.commissionset === 2 ? 'commissiondata2' : 'commissiondata3', l.id, 2, v)"
                    style="width: 140px" />
                </label>
              </div>
            </div>
          </el-form-item>

          <!-- ★积分抵扣 -->
          <el-form-item label="积分抵扣">
            <el-radio-group v-model="form.scoredkmaxset">
              <el-radio :value="0">按照系统设置抵扣</el-radio>
              <el-radio :value="1">单独设置抵扣比例</el-radio>
              <el-radio :value="2">单独设置抵扣金额</el-radio>
              <el-radio :value="-1">不可用积分抵扣</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="form.scoredkmaxset === 1 || form.scoredkmaxset === 2" label=" ">
            <span class="dk-label">最多抵扣({{ form.scoredkmaxset === 1 ? '%' : '元' }})</span>
            <el-input-number v-model="form.scoredkmaxval" :min="0" :precision="2" />
          </el-form-item>

          <el-form-item label="显示条件">
            <el-checkbox-group v-model="form.showtj">
              <el-checkbox v-for="o in tjOptions" :key="o.value" :value="o.value">{{ o.label }}</el-checkbox>
            </el-checkbox-group>
          </el-form-item>
          <el-form-item label="购买条件">
            <el-checkbox-group v-model="form.gettj">
              <el-checkbox v-for="o in tjOptions" :key="o.value" :value="o.value">{{ o.label }}</el-checkbox>
            </el-checkbox-group>
          </el-form-item>
          <el-form-item label="状态">
            <el-radio-group v-model="form.status">
              <el-radio :value="1">已上架</el-radio>
              <el-radio :value="0">未上架</el-radio>
            </el-radio-group>
          </el-form-item>
        </el-form>
        <div class="tab-footer">
          <el-button type="primary" @click="activeTab = 'detail'">下一步-编辑商品详情</el-button>
          <el-button type="danger" :loading="submitting" @click="submitForm">提交</el-button>
        </div>
      </el-tab-pane>

      <el-tab-pane label="商品详情" name="detail">
        <GoodsDetailEditor v-model="detailModules" title="商品详情" />
        <div class="tab-footer">
          <el-button type="primary" @click="activeTab = 'info'">上一步-编辑商品信息</el-button>
          <el-button type="danger" :loading="submitting" @click="submitForm">提交</el-button>
        </div>
      </el-tab-pane>
    </el-tabs>

    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="imgPickerMulti" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
.toolbar :deep(.el-card__body) { padding: 3px 6px; display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: nowrap; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; flex: 1 1 auto; justify-content: flex-end; flex-wrap: nowrap; min-width: 0; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 8px; }
.toolbar-search :deep(.el-form-item:last-child) { margin-right: 0; }
.toolbar-search :deep(.el-form-item__label) { padding-right: 6px; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }
:deep(th.el-table__cell) { background: #e6e6e6 !important; }
:deep(.cell) { text-align: left; }

.goods-info { display: flex; align-items: center; gap: 10px; }
.goods-info-img { width: 70px; height: 70px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.goods-info-img-empty { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 12px; background: #fafafa; }
.goods-info-meta { min-width: 0; flex: 1; }
.goods-info-name { color: #303133; font-size: 13px; line-height: 1.5; word-break: break-all; }
.goods-info-price { color: #ff6600; font-weight: 600; font-size: 14px; margin-top: 6px; }
.tier-line { font-size: 12px; line-height: 1.7; }
.tier-reached { color: #f56c6c; margin-left: 6px; }
.tier-left { color: #c0c4cc; margin-left: 6px; }
.status-on { color: #21ba45; }
.status-off { color: #f56c6c; }
.check-pending { color: #409eff; }
.activity-empty { color: #c0c4cc; }
.row-ops { display: flex; flex-direction: row; flex-wrap: wrap; gap: 2px 10px; line-height: 1.6; }
.row-ops .el-button { margin: 0; height: auto; min-height: 0; padding: 0; font-size: 12px; }
.row-ops :deep(.el-button) { height: auto; padding: 1px 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 12px; justify-content: flex-end; }

.goods-edit { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; }
.ge-head-title { font-size: 16px; font-weight: 600; color: #303133; flex: 1; }
.ge-head-close { cursor: pointer; color: #909399; display: flex; align-items: center; }
.ge-tabs { margin-bottom: 8px; }
.ge-form :deep(.el-form-item) { margin-bottom: 15px; }
.ge-hint { color: #909399; font-size: 12px; margin-left: 8px; }
.ge-hint-block { color: #909399; font-size: 12px; line-height: 1.7; margin-top: 4px; }
.ge-unit { margin-left: 6px; color: #606266; }
.ge-upload-box { margin-top: 8px; }
.ge-upload-add { width: 80px; height: 80px; border: 1px dashed #dcdfe6; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #909399; font-size: 24px; cursor: pointer; }
.ge-upload-del { position: absolute; margin-left: -18px; color: #f56c6c; cursor: pointer; font-size: 16px; }
.main-pic { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }
.ge-thumb-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
.ge-thumb-item { display: flex; flex-direction: column; align-items: center; }
.pic-thumb { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }
.ge-tier { display: flex; flex-direction: column; gap: 8px; }
.ge-tier-row { display: flex; align-items: center; gap: 8px; }
.freight-pick { display: flex; flex-direction: column; gap: 8px; }
.freight-add-btn { align-self: flex-start; }
.commission-block { min-width: 480px; }
.commission-row { display: flex; align-items: center; gap: 16px; margin-bottom: 10px; }
.commission-label { width: 110px; background: #f5f7fa; padding: 4px 8px; font-size: 13px; color: #303133; }
.commission-field { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #606266; }
.dk-label { background: #f5f7fa; padding: 6px 10px; font-size: 13px; margin-right: 8px; }
.tab-footer { display: flex; justify-content: center; gap: 14px; padding: 16px 0 8px; border-top: 1px solid #ebeef5; }
</style>
