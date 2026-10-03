<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { areaProvinces } from '../../utils/areaData'
import {
  getScoreshopProductList,
  getScoreshopProductDetail,
  saveScoreshopProduct,
  deleteScoreshopProduct,
  setScoreshopProductStatus,
  batchDeleteScoreshopProducts,
  batchSetScoreshopProductStatus,
  getScoreshopCategoryList,
  saveScoreshopCategory,
  deleteScoreshopCategory,
  getScoreshopOrderList,
  closeScoreshopOrder,
  sendScoreshopOrderExpress,
  hexiaoScoreshopOrder,
  refundScoreshopOrder,
  deliverScoreshopOrder,
  ispayScoreshopOrder,
  deleteScoreshopOrder,
  getScoreshopExpressOptions,
  getScoreshopOrderTrace,
  getScoreshopShd,
  batchSendScoreshopOrder,
  getScoreshopAgentLevels,
  getScoreshopDeliveryStaff,
  ewaybillPrepareScoreshop,
  ewaybillAddScoreshop,
  getScoreshopSet,
  saveScoreshopSet,
  exportScoreshopOrders,
  type ScoreshopProductItem,
  type ScoreshopCategoryItem,
  type ScoreshopOrderRow,
} from '../../api/scoreshop'
import { getGoodsOptions } from '../../api/cuxiao'
import type { GoodsOption } from '../../api/cuxiao'
import { getFreightList } from '../../api/tenantSet'
import type { DesignerModule } from '../../api/designer'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'

const props = defineProps<{ initialTab?: string }>()
const VALID_TABS = ['product', 'order', 'category', 'set']
const activeTab = ref(VALID_TABS.includes(props.initialTab || '') ? props.initialTab! : 'product')

/** 商品无图时的默认占位图 */
const defaultGoodsImg = '/imgsrc/default_goods.png'
function goodsImg(pic?: string) { return pic || defaultGoodsImg }

/* ================= 选图 ================= */
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

/* ================= 商品管理列表 ================= */
const pLoading = ref(false)
const pList = ref<ScoreshopProductItem[]>([])
const pTotal = ref(0)
const pSelected = ref<ScoreshopProductItem[]>([])
const pQuery = reactive({ page: 1, pagesize: 10, keyword: '', status: '' as number | '', cid: '' as number | '' })
const categories = ref<ScoreshopCategoryItem[]>([])

async function fetchCategories() {
  try { categories.value = (await getScoreshopCategoryList()).list || [] } catch { categories.value = [] }
}

async function fetchProductList() {
  pLoading.value = true
  try {
    const res = await getScoreshopProductList({
      page: pQuery.page, pagesize: pQuery.pagesize,
      keyword: pQuery.keyword || undefined,
      status: pQuery.status === '' ? undefined : pQuery.status,
      cid: pQuery.cid === '' ? undefined : pQuery.cid,
    })
    pList.value = res.list || []
    pTotal.value = res.total || 0
  } finally { pLoading.value = false }
}
function reloadProducts() { pQuery.page = 1; fetchProductList() }

async function onProductDelete(row: ScoreshopProductItem) {
  await ElMessageBox.confirm('确定删除该商品？', '提示', { type: 'warning' })
  await deleteScoreshopProduct(row.id)
  ElMessage.success('删除成功')
  fetchProductList()
}
async function onProductToggle(row: ScoreshopProductItem) {
  await setScoreshopProductStatus(row.id, row.status ? 0 : 1)
  ElMessage.success(row.status ? '已下架' : '已上架')
  fetchProductList()
}
async function onBatchDelete() {
  if (!pSelected.value.length) { ElMessage.warning('请先勾选商品'); return }
  await ElMessageBox.confirm(`确定删除选中的 ${pSelected.value.length} 个商品？`, '提示', { type: 'warning' })
  await batchDeleteScoreshopProducts(pSelected.value.map((r) => r.id))
  ElMessage.success('批量删除完成')
  fetchProductList()
}
async function onBatchStatus(status: number) {
  if (!pSelected.value.length) { ElMessage.warning('请先勾选商品'); return }
  await batchSetScoreshopProductStatus(pSelected.value.map((r) => r.id), status)
  ElMessage.success(status ? '已批量上架' : '已批量下架')
  fetchProductList()
}

/* ================= 商品编辑（整页） ================= */
const editorVisible = ref(false)
const editorIsEdit = ref(false)
const editorTab = ref<'info' | 'detail'>('info')
const saving = ref(false)

interface GuigeRow {
  id?: number
  name: string
  pic: string
  market_price: number
  cost_price: number
  score_price: number
  money_price: number
  weight: number
  stock: number
}

const form = reactive({
  id: 0, cid: '' as number | '', name: '', pic: '', pics: [] as string[], detail: '',
  sell_price: 0, cost_price: 0, score_price: 0, money_price: 0,
  buymax: 0, everyday_buymax: 0, lvprice: 0, weight: 0, sales: 0, sort: 0, status: 1,
  freighttype: 1, freightdata: '', freightcontent: '', contact_require: 1,
  commissionset: 0,
  commissiondata1: {} as Record<string, Record<string, string>>,
  commissiondata2: {} as Record<string, Record<string, string>>,
  commissiondata3: {} as Record<string, Record<string, string>>,
  commissiondata5: {} as Record<string, Record<string, { money: string; score: string }>>,
  showtj: ['-1'] as string[], gettj: ['-1'] as string[],
  guige: [] as GuigeRow[],
})

const detailModules = ref<DesignerModule[]>([])
const chosenFreight = ref<number[]>([])
const freightList = ref<{ id: number; name: string }[]>([])
const agentLevels = ref<{ id: number; name: string; can_agent: number }[]>([])

const goodsPickerVisible = ref(false)
const goodsOptions = ref<GoodsOption[]>([])
async function openGoodsPicker() {
  goodsPickerVisible.value = true
  try { goodsOptions.value = (await getGoodsOptions('')).list || [] } catch { /* */ }
}
function pickGoods(g: GoodsOption) {
  form.name = g.name
  form.sell_price = Number(g.sell_price) || 0
  goodsPickerVisible.value = false
}

function closeEditor() { editorVisible.value = false; fetchProductList() }

function resetForm() {
  Object.assign(form, {
    id: 0, cid: '', name: '', pic: '', pics: [], detail: '',
    sell_price: 0, cost_price: 0, score_price: 0, money_price: 0,
    buymax: 0, everyday_buymax: 0, lvprice: 0, weight: 0, sales: 0, sort: 0, status: 1,
    freighttype: 1, freightdata: '', freightcontent: '', contact_require: 1,
    commissionset: 0,
    commissiondata1: {}, commissiondata2: {}, commissiondata3: {}, commissiondata5: {},
    showtj: ['-1'], gettj: ['-1'],
    guige: [{ id: undefined, name: '默认规格', pic: '', market_price: 0, cost_price: 0, score_price: 0, money_price: 0, weight: 0, stock: 100 }],
  })
  detailModules.value = []
  chosenFreight.value = []
}

async function loadEditorAux() {
  try { agentLevels.value = await getScoreshopAgentLevels() } catch { agentLevels.value = [] }
  try {
    const fl: any = await getFreightList()
    freightList.value = Array.isArray(fl) ? fl : (Array.isArray(fl?.list) ? fl.list : [])
  } catch { freightList.value = [] }
  agentLevels.value.forEach((lv) => {
    if (!form.commissiondata1[lv.id]) form.commissiondata1[lv.id] = { commission1: '', commission2: '' }
    if (!form.commissiondata2[lv.id]) form.commissiondata2[lv.id] = { commission1: '', commission2: '' }
    if (!form.commissiondata3[lv.id]) form.commissiondata3[lv.id] = { commission1: '', commission2: '' }
    if (!form.commissiondata5[lv.id]) form.commissiondata5[lv.id] = { commission1: { money: '', score: '' }, commission2: { money: '', score: '' } }
  })
}

function toggleFreight(id: number) {
  const i = chosenFreight.value.indexOf(id)
  if (i >= 0) chosenFreight.value.splice(i, 1); else chosenFreight.value.push(id)
}

function onShowtjChange(val: string[]) { if (val.includes('-1')) form.showtj = ['-1'] }
function onGettjChange(val: string[]) { if (val.includes('-1')) form.gettj = ['-1'] }

async function openAddProduct() {
  resetForm()
  editorIsEdit.value = false
  editorTab.value = 'info'
  editorVisible.value = true
  await loadEditorAux()
}

