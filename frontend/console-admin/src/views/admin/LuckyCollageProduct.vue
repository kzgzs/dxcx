<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  batchDeleteLuckyCollageProducts,
  batchSetLuckyCollageProductStatus,
  checkLuckyCollageProduct,
  deleteLuckyCollageCode,
  getLuckyCollageCategoryList,
  getLuckyCollageCodeList,
  getLuckyCollageProductDetail,
  getLuckyCollageProductList,
  importLuckyCollageCode,
  luckyCollageKaituan,
  saveLuckyCollageProduct,
} from '../../api/luckyCollage'
import type {
  LuckyCollageCategoryItem,
  LuckyCollageCodeItem,
  LuckyCollageProductItem,
} from '../../api/luckyCollage'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'
import type { DesignerModule } from '../../api/designer'
import { defaultModule } from './designerModules'
import { getGoodsList, getGoodsDetail } from '../../api/goods'
import type { GoodsItem } from '../../api/types'
import { getFreightList } from '../../api/tenantSet'
import type { FreightItem } from '../../api/types'

// ============ 双模式：bid 有值=商户视图(showtype=2)，无值=平台自营视图 ============
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchant = computed(() => !!props.bid)

// ============ 列表 ============
const viewMode = ref<'list' | 'edit'>('list')
const loading = ref(false)
const tableData = ref<LuckyCollageProductItem[]>([])
const total = ref(0)
const selected = ref<LuckyCollageProductItem[]>([])
const categories = ref<LuckyCollageCategoryItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  cid: '' as number | '',
  status: '' as number | '',
  orderField: '',
  orderType: '',
})

