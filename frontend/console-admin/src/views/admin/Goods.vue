<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type {
  FormRules,
  UploadRequestOptions,
} from 'element-plus'
import {
  addGoods,
  changeGoodsPrice,
  copyGoods,
  deleteGoods,
  editGoods,
  getCategoryList,
  getGoodsDetail,
  getGoodsList,
  setGoodsStatus,
  stockImport,
  uploadFile,
} from '../../api/goods'
import {
  getShopFuwuList,
  getShopGroupList,
  getShopParamList,
  getShopSetConfig,
  type ShopFuwuRow,
  type ShopGroupRow,
  type ShopParamRow,
} from '../../api/shopconfig'
import { getFreightList } from '../../api/tenantSet'
import type { FreightItem } from '../../api/types'
import type {
  CategoryItem,
  GoodsItem,
  GoodsSpecGroup,
  GoodsSkuOption,
} from '../../api/types'
import type { DesignerModule } from '../../api/designer'
import { defaultModule } from './designerModules'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'
import GoodsSpecEditor from '../../components/GoodsSpecEditor.vue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<GoodsItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  category_id: '' as number | '',
  gid: '' as number | '',
  status: '' as number | '',
  sort_field: '',
  sort_order: 'desc',
})

const categoryOptions = ref<CategoryItem[]>([])
async function fetchCategoryOptions() {
  try {
    const data = await getCategoryList({ page: 1, pagesize: 500 })
    categoryOptions.value = data.list || []
  } catch {
    // 拦截器统一提示
  }
}

const groupOptions = ref<ShopGroupRow[]>([])
async function fetchGroupOptions() {
  try {
    const data = await getShopGroupList({ page: 1, pagesize: 500 })
    groupOptions.value = data.list || []
  } catch {
    // 拦截器统一提示
  }
}

// 商品服务（表单复选）
const fuwuOptions = ref<ShopFuwuRow[]>([])
async function fetchFuwuOptions() {
  try {
    const data = await getShopFuwuList({ page: 1, pagesize: 500, status: 1 })
    fuwuOptions.value = data.list || []
  } catch {
    // 拦截器统一提示
  }
}

// 配送模板（选择铺货时用）
const freightOptions = ref<FreightItem[]>([])
async function fetchFreightOptions() {
  try {
    const data = await getFreightList()
    freightOptions.value = data.list || []
  } catch {
    // 拦截器统一提示
  }
}

// 会员等级（显示/购买条件、会员价）
const levelList = ref<{ id: number; name: string; can_agent?: number }[]>([])
async function fetchLevelList() {
  try {
    const data = await getShopSetConfig()
    levelList.value = data.levellist || []
  } catch {
    // 拦截器统一提示
  }
}

// 具有分销权限的会员等级（can_agent>0）；can_agent 数值决定可分销层级（>1 出二级、>2 出三级）
const agentLevels = computed(() => levelList.value.filter((lv) => Number(lv.can_agent) > 0))
type CommissionRow = { commission1?: number | string; commission2?: number | string; commission3?: number | string }
type CommissionData = Record<string, CommissionRow>
// 取某分销等级的提成行（惰性建对象，供 v-model 绑定）
function commissionCell(which: 1 | 2 | 3, levelId: number): CommissionRow {
  const map = (form as unknown as Record<string, CommissionData>)['commissiondata' + which]
  const id = String(levelId)
  if (!map[id]) map[id] = {}
  return map[id]
}

const selectedRows = ref<GoodsItem[]>([])
function handleSelectionChange(rows: GoodsItem[]) {
  selectedRows.value = rows
}

function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  query.sort_field = order ? prop : ''
  query.sort_order = order === 'ascending' ? 'asc' : 'desc'
  query.page = 1
  fetchList()
}
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