async function openEditProduct(row: ScoreshopProductItem) {
  editorIsEdit.value = true
  editorTab.value = 'info'
  editorVisible.value = true
  try {
    const d = await getScoreshopProductDetail(row.id)
    Object.assign(form, {
      id: d.id, cid: d.cid || '', name: d.name, pic: d.pic || '',
      pics: d.pics ? d.pics.split(',').filter(Boolean) : [],
      detail: d.detail || '',
      sell_price: d.sell_price || 0, cost_price: d.cost_price || 0,
      score_price: d.score_price || 0, money_price: d.money_price || 0,
      buymax: d.buymax || 0, everyday_buymax: d.everyday_buymax || 0,
      lvprice: d.lvprice || 0, weight: d.weight || 0, sales: d.sales || 0,
      sort: d.sort || 0, status: d.status || 0,
      freighttype: d.freighttype ?? 1, freightdata: d.freightdata || '',
      freightcontent: d.freightcontent || '', contact_require: d.contact_require || 0,
      commissionset: d.commissionset ?? 0,
      commissiondata1: d.commissiondata1 && typeof d.commissiondata1 === 'object' ? d.commissiondata1 : {},
      commissiondata2: d.commissiondata2 && typeof d.commissiondata2 === 'object' ? d.commissiondata2 : {},
      commissiondata3: d.commissiondata3 && typeof d.commissiondata3 === 'object' ? d.commissiondata3 : {},
      commissiondata5: d.commissiondata5 && typeof d.commissiondata5 === 'object' ? d.commissiondata5 : {},
      showtj: Array.isArray(d.showtj) ? [...d.showtj] : ['-1'],
      gettj: Array.isArray(d.gettj) ? [...d.gettj] : ['-1'],
      guige: Array.isArray(d.guige) && d.guige.length ? d.guige.map((g: any) => ({
        id: g.id, name: g.name || '默认规格', pic: g.pic || '',
        market_price: g.market_price || 0, cost_price: g.cost_price || 0,
        score_price: g.score_price || 0, money_price: g.money_price || 0,
        weight: g.weight || 0, stock: g.stock || 0,
      })) : [{ id: undefined, name: '默认规格', pic: '', market_price: 0, cost_price: 0, score_price: 0, money_price: 0, weight: 0, stock: 100 }],
    })
    chosenFreight.value = form.freightdata ? form.freightdata.split(',').filter(Boolean).map(Number) : []
    try {
      const parsed = JSON.parse(d.detail || '[]')
      detailModules.value = Array.isArray(parsed) ? parsed : []
    } catch { detailModules.value = [] }
    await loadEditorAux()
  } catch { /* */ }
}

async function submitProduct() {
  if (!form.name) { ElMessage.warning('请填写商品名称'); return }
  if (!form.cid) { ElMessage.warning('请选择商品分类'); return }
  saving.value = true
  try {
    await saveScoreshopProduct({
      id: form.id || undefined,
      cid: Number(form.cid),
      name: form.name,
      pic: form.pic,
      pics: form.pics.join(','),
      detail: JSON.stringify(detailModules.value || []),
      sell_price: Number(form.sell_price) || 0,
      cost_price: Number(form.cost_price) || 0,
      score_price: Number(form.score_price) || 0,
      money_price: Number(form.money_price) || 0,
      buymax: Number(form.buymax) || 0,
      everyday_buymax: Number(form.everyday_buymax) || 0,
      lvprice: form.lvprice,
      weight: Number(form.weight) || 0,
      sales: Number(form.sales) || 0,
      sort: Number(form.sort) || 0,
      status: form.status,
      freighttype: form.freighttype,
      freightdata: chosenFreight.value.join(','),
      freightcontent: form.freightcontent,
      contact_require: form.contact_require,
      commissionset: form.commissionset,
      commissiondata1: form.commissionset === 1 ? form.commissiondata1 : undefined,
      commissiondata2: form.commissionset === 2 ? form.commissiondata2 : undefined,
      commissiondata3: form.commissionset === 3 ? form.commissiondata3 : undefined,
      commissiondata5: form.commissionset === 5 ? form.commissiondata5 : undefined,
      showtj: form.showtj,
      gettj: form.gettj,
      guige: form.guige.map((g) => ({
        id: g.id, name: g.name, pic: g.pic,
        market_price: Number(g.market_price) || 0, cost_price: Number(g.cost_price) || 0,
        score_price: Number(g.score_price) || 0, money_price: Number(g.money_price) || 0,
        weight: Number(g.weight) || 0, stock: Number(g.stock) || 0,
      })),
    } as any)
    ElMessage.success('保存成功')
    editorVisible.value = false
    fetchProductList()
  } finally { saving.value = false }
}

/* ================= 订单管理 ================= */
const oLoading = ref(false)
const oList = ref<ScoreshopOrderRow[]>([])
const oTotal = ref(0)
const oQuery = reactive({ page: 1, pagesize: 10, ordernum: '', linkman: '', tel: '', status: '' as number | '', ctimeRange: [] as string[] })

async function fetchOrderList() {
  oLoading.value = true
  try {
    const [st, et] = oQuery.ctimeRange || []
    const res = await getScoreshopOrderList({
      page: oQuery.page, pagesize: oQuery.pagesize,
      status: oQuery.status === '' ? undefined : oQuery.status,
      ordernum: oQuery.ordernum || undefined,
      linkman: oQuery.linkman || undefined,
      tel: oQuery.tel || undefined,
      start_date: st || undefined, end_date: et || undefined,
    })
    oList.value = res.list || []
    oTotal.value = res.total || 0
  } finally { oLoading.value = false }
}
function reloadOrders() { oQuery.page = 1; fetchOrderList() }

function fmtTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

async function doExport() {
  const [st, et] = oQuery.ctimeRange || []
  const blob: any = await exportScoreshopOrders({
    status: oQuery.status === '' ? '' : oQuery.status,
    ordernum: oQuery.ordernum || undefined, linkman: oQuery.linkman || undefined, tel: oQuery.tel || undefined,
    start_date: st || undefined, end_date: et || undefined,
  })
  const url = URL.createObjectURL(blob instanceof Blob ? blob : new Blob([blob as BlobPart]))
  const a = document.createElement('a')
  a.href = url; a.download = `积分兑换订单_${Date.now()}.xlsx`; a.click()
  URL.revokeObjectURL(url)
}

const sendWayVisible = ref(false)
const currentOrder = ref<ScoreshopOrderRow | null>(null)
function openSendWay(row: ScoreshopOrderRow) { currentOrder.value = row; sendWayVisible.value = true }

const manualVisible = ref(false)
const expressOptions = ref<{ code: string; name: string }[]>([])
const manualForm = ref({ express_com: 'EMS', express_no: '' })
function useManual() {
  sendWayVisible.value = false
  manualForm.value = { express_com: 'EMS', express_no: '' }
  manualVisible.value = true
}
async function submitManual() {
  if (!manualForm.value.express_no) { ElMessage.warning('请输入快递单号'); return }
  await sendScoreshopOrderExpress(currentOrder.value!.id, manualForm.value.express_com, manualForm.value.express_no)
  ElMessage.success('发货成功')
  manualVisible.value = false
  fetchOrderList()
}

/* ================= 电子面单弹窗（对标已验收 CollageOrder 标准实现） ================= */
interface EwbParty {
  name: string
  mobile: string
  province: string
  city: string
  area: string
  address: string
  tel: string
  company: string
  post_code: string
}
const emptyParty = (): EwbParty => ({
  name: '', mobile: '', province: '', city: '', area: '',
  address: '', tel: '', company: '', post_code: '',
})

const ewbVisible = ref(false)
const ewbLoading = ref(false)
const ewbSubmitting = ref(false)
const ewbForm = reactive<Record<string, any>>({
  ordercode: '',
  ordernum: '',
  delivery_info: '',
  is_notice: 0,
  quantity: 1,
  weight: 1,
  volume: 0.001,
  insure: 0,
  insure_value: '',
  warm_layer: '',
  remark: '',
  expect_start_time: '',
  expect_end_time: '',
  receiver: emptyParty(),
  sender: emptyParty(),
})
const ewbExpressOptions = ref<{ code: string; name: string; iswarm: number }[]>([])
const ewbWarmLayer = ref<Record<string, string>>({})

function ewbCityList(p: EwbParty) {
  const prov = areaProvinces.find((x) => x.name === p.province)
  return prov ? prov.cityList : []
}
function ewbAreaList(p: EwbParty) {
  const prov = areaProvinces.find((x) => x.name === p.province)
  const city = prov?.cityList.find((c) => c.name === p.city)
  return city ? city.areaList : []
}
function onEwbProvince(p: EwbParty) { p.city = ''; p.area = '' }
function onEwbCity(p: EwbParty) { p.area = '' }
const ewbSelectedWarm = computed(() => {
  const it = ewbExpressOptions.value.find((o) => o.code === ewbForm.delivery_info)
  return it ? Number(it.iswarm) === 1 : false
})

const ewbNorm = (s: string) =>
  s.replace(/省|市|区|县|自治区|自治州|地区|盟|壮族|回族|维吾尔/g, '')
function fillPartyFromRow(p: EwbParty, row: Record<string, any>) {
  p.name = row.linkman || ''
  p.mobile = row.tel || ''
  p.address = row.address || ''
  const text = String(row.area || '').trim()
  if (!text) return
  // 兼容「河南省南阳市」省市区连写与「河南省 南阳市 宛城区」带分隔符两种数据形态
  const hay = text
  const normHay = ewbNorm(hay)
  for (const prov of areaProvinces) {
    const hitP = hay.includes(prov.name) || normHay.includes(ewbNorm(prov.name))
    if (!hitP) continue
    p.province = prov.name
    for (const c of prov.cityList) {
      const hitC = hay.includes(c.name) || normHay.includes(ewbNorm(c.name))
      if (!hitC) continue
      p.city = c.name
      for (const a of c.areaList) {
        if (hay.includes(a) || normHay.includes(ewbNorm(a))) { p.area = a; break }
      }
      break
    }
    break
  }
}