async function fetchCategories() {
  try {
    const data = await getLuckyCollageCategoryList()
    categories.value = data.list || []
  } catch {
    // 忽略
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getLuckyCollageProductList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      cid: query.cid === '' ? undefined : Number(query.cid),
      status: query.status === '' ? undefined : query.status,
      orderField: query.orderField || undefined,
      orderType: query.orderType || undefined,
      bid: props.bid || undefined,
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

function handleSelectionChange(rows: LuckyCollageProductItem[]) {
  selected.value = rows
}

function handleSortChange({ prop, order }: { prop: string; order: null | 'ascending' | 'descending' }) {
  if (!prop || !order) {
    query.orderField = ''
    query.orderType = ''
  } else {
    query.orderField = prop
    query.orderType = order === 'ascending' ? 'asc' : 'desc'
  }
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

function requireSelection(): boolean {
  if (selected.value.length === 0) {
    ElMessage.warning('请选择数据')
    return false
  }
  return true
}

// ============ 批量操作 ============
function handleBatchDelete() {
  if (!requireSelection()) return
  const ids = selected.value.map((r) => r.id)
  ElMessageBox.confirm('确定要删除选中的幸运拼团商品吗？删除后不可恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchDeleteLuckyCollageProducts(ids)
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function handleBatchStatus(st: number) {
  if (!requireSelection()) return
  const ids = selected.value.map((r) => r.id)
  ElMessageBox.confirm(`确定要${st === 1 ? '上架' : '下架'}选中的商品吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchSetLuckyCollageProductStatus(ids, st)
      ElMessage.success(st === 1 ? '已上架' : '已下架')
      fetchList()
    })
    .catch(() => {})
}

// ============ 行操作 ============
function handleRowDelete(row: LuckyCollageProductItem) {
  ElMessageBox.confirm(`确定要删除幸运拼团商品「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchDeleteLuckyCollageProducts([row.id])
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function handleRowStatus(row: LuckyCollageProductItem) {
  const target = row.status === 1 ? 0 : 1
  ElMessageBox.confirm(`确定要${target === 1 ? '上架' : '下架'}「${row.name}」吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchSetLuckyCollageProductStatus([row.id], target)
      ElMessage.success(target === 1 ? '已上架' : '已下架')
      fetchList()
    })
    .catch(() => {})
}

function handleCheck(row: LuckyCollageProductItem, pass: boolean) {
  if (pass) {
    ElMessageBox.confirm(`确定通过商品「${row.name}」的审核吗？`, '审核确认', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'success',
    })
      .then(async () => {
        await checkLuckyCollageProduct(row.id, 1)
        ElMessage.success('审核通过')
        fetchList()
      })
      .catch(() => {})
    return
  }
  ElMessageBox.prompt('请输入驳回原因', '驳回审核', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '请填写驳回原因',
    type: 'warning',
  })
    .then(async ({ value }) => {
      await checkLuckyCollageProduct(row.id, 2, (value || '').trim())
      ElMessage.success('已驳回')
      fetchList()
    })
    .catch(() => {})
}

function handleKaituan(row: LuckyCollageProductItem) {
  ElMessageBox.confirm('确定要开团吗？', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      const res = await luckyCollageKaituan(row.id, 2)
      ElMessage.success(res.kaijiang ? '机器人已开团并触发开奖' : '开团成功')
      fetchList()
    })
    .catch(() => {})
}

// ============ 平铺编辑（viewMode） ============
const isEdit = ref(false)
const activeTab = ref('basic')
const formRef = ref<FormInstance>()
const submitting = ref(false)

interface SpecGroup {
  title: string
  items: { title: string }[]
}
interface SkuRow {
  specName: string
  procode: string
  market_price: number
  sell_price: number
  weight: number
  stock: number
  pic: string
}

function defaultDetailModules(): DesignerModule[] {
  return [defaultModule('richtext')]
}

const form = reactive({
  id: 0,
  goods_id: 0,
  name: '',
  pic: '',
  picsArr: [] as string[],
  cid: '' as number | '',
  // 规格
  // 会员等级折扣 -1跟随系统 0参与 1不参与
  no_discount: -1,
  // 配送模板 1全部模板 0选择模板 3自动发货 4在线卡密
  freighttype: 1,
  freightid: 0,
  teamnum: 3,
  gua_num: 1,
  // 不中奖红包 1按比例 2按金额
  fy_type: 1,
  fy_money: 1,
  fy_money_val: 1,
  // 返红包模式 1返到余额 2返到微信零钱
  red_give_mode: 1,
  // 退款路线 / 失败退款路线 1原路退回 2退款到余额
  tklx: 1,
  failtklx: 1,
  buymax: 0,
  perlimitdan: 0,
  teamhour: 36,
  leadermoney: 0,
  leaderscore: 0,
  // 中奖用户分销设置 0按会员等级 1单独比例 2单独金额 3送积分 -1不参与
  commissionset: 0,
  commission1: 0,
  commission2: 0,
  commission3: 0,
  sales: 0,
  sort: 0,
  status: 1,
  detailModules: [] as DesignerModule[],
})

const specGroups = ref<SpecGroup[]>([{ title: '规格', items: [{ title: '默认规格' }] }])
const specDone = ref(false)
const skuRows = ref<SkuRow[]>([])
const freightList = ref<FreightItem[]>([])

const COMMISSION_OPTIONS = [
  { value: 0, label: '按照会员等级' },
  { value: 1, label: '单独设置提成比例' },
  { value: 2, label: '单独设置提成金额' },
  { value: 3, label: '分销送积分' },
  { value: -1, label: '不参与分销' },
]

const rules: FormRules = {
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  teamnum: [{ required: true, message: '请输入拼团人数', trigger: 'blur' }],
  gua_num: [{ required: true, message: '请输入中奖人数', trigger: 'blur' }],
  teamhour: [{ required: true, message: '请输入拼团时间', trigger: 'blur' }],
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    goods_id: 0,
    name: '',
    pic: '',
    picsArr: [],
    cid: '',
    no_discount: -1,
    freighttype: 1,
    freightid: 0,
    teamnum: 3,
    gua_num: 1,
    fy_type: 1,
    fy_money: 1,
    fy_money_val: 1,
    red_give_mode: 1,
    tklx: 1,
    failtklx: 1,
    buymax: 0,
    perlimitdan: 0,
    teamhour: 36,
    leadermoney: 0,
    leaderscore: 0,
    commissionset: 0,
    commission1: 0,
    commission2: 0,
    commission3: 0,
    sales: 0,
    sort: 0,
    status: 1,
    detailModules: defaultDetailModules(),
  })
  specGroups.value = [{ title: '规格', items: [{ title: '默认规格' }] }]
  specDone.value = false
  buildDefaultSku()
}

function buildDefaultSku() {
  skuRows.value = [
    { specName: '默认规格', procode: '', market_price: 0, sell_price: 0, weight: 0, stock: 1000, pic: '' },
  ]
}

function openAdd() {
  isEdit.value = false
  resetForm()
  activeTab.value = 'basic'
  viewMode.value = 'edit'
}

async function openEdit(row: LuckyCollageProductItem) {
  isEdit.value = true
  resetForm()
  activeTab.value = 'basic'
  viewMode.value = 'edit'
  try {
    const detail = (await getLuckyCollageProductDetail(row.id)) as Record<string, unknown> & {
      pics?: string
      options?: Record<string, unknown>[]
      detail?: string
    }
    form.id = Number(detail.id || 0)
    form.goods_id = Number(detail.goods_id || 0)
    form.name = String(detail.name || '')
    form.pic = String(detail.pic || '')
    form.picsArr = detail.pics ? String(detail.pics).split(',').filter(Boolean) : []
    form.cid = detail.cid ? Number(detail.cid) : ''
    form.no_discount = Number(detail.no_discount ?? -1)
    form.freighttype = Number(detail.freighttype ?? 1)
    form.freightid = Number(detail.freightid ?? 0)
    form.teamnum = Number(detail.teamnum ?? 3)
    form.gua_num = Number(detail.gua_num ?? 1)
    form.fy_type = Number(detail.fy_type ?? 1)
    form.fy_money = Number(detail.fy_money ?? 1)
    form.fy_money_val = Number(detail.fy_money_val ?? 1)
    form.red_give_mode = Number(detail.red_give_mode ?? 1)
    form.tklx = Number(detail.tklx ?? 1)
    form.failtklx = Number(detail.failtklx ?? 1)
    form.buymax = Number(detail.buymax ?? 0)
    form.perlimitdan = Number(detail.perlimitdan ?? 0)
    form.teamhour = Number(detail.teamhour ?? 36)
    form.leadermoney = Number(detail.leadermoney ?? 0)
    form.leaderscore = Number(detail.leaderscore ?? 0)
    form.commissionset = Number(detail.commissionset ?? 0)
    form.commission1 = Number(detail.commission1 ?? 0)
    form.commission2 = Number(detail.commission2 ?? 0)
    form.commission3 = Number(detail.commission3 ?? 0)
    form.sales = Number(detail.sales ?? 0)
    form.sort = Number(detail.sort ?? 0)
    form.status = Number(detail.status ?? 1)

    // 详情装修器：detail 为 DesignerModule[] 的 JSON
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

    // 规格分组 guigedata
    if (detail.guigedata) {
      try {
        const g = JSON.parse(String(detail.guigedata))
        if (Array.isArray(g) && g.length) {
          specGroups.value = g.map((gr: Record<string, unknown>) => ({
            title: String(gr.title || ''),
            items: Array.isArray(gr.items) ? gr.items.map((it: Record<string, unknown>) => ({ title: String(it.title || '') })) : [],
          }))
          specDone.value = true
        }
      } catch {
        // ignore
      }
    }

    // 规格 SKU
    const opts = detail.options || []
    if (opts.length) {
      skuRows.value = opts.map((o) => ({
        specName: String(o.name || ''),
        procode: String(o.procode || ''),
        market_price: Number(o.market_price) || 0,
        sell_price: Number(o.sell_price) || 0,
        weight: Number(o.weight) || 0,
        stock: Number(o.stock) || 0,
        pic: String(o.pic || ''),
      }))
      if (!specDone.value) {
        specGroups.value = [{ title: '规格', items: opts.map((o) => ({ title: String(o.name || '') })) }]
      }
    } else {
      buildDefaultSku()
    }
  } catch {
    viewMode.value = 'list'
  }
}

function backToList() {
  viewMode.value = 'list'
  resetForm()
  fetchList()
}

// ============ 规格分组 / SKU ============
function addGroup() {
  specGroups.value.push({ title: '', items: [{ title: '' }] })
}
function removeGroup(i: number) {
  specGroups.value.splice(i, 1)
}
function addItem(g: SpecGroup) {
  g.items.push({ title: '' })
}
function removeItem(g: SpecGroup, i: number) {
  g.items.splice(i, 1)
}

// 设置完成：按规格分组笛卡尔积展开 SKU 表，保留已填值
function refreshSku() {
  const groups = specGroups.value.filter((g) => g.title.trim() && g.items.some((it) => it.title.trim()))
  if (groups.length === 0) {
    ElMessage.warning('请先填写规格分组与规格名称')
    specDone.value = false
    return
  }
  const dims = groups.map((g) => g.items.map((it) => it.title.trim()).filter(Boolean))
  if (dims.some((d) => d.length === 0)) {
    ElMessage.warning('请完整填写规格分组与规格名称')
    return
  }
  let combos: string[][] = [[]]
  for (const d of dims) {
    const next: string[][] = []
    for (const c of combos) for (const t of d) next.push([...c, t])
    combos = next
  }
  const prev = new Map(skuRows.value.map((r) => [r.specName, r]))
  skuRows.value = combos.map((names) => {
    const specName = names.join(' ')
    const old = prev.get(specName)
    return old
      ? { ...old, specName }
      : { specName, procode: '', market_price: 0, sell_price: 0, weight: 0, stock: 1000, pic: '' }
  })
  ElMessage.success('规格设置完成')
}

function onSpecDoneChange(val: boolean) {
  if (val) refreshSku()
}

// ============ 下一步 / 提交 ============
async function goDetail() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }
  if (!form.pic) {
    ElMessage.warning('请上传商品主图')
    return
  }
  if (form.gua_num < 1 || form.gua_num >= form.teamnum) {
    ElMessage.warning('中奖人数必须大于等于1，并且小于拼团人数')
    return
  }
  activeTab.value = 'detail'
}

async function handleSubmit() {
  if (!formRef.value) return
  // 基本信息校验
  if (activeTab.value === 'detail') {
    try {
      await formRef.value.validate()
    } catch {
      activeTab.value = 'basic'
      return
    }
  } else {
    await formRef.value.validate(async (valid) => {
      if (!valid) return
      await doSubmit()
    })
    return
  }
  await doSubmit()
}

async function doSubmit() {
  if (!form.pic) {
    ElMessage.warning('请上传商品主图')
    activeTab.value = 'basic'
    return
  }
  if (form.gua_num < 1 || form.gua_num >= form.teamnum) {
    ElMessage.warning('中奖人数必须大于等于1，并且小于拼团人数')
    activeTab.value = 'basic'
    return
  }
  if (skuRows.value.length === 0) {
    ElMessage.warning('请设置商品规格')
    activeTab.value = 'basic'
    return
  }
  submitting.value = true
  try {
    await saveLuckyCollageProduct({
      id: form.id || undefined,
      goods_id: form.goods_id || undefined,
      name: form.name,
      pic: form.pic,
      pics: form.picsArr.join(','),
      cid: form.cid === '' ? 0 : Number(form.cid),
      no_discount: form.no_discount,
      freighttype: form.freighttype,
      freightid: form.freighttype === 0 ? form.freightid : 0,
      teamnum: form.teamnum,
      gua_num: form.gua_num,
      fy_type: form.fy_type,
      fy_money: form.fy_money,
      fy_money_val: form.fy_money_val,
      red_give_mode: form.red_give_mode,
      tklx: form.tklx,
      failtklx: form.failtklx,
      buymax: form.buymax,
      perlimitdan: form.perlimitdan,
      teamhour: form.teamhour,
      leadermoney: form.leadermoney,
      leaderscore: form.leaderscore,
      commissionset: form.commissionset,
      commission1: form.commission1,
      commission2: form.commission2,
      commission3: form.commission3,
      sales: form.sales,
      sort: form.sort,
      status: form.status,
      detail: JSON.stringify(form.detailModules || []),
      guigedata: JSON.stringify(specGroups.value.filter((g) => g.title.trim())),
      options: skuRows.value.map((r) => ({
        name: r.specName,
        procode: r.procode,
        market_price: r.market_price,
        sell_price: r.sell_price,
        weight: r.weight,
        stock: r.stock,
        pic: r.pic,
      })),
    })
    ElMessage.success('保存成功')
    backToList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

// ============ 图片选择 ============
const picPickerVisible = ref(false)
const picsPickerVisible = ref(false)
function onPickPic(urls: string[]) {
  if (urls[0]) form.pic = urls[0]
}
function onPickPics(urls: string[]) {
  form.picsArr = urls.slice()
}
function removePic(idx: number) {
  form.picsArr.splice(idx, 1)
}

// SKU 行图片上传
const skuPicPickerVisible = ref(false)
let skuPicTarget: SkuRow | null = null
function openSkuPic(row: SkuRow) {
  skuPicTarget = row
  skuPicPickerVisible.value = true
}
function onPickSkuPic(urls: string[]) {
  if (skuPicTarget && urls[0]) skuPicTarget.pic = urls[0]
}

// ============ 选择商城商品 ============
const chooseVisible = ref(false)
const chooseLoading = ref(false)
const chooseKeyword = ref('')
const choosePage = ref(1)
const chooseTotal = ref(0)
const chooseList = ref<GoodsItem[]>([])

async function openChoose() {
  chooseVisible.value = true
  chooseKeyword.value = ''
  choosePage.value = 1
  await fetchChoose()
}
async function fetchChoose() {
  chooseLoading.value = true
  try {
    const d = await getGoodsList({ page: choosePage.value, pagesize: 10, keyword: chooseKeyword.value || undefined, status: 1 })
    chooseList.value = d.list || []
    chooseTotal.value = d.total || 0
  } catch {
    // ignore
  } finally {
    chooseLoading.value = false
  }
}
function handleChooseSearch() {
  choosePage.value = 1
  fetchChoose()
}
async function pickGoods(g: GoodsItem) {
  form.goods_id = g.id
  form.name = g.name
  form.pic = g.image
  if (g.image) form.picsArr = [g.image]
  // 带入详情：规格 / 图片
  try {
    const gd = await getGoodsDetail(g.id)
    if (gd.images) {
      const arr = String(gd.images).split(',').filter(Boolean)
      if (arr.length) form.picsArr = arr
    }
    // 带入规格
    const skus = gd.skus || []
    if (skus.length) {
      skuRows.value = skus.map((s) => ({
        specName: String(s.name || ''),
        procode: String(s.procode || ''),
        market_price: Number(s.market_price) || 0,
        sell_price: Number(s.sell_price) || 0,
        weight: Number(s.weight) || 0,
        stock: Number(s.stock) || 0,
        pic: String(s.pic || ''),
      }))
    } else {
      // 预填到 SKU：单买价=市场价，拼团价=商城价
      skuRows.value.forEach((r) => {
        r.market_price = Number(g.market_price || g.price)
        r.sell_price = Number(g.price) || r.sell_price
      })
    }
  } catch {
    // 详情获取失败则仅带基础字段
  }
  chooseVisible.value = false
}

// ============ 卡密信息（freighttype=4 在线卡密商品） ============
const codeVisible = ref(false)
const codeLoading = ref(false)
const codeProid = ref(0)
const codeProname = ref('')
const codeList = ref<LuckyCollageCodeItem[]>([])
const codeTotal = ref(0)
const codeQuery = reactive({ page: 1, pagesize: 10, status: '' as number | '' })
const codeSubmitting = ref(false)
const codeContent = ref('')

async function fetchCodeList() {
  codeLoading.value = true
  try {
    const data = await getLuckyCollageCodeList({
      proid: codeProid.value,
      page: codeQuery.page,
      pagesize: codeQuery.pagesize,
      status: codeQuery.status === '' ? undefined : codeQuery.status,
    })
    codeList.value = data.list || []
    codeTotal.value = data.total || 0
  } catch {
    /* 拦截器统一提示 */
  } finally {
    codeLoading.value = false
  }
}

function openCode(row: LuckyCollageProductItem) {
  codeProid.value = row.id
  codeProname.value = row.name
  codeQuery.page = 1
  codeQuery.status = ''
  codeContent.value = ''
  codeVisible.value = true
  fetchCodeList()
}

async function submitCodeImport() {
  const lines = codeContent.value.split(/\r?\n/).map((s) => s.trim()).filter(Boolean)
  if (!lines.length) {
    ElMessage.warning('请输入卡密内容，每行一条')
    return
  }
  codeSubmitting.value = true
  try {
    const res = await importLuckyCollageCode(codeProid.value, codeContent.value)
    ElMessage.success(`导入 ${res.added} 条，重复跳过 ${res.skipped} 条`)
    codeContent.value = ''
    fetchCodeList()
  } catch {
    /* 拦截器统一提示 */
  } finally {
    codeSubmitting.value = false
  }
}

function handleCodeDelete(row: LuckyCollageCodeItem) {
  ElMessageBox.confirm('确定要删除该卡密吗？已售出的卡密不可删除。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLuckyCollageCode(row.id)
        ElMessage.success('删除成功')
        fetchCodeList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

async function fetchFreightList() {
  try {
    const d = await getFreightList()
    freightList.value = d.list || []
  } catch {
    // ignore
  }
}

onMounted(() => {
  fetchCategories()
  fetchList()
  fetchFreightList()
})
</script>

<template>
  <!-- ============ 列表视图 ============ -->
  <div v-if="viewMode === 'list'">
    <el-alert
      v-if="isMerchant"
      type="warning"
      :closable="false"
      title="请确保商家余额充足，否则会发放奖励失败"
      style="margin-bottom: 3px"
    />

    <el-card shadow="never" class="toolbar">
      <div class="toolbar-left">
        <el-button v-if="!isMerchant" type="success" @click="openAdd">添加</el-button>
        <el-button @click="handleBatchDelete">删除</el-button>
        <el-button @click="handleBatchStatus(1)">上架</el-button>
        <el-button @click="handleBatchStatus(0)">下架</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item label="商品名称">
          <el-input
            v-model="query.keyword"
            placeholder="商品名称"
            clearable
            style="width: 120px"
            @keyup.enter="handleSearch"
          />
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
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="40" align="center" />
        <el-table-column prop="id" label="ID" width="60" sortable="custom" align="center" />
        <el-table-column v-if="isMerchant" prop="bname" label="所属商户" width="96" align="center" />
        <el-table-column label="商品信息" min-width="260">
          <template #default="{ row }">
            <div class="goods-info">
              <el-image
                v-if="row.pic"
                :src="row.pic"
                :preview-src-list="[row.pic]"
                fit="cover"
                class="goods-info-img"
                preview-teleported
              />
              <div v-else class="goods-info-img goods-info-img-empty">无图</div>
              <div class="goods-info-meta">
                <div class="goods-info-name">
                  <span v-if="row.cname" class="goods-info-cname">[{{ row.cname }}]</span>{{ row.name }}
                </div>
                <div class="goods-info-price">￥{{ Number(row.sell_price).toFixed(2) }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="teamnum" label="拼团人数" width="80" align="center" />
        <el-table-column prop="stock" label="库存" width="80" align="center" />
        <el-table-column prop="sales" label="销量" width="80" sortable="custom" align="center" />
        <el-table-column prop="sort" label="序号" width="70" sortable="custom" align="center" />
        <el-table-column prop="createtime" label="创建时间" width="132" sortable="custom" align="center">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '已上架' : '未上架' }}</span>
          </template>
        </el-table-column>
        <el-table-column v-if="isMerchant" label="审核状态" width="86" align="center">
          <template #default="{ row }">
            <span v-if="row.ischecked === 1" class="check-on">已通过</span>
            <span v-else-if="row.ischecked === 2" class="check-off" :title="row.check_reason">已驳回</span>
            <span v-else class="check-wait">待审核</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="240">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
              <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleRowStatus(row)">
                {{ row.status === 1 ? '下架' : '上架' }}
              </el-button>
              <el-button link type="primary" @click="handleKaituan(row)">机器人开团</el-button>
              <el-button v-if="row.freighttype === 4" link type="warning" @click="openCode(row)">卡密信息</el-button>
              <template v-if="isMerchant">
                <el-button
                  v-if="row.ischecked === 0 || row.ischecked === 2"
                  link
                  type="success"
                  @click="handleCheck(row, true)"
                >通过</el-button>
                <el-button
                  v-if="row.ischecked === 0 || row.ischecked === 1"
                  link
                  type="warning"
                  @click="handleCheck(row, false)"
                >驳回</el-button>
              </template>
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

  <!-- ============ 平铺编辑视图 ============ -->
  <div v-else class="goods-edit">
    <div class="ge-head">
      <span class="ge-head-title">{{ isEdit ? '编辑商品' : '＋添加商品' }}</span>
      <el-button class="ge-close" @click="backToList">✕</el-button>
    </div>

    <el-form
      ref="formRef"
      :model="form"
      :rules="rules"
      label-position="right"
      label-width="116px"
      class="ge-form"
    >
      <el-tabs v-model="activeTab" class="ge-tabs">
        <!-- ===== tab1 商品基本信息 ===== -->
        <el-tab-pane label="商品基本信息" name="basic">
          <el-form-item label="商品名称" required>
            <el-input v-model="form.name" maxlength="120" style="width: 300px" placeholder="请输入商品名称" />
            <el-button style="margin-left: 10px" @click="openChoose">选择商城商品</el-button>
          </el-form-item>

          <el-form-item label="商品主图">
            <div class="ge-pic-row">
              <el-image v-if="form.pic" :src="form.pic" fit="cover" class="main-pic" />
              <el-button size="small" @click="picPickerVisible = true">上传图片</el-button>
              <el-button v-if="form.pic" size="small" link type="danger" @click="form.pic = ''">删除</el-button>
              <span class="ge-hint">建议尺寸：640×640像素</span>
            </div>
          </el-form-item>

          <el-form-item label="商品图片">
            <div class="ge-pic-row">
              <div v-for="(url, i) in form.picsArr" :key="url" class="ge-thumb-item">
                <el-image :src="url" fit="cover" class="pic-thumb" />
                <span class="ge-upload-del" @click="removePic(i)">×</span>
              </div>
              <el-button size="small" @click="picsPickerVisible = true">批量上传</el-button>
              <span class="ge-hint">建议尺寸：640×640像素</span>
            </div>
          </el-form-item>

          <el-form-item label="商品分类">
            <el-select v-model="form.cid" placeholder="-请选择-" clearable style="width: 400px">
              <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="设置规格">
            <div class="spec-wrap">
              <table class="gg-table">
                <thead>
                  <tr>
                    <th style="width: 150px">规格分组</th>
                    <th>规格名称</th>
                    <th style="width: 90px">操作</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(g, gi) in specGroups" :key="gi">
                    <td>
                      <el-input v-model="g.title" style="width: 120px" placeholder="规格分组" />
                    </td>
                    <td>
                      <div v-for="(it, ii) in g.items" :key="ii" class="gg-name-line">
                        <el-input v-model="it.title" style="width: 200px" placeholder="请输入规格名称" />
                        <el-button size="small" @click="addItem(g)">+</el-button>
                        <el-button v-if="ii > 0" size="small" type="danger" plain @click="removeItem(g, ii)">×</el-button>
                      </div>
                    </td>
                    <td>
                      <el-button v-if="gi === 0" size="small" @click="addGroup">添加</el-button>
                      <el-button v-else size="small" type="danger" plain @click="removeGroup(gi)">删除</el-button>
                    </td>
                  </tr>
                </tbody>
              </table>
              <el-checkbox v-model="specDone" style="margin-top: 8px" @change="onSpecDoneChange">设置完成</el-checkbox>

              <table v-if="skuRows.length" class="sku-table">
                <thead>
                  <tr>
                    <th>规格</th>
                    <th>规格编号</th>
                    <th>单买价(元)</th>
                    <th>拼团价(元)</th>
                    <th>重量(克)</th>
                    <th>库存</th>
                    <th>图片</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, ri) in skuRows" :key="ri">
                    <td class="sku-spec-cell">{{ row.specName }}</td>
                    <td><el-input v-model="row.procode" placeholder="编号" style="width: 110px" /></td>
                    <td><el-input-number v-model="row.market_price" :min="0" :precision="2" :controls="false" style="width: 95px" /></td>
                    <td><el-input-number v-model="row.sell_price" :min="0" :precision="2" :controls="false" style="width: 95px" /></td>
                    <td><el-input-number v-model="row.weight" :min="0" :controls="false" style="width: 85px" /></td>
                    <td><el-input-number v-model="row.stock" :min="0" :controls="false" style="width: 85px" /></td>
                    <td>
                      <el-image
                        v-if="row.pic"
                        :src="row.pic"
                        fit="cover"
                        class="sku-pic"
                        @click="openSkuPic(row)"
                      />
                      <span v-else class="sku-pic-empty" @click="openSkuPic(row)">上传</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </el-form-item>

          <el-form-item label="会员等级折扣">
            <el-radio-group v-model="form.no_discount">
              <el-radio :value="-1">跟随系统</el-radio>
              <el-radio :value="0">参与</el-radio>
              <el-radio :value="1">不参与</el-radio>
            </el-radio-group>
            <span class="ge-hint">跟随系统：幸运拼团-系统设置-会员等级折扣</span>
          </el-form-item>

          <el-form-item label="配送模板">
            <el-radio-group v-model="form.freighttype">
              <el-radio :value="1">全部模板</el-radio>
              <el-radio :value="0">选择模板</el-radio>
              <el-radio :value="3">自动发货</el-radio>
              <el-radio :value="4">在线卡密</el-radio>
            </el-radio-group>
            <el-select
              v-if="form.freighttype === 0"
              v-model="form.freightid"
              placeholder="-请选择配送模板-"
              style="width: 220px; margin-left: 10px"
            >
              <el-option v-for="f in freightList" :key="f.id" :label="f.name" :value="f.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="拼团人数" required>
            <el-input-number v-model="form.teamnum" :min="2" :max="100" :precision="0" :step="1" />
            <span class="ge-hint">每个团多少人</span>
          </el-form-item>

          <el-form-item label="中奖人数" required>
            <el-input-number v-model="form.gua_num" :min="1" :max="form.teamnum - 1" :precision="0" />
            <span class="ge-hint">中奖人数必须大于等于1，并且小于拼团人数</span>
          </el-form-item>

          <el-form-item label="不中奖红包">
            <el-radio-group v-model="form.fy_type">
              <el-radio :value="1">按比例</el-radio>
              <el-radio :value="2">按金额</el-radio>
            </el-radio-group>
            <el-input-number
              v-if="form.fy_type === 1"
              v-model="form.fy_money"
              :min="0"
              :max="100"
              :precision="2"
              style="width: 140px; margin-left: 10px"
            />
            <el-input-number
              v-if="form.fy_type === 2"
              v-model="form.fy_money_val"
              :min="0"
              :precision="2"
              style="width: 140px; margin-left: 10px"
            />
            <div class="ge-block-hint">
              <span v-if="form.fy_type === 1">按比例按商品最低金额比例</span>
              <span v-else>按金额新不中用户每人都获得该金额红包</span>
            </div>
          </el-form-item>

          <el-form-item label="返红包模式">
            <el-radio-group v-model="form.red_give_mode">
              <el-radio :value="1">返到余额</el-radio>
              <el-radio :value="2">返到微信零钱</el-radio>
            </el-radio-group>
            <span class="ge-hint">返微信零钱需去微信开通「企业付款到零钱」功能；且需设置最低金额，微信商户号企业到零钱功能一般最低提现金额是一元，如有特殊情况自行设定金额</span>
          </el-form-item>

          <el-form-item label="退款路线">
            <el-radio-group v-model="form.tklx">
              <el-radio :value="1">原路退回</el-radio>
              <el-radio :value="2">退款到余额</el-radio>
            </el-radio-group>
            <span class="ge-hint">默认原路退回</span>
          </el-form-item>

          <el-form-item label="失败退款路线">
            <el-radio-group v-model="form.failtklx">
              <el-radio :value="1">原路退回</el-radio>
              <el-radio :value="2">退款到余额</el-radio>
            </el-radio-group>
            <span class="ge-hint">默认原路退回</span>
          </el-form-item>

          <el-form-item label="每人限购">
            <el-input-number v-model="form.buymax" :min="0" :precision="0" :step="1" />
            <span class="ge-hint">每人最多可购买多少件，0表示不限购</span>
          </el-form-item>

          <el-form-item label="每单限购">
            <el-input-number v-model="form.perlimitdan" :min="0" :precision="0" :step="1" />
            <span class="ge-hint">每单最多可购买多少件，0表示不限购</span>
          </el-form-item>

          <el-form-item label="拼团时间">
            <el-input-number v-model="form.teamhour" :min="1" :max="720" :precision="0" :step="1" />
            <span class="ge-hint">小时</span>
            <span class="ge-hint">超过该时间人数未满则拼团失败，自动退款</span>
          </el-form-item>

          <el-form-item label="团长优惠金额">
            <el-input-number v-model="form.leadermoney" :min="0" :precision="2" :step="1" />
            <span class="ge-hint">元</span>
            <span class="ge-hint">在拼团价基础上再优惠多少钱，0表示不优惠</span>
          </el-form-item>

          <el-form-item label="奖励积分">
            <el-input-number v-model="form.leaderscore" :min="0" :precision="0" :step="1" />
            <span class="ge-hint">拼团成功后赠送多少积分</span>
          </el-form-item>

          <el-form-item label="中奖用户分销设置">
            <el-radio-group v-model="form.commissionset">
              <el-radio v-for="o in COMMISSION_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
            </el-radio-group>
            <div class="ge-block-hint">
              按照会员等级：按照会员等级中设置的提成比例进行提成分配，提成=对应会员等级的提成比例×购买数量<br>
              单独设置提成比例：按照单独设置的提成比例进行提成分配，提成=设置的百分比×购买数量<br>
              单独设置提成金额：按照单独设置的提成金额进行提成分配，提成=提成金额×购买数量<br>
              分销送积分：分销提成不给佣金而是给积分，得到的积分=提成积分×购买数量
            </div>
            <el-input-number
              v-if="form.commissionset === 1"
              v-model="form.commission1"
              :min="0"
              :max="100"
              :precision="2"
              style="width: 160px; margin-top: 8px"
            />
            <span v-if="form.commissionset === 1" class="ge-hint">一级提成比例(%)</span>
            <el-input-number
              v-if="form.commissionset === 2"
              v-model="form.commission2"
              :min="0"
              :precision="2"
              style="width: 160px; margin-top: 8px"
            />
            <span v-if="form.commissionset === 2" class="ge-hint">一级提成金额(元)</span>
            <el-input-number
              v-if="form.commissionset === 3"
              v-model="form.commission3"
              :min="0"
              :precision="0"
              style="width: 160px; margin-top: 8px"
            />
            <span v-if="form.commissionset === 3" class="ge-hint">提成积分</span>
          </el-form-item>

          <el-form-item label="销量">
            <el-input-number v-model="form.sales" :min="0" :precision="0" :step="1" />
          </el-form-item>

          <el-form-item label="序号">
            <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
            <span class="ge-hint">用于排序,越大越靠前</span>
          </el-form-item>

          <el-form-item label="状态">
            <el-radio-group v-model="form.status">
              <el-radio :value="1">已上架</el-radio>
              <el-radio :value="0">未上架</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label=" ">
            <el-button type="primary" @click="goDetail">下一步-编辑商品详情</el-button>
            <el-button type="success" :loading="submitting" @click="handleSubmit">提交</el-button>
          </el-form-item>
        </el-tab-pane>

        <!-- ===== tab2 商品详情（DIY 装修器） ===== -->
        <el-tab-pane label="商品详情" name="detail">
          <GoodsDetailEditor v-model="form.detailModules" title="商品详情" />
          <el-form-item label=" " class="tab-detail-footer">
            <el-button type="primary" @click="activeTab = 'basic'">上一步-编辑商品信息</el-button>
            <el-button type="success" :loading="submitting" @click="handleSubmit">提交</el-button>
          </el-form-item>
        </el-tab-pane>
      </el-tabs>
    </el-form>

    <ImgIconPicker v-model:visible="picPickerVisible" default-tab="upload" @pick="onPickPic" />
    <ImgIconPicker v-model:visible="picsPickerVisible" default-tab="upload" multi @pick="onPickPics" />
    <ImgIconPicker v-model:visible="skuPicPickerVisible" default-tab="upload" @pick="onPickSkuPic" />

    <!-- 选择商城商品弹窗 -->
    <el-dialog v-model="chooseVisible" title="选择商城商品" width="760px">
      <el-form inline @submit.prevent style="margin-bottom: 10px">
        <el-form-item label="商品名称">
          <el-input v-model="chooseKeyword" placeholder="商品名称" clearable style="width: 200px" @keyup.enter="handleChooseSearch" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleChooseSearch">搜索</el-button>
        </el-form-item>
      </el-form>
      <el-table v-loading="chooseLoading" :data="chooseList" border size="small">
        <el-table-column label="商品" min-width="280">
          <template #default="{ row }">
            <div style="display: flex; align-items: center; gap: 8px">
              <el-image v-if="row.image" :src="row.image" fit="cover" style="width: 40px; height: 40px; border-radius: 4px" />
              <span>{{ row.name }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="商城价" width="110">
          <template #default="{ row }">￥{{ Number(row.price).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90" align="center">
          <template #default="{ row }">
            <el-button link type="primary" @click="pickGoods(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        style="margin-top: 10px; justify-content: flex-end"
        background
        layout="prev, pager, next"
        :total="chooseTotal"
        :current-page="choosePage"
        :page-size="10"
        @current-change="(p: number) => { choosePage = p; fetchChoose() }"
      />
    </el-dialog>

    <!-- 卡密信息弹窗 -->
    <el-dialog v-model="codeVisible" :title="`卡密信息 - ${codeProname}`" width="720px">
      <div class="code-toolbar">
        <el-select v-model="codeQuery.status" placeholder="全部状态" clearable size="small" style="width: 120px" @change="codeQuery.page = 1; fetchCodeList()">
          <el-option label="未售出" :value="0" />
          <el-option label="已售出" :value="1" />
        </el-select>
      </div>
      <el-table v-loading="codeLoading" :data="codeList" border size="small" max-height="300">
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="content" label="卡密内容" min-width="200" show-overflow-tooltip />
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'status-on' : 'cell-muted'">{{ row.status === 1 ? '已售出' : '未售出' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="购买会员" width="100">
          <template #default="{ row }">{{ row.nickname || '-' }}</template>
        </el-table-column>
        <el-table-column label="售出时间" width="150" align="center">
          <template #default="{ row }">{{ row.buytime ? formatTime(row.buytime) : '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="70" align="center">
          <template #default="{ row }">
            <el-button v-if="row.status === 0" link type="danger" @click="handleCodeDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pagination"
        small
        background
        layout="total, prev, pager, next"
        :total="codeTotal"
        :current-page="codeQuery.page"
        :page-size="codeQuery.pagesize"
        @current-change="(p: number) => { codeQuery.page = p; fetchCodeList() }"
      />
      <el-divider content-position="left">批量导入卡密</el-divider>
      <el-input
        v-model="codeContent"
        type="textarea"
        :rows="4"
        placeholder="每行一条卡密，重复的卡密自动跳过"
      />
      <div style="margin-top: 8px; text-align: right">
        <el-button type="primary" size="small" :loading="codeSubmitting" @click="submitCodeImport">导入卡密</el-button>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
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
.goods-info-cname { color: #f56c6c; margin-right: 4px; }
.goods-info-price { color: #ff6600; font-weight: 600; font-size: 14px; margin-top: 6px; }
.status-on { color: #21ba45; }
.status-off { color: #f56c6c; }
.check-on { color: #21ba45; }
.check-off { color: #f56c6c; }
.check-wait { color: #409eff; }
.cell-muted { color: #909399; }
.code-toolbar { display: flex; justify-content: flex-end; margin-bottom: 8px; }
.row-ops { display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; gap: 2px 6px; line-height: 1.5; }
.row-ops .el-button { margin: 0; height: auto; min-height: 0; padding: 0; font-size: 12px; }
.row-ops :deep(.el-button) { height: auto; padding: 1px 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 3px; justify-content: flex-end; }

/* ===== 整页编辑 ===== */
.goods-edit { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.ge-head-title { font-size: 16px; font-weight: 600; color: #303133; }
.ge-close { border: none; background: transparent; color: #909399; font-size: 18px; font-weight: bold; }
.ge-close:hover { color: #303133; }
.ge-form :deep(.el-form-item) { margin-bottom: 15px; }
.ge-hint { color: #909399; font-size: 12px; line-height: 1.5; margin-left: 10px; }
.ge-block-hint { color: #909399; font-size: 12px; line-height: 1.7; margin-top: 6px; }
.ge-pic-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.main-pic, .pic-thumb { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }
.ge-thumb-item { position: relative; }
.ge-upload-del { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; line-height: 16px; text-align: center; background: #909399; color: #fff; border-radius: 50%; font-size: 12px; cursor: pointer; z-index: 2; }

.spec-wrap { width: 100%; }
.gg-table { border-collapse: collapse; width: 600px; }
.gg-table th, .gg-table td { border: 1px solid #e4e7ed; padding: 6px 8px; font-size: 12px; text-align: left; }
.gg-table th { background: #f5f7fa; }
.gg-name-line { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.gg-name-line:last-child { margin-bottom: 0; }
.sku-table { border-collapse: collapse; width: 100%; margin-top: 16px; }
.sku-table th, .sku-table td { border: 1px solid #e4e7ed; padding: 6px 8px; font-size: 12px; }
.sku-table th { background: #f5f7fa; text-align: center; }
.sku-spec-cell { color: #303133; white-space: nowrap; }
.sku-pic { width: 40px; height: 40px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; cursor: pointer; }
.sku-pic-empty { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border: 1px dashed #c0c4cc; border-radius: 4px; color: #909399; font-size: 12px; cursor: pointer; }
.tab-detail-footer { margin-top: 16px; }
</style>