async function fetchList() {
  loading.value = true
  try {
    const data = await getGoodsList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      category_id: query.category_id === '' ? undefined : query.category_id,
      status: query.status === '' ? undefined : query.status,
      gid: query.gid === '' ? undefined : query.gid,
      sort_field: query.sort_field || undefined,
      sort_order: query.sort_order || undefined,
      bid: props.bid || undefined,
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

function handleRowStatus(row: GoodsItem) {
  const target = row.status === 1 ? 0 : 1
  const tip = target === 1 ? '上架' : '下架'
  ElMessageBox.confirm(`确定要${tip}商品「${row.name}」吗？`, `${tip}确认`, {
    confirmButtonText: tip,
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setGoodsStatus(row.id, target)
        row.status = target
        ElMessage.success(`已${tip}`)
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleDelete(row: GoodsItem) {
  ElMessageBox.confirm(`确定要删除商品「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteGoods(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function selectedIds(): number[] {
  return selectedRows.value.map((r) => r.id)
}

function handleBatchDelete() {
  const ids = selectedIds()
  if (ids.length === 0) {
    ElMessage.warning('请先勾选商品')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${ids.length} 件商品吗？删除后不可恢复。`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteGoods(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleBatchStatus(status: number) {
  const ids = selectedIds()
  if (ids.length === 0) {
    ElMessage.warning('请先勾选商品')
    return
  }
  const tip = status === 1 ? '上架' : '下架'
  ElMessageBox.confirm(`确定要${tip}选中的 ${ids.length} 件商品吗？`, `批量${tip}`, {
    confirmButtonText: tip,
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setGoodsStatus(ids, status)
        ElMessage.success(`已${tip}`)
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

async function handleCopy(row: GoodsItem) {
  try {
    await copyGoods(row.id)
    ElMessage.success('复制成功')
    fetchList()
  } catch {
    // 拦截器统一提示
  }
}

function handleBatchCopy() {
  const rows = selectedRows.value
  if (rows.length === 0) {
    ElMessage.warning('请先勾选商品')
    return
  }
  ElMessageBox.confirm(`确定要复制选中的 ${rows.length} 件商品吗？副本将处于未上架状态。`, '复制商品', {
    confirmButtonText: '复制',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        for (const r of rows) {
          await copyGoods(r.id)
        }
        ElMessage.success('复制成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

// 修改价格
const priceVisible = ref(false)
const priceSaving = ref(false)
const priceForm = reactive({ id: 0, sell_price: 0, market_price: 0 })
function openPriceDialog(row: GoodsItem) {
  priceForm.id = row.id
  priceForm.sell_price = Number(row.price) || 0
  priceForm.market_price = Number(row.market_price) || 0
  priceVisible.value = true
}
async function submitPrice() {
  priceSaving.value = true
  try {
    await changeGoodsPrice({
      id: priceForm.id,
      sell_price: priceForm.sell_price,
      market_price: priceForm.market_price,
    })
    ElMessage.success('修改成功')
    priceVisible.value = false
    fetchList()
  } catch {
    // 拦截器统一提示
  } finally {
    priceSaving.value = false
  }
}

// 商品链接（全站统一 LinkAddressModal）
const linkVisible = ref(false)
const linkPath = ref('')
const linkName = ref('')
function handleShowLink(row: GoodsItem) {
  linkPath.value = `pagesM/goods/detail?id=${row.id}`
  linkName.value = row.name
  linkVisible.value = true
}

// ============ 视图切换：列表 / 整页编辑 ============
const viewMode = ref<'list' | 'edit'>('list')
const activeTab = ref<'info' | 'param' | 'detail' | 'share'>('info')
const isEdit = ref(false)
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  print_name: '',
  cids: [] as number[],
  gids: [] as number[],
  fwids: [] as number[],
  image: '',
  images: [] as string[],
  video: '',
  sellpoint: '',
  procode: '',
  barcode: '',
  price: 0,
  market_price: 0,
  cost_price: 0,
  weight: 100,
  stock: 1000,
  givescore: 0,
  givescore_time: 0,
  price_type: 0,
  lvprice: 0,
  lvprice_data: {} as Record<string, number>,
  no_discount: -1,
  freighttype: 1,
  freightIds: [] as number[],
  contact_require: false,
  freightcontent: '',
  commissionset: 0,
  commissiondata1: {} as CommissionData,
  commissiondata2: {} as CommissionData,
  commissiondata3: {} as CommissionData,
  scoredkmaxset: 0,
  scoredkmaxval: 0,
  feepercent: '' as number | '',
  sales: 0,
  limit_start: 0,
  perlimit: 0,
  perlimitdan: 0,
  sort: 0,
  showtj: ['-1'] as string[],
  gettj: ['-1'] as string[],
  status: 1,
  start_hours: '',
  end_hours: '',
  start_time: '',
  end_time: '',
  detailModules: [] as DesignerModule[],
  description: '',
  sharetitle: '',
  sharepic: '',
  sharedesc: '',
})

const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
}))

// ============ 规格 / SKU 矩阵（共享组件 GoodsSpecEditor） ============
const specEditorRef = ref<InstanceType<typeof GoodsSpecEditor> | null>(null)
const specGroups = ref<GoodsSpecGroup[]>([])
let skuMap = reactive<Record<string, GoodsSkuOption>>({})

// 新建 SKU 行时的种子值（取自主表单价格/重量/积分）
const defaultSpecSku = computed(() => ({
  market_price: form.market_price,
  cost_price: form.cost_price,
  sell_price: form.price,
  weight: form.weight,
  givescore: form.givescore,
}))

function resetSpecState() {
  specGroups.value = [{ title: '规格', items: [{ title: '默认规格' }] }]
  Object.keys(skuMap).forEach((k) => delete skuMap[k])
}

// 开启 / 关闭会员价（按钮切换）
function toggleLvprice() {
  form.lvprice = form.lvprice === 1 ? 0 : 1
}

// 组件选图事件 → 复用本页选图弹窗回填
function onSpecPickImage(_current: string, done: (url: string) => void) {
  openImgPicker((urls) => {
    if (urls[0]) done(urls[0])
  })
}

// 保存时多规格校验与载荷（单规格由主表单 price/stock 提供，组件内 no-op）
function buildSpecPayload(params: Record<string, unknown>): boolean {
  return specEditorRef.value?.buildMultiSpecPayload(params) ?? true
}

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

// 视频上传
const videoUploading = ref(false)
async function doVideoUpload(options: UploadRequestOptions) {
  videoUploading.value = true
  try {
    const res = await uploadFile(options.file)
    form.video = res.url
    ElMessage.success('视频上传成功')
  } catch {
    // 拦截器统一提示
  } finally {
    videoUploading.value = false
  }
}

// ============ 商品参数（按所选分类联动） ============
const paramRows = ref<ShopParamRow[]>([])
const paramdata = reactive<Record<string, unknown>>({})
function paramOptions(p: ShopParamRow): string[] {
  const raw = (p.params || '').trim()
  if (!raw) return []
  try {
    const parsed = JSON.parse(raw)
    if (Array.isArray(parsed)) return parsed.map((x) => String(x))
  } catch {
    // 非 JSON，按换行/逗号分割
  }
  return raw.split(/[\n,，]/).map((s) => s.trim()).filter(Boolean)
}
async function fetchParamRows() {
  if (form.cids.length === 0) {
    paramRows.value = []
    return
  }
  try {
    const res = await getShopParamList({ page: 1, pagesize: 500, status: 1 })
    const list = res.list || []
    paramRows.value = list.filter((p) => {
      if (!p.cid) return true // 未关联分类=全部商品适用
      const ids = p.cid.split(',').map((s) => Number(s.trim())).filter(Boolean)
      return form.cids.some((c) => ids.includes(c))
    })
  } catch {
    // 拦截器统一提示
  }
}
watch(
  () => form.cids.slice().join(','),
  () => {
    fetchParamRows()
  },
)
// 多分类最多 5 个
function handleCidsChange(val: number[]) {
  if (val.length > 5) {
    ElMessage.warning('商品分类最多选择 5 个')
    form.cids = val.slice(0, 5)
  }
}

// 显示 / 购买条件（所有人与其它项互斥）
// checkbox-group 版互斥：勾“所有人”只留所有人；已选所有人再勾具体项时移除所有人
function condChange(which: 'showtj' | 'gettj', val: string[]) {
  if (val.includes('-1')) {
    form[which] = val.length === 1 ? ['-1'] : form[which].includes('-1') ? val.filter((x) => x !== '-1') : ['-1']
  } else {
    form[which] = val
  }
}

// ============ 重置 / 新增 / 编辑 ============
function defaultDetailModules(): DesignerModule[] {
  return [defaultModule('richtext')]
}
function resetForm() {
  Object.assign(form, {
    id: 0,
    name: '',
    print_name: '',
    cids: [],
    gids: [],
    fwids: [],
    image: '',
    images: [],
    video: '',
    sellpoint: '',
    procode: '',
    barcode: '',
    price: 0,
    market_price: 0,
    cost_price: 0,
    weight: 100,
    stock: 1000,
    givescore: 0,
    givescore_time: 0,
    price_type: 0,
    lvprice: 0,
    lvprice_data: {},
    no_discount: -1,
    freighttype: 1,
    freightIds: [],
    contact_require: false,
    freightcontent: '',
    commissionset: 0,
    commissiondata1: {},
    commissiondata2: {},
    commissiondata3: {},
    scoredkmaxset: 0,
    scoredkmaxval: 0,
    feepercent: '',
    sales: 0,
    limit_start: 0,
    perlimit: 0,
    perlimitdan: 0,
    sort: 0,
    showtj: ['-1'],
    gettj: ['-1'],
    status: 1,
    start_hours: '',
    end_hours: '',
    start_time: '',
    end_time: '',
    detailModules: defaultDetailModules(),
    description: '',
    sharetitle: '',
    sharepic: '',
    sharedesc: '',
  })
  Object.keys(paramdata).forEach((k) => delete paramdata[k])
}

function openAdd() {
  isEdit.value = false
  resetSpecState()
  resetForm()
  activeTab.value = 'info'
  viewMode.value = 'edit'
}

async function openEdit(row: GoodsItem) {
  isEdit.value = true
  let detail
  try {
    detail = await getGoodsDetail(row.id)
  } catch {
    return
  }
  resetSpecState()
  resetForm()
  Object.assign(form, {
    id: detail.id,
    name: detail.name,
    print_name: detail.print_name || '',
    cids: detail.cids && detail.cids.length ? detail.cids : detail.category_id ? [detail.category_id] : [],
    gids: detail.gids || [],
    fwids: detail.fwids || [],
    image: detail.image,
    images: detail.images ? detail.images.split(',').filter((s) => s !== '') : [],
    video: detail.video || '',
    sellpoint: detail.sellpoint || '',
    procode: detail.procode || '',
    barcode: detail.barcode || '',
    price: detail.price,
    market_price: detail.market_price,
    cost_price: detail.cost_price || 0,
    weight: detail.weight ?? 100,
    stock: detail.stock,
    givescore: detail.givescore ?? 0,
    givescore_time: detail.givescore_time ?? 0,
    price_type: detail.price_type ?? 0,
    lvprice: detail.lvprice ?? 0,
    lvprice_data: detail.lvprice_data || {},
    no_discount: detail.no_discount ?? -1,
    freighttype: detail.freighttype ?? 1,
    freightIds: detail.freightdata ? detail.freightdata.split(',').map((s: string) => Number(s.trim())).filter(Boolean) : [],
    contact_require: detail.contact_require === 1,
    freightcontent: detail.freightcontent || '',
    commissionset: detail.commissionset ?? 0,
    commissiondata1: (detail as unknown as Record<string, unknown>).commissiondata1 || {},
    commissiondata2: (detail as unknown as Record<string, unknown>).commissiondata2 || {},
    commissiondata3: (detail as unknown as Record<string, unknown>).commissiondata3 || {},
    scoredkmaxset: detail.scoredkmaxset ?? 0,
    scoredkmaxval: detail.scoredkmaxval ?? 0,
    feepercent: detail.feepercent === null || detail.feepercent === undefined ? '' : detail.feepercent,
    sales: detail.sales ?? 0,
    limit_start: detail.limit_start ?? 0,
    perlimit: detail.perlimit ?? 0,
    perlimitdan: detail.perlimitdan ?? 0,
    sort: detail.sort ?? 0,
    showtj: detail.showtjs && detail.showtjs.length ? detail.showtjs : ['-1'],
    gettj: detail.gettjs && detail.gettjs.length ? detail.gettjs : ['-1'],
    status: detail.status,
    start_hours: detail.start_hours || '',
    end_hours: detail.end_hours || '',
    start_time: detail.start_time || '',
    end_time: detail.end_time || '',
    description: detail.description || '',
    sharetitle: detail.sharetitle || '',
    sharepic: detail.sharepic || '',
    sharedesc: detail.sharedesc || '',
  })
  // 商品详情组件回显
  let modules: DesignerModule[] = []
  if (detail.detail) {
    try {
      const parsed = JSON.parse(detail.detail)
      if (Array.isArray(parsed)) modules = parsed
    } catch {
      modules = []
    }
  }
  form.detailModules = modules.length ? modules : defaultDetailModules()
  // 商品参数回显
  if (detail.paramdata && typeof detail.paramdata === 'object') {
    Object.keys(paramdata).forEach((k) => delete paramdata[k])
    Object.assign(paramdata, detail.paramdata)
  }
  // 规格回显（单/多规格由共享组件按 groups 结构自动判定）
  if (detail.specs && detail.specs.length > 0) {
    specGroups.value = detail.specs.map((g) => ({
      title: g.title,
      items: (g.items || []).map((it) => ({ title: it.title, ggpic_wholesale: it.ggpic_wholesale || '' })),
    }))
    for (const sku of detail.skus || []) {
      skuMap[sku.ks] = {
        name: sku.name,
        pic: sku.pic,
        market_price: sku.market_price,
        cost_price: sku.cost_price,
        sell_price: sku.sell_price,
        weight: sku.weight ?? 0,
        stock: sku.stock,
        givescore: sku.givescore ?? 0,
        procode: sku.procode || '',
        barcode: sku.barcode,
      }
    }
  }
  await fetchParamRows()
  activeTab.value = 'info'
  viewMode.value = 'edit'
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

// tab 上一步 / 下一步
const TAB_ORDER = ['info', 'param', 'detail', 'share'] as const
function nextTab() {
  const i = TAB_ORDER.indexOf(activeTab.value)
  if (i < TAB_ORDER.length - 1) activeTab.value = TAB_ORDER[i + 1]
}
function prevTab() {
  const i = TAB_ORDER.indexOf(activeTab.value)
  if (i > 0) activeTab.value = TAB_ORDER[i - 1]
}
// 显式受控同步页签（保证导航高亮与值一致）
function onTabChange(name: string | number) {
  activeTab.value = String(name) as typeof activeTab.value
}
const nextLabel = computed(() => {
  switch (activeTab.value) {
    case 'info': return '下一步：编辑商品参数'
    case 'param': return '下一步：编辑商品详情'
    case 'detail': return '下一步：编辑分享设置'
    default: return ''
  }
})
const prevLabel = computed(() => {
  switch (activeTab.value) {
    case 'param': return '上一步：编辑商品信息'
    case 'detail': return '上一步：编辑商品参数'
    case 'share': return '上一步：编辑商品详情'
    default: return ''
  }
})

// 选项常量
const COMMISSION_OPTIONS = [
  { value: 0, label: '按照会员等级' },
  { value: 1, label: '单独设置提成比例' },
  { value: 2, label: '单独设置提成金额' },
  { value: 3, label: '分销送积分' },
  { value: 5, label: '提成比例+积分' },
  { value: 6, label: '提成金额+积分' },
  { value: -1, label: '不参与分销' },
]
const SCORE_DK_OPTIONS = [
  { value: 0, label: '按照系统设置抵扣' },
  { value: 1, label: '单独设置抵扣比例' },
  { value: 2, label: '单独设置抵扣金额' },
  { value: -1, label: '不可用积分抵扣' },
]

async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请输入商品名称')
    activeTab.value = 'info'
    return
  }
  if (form.cids.length === 0) {
    ElMessage.warning('请选择商品分类（最多 5 个）')
    activeTab.value = 'info'
    return
  }
  if (!form.image) {
    ElMessage.warning('请上传商品主图')
    activeTab.value = 'info'
    return
  }

  const params: Record<string, unknown> = {
    id: form.id,
    name: form.name,
    print_name: form.print_name,
    category_id: Number(form.cids[0]),
    cid: form.cids,
    gid: form.gids,
    fwid: form.fwids,
    image: form.image,
    images: form.images.join(','),
    video: form.video,
    sellpoint: form.sellpoint,
    procode: form.procode,
    barcode: form.barcode,
    price: form.price,
    market_price: form.market_price,
    cost_price: form.cost_price,
    weight: form.weight,
    stock: form.stock,
    givescore: form.givescore,
    givescore_time: form.givescore_time,
    price_type: form.price_type,
    lvprice: form.lvprice,
    lvprice_data: form.lvprice ? form.lvprice_data : {},
    no_discount: form.no_discount,
    freighttype: form.freighttype,
    freightdata: form.freighttype === 0 ? form.freightIds.join(',') : '',
    contact_require: form.contact_require ? 1 : 0,
    freightcontent: [3, 4].includes(form.freighttype) ? form.freightcontent : '',
    commissionset: form.commissionset,
    commissiondata1: form.commissiondata1,
    commissiondata2: form.commissiondata2,
    commissiondata3: form.commissiondata3,
    scoredkmaxset: form.scoredkmaxset,
    scoredkmaxval: [1, 2].includes(form.scoredkmaxset) ? form.scoredkmaxval : 0,
    feepercent: form.feepercent === '' ? null : form.feepercent,
    sales: form.sales,
    limit_start: form.limit_start,
    perlimit: form.perlimit,
    perlimitdan: form.perlimitdan,
    sort: form.sort,
    showtj: form.showtj,
    gettj: form.gettj,
    status: form.status,
    start_hours: form.status === 2 ? form.start_hours : '',
    end_hours: form.status === 2 ? form.end_hours : '',
    start_time: form.status === 3 ? form.start_time : '',
    end_time: form.status === 3 ? form.end_time : '',
    paramdata: { ...paramdata },
    detail: JSON.stringify(form.detailModules || []),
    description: form.description,
    sharetitle: form.sharetitle || form.name,
    sharepic: form.sharepic || form.image,
    sharedesc: form.sharedesc || form.sellpoint,
  }
  if (!buildSpecPayload(params)) return

  submitting.value = true
  try {
    if (isEdit.value) {
      await editGoods(params as never)
      ElMessage.success('保存成功')
    } else {
      await addGoods(params as never)
      ElMessage.success('新增成功')
    }
    backToList()
  } catch {
    // 拦截器统一提示
  } finally {
    submitting.value = false
  }
}

// ============ Excel 库存导入 ============
const importVisible = ref(false)
const importing = ref(false)
async function doStockImport(options: UploadRequestOptions) {
  importing.value = true
  try {
    const res = await stockImport(options.file)
    let msg = `成功更新${res.updated}条数据`
    if (res.missed > 0) msg += `，未找到商品${res.missed}条`
    ElMessage.success(msg)
    importVisible.value = false
    fetchList()
  } catch {
    // 拦截器统一提示
  } finally {
    importing.value = false
  }
}

onMounted(() => {
  fetchList()
  fetchCategoryOptions()
  fetchGroupOptions()
  fetchFuwuOptions()
  fetchFreightOptions()
  fetchLevelList()
})
</script>

<template>
  <div>
    <!-- ============ 列表视图 ============ -->
    <div v-if="viewMode === 'list'">
      <el-card shadow="never" class="option-bar">
        <el-button type="success" @click="openAdd">添加</el-button>
        <el-button @click="handleBatchDelete">删除</el-button>
        <el-button @click="handleBatchStatus(1)">上架</el-button>
        <el-button @click="handleBatchStatus(0)">下架</el-button>
        <el-button @click="importVisible = true">导入</el-button>
        <el-button @click="handleBatchCopy">复制商品</el-button>
      </el-card>

      <el-card shadow="never" class="search-bar">
        <el-form inline @submit.prevent>
          <el-form-item label="商品名称">
            <el-input v-model="query.keyword" placeholder="商品名称" clearable style="width: 180px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="分类">
            <el-select v-model="query.category_id" placeholder="全部" clearable style="width: 160px">
              <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="分组">
            <el-select v-model="query.gid" placeholder="全部" clearable style="width: 140px">
              <el-option v-for="g in groupOptions" :key="g.id" :label="g.name" :value="g.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
              <el-option label="已上架" :value="1" />
              <el-option label="未上架" :value="0" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-icon-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange" @sort-change="handleSortChange">
          <el-table-column type="selection" width="45" />
          <el-table-column prop="id" label="ID" width="70" sortable="custom" />
          <el-table-column label="商品信息" min-width="320">
            <template #default="{ row }">
              <div class="goods-info">
                <el-image v-if="row.image" :src="row.image" :preview-src-list="[row.image]" fit="cover" class="goods-info-img" preview-teleported />
                <div v-else class="goods-info-img goods-info-img-empty">无图</div>
                <div class="goods-info-meta">
                  <div class="goods-info-name">
                    <span v-if="row.category_name" class="goods-info-cat">[{{ row.category_name }}]</span>{{ row.name }}
                  </div>
                  <div class="goods-info-price">￥{{ Number(row.price).toFixed(2) }}</div>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="stock" label="库存" width="80" sortable="custom" />
          <el-table-column label="显示销量" width="85" sortable="custom" prop="sales">
            <template #default="{ row }">{{ row.sales ?? 0 }}</template>
          </el-table-column>
          <el-table-column label="实际销量" width="85">
            <template #default="{ row }">{{ row.realsalenum ?? row.sales ?? 0 }}</template>
          </el-table-column>
          <el-table-column prop="sort" label="序号" width="70" sortable="custom" />
          <el-table-column prop="create_time" label="创建时间" width="155" sortable="custom" />
          <el-table-column label="商品状态" width="85">
            <template #default="{ row }">
              <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '已上架' : '未上架' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="参与活动" width="85">
            <template><span class="activity-empty">-</span></template>
          </el-table-column>
          <el-table-column label="操作" width="290">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="handleShowLink(row)">查看链接</el-button>
                <el-button link type="primary" @click="handleCopy(row)">复制</el-button>
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
                <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleRowStatus(row)">
                  {{ row.status === 1 ? '下架' : '上架' }}
                </el-button>
                <el-button link type="primary" @click="openPriceDialog(row)">改价</el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination
          class="pagination"
          background
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :current-page="query.page"
          :page-size="query.pagesize"
          :page-sizes="[10, 20, 50, 100]"
          @current-change="handlePageChange"
          @size-change="handleSizeChange"
        />
      </el-card>
    </div>

    <!-- ============ 整页编辑：四页签 ============ -->
    <div v-else class="goods-edit">
      <div class="ge-head">
        <el-button @click="backToList">返回列表</el-button>
        <span class="ge-head-title">{{ isEdit ? '编辑商品' : '添加商品' }}</span>
      </div>

      <el-form :model="form" :rules="rules" label-position="right" label-width="116px" class="ge-form">
        <el-tabs v-model="activeTab" class="ge-tabs" @tab-change="onTabChange">
          <!-- ===== 商品信息 ===== -->
                    <el-tab-pane label="商品信息" name="info">
            <!-- 一、基础信息 -->
            <el-form-item label="商品名称" required>
              <el-input v-model="form.name" maxlength="120" style="width: 400px" placeholder="请输入商品名称" />
            </el-form-item>
            <el-form-item label="商品别名">
              <el-input v-model="form.print_name" style="width: 300px" />
              <span class="ge-hint">小票打印使用别名替代商品名称，留空使用商品名称</span>
            </el-form-item>
            <el-form-item label="商品主图" required>
              <el-button @click="openImgPicker((urls) => { if (urls[0]) form.image = urls[0] })">上传图片</el-button>
              <span class="ge-hint">建议尺寸：640×640像素</span>
              <div class="ge-upload-box">
                <template v-if="form.image">
                  <el-image :src="form.image" fit="cover" class="main-pic" />
                  <span class="ge-upload-del" @click="form.image = ''">×</span>
                </template>
                <div
                  v-else
                  class="ge-upload-add"
                  @click="openImgPicker((urls) => { if (urls[0]) form.image = urls[0] })"
                >+</div>
              </div>
            </el-form-item>
            <el-form-item label="商品图片">
              <el-button
                @click="openImgPicker((urls) => { urls.forEach((u) => { if (!form.images.includes(u)) form.images.push(u) }) }, true)"
              >批量上传</el-button>
              <span class="ge-hint">建议尺寸：640×640像素</span>
              <div class="ge-thumb-row">
                <div v-for="(url, i) in form.images" :key="url" class="ge-thumb-item">
                  <el-image :src="url" fit="cover" class="pic-thumb" />
                  <span class="ge-upload-del" @click="form.images.splice(i, 1)">×</span>
                </div>
              </div>
            </el-form-item>
            <el-form-item label="商品视频">
              <el-input v-model="form.video" style="width: 400px" placeholder="mp4 视频源链接" />
              <el-upload
                :show-file-list="false"
                :http-request="doVideoUpload"
                accept="video/*"
                style="margin-left: 10px"
              >
                <el-button :loading="videoUploading">上传视频</el-button>
              </el-upload>
              <span class="ge-hint">填写视频链接或上传视频，视频链接必须为mp4格式的源链接，视频大小须在50MB以内</span>
            </el-form-item>
            <el-form-item label="商品卖点">
              <el-input v-model="form.sellpoint" maxlength="60" show-word-limit style="width: 400px" />
              <span class="ge-hint">在商品详情页标题下面展示商品卖点，建议60字以内</span>
            </el-form-item>
            <el-form-item label="商品分类" required>
              <el-select
                v-model="form.cids"
                multiple
                collapse-tags
                collapse-tags-tooltip
                placeholder="-请选择-"
                style="width: 400px"
                @change="handleCidsChange"
              >
                <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
              </el-select>
              <span class="ge-hint">最多可选5个分类</span>
            </el-form-item>
            <el-form-item label="商品分组">
              <el-checkbox-group v-model="form.gids" class="ge-check-grid">
                <el-checkbox v-for="g in groupOptions" :key="g.id" :value="g.id">{{ g.name }}</el-checkbox>
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="商品服务">
              <el-checkbox-group v-model="form.fwids" class="ge-check-grid">
                <el-checkbox v-for="f in fuwuOptions" :key="f.id" :value="f.id">
                  {{ f.name }}<span v-if="f.desc" class="ge-fuwu-desc">（{{ f.desc }}）</span>
                </el-checkbox>
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="商品编码">
              <el-input v-model="form.procode" style="width: 300px" placeholder="商品编码（货号）" />
            </el-form-item>
            <el-form-item label="商品条码">
              <el-input v-model="form.barcode" style="width: 300px" placeholder="商品条形码" />
            </el-form-item>

            <!-- 二、规格 / SKU / 价格（共享规格编辑器） -->
            <el-form-item label="设置规格">
              <GoodsSpecEditor
                ref="specEditorRef"
                v-model:groups="specGroups"
                v-model:skus="skuMap"
                :default-sku="defaultSpecSku"
                @pick-image="onSpecPickImage"
              >
                <template #single-row>
                  <td class="sku-spec-cell">默认规格</td>
                  <td><el-input v-model="form.procode" placeholder="编号" style="width: 100px" /></td>
                  <td><el-input-number v-model="form.market_price" :min="0" :precision="2" :controls="false" style="width: 90px" /></td>
                  <td><el-input-number v-model="form.cost_price" :min="0" :precision="2" :controls="false" style="width: 90px" /></td>
                  <td><el-input-number v-model="form.price" :min="0" :precision="2" :controls="false" style="width: 90px" /></td>
                  <td><el-input-number v-model="form.weight" :min="0" :controls="false" style="width: 80px" /></td>
                  <td><el-input-number v-model="form.stock" :min="0" :controls="false" style="width: 80px" /></td>
                  <td><el-input-number v-model="form.givescore" :min="0" :controls="false" style="width: 80px" /></td>
                  <td>
                    <el-image v-if="form.image" :src="form.image" fit="cover" class="sku-pic" @click="openImgPicker((urls) => { if (urls[0]) form.image = urls[0] })" />
                    <span v-else class="sku-pic-empty" @click="openImgPicker((urls) => { if (urls[0]) form.image = urls[0] })">上传</span>
                  </td>
                </template>
                <template #actions>
                  <el-button @click="toggleLvprice">{{ form.lvprice === 1 ? '关闭会员价' : '开启会员价' }}</el-button>
                </template>
                <template #footer>
                  <div class="ge-block-hint">
                    注：开启会员价可设置不同会员等级显示不同价格；赠积分确认收货才赠送，与系统设置中的消费赠送积分(支付就赠送积分)有差异；开启后，会员价优先，会员等级折扣不再叠加
                  </div>
                </template>
              </GoodsSpecEditor>
            </el-form-item>
            <el-form-item v-if="form.lvprice === 1" label="会员等级价格">
              <div class="ge-lvprice">
                <div v-for="lv in levelList" :key="lv.id" class="ge-lvprice-item">
                  <span class="ge-lvprice-name">{{ lv.name }}</span>
                  <el-input-number v-model="form.lvprice_data[String(lv.id)]" :min="0" :precision="2" :controls="false" style="width: 130px" />
                </div>
                <div v-if="levelList.length === 0" class="ge-empty-tip">暂无会员等级，请先在会员-会员等级中配置</div>
              </div>
            </el-form-item>
            <el-form-item label="价格模式">
              <el-radio-group v-model="form.price_type">
                <el-radio :value="0">默认</el-radio>
                <el-radio :value="1">询价</el-radio>
              </el-radio-group>
              <span class="ge-hint">默认：展示售价，详情页展示购买和加入购物车，可下单；询价：不设置售价时不展示，详情页展示联系按钮（点击展示系统设置的商家名称和服务电话），不可下单。</span>
            </el-form-item>
            <el-form-item label="会员等级折扣">
              <el-radio-group v-model="form.no_discount">
                <el-radio :value="-1">跟随系统</el-radio>
                <el-radio :value="0">参与</el-radio>
                <el-radio :value="1">不参与</el-radio>
              </el-radio-group>
              <span class="ge-hint">跟随系统：商城-系统设置-会员等级折扣</span>
            </el-form-item>
            <el-form-item label="配送模板">
              <el-radio-group v-model="form.freighttype">
                <el-radio :value="1">全部铺货（适用全部配送模板）</el-radio>
                <el-radio :value="0">选择铺货（指定配送模板）</el-radio>
                <el-radio :value="3">自动发货（无需物流）</el-radio>
                <el-radio :value="4">在线卡密</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="form.freighttype === 0" label="选择配送模板">
              <el-select v-model="form.freightIds" multiple collapse-tags placeholder="请选择配送模板" style="width: 400px">
                <el-option v-for="f in freightOptions" :key="f.id" :label="f.name" :value="f.id" />
              </el-select>
            </el-form-item>
            <el-form-item v-if="[3, 4].includes(form.freighttype)" label=" ">
              <el-checkbox v-model="form.contact_require">必填联系人、手机号信息</el-checkbox>
            </el-form-item>
            <el-form-item v-if="form.freighttype === 3" label="发货信息">
              <el-input v-model="form.freightcontent" type="textarea" :rows="3" placeholder="请输入发货信息（自动发货时展示给买家）" style="width: 500px" />
            </el-form-item>
            <el-form-item v-if="form.freighttype === 4" label=" ">
              <span class="ge-block-hint">请保存商品信息后在商品列表中上传卡密信息；用户购买几件将发送几个卡密信息</span>
            </el-form-item>
            <el-form-item label="分销设置">
              <el-radio-group v-model="form.commissionset">
                <el-radio v-for="o in COMMISSION_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
              </el-radio-group>
              <div class="ge-block-hint">
                按照会员等级：按照会员等级中设置的提成比例进行提成分配，提成=对应会员等级的提成比例×商品金额×购买数量<br>
                单独设置提成比例：按照单独设置的提成比例进行提成分配，提成=设置的百分比×商品金额×购买数量<br>
                单独设置提成金额：按照单独设置的提成金额进行提成分配，提成=提成金额×购买数量<br>
                分销送积分：分销提成不给佣金而是给积分，得到的积分=提成积分×购买数量<br>
                * 当商家开启独立收款并且开启商家返款扣除分销佣金时，提成金额比例+平台抽成金额比例不能超过30%（微信支付分账限制）
              </div>
            </el-form-item>
            <!-- 分销提成联动：单独设置提成比例 / 提成比例+积分 -->
            <template v-if="[1, 5].includes(form.commissionset)">
              <el-form-item v-if="agentLevels.length === 0" label="提成比例">
                <span class="ge-comm-empty">未设置具有分销权限的会员等级，请先在「会员-等级及分销」中设置分销权限</span>
              </el-form-item>
              <el-form-item v-for="lv in agentLevels" :key="'c1-' + lv.id" :label="lv.name">
                <div class="ge-comm-line">
                  <span class="ge-comm-cell"><em>一级(%)</em><el-input-number v-model="commissionCell(1, lv.id).commission1" :min="0" :precision="2" :controls="false" style="width: 120px" /></span>
                  <span v-if="Number(lv.can_agent) > 1" class="ge-comm-cell"><em>二级(%)</em><el-input-number v-model="commissionCell(1, lv.id).commission2" :min="0" :precision="2" :controls="false" style="width: 120px" /></span>
                  <span v-if="Number(lv.can_agent) > 2" class="ge-comm-cell"><em>三级(%)</em><el-input-number v-model="commissionCell(1, lv.id).commission3" :min="0" :precision="2" :controls="false" style="width: 120px" /></span>
                </div>
              </el-form-item>
            </template>
            <!-- 分销提成联动：单独设置提成金额 / 提成金额+积分 -->
            <template v-if="[2, 6].includes(form.commissionset)">
              <el-form-item v-if="agentLevels.length === 0" label="提成金额">
                <span class="ge-comm-empty">未设置具有分销权限的会员等级，请先在「会员-等级及分销」中设置分销权限</span>
              </el-form-item>
              <el-form-item v-for="lv in agentLevels" :key="'c2-' + lv.id" :label="lv.name">
                <div class="ge-comm-line">
                  <span class="ge-comm-cell"><em>一级(元)</em><el-input-number v-model="commissionCell(2, lv.id).commission1" :min="0" :precision="2" :controls="false" style="width: 120px" /></span>
                  <span v-if="Number(lv.can_agent) > 1" class="ge-comm-cell"><em>二级(元)</em><el-input-number v-model="commissionCell(2, lv.id).commission2" :min="0" :precision="2" :controls="false" style="width: 120px" /></span>
                  <span v-if="Number(lv.can_agent) > 2" class="ge-comm-cell"><em>三级(元)</em><el-input-number v-model="commissionCell(2, lv.id).commission3" :min="0" :precision="2" :controls="false" style="width: 120px" /></span>
                </div>
              </el-form-item>
            </template>
            <!-- 分销提成联动：分销送积分 / 提成比例+积分 / 提成金额+积分 -->
            <template v-if="[3, 5, 6].includes(form.commissionset)">
              <el-form-item v-if="agentLevels.length === 0" label="提成积分">
                <span class="ge-comm-empty">未设置具有分销权限的会员等级，请先在「会员-等级及分销」中设置分销权限</span>
              </el-form-item>
              <el-form-item v-for="lv in agentLevels" :key="'c3-' + lv.id" :label="lv.name">
                <div class="ge-comm-line">
                  <span class="ge-comm-cell"><em>一级(积分)</em><el-input-number v-model="commissionCell(3, lv.id).commission1" :min="0" :precision="0" :controls="false" style="width: 120px" /></span>
                  <span v-if="Number(lv.can_agent) > 1" class="ge-comm-cell"><em>二级(积分)</em><el-input-number v-model="commissionCell(3, lv.id).commission2" :min="0" :precision="0" :controls="false" style="width: 120px" /></span>
                  <span v-if="Number(lv.can_agent) > 2" class="ge-comm-cell"><em>三级(积分)</em><el-input-number v-model="commissionCell(3, lv.id).commission3" :min="0" :precision="0" :controls="false" style="width: 120px" /></span>
                </div>
              </el-form-item>
            </template>
            <el-form-item label="积分抵扣">
              <el-radio-group v-model="form.scoredkmaxset">
                <el-radio v-for="o in SCORE_DK_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
              </el-radio-group>
              <template v-if="[1, 2].includes(form.scoredkmaxset)">
                <span style="margin: 0 8px 0 16px">最多抵扣 ({{ form.scoredkmaxset === 1 ? '%' : '元' }})</span>
                <el-input-number
                  v-model="form.scoredkmaxval"
                  :min="0"
                  :max="form.scoredkmaxset === 1 ? 100 : undefined"
                  :precision="form.scoredkmaxset === 1 ? 0 : 2"
                  :controls="false"
                  style="width: 150px"
                />
              </template>
            </el-form-item>
            <el-form-item label="赠积分时机">
              <el-radio-group v-model="form.givescore_time">
                <el-radio :value="0">确认收货后赠送</el-radio>
                <el-radio :value="1">支付后立即赠送</el-radio>
              </el-radio-group>
            </el-form-item>

            <!-- 三、交易 / 显示 / 状态 -->
            <el-form-item label="抽成费率">
              <el-input-number v-model="form.feepercent" :min="0" :max="100" :precision="2" :controls="false" placeholder="留空" style="width: 200px" />
              <span class="ge-hint">%，平台收取的佣金比例，留空使用商户统一费率(商家不可修改)</span>
            </el-form-item>
            <el-form-item label="销量">
              <el-input-number v-model="form.sales" :min="0" :controls="false" style="width: 200px" />
            </el-form-item>
            <el-form-item label="起售数量">
              <el-input-number v-model="form.limit_start" :min="0" :controls="false" style="width: 200px" />
              <span class="ge-hint">多少件起卖，0表示不限购</span>
            </el-form-item>
            <el-form-item label="每人限购">
              <el-input-number v-model="form.perlimit" :min="0" :controls="false" style="width: 200px" />
              <span class="ge-hint">每人最多可购买多少件，0表示不限购</span>
            </el-form-item>
            <el-form-item label="每单限购">
              <el-input-number v-model="form.perlimitdan" :min="0" :controls="false" style="width: 200px" />
              <span class="ge-hint">每单最多可购买多少件，0表示不限购</span>
            </el-form-item>
            <el-form-item label="序号">
              <el-input-number v-model="form.sort" :controls="false" style="width: 200px" />
              <span class="ge-hint">用于排序，越大越靠前</span>
            </el-form-item>
            <el-form-item label="显示条件">
              <el-checkbox-group v-model="form.showtj" class="ge-check-grid" @change="(v: string[]) => condChange('showtj', v)">
                <el-checkbox value="-1">所有人</el-checkbox>
                <el-checkbox value="-2">未登录用户</el-checkbox>
                <el-checkbox value="0">关注用户</el-checkbox>
                <el-checkbox v-for="lv in levelList" :key="lv.id" :value="String(lv.id)">{{ lv.name }}</el-checkbox>
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="购买条件">
              <el-checkbox-group v-model="form.gettj" class="ge-check-grid" @change="(v: string[]) => condChange('gettj', v)">
                <el-checkbox value="-1">所有人</el-checkbox>
                <el-checkbox value="0">关注用户</el-checkbox>
                <el-checkbox v-for="lv in levelList" :key="lv.id" :value="String(lv.id)">{{ lv.name }}</el-checkbox>
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="状态">
              <el-radio-group v-model="form.status">
                <el-radio :value="1">已上架</el-radio>
                <el-radio :value="0">未上架</el-radio>
                <el-radio :value="2">设置上架时间</el-radio>
                <el-radio :value="3">设置上架周期</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="form.status === 2" label="上架时间段">
              <el-time-picker v-model="form.start_hours" value-format="HH:mm" format="HH:mm" placeholder="开始时间" style="width: 140px" />
              <span style="margin: 0 8px">至</span>
              <el-time-picker v-model="form.end_hours" value-format="HH:mm" format="HH:mm" placeholder="结束时间" style="width: 140px" />
            </el-form-item>
            <el-form-item v-if="form.status === 3" label="上架周期">
              <el-date-picker v-model="form.start_time" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" style="width: 150px" />
              <span style="margin: 0 8px">至</span>
              <el-date-picker v-model="form.end_time" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" style="width: 150px" />
            </el-form-item>
          </el-tab-pane>

          <!-- ===== 商品参数 ===== -->
          <el-tab-pane label="商品参数" name="param">
            <el-form-item label=" ">
              <div class="ge-block-hint">
                使用商品对应分类下已启用的参数；未关联分类的参数对所有商品生效。若此处为空，请先在「商城-商品参数」中配置，并为商品选择对应分类。
              </div>
            </el-form-item>
            <el-form-item v-if="paramRows.length === 0" label=" ">
              <div class="ge-empty-tip">当前所选分类暂无关联参数</div>
            </el-form-item>
            <el-form-item v-for="p in paramRows" :key="p.id" :label="p.name + '：'">
              <el-input v-if="p.type === 0" v-model="paramdata[p.name]" :placeholder="p.tips || '请输入'" style="width: 400px" />
              <el-select
                v-else-if="p.type === 1"
                v-model="paramdata[p.name]"
                :placeholder="p.tips || '请选择'"
                clearable
                style="width: 400px"
              >
                <el-option v-for="opt in paramOptions(p)" :key="opt" :label="opt" :value="opt" />
              </el-select>
              <el-select
                v-else
                v-model="paramdata[p.name]"
                multiple
                collapse-tags
                :placeholder="p.tips || '可多选'"
                style="width: 400px"
              >
                <el-option v-for="opt in paramOptions(p)" :key="opt" :label="opt" :value="opt" />
              </el-select>
            </el-form-item>
          </el-tab-pane>

          <!-- ===== 商品详情（组件装修） ===== -->
          <el-tab-pane label="商品详情" name="detail">
            <div class="ge-detail-block">
              <div class="ge-detail-head">
                <span class="ge-detail-title">商品详情装修</span>
                <span class="ge-detail-tip">组件搭建，每个组件可单独设置显示平台与显示范围</span>
              </div>
              <GoodsDetailEditor v-model="form.detailModules" />
            </div>
          </el-tab-pane>

          <!-- ===== 分享设置 ===== -->
          <el-tab-pane label="分享设置" name="share">
            <el-form-item label="分享标题">
              <el-input v-model="form.sharetitle" placeholder="留空则使用商品名称" maxlength="60" style="width: 400px" />
            </el-form-item>
            <el-form-item label="分享图标">
              <el-button @click="openImgPicker((urls) => { if (urls[0]) form.sharepic = urls[0] })">
                {{ form.sharepic ? '更换图标' : '选择图标（建议400×400）' }}
              </el-button>
              <el-button v-if="form.sharepic" link @click="form.sharepic = ''">恢复默认主图</el-button>
              <span class="ge-hint">建议 400×400像素，默认为商品主图</span>
              <div class="ge-upload-box" v-if="form.sharepic">
                <el-image :src="form.sharepic" fit="cover" class="main-pic" />
                <span class="ge-upload-del" @click="form.sharepic = ''">×</span>
              </div>
            </el-form-item>
            <el-form-item label="分享描述">
              <el-input v-model="form.sharedesc" type="textarea" :rows="3" placeholder="留空则使用商品卖点" maxlength="120" show-word-limit style="width: 400px" />
            </el-form-item>
          </el-tab-pane>
        </el-tabs>

        <!-- 底部操作：上一步 / 下一步 / 提交 -->
        <div class="tab-footer">
          <el-button v-if="prevLabel" @click="prevTab">{{ prevLabel }}</el-button>
          <el-button v-if="nextLabel" type="primary" @click="nextTab">{{ nextLabel }}</el-button>
          <el-button type="warning" :loading="submitting" @click="handleSubmit">提交</el-button>
        </div>
      </el-form>
    </div>

    <!-- 修改价格 -->
    <el-dialog v-model="priceVisible" title="修改价格" width="420px" append-to-body>
      <el-form label-width="90px">
        <el-form-item label="销售价">
          <el-input-number v-model="priceForm.sell_price" :min="0" :precision="2" :step="1" />
        </el-form-item>
        <el-form-item label="市场价">
          <el-input-number v-model="priceForm.market_price" :min="0" :precision="2" :step="1" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="priceVisible = false">取消</el-button>
        <el-button type="primary" :loading="priceSaving" @click="submitPrice">确定</el-button>
      </template>
    </el-dialog>

    <!-- 商品链接（全站统一组件） -->
    <LinkAddressModal v-model:visible="linkVisible" :name="linkName" :path="linkPath" />

    <!-- 库存导入 -->
    <el-dialog v-model="importVisible" title="Excel 库存导入" width="480px">
      <p class="import-tip">
        模板列：B列=商品编码，E列=规格名，F列=库存（首行含「编码/规格/库存」表头时自动识别列位）。
        规格名找不到时回退该商品第一条 SKU；无 SKU 的单规格商品直接更新商品库存。
      </p>
      <el-upload drag :show-file-list="false" accept=".xlsx,.xls,.csv" :http-request="doStockImport" :disabled="importing">
        <div class="import-drop">
          <p>{{ importing ? '导入中…' : '将 Excel 文件拖到此处，或点击上传' }}</p>
        </div>
      </el-upload>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="imgPickerMulti" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.option-bar {
  margin-bottom: 10px;
}
.option-bar :deep(.el-card__body) {
  padding: 12px 16px;
}
.option-bar .el-button {
  margin-left: 0;
  margin-right: 8px;
}
.search-bar {
  margin-bottom: 12px;
}
.search-bar :deep(.el-card__body) {
  padding: 14px 16px 2px;
}
.search-bar :deep(.el-form-item) {
  margin-bottom: 12px;
}
.search-icon-btn {
  padding: 8px 14px;
}
.search-icon-btn svg {
  display: block;
}
.goods-info {
  display: flex;
  align-items: center;
  gap: 10px;
}
.goods-info-img {
  width: 70px;
  height: 70px;
  border-radius: 4px;
  border: 1px solid #ebeef5;
  flex-shrink: 0;
}
.goods-info-img-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
  background: #fafafa;
}
.goods-info-meta {
  min-width: 0;
  flex: 1;
}
.goods-info-name {
  color: #303133;
  font-size: 13px;
  line-height: 1.5;
  word-break: break-all;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.goods-info-cat {
  color: #f56c6c;
  margin-right: 4px;
}
.goods-info-price {
  color: #ff6600;
  font-weight: 600;
  font-size: 14px;
  margin-top: 6px;
}
.status-on {
  color: #21ba45;
}
.status-off {
  color: #f56c6c;
}
.activity-empty {
  color: #c0c4cc;
}
.row-ops {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  line-height: 1.4;
}
.row-ops :deep(.el-button) {
  height: auto;
  padding: 1px 0;
  margin: 0;
  font-size: 12px;
}
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

/* ===== 整页编辑 ===== */
.goods-edit {
  background: #fff;
  border-radius: 6px;
  padding: 16px 20px;
}
.ge-head {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 12px;
}
.ge-head-title {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}
.ge-section {
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 16px 20px 4px;
  margin-bottom: 16px;
  background: #fff;
}
.ge-section-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  border-left: 3px solid #409eff;
  padding-left: 8px;
  margin-bottom: 16px;
}
.ge-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 24px;
}
.ge-grid :deep(.el-form-item) {
  margin-bottom: 14px;
}
.ge-check-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 18px;
}
.ge-fuwu-desc {
  color: #909399;
  font-size: 12px;
}
.ge-radio-vertical {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.ge-tip,
.sku-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.7;
  margin-top: 4px;
}
.ge-empty-tip {
  color: #909399;
  font-size: 13px;
  padding: 12px 0;
}
.ge-lvprice {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.ge-lvprice-item {
  display: flex;
  align-items: center;
  gap: 8px;
}
.ge-lvprice-name {
  font-size: 13px;
  color: #606266;
}
.tab-footer {
  display: flex;
  justify-content: center;
  gap: 14px;
  padding: 16px 0 8px;
  border-top: 1px solid #ebeef5;
}
.video-line {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* 图片 */
.pic-line {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}
.pic-item {
  display: flex;
  flex-direction: column;
  align-items: center;
}
.pic-thumb,
.main-pic {
  width: 80px;
  height: 80px;
  border-radius: 4px;
  border: 1px solid #e4e7ed;
  object-fit: cover;
}
/* 规格编辑器 */
.spec-editor {
  width: 100%;
}
.spec-group {
  border: 1px solid #ebeef5;
  border-radius: 4px;
  padding: 10px 12px;
  margin-bottom: 10px;
  background: #fafafa;
}
.spec-group-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
}
.spec-item {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
  margin-left: 12px;
}
.spec-value-pic {
  width: 32px;
  height: 32px;
  border-radius: 4px;
  cursor: pointer;
}
.sku-bulk {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 10px;
  flex-wrap: wrap;
}
.sku-bulk-label {
  color: #606266;
  font-size: 13px;
}
.sku-table-wrap {
  width: 100%;
  overflow-x: auto;
}
.sku-table {
  border-collapse: collapse;
  width: 100%;
  font-size: 13px;
}
.sku-table th,
.sku-table td {
  border: 1px solid #dcdfe6;
  padding: 6px 8px;
  text-align: center;
  white-space: nowrap;
}
.sku-table th {
  background: #f5f7fa;
  color: #606266;
  font-weight: 600;
}
.sku-spec-cell {
  background: #fafafa;
  font-weight: 500;
}
.sku-pic {
  width: 40px;
  height: 40px;
  border-radius: 4px;
  cursor: pointer;
}
.sku-pic-empty {
  color: #409eff;
  cursor: pointer;
  font-size: 12px;
}
.import-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.7;
  margin: 0 0 12px;
}
.import-drop {
  padding: 20px 0;
  color: #606266;
}
/* ===== 水平表单：行内灰色说明 ===== */
.ge-form :deep(.el-form-item) {
  margin-bottom: 15px;
}
.ge-hint {
  color: #909399;
  font-size: 12px;
  line-height: 1.5;
  margin-left: 10px;
}
.ge-block-hint {
  color: #909399;
  font-size: 12px;
  line-height: 1.9;
  width: 100%;
}
.ge-form :deep(.el-radio) {
  margin-right: 14px;
}
.ge-upload-box {
  position: relative;
  width: 100px;
  height: 100px;
  margin-top: 8px;
}
.ge-upload-box .main-pic {
  width: 100px;
  height: 100px;
  border-radius: 4px;
  border: 1px solid #e4e7ed;
}
.ge-upload-add {
  width: 100px;
  height: 100px;
  border: 1px dashed #dcdfe6;
  border-radius: 4px;
  background: #fafafa;
  color: #c0c4cc;
  font-size: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.ge-upload-add:hover {
  border-color: #409eff;
  color: #409eff;
}
.ge-upload-del {
  position: absolute;
  top: -8px;
  right: -8px;
  width: 18px;
  height: 18px;
  line-height: 16px;
  text-align: center;
  background: #909399;
  color: #fff;
  border-radius: 50%;
  font-size: 12px;
  cursor: pointer;
  z-index: 2;
}
.ge-thumb-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  width: 100%;
  margin-top: 8px;
}
.ge-thumb-item {
  position: relative;
}
.ge-thumb-item .pic-thumb {
  width: 80px;
  height: 80px;
  border-radius: 4px;
  border: 1px solid #e4e7ed;
}
.ge-thumb-item .ge-upload-del {
  top: -6px;
  right: -6px;
}
.gg-table {
  border-collapse: collapse;
  width: 100%;
  max-width: 760px;
  font-size: 13px;
}
.gg-table th,
.gg-table td {
  border: 1px solid #dcdfe6;
  padding: 8px;
  text-align: left;
  vertical-align: top;
}
.gg-table th {
  background: #f5f7fa;
  color: #606266;
  font-weight: 600;
  text-align: center;
}
.gg-name-line {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}
.gg-name-line:last-child {
  margin-bottom: 0;
}
.ge-detail-block {
  margin-bottom: 20px;
}
.ge-detail-head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  margin-bottom: 10px;
}
.ge-detail-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  border-left: 3px solid #409eff;
  padding-left: 8px;
  line-height: 18px;
}
.ge-detail-tip {
  font-size: 12px;
  color: #909399;
}
.ge-comm-line {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 20px;
}
.ge-comm-cell {
  display: inline-flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  font-size: 12px;
  color: #909399;
  font-style: normal;
}
.ge-comm-empty {
  color: #f56c6c;
  font-size: 12px;
}
</style>