async function useEwaybill() {
  const row = currentOrder.value!
  sendWayVisible.value = false
  ewbVisible.value = true
  ewbLoading.value = true
  Object.assign(ewbForm, {
    ordercode: '',
    ordernum: row.ordernum || '',
    delivery_info: '',
    is_notice: 0,
    quantity: row.totalnum || 1,
    weight: 1,
    volume: 0.001,
    insure: 0,
    insure_value: '',
    warm_layer: '',
    remark: `${row.goods?.[0]?.name || row.title || '商品'} x${row.totalnum || 1}`,
    expect_start_time: '',
    expect_end_time: '',
    receiver: emptyParty(),
    sender: emptyParty(),
  })
  fillPartyFromRow(ewbForm.receiver, row as unknown as Record<string, any>)
  try {
    const p = await ewaybillPrepareScoreshop(row.id)
    ewbForm.ordercode = (p.ordercode as string) || ''
    ewbExpressOptions.value = (p.express_options as any[]) || []
    ewbWarmLayer.value = ((p as any).warm_layer as Record<string, string>) || {}
    const last = (p.last_order as any) || {}
    ewbForm.sender = {
      name: last.sender_name || '',
      mobile: last.sender_mobile || '',
      province: last.sender_province || '',
      city: last.sender_city || '',
      area: last.sender_area || '',
      address: last.sender_address || '',
      tel: last.sender_tel || '',
      company: last.sender_company || '',
      post_code: last.sender_post_code || '',
    }
    if (!ewbExpressOptions.value.length) {
      ElMessage.warning('未配置电子面单快递公司，请先在「扩展-电子面单」中绑定快递公司账号')
    }
  } catch (e) {
    ElMessage.error((e as Error)?.message || '电子面单初始化失败')
  } finally {
    ewbLoading.value = false
  }
}

async function submitEwaybill() {
  const row = currentOrder.value!
  if (!ewbForm.delivery_info) { ElMessage.warning('请选择快递公司'); return }
  const rc = ewbForm.receiver as EwbParty
  const sd = ewbForm.sender as EwbParty
  ewbSubmitting.value = true
  try {
    await ewaybillAddScoreshop({
      ordercode: ewbForm.ordercode,
      ordernum: row.ordernum,
      orderid: row.id,
      delivery_info: ewbForm.delivery_info,
      is_notice: ewbForm.is_notice,
      expect_start_time: ewbForm.expect_start_time || '',
      expect_end_time: ewbForm.expect_end_time || '',
      quantity: ewbForm.quantity,
      weight: ewbForm.weight,
      volume: ewbForm.volume,
      insure: ewbForm.insure,
      insure_value: ewbForm.insure_value || 0,
      warm_layer: ewbForm.warm_layer,
      remark: ewbForm.remark,
      receiver_name: rc.name,
      receiver_mobile: rc.mobile,
      receiver_province: rc.province,
      receiver_city: rc.city,
      receiver_area: rc.area,
      receiver_address: rc.address,
      receiver_tel: rc.tel,
      receiver_company: rc.company,
      receiver_post_code: rc.post_code,
      sender_name: sd.name,
      sender_mobile: sd.mobile,
      sender_province: sd.province,
      sender_city: sd.city,
      sender_area: sd.area,
      sender_address: sd.address,
      sender_tel: sd.tel,
      sender_company: sd.company,
      sender_post_code: sd.post_code,
    })
    ElMessage.success('电子面单发货成功')
    ewbVisible.value = false
    fetchOrderList()
  } finally {
    ewbSubmitting.value = false
  }
}

const batchVisible = ref(false)
const batchForm = ref({ express_com: 'EMS', fileName: '', file: null as File | null })
const batchFileInput = ref<HTMLInputElement | null>(null)
function openBatch() { batchForm.value = { express_com: 'EMS', fileName: '', file: null }; batchVisible.value = true }
function pickBatchFile() { batchFileInput.value?.click() }
function onBatchFile(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (f) { batchForm.value.file = f; batchForm.value.fileName = f.name }
}
async function submitBatch() {
  if (!batchForm.value.file) { ElMessage.warning('请先上传EXCEL文件'); return }
  await batchSendScoreshopOrder(batchForm.value.express_com, batchForm.value.file!)
  ElMessage.success('批量发货完成')
  batchVisible.value = false
  fetchOrderList()
}

async function doHexiao(row: ScoreshopOrderRow) {
  await ElMessageBox.confirm('确定要核销并改为已完成状态吗？', '核销确认', { type: 'warning' })
  await hexiaoScoreshopOrder(row.id)
  ElMessage.success('核销成功')
  fetchOrderList()
}

const refundVisible = ref(false)
const refundForm = ref({ reason: '' })
function openRefund(row: ScoreshopOrderRow) { currentOrder.value = row; refundForm.value = { reason: '' }; refundVisible.value = true }
async function submitRefund() {
  await refundScoreshopOrder(currentOrder.value!.id, refundForm.value.reason)
  ElMessage.success('退款成功')
  refundVisible.value = false
  fetchOrderList()
}

const deliverVisible = ref(false)
const staffList = ref<{ id: number; name: string; mobile: string; doing: number }[]>([])
const deliverForm = ref({ mdid: 0 })
async function openDeliver(row: ScoreshopOrderRow) {
  currentOrder.value = row
  deliverForm.value = { mdid: 0 }
  try { staffList.value = await getScoreshopDeliveryStaff() } catch { staffList.value = [] }
  deliverVisible.value = true
}
async function submitDeliver() {
  if (!deliverForm.value.mdid) { ElMessage.warning('请选择配送员'); return }
  await deliverScoreshopOrder(currentOrder.value!.id, deliverForm.value.mdid)
  ElMessage.success('配送成功')
  deliverVisible.value = false
  fetchOrderList()
}

async function doClose(row: ScoreshopOrderRow) {
  await ElMessageBox.confirm('确定关闭该订单？', '提示', { type: 'warning' })
  await closeScoreshopOrder(row.id)
  ElMessage.success('订单已关闭')
  fetchOrderList()
}
async function doIspay(row: ScoreshopOrderRow) {
  await ElMessageBox.confirm('确定将该订单改为已支付？', '提示', { type: 'warning' })
  await ispayScoreshopOrder(row.id)
  ElMessage.success('操作成功')
  fetchOrderList()
}
async function doOrderDelete(row: ScoreshopOrderRow) {
  await ElMessageBox.confirm('确定删除该订单？', '提示', { type: 'warning' })
  await deleteScoreshopOrder(row.id)
  ElMessage.success('删除成功')
  fetchOrderList()
}

const detailVisible = ref(false)
const detail = ref<ScoreshopOrderRow | null>(null)
function openDetail(row: ScoreshopOrderRow) { detail.value = row; detailVisible.value = true }

const traceVisible = ref(false)
const trace = ref<{ express_com: string; express_no: string; traces: { time: string; station: string }[] }>({ express_com: '', express_no: '', traces: [] })
async function openTrace(row: ScoreshopOrderRow) {
  try {
    trace.value = await getScoreshopOrderTrace(row.id)
    traceVisible.value = true
  } catch { /* */ }
}

// ============ 送货单（整页打印） ============
const shdVisible = ref(false)
const shdLoading = ref(false)
const shdData = ref<Record<string, any> | null>(null)
async function openShd(row: ScoreshopOrderRow) {
  shdVisible.value = true
  shdLoading.value = true
  shdData.value = null
  try {
    shdData.value = await getScoreshopShd(row.id)
  } catch { /* */ } finally { shdLoading.value = false }
}
function shdBack() { shdVisible.value = false }
function shdPrint() { window.print() }

/* ================= 商品分类 ================= */
const cLoading = ref(false)
const cList = ref<ScoreshopCategoryItem[]>([])
async function fetchCategoryList() {
  cLoading.value = true
  try { cList.value = (await getScoreshopCategoryList()).list || [] } finally { cLoading.value = false }
}
const catDialog = ref(false)
const catForm = reactive({ id: 0, name: '', pic: '', sort: 0, status: 1 })
function openAddCategory() { Object.assign(catForm, { id: 0, name: '', pic: '', sort: 0, status: 1 }); catDialog.value = true }
function openEditCategory(row: ScoreshopCategoryItem) { Object.assign(catForm, { id: row.id, name: row.name, pic: row.pic, sort: row.sort, status: row.status }); catDialog.value = true }
async function submitCategory() {
  if (!catForm.name) { ElMessage.warning('请填写分类名称'); return }
  await saveScoreshopCategory({ id: catForm.id || undefined, name: catForm.name, pic: catForm.pic, sort: catForm.sort, status: catForm.status })
  ElMessage.success('保存成功')
  catDialog.value = false
  fetchCategoryList()
}
async function deleteCategory(row: ScoreshopCategoryItem) {
  await ElMessageBox.confirm('确定删除该分类？', '提示', { type: 'warning' })
  await deleteScoreshopCategory(row.id)
  ElMessage.success('删除成功')
  fetchCategoryList()
}

/* ================= 系统设置 ================= */
const setForm = reactive({
  autoshdays: 1, showjd: 1, autoclose: 30,
  comment: 1, comment_check: 0, gettj: ['-1'] as string[], showcommission: 0,
})
async function fetchSet() {
  try {
    const s = await getScoreshopSet()
    Object.assign(setForm, {
      autoshdays: s.autoshdays || 1, showjd: s.showjd ?? 1, autoclose: s.autoclose || 30,
      comment: s.comment ?? 1, comment_check: s.comment_check || 0, showcommission: s.showcommission || 0,
    })
    setForm.gettj = s.gettj ? s.gettj.split(',').filter(Boolean) : ['-1']
  } catch { /* */ }
}
function onSetGettjChange(val: string[]) { if (val.includes('-1')) setForm.gettj = ['-1'] }
async function saveSet() {
  await saveScoreshopSet({
    autoshdays: Number(setForm.autoshdays) || 1,
    showjd: setForm.showjd, autoclose: Number(setForm.autoclose) || 30,
    comment: setForm.comment, comment_check: setForm.comment_check,
    gettj: setForm.gettj.join(','), showcommission: setForm.showcommission,
  })
  ElMessage.success('保存成功')
}

function handleTabChange(tab: string | number) {
  if (tab === 'category') fetchCategoryList()
  if (tab === 'product') { fetchCategories(); fetchProductList() }
  if (tab === 'order') fetchOrderList()
}

onMounted(async () => {
  await fetchSet()
  try { expressOptions.value = await getScoreshopExpressOptions() } catch { expressOptions.value = [] }
  handleTabChange(activeTab.value)
})
</script>

<template>
  <div class="scoreshop-page">
    <!-- 顶部横向 tab 条已删除：左侧树菜单已负责导航，按 initialTab 只渲染对应视图 -->

    <!-- ============ 商品管理 ============ -->
    <div v-show="activeTab === 'product' && !editorVisible" class="pane">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button type="primary" size="small" @click="openAddProduct">添加</el-button>
          <el-button size="small" @click="onBatchDelete">删除</el-button>
          <el-button size="small" @click="onBatchStatus(1)">上架</el-button>
          <el-button size="small" @click="onBatchStatus(0)">下架</el-button>
        </div>
        <div class="toolbar-right">
          <el-input v-model="pQuery.keyword" placeholder="商品名称" clearable size="small" @keyup.enter="reloadProducts" />
          <el-select v-model="pQuery.cid" placeholder="分类" size="small" clearable style="width:110px">
            <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
          <el-select v-model="pQuery.status" placeholder="状态" size="small" clearable style="width:100px">
            <el-option label="全部" value="" />
            <el-option label="已上架" :value="1" />
            <el-option label="未上架" :value="0" />
          </el-select>
          <el-button type="primary" size="small" @click="reloadProducts">搜索</el-button>
        </div>
      </div>

      <el-table v-loading="pLoading" :data="pList" border size="small" @selection-change="(r: ScoreshopProductItem[]) => (pSelected = r)">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="商品信息" min-width="240">
          <template #default="{ row }">
            <div class="goods-cell">
              <el-image :src="goodsImg(row.pic)" fit="cover" class="goods-img" :preview-src-list="[goodsImg(row.pic)]" />
              <div>
                <div class="goods-name">{{ row.name }}</div>
                <div class="goods-sub">价值 ¥{{ row.sell_price }} | {{ row.score_price }}积分 + ¥{{ row.money_price }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="stock" label="库存" width="80" />
        <el-table-column prop="sales" label="销量" width="80" />
        <el-table-column prop="sort" label="序号" width="70" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag size="small" :type="row.status ? 'success' : 'info'">{{ row.status ? '已上架' : '未上架' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200">
          <template #default="{ row }">
            <div class="ops">
              <el-button link type="primary" size="small" @click="openEditProduct(row)">编辑</el-button>
              <el-button link :type="row.status ? 'warning' : 'success'" size="small" @click="onProductToggle(row)">{{ row.status ? '下架' : '上架' }}</el-button>
              <el-button link type="danger" size="small" @click="onProductDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination class="pager" :current-page="pQuery.page" :page-size="pQuery.pagesize" :total="pTotal" layout="total, prev, pager, next"
        @current-change="(p: number) => { pQuery.page = p; fetchProductList() }" />
    </div>

    <!-- ============ 订单管理 ============ -->
    <div v-show="activeTab === 'order' && !shdVisible" class="pane">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button size="small" @click="doExport">导出</el-button>
          <el-button type="primary" size="small" @click="openBatch">批量发货</el-button>
        </div>
        <div class="toolbar-right">
          <el-input v-model="oQuery.ordernum" placeholder="订单号" clearable size="small" @keyup.enter="reloadOrders" />
          <el-input v-model="oQuery.linkman" placeholder="收货人" clearable size="small" @keyup.enter="reloadOrders" />
          <el-input v-model="oQuery.tel" placeholder="手机号" clearable size="small" @keyup.enter="reloadOrders" />
          <el-date-picker v-model="oQuery.ctimeRange" type="daterange" size="small" range-separator="-" start-placeholder="开始日期" end-placeholder="结束日期" value-format="YYYY-MM-DD" style="width:240px" />
          <el-select v-model="oQuery.status" placeholder="状态" size="small" clearable style="width:110px">
            <el-option label="全部" value="" />
            <el-option label="未支付" :value="0" />
            <el-option label="已支付" :value="1" />
            <el-option label="已发货" :value="2" />
            <el-option label="已完成" :value="3" />
            <el-option label="已关闭" :value="4" />
          </el-select>
          <el-button type="primary" size="small" @click="reloadOrders">搜索</el-button>
        </div>
      </div>

      <el-table v-loading="oLoading" :data="oList" border size="small">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="商品信息" min-width="180">
          <template #default="{ row }">
            <div class="goods-cell">
              <el-image :src="goodsImg(row.goods?.[0]?.pic)" fit="cover" class="goods-img" />
              <div class="goods-name">{{ row.goods?.[0]?.name || row.title }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="订单号/下单时间" width="170">
          <template #default="{ row }">
            <div class="ordernum">{{ row.ordernum }}</div>
            <div class="muted">{{ fmtTime(row.createtime) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="积分/金额" width="110">
          <template #default="{ row }">
            <div>{{ row.totalscore }}积分</div>
            <div>¥{{ row.totalprice }}</div>
          </template>
        </el-table-column>
        <el-table-column label="收货地址" min-width="160">
          <template #default="{ row }">
            <div>{{ row.linkman }} {{ row.tel }}</div>
            <div class="muted">{{ row.address }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="platform_text" label="来源" width="80" />
        <el-table-column label="头像昵称" width="110">
          <template #default="{ row }">
            <el-avatar :src="row.headimg" :size="22">{{ (row.nickname || '?').slice(0, 1) }}</el-avatar>
            <span class="muted">{{ row.nickname }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="paytype" label="支付方式" width="90" />
        <el-table-column prop="freight_text" label="配送方式" width="110" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag size="small" :type="row.status === 1 ? 'success' : row.status === 0 ? 'warning' : row.status === 2 ? 'primary' : row.status === 4 ? 'info' : ''">{{ ['未支付','已支付','已发货','已完成','已关闭'][row.status] }}</el-tag>
            <div v-if="row.refund_status" class="refund">{{ row.refund_text }}</div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="280">
          <template #default="{ row }">
            <div class="ops">
              <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
              <el-button link type="info" size="small" @click="openShd(row)">送货单</el-button>
              <el-button link type="danger" size="small" @click="doOrderDelete(row)">删除</el-button>
              <template v-if="row.status === 0">
                <el-button link type="warning" size="small" @click="doClose(row)">关闭订单</el-button>
                <el-button link type="success" size="small" @click="doIspay(row)">改为已支付</el-button>
              </template>
              <template v-if="row.status === 1">
                <el-button link type="primary" size="small" @click="openSendWay(row)">发货</el-button>
                <el-button v-if="row.freight_type === 1" link type="danger" size="small" @click="doHexiao(row)">核销</el-button>
                <el-button v-if="row.freight_type === 2" link type="warning" size="small" @click="openDeliver(row)">配送</el-button>
                <el-button link type="danger" size="small" @click="openRefund(row)">退款</el-button>
              </template>
              <el-button v-if="row.status === 2 && row.express_no" link type="primary" size="small" @click="openTrace(row)">查物流</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination class="pager" :current-page="oQuery.page" :page-size="oQuery.pagesize" :total="oTotal" layout="total, prev, pager, next"
        @current-change="(p: number) => { oQuery.page = p; fetchOrderList() }" />

      <!-- 发货方式 -->
      <el-dialog v-model="sendWayVisible" title="请选择发货方式" width="380px" append-to-body>
        <p class="way-tip">是否要使用电子面单发货？</p>
        <template #footer>
          <el-button type="primary" @click="useEwaybill">使用电子面单发货</el-button>
          <el-button @click="useManual">手动发货</el-button>
        </template>
      </el-dialog>

      <!-- 手动发货 -->
      <el-dialog v-model="manualVisible" title="手动发货" width="420px" append-to-body>
        <el-form label-width="90px" size="small">
          <el-form-item label="快递公司">
            <el-select v-model="manualForm.express_com" style="width:100%">
              <el-option v-for="o in expressOptions" :key="o.code" :label="o.name" :value="o.name" />
            </el-select>
          </el-form-item>
          <el-form-item label="快递单号"><el-input v-model="manualForm.express_no" placeholder="请输入快递单号" /></el-form-item>
        </el-form>
        <template #footer>
          <el-button type="primary" @click="submitManual">确定</el-button>
          <el-button @click="manualVisible = false">取消</el-button>
        </template>
      </el-dialog>

      <!-- 电子面单（对标已验收 CollageOrder 标准，三段：发货/包裹+收件人/发件人） -->
      <el-dialog v-model="ewbVisible" title="创建电子面单" width="720px" append-to-body :close-on-click-modal="false" destroy-on-close>
        <div v-loading="ewbLoading" class="ewb-body">
          <div class="ewb-row">
            <label>订单号：</label>
            <span class="ewb-static">{{ ewbForm.ordernum }}</span>
          </div>
          <div class="ewb-row">
            <label>面单订单号：</label>
            <span class="ewb-static">{{ ewbForm.ordercode }}</span>
          </div>
          <div class="ewb-row">
            <label><i>*</i>快递公司：</label>
            <el-select v-model="ewbForm.delivery_info" placeholder="请选择快递公司" style="width: 300px">
              <el-option v-for="o in ewbExpressOptions" :key="o.code" :label="o.name" :value="o.code" />
            </el-select>
          </div>
          <div class="ewb-row">
            <label>通知快递员：</label>
            <el-radio-group v-model="ewbForm.is_notice">
              <el-radio :value="0">否</el-radio>
              <el-radio :value="1">通知快递员上门揽件</el-radio>
            </el-radio-group>
          </div>
          <template v-if="ewbForm.is_notice === 1">
            <div class="ewb-row">
              <label>揽件开始时间：</label>
              <el-date-picker v-model="ewbForm.expect_start_time" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" placeholder="选择开始时间" style="width: 300px" />
              <span class="ewb-aux">不填表示已事先约定；跨越速运、京东快运必填</span>
            </div>
            <div class="ewb-row">
              <label>揽件结束时间：</label>
              <el-date-picker v-model="ewbForm.expect_end_time" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" placeholder="选择结束时间" style="width: 300px" />
            </div>
          </template>

          <div class="ewb-title">包裹信息</div>
          <div class="ewb-row">
            <label>包裹信息：</label>
            <span class="pkg-tag">包裹数量</span>
            <el-input-number v-model="ewbForm.quantity" :min="1" :controls="false" class="w70" />
            <span class="pkg-tag">包裹总重量(kg)</span>
            <el-input-number v-model="ewbForm.weight" :min="0.01" :step="0.1" :controls="false" class="w80" />
            <span class="pkg-tag">包裹总体积(m³)</span>
            <el-input-number v-model="ewbForm.volume" :min="0.001" :step="0.001" :precision="3" :controls="false" class="w90" />
          </div>
          <div class="ewb-hint">一个包裹对应一个运单号，数量&gt;1，则按照子母件的方式返回母运单号和子运单号</div>

          <div class="ewb-row">
            <label>是否保价：</label>
            <el-radio-group v-model="ewbForm.insure">
              <el-radio :value="0">否</el-radio>
              <el-radio :value="1">是</el-radio>
            </el-radio-group>
            <template v-if="ewbForm.insure === 1">
              <span class="pkg-tag">保价金额</span>
              <el-input-number v-model="ewbForm.insure_value" :min="0" :controls="false" class="w100" />
              <span class="ewb-static">元</span>
            </template>
          </div>

          <div v-if="ewbSelectedWarm" class="ewb-row">
            <label><i>*</i>温层：</label>
            <el-radio-group v-model="ewbForm.warm_layer">
              <el-radio v-for="(wn, wk) in ewbWarmLayer" :key="wk" :value="wk">{{ wn }}</el-radio>
            </el-radio-group>
          </div>

          <div class="ewb-row ewb-row-top">
            <label>备注信息：</label>
            <el-input v-model="ewbForm.remark" type="textarea" :rows="6" maxlength="200" show-word-limit style="width: 530px" />
          </div>
          <div class="ewb-hint">快递备注信息，该内容会打印在快递单上；不超过200字符，超过部分则不显示。</div>

          <div class="ewb-title">收件人信息</div>
          <div class="ewb-row">
            <label><i>*</i>姓名：</label>
            <el-input v-model="ewbForm.receiver.name" style="width: 300px" />
            <span class="ewb-aux">收件人姓名</span>
          </div>
          <div class="ewb-row">
            <label><i>*</i>手机号码：</label>
            <el-input v-model="ewbForm.receiver.mobile" style="width: 300px" />
            <span class="ewb-aux">座机号码和手机号不能同时为空</span>
          </div>
          <div class="ewb-row">
            <label><i>*</i>地区：</label>
            <el-select v-model="ewbForm.receiver.province" placeholder="省" style="width: 160px" @change="onEwbProvince(ewbForm.receiver)">
              <el-option v-for="p in areaProvinces" :key="p.name" :label="p.name" :value="p.name" />
            </el-select>
            <el-select v-model="ewbForm.receiver.city" placeholder="市" style="width: 150px" @change="onEwbCity(ewbForm.receiver)">
              <el-option v-for="c in ewbCityList(ewbForm.receiver)" :key="c.name" :label="c.name" :value="c.name" />
            </el-select>
            <el-select v-model="ewbForm.receiver.area" placeholder="区" style="width: 150px">
              <el-option v-for="a in ewbAreaList(ewbForm.receiver)" :key="a" :label="a" :value="a" />
            </el-select>
          </div>
          <div class="ewb-row">
            <label><i>*</i>详细地址：</label>
            <el-input v-model="ewbForm.receiver.address" style="width: 400px" />
            <span class="ewb-aux">不填省市区</span>
          </div>
          <div class="ewb-row">
            <label>座机号码：</label>
            <el-input v-model="ewbForm.receiver.tel" style="width: 300px" />
            <span class="ewb-aux">座机号码和手机号不能同时为空</span>
          </div>
          <div class="ewb-row">
            <label>公司名称：</label>
            <el-input v-model="ewbForm.receiver.company" style="width: 300px" />
            <span class="ewb-aux">收件人公司名称</span>
          </div>
          <div class="ewb-row">
            <label>邮编：</label>
            <el-input v-model="ewbForm.receiver.post_code" style="width: 300px" />
            <span class="ewb-aux">收件人邮编 邮政/EMS 必填 可填000000</span>
          </div>

          <div class="ewb-title">发件人信息</div>
          <div class="ewb-banner">发件人信息会自动获取上一单的发件人信息（仅需填写一次），如需要修改请手动输入。</div>
          <div class="ewb-row">
            <label><i>*</i>姓名：</label>
            <el-input v-model="ewbForm.sender.name" style="width: 300px" />
            <span class="ewb-aux">发件人姓名</span>
          </div>
          <div class="ewb-row">
            <label><i>*</i>手机号码：</label>
            <el-input v-model="ewbForm.sender.mobile" style="width: 300px" />
            <span class="ewb-aux">座机号码和手机号不能同时为空</span>
          </div>
          <div class="ewb-row">
            <label><i>*</i>地区：</label>
            <el-select v-model="ewbForm.sender.province" placeholder="省" style="width: 160px" @change="onEwbProvince(ewbForm.sender)">
              <el-option v-for="p in areaProvinces" :key="p.name" :label="p.name" :value="p.name" />
            </el-select>
            <el-select v-model="ewbForm.sender.city" placeholder="市" style="width: 150px" @change="onEwbCity(ewbForm.sender)">
              <el-option v-for="c in ewbCityList(ewbForm.sender)" :key="c.name" :label="c.name" :value="c.name" />
            </el-select>
            <el-select v-model="ewbForm.sender.area" placeholder="区" style="width: 150px">
              <el-option v-for="a in ewbAreaList(ewbForm.sender)" :key="a" :label="a" :value="a" />
            </el-select>
          </div>
          <div class="ewb-row">
            <label><i>*</i>详细地址：</label>
            <el-input v-model="ewbForm.sender.address" style="width: 400px" />
            <span class="ewb-aux">不填省市区</span>
          </div>
          <div class="ewb-row">
            <label>座机号码：</label>
            <el-input v-model="ewbForm.sender.tel" style="width: 300px" />
            <span class="ewb-aux">座机号码和手机号不能同时为空</span>
          </div>
          <div class="ewb-row">
            <label>公司名称：</label>
            <el-input v-model="ewbForm.sender.company" style="width: 300px" />
            <span class="ewb-aux">发件人公司名称</span>
          </div>
          <div class="ewb-row">
            <label>邮编：</label>
            <el-input v-model="ewbForm.sender.post_code" style="width: 300px" />
            <span class="ewb-aux">发件人邮编 邮政/EMS 必填 可填000000</span>
          </div>

          <div class="ewb-submit">
            <el-button type="danger" :loading="ewbSubmitting" @click="submitEwaybill">提 交</el-button>
          </div>
        </div>
      </el-dialog>

      <!-- 批量发货 -->
      <el-dialog v-model="batchVisible" title="导入EXCEL文件" width="480px" append-to-body>
        <el-form label-width="100px" size="small">
          <el-form-item label="快递公司">
            <el-select v-model="batchForm.express_com" style="width:100%">
              <el-option v-for="o in expressOptions" :key="o.code" :label="o.name" :value="o.name" />
            </el-select>
          </el-form-item>
          <el-form-item label="上传EXCEL文件">
            <el-input v-model="batchForm.fileName" placeholder="未选择文件" readonly style="flex:1" />
            <el-button @click="pickBatchFile">上传</el-button>
            <input ref="batchFileInput" type="file" accept=".xlsx,.xls" style="display:none" @change="onBatchFile" />
          </el-form-item>
          <el-link type="primary" :underline="false" @click="ElMessage.info('导入格式：第1列订单号，第2列快递单号')">点击下载查看导入格式</el-link>
        </el-form>
        <template #footer>
          <el-button type="primary" @click="submitBatch">确定导入发货</el-button>
        </template>
      </el-dialog>

      <!-- 退款（专有） -->
      <el-dialog v-model="refundVisible" title="退款" width="420px" append-to-body>
        <el-form label-width="80px" size="small">
          <el-form-item label="退款原因">
            <el-input v-model="refundForm.reason" type="textarea" :rows="3" placeholder="请输入退款原因" />
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button type="primary" @click="submitRefund">确定</el-button>
          <el-button @click="refundVisible = false">取消</el-button>
        </template>
      </el-dialog>

      <!-- 配送（专有） -->
      <el-dialog v-model="deliverVisible" title="配送" width="420px" append-to-body>
        <el-form label-width="100px" size="small">
          <el-form-item label="选择配送员">
            <el-select v-model="deliverForm.mdid" placeholder="请选择配送员" style="width:100%">
              <el-option v-for="s in staffList" :key="s.id" :value="s.id" :label="`${s.name}-${s.mobile}(配送中${s.doing}单)`" />
            </el-select>
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button type="primary" @click="submitDeliver">确定</el-button>
          <el-button @click="deliverVisible = false">取消</el-button>
        </template>
      </el-dialog>

      <!-- 详情 -->
      <el-dialog v-model="detailVisible" title="查看详情" width="640px" append-to-body>
        <el-descriptions v-if="detail" :column="2" border size="small">
          <el-descriptions-item label="订单号">{{ detail.ordernum }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ ['未支付','已支付','已发货','已完成','已关闭'][detail.status] }}</el-descriptions-item>
          <el-descriptions-item label="商品">{{ detail.goods?.[0]?.name || detail.title }}</el-descriptions-item>
          <el-descriptions-item label="数量">×{{ detail.totalnum }}</el-descriptions-item>
          <el-descriptions-item label="消耗积分">{{ detail.totalscore }}</el-descriptions-item>
          <el-descriptions-item label="实付金额">¥{{ detail.totalprice }}</el-descriptions-item>
          <el-descriptions-item label="收货人">{{ detail.linkman }} {{ detail.tel }}</el-descriptions-item>
          <el-descriptions-item label="地址">{{ detail.address }}</el-descriptions-item>
          <el-descriptions-item label="快递公司">{{ detail.express_com || '-' }}</el-descriptions-item>
          <el-descriptions-item label="快递单号">{{ detail.express_no || '-' }}</el-descriptions-item>
        </el-descriptions>
      </el-dialog>

      <!-- 物流 -->
      <el-dialog v-model="traceVisible" title="查物流" width="520px" append-to-body>
        <div v-if="trace.express_com" class="trace-head">
          <span class="trace-com">{{ trace.express_com }}</span>
          <span class="trace-no">{{ trace.express_no }}</span>
        </div>
        <el-timeline v-if="trace.traces.length">
          <el-timeline-item v-for="(t, i) in trace.traces" :key="i" :timestamp="t.time">{{ t.station }}</el-timeline-item>
        </el-timeline>
        <el-empty v-else description="暂无物流轨迹" />
      </el-dialog>
    </div>

    <!-- ============ 送货单整页 ============ -->
    <div v-if="shdVisible" class="shd-page">
      <div class="shd-toolbar">
        <el-button size="small" @click="shdBack">返回列表</el-button>
        <el-button size="small" type="success" @click="shdPrint">打印</el-button>
      </div>
      <div v-loading="shdLoading" class="shd-print">
        <template v-if="shdData">
          <div class="shd-sheet">
            <div class="shd-title-row">
              <span class="shd-title">{{ shdData.title || '送货单' }}</span>
              <span class="shd-no">NO:{{ shdData.ordernum }}</span>
            </div>
            <div class="shd-info-row">
              <span>客户名称：{{ shdData.linkman }}</span>
              <span>客户电话：{{ shdData.tel }}</span>
              <span class="shd-date">送货日期：{{ fmtTime(shdData.createtime) }}</span>
            </div>
            <div class="shd-info-row">
              <span>客户地址：{{ shdData.address }}</span>
            </div>
            <div class="shd-table-wrap">
              <table class="shd-table" border="1">
                <thead>
                  <tr><th>序号</th><th>商品名称</th><th>单价</th><th>数量</th><th>小计</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(g, i) in shdData.goods" :key="i">
                    <td>{{ Number(i) + 1 }}</td>
                    <td class="shd-left">{{ g.name }}</td>
                    <td>{{ Number(g.price).toFixed(2) }}</td>
                    <td>{{ g.num }}</td>
                    <td>{{ Number(g.money).toFixed(2) }}</td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr><td colspan="4" class="shd-right">运费</td><td>{{ Number(shdData.freight_price || 0).toFixed(2) }}</td></tr>
                  <tr><td colspan="4" class="shd-right">合计金额(小写)</td><td>{{ Number(shdData.totalprice).toFixed(2) }}</td></tr>
                  <tr><td colspan="4" class="shd-right">合计金额(大写)</td><td>{{ shdData.total_upper }}</td></tr>
                </tfoot>
              </table>
            </div>
            <div class="shd-foot">
              <span>收货人签字：____________</span>
              <span>日期：____________</span>
            </div>
          </div>
        </template>
      </div>
    </div>
    <!-- ============ 商品分类 ============ -->
    <div v-show="activeTab === 'category'" class="pane">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button type="primary" size="small" @click="openAddCategory">添加</el-button>
          <el-button size="small" @click="onBatchDelete">删除</el-button>
        </div>
      </div>
      <el-table v-loading="cLoading" :data="cList" border size="small">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="名称" min-width="160" />
        <el-table-column label="图片" width="100">
          <template #default="{ row }"><el-image :src="row.pic" fit="cover" style="width:40px;height:40px" /></template>
        </el-table-column>
        <el-table-column prop="sort" label="序号" width="80" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }"><el-tag size="small" :type="row.status ? 'success' : 'info'">{{ row.status ? '显示' : '隐藏' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEditCategory(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="deleteCategory(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-dialog v-model="catDialog" :title="catForm.id ? '编辑商品分类' : '+添加商品分类'" width="480px" append-to-body>
        <el-form label-width="90px" size="small">
          <el-form-item label="分类名称"><el-input v-model="catForm.name" /></el-form-item>
          <el-form-item label="分类图标">
            <div v-if="catForm.pic" class="cat-pic"><el-image :src="catForm.pic" fit="cover" style="width:60px;height:60px" /></div>
            <div v-else class="ge-upload-add" @click="openImgPicker((urls) => { if (urls[0]) catForm.pic = urls[0] })">+</div>
            <div class="muted">建议尺寸400x400</div>
          </el-form-item>
          <el-form-item label="序号"><el-input-number v-model="catForm.sort" :min="0" /><div class="muted">用于排序，越大越靠前</div></el-form-item>
          <el-form-item label="状态">
            <el-radio-group v-model="catForm.status"><el-radio :value="1">显示</el-radio><el-radio :value="0">隐藏</el-radio></el-radio-group>
          </el-form-item>
        </el-form>
        <template #footer><el-button type="primary" @click="submitCategory">提交</el-button></template>
      </el-dialog>
    </div>

    <!-- ============ 系统设置 ============ -->
    <div v-show="activeTab === 'set'" class="pane">
      <el-card shadow="never">
        <el-form label-width="120px" size="small" style="max-width:600px">
          <el-form-item label="自动收货天数">
            <el-input-number v-model="setForm.autoshdays" :min="1" /> 天
            <div class="muted">发货后多少天用户未确认收货自动完成收货（最小值为1）</div>
          </el-form-item>
          <el-form-item label="自动关闭订单">
            <el-input-number v-model="setForm.autoclose" :min="1" /> 分钟
            <div class="muted">下单后多久不支付自动关闭订单，释放占用的库存</div>
          </el-form-item>
          <el-form-item label="店铺信息">
            <el-radio-group v-model="setForm.showjd"><el-radio :value="1">显示</el-radio><el-radio :value="0">不显示</el-radio></el-radio-group>
            <div class="muted">商品详情页是否显示店铺信息</div>
          </el-form-item>
          <el-form-item label="是否显示佣金">
            <el-radio-group v-model="setForm.showcommission"><el-radio :value="1">显示</el-radio><el-radio :value="0">不显示</el-radio></el-radio-group>
            <div class="muted">开启后商品详情页显示分销佣金</div>
          </el-form-item>
          <el-form-item label="进入条件">
            <el-checkbox-group v-model="setForm.gettj" @change="onSetGettjChange">
              <el-checkbox value="-1">所有人</el-checkbox>
              <el-checkbox value="1">普通会员</el-checkbox>
              <el-checkbox value="2">经销商</el-checkbox>
            </el-checkbox-group>
          </el-form-item>
          <el-form-item><el-button type="primary" @click="saveSet">提交</el-button></el-form-item>
        </el-form>
      </el-card>
    </div>

    <!-- ============ 商品编辑整页（平铺主区域） ============ -->
    <div v-if="editorVisible" class="goods-edit">
      <div class="ge-head">
        <div class="ge-head-title">{{ editorIsEdit ? '编辑商品' : '添加商品' }}</div>
        <div class="ge-head-close" @click="closeEditor">
          <svg viewBox="0 0 1024 1024" width="18" height="18" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </div>
      </div>

      <el-tabs v-model="editorTab" class="ge-tabs">
        <el-tab-pane label="商品基本信息" name="info">
          <el-form label-width="120px" size="small" class="editor-form">
            <el-form-item label="兑换类型"><el-radio-group><el-radio :value="0" disabled>默认</el-radio></el-radio-group></el-form-item>
            <el-form-item label="商品名称" required>
              <el-input v-model="form.name" style="width:360px" />
              <el-button style="margin-left:8px" @click="openGoodsPicker">选择商城商品</el-button>
            </el-form-item>
            <el-form-item label="商品主图">
              <div v-if="form.pic" class="cat-pic">
                <el-image :src="form.pic" fit="cover" style="width:80px;height:80px" />
                <el-button link type="danger" size="small" @click="form.pic = ''">删除</el-button>
              </div>
              <div v-else class="ge-upload-add" @click="openImgPicker((urls) => { if (urls[0]) form.pic = urls[0] })">+</div>
              <div class="muted">建议尺寸640x640</div>
            </el-form-item>
            <el-form-item label="商品图片">
              <div class="pic-list">
                <div v-for="(p, i) in form.pics" :key="i" class="pic-item">
                  <el-image :src="p" fit="cover" style="width:60px;height:60px" />
                  <span class="pic-del" @click="form.pics.splice(i, 1)">×</span>
                </div>
                <div class="ge-upload-add" @click="openImgPicker((urls) => { form.pics = form.pics.concat(urls) }, true)">+</div>
              </div>
              <div class="muted">建议尺寸640x640</div>
            </el-form-item>
            <el-form-item label="商品分类" required>
              <el-select v-model="form.cid" placeholder="--请选择--" style="width:240px">
                <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="每人限兑">
              <el-input-number v-model="form.buymax" :min="0" />
              <div class="muted">每人最多兑换多少件，0表示不限制</div>
            </el-form-item>
            <el-form-item label="每人每天限兑">
              <el-input-number v-model="form.everyday_buymax" :min="0" />
              <div class="muted">每人每天最多兑换多少件，0表示不限制</div>
            </el-form-item>
            <el-form-item label="会员价">
              <el-radio-group v-model="form.lvprice"><el-radio :value="0">关闭</el-radio><el-radio :value="1">开启</el-radio></el-radio-group>
            </el-form-item>

            <el-form-item label="设置价格">
              <el-table :data="form.guige" border size="small" style="width:100%">
                <el-table-column label="规格" width="120">
                  <template #default="{ row }"><el-input v-model="row.name" size="small" /></template>
                </el-table-column>
                <el-table-column label="市场价(元)" width="120">
                  <template #default="{ row }"><el-input-number v-model="row.market_price" :min="0" :precision="2" size="small" controls-position="right" /></template>
                </el-table-column>
                <el-table-column label="成本价(元)" width="120">
                  <template #default="{ row }"><el-input-number v-model="row.cost_price" :min="0" :precision="2" size="small" controls-position="right" /></template>
                </el-table-column>
                <el-table-column label="销售价(元+积分)" min-width="200">
                  <template #default="{ row }">
                    <el-input-number v-model="row.money_price" :min="0" :precision="2" size="small" controls-position="right" style="width:80px" />
                    <span class="muted">元+</span>
                    <el-input-number v-model="row.score_price" :min="0" size="small" controls-position="right" style="width:80px" />
                    <span class="muted">积分</span>
                  </template>
                </el-table-column>
                <el-table-column label="重量(克)" width="110">
                  <template #default="{ row }"><el-input-number v-model="row.weight" :min="0" size="small" controls-position="right" /></template>
                </el-table-column>
                <el-table-column label="库存" width="100">
                  <template #default="{ row }"><el-input-number v-model="row.stock" :min="0" size="small" controls-position="right" /></template>
                </el-table-column>
                <el-table-column label="图片" width="90">
                  <template #default="{ row }">
                    <div v-if="row.pic" class="cat-pic"><el-image :src="row.pic" fit="cover" style="width:40px;height:40px" /></div>
                    <div v-else class="ge-upload-add" style="width:40px;height:40px" @click="openImgPicker((urls) => { if (urls[0]) row.pic = urls[0] })">+</div>
                  </template>
                </el-table-column>
              </el-table>
            </el-form-item>

            <el-form-item label="销量"><el-input-number v-model="form.sales" :min="0" /></el-form-item>

            <el-form-item label="配送模板">
              <el-radio-group v-model="form.freighttype">
                <el-radio :value="1">全部模板</el-radio>
                <el-radio :value="0">选择模板</el-radio>
                <el-radio :value="3">自动发货</el-radio>
                <el-radio :value="4">在线卡密</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="form.freighttype === 0" label-width="120px">
              <el-table :data="freightList" border size="small" style="width:100%" max-height="200">
                <el-table-column label="" width="50">
                  <template #default="{ row }"><el-checkbox :model-value="chosenFreight.includes(row.id)" @change="toggleFreight(row.id)" /></template>
                </el-table-column>
                <el-table-column prop="id" label="模板ID" width="80" />
                <el-table-column prop="name" label="模板名称" />
              </el-table>
            </el-form-item>
            <el-form-item v-if="form.freighttype === 3" label-width="120px">
              <div>
                <el-checkbox v-model="form.contact_require">必填联系人、手机号信息</el-checkbox>
                <el-input v-model="form.freightcontent" type="textarea" :rows="3" placeholder="请输入发货信息" style="margin-top:8px" />
              </div>
            </el-form-item>
            <el-form-item v-if="form.freighttype === 4" label-width="120px">
              <div>
                <el-checkbox v-model="form.contact_require">必填联系人、手机号信息</el-checkbox>
                <div class="muted" style="margin-top:8px">请保存商品信息后在商品列表中上传卡密信息；用户购买几件将发送几个卡密信息</div>
              </div>
            </el-form-item>

            <el-form-item label="分销设置">
              <el-radio-group v-model="form.commissionset">
                <el-radio :value="0">按照会员等级</el-radio>
                <el-radio :value="1">单独设置提成比例</el-radio>
                <el-radio :value="2">单独设置提成金额</el-radio>
                <el-radio :value="3">分销送积分</el-radio>
                <el-radio :value="5">提成比例+积分</el-radio>
                <el-radio :value="-1">不参与分销</el-radio>
              </el-radio-group>
            </el-form-item>

            <el-form-item v-if="form.commissionset === 1" label-width="120px">
              <el-table :data="agentLevels" border size="small" style="width:100%">
                <el-table-column prop="name" label="会员等级" width="160" />
                <el-table-column label="一级(%)">
                  <template #default="{ row }"><el-input v-model="form.commissiondata1[row.id].commission1" size="small" placeholder="0" /></template>
                </el-table-column>
                <el-table-column label="二级(%)">
                  <template #default="{ row }"><el-input v-model="form.commissiondata1[row.id].commission2" size="small" placeholder="0" /></template>
                </el-table-column>
              </el-table>
            </el-form-item>

            <el-form-item v-if="form.commissionset === 2" label-width="120px">
              <el-table :data="agentLevels" border size="small" style="width:100%">
                <el-table-column prop="name" label="会员等级" width="160" />
                <el-table-column label="一级(元)">
                  <template #default="{ row }"><el-input v-model="form.commissiondata2[row.id].commission1" size="small" placeholder="0" /></template>
                </el-table-column>
                <el-table-column label="二级(元)">
                  <template #default="{ row }"><el-input v-model="form.commissiondata2[row.id].commission2" size="small" placeholder="0" /></template>
                </el-table-column>
              </el-table>
            </el-form-item>

            <el-form-item v-if="form.commissionset === 3" label-width="120px">
              <el-table :data="agentLevels" border size="small" style="width:100%">
                <el-table-column prop="name" label="会员等级" width="160" />
                <el-table-column label="一级(积分)">
                  <template #default="{ row }"><el-input v-model="form.commissiondata3[row.id].commission1" size="small" placeholder="0" /></template>
                </el-table-column>
                <el-table-column label="二级(积分)">
                  <template #default="{ row }"><el-input v-model="form.commissiondata3[row.id].commission2" size="small" placeholder="0" /></template>
                </el-table-column>
              </el-table>
            </el-form-item>

            <el-form-item v-if="form.commissionset === 5" label-width="120px">
              <div>
                <el-table :data="agentLevels" border size="small" style="width:100%;margin-bottom:8px">
                  <el-table-column prop="name" label="会员等级" width="160" />
                  <el-table-column label="一级(%)">
                    <template #default="{ row }"><el-input v-model="form.commissiondata5[row.id].commission1.money" size="small" placeholder="0" /></template>
                  </el-table-column>
                  <el-table-column label="二级(%)">
                    <template #default="{ row }"><el-input v-model="form.commissiondata5[row.id].commission2.money" size="small" placeholder="0" /></template>
                  </el-table-column>
                </el-table>
                <el-table :data="agentLevels" border size="small" style="width:100%">
                  <el-table-column prop="name" label="会员等级" width="160" />
                  <el-table-column label="一级(积分)">
                    <template #default="{ row }"><el-input v-model="form.commissiondata5[row.id].commission1.score" size="small" placeholder="0" /></template>
                  </el-table-column>
                  <el-table-column label="二级(积分)">
                    <template #default="{ row }"><el-input v-model="form.commissiondata5[row.id].commission2.score" size="small" placeholder="0" /></template>
                  </el-table-column>
                </el-table>
              </div>
            </el-form-item>

            <el-form-item label="序号"><el-input-number v-model="form.sort" :min="0" /><div class="muted">用于排序，越大越靠前</div></el-form-item>
            <el-form-item label="显示条件">
              <el-checkbox-group v-model="form.showtj" @change="onShowtjChange">
                <el-checkbox value="-1">所有人</el-checkbox>
                <el-checkbox value="focus">关注用户</el-checkbox>
                <el-checkbox value="1">普通会员</el-checkbox>
                <el-checkbox value="2">经销商</el-checkbox>
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="购买条件">
              <el-checkbox-group v-model="form.gettj" @change="onGettjChange">
                <el-checkbox value="-1">所有人</el-checkbox>
                <el-checkbox value="focus">关注用户</el-checkbox>
                <el-checkbox value="1">普通会员</el-checkbox>
                <el-checkbox value="2">经销商</el-checkbox>
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="状态">
              <el-radio-group v-model="form.status"><el-radio :value="1">已上架</el-radio><el-radio :value="0">未上架</el-radio></el-radio-group>
            </el-form-item>
          </el-form>
          <div class="tab-footer">
            <el-button @click="editorTab = 'detail'">下一步:编辑商品详情</el-button>
            <el-button type="primary" :loading="saving" @click="submitProduct">提交</el-button>
          </div>
        </el-tab-pane>

        <el-tab-pane label="商品详情" name="detail">
          <GoodsDetailEditor v-model="detailModules" title="商品详情" />
          <div class="tab-footer">
            <el-button @click="editorTab = 'info'">上一步:编辑商品信息</el-button>
            <el-button type="primary" :loading="saving" @click="submitProduct">提交</el-button>
          </div>
        </el-tab-pane>
      </el-tabs>

      <ImgIconPicker v-model:visible="imgPickerVisible" v-model:multiple="imgPickerMulti" @pick="onImgPick" />
    </div>
    <ImgIconPicker v-model:visible="imgPickerVisible" v-model:multiple="imgPickerMulti" @pick="onImgPick" />

    <!-- 选择商城商品 -->
    <el-dialog v-model="goodsPickerVisible" title="选择商城商品" width="720px" append-to-body>
      <el-table :data="goodsOptions" border size="small" @row-dblclick="pickGoods">
        <el-table-column prop="name" label="商品名称" />
        <el-table-column prop="sell_price" label="价格" width="120" />
        <el-table-column label="操作" width="80">
          <template #default="{ row }"><el-button link type="primary" size="small" @click="pickGoods(row)">选择</el-button></template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </div>
</template>

<style scoped>
.scoreshop-page { margin: 3px; }
.pane { margin-top: 3px; }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px; gap: 8px; flex-wrap: nowrap; }
.toolbar-left { display: flex; gap: 6px; flex-shrink: 0; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.toolbar-right .el-input { width: 130px; }
.goods-cell { display: flex; gap: 8px; align-items: center; }
.goods-img { width: 40px; height: 40px; border-radius: 4px; flex-shrink: 0; }
.goods-name { font-size: 12px; }
.goods-sub { color: #f60; font-size: 12px; }
.muted { color: #999; font-size: 12px; }
.refund { color: #f56c6c; font-size: 12px; }
.ordernum { font-size: 12px; }
.ops { display: flex; flex-wrap: nowrap; gap: 2px; white-space: nowrap; }
.pager { margin-top: 10px; justify-content: flex-end; }
.way-tip { margin: 0 0 10px; color: #333; }
.ge-upload-add { width: 80px; height: 80px; border: 1px dashed #dcdfe6; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #909399; font-size: 24px; cursor: pointer; }
.pic-list { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.pic-item { position: relative; }
.pic-del { position: absolute; top: -6px; right: -6px; color: #f56c6c; cursor: pointer; font-size: 16px; background: #fff; border-radius: 50%; }
.cat-pic { display: flex; align-items: center; gap: 6px; }
.editor-form { max-width: 1000px; }
:deep(.el-table__header th) { background: #e6e6e6 !important; }
:deep(.el-table .cell) { text-align: left; }

/* ============ 电子面单弹窗（对齐 CollageOrder 标准） ============ */
.ewb-body { font-size: 13px; max-height: calc(100vh - 160px); overflow-y: auto; }
.ewb-title { color: #333; font-weight: 600; margin: 12px 0 8px; }
.ewb-title::after { content: ''; display: block; border-top: 1px solid #dcdfe6; margin-top: 6px; }
.ewb-row { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; flex-wrap: wrap; }
.ewb-row-top { align-items: flex-start; }
.ewb-row label { width: 110px; text-align: right; color: #606266; flex: none; }
.ewb-row label i { color: #f56c6c; font-style: normal; margin-right: 2px; }
.ewb-static { color: #333; }
.ewb-aux { color: #909399; font-size: 12px; }
.ewb-hint { color: #909399; font-size: 12px; margin: -4px 0 8px 116px; }
.ewb-body .w70 { width: 70px; }
.ewb-body .w80 { width: 80px; }
.ewb-body .w90 { width: 90px; }
.ewb-body .w100 { width: 100px; }
.pkg-tag { background: #e6e6e6; padding: 4px 8px; color: #606266; }
.ewb-banner { background: #f4f4f5; border-left: 3px solid #2bb3a3; padding: 8px 10px; color: #606266; margin-bottom: 8px; }
.ewb-submit { margin: 12px 0 4px 110px; }
/* ============ 商品编辑平铺主区域 ============ */
.goods-edit { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; }
.ge-head-title { font-size: 16px; font-weight: 600; color: #303133; flex: 1; }
.ge-head-close { cursor: pointer; color: #909399; display: flex; align-items: center; }
.ge-tabs { margin-bottom: 8px; }
.tab-footer { margin-top: 16px; padding-top: 12px; border-top: 1px solid #f0f0f0; display: flex; gap: 10px; }

/* ============ 送货单整页 ============ */
.shd-toolbar { margin-bottom: 10px; display: flex; gap: 8px; }
.shd-print { background: #fff; padding: 20px; }
.shd-sheet { max-width: 800px; margin: 0 auto; }
.shd-title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.shd-title { font-size: 22px; font-weight: 700; }
.shd-no { color: #606266; font-size: 13px; }
.shd-info-row { display: flex; gap: 24px; margin-bottom: 8px; font-size: 13px; }
.shd-date { margin-left: auto; }
.shd-table-wrap { margin-top: 12px; }
.shd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.shd-table th, .shd-table td { border: 1px solid #999; padding: 6px 10px; }
.shd-table th { background: #f0f0f0; }
.shd-left { text-align: left; }
.shd-right { text-align: right; }
.shd-foot { margin-top: 40px; display: flex; justify-content: space-between; font-size: 13px; }

@media print {
  .trace-head{display:flex;gap:12px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px dashed #e0e0e0;font-size:14px;}
.trace-com{font-weight:600;color:#303133;}
.trace-no{color:#909399;font-family:monospace;}
.shd-page { margin: 0; }
  .shd-toolbar { display: none; }
}
</style>
