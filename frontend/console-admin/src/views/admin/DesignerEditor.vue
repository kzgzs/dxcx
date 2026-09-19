<script setup lang="ts">
/**
 * 页面设计器 - 编辑器（独立标签页，三栏布局）
 * - 左：组件面板（31 个可添加组件，任务45 P27-1 扩展版）
 * - 中：手机预览画布（375 宽，按 content 顺序简版渲染，点击上下移动/删除）
 * - 右：属性面板（选中组件 params + 通用 margin/padding/bgcolor）
 * - 顶部：页面标题编辑、保存（保存即发布）、预览弹窗、返回首页
 * - 组件统一结构 {id:'M'+时间戳, temp, params, data}
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getDesignerPageDetail,
  getDesignerTabContent,
  saveDesignerPage,
  saveDesignerTabContent,
  type DesignerModule,
} from '../../api/designer'
import { MODULE_MENUS, MODULE_NAMES, MEMBER_LEVEL_OPTIONS, PLATFORM_OPTIONS, MODULE_CATEGORY_ORDER, MODULE_CATEGORY_LABELS, defaultModule, defaultTopbar, genMid, type ModuleCategory, type CubeSlot } from './designerModules'
import DesignerChooseUrl from './DesignerChooseUrl.vue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import CouponPicker from '../../components/CouponPicker.vue'
import DeSlider from '../../components/DeSlider.vue'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'
import VideoPicker, { type PickedVideo } from '../../components/VideoPicker.vue'
import { uploadFile, getGoodsList, getCategoryList } from '../../api/goods'
import '@wangeditor/editor/dist/css/style.css'
import { Editor as RtEditor, Toolbar as RtToolbar } from '@wangeditor/editor-for-vue'
import type { IDomEditor } from '@wangeditor/editor'
import { getMusicList, type MusicRow } from '../../api/music'
import { loadSysTheme, resolveNavBg, resolveNavText, sysTheme } from '../../utils/sysTheme'

const route = useRoute()

// ============ 页面状态 ============
const pageId = ref<number>(Number(route.query.id) || 0)
// 页面类型（装饰设计页双挂，P24-8b 任务32）：1 常规页 2 外卖页 3 点餐页，仅新建保存时随请求提交
const pageType = ref<number>([0, 2, 3].includes(Number(route.query.type)) ? Number(route.query.type) : 0)
const topbar = reactive<DesignerModule>(defaultTopbar())
const items = ref<DesignerModule[]>([])
const selectedId = ref('')
const saving = ref(false)
const previewVisible = ref(false)
const loaded = ref(false)

/** 页面标题（topbar.params.title 的编辑代理） */
const pageTitle = computed({
  get: () => String(topbar.params.title || ''),
  set: (v: string) => { topbar.params.title = v },
})

// ============ 页面信息设置（任务205：未选中=选中 topbar 统一表单 + 画布顶栏联动） ============
/** 查看权限复选（all 所有人 / member 普通会员 / dealer 经销商） */
const viewpermChecked = computed<string[]>({
  get: () => {
    const v = topbar.params.viewperm
    return v && typeof v === 'object' ? Object.keys(v).filter((k) => v[k]) : []
  },
  set: (arr: string[]) => {
    if (!topbar.params.viewperm || typeof topbar.params.viewperm !== 'object') topbar.params.viewperm = { all: true }
    const v = topbar.params.viewperm as Record<string, any>
    for (const k of Object.keys(v)) delete v[k]
    arr.forEach((k) => { v[k] = true })
    v.all = arr.includes('all') || arr.length === 0
  },
})

/** 开屏公告展示人群多选（任务206：all/followed/unfollowed/member/dealer） */
const ggcrowdChecked = computed<string[]>({
  get: () => {
    const v = topbar.params.ggcrowd
    return v && typeof v === 'object' ? Object.keys(v).filter((k) => v[k]) : []
  },
  set: (arr: string[]) => {
    if (!topbar.params.ggcrowd || typeof topbar.params.ggcrowd !== 'object') topbar.params.ggcrowd = { all: true }
    const v = topbar.params.ggcrowd as Record<string, any>
    for (const k of Object.keys(v)) delete v[k]
    arr.forEach((k) => { v[k] = true })
    v.all = arr.includes('all') || arr.length === 0
  },
})

/** 画布顶栏背景色（任务209：自定义用页面值，跟随系统=系统 navbgmode/navbgcolor/主色调） */
const canvasNavBg = computed(() => resolveNavBg(sysTheme.value, topbar.params as Record<string, any>))

/** 画布顶栏文字色（任务209 默认#333黑；跟随系统=系统 navtextcolor 或黑） */
const canvasNavText = computed(() => resolveNavText(sysTheme.value, topbar.params as Record<string, any>))

/** 画布主区域背景（任务207：背景应用于主区域，非顶栏；bgdir 0上下 1左右） */
const canvasPageBg = computed(() => {
  const c1 = String(topbar.params.bgcolor || '#F5F5F5')
  if (topbar.params.bgmode === '1') {
    const deg = topbar.params.bgdir === '1' ? '90deg' : '180deg'
    return `linear-gradient(${deg}, ${c1}, ${String(topbar.params.bgcolor2 || '#FFFFFF')})`
  }
  return c1
})

/** 开屏公告单图位（gglist[0]，强转为自动补位） */
const ggFirst = computed(() => {
  if (!Array.isArray(topbar.params.gglist)) topbar.params.gglist = []
  if (!topbar.params.gglist.length) topbar.params.gglist.push({ image: '', url: '' })
  return topbar.params.gglist[0]
})

// 独立标签页标题同步
watchEffect(() => {
  document.title = `${pageTitle.value || '页面设计'} - 鼎极多商·设计器`
})

const selected = computed<DesignerModule | null>(() => {
  if (selectedId.value === topbar.id) return topbar
  return items.value.find((i) => i.id === selectedId.value) || null
})

// ============ 加载回显 ============
async function loadPage() {
  if (!pageId.value) {
    loaded.value = true
    return
  }
  const res = await getDesignerPageDetail(pageId.value)
  const pi = Array.isArray(res.pageinfo) && res.pageinfo.length ? res.pageinfo[0] : defaultTopbar()
  const fresh = defaultTopbar()
  topbar.params.gglist = []
  Object.assign(topbar, fresh, pi)
  topbar.params = { ...fresh.params, ...(pi.params || {}) }
  if (!Array.isArray(topbar.params.gglist)) topbar.params.gglist = []
  items.value = (Array.isArray(res.content) ? res.content : []).map((m) => ({
    ...defaultModule(m.temp),
    ...m,
    params: { ...defaultModule(m.temp).params, ...(m.params || {}) },
  }))
  // 任务100：旧版魔方（cubeNum+data 列数模式）迁移为 slots 自由布局，两列流式归位
  items.value.forEach((m) => {
    if (m.temp !== 'cube' || Array.isArray(m.params.slots)) return
    const slots: CubeSlot[] = []
    ;(Array.isArray(m.data) ? m.data : []).forEach((d: Record<string, any>, i: number) => {
      const y = Math.floor(i / 2)
      if (y > 3) return
      slots.push({ x: (i % 2) * 2, y, w: 2, h: 1, imgurl: d?.imgurl || '', hrefurl: d?.hrefurl || '', hrefname: d?.hrefname || '' })
    })
    m.params.slots = slots
  })
  loaded.value = true
}

// ============ 左面板：三分类手风琴 + 搜索（任务299） ============
const catKeyword = ref('')
const expandedCat = ref<ModuleCategory | ''>('basic')

/** 三分类组（类头数量动态计算） */
const catGroups = computed(() =>
  MODULE_CATEGORY_ORDER.map((key) => ({
    key,
    label: MODULE_CATEGORY_LABELS[key],
    items: MODULE_MENUS.filter((m) => m.category === key),
  })),
)

/** 搜索命中（跨三类，名称/描述/temp 匹配） */
const searchHits = computed(() => {
  const kw = catKeyword.value.trim()
  if (!kw) return []
  const low = kw.toLowerCase()
  return MODULE_MENUS.filter(
    (m) => m.name.includes(kw) || m.desc.includes(kw) || m.temp.includes(low),
  )
})

/** 手风琴：展开某类即收起另两类；点击已展开类则收起 */
function toggleCat(key: ModuleCategory) {
  expandedCat.value = expandedCat.value === key ? '' : key
}

// ============ 组件操作 ============
function addModule(temp: string) {
  const mod = defaultModule(temp)
  // 有选中组件时插到其后面，否则追加末尾
  const idx = items.value.findIndex((i) => i.id === selectedId.value)
  if (idx >= 0) items.value.splice(idx + 1, 0, mod)
  else items.value.push(mod)
  selectedId.value = mod.id
}

function indexOf(id: string): number {
  return items.value.findIndex((i) => i.id === id)
}

function moveUp(id: string) {
  const idx = indexOf(id)
  if (idx > 0) {
    const arr = items.value
    ;[arr[idx - 1], arr[idx]] = [arr[idx], arr[idx - 1]]
  }
}

function moveDown(id: string) {
  const idx = indexOf(id)
  if (idx >= 0 && idx < items.value.length - 1) {
    const arr = items.value
    ;[arr[idx], arr[idx + 1]] = [arr[idx + 1], arr[idx]]
  }
}

function removeModule(id: string) {
  const idx = indexOf(id)
  if (idx >= 0) items.value.splice(idx, 1)
  if (selectedId.value === id) selectedId.value = ''
}

// ============ 保存（保存即发布） ============
async function handleSave(): Promise<number | null> {
  if (!pageTitle.value.trim()) {
    ElMessage.warning('请先填写页面标题')
    return null
  }
  saving.value = true
  try {
    const payload = {
      id: pageId.value || undefined,
      type: pageId.value ? undefined : pageType.value,
      pageinfo: [JSON.parse(JSON.stringify(topbar))],
      content: JSON.parse(JSON.stringify(items.value)),
    }
    const res = await saveDesignerPage(payload)
    pageId.value = res.id
    ElMessage.success('保存成功，已发布')
    return res.id
  } finally {
    saving.value = false
  }
}

// ============ 图片/图标选择（任务192 接入 ImgIconPicker） ============
const imgPickerVisible = ref(false)
const iconPickerVisible = ref(false)
const pickTarget = ref<((url: string) => void) | null>(null)

function openImgPick(set: (url: string) => void) {
  pickTarget.value = set
  imgPickerVisible.value = true
}

function openIconPick(set: (url: string) => void) {
  pickTarget.value = set
  iconPickerVisible.value = true
}

function onPick(urls: string[]) {
  if (pickTarget.value && urls[0]) pickTarget.value(urls[0])
  pickTarget.value = null
}

// ============ 商城商品组件（任务302：属性面板数据 - 分类/分组/手动选品） ============
const prodCategories = ref<{ id: number; name: string }[]>([])

/** 选中商品组件时懒加载商品分类（下拉选项） */
watch(
  () => selected.value?.temp,
  async (t) => {
    if (t !== 'product') return
    // 兼容旧数据：category 空串归一为 all（空串会导致下拉框显示“请选择”）
    const sel = selected.value
    if (sel && !sel.params.category) sel.params.category = 'all'
    if (prodCategories.value.length) return
    try {
      const data = await getCategoryList({ page: 1, pagesize: 100 })
      prodCategories.value = (data.list || []).map((c) => ({ id: c.id, name: c.name }))
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    }
  },
)

/** 商品分组多选选项（对照截图：热卖/推荐/爆款/颜色） */
const PROD_GROUP_OPTIONS = [
  { value: 'hot', label: '热卖' },
  { value: 'recommend', label: '推荐' },
  { value: 'pop', label: '爆款' },
  { value: 'color', label: '颜色' },
]

const prodGroupAll = computed<boolean>({
  get: () => selected.value?.params.groupall === '1',
  set: (v) => { if (selected.value) selected.value.params.groupall = v ? '1' : '0' },
})
const prodNoGroup = computed<boolean>({
  get: () => selected.value?.params.nogroup === '1',
  set: (v) => { if (selected.value) selected.value.params.nogroup = v ? '1' : '0' },
})
const prodGroupsChecked = computed<string[]>({
  get: () => (Array.isArray(selected.value?.params.groups) ? (selected.value!.params.groups as string[]) : []),
  set: (v) => { if (selected.value) selected.value.params.groups = v },
})

/** 显示数量数字代理（params 为字符串口径） */
const prodShownum = computed<number>({
  get: () => Number(selected.value?.params.proshownum || 6),
  set: (n) => { if (selected.value) selected.value.params.proshownum = String(n) },
})

/** 预览价格颜色：跟随主色调=系统主题色 color1，自定义=pricecolor */
function prodPriceColor(p: Record<string, any>): string {
  return p.pricecolormode === '1' ? String(p.pricecolor || '#FF4400') : sysTheme.value.color1
}

/** 预览角标文字（probadge 1推荐 2热卖 3新上 4限时 5促销，0无；自己上传图优先） */
function prodBadgeText(p: Record<string, any>): string {
  const map: Record<string, string> = { '1': '推荐', '2': '热卖', '3': '新上', '4': '限时', '5': '促销' }
  return map[String(p.probadge)] || ''
}

// ============ 积分兑换组件方法 ============
/** 移动商品上移 */
function moveScoreshopUp(index: number) {
  if (index <= 0 || !selected.value) return
  const data = selected.value.data || []
  const temp = data[index - 1]
  data[index - 1] = data[index]
  data[index] = temp
}

/** 移动商品下移 */
function moveScoreshopDown(index: number) {
  if (index >= (selected.value?.data?.length || 0) - 1 || !selected.value) return
  const data = selected.value.data || []
  const temp = data[index]
  data[index] = data[index + 1]
  data[index + 1] = temp
}

/** 删除商品 */
function removeScoreshopItem(index: number) {
  if (!selected.value) return
  const data = selected.value.data || []
  data.splice(index, 1)
}

/** 计算网格列数 */
function getGridColumns(params: Record<string, any>): string {
  const style = params.style || '2'
  const colsMap: Record<string, string> = {
    '1': 'repeat(1, 1fr)', // 单排
    '2': 'repeat(2, 1fr)', // 双排
    '3': 'repeat(3, 1fr)', // 三排
    '4': 'repeat(auto-fit, minmax(80px, 1fr))', // 横排
    '5': 'repeat(2, 1fr)', // 左右滑动（暂按双排处理，实际需配合 Swiper）
    '6': 'auto-fill', // 瀑布流（暂按自动填充处理）
  }
  return colsMap[style] || 'repeat(2, 1fr)'
}

// ---- 手动选择商品弹窗 ----
const prodGoodsVisible = ref(false)
const prodGoodsLoading = ref(false)
const prodGoodsList = ref<any[]>([])
const prodGoodsTotal = ref(0)
const prodGoodsQuery = reactive({ page: 1, keyword: '' })
const prodGoodsSel = ref<any[]>([])

async function fetchProdGoods() {
  prodGoodsLoading.value = true
  try {
    const data = await getGoodsList({ page: prodGoodsQuery.page, pagesize: 8, keyword: prodGoodsQuery.keyword || undefined, status: 1 })
    prodGoodsList.value = data.list || []
    prodGoodsTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    prodGoodsLoading.value = false
  }
}

function openProdGoodsDialog() {
  prodGoodsQuery.page = 1
  prodGoodsQuery.keyword = ''
  prodGoodsSel.value = [...(((selected.value?.data as any[]) || []))]
  prodGoodsVisible.value = true
  fetchProdGoods()
}

function onProdGoodsSelChange(rows: any[]) {
  prodGoodsSel.value = rows
}

function confirmProdGoods() {
  if (selected.value) {
    selected.value.data = prodGoodsSel.value.map((g) => ({ id: g.id, name: g.name, image: g.image, price: g.price }))
  }
  prodGoodsVisible.value = false
}

function removeProdItem(di: number) {
  if (selected.value && Array.isArray(selected.value.data)) selected.value.data.splice(di, 1)
}

// ============ 视频选择（任务197：独立视频选择弹窗，与图片/图标弹窗分离） ============
const videoPickerVisible = ref(false)
const videoUploading = ref(false)
const videoFileInput = ref<HTMLInputElement | null>(null)

function openVideoPicker() {
  videoPickerVisible.value = true
}

/** 从视频列表选中回写：type=1（视频ID） src=视频ID，封面顺便带回 */
function onVideoPick(v: PickedVideo) {
  if (!selected.value) return
  selected.value.params.type = '1'
  selected.value.params.src = String(v.id)
  if (v.cover) selected.value.params.pic = v.cover
}

function triggerVideoUpload() {
  videoFileInput.value?.click()
}

/** 上传视频：走现有 /admin/upload 接口，回填 URL（type=0 视频链接） */
async function onVideoFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !selected.value) return
  videoUploading.value = true
  try {
    const res = await uploadFile(file)
    selected.value.params.type = '0'
    selected.value.params.src = res.url
  } catch {
    // 错误提示已由 axios 拦截器统一弹窗
  } finally {
    videoUploading.value = false
  }
}

/** data 数组增删（banner/menu/notice/pictures 等列表型组件） */
function addDataItem(mod: DesignerModule, template: Record<string, any>) {
  if (!Array.isArray(mod.data)) mod.data = []
  mod.data.push({ ...template })
}

function removeDataItem(mod: DesignerModule, index: number) {
  if (!Array.isArray(mod.data)) return
  mod.data.splice(index, 1)
}

// ============ 优惠券设置（严格参照 yhq.png 图片及功能逻辑） ============
const couponName = ref('')
const couponType = ref('count')
const couponStock = ref(0)

// 背景样式缩略图（存储选中的图标 URL）
const bgStyleThumbs = ref(['', '', '', ''])

// 优惠券组件相关状态（商品组设置口径）
const couponPickerVisible = ref(false)
const couponIconPickerVisible = ref(false)

/** 打开优惠券多选弹窗（手动选择模式，已选券存 selected.data） */
function openCouponPicker() {
  if (!selected.value) {
    ElMessage.warning('请先选中优惠券组件')
    return
  }
  couponPickerVisible.value = true
}

/** 处理单个优惠券选择（点击选择按钮即追加，写入 selected.data） */
/** 处理单个优惠券选择（点击选择按钮即追加，写入 selected.data，对齐 canzhao 数据结构） */
function onCouponSelected(item: any) {
  if (!selected.value) return
  if (!selected.value.data) selected.value.data = []
  const couponid = item.couponid || item.id
  const existingIds = (selected.value.data || []).map((c: any) => c.couponid || c.id)
  if (existingIds.includes(couponid)) {
    ElMessage.warning('该优惠券已添加')
    return
  }
  // 对齐 canzhao pushcoupon 数据结构：生成唯一 id，保留 couponid/type/money/minprice/limit_count/name
  const gid = 'G' + Date.now() + '_' + Math.floor(Math.random() * 1000000)
  selected.value.data.push({
    id: gid,
    couponid: couponid,
    type: item.type,
    type_txt: item.type_txt,
    name: item.name,
    money: item.money,
    minprice: item.minprice || 0,
    limit_count: item.limit_count || item.value || 0,
    value: item.value,
    discount: item.discount,
    starttime: item.starttime,
    endtime: item.endtime
  })
}

/** 优惠券背景图标选择回调（写入 params.bgpic） */
function onCouponIconPicked(urls: string[]) {
  if (!selected.value || !urls[0]) return
  selected.value.params.bgpic = urls[0]
}

/** 已选优惠券上移 */
function moveCouponUp(idx: number) {
  if (!selected.value?.data || idx <= 0) return
  const arr = selected.value.data
  ;[arr[idx - 1], arr[idx]] = [arr[idx], arr[idx - 1]]
}

/** 已选优惠券下移 */
function moveCouponDown(idx: number) {
  if (!selected.value?.data) return
  const arr = selected.value.data
  if (idx >= arr.length - 1) return
  ;[arr[idx + 1], arr[idx]] = [arr[idx], arr[idx + 1]]
}

/** 移除已选优惠券 */
function removeCoupon(idx: number) {
  if (!selected.value?.data) return
  selected.value.data.splice(idx, 1)
}

/** 获取优惠券类型对应的 tag 颜色 */
function getTypeTag(type: any): string {
  const map: Record<string, string> = { '1': 'danger', '2': 'warning', '3': 'success', '4': 'info', '10': 'danger', '11': 'info' }
  return map[String(type)] || ''
}

/** 获取优惠券金额显示 */
function getCouponValue(coupon: any): string {
  if (!coupon) return '-'
  const type = String(coupon.type || coupon.coupon_type || '1')
  const val = coupon.value || coupon.coupon_value || coupon.money || coupon.deduction || 0
  if (type === '1' || type === 'cash') {
    const yuan = Number(val) > 1000 ? (Number(val) / 100).toFixed(2) : Number(val).toFixed(2)
    return '¥' + yuan
  }
  if (type === '2' || type === 'discount' || type === '10') {
    return Number(val) + '折'
  }
  if (type === '3' || type === 'count') {
    return Number(val) + '次'
  }
  return val || '-'
}



/** data 项上移一位（任务32 选项卡选项列排序按钮：交换数组项） */
function moveDataItemUp(mod: DesignerModule, index: number) {
  if (!Array.isArray(mod.data) || index <= 0 || index >= mod.data.length) return
  const [item] = mod.data.splice(index, 1)
  mod.data.splice(index - 1, 0, item)
}

// ============ 链接选择弹窗（复用 DesignerChooseUrl，任务45：代替 hrefurl 手输） ============
const chooseUrlVisible = ref(false)

function openChooseUrl(setter: (url: string, name: string) => void) {
  chooseUrlSetter = setter
  chooseUrlVisible.value = true
}

let chooseUrlSetter: ((url: string, name: string) => void) | null = null

function onChooseUrlSelect(url: string, name: string) {
  chooseUrlSetter?.(url, name)
  chooseUrlVisible.value = false
}

// ============ 通用可见性（任务45：platform 端可见 / quanxian 会员等级多选） ============
function getVis(mod: DesignerModule, key: 'platform' | 'quanxian'): Record<string, any> {
  if (!mod.params[key] || typeof mod.params[key] !== 'object') mod.params[key] = { all: true }
  return mod.params[key]
}

const platformChecked = computed<string[]>({
  get: () => {
    const p = selected.value?.params.platform
    return p && typeof p === 'object' ? Object.keys(p).filter((k) => k !== 'all' && p[k]) : []
  },
  set: (arr: string[]) => {
    const mod = selected.value
    if (!mod) return
    const p = getVis(mod, 'platform')
    for (const k of Object.keys(p)) if (k !== 'all') delete p[k]
    arr.forEach((k) => { p[k] = true })
    p.all = arr.length === 0
  },
})

const quanxianChecked = computed<string[]>({
  get: () => {
    const q = selected.value?.params.quanxian
    return q && typeof q === 'object' ? Object.keys(q).filter((k) => k !== 'all' && q[k]) : []
  },
  set: (arr: string[]) => {
    const mod = selected.value
    if (!mod) return
    const q = getVis(mod, 'quanxian')
    for (const k of Object.keys(q)) if (k !== 'all') delete q[k]
    arr.forEach((k) => { q[k] = true })
    q.all = arr.length === 0
  },
})

/** 显示平台「所有平台」联动勾选（勾选清空个别平台；个别平台有勾选时自动取消） */
const platformAll = computed<boolean>({
  get: () => platformChecked.value.length === 0,
  set: (v: boolean) => {
    if (v) platformChecked.value = []
  },
})

/** 显示范围「所有人」联动勾选（勾选清空个别等级；个别等级有勾选时自动取消） */
const quanxianAll = computed<boolean>({
  get: () => quanxianChecked.value.length === 0,
  set: (v: boolean) => {
    if (v) quanxianChecked.value = []
  },
})

// ============ 选项卡「编辑选项内容」弹窗（任务50，designerpage_tab） ============
const tabDialogVisible = ref(false)
const tabDialogLoading = ref(false)
const tabDialogSaving = ref(false)
const tabDialogMod = ref<DesignerModule | null>(null)
/** tabindexid 富文本 html（任务33：选项内容改简单富文本） */
const tabHtmlRows = ref<Record<string, string>>({})

/** 确保 tab 选项项有 id（tabindexid 口径，历史数据无 id 时补生成） */
function ensureTabItemIds(mod: DesignerModule) {
  if (!Array.isArray(mod.data)) mod.data = []
  mod.data.forEach((d) => {
    if (!d.id) d.id = genMid()
    if (d.name === undefined || d.name === null) d.name = ''
  })
}

/** 打开弹窗：逐选项调 tabGet 回显子内容（richtext 优先；旧 text 行拼 <p> 兼容） */
async function openTabDialog(mod: DesignerModule) {
  ensureTabItemIds(mod)
  tabDialogMod.value = mod
  const rows: Record<string, string> = {}
  for (const item of mod.data as Array<{ id: string; name: string }>) rows[item.id] = ''
  tabHtmlRows.value = rows
  tabDialogVisible.value = true
  tabDialogLoading.value = true
  try {
    for (const item of mod.data as Array<{ id: string; name: string }>) {
      const res = await getDesignerTabContent(mod.id, item.id)
      const list = res.content || []
      const rt = list.find((c) => c.temp === 'richtext')
      if (rt) {
        rows[item.id] = typeof rt.content === 'string' ? rt.content : ''
      } else {
        rows[item.id] = list
          .filter((c) => c.temp === 'text')
          .map((c) => `<p>${String(c.params?.content || '')}</p>`)
          .join('')
      }
    }
  } finally {
    tabDialogLoading.value = false
  }
}

/** 保存：逐选项调 tabSave 子内容（每选项一个 richtext 组件结构） */
async function saveTabDialog() {
  const mod = tabDialogMod.value
  if (!mod) return
  tabDialogSaving.value = true
  try {
    for (const item of mod.data as Array<{ id: string; name: string }>) {
      const content = [{
        id: genMid(),
        temp: 'richtext',
        params: {
          bgcolor: 'transparent',
          margin_x: '0', margin_y: '0', padding_x: '5', padding_y: '5',
        },
        content: tabHtmlRows.value[item.id] || '',
        data: [],
      }]
      await saveDesignerTabContent(mod.id, item.id, content as DesignerModule[])
    }
    ElMessage.success('选项内容已保存')
    tabDialogVisible.value = false
  } finally {
    tabDialogSaving.value = false
  }
}

// ============ 图片魔方（任务100→16宫格布局编辑器） ============
const CUBE_SIZE = 4
/** 单格建议尺寸基数（以计640口径，w*160 × h*160） */
const CUBE_UNIT = 160
/** 鍥句綅鑹叉澘锛氫笉鍚屽浘浣嶄笉鍚屾祬鑹诧紙瀵圭収鍙傝€冿級 */
const CUBE_COLORS = ['#F9E79F', '#E0A352', '#A9DF9B', '#7FBF7F', '#AED6F1', '#D7BDE2', '#F5B7B1', '#A3E4D7', '#F8C471', '#85C1E9']

function cubeSlotsOf(mod: DesignerModule): CubeSlot[] {
  if (!Array.isArray(mod.params.slots)) mod.params.slots = []
  return mod.params.slots as CubeSlot[]
}

/** (x,y) 格被哪个图位覆盖（含锚点），空返回 null */
function cubeCoverAt(mod: DesignerModule, x: number, y: number): CubeSlot | null {
  return cubeSlotsOf(mod).find((s) => x >= s.x && x < s.x + s.w && y >= s.y && y < s.y + s.h) || null
}

/** 编辑用网格单元：空格+锚点格（被覆盖格由锚点格跨格渲染，不输出） */
const cubeCells = computed(() => {
  const mod = selected.value
  if (!mod || mod.temp !== 'cube') return [] as Array<{ x: number; y: number; slot: CubeSlot | null; index: number }>
  const slots = cubeSlotsOf(mod)
  const cells: Array<{ x: number; y: number; slot: CubeSlot | null; index: number }> = []
  for (let y = 0; y < CUBE_SIZE; y++) {
    for (let x = 0; x < CUBE_SIZE; x++) {
      const cover = cubeCoverAt(mod, x, y)
      if (!cover) cells.push({ x, y, slot: null, index: -1 })
      else if (cover.x === x && cover.y === y) cells.push({ x, y, slot: cover, index: slots.indexOf(cover) })
    }
  }
  return cells
})

/** 当前选中图位下标（点击图位块→下方编辑卡） */
const cubeSlotIndex = ref(-1)
const cubeActiveSlot = computed<CubeSlot | null>(() => {
  const mod = selected.value
  if (!mod || mod.temp !== 'cube') return null
  return cubeSlotsOf(mod)[cubeSlotIndex.value] || null
})
function selectCubeSlot(index: number) {
  cubeSlotIndex.value = index
}
function removeCubeSlot() {
  const mod = selected.value
  if (!mod || mod.temp !== 'cube' || cubeSlotIndex.value < 0) return
  cubeSlotsOf(mod).splice(cubeSlotIndex.value, 1)
  cubeSlotIndex.value = -1
}
function cubeSlotColor(index: number) {
  return CUBE_COLORS[index % CUBE_COLORS.length]
}

// ---- 选择布局弹窗：锚点向右向下枚举全空矩形 ----
const cubeLayoutVisible = ref(false)
const cubeLayoutAnchor = ref<{ x: number; y: number }>({ x: 0, y: 0 })
const cubeLayoutHover = ref<{ w: number; h: number } | null>(null)

/** 可选尺寸组合（w,h 向下闭合：大矩形全空则子矩形必全空） */
const cubeLayoutOpts = computed<{ w: number; h: number }[]>(() => {
  const mod = selected.value
  if (!mod || mod.temp !== 'cube') return []
  const { x, y } = cubeLayoutAnchor.value
  const opts: { w: number; h: number }[] = []
  for (let h = 1; h <= CUBE_SIZE - y; h++) {
    for (let w = 1; w <= CUBE_SIZE - x; w++) {
      let free = true
      for (let yy = y; yy < y + h && free; yy++) {
        for (let xx = x; xx < x + w; xx++) {
          if (cubeCoverAt(mod, xx, yy)) { free = false; break }
        }
      }
      if (free) opts.push({ w, h })
      else break
    }
  }
  return opts
})
const cubeLayoutMaxW = computed(() => cubeLayoutOpts.value.reduce((m, o) => Math.max(m, o.w), 1))
const cubeLayoutMaxH = computed(() => cubeLayoutOpts.value.reduce((m, o) => Math.max(m, o.h), 1))
function cubeLayoutHas(w: number, h: number) {
  return cubeLayoutOpts.value.some((o) => o.w === w && o.h === h)
}
function openCubeLayout(x: number, y: number) {
  cubeLayoutAnchor.value = { x, y }
  cubeLayoutHover.value = { w: 1, h: 1 }
  cubeLayoutVisible.value = true
}
function confirmCubeLayout(w: number, h: number) {
  const mod = selected.value
  if (!mod || mod.temp !== 'cube') return
  const slots = cubeSlotsOf(mod)
  slots.push({ x: cubeLayoutAnchor.value.x, y: cubeLayoutAnchor.value.y, w, h, imgurl: '', hrefurl: '', hrefname: '' })
  cubeSlotIndex.value = slots.length - 1
  cubeLayoutVisible.value = false
}

/** 切换选中组件时复位编辑窗 */
watch(selectedId, () => {
  cubeSlotIndex.value = -1
  cubeLayoutVisible.value = false
})

/** 魔方画布渲染：slots 无缝绝对定位（百分比口径，适配任意容器宽） */
function cubeMaxH(mod: DesignerModule): number {
  return cubeSlotsOf(mod).reduce((m, s) => Math.max(m, s.y + s.h), 1)
}
function cubeCellStyle(s: CubeSlot) {
  return {
    left: `${s.x * 25}%`,
    top: `${s.y * 25}%`,
    width: `${s.w * 25}%`,
    height: `${s.h * 25}%`,
  }
}

/** 数值型 params 代理（滑杆数字框双向绑定，存字符串口径） */
function numParamProxy(key: string) {
  return computed({
    get: () => Number(selected.value?.params[key] || 0),
    set: (v: number) => {
      if (selected.value) selected.value.params[key] = String(v)
    },
  })
}
const cubeMarginY = numParamProxy('margin_y')
const cubeMarginX = numParamProxy('margin_x')
const cubePaddingX = numParamProxy('padding_x')
const cubePaddingY = numParamProxy('padding_y')

// ============ 轮播（任务25 对照成图重做） ============
const bnMarginY = numParamProxy('margin_y')
const bnMarginX = numParamProxy('margin_x')
const bnPaddingX = numParamProxy('padding_x')
const bnPaddingY = numParamProxy('padding_y')
const bnHeight = numParamProxy('height')
const bnRadius = numParamProxy('borderradius')
const bnDotBottom = numParamProxy('dotbottom')
const bnInterval = numParamProxy('interval')

// ============ 单图（任务26 对照成图重做） ============
const picMarginY = numParamProxy('margin_y')
const picMarginX = numParamProxy('margin_x')
const picPaddingX = numParamProxy('padding_x')
const picPaddingY = numParamProxy('padding_y')
const picRadius = numParamProxy('borderradius')

// ============ 多图（任务27 对照成图重做） ============
const pgMarginY = numParamProxy('margin_y')
const pgMarginX = numParamProxy('margin_x')
const pgPaddingX = numParamProxy('padding_x')
const pgPaddingY = numParamProxy('padding_y')

/** 多图布局（layout 优先，旧数据回退 style）：1/2/3/4 堆积列、showcase 橱窗 */
function picsLay(mod: DesignerModule): string {
  const p = (mod.params || {}) as Record<string, any>
  const l = String(p.layout ?? '')
  if (l === 'showcase' || l === '2' || l === '3' || l === '4') return l
  const s = String(p.style ?? '2')
  return s === '3' || s === '4' ? s : '2'
}

// ============ 标题（任务28 对照成图重做：单行紧凑） ============
const ttMarginY = numParamProxy('margin_y')
const ttFontsize = numParamProxy('fontsize')
const ttIconsize = numParamProxy('iconsize')
const ttMarginX = numParamProxy('margin_x')
const ttPaddingX = numParamProxy('padding_x')
const ttPaddingY = numParamProxy('padding_y')

// ============ 文本（任务31 对照成图重做） ============
const txMarginY = numParamProxy('margin_y')
const txFontsize = numParamProxy('fontsize')
const txLineheight = numParamProxy('lineheight')
const txLetterspacing = numParamProxy('letterspacing')
const txMarginX = numParamProxy('margin_x')
const txPaddingX = numParamProxy('padding_x')
const txPaddingY = numParamProxy('padding_y')

// ============ 选项卡（任务32 对照成图重做：双样式） ============
const tabMarginY = numParamProxy('margin_y')
const tabFontsize = numParamProxy('fontsize1')
const tabRadius = numParamProxy('borderradius')

// ============ 视频（对照截图重做属性面板） ============
const vidMarginY = numParamProxy('margin_y')
const vidHeight = numParamProxy('videoheight')

// ============ 按钮（对照截图重做属性面板） ============
const btnMarginY = numParamProxy('margin_y')
const btnFontsize = numParamProxy('btnfontsize')
const btnWidth = numParamProxy('btnwidth')
const btnHeight = numParamProxy('btnheight')
const btnRadius = numParamProxy('btnradius')

// ============ 列表导航（对照截图重做属性面板） ============
const dhMarginY = numParamProxy('margin_y')
const dhFontsize1 = numParamProxy('fontsize1')
const dhTitleLeft = numParamProxy('titlemarginleft')
const dhFontsize2 = numParamProxy('fontsize2')

// ============ 悬浮按钮（对照截图重做：双类型+悬浮定位，无通用设置） ============
const covFontsize = numParamProxy('fontsize')
const covPicW = numParamProxy('picwidth')
const covPicH = numParamProxy('picheight')
const covBorder = numParamProxy('border')
const covTop = numParamProxy('top')
const covLeft = numParamProxy('left')
const covWidth = numParamProxy('width')
const covHeight = numParamProxy('height')
const covRadius = numParamProxy('radius')
const shopNavH = numParamProxy('navheight')
const shopNavFont = numParamProxy('navfontsize')
const shopNavIcon = numParamProxy('naviconsize')

/** 视频画布高度：100% = 9:16 竖屏（约375 * 16/9），按 videoheight% 缩放 */
function vidHeightPx(p: Record<string, unknown>) {
  const pct = Math.min(100, Math.max(10, Number(p.videoheight) || 30))
  return Math.round(((375 * 16) / 9 * pct) / 100)
}

/** 悬浮按钮样式：top/left 百分比定位 + 宽高圆角边框（覆盖在手机区最前层） */
function covBtnStyle(p: Record<string, unknown>) {
  const s: Record<string, string> = {
    top: `${Math.min(Number(p.top ?? 80), 100)}%`,
    left: `${Math.min(Number(p.left ?? 80), 100)}%`,
    width: `${Number(p.width || 50)}px`,
    height: `${Number(p.height || 50)}px`,
    backgroundColor: (p.bgcolor as string) || '#FD4A46',
    borderRadius: `${Number(p.radius || 0)}px`,
  }
  const bw = Number(p.border || 0)
  if (bw > 0) s.border = `${bw}px solid ${(p.bordercolor as string) || '#FFFFFF'}`
  return s
}

/** 选项卡选项项样式（任务332：样式一=均分文字+下划线标识；样式二胶囊） */
function tabItemStyle(mod: DesignerModule, di: number): Record<string, string> {
  const p = (mod.params || {}) as Record<string, any>
  const fs = `${Number(p.fontsize1 || 14)}px`
  if (String(p.style ?? '1') === '2') {
    return di === 0
      ? { fontSize: fs, color: p.color2 || '#FF9900', backgroundColor: p.selectbgcolor || '#399BE5', border: `1px solid ${p.selectbordercolor || '#ffffff'}`, borderRadius: '4px', padding: '4px 0' }
      : { fontSize: fs, color: p.color1 || '#333333', backgroundColor: p.unselectbgcolor || '#E9EAEC', border: '1px solid transparent', borderRadius: '4px', padding: '4px 0' }
  }
  return { fontSize: fs, color: di === 0 ? (p.color2 || '#FF9900') : (p.color1 || '#333333') }
}

// ============ 富文本（对照重做：主流富文本编辑器 + 与上下边距 + 背景颜色） ============
const rtMarginY = numParamProxy('margin_y')
const rtEditorRef = ref<IDomEditor | null>(null)
function onRtCreated(editor: IDomEditor) {
  rtEditorRef.value = editor
}
const rtHtml = computed<string>({
  get: () => (typeof selected.value?.content === 'string' ? selected.value.content : ''),
  set: (v: string) => {
    if (selected.value) selected.value.content = v
  },
})
const rtToolbarConfig = { excludeKeys: ['fullScreen'] }
const rtEditorConfig = {
  placeholder: '请输入富文本内容',
  MENU_CONF: {
    uploadImage: {
      async customUpload(file: File, insertFn: (url: string) => void) {
        try {
          const res = await uploadFile(file)
          insertFn(res.url)
        } catch {
    ElMessage.error('图片上传失败')
        }
      },
    },
  },
}
onBeforeUnmount(() => {
  rtEditorRef.value?.destroy()
  rtEditorRef.value = null
})

/** 辅助线条渲染样式（dotted 圆点用径向渐变实现真圆点） */
function lineStyle(p: Record<string, unknown>) {
  const h = Math.max(1, Number(p.height || 2))
  const c = (p.color as string) || '#666'
  const s = (p.style as string) || 'dashed'
  if (s === 'dotted') {
    const r = Math.max(h / 2, 1)
    return {
      height: h + 'px',
      backgroundImage: `radial-gradient(circle, ${c} ${r}px, transparent ${r}px)`,
      backgroundSize: `${h * 3}px ${h}px`,
      backgroundRepeat: 'repeat-x',
      backgroundPosition: '0 center',
    }
  }
  return { borderTop: `${h}px ${s} ${c}` }
}

// ============ 公告（属性面板对照截图重做） ============
/** 字符串型 params 代理（radio 0/1 双向绑定，存字符串口径，兼容旧数值） */
function strParamProxy(key: string, def: string) {
  return computed({
    get: () => String(selected.value?.params[key] ?? def),
    set: (v: string) => {
      if (selected.value) selected.value.params[key] = v
    },
  })
}
const ntShowImg = strParamProxy('showimg', '0')
const ntShowIcon = strParamProxy('showicon', '1')

// ============ 精选（任务303：左轮播+右双图） ============
const featMarginY = numParamProxy('margin_y')
const featMarginX = numParamProxy('margin_x')
const featPaddingX = numParamProxy('padding_x')
const featPaddingY = numParamProxy('padding_y')
const featHeight = numParamProxy('height')
const featDotBottom = numParamProxy('dotbottom')
const featInterval = numParamProxy('interval')

/** 画布预览轮播当前帧（按 interval 秒自动切换） */
const featIdx = ref(0)
let featTimer: ReturnType<typeof setInterval> | null = null
function featStop() {
  if (featTimer) {
    clearInterval(featTimer)
    featTimer = null
  }
}
function featStart() {
  featStop()
  const mod = selected.value
  if (!mod || mod.temp !== 'featured') return
  const sec = Math.max(1, Number(mod.params.interval || 5))
  featTimer = setInterval(() => {
    const n = (mod.data || []).length
    if (n > 1) featIdx.value = (featIdx.value + 1) % n
  }, sec * 1000)
}
watch(selectedId, () => {
  featIdx.value = 0
  featStart()
})
watch(() => selected.value?.params.interval, () => featStart())
onBeforeUnmount(featStop)

/** 轮播帧安全下标（data 长度变化后不越界） */
function featIdxSafe(mod: DesignerModule): number {
  const n = (mod.data || []).length
  return n > 0 ? featIdx.value % n : 0
}

/** 列表项上移（精选轮播图排序） */
function moveDataItem(mod: DesignerModule, index: number, dir: -1 | 1) {
  const list = mod.data || []
  const to = index + dir
  if (to < 0 || to >= list.length) return
  const tmp = list[index]
  list[index] = list[to]
  list[to] = tmp
}

// ============ 预览样式辅助 ============
function boxStyle(params: Record<string, any>, temp?: string) {
  // 商城商品：背景色/内边距由组件内部自绘（标题样式内边距+组件样式背景），包裹层仅外边距，避免双重内边距/图片背景模式被涂底色
  if (temp === 'product') {
    return {
      marginLeft: `${Number(params.margin_x || 0)}px`,
      marginRight: `${Number(params.margin_x || 0)}px`,
      marginTop: `${Number(params.margin_y || 0)}px`,
      marginBottom: `${Number(params.margin_y || 0)}px`,
    }
  }
  return {
    backgroundColor: params.bgcolor || 'transparent',
    marginLeft: `${Number(params.margin_x || 0)}px`,
    marginRight: `${Number(params.margin_x || 0)}px`,
    marginTop: `${Number(params.margin_y || 0)}px`,
    marginBottom: `${Number(params.margin_y || 0)}px`,
    paddingLeft: `${Number(params.padding_x || 0)}px`,
    paddingRight: `${Number(params.padding_x || 0)}px`,
    paddingTop: `${Number(params.padding_y || 0)}px`,
    paddingBottom: `${Number(params.padding_y || 0)}px`,
  }
}

function nameOf(temp: string): string {
  return MODULE_NAMES[temp] || temp
}

  // 关闭：window.open 打开的标签页直接关闭
function handleClose() {
  window.close()
  setTimeout(() => ElMessage.info('浏览器拦截了自动关闭，请手动关闭本标签页'), 100)
}

// ============ 撤销/重做（任务12：模块列表历史栈，上限50，Ctrl+Z/Ctrl+Y） ============
const undoStack = ref<string[]>([])
const redoStack = ref<string[]>([])
let historyReady = false
let applyingHistory = false
let lastSnapshot = '[]'

function snapshotItems(): string {
  return JSON.stringify(items.value)
}

  // 变更即记录（push 变更前的深拷贝快照）
watch(items, () => {
  if (!historyReady || applyingHistory) return
  undoStack.value.push(lastSnapshot)
  if (undoStack.value.length > 50) undoStack.value.shift()
  redoStack.value = []
  lastSnapshot = snapshotItems()
}, { deep: true })

function applySnapshot(snap: string) {
  applyingHistory = true
  items.value = JSON.parse(snap)
  selectedId.value = ''
  void nextTick(() => {
    applyingHistory = false
    lastSnapshot = snapshotItems()
  })
}

function undo() {
  const prev = undoStack.value.pop()
  if (prev === undefined) return
  redoStack.value.push(lastSnapshot)
  applySnapshot(prev)
}

function redo() {
  const next = redoStack.value.pop()
  if (next === undefined) return
  undoStack.value.push(lastSnapshot)
  applySnapshot(next)
}

function onEditorKeydown(e: KeyboardEvent) {
  if (!(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey) return
  const key = e.key.toLowerCase()
  if (key !== 'z' && key !== 'y') return
  const t = e.target as HTMLElement | null
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return
  e.preventDefault()
  if (key === 'z') undo()
  else redo()
}

// ============ 清空（任务12：清主区域模块，保留页面标题） ============
function handleClearAll() {
  ElMessageBox.confirm('清空当前手机主区域所有设计内容，不包含页面标题', '清空确认', {
    type: 'warning',
    confirmButtonText: '确定清空',
    cancelButtonText: '取消',
  }).then(() => {
    items.value = []
    selectedId.value = ''
  }).catch(() => {})
}

// ============ 全屏（任务12：documentElement fullscreen 切换） ============
const isFullscreen = ref(false)
function toggleFullscreen() {
  if (document.fullscreenElement) {
    void document.exitFullscreen()
  } else {
    void document.documentElement.requestFullscreen()
  }
}
function onFullscreenChange() {
  isFullscreen.value = !!document.fullscreenElement
}

// ============ 背景音乐（任务12：临时解析，不入保存数据） ============
type MusicMode = 'pause' | 'mute' | 'random' | 'list' | 'loop'
const musicMode = ref<MusicMode>('list')
const musicList = ref<MusicRow[]>([])
const currentMusicId = ref(0)
const musicActive = ref(false)
let audioEl: HTMLAudioElement | null = null
let pendingResume = false

function musicSrc(url: string): string {
  if (/^https?:\/\//i.test(url)) return url
  return import.meta.env.BASE_URL.replace(/\/$/, '') + url
}

async function loadMusicList() {
  try {
    const res = await getMusicList({ page: 1, size: 100 })
    musicList.value = res.list || []
  } catch {
    musicList.value = []
  }
}

function ensureAudio(): HTMLAudioElement {
  if (!audioEl) {
    audioEl = new Audio()
    audioEl.addEventListener('ended', onMusicEnded)
  }
  return audioEl
}

function currentIndex(): number {
  return musicList.value.findIndex((m) => m.id === currentMusicId.value)
}

function playMusicAt(idx: number) {
  const n = musicList.value.length
  if (!n || musicMode.value === 'mute' || musicMode.value === 'pause') return
  const row = musicList.value[((idx % n) + n) % n]
  if (!row) return
  const el = ensureAudio()
  currentMusicId.value = row.id
  el.loop = musicMode.value === 'loop'
  el.muted = false
  el.src = musicSrc(row.url)
  musicActive.value = true
  el.play().then(() => {
    pendingResume = false
  }).catch(() => {
  // 浏览器拦截 autoplay：静默降级，首次交互后恢复
    pendingResume = true
  })
}

function onMusicEnded() {
  const n = musicList.value.length
  if (!n || musicMode.value === 'mute' || musicMode.value === 'pause') return
  if (musicMode.value === 'loop') return // loop=true 已自动重播，无需额外处理
  if (musicMode.value === 'random') {
    playMusicAt(Math.floor(Math.random() * n))
    return
  }
  playMusicAt(currentIndex() + 1)
}

function onMusicModeChange(v: string | number | boolean) {
  const mode = String(v) as MusicMode
  musicMode.value = mode
  const el = audioEl
// 任务17：暂停档——仅 audio.pause() 保留进度，切回任一播放档即恢复
  if (mode === 'pause') {
    musicActive.value = false
    if (el) el.pause()
    return
  }
  if (mode === 'mute') {
    musicActive.value = false
    if (el) {
      el.pause()
      el.muted = true
    }
    return
  }
  if (el) el.loop = mode === 'loop'
  if (!musicList.value.length) return
  if (el && el.paused && el.src) {
// 从暂停进度恢复播放
    musicActive.value = true
    el.muted = false
    el.play().then(() => {
      pendingResume = false
    }).catch(() => {
      pendingResume = true
    })
    return
  }
  if (!el) {
    const idx = currentIndex()
    playMusicAt(mode === 'random' ? Math.floor(Math.random() * musicList.value.length) : (idx >= 0 ? idx : 0))
  }
}

function clickMusic(row: MusicRow) {
  if (musicMode.value === 'mute' || musicMode.value === 'pause') musicMode.value = 'list'
  const idx = musicList.value.findIndex((m) => m.id === row.id)
  if (idx >= 0) playMusicAt(idx)
}

function onFirstInteraction() {
  if (pendingResume && musicMode.value !== 'mute' && musicMode.value !== 'pause' && audioEl) {
    pendingResume = false
    audioEl.play().catch(() => {})
  }
}

onMounted(() => {
  loadPage().then(() => {
    lastSnapshot = snapshotItems()
    historyReady = true
  })
// 任务09：拉取系统导航主题色，画布顶栏「跟随系统」用系统导航色
  void loadSysTheme()
// 任务12：快捷键/全屏/音乐自动播放（默认列表播放从第一首开始）
  window.addEventListener('keydown', onEditorKeydown)
  document.addEventListener('fullscreenchange', onFullscreenChange)
  window.addEventListener('pointerdown', onFirstInteraction)
  void loadMusicList().then(() => {
    if (musicList.value.length) playMusicAt(0)
  })
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onEditorKeydown)
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  window.removeEventListener('pointerdown', onFirstInteraction)
  if (audioEl) {
    audioEl.pause()
    audioEl.removeEventListener('ended', onMusicEnded)
    audioEl = null
  }
})

// ============ 优惠券操作（暴露到模板） ============
defineExpose({ openCouponPicker, moveCouponUp, moveCouponDown, removeCoupon })
</script>

<template>
  <div class="de-page" v-if="loaded">
    <!-- 顶部工具栏 -->
    <div class="de-topbar">
      <div class="de-topbar-left">
        <span class="de-logo">设计器</span>
        <el-input v-model="pageTitle" class="de-title-input" placeholder="请输入页面标题" maxlength="30" show-word-limit />
        <!-- 任务16：ID 徽标移至标题框紧后面（撤销图标左侧） -->
        <el-tag v-if="pageId" type="success" size="small" class="de-id-badge">ID: {{ pageId }}</el-tag>
        <el-tag v-else type="info" size="small" class="de-id-badge">未保存</el-tag>
        <div class="de-top-actions">
          <el-tooltip content="撤销 (Ctrl+Z)" placement="bottom">
            <el-button size="small" circle :disabled="!undoStack.length" @click="undo">↶</el-button>
          </el-tooltip>
          <el-tooltip content="重做 (Ctrl+Y)" placement="bottom">
            <el-button size="small" circle :disabled="!redoStack.length" @click="redo">↷</el-button>
          </el-tooltip>
          <el-tooltip content="清空主区域" placement="bottom">
            <el-button size="small" circle :disabled="!items.length" @click="handleClearAll">🗑</el-button>
          </el-tooltip>
          <el-tooltip :content="isFullscreen ? '退出全屏' : '全屏'" placement="bottom">
            <el-button size="small" circle :type="isFullscreen ? 'primary' : ''" @click="toggleFullscreen">⛶</el-button>
          </el-tooltip>
          <!-- 任务16：reference 内不得嵌套 el-tooltip（tooltip 内的 trigger 会吞掉 click，popover 不弹），按钮直挂 -->
          <el-popover trigger="click" :width="280" placement="bottom-start">
            <template #reference>
              <el-button size="small" circle :type="musicActive ? 'primary' : ''" title="背景音乐">🎵</el-button>
            </template>
            <div class="de-music-panel">
              <div class="de-music-mode">
                <el-radio-group :model-value="musicMode" size="small" @update:model-value="onMusicModeChange">
                  <!-- 任务17：暂停档置空，选它即 audio.pause()，切回播放档恢复 -->
                  <el-radio value="pause">暂停</el-radio>
                  <el-radio value="mute">静音</el-radio>
                  <el-radio value="random">自动随机播放</el-radio>
                  <el-radio value="list">自动列表播放</el-radio>
                  <el-radio value="loop">单曲循环</el-radio>
                </el-radio-group>
              </div>
              <div class="de-music-list">
                <div v-if="!musicList.length" class="de-music-empty">暂无音乐，到「控制台 → 音乐控制」添加</div>
                <div
                  v-for="m in musicList"
                  :key="m.id"
                  class="de-music-item"
                  :class="{ active: m.id === currentMusicId }"
                  @click="clickMusic(m)"
                >
                  <span class="de-music-ico">{{ m.id === currentMusicId && musicActive ? '⏸' : '▶' }}</span>
                  <span class="de-music-name">{{ m.name }}</span>
                </div>
              </div>
            </div>
          </el-popover>
        </div>
      </div>
      <div class="de-topbar-right">
        <el-button @click="previewVisible = true">预览</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存并发布</el-button>
        <el-button type="danger" plain @click="handleClose">关闭</el-button>
      </div>
    </div>

    <div class="de-body">
      <!-- 左：组件面板（任务299：三分类手风琴 + 三列卡片 + 搜索） -->
      <div class="de-left">
        <div class="de-panel-title">组件库</div>
        <div class="de-left-search">
          <el-input v-model="catKeyword" size="small" placeholder="搜索功能组件" clearable />
        </div>
        <div class="de-left-tip">点击组件添加到页面</div>

        <!-- 搜索态：跨三类独立结果区 -->
        <div v-if="catKeyword.trim()" class="de-cat">
          <div class="de-cat-head de-cat-head--static">搜索结果({{ searchHits.length }})</div>
          <div v-if="searchHits.length" class="de-menu-grid">
            <div
              v-for="m in searchHits"
              :key="m.temp"
              class="de-menu-card"
              :title="m.desc"
              @click="addModule(m.temp)"
            >
              <span class="de-menu-card-icon">{{ m.icon }}</span>
              <span class="de-menu-card-name">{{ m.name }}</span>
            </div>
          </div>
          <div v-else class="de-left-empty">未找到匹配组件</div>
        </div>

        <!-- 默认态：三分类手风琴 -->
        <template v-else>
          <div v-for="g in catGroups" :key="g.key" class="de-cat">
            <div class="de-cat-head" @click="toggleCat(g.key)">
              <span class="de-cat-arrow" :class="{ open: expandedCat === g.key }">▶</span>
              <span>{{ g.label }}({{ g.items.length }})</span>
            </div>
            <div v-show="expandedCat === g.key" class="de-menu-grid">
              <div
                v-for="m in g.items"
                :key="m.temp"
                class="de-menu-card"
                :title="m.desc"
                @click="addModule(m.temp)"
              >
                <span class="de-menu-card-icon">{{ m.icon }}</span>
                <span class="de-menu-card-name">{{ m.name }}</span>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- 中：手机预览画布 -->
      <div class="de-center">
        <div class="de-phone">
          <!-- topbar 预览（页面标题+导航色，点击可编辑页面设置） -->
          <div
            class="de-canvas-topbar"
            :class="{ active: selectedId === topbar.id }"
            :style="{ backgroundColor: canvasNavBg }"
            @click.stop="selectedId = topbar.id"
          >
            <span class="de-canvas-topbar-title" :style="{ color: canvasNavText }">{{ pageTitle || '页面标题' }}</span>
            <span v-if="topbar.params.needlogin === '1'" class="de-canvas-topbar-lock">🔒 需登录</span>
          </div>

          <!-- 组件区（任务07：主区域背景=页面背景色，与导航栏分离） -->
          <div class="de-phone-body" :style="{ background: canvasPageBg }">
          <div v-if="items.length === 0" class="de-canvas-empty">
            从左侧「组件库」点击组件添加到页面
          </div>

          <div
            v-for="(mod, index) in items"
            :key="mod.id"
            class="de-canvas-item"
            :class="{ active: selectedId === mod.id }"
            @click.stop="selectedId = mod.id"
          >
            <!-- 悬停操作条 -->
            <div class="de-canvas-tools">
              <span class="de-canvas-tools-name">{{ nameOf(mod.temp) }}</span>
              <span class="de-tool" title="上移" :class="{ disabled: index === 0 }" @click.stop="moveUp(mod.id)">↑</span>
              <span class="de-tool" title="下移" :class="{ disabled: index === items.length - 1 }" @click.stop="moveDown(mod.id)">↓</span>
              <span class="de-tool danger" title="删除" @click.stop="removeModule(mod.id)">✕</span>
            </div>

            <!-- 简版预览（按 temp 分发） -->
            <div :style="boxStyle(mod.params, mod.temp)">
              <!-- 轮播图（任务25：属性真实渲染） -->
              <template v-if="mod.temp === 'banner'">
                <div class="pv-banner" :style="{ height: `${Number(mod.params.height || 200) / 2}px`, borderRadius: `${mod.params.borderradius || 0}px`, backgroundColor: mod.params.bgcolor || '#f2f3f5' }">
                  <img v-if="mod.data?.length && mod.data[0].imgurl" :src="mod.data[0].imgurl" />
                  <span v-else class="pv-placeholder">轮播图（{{ mod.data?.length || 0 }} 张）</span>
                  <div v-if="mod.params.indicatordots === '1' && (mod.data?.length || 0) > 0" class="pv-banner-dots" :class="[`pos-${mod.params.dotposition || 'center'}`, `shape-${mod.params.dotshape || '0'}`]" :style="{ bottom: `${Number(mod.params.dotbottom || 8) / 2}px` }">
                    <i v-for="di in (mod.data?.length || 0)" :key="di" :style="{ backgroundColor: di === 1 ? (mod.params.dotactivecolor || '#3DB51E') : (mod.params.dotcolor || '#EDEEF0') }"></i>
                  </div>
                </div>
              </template>

              <!-- 搜索框 -->
              <template v-else-if="mod.temp === 'search'">
                <div class="pv-search" :style="{ backgroundColor: mod.params.bgcolor, borderRadius: `${mod.params.borderradius || 5}px` }">
                  <span class="pv-search-icon">🔍</span>
                  <span :style="{ color: mod.params.color }">{{ mod.params.placeholder || '搜索' }}</span>
                </div>
              </template>

              <!-- 公告 -->
              <template v-else-if="mod.temp === 'notice'">
                <div class="pv-notice" :style="{ color: mod.params.color, fontSize: `${mod.params.fontsize || 14}px` }">
                  <img v-if="String(mod.params.showimg) === '1' && mod.params.img" class="pv-notice-img" :src="mod.params.img" />
                  <img v-if="String(mod.params.showicon) === '1' && mod.params.icon" class="pv-notice-icon-img" :src="mod.params.icon" />
                  <span v-else-if="String(mod.params.showicon) === '1'" class="pv-notice-icon">📢</span>
                  <span>{{ mod.data?.[0]?.title || '暂无公告' }}</span>
                </div>
              </template>

              <!-- 宫格导航 -->
              <template v-else-if="mod.temp === 'menu'">
                <div class="pv-menu" :style="{ gridTemplateColumns: `repeat(${mod.params.pernum || 5}, 1fr)` }">
                  <div v-for="(d, di) in mod.data || []" :key="di" class="pv-menu-item">
                    <img v-if="d.imgurl" :src="d.imgurl" :style="{ width: `${mod.params.iconsize || 40}px`, height: `${mod.params.iconsize || 40}px` }" />
                    <span v-else class="pv-menu-icon-ph" :style="{ width: `${mod.params.iconsize || 40}px`, height: `${mod.params.iconsize || 40}px` }">{{ di + 1 }}</span>
                    <em v-if="mod.params.showtitle === '1'" :style="{ fontSize: `${mod.params.fontsize || 14}px` }">{{ d.title || '菜单' }}</em>
                  </div>
                </div>
              </template>

              <!-- 单图（任务26：图片纵向堆叠真实渲染） -->
              <template v-else-if="mod.temp === 'picture'">
                <div class="pv-picture" :style="{ borderRadius: `${mod.params.borderradius || 0}px` }">
                  <template v-for="(d, di) in mod.data || []" :key="di">
                    <img v-if="d.imgurl" :src="d.imgurl" />
                  </template>
                  <span v-if="!(mod.data || []).some((d: any) => d && d.imgurl)" class="pv-placeholder">单图</span>
                </div>
              </template>

              <!-- 多图（任务27：layout 布局真实渲染） -->
              <template v-else-if="mod.temp === 'pictures'">
                <div class="pv-pictures" :class="[`lay-${picsLay(mod)}`]">
                  <div v-for="(d, di) in mod.data || []" :key="di" class="pv-pictures-item" :style="{ borderRadius: `${mod.params.imageRadius || 0}px` }">
                    <img v-if="d.imgurl" :src="d.imgurl" />
                  <span v-else class="pv-placeholder sm">图{{ di + 1 }}</span>
                  </div>
                </div>
              </template>

              <!-- 标题（任务28：居中左右装饰样式+图标） -->
              <template v-else-if="mod.temp === 'title'">
                <div class="pv-title" :style="{ ...boxStyle(mod.params), backgroundColor: mod.params.bgcolor || '#F6F6F6', color: mod.params.color || '#333', fontSize: `${mod.params.fontsize || 14}px` }">
                  <i class="pv-title-line" :style="{ borderColor: mod.params.linecolor || '#DDDDDD' }"></i>
                  <img v-if="String(mod.params.showicon ?? '1') === '1' && mod.params.imgurl" class="pv-title-icon" :src="mod.params.imgurl" :style="{ width: `${mod.params.iconsize || 14}px`, height: `${mod.params.iconsize || 14}px` }" />
                  <span>{{ mod.params.title || '标题' }}</span>
                  <i class="pv-title-line" :style="{ borderColor: mod.params.linecolor || '#DDDDDD' }"></i>
                </div>
              </template>

              <!-- 文本（任务31：对照截图重做） -->
              <template v-else-if="mod.temp === 'text'">
                <div class="pv-text" :style="{ ...boxStyle(mod.params), backgroundColor: mod.params.bgcolor || '#FFFFFF', color: mod.params.color || '#000', fontSize: `${mod.params.fontsize || 14}px`, lineHeight: `${mod.params.lineheight || 20}px`, letterSpacing: `${mod.params.letterspacing || 0}px`, fontWeight: String(mod.params.isbold ?? mod.params.fontbold ?? '0') !== '0' ? 'bold' : 'normal', backgroundImage: mod.params.bgimg ? `url(${mod.params.bgimg})` : '', backgroundSize: 'cover', textAlign: mod.params.align || 'left' }">
                  {{ mod.params.content || '文本内容' }}
                </div>
              </template>

              <!-- 富文本 -->
              <template v-else-if="mod.temp === 'richtext'">
                <div class="pv-richtext" v-if="mod.content" v-html="mod.content"></div>
                <div class="pv-placeholder-box" v-else>富文本内容</div>
              </template>

              <!-- 辅助空白 -->
              <template v-else-if="mod.temp === 'blank'">
                <div class="pv-blank" :style="{ height: `${mod.params.height || 50}px` }"></div>
              </template>

              <!-- 辅助线 -->
              <template v-else-if="mod.temp === 'line'">
                <div class="pv-line" :style="lineStyle(mod.params)"></div>
              </template>

              <!-- 商城商品（任务 302：标题栏 + 六种布局预览） -->
              <template v-else-if="mod.temp === 'product'">
                <div class="pv-product-box" :style="{ backgroundColor: mod.params.bgmode === '1' ? undefined : (mod.params.bgcolor || '#FFFFFF'), borderRadius: `${mod.params.borderradius || 0}px`, backgroundImage: mod.params.bgmode === '1' && mod.params.bgimg ? `url(${mod.params.bgimg})` : undefined, backgroundSize: 'cover', backgroundPosition: 'center' }">
                  <div v-if="mod.params.titleshow === '1'" class="pv-product-head" :style="{ padding: `${mod.params.padding_y || 8}px ${mod.params.padding_x || 8}px 0` }">
                    <b :style="{ fontSize: `${mod.params.titlefontsize || 14}px`, color: mod.params.titlecolor || '#000000' }">{{ mod.params.titletext || '商城商品' }}</b>
                    <span :style="{ fontSize: `${mod.params.notefontsize || 12}px`, color: mod.params.notecolor || '#999999' }">{{ mod.params.notetext || '查看更多' }}<i v-if="mod.params.arrowshow === '1'" class="pv-product-arrow">›</i></span>
                  </div>
                  <div class="pv-product" :class="[`lay-${mod.params.style || '2'}`]" :style="{ padding: `${mod.params.pro_margin_y || 0}px ${mod.params.pro_margin_x || 0}px` }">
              <!-- 有手动选品时取 data，否则用示例兜底（最多显示 4 个） -->
                    <div v-for="(item, di) in (mod.data || []).slice(0, 4)" :key="di" class="pv-product-item" :style="{ borderRadius: `${mod.params.proradius || 5}px` }">
                      <div class="pv-product-img" :style="{ borderRadius: `${mod.params.imgradius || 0}px` }">
                        <img v-if="mod.params.badgeimg" :src="mod.params.badgeimg" class="pv-product-badgeimg" />
                        <span v-else-if="mod.params.probadge !== '0'" class="pv-product-badge">{{ prodBadgeText(mod.params) }}</span>
                        <img v-else-if="item.image" :src="item.image" class="pv-product-example" />
                      </div>
                      <div v-if="mod.params.nameshow === '1'" class="pv-product-name" :style="{ fontSize: `${mod.params.namefontsize || 16}px`, color: mod.params.namecolor || '#000000', fontWeight: mod.params.namebold === '1' ? '600' : 'normal' }">{{ item.name || '商品名称示例' }}</div>
                      <div class="pv-product-foot">
                        <span v-if="mod.params.priceshow !== '0'" class="pv-product-price" :style="{ color: prodPriceColor(mod.params), fontWeight: mod.params.pricebold === '1' ? '600' : 'normal' }">¥{{ Number(item.price || 19.00).toFixed(2) }}<s v-if="mod.params.priceshow === '1'" class="pv-product-market">¥{{ Number(item.market_price || item.sell_price || 29.00).toFixed(2) }}</s></span>
                        <span v-if="mod.params.salesshow === '1'" class="pv-product-sales" :style="{ color: mod.params.salescolor || '#999999', fontWeight: mod.params.salesbold === '1' ? '600' : 'normal' }">销量{{ item.sales || 0 }}</span>
                        <img v-if="mod.params.buybtnshow === '2' && mod.params.buybtnimg" :src="mod.params.buybtnimg" class="pv-product-buyimg" />
                        <span v-else-if="mod.params.buybtnshow === '1'" class="pv-product-cart" :style="{ backgroundColor: prodPriceColor(mod.params) }">🛒</span>
                      </div>
                    </div>
              <!-- 空数据时用示例填充到 4 个 -->
                    <template v-if="!(mod.data || []).length">
                      <div v-for="n in 4" :key="n" class="pv-product-item" :style="{ borderRadius: `${mod.params.proradius || 5}px` }">
                        <div class="pv-product-img" :style="{ borderRadius: `${mod.params.imgradius || 0}px` }">
                          <img v-if="mod.params.badgeimg" :src="mod.params.badgeimg" class="pv-product-badgeimg" />
                          <span v-else-if="mod.params.probadge !== '0'" class="pv-product-badge">{{ prodBadgeText(mod.params) }}</span>
                        </div>
                        <div v-if="mod.params.nameshow === '1'" class="pv-product-name" :style="{ fontSize: `${mod.params.namefontsize || 16}px`, color: mod.params.namecolor || '#000000', fontWeight: mod.params.namebold === '1' ? '600' : 'normal' }">商品名称示例</div>
                        <div class="pv-product-foot">
                          <span v-if="mod.params.priceshow !== '0'" class="pv-product-price" :style="{ color: prodPriceColor(mod.params), fontWeight: mod.params.pricebold === '1' ? '600' : 'normal' }">¥19.00<s v-if="mod.params.priceshow === '1'">¥29.00</s></span>
                          <span v-if="mod.params.salesshow === '1'" class="pv-product-sales" :style="{ color: mod.params.salescolor || '#999999', fontWeight: mod.params.salesbold === '1' ? '600' : 'normal' }">销量 26</span>
                          <img v-if="mod.params.buybtnshow === '2' && mod.params.buybtnimg" :src="mod.params.buybtnimg" class="pv-product-buyimg" />
                          <span v-else-if="mod.params.buybtnshow === '1'" class="pv-product-cart" :style="{ backgroundColor: prodPriceColor(mod.params) }">🛒</span>
                        </div>
                      </div>
                    </template>
                  </div>
                </div>
              </template>

              <!-- 积分兑换（六种布局：单排/双排/三排/横排/左右滑动/瀑布流） -->
              <template v-else-if="mod.temp === 'scoreshop'">
                <div class="pv-scoreshop-box" :style="{ backgroundColor: mod.params.backgroundtype === '2' ? undefined : (mod.params.bgcolor || '#FFFFFF'), borderRadius: `${mod.params.roundness || 0}px`, backgroundImage: mod.params.backgroundtype === '2' && mod.params.imagebgurl ? `url(${mod.params.imagebgurl})` : undefined, backgroundSize: 'cover', backgroundPosition: 'center', padding: `${mod.params.sidemargin || 0}px ${mod.params.padding_x || 8}px}`, marginTop: `${mod.params.margin_y || 0}px` }">
                  <div v-if="mod.params.titleshow === '1'" class="pv-scoreshop-head" :style="{ padding: `${mod.params.padding_y || 8}px ${mod.params.padding_x || 8}px 0` }">
                    <b :style="{ fontSize: `${mod.params.titlefontsize || 14}px`, color: mod.params.titlecolor || '#000000' }">{{ mod.params.titletext || '积分兑换' }}</b>
                    <span style="float: right;" :style="{ fontSize: `${mod.params.notefontsize || 12}px`, color: mod.params.notecolor || '#999999' }">{{ mod.params.notetext || '查看更多'}}<i v-if="mod.params.arrowshow === '1'" class="pv-scoreshop-arrow">›</i></span>
                  </div>
                  <!-- 根据 style 参数选择不同布局 -->
                  <div :class="[`pv-scoreshop-layout`, `lay-${mod.params.style || '2'}`]" :style="{ padding: `${mod.params.margin_y || 0}px ${mod.params.margin_x || 0}px`, gridTemplateColumns: getGridColumns(mod.params) }">
                    <!-- 手动选品时显示所有选中的商品 -->
                    <template v-if="(mod.data || []).length > 0">
                      <div v-for="(item, di) in mod.data" :key="di" class="pv-scoreshop-item">
                        <div class="pv-scoreshop-img" :style="{ borderRadius: `${mod.params.imgradius || 0}px` }">
                          <img v-if="item.image" :src="item.image" />
                        </div>
                        <div v-if="mod.params.productnameshow === '1'" class="pv-scoreshop-name" :style="{ fontSize: `${mod.params.productnamefontsize || 16}px`, color: mod.params.productnamecolor || '#000000', fontWeight: mod.params.productnamebold || 'normal' }">{{ item.name || '积分兑换商品' }}</div>
                        <div class="pv-scoreshop-foot">
                          <span v-if="mod.params.priceshow === '2'" class="pv-scoreshop-price-integral" :style="{ color: mod.params.pricecolortype === '2' && mod.params.pricecustomcolor || mod.params.pricecolor || '#FF4400', fontWeight: mod.params.pricebold || 'normal' }">{{ item.score || '100' }}积分</span>
                          <span v-else-if="mod.params.priceshow === '1'" class="pv-scoreshop-price-all" :style="{ color: mod.params.pricecolortype === '2' && mod.params.pricecustomcolor || mod.params.pricecolor || '#FF4400', fontWeight: mod.params.pricebold || 'normal' }">市场价¥{{ item.market_price || '29.00' }} + {{ item.score || '100' }}积分</span>
                          <span v-else-if="mod.params.priceshow === '3'" class="pv-scoreshop-sales" :style="{ color: mod.params.salecolor || '#999999', fontWeight: mod.params.salebold || 'normal' }">销量{{ item.sales || 0 }}</span>
                        </div>
                      </div>
                    </template>
                    <!-- 空数据时用示例填充到 4 个 -->
                    <template v-else>
                      <div v-for="n in 4" :key="n" class="pv-scoreshop-item">
                        <div class="pv-scoreshop-img" :style="{ borderRadius: `${mod.params.imgradius || 0}px` }">
                          <span class="pv-placeholder">图片</span>
                        </div>
                        <div v-if="mod.params.productnameshow === '1'" class="pv-scoreshop-name" :style="{ fontSize: `${mod.params.productnamefontsize || 16}px`, color: mod.params.productnamecolor || '#000000', fontWeight: mod.params.productnamebold || 'normal' }">积分兑换商品示例</div>
                        <div class="pv-scoreshop-foot">
                          <span v-if="mod.params.priceshow === '2'" class="pv-scoreshop-price-integral" :style="{ color: mod.params.pricecolortype === '2' && mod.params.pricecustomcolor || mod.params.pricecolor || '#FF4400', fontWeight: mod.params.pricebold || 'normal' }">100 积分</span>
                          <span v-else-if="mod.params.priceshow === '1'" class="pv-scoreshop-price-all" :style="{ color: mod.params.pricecolortype === '2' && mod.params.pricecustomcolor || mod.params.pricecolor || '#FF4400', fontWeight: mod.params.pricebold || 'normal' }">市场价¥29.00 + 100 积分</span>
                          <span v-else-if="mod.params.priceshow === '3'" class="pv-scoreshop-sales" :style="{ color: mod.params.salecolor || '#999999', fontWeight: mod.params.salebold || 'normal' }">销量 10</span>
                        </div>
                      </div>
                    </template>
                  </div>
                </div>
              </template>

              <!-- TODO: 新增优惠券组件属性面板 -->

              <!-- 选项卡（任务32 双样式） -->
              <template v-else-if="mod.temp === 'tab'">
                <div class="pv-tab" :style="{ borderRadius: `${mod.params.borderradius || 0}px`, overflow: 'hidden' }">
                  <div class="pv-tab-head" :class="{ s2: mod.params.style === '2' }" :style="mod.params.style === '2' ? { backgroundColor: 'transparent' } : { backgroundColor: mod.params.bgcolor || '#F7F7F8' }">
                    <span
                      v-for="(d, di) in mod.data || []"
                      :key="di"
                      class="pv-tab-item"
                      :class="{ s2: mod.params.style === '2', on: di === 0 }"
                      :style="tabItemStyle(mod, di)"
                    >
                      {{ d.name || `选项${di + 1}` }}
                      <i v-if="di === 0 && mod.params.style !== '2' && mod.params.arrowshow === '1'" :style="{ backgroundColor: mod.params.arrowcolor || '#FF9900' }"></i>
                    </span>
                  </div>
                  <div class="pv-tab-body" :style="{ backgroundColor: mod.params.bgcolor2 || '#FFFFFF' }">选项卡内容区域</div>
                </div>
              </template>

              <!-- 视频（任务45） -->
              <template v-else-if="mod.temp === 'video'">
                <div class="pv-video" :style="{ height: vidHeightPx(mod.params) + 'px' }">
                  <img v-if="mod.params.pic" :src="mod.params.pic" />
                  <span class="pv-video-play">▶</span>
                  <span v-if="!mod.params.pic" class="pv-placeholder">视频</span>
                </div>
              </template>

              <!-- 按钮（任务45） -->
              <template v-else-if="mod.temp === 'button'">
                <div class="pv-button">
                  <span
                    :style="{ width: `${mod.params.btnwidth || 120}px`, height: `${mod.params.btnheight || 40}px`, backgroundColor: mod.params.btnbgcolor || '#FD4A46', color: mod.params.btncolor || '#FFFFFF', fontSize: `${mod.params.btnfontsize || 14}px`, borderRadius: `${mod.params.btnradius || 0}px`, border: '1px solid ' + (mod.params.btnbordercolor || 'transparent') }"
                  >{{ mod.params.btntext || '按钮' }}</span>
                </div>
              </template>

              <!-- 列表导航（任务45） -->
              <template v-else-if="mod.temp === 'dhlist'">
                <div class="pv-dhlist" :style="{ backgroundColor: mod.params.bgcolor || '#FFFFFF' }">
                  <div v-for="(d, di) in mod.data || []" :key="di" class="pv-dhlist-item">
                    <span v-if="d.showicon === '1'" class="pv-dhlist-icon" :style="{ width: `${d.iconsize || 24}px`, height: `${d.iconsize || 24}px` }">
                      <img v-if="d.imgurl" :src="d.imgurl" />
                    </span>
                    <div class="pv-dhlist-txt">
                      <b :style="{ fontSize: `${mod.params.fontsize1 || 15}px`, color: mod.params.color1 || '#333333', marginLeft: `${mod.params.titlemarginleft || 0}px` }">{{ d.title1 || '标题' }}</b>
                      <em :style="{ fontSize: `${mod.params.fontsize2 || 12}px`, color: mod.params.color2 || '#999999' }">{{ d.title2 || '' }}</em>
                    </div>
                    <i v-if="mod.params.arrowshow === '1'" class="pv-dhlist-arrow">›</i>
                  </div>
                </div>
              </template>


              <!-- 地图（任务45） -->
              <template v-else-if="mod.temp === 'map'">
                <div class="pv-map" :style="{ height: `${mod.params.height || 200}px` }">
                  <span class="pv-map-pin">📍</span>
                  <span class="pv-map-addr">{{ mod.params.address || '地图地址' }}</span>
                </div>
              </template>

              <!-- 热区（任务45） -->
              <template v-else-if="mod.temp === 'hotspot'">
                <div class="pv-hotspot">
                  <img v-if="mod.params.imgurl" :src="mod.params.imgurl" />
                  <span v-else class="pv-placeholder">热区背景</span>
                  <div
                    v-for="(s, si) in mod.data || []"
                    :key="si"
                    class="pv-hotspot-area"
                    :style="{ width: `${s.width || 80}px`, height: `${s.height || 80}px`, left: `${s.left || 0}px`, top: `${s.top || 0}px` }"
                  ></div>
                </div>
              </template>

              <!-- 图片魔方（任务 100：slots 无缝布局） -->
              <template v-else-if="mod.temp === 'cube'">
                <div v-if="cubeSlotsOf(mod).length" class="pv-cube-box" :style="{ aspectRatio: `4 / ${cubeMaxH(mod)}` }">
                  <div v-for="(s, si) in cubeSlotsOf(mod)" :key="si" class="pv-cube-cell" :style="cubeCellStyle(s)">
                    <img v-if="s.imgurl" :src="s.imgurl" />
                  </div>
                </div>
                <div v-else class="pv-cube-none">未布置图位</div>
              </template>
              <!-- 优惠券（商品组设置属性面板） -->
              <template v-else-if="mod.temp === 'coupon'">
                <div v-if="(mod.data || []).length > 0" class="pv-coupon-list">
                  <div v-for="(coupon, idx) in mod.data" :key="coupon.id || idx" class="pv-coupon" :style="mod.params.bgpic ? { backgroundImage: `url(${mod.params.bgpic})`, backgroundSize: '100% 100%', backgroundRepeat: 'no-repeat' } : {}">
                    <!-- 左侧：type==1 显示金额，type!=1 显示名称 -->
                    <div class="pv-coupon-left" :style="{ color: mod.params.titlecolor || '#FFFFFF' }">
                      <template v-if="String(coupon.type) === '1'">
                        <b>¥{{ coupon.money }}</b>
                        <span style="font-size:10px; margin-top:2px; display:block;">优惠券</span>
                      </template>
                      <template v-else>
                        <b style="font-size:14px;">{{ coupon.name }}</b>
                      </template>
                    </div>
                    <!-- 右侧：type==1 显示使用条件，type!=1 显示类型 -->
                    <div class="pv-coupon-right">
                      <template v-if="String(coupon.type) === '1'">
                        <span class="pv-coupon-name" :style="{ color: mod.params.remarkcolor || '#E5E5E5' }">{{ coupon.minprice > 0 ? '满' + coupon.minprice + '元可用' : '无门槛' }}</span>
                      </template>
                      <template v-else>
                        <span class="pv-coupon-name" :style="{ color: mod.params.remarkcolor || '#E5E5E5' }">{{ coupon.type_txt || coupon.type_name }}</span>
                      </template>
                    </div>
                  </div>
                </div>
                <div v-else-if="mod.params.couponfrom === '1'" class="pv-coupon-list">

                  <div v-for="n in Math.min(Number(mod.params.shownum) || 4, 4)" :key="'auto-'+n" class="pv-coupon" :style="mod.params.bgpic ? { backgroundImage: `url(${mod.params.bgpic})`, backgroundSize: '100% 100%', backgroundRepeat: 'no-repeat' } : {}">

                    <div class="pv-coupon-left" :style="{ color: mod.params.titlecolor || '#FFFFFF' }">

                      <b>¥{{ 10 * n }}</b>

                      <span style="font-size:10px; margin-top:2px; display:block;">优惠券</span>

                    </div>

                    <div class="pv-coupon-right">

                      <span class="pv-coupon-name" :style="{ color: mod.params.titlecolor || '#FFFFFF' }">自动获取优惠券{{ n }}</span>

                      <span class="pv-coupon-btn" :style="{ color: mod.params.remarkcolor || '#E5E5E5' }">领取</span>

                    </div>

                  </div>

                </div>

                <div v-else class="pv-coupon-list">

                  <div class="pv-coupon" :style="mod.params.bgpic ? { backgroundImage: `url(${mod.params.bgpic})`, backgroundSize: '100% 100%', backgroundRepeat: 'no-repeat' } : {}">

                    <div class="pv-coupon-left" :style="{ color: mod.params.titlecolor || '#FFFFFF' }">

                      <b>--</b>

                      <span style="font-size:10px; margin-top:2px; display:block;">优惠券</span>

                    </div>

                    <div class="pv-coupon-right">

                      <span class="pv-coupon-name" :style="{ color: mod.params.titlecolor || '#FFFFFF' }">请添加优惠券</span>

                      <span class="pv-coupon-btn" :style="{ color: mod.params.remarkcolor || '#E5E5E5' }">领取</span>

                    </div>

                  </div>

                </div>
              </template>

              <!-- 店招（任务02：背景图+logo+店铺名+底部导航栏） -->
              <template v-else-if="mod.temp === 'shop'">
                <div class="pv-shop" :class="{ s2: mod.params.style === '2' }">
                  <img v-if="mod.params.bgimg" class="pv-shop-bg" :src="mod.params.bgimg" />
                <div v-else class="pv-shop-bg-ph">店招背景</div>
                  <template v-if="mod.params.style === '2'">
                    <div class="pv-shop-logo sq" :style="{ bottom: `${Number(mod.params.navheight || 34) / 2}px`, zIndex: '5' }">
                      <img v-if="mod.params.logo === '1' && mod.params.logourl" :src="mod.params.logourl" />
                    </div>
                    <div v-if="mod.params.name === '1'" class="pv-shop-name s2" :style="{ bottom: `${Number(mod.params.navheight || 34) * 0.75 + 31}px`, transform: 'translateY(50%)' }">{{ mod.params.shopname || '店铺名称' }}</div>
                    <div v-if="mod.params.menu === '1' && (mod.data || []).length" class="pv-shop-nav row" :style="{ height: `${Number(mod.params.navheight || 34)}px` }">
                      <span v-for="(d, di) in mod.data" :key="di" :style="{ color: mod.params.navcolor || '#FFFFFF', fontSize: `${Number(mod.params.navfontsize || 11)}px` }">
                        <img v-if="d.imgurl" :src="d.imgurl" :style="{ width: `${Number(mod.params.naviconsize || 16)}px`, height: `${Number(mod.params.naviconsize || 16)}px` }" />{{ d.text || '按钮' }}
                      </span>
                    </div>
                  </template>
                  <template v-else>
                    <div v-if="mod.params.logo === '1'" class="pv-shop-logo" :style="{ bottom: `${Number(mod.params.navheight || 34) + 26}px` }">
                      <img v-if="mod.params.logourl" :src="mod.params.logourl" />
                      <span v-else>LOGO</span>
                    </div>
                    <div v-if="mod.params.name === '1'" class="pv-shop-name" :style="{ bottom: `${Number(mod.params.navheight || 34) + 4}px` }">{{ mod.params.shopname || '店铺名称' }}</div>
                    <div v-if="mod.params.menu === '1' && (mod.data || []).length" class="pv-shop-nav" :style="{ height: `${Number(mod.params.navheight || 34)}px` }">
                      <span v-for="(d, di) in mod.data" :key="di" :style="{ color: mod.params.navcolor || '#FFFFFF', fontSize: `${Number(mod.params.navfontsize || 11)}px` }">
                        <img v-if="d.imgurl" :src="d.imgurl" :style="{ width: `${Number(mod.params.naviconsize || 16)}px`, height: `${Number(mod.params.naviconsize || 16)}px` }" />{{ d.text || '按钮' }}
                      </span>
                    </div>
                  </template>
                </div>
              </template>

              <!-- 精选（任务303：左轮播+右双图） -->
              <template v-else-if="mod.temp === 'featured'">
                <div class="pv-featured" :style="{ height: `${Number(mod.params.height || 249)}px`, gap: `${Number(mod.params.padding_x || 0)}px` }">
                  <div class="pv-featured-left">
                    <img v-if="(mod.data || [])[featIdxSafe(mod)]?.imgurl" :src="mod.data?.[featIdxSafe(mod)]?.imgurl" />
                  <span v-else class="pv-placeholder">轮播图{{ featIdxSafe(mod) + 1 }}</span>
                    <div
                      v-if="mod.params.indicatordots === '1' && (mod.data || []).length"
                      class="pv-featured-dots"
                      :class="[`pos-${mod.params.dotposition || 'center'}`, `shape-${mod.params.dotshape || '0'}`]"
                      :style="{ bottom: `${Number(mod.params.dotbottom || 8)}px` }"
                    >
                      <i v-for="(_d, di) in mod.data" :key="di" :style="{ backgroundColor: di === featIdxSafe(mod) ? (mod.params.dotactivecolor || '#3DB51E') : (mod.params.dotcolor || '#ffffff') }"></i>
                    </div>
                  </div>
                  <div class="pv-featured-right" :style="{ gap: `${Number(mod.params.padding_y || 0)}px` }">
                    <div class="pv-featured-rimg"><img v-if="mod.params.imgtop" :src="mod.params.imgtop" /><span v-else class="pv-placeholder">右上方图</span></div>
                    <div class="pv-featured-rimg"><img v-if="mod.params.imgbot" :src="mod.params.imgbot" /><span v-else class="pv-placeholder">右下方图</span></div>
                  </div>
                </div>
              </template>

              <!-- 订单播报（任务20：页面流内播报条占位） -->
              <template v-else-if="mod.temp === 'orderbroadcast'">
                <div
                  class="pv-orderbroadcast"
                  :style="{ height: `${Number(mod.params.height || 36)}px`, borderRadius: `${mod.params.borderradius || 18}px`, backgroundColor: mod.params.bgcolor || '#F5F5F5', color: mod.params.color || '#666666' }"
                >
                  <span v-if="mod.params.showavatar === '1'" class="pv-orderbroadcast-avatar"></span>
                  <span class="pv-orderbroadcast-text">用户** 刚刚购买了商品</span>
                </div>
              </template>

              <!-- 优惠券 -->
              <template v-else>
                <div class="pv-placeholder-box">{{ nameOf(mod.temp) }}</div>
              </template>
            </div>
          </div>
          </div>

          <!-- 悬浮按钮覆盖层：手机区任意定位，覆盖组件最前层（顶部标题在其上） -->
          <div
            v-for="mod in items.filter((m) => m.temp === 'cover')"
            :key="'cov-' + mod.id"
            class="pv-cover"
            @click.stop="selectedId = mod.id"
          >
            <div class="pv-cover-btn" :class="{ active: selectedId === mod.id }" :style="covBtnStyle(mod.params)">
              <div class="de-canvas-tools">
                <span class="de-canvas-tools-name">悬浮按钮</span>
                <span class="de-tool" title="上移" @click.stop="moveUp(mod.id)">↑</span>
                <span class="de-tool" title="下移" @click.stop="moveDown(mod.id)">↓</span>
                <span class="de-tool danger" title="删除" @click.stop="removeModule(mod.id)">✕</span>
              </div>
              <img v-if="mod.params.style === '2' && mod.params.pic" :src="mod.params.pic" :style="{ width: `${mod.params.picwidth || 48}px`, height: `${mod.params.picheight || 48}px` }" />
              <span v-else :style="{ fontSize: `${mod.params.fontsize || 14}px`, color: mod.params.color || '#FFFFFF' }">{{ mod.params.text || '悬浮' }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- 右：属性面板 -->
      <div class="de-right">
        <div class="de-panel-title">
          {{ !selected || selected.temp === 'topbar' ? '页面信息设置' : `${nameOf(selected.temp)} 属性` }}
        </div>

        <!-- 页面信息设置（任务205：未选中组件时仅此一块，与选中 topbar 同表单） -->
        <el-form v-if="!selected || selected.temp === 'topbar'" label-position="top" class="de-form">
          <el-form-item label="页面标题">
            <el-input v-model="pageTitle" maxlength="30" show-word-limit />
            <div class="de-form-tip">手机端的页面标题</div>
          </el-form-item>
          <el-form-item label="导航背景颜色">
            <el-radio-group v-model="topbar.params.navbgmode">
              <el-radio value="0">跟随系统设置</el-radio>
              <el-radio value="1">自定义</el-radio>
            </el-radio-group>
            <div class="de-form-tip">仅支持小程序设置背景色</div>
            <el-color-picker v-if="topbar.params.navbgmode === '1'" v-model="topbar.params.navbgcolor" />
          </el-form-item>
          <el-form-item label="导航文字颜色">
            <el-radio-group v-model="topbar.params.navtextmode">
              <el-radio value="0">跟随系统设置</el-radio>
              <el-radio value="1">白色</el-radio>
              <el-radio value="2">黑色</el-radio>
            </el-radio-group>
            <div class="de-form-tip">仅支持小程序设置背景色</div>
          </el-form-item>
          <el-form-item label="背景颜色">
            <el-radio-group v-model="topbar.params.bgmode">
              <el-radio value="0">纯色</el-radio>
              <el-radio value="1">渐变色</el-radio>
            </el-radio-group>
            <div class="de-color-row">
              <el-input v-model="topbar.params.bgcolor" style="width: 110px" />
              <el-color-picker v-model="topbar.params.bgcolor" />
            </div>
            <div v-if="topbar.params.bgmode === '1'" class="de-color-row">
              <el-input v-model="topbar.params.bgcolor2" style="width: 110px" />
              <el-color-picker v-model="topbar.params.bgcolor2" />
            </div>
          </el-form-item>
          <el-form-item v-if="topbar.params.bgmode === '1'" label="渐变方向">
            <el-radio-group v-model="topbar.params.bgdir">
              <el-radio value="0">从上到下</el-radio>
              <el-radio value="1">从左到右</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="强制登录">
            <el-switch v-model="topbar.params.needlogin" active-value="1" inactive-value="0" active-text="开" inactive-text="关" />
            <div class="de-form-tip de-form-tip--warn">开启后可能会被下架或者驳回，谨慎开启</div>
          </el-form-item>
          <el-form-item label="查看权限">
            <el-checkbox-group v-model="viewpermChecked">
              <el-checkbox value="all">所有人</el-checkbox>
              <el-checkbox value="member">普通会员</el-checkbox>
              <el-checkbox value="dealer">经销商</el-checkbox>
            </el-checkbox-group>
          </el-form-item>
          <el-form-item label="付费查看">
            <el-radio-group v-model="topbar.params.payview">
              <el-radio value="0">关闭</el-radio>
              <el-radio value="1">开启</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="topbar.params.payview === '1'" label="支付金额">
            <el-input v-model="topbar.params.payviewprice" placeholder="请输入支付金额">
              <template #append>元</template>
            </el-input>
          </el-form-item>
          <el-divider>开屏广告</el-divider>
          <el-form-item label="开屏广告">
            <el-radio-group v-model="topbar.params.showgg">
              <el-radio value="0">关闭</el-radio>
              <el-radio value="1">图片</el-radio>
            </el-radio-group>
          </el-form-item>
          <template v-if="topbar.params.showgg === '1'">
            <el-form-item label="广告图片">
              <span class="de-pick" @click="openImgPick((u) => (ggFirst.image = u))">
                <img v-if="ggFirst.image" class="de-thumb" :src="ggFirst.image" />
                <el-button v-else size="small">选择图片</el-button>
              </span>
            </el-form-item>
            <el-form-item label="跳转链接">
              <el-input v-model="ggFirst.url" placeholder="广告跳转链接" />
              <el-button size="small" style="margin-top: 6px" @click="openChooseUrl((u) => (ggFirst.url = u))">选链接</el-button>
            </el-form-item>
            <el-form-item label="展示人群">
              <el-checkbox-group v-model="ggcrowdChecked">
                <el-checkbox value="all">所有人</el-checkbox>
                <el-checkbox value="followed">已关注</el-checkbox>
                <el-checkbox value="unfollowed">未关注</el-checkbox>
                <el-checkbox value="member">普通会员</el-checkbox>
                <el-checkbox value="dealer">经销商</el-checkbox>
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="展示方式">
              <el-radio-group v-model="topbar.params.ggmode">
                <el-radio value="0">首次</el-radio>
                <el-radio value="1">每次</el-radio>
              </el-radio-group>
            </el-form-item>
          </template>
        </el-form>

        <!-- 组件属性 -->
        <template v-else-if="selected">
          <el-form label-position="left" label-width="110px" class="de-form">
            <!-- banner（任务25 对照截图重做） -->
            <template v-if="selected.temp === 'banner'">
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="bnMarginY" :min="0" :max="100" />
                  <el-input-number v-model="bnMarginY" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="是否显示指示点">
                <el-radio-group v-model="selected.params.indicatordots">
                  <el-radio value="1">显示</el-radio>
                  <el-radio value="0">不显示</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="指示点形状">
                <el-radio-group v-model="selected.params.dotshape">
                  <el-radio value="0">默认</el-radio>
                  <el-radio value="1">长方形</el-radio>
                  <el-radio value="2">圆形</el-radio>
                  <el-radio value="3">正方形</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="指示点位置">
                <el-radio-group v-model="selected.params.dotposition">
                  <el-radio value="left">居左</el-radio>
                  <el-radio value="center">居中</el-radio>
                  <el-radio value="right">居右</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="指示点与下方距离">
                <div class="de-slider-row">
                  <el-slider v-model="bnDotBottom" :min="0" :max="40" />
                  <el-input-number v-model="bnDotBottom" :min="0" :max="40" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="指示点颜色">
                <el-color-picker v-model="selected.params.dotcolor" />
                <code class="de-link-val">{{ selected.params.dotcolor }}</code>
              </el-form-item>
              <el-form-item label="选中指示点颜色">
                <el-color-picker v-model="selected.params.dotactivecolor" />
                <code class="de-link-val">{{ selected.params.dotactivecolor }}</code>
              </el-form-item>
              <el-form-item label="轮播图高度">
                <div class="de-slider-row">
                  <el-slider v-model="bnHeight" :min="60" :max="500" />
                  <el-input-number v-model="bnHeight" :min="60" :max="500" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="圆角度">
                <div class="de-slider-row">
                  <el-slider v-model="bnRadius" :min="0" :max="100" />
                  <el-input-number v-model="bnRadius" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="自动切换时间">
                <div class="de-slider-row">
                  <el-slider v-model="bnInterval" :min="1" :max="30" />
                  <el-input-number v-model="bnInterval" :min="1" :max="30" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">秒</span>
                </div>
              </el-form-item>
              <el-form-item label="背景颜色">
                <el-color-picker v-model="selected.params.bgcolor" />
                <code class="de-link-val">{{ selected.params.bgcolor }}</code>
              </el-form-item>
              <el-divider>轮播图片</el-divider>
              <div v-for="(d, di) in selected.data" :key="di" class="de-data-card col">
                <div class="de-data-card-row feat-card-top">
                  <el-button link :disabled="di === 0" title="上移" @click="moveDataItem(selected, di, -1)">↑</el-button>
                  <el-button link type="danger" title="删除" @click="removeDataItem(selected, di)">✕</el-button>
                </div>
                <div class="de-data-card-row">
                  <span class="de-pick" @click="openImgPick((u) => (d.imgurl = u))">
                    <img v-if="d.imgurl" class="de-thumb" :src="d.imgurl" />
                    <em v-if="d.imgurl" class="feat-pick-mask">重新选择图片</em>
                    <el-button v-else size="small">选择图片</el-button>
                  </span>
                  <el-input :model-value="d.hrefname || d.hrefurl" readonly size="small" placeholder="请选择跳转链接" />
                  <el-button size="small" @click="openChooseUrl((u, n) => { d.hrefurl = u; d.hrefname = n })">选链接</el-button>
                </div>
              </div>
              <el-button size="small" style="width: 100%" @click="addDataItem(selected, { id: genMid(), imgurl: '', hrefurl: '', hrefname: '' })">添加轮播</el-button>
              <el-form-item label="左右边距" style="margin-top: 12px">
                <div class="de-slider-row">
                  <el-slider v-model="bnMarginX" :min="0" :max="50" />
                  <el-input-number v-model="bnMarginX" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="左右内边距">
                <div class="de-slider-row">
                  <el-slider v-model="bnPaddingX" :min="0" :max="50" />
                  <el-input-number v-model="bnPaddingX" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="上下内边距">
                <div class="de-slider-row">
                  <el-slider v-model="bnPaddingY" :min="0" :max="50" />
                  <el-input-number v-model="bnPaddingY" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
            </template>

            <!-- search -->
            <template v-else-if="selected.temp === 'search'">
              <el-form-item label="提示文字"><el-input v-model="selected.params.placeholder" /></el-form-item>
              <el-form-item label="文字颜色"><el-color-picker v-model="selected.params.color" /></el-form-item>
              <el-form-item label="圆角度"><DeSlider v-model="selected.params.borderradius" :max="100" /></el-form-item>
            </template>

            <!-- notice（对照截图重做：公告设置 + 公告列表 + 边距） -->
            <template v-else-if="selected.temp === 'notice'">
              <el-divider>公告设置</el-divider>
              <el-form-item label="与上方间距"><DeSlider v-model="selected.params.margin_y" :max="100" /></el-form-item>
              <el-form-item label="公告图标">
                <el-radio-group v-model="ntShowImg">
                  <el-radio value="0">不显示</el-radio>
                  <el-radio value="1">显示</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="小图标">
                <el-radio-group v-model="ntShowIcon">
                  <el-radio value="0">不显示</el-radio>
                  <el-radio value="1">显示</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item v-if="ntShowImg === '1'" label="选择图片">
                <span class="de-pick" @click="openImgPick((u) => (selected!.params.img = u))">
                  <img v-if="selected.params.img" class="de-thumb xl" :src="selected.params.img" />
                  <em v-if="selected.params.img" class="feat-pick-mask">重新选择图片</em>
                  <el-button v-else size="small">选择图片</el-button>
                </span>
              </el-form-item>
              <el-form-item v-if="ntShowIcon === '1'" label="选择图标">
                <span class="de-pick" @click="openImgPick((u) => (selected!.params.icon = u))">
                  <img v-if="selected.params.icon" class="de-thumb xl" :src="selected.params.icon" />
                  <em v-if="selected.params.icon" class="feat-pick-mask">重新选择图标</em>
                  <el-button v-else size="small">选择图标</el-button>
                </span>
              </el-form-item>
              <el-form-item label="文字颜色">
                <div class="de-color-row">
                  <el-input v-model="selected.params.color" size="small" />
                  <el-color-picker v-model="selected.params.color" />
                </div>
              </el-form-item>
              <el-form-item label="文字大小"><DeSlider v-model="selected.params.fontsize" :min="10" :max="60" /></el-form-item>
              <el-form-item label="背景颜色">
                <div class="de-color-row">
                  <el-input v-model="selected.params.bgcolor" size="small" />
                  <el-color-picker v-model="selected.params.bgcolor" />
                </div>
              </el-form-item>
              <el-form-item label="滚动速度"><DeSlider v-model="selected.params.scroll" :min="1" :max="30" unit="秒" /></el-form-item>
              <el-divider>公告列表</el-divider>
              <div v-for="(d, di) in selected.data" :key="di" class="de-data-card col">
                <div class="de-data-card-row feat-card-top">
                  <span class="de-card-title">标题编辑</span>
                  <el-button link :disabled="di === 0" title="上移" @click="moveDataItem(selected, di, -1)">↑</el-button>
                  <el-button link type="danger" title="删除" @click="removeDataItem(selected, di)">✕</el-button>
                </div>
                <el-input v-model="d.title" placeholder="公告标题" />
                <div class="de-data-card-row">
                  <el-input :model-value="d.hrefurl" readonly size="small" placeholder="跳转链接" />
                  <el-button size="small" @click="openChooseUrl((u) => (d.hrefurl = u))">选链接</el-button>
                </div>
              </div>
              <el-button size="small" style="width: 100%" @click="addDataItem(selected, { title: '', hrefurl: '' })">添加公告</el-button>
              <el-form-item label="左右边距" style="margin-top: 12px"><DeSlider v-model="selected.params.margin_x" :max="50" /></el-form-item>
              <el-form-item label="左右内边距"><DeSlider v-model="selected.params.padding_x" :max="50" /></el-form-item>
              <el-form-item label="上下内边距"><DeSlider v-model="selected.params.padding_y" :max="50" /></el-form-item>
            </template>

            <!-- menu -->
            <template v-else-if="selected.temp === 'menu'">
              <el-form-item label="每行数量"><DeSlider v-model="selected.params.pernum" :min="1" :max="10" unit="个" /></el-form-item>
              <el-form-item label="图标尺寸"><DeSlider v-model="selected.params.iconsize" :min="10" :max="200" /></el-form-item>
              <el-form-item label="显示标题"><el-switch v-model="selected.params.showtitle" active-value="1" inactive-value="0" /></el-form-item>
              <el-divider>导航项</el-divider>
              <div v-for="(d, di) in selected.data" :key="di" class="de-data-card col">
                <div class="de-data-card-row">
                  <span class="de-pick" @click="openIconPick((u) => (d.imgurl = u))">
                    <img v-if="d.imgurl" class="de-thumb sm" :src="d.imgurl" />
                    <el-button v-else size="small">选择图标</el-button>
                  </span>
                  <el-input v-model="d.title" placeholder="标题" />
                </div>
                <div class="de-data-card-row">
                  <el-button size="small" @click="openChooseUrl((u) => (d.hrefurl = u))">选链接</el-button>
                  <code class="de-link-val">{{ d.hrefurl || '未设置' }}</code>
                  <el-button v-if="d.hrefurl" link @click="d.hrefurl = ''">清除</el-button>
                </div>
                <el-button link type="danger" @click="removeDataItem(selected, di)">删除</el-button>
              </div>
              <el-button size="small" @click="addDataItem(selected, { imgurl: '', title: '', hrefurl: '' })">+ 添加导航项</el-button>
            </template>

            <!-- picture（任务26 对照截图重做） -->
            <template v-else-if="selected.temp === 'picture'">
              <el-divider>单图设置</el-divider>
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="picMarginY" :min="0" :max="100" />
                  <el-input-number v-model="picMarginY" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="背景颜色">
                <el-color-picker v-model="selected.params.bgcolor" />
                <code class="de-link-val">{{ selected.params.bgcolor }}</code>
              </el-form-item>
              <el-divider>图片设置</el-divider>
              <div v-for="(d, di) in selected.data" :key="di" class="de-data-card col">
                <div class="de-data-card-row feat-card-top">
                  <el-button link :disabled="di === 0" title="上移" @click="moveDataItem(selected, di, -1)">↑</el-button>
                  <el-button link type="danger" title="删除" @click="removeDataItem(selected, di)">✕</el-button>
                </div>
                <div class="de-data-card-row">
                  <span class="de-pick" @click="openImgPick((u) => (d.imgurl = u))">
                    <img v-if="d.imgurl" class="de-thumb" :src="d.imgurl" />
                    <em v-if="d.imgurl" class="feat-pick-mask">重新选择图片</em>
                    <el-button v-else size="small">选择图片</el-button>
                  </span>
                  <el-input :model-value="d.hrefname || d.hrefurl" readonly size="small" placeholder="链接地址" />
                  <el-button size="small" @click="openChooseUrl((u, n) => { d.hrefurl = u; d.hrefname = n })">选链接</el-button>
                </div>
              </div>
              <el-button size="small" style="width: 100%" @click="addDataItem(selected, { id: genMid(), imgurl: '', hrefurl: '', hrefname: '' })">添加图片</el-button>
              <el-form-item label="圆角度" style="margin-top: 12px">
                <div class="de-slider-row">
                  <el-slider v-model="picRadius" :min="0" :max="100" />
                  <el-input-number v-model="picRadius" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="左右边距">
                <div class="de-slider-row">
                  <el-slider v-model="picMarginX" :min="0" :max="50" />
                  <el-input-number v-model="picMarginX" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="左右内边距">
                <div class="de-slider-row">
                  <el-slider v-model="picPaddingX" :min="0" :max="50" />
                  <el-input-number v-model="picPaddingX" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="上下内边距">
                <div class="de-slider-row">
                  <el-slider v-model="picPaddingY" :min="0" :max="50" />
                  <el-input-number v-model="picPaddingY" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
            </template>

            <!-- pictures（任务27 对照截图重做） -->
            <template v-else-if="selected.temp === 'pictures'">
              <el-divider>多图设置</el-divider>
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="pgMarginY" :min="0" :max="100" />
                  <el-input-number v-model="pgMarginY" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="布局方式">
                <el-radio-group v-model="selected.params.layout">
                  <el-radio value="2">堆积两列</el-radio>
                  <el-radio value="3">堆积三列</el-radio>
                  <el-radio value="4">堆积四列</el-radio>
                  <el-radio value="showcase">橱窗样式</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="背景颜色">
                <el-color-picker v-model="selected.params.bgcolor" />
                <code class="de-link-val">{{ selected.params.bgcolor }}</code>
              </el-form-item>
              <el-divider>图片设置</el-divider>
              <div v-for="(d, di) in selected.data" :key="di" class="de-data-card col">
                <div class="de-data-card-row feat-card-top">
                  <el-button link :disabled="di === 0" title="上移" @click="moveDataItem(selected, di, -1)">↑</el-button>
                  <el-button link type="danger" title="删除" @click="removeDataItem(selected, di)">✕</el-button>
                </div>
                <div class="de-data-card-row pg-card-body">
                  <span class="de-pick" @click="openImgPick((u) => (d.imgurl = u))">
                    <img v-if="d.imgurl" class="de-thumb" :src="d.imgurl" />
                    <em v-if="d.imgurl" class="feat-pick-mask">重新选择图片</em>
                    <el-button v-else size="small">选择图片</el-button>
                  </span>
                  <div class="pg-card-right">
                    <div class="pg-row">
                      <span class="pg-label">点击图片</span>
                      <el-radio-group v-model="d.clicktype">
                        <el-radio value="link">跳转链接</el-radio>
                        <el-radio value="preview">预览图片</el-radio>
                      </el-radio-group>
                    </div>
                    <div class="pg-row">
                      <el-input :model-value="d.hrefname || d.hrefurl" readonly size="small" placeholder="链接地址" />
                      <el-button size="small" @click="openChooseUrl((u, n) => { d.hrefurl = u; d.hrefname = n })">选链接</el-button>
                    </div>
                  </div>
                </div>
              </div>
              <el-button size="small" style="width: 100%" @click="addDataItem(selected, { id: genMid(), imgurl: '', hrefurl: '', hrefname: '', clicktype: 'link' })">添加图片</el-button>
              <el-form-item label="左右边距" style="margin-top: 12px">
                <div class="de-slider-row">
                  <el-slider v-model="pgMarginX" :min="0" :max="50" />
                  <el-input-number v-model="pgMarginX" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="左右内边距">
                <div class="de-slider-row">
                  <el-slider v-model="pgPaddingX" :min="0" :max="50" />
                  <el-input-number v-model="pgPaddingX" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="上下内边距">
                <div class="de-slider-row">
                  <el-slider v-model="pgPaddingY" :min="0" :max="50" />
                  <el-input-number v-model="pgPaddingY" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
            </template>

            <!-- title（任务28 对照截图重做：单行紧凑） -->
            <template v-else-if="selected.temp === 'title'">
              <div class="tt-block">
                <el-form-item label="与上方间距">
                  <div class="de-slider-row">
                    <el-slider v-model="ttMarginY" :min="0" :max="100" />
                    <el-input-number v-model="ttMarginY" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                    <span class="de-slider-unit">px</span>
                  </div>
                </el-form-item>
                <el-form-item label="标题名称"><el-input v-model="selected.params.title" /></el-form-item>
                <el-form-item label="标题大小">
                  <div class="de-slider-row">
                    <el-slider v-model="ttFontsize" :min="10" :max="60" />
                    <el-input-number v-model="ttFontsize" :min="10" :max="60" size="small" :controls="false" class="de-slider-num" />
                    <span class="de-slider-unit">px</span>
                  </div>
                </el-form-item>
                <el-form-item label="标题颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.color }}</code>
                    <el-color-picker v-model="selected.params.color" />
                  </div>
                </el-form-item>
                <el-form-item label="图标">
                  <el-radio-group v-model="selected.params.showicon">
                    <el-radio value="1">显示</el-radio>
                    <el-radio value="0">不显示</el-radio>
                  </el-radio-group>
                </el-form-item>
                <el-form-item label="选择图标" class="tt-icon-row">
                  <img v-if="selected.params.imgurl" class="de-thumb sm" :src="selected.params.imgurl" />
                  <el-button size="small" @click="openImgPick((u) => (selected!.params.imgurl = u))">上传图标</el-button>
                </el-form-item>
                <el-form-item label="图标大小">
                  <div class="de-slider-row">
                    <el-slider v-model="ttIconsize" :min="5" :max="60" />
                    <el-input-number v-model="ttIconsize" :min="5" :max="60" size="small" :controls="false" class="de-slider-num" />
                    <span class="de-slider-unit">px</span>
                  </div>
                </el-form-item>
                <el-form-item label="横线颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.linecolor }}</code>
                    <el-color-picker v-model="selected.params.linecolor" />
                  </div>
                </el-form-item>
                <el-form-item label="背景颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.bgcolor }}</code>
                    <el-color-picker v-model="selected.params.bgcolor" />
                  </div>
                </el-form-item>
                <el-form-item label="左右边距">
                  <div class="de-slider-row">
                    <el-slider v-model="ttMarginX" :min="0" :max="50" />
                    <el-input-number v-model="ttMarginX" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                    <span class="de-slider-unit">px</span>
                  </div>
                </el-form-item>
                <el-form-item label="左右内边距">
                  <div class="de-slider-row">
                    <el-slider v-model="ttPaddingX" :min="0" :max="100" />
                    <el-input-number v-model="ttPaddingX" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                    <span class="de-slider-unit">px</span>
                  </div>
                </el-form-item>
                <el-form-item label="上下内边距">
                  <div class="de-slider-row">
                    <el-slider v-model="ttPaddingY" :min="0" :max="50" />
                    <el-input-number v-model="ttPaddingY" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                    <span class="de-slider-unit">px</span>
                  </div>
                </el-form-item>
              </div>
            </template>

            <!-- text（任务31 对照截图重做） -->
            <template v-else-if="selected.temp === 'text'">
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="txMarginY" :min="0" :max="100" />
                  <el-input-number v-model="txMarginY" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="文本内容" class="de-top-item">
                <el-input v-model="selected.params.content" type="textarea" :rows="4" />
              </el-form-item>
              <el-form-item label="文本大小">
                <div class="de-slider-row">
                  <el-slider v-model="txFontsize" :min="10" :max="60" />
                  <el-input-number v-model="txFontsize" :min="10" :max="60" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="文本行高">
                <div class="de-slider-row">
                  <el-slider v-model="txLineheight" :min="10" :max="60" />
                  <el-input-number v-model="txLineheight" :min="10" :max="60" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="字体间距">
                <div class="de-slider-row">
                  <el-slider v-model="txLetterspacing" :min="0" :max="20" />
                  <el-input-number v-model="txLetterspacing" :min="0" :max="20" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="字体加粗">
                <el-switch v-model="selected.params.isbold" active-value="1" inactive-value="0" />
              </el-form-item>
              <el-form-item label="对齐方向">
                <el-radio-group v-model="selected.params.align">
                  <el-radio value="left">居左</el-radio>
                  <el-radio value="center">居中</el-radio>
                  <el-radio value="right">居右</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="文字颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.color }}</code>
                  <el-color-picker v-model="selected.params.color" />
                </div>
              </el-form-item>
              <el-form-item label="背景颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.bgcolor }}</code>
                  <el-color-picker v-model="selected.params.bgcolor" />
                </div>
              </el-form-item>
              <el-form-item label="背景图片">
                <div class="de-data-card-row">
                  <el-input size="small" readonly :model-value="selected.params.bgimg" placeholder="未选择" />
                  <el-button size="small" @click="openImgPick((u) => (selected!.params.bgimg = u))">选择图片</el-button>
                </div>
              </el-form-item>
              <el-form-item label="跳转链接">
                <div class="de-data-card-row">
                  <el-input size="small" readonly :model-value="selected.params.hrefname || selected.params.hrefurl" placeholder="未选择" />
                  <el-button size="small" @click="openChooseUrl((u, n) => { selected!.params.hrefurl = u; selected!.params.hrefname = n })">选链接</el-button>
                </div>
              </el-form-item>
              <el-form-item label="左右边距">
                <div class="de-slider-row">
                  <el-slider v-model="txMarginX" :min="0" :max="50" />
                  <el-input-number v-model="txMarginX" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="左右内边距">
                <div class="de-slider-row">
                  <el-slider v-model="txPaddingX" :min="0" :max="50" />
                  <el-input-number v-model="txPaddingX" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="上下内边距">
                <div class="de-slider-row">
                  <el-slider v-model="txPaddingY" :min="0" :max="50" />
                  <el-input-number v-model="txPaddingY" :min="0" :max="50" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
            </template>

            <!-- richtext（对照截图重做：编辑器在中间 + 与上方间距 + 背景颜色，边距走通用设置） -->
            <template v-else-if="selected.temp === 'richtext'">
              <el-form-item label="富文本内容" class="de-top-item">
                <div class="rt-editor">
                  <RtToolbar :editor="rtEditorRef" :defaultConfig="rtToolbarConfig" mode="default" class="rt-toolbar" />
                  <RtEditor v-model="rtHtml" :defaultConfig="rtEditorConfig" mode="default" class="rt-area" @onCreated="onRtCreated" />
                </div>
              </el-form-item>
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="rtMarginY" :min="0" :max="100" />
                  <el-input-number v-model="rtMarginY" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="背景颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.bgcolor }}</code>
                  <el-color-picker v-model="selected.params.bgcolor" />
                </div>
              </el-form-item>
            </template>

            <!-- blank -->
            <template v-else-if="selected.temp === 'blank'">
              <el-form-item label="空白高度"><DeSlider v-model="selected.params.height" :max="500" /></el-form-item>
            </template>

            <!-- line -->
            <template v-else-if="selected.temp === 'line'">
              <el-form-item label="线条粗细"><DeSlider v-model="selected.params.height" :max="50" /></el-form-item>
              <el-form-item label="线条样式">
                <el-radio-group v-model="selected.params.style">
                  <el-radio value="solid">实线</el-radio>
                  <el-radio value="dashed">虚线</el-radio>
                  <el-radio value="dotted">圆点</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="线条颜色"><el-color-picker v-model="selected.params.color" /></el-form-item>
            </template>

            <!-- product（任务302：对照三张截图重做 - 样式选择/标题样式/商品样式/商品设置/组件样式） -->
            <template v-else-if="selected.temp === 'product'">
              <el-form-item label="与上方距离"><DeSlider v-model="selected.params.margin_y" :max="100" /></el-form-item>
              <el-form-item label="样式选择">
                <el-radio-group v-model="selected.params.style">
                  <el-radio value="1">单排</el-radio>
                  <el-radio value="2">双排</el-radio>
                  <el-radio value="3">三排</el-radio>
                  <el-radio value="4">横排</el-radio>
                  <el-radio value="5">左右滑动</el-radio>
                  <el-radio value="6">瀑布流</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-divider>标题样式</el-divider>
              <el-form-item label="是否显示">
                <el-radio-group v-model="selected.params.titleshow">
                  <el-radio value="1">显示</el-radio>
                  <el-radio value="0">不显示</el-radio>
                </el-radio-group>
              </el-form-item>
              <template v-if="selected.params.titleshow === '1'">
                <el-form-item label="标题内容"><el-input v-model="selected.params.titletext" maxlength="20" /></el-form-item>
                <el-form-item label="标题大小"><DeSlider v-model="selected.params.titlefontsize" :min="10" :max="60" /></el-form-item>
                <el-form-item label="标题颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.titlecolor }}</code>
                    <el-color-picker v-model="selected.params.titlecolor" />
                  </div>
                </el-form-item>
                <el-form-item label="备注内容"><el-input v-model="selected.params.notetext" maxlength="20" /></el-form-item>
                <el-form-item label="备注大小"><DeSlider v-model="selected.params.notefontsize" :min="10" :max="60" /></el-form-item>
                <el-form-item label="备注颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.notecolor }}</code>
                    <el-color-picker v-model="selected.params.notecolor" />
                  </div>
                </el-form-item>
                <el-form-item label="右箭头">
                  <el-radio-group v-model="selected.params.arrowshow">
                    <el-radio value="1">显示</el-radio>
                    <el-radio value="0">不显示</el-radio>
                  </el-radio-group>
                </el-form-item>
                <el-form-item label="跳转链接">
                  <div class="de-data-card-row">
                    <el-input size="small" readonly :model-value="selected.params.hrefurl" placeholder="跳转链接" />
                    <el-button size="small" @click="openChooseUrl((u, n) => { selected!.params.hrefurl = u; selected!.params.hrefname = n })">+</el-button>
                  </div>
                </el-form-item>
              </template>
              <el-form-item label="左右内边距"><DeSlider v-model="selected.params.padding_x" :max="50" /></el-form-item>
              <el-form-item label="上下内边距"><DeSlider v-model="selected.params.padding_y" :max="50" /></el-form-item>
              <el-divider>商品样式</el-divider>
              <el-form-item label="商品名称">
                <el-radio-group v-model="selected.params.nameshow">
                  <el-radio value="1">显示</el-radio>
                  <el-radio value="0">不显示</el-radio>
                </el-radio-group>
              </el-form-item>
              <template v-if="selected.params.nameshow === '1'">
                <el-form-item label="字体大小"><DeSlider v-model="selected.params.namefontsize" :min="10" :max="60" /></el-form-item>
                <el-form-item label="字体颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.namecolor }}</code>
                    <el-color-picker v-model="selected.params.namecolor" />
                  </div>
                </el-form-item>
                <el-form-item label="字体粗细">
                  <el-radio-group v-model="selected.params.namebold">
                    <el-radio value="1">加粗</el-radio>
                    <el-radio value="0">正常</el-radio>
                  </el-radio-group>
                </el-form-item>
              </template>
              <el-form-item label="商品价格">
                <el-radio-group v-model="selected.params.priceshow">
                  <el-radio value="1">市场价+销售价</el-radio>
                  <el-radio value="2">销售价</el-radio>
                  <el-radio value="0">不显示</el-radio>
                </el-radio-group>
                <div class="de-form-tip">提示：市场价=销售价，市场价大于销售价才会显示销售价</div>
              </el-form-item>
              <template v-if="selected.params.priceshow !== '0'">
                <el-form-item label="价格颜色">
                  <el-radio-group v-model="selected.params.pricecolormode">
                    <el-radio value="0">跟随主色调</el-radio>
                    <el-radio value="1">自定义颜色</el-radio>
                  </el-radio-group>
                  <div v-if="selected.params.pricecolormode === '1'" class="de-slider-row" style="margin-top: 6px">
                    <code class="de-link-val tt-color-val">{{ selected.params.pricecolor }}</code>
                    <el-color-picker v-model="selected.params.pricecolor" />
                  </div>
                </el-form-item>
                <el-form-item label="字体粗细">
                  <el-radio-group v-model="selected.params.pricebold">
                    <el-radio value="1">加粗</el-radio>
                    <el-radio value="0">正常</el-radio>
                  </el-radio-group>
                </el-form-item>
              </template>
              <el-form-item label="商品销量">
                <el-radio-group v-model="selected.params.salesshow">
                  <el-radio value="0">不显示</el-radio>
                  <el-radio value="1">显示</el-radio>
                </el-radio-group>
                <div class="de-form-tip">提示：显示销量时，销量大于0才会显示</div>
              </el-form-item>
              <template v-if="selected.params.salesshow === '1'">
                <el-form-item label="字体颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.salescolor }}</code>
                    <el-color-picker v-model="selected.params.salescolor" />
                  </div>
                </el-form-item>
                <el-form-item label="字体粗细">
                  <el-radio-group v-model="selected.params.salesbold">
                    <el-radio value="1">加粗</el-radio>
                    <el-radio value="0">正常</el-radio>
                  </el-radio-group>
                </el-form-item>
              </template>
              <el-form-item label="图片圆角">
                <DeSlider v-model="selected.params.imgradius" :max="50" />
                <div class="de-form-tip">提示：仅商品图片圆角，0表示不圆角</div>
              </el-form-item>
              <el-form-item label="左右外边距"><DeSlider v-model="selected.params.pro_margin_x" :max="50" /></el-form-item>
              <el-form-item label="上下外边距"><DeSlider v-model="selected.params.pro_margin_y" :max="50" /></el-form-item>
              <el-form-item label="购买按钮">
                <el-radio-group v-model="selected.params.buybtnshow">
                  <el-radio value="0">不显示</el-radio>
                  <el-radio value="1">显示</el-radio>
                  <el-radio value="2">自己上传</el-radio>
                </el-radio-group>
                <div v-if="selected.params.buybtnshow === '2'" class="de-slider-row" style="margin-top: 6px">
                  <img v-if="selected.params.buybtnimg" :src="selected.params.buybtnimg" class="de-badge-thumb" />
                  <el-button size="small" @click="openImgPick((u) => { selected!.params.buybtnimg = u })">选择图片</el-button>
                  <el-button v-if="selected.params.buybtnimg" size="small" link type="danger" @click="selected.params.buybtnimg = ''">移除</el-button>
                </div>
              </el-form-item>
              <el-divider>商品设置</el-divider>
              <el-form-item label="商品属性">
                <el-radio-group v-model="selected.params.probadge">
                  <el-radio value="0">无</el-radio>
                  <el-radio value="1">推荐</el-radio>
                  <el-radio value="2">热卖</el-radio>
                  <el-radio value="3">新上</el-radio>
                  <el-radio value="4">限时</el-radio>
                  <el-radio value="5">促销</el-radio>
                </el-radio-group>
                <div class="de-badge-upload">
                  <img v-if="selected.params.badgeimg" :src="selected.params.badgeimg" class="de-badge-thumb" />
                  <div v-else class="de-badge-box" @click="openImgPick((u) => { selected!.params.badgeimg = u })">+</div>
                  <span class="de-badge-label">自己上传</span>
                  <el-button v-if="selected.params.badgeimg" size="small" link type="danger" @click="selected.params.badgeimg = ''">移除</el-button>
                </div>
              </el-form-item>
              <el-form-item label="商品来源">
                <el-radio-group v-model="selected.params.prosource">
                  <el-radio value="0">手动选择</el-radio>
                  <el-radio value="1">分组分类</el-radio>
                </el-radio-group>
              </el-form-item>
              <template v-if="selected.params.prosource === '0'">
                <el-form-item label="已选商品">
                  <el-button size="small" @click="openProdGoodsDialog">选择商品</el-button>
                  <span class="de-badge-label">已选 {{ (selected.data || []).length }} 个</span>
                </el-form-item>
                <div v-for="(d, di) in selected.data || []" :key="d.id || di" class="de-data-card col">
                  <div class="de-data-card-row">
                    <img v-if="d.image" :src="d.image" class="de-badge-thumb" />
                    <span class="de-tab-label">{{ d.name || '商品' }}</span>
                    <el-button link type="danger" title="删除" @click="removeProdItem(di)">✕</el-button>
                  </div>
                </div>
              </template>
              <template v-else>
                <el-form-item label="所属商家">
                  <el-select v-model="selected.params.merchant" size="small" style="width: 100%">
                    <el-option label="自营" value="self" />
                  </el-select>
                </el-form-item>
                <el-form-item label="商品分类">
                  <el-select v-model="selected.params.category" size="small" style="width: 100%">
                    <el-option label="全部分类" value="all" />
                    <el-option v-for="c in prodCategories" :key="c.id" :label="c.name" :value="String(c.id)" />
                  </el-select>
                </el-form-item>
                <el-form-item label="商品分组">
                  <div class="de-plat-grid">
                    <el-checkbox v-model="prodGroupAll">所有分组</el-checkbox>
                    <el-checkbox v-model="prodNoGroup">无分组</el-checkbox>
                    <el-checkbox-group v-model="prodGroupsChecked">
                      <el-checkbox v-for="g in PROD_GROUP_OPTIONS" :key="g.value" :value="g.value">{{ g.label }}</el-checkbox>
                    </el-checkbox-group>
                  </div>
                </el-form-item>
              </template>
              <el-form-item label="排序方式">
                <el-radio-group v-model="selected.params.sortway">
                  <el-radio value="0">默认</el-radio>
                  <el-radio value="1">创建时间↓</el-radio>
                  <el-radio value="2">创建时间↑</el-radio>
                  <el-radio value="3">销量</el-radio>
                  <el-radio value="4">随机</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="显示数量">
                <el-input-number v-model="prodShownum" :min="1" :max="100" size="small" :controls="false" class="de-slider-num" />
              </el-form-item>
              <el-form-item label="分页加载">
                <el-radio-group v-model="selected.params.pageload">
                  <el-radio value="0">关闭</el-radio>
                  <el-radio value="1">开启</el-radio>
                </el-radio-group>
                <div class="de-form-tip">开启后，商品将按照每页20个操作分页加载，页面浏览到底部时上拉加载下页数据；同一个页面仅支持一个组件分页加载。注：选项卡不支持分页加载；下方不能有其它加载，否则无法触发分页加载</div>
              </el-form-item>
              <el-form-item label="商品框圆角">
                <DeSlider v-model="selected.params.proradius" :max="50" />
                <div class="de-form-tip">提示：每个商品框的圆角，0表示不圆角</div>
              </el-form-item>
              <el-divider>组件样式</el-divider>
              <el-form-item label="组件背景">
                <el-radio-group v-model="selected.params.bgmode">
                  <el-radio value="0">颜色</el-radio>
                  <el-radio value="1">图片</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item v-if="selected.params.bgmode === '0'" label="背景颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.bgcolor }}</code>
                  <el-color-picker v-model="selected.params.bgcolor" />
                </div>
              </el-form-item>
              <el-form-item v-else label="背景图片">
                <div class="de-slider-row">
                  <img v-if="selected.params.bgimg" :src="selected.params.bgimg" class="de-badge-thumb" />
                  <el-button size="small" @click="openImgPick((u) => { selected!.params.bgimg = u })">选择图片</el-button>
                  <el-button v-if="selected.params.bgimg" size="small" link type="danger" @click="selected.params.bgimg = ''">移除</el-button>
                </div>
              </el-form-item>
              <el-form-item label="圆角度"><DeSlider v-model="selected.params.borderradius" :max="50" /></el-form-item>
            </template>
            
            <!-- scoreshop 积分兑换 -->
            <template v-else-if="selected.temp === 'scoreshop'">
              <el-form-item label="与上方距离">
                <div class="de-slider-row">
                  <el-slider v-model="selected.params.margin_y" :min="0" :max="100" />
                  <el-input-number v-model="selected.params.margin_y" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="样式选择">
                <el-radio-group v-model="selected.params.style">
                  <el-radio value="1">单排</el-radio>
                  <el-radio value="2">双排</el-radio>
                  <el-radio value="3">三排</el-radio>
                  <el-radio value="4">横排</el-radio>
                  <el-radio value="5">左右滑动</el-radio>
                  <el-radio value="6">瀑布流</el-radio>
                </el-radio-group>
              </el-form-item>

              <el-divider>标题样式</el-divider>
              <el-form-item label="是否显示">
                <el-radio-group v-model="selected.params.titleshow">
                  <el-radio value="1">显示</el-radio>
                  <el-radio value="0">不显示</el-radio>
                </el-radio-group>
              </el-form-item>
              <template v-if="selected.params.titleshow === '1'">
                <el-form-item label="标题内容"><el-input v-model="selected.params.titletext" maxlength="20" /></el-form-item>
                <el-form-item label="标题大小"><DeSlider v-model="selected.params.titlefontsize" :min="10" :max="60" /></el-form-item>
                <el-form-item label="标题颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.titlecolor }}</code>
                    <el-color-picker v-model="selected.params.titlecolor" />
                  </div>
                </el-form-item>
                <el-form-item label="备注内容"><el-input v-model="selected.params.notetext" maxlength="20" /></el-form-item>
                <el-form-item label="备注大小"><DeSlider v-model="selected.params.notefontsize" :min="10" :max="60" /></el-form-item>
                <el-form-item label="备注颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.notecolor }}</code>
                    <el-color-picker v-model="selected.params.notecolor" />
                  </div>
                </el-form-item>
                <el-form-item label="右箭头">
                  <el-radio-group v-model="selected.params.arrowshow">
                    <el-radio value="1">显示</el-radio>
                    <el-radio value="0">不显示</el-radio>
                  </el-radio-group>
                </el-form-item>
                <el-form-item label="跳转链接">
                  <div class="de-data-card-row">
                    <el-input size="small" readonly :model-value="selected.params.hrefurl" placeholder="跳转链接" />
                    <el-button size="small" @click="openChooseUrl((u, n) => { selected!.params.hrefurl = u; selected!.params.hrefname = n })">+</el-button>
                  </div>
                </el-form-item>
              </template>
              <el-form-item label="左右内边距"><DeSlider v-model="selected.params.padding_x" :max="50" /></el-form-item>
              <el-form-item label="上下内边距"><DeSlider v-model="selected.params.padding_y" :max="50" /></el-form-item>
            
              <el-divider>商品样式</el-divider>
              <el-form-item label="商品名称">
                <el-radio-group v-model="selected.params.productnameshow">
                  <el-radio value="1">显示</el-radio>
                  <el-radio value="0">不显示</el-radio>
                </el-radio-group>
              </el-form-item>
              <template v-if="selected.params.productnameshow === '1'">
                <el-form-item label="字体大小"><DeSlider v-model="selected.params.productnamefontsize" :min="10" :max="60" /></el-form-item>
                <el-form-item label="字体颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.productnamecolor }}</code>
                    <el-color-picker v-model="selected.params.productnamecolor" />
                  </div>
                </el-form-item>
                <el-form-item label="字体粗细">
                  <el-radio-group v-model="selected.params.productnamebold">
                    <el-radio value="normal">正常</el-radio>
                    <el-radio value="bold">加粗</el-radio>
                  </el-radio-group>
                </el-form-item>
              </template>
              <el-form-item label="商品价格">
                <el-radio-group v-model="selected.params.priceshow">
                  <el-radio value="1">市场价 + 所需积分</el-radio>
                  <el-radio value="2">只展示所需积分</el-radio>
                  <el-radio value="3">不显示</el-radio>
                </el-radio-group>
                <div class="de-form-tip">提示：单价 + 拼团价，单价大于拼团价才会显示单买价</div>
              </el-form-item>
              <template v-if="selected.params.priceshow !== '3'">
                <el-form-item label="价格颜色">
                  <el-radio-group v-model="selected.params.pricecolortype">
                    <el-radio value="1">跟随主色调</el-radio>
                    <el-radio value="2">自定义颜色</el-radio>
                  </el-radio-group>
                  <div v-if="selected.params.pricecolortype === '2'" class="de-slider-row" style="margin-top: 6px">
                    <code class="de-link-val tt-color-val">{{ selected.params.pricecustomcolor }}</code>
                    <el-color-picker v-model="selected.params.pricecustomcolor" />
                  </div>
                </el-form-item>
                <el-form-item label="字体粗细">
                  <el-radio-group v-model="selected.params.pricebold">
                    <el-radio value="normal">正常</el-radio>
                    <el-radio value="bold">加粗</el-radio>
                  </el-radio-group>
                </el-form-item>
              </template>
              <el-form-item label="商品销量">
                <el-radio-group v-model="selected.params.salesshow">
                  <el-radio value="0">不显示</el-radio>
                  <el-radio value="1">显示</el-radio>
                </el-radio-group>
                <div class="de-form-tip">提示：显示销量时，销量大于 0 才会显示</div>
              </el-form-item>
              <template v-if="selected.params.salesshow === '1'">
                <el-form-item label="字体颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.salecolor }}</code>
                    <el-color-picker v-model="selected.params.salecolor" />
                  </div>
                </el-form-item>
                <el-form-item label="字体粗细">
                  <el-radio-group v-model="selected.params.salebold">
                    <el-radio value="normal">正常</el-radio>
                    <el-radio value="bold">加粗</el-radio>
                  </el-radio-group>
                </el-form-item>
              </template>
            
              <el-divider>组件样式</el-divider>
              <el-form-item label="图片圆角">
                <DeSlider v-model="selected.params.imgradius" :max="50" />
                <div class="de-form-tip">提示：仅商品图片圆角，0 表示不圆角</div>
              </el-form-item>
              <el-form-item label="左右外边距"><DeSlider v-model="selected.params.margin_x" :max="50" /></el-form-item>
              <el-form-item label="上下外边距"><DeSlider v-model="selected.params.margin_y" :max="50" /></el-form-item>
            
              <el-divider>商品设置</el-divider>
              <el-form-item label="商品属性">
                <el-radio-group v-model="selected.params.goodstags">
                  <el-radio value="">无</el-radio>
                  <el-radio value="推荐">推荐</el-radio>
                  <el-radio value="热销">热销</el-radio>
                  <el-radio value="新上">新上</el-radio>
                  <el-radio value="限时">限时</el-radio>
                  <el-radio value="促销">促销</el-radio>
                </el-radio-group>
                <div style="margin-top: 8px; text-align: center;">
                  <img v-if="selected.params.tagimgurl" :src="selected.params.tagimgurl" class="de-badge-thumb" style="width: 60px; height: 60px;" />
                  <el-empty v-else description="点击上传" image="simple" style="width: 100px; height: 80px; line-height: 80px; display: flex; align-items: center; justify-content: center; border-radius: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.1);" />
                  <div class="de-data-card-row" style="margin-top: 8px;">
                    <span class="de-pick" @click="openImgPick((u) => { selected!.params.tagimgurl = u })">
                      <el-button size="small">自己上传</el-button>
                    </span>
                  </div>
                </div>
              </el-form-item>
              <el-form-item label="商品来源">
                <el-radio-group v-model="selected.params.source">
                  <el-radio value="1">手动选择</el-radio>
                  <el-radio value="2">分组分类</el-radio>
                </el-radio-group>
              </el-form-item>
              <template v-if="selected.params.source === '1'">
                <el-form-item label="已选商品">
                  <el-button size="small" @click="openProdGoodsDialog">选择商品</el-button>
                  <span class="de-badge-label">已选 {{ (selected.data || []).length }} 个</span>
                </el-form-item>
                <!-- 每个商品卡片 -->
                <div v-for="(d, di) in selected.data" :key="d.id || di" class="de-scoreshop-card">
                  <!-- 左侧：商品图片和信息 -->
                  <div class="de-scoreshop-card-left">
                    <div class="de-scoreshop-thumb">
                      <img v-if="d.image" :src="d.image" />
                      <div v-else class="de-placeholder">图片</div>
                    </div>
                    <div class="de-scoreshop-info">
                      <div class="de-scoreshop-name">{{ d.name || '积分兑换商品' }}</div>
                      <div class="de-scoreshop-price">
                        <span>市场价¥{{ d.market_price || '0.00' }}</span>
                        <span>+ {{ d.score || '0' }}积分</span>
                      </div>
                    </div>
                  </div>
                  <!-- 右侧：操作按钮 -->
                  <div class="de-scoreshop-card-right">
                    <div class="de-scoreshop-btn-row">
                      <el-button link type="primary" :disabled="di === 0" @click="moveScoreshopUp(di)">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                          <path d="M8 3L3 8H13L8 3Z" fill="currentColor"/>
                        </svg>
                      </el-button>
                    </div>
                    <div class="de-scoreshop-btn-row">
                      <el-button link type="danger" @click="removeScoreshopItem(di)">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                          <path d="M3 3L13 13M13 3L3 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                      </el-button>
                    </div>
                  </div>
                </div>
              </template>
              <template v-else>
                <el-form-item label="商品分类">
                  <el-select v-model="selected.params.category" size="small" style="width: 100%">
                    <el-option label="全部分类" value="all" />
                    <el-option v-for="c in prodCategories" :key="c.id" :label="c.name" :value="String(c.id)" />
                  </el-select>
                </el-form-item>
                <el-form-item label="排序方式">
                  <el-radio-group v-model="selected.params.sortway">
                    <el-radio value="default">按排序</el-radio>
                    <el-radio value="ctime">创建时间↓</el-radio>
                    <el-radio value="createtime">创建时间↑</el-radio>
                    <el-radio value="stock">库存</el-radio>
                  </el-radio-group>
                </el-form-item>
              </template>
              <el-form-item label="显示数量">
                <el-input-number v-model="selected.params.displaycount" :min="1" :max="100" size="small" :controls="false" class="de-slider-num" />
              </el-form-item>
              <el-form-item label="商品块圆角">
                <DeSlider v-model="selected.params.blockborder" :min="0" :max="50" />
                <div class="de-form-tip">提示：每个商品容器的圆角，0 表示不圆角</div>
              </el-form-item>
            
              <el-divider>组件样式</el-divider>
              <el-form-item label="组件背景">
                <el-radio-group v-model="selected.params.backgroundtype">
                  <el-radio value="1">颜色</el-radio>
                  <el-radio value="2">图片</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item v-if="selected.params.backgroundtype === '1'" label="背景颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.bgcolor }}</code>
                  <el-color-picker v-model="selected.params.bgcolor" />
                </div>
              </el-form-item>
              <el-form-item v-else label="背景图片">
                <div class="de-slider-row">
                  <img v-if="selected.params.imagebgurl" :src="selected.params.imagebgurl" class="de-badge-thumb" />
                  <el-button size="small" @click="openImgPick((u) => { selected!.params.imagebgurl = u })">选择图片</el-button>
                  <el-button v-if="selected.params.imagebgurl" size="small" link type="danger" @click="selected.params.imagebgurl = ''">移除</el-button>
                </div>
              </el-form-item>
              <el-form-item label="圆角度数"><DeSlider v-model="selected.params.roundness" :max="50" /><span style="font-size:12px;color:#999;margin-left:8px;">注：0 是方形 100 是圆形</span></el-form-item>
              <el-form-item label="左右边距"><DeSlider v-model="selected.params.sidemargin" :max="100" /></el-form-item>
              <el-form-item label="左右内边距"><DeSlider v-model="selected.params.padding_x" :max="100" /></el-form-item>
              <el-form-item label="上下内边距"><DeSlider v-model="selected.params.padding_y" :max="100" /></el-form-item>
            </template>
            
            <!-- tab（任务 32 对照截图重做：双样式 + 选项列表行内设置；选项子内容经「设置内容」弹窗独立存储） -->
            <template v-else-if="selected.temp === 'tab'">
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="tabMarginY" :min="0" :max="100" />
                  <el-input-number v-model="tabMarginY" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-divider>样式设置</el-divider>
              <el-form-item label="样式选择">
                <el-radio-group v-model="selected.params.style">
                  <el-radio value="1">样式一</el-radio>
                  <el-radio value="2">样式二</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="标题大小">
                <div class="de-slider-row">
                  <el-slider v-model="tabFontsize" :min="10" :max="60" />
                  <el-input-number v-model="tabFontsize" :min="10" :max="60" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="标题颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.color1 }}</code>
                  <el-color-picker v-model="selected.params.color1" />
                </div>
              </el-form-item>
              <el-form-item label="选中颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.color2 }}</code>
                  <el-color-picker v-model="selected.params.color2" />
                </div>
              </el-form-item>
              <template v-if="selected.params.style !== '2'">
                <el-form-item label="标题区背景">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.bgcolor }}</code>
                    <el-color-picker v-model="selected.params.bgcolor" />
                  </div>
                </el-form-item>
                <el-form-item label="选项条">
                  <el-radio-group v-model="selected.params.arrowshow">
                    <el-radio value="1">显示</el-radio>
                    <el-radio value="0">不显示</el-radio>
                  </el-radio-group>
                </el-form-item>
                <el-form-item label="选项条颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.arrowcolor }}</code>
                    <el-color-picker v-model="selected.params.arrowcolor" />
                  </div>
                </el-form-item>
              </template>
              <template v-else>
                <el-form-item label="未选中背景">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.unselectbgcolor }}</code>
                    <el-color-picker v-model="selected.params.unselectbgcolor" />
                  </div>
                </el-form-item>
                <el-form-item label="选中边框">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.selectbordercolor }}</code>
                    <el-color-picker v-model="selected.params.selectbordercolor" />
                  </div>
                </el-form-item>
                <el-form-item label="选中背景">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.selectbgcolor }}</code>
                    <el-color-picker v-model="selected.params.selectbgcolor" />
                  </div>
                </el-form-item>
              </template>
              <el-form-item label="内容区背景">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.bgcolor2 }}</code>
                  <el-color-picker v-model="selected.params.bgcolor2" />
                </div>
              </el-form-item>
              <el-form-item label="圆角度">
                <div class="de-slider-row">
                  <el-slider v-model="tabRadius" :min="0" :max="60" />
                  <el-input-number v-model="tabRadius" :min="0" :max="60" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="吸顶效果">
                <el-radio-group v-model="selected.params.sticky">
                  <el-radio value="1">开</el-radio>
                  <el-radio value="0">关</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-divider>标题设置</el-divider>
              <div class="de-form-tip">注意：不建议嵌套使用（存在兼容问题）；表单插件不要紧挨着，上一个表单项选择内容会影响其他表单项选择</div>
              <div v-for="(d, di) in selected.data" :key="di" class="de-data-card col">
                <div class="de-data-card-row">
                  <span class="de-tab-label">标题</span>
                  <el-input v-model="d.name" placeholder="选项标题" class="de-data-input" />
                  <el-button link :disabled="di === 0" title="上移" @click="moveDataItemUp(selected, di)">↑</el-button>
                  <el-button link type="danger" title="删除" @click="removeDataItem(selected, di)">✕</el-button>
                </div>
                <div class="de-data-card-row">
                  <span class="de-tab-label">内容</span>
                  <el-button size="small" @click="openTabDialog(selected)">设置内容</el-button>
                </div>
              </div>
              <el-button size="small" style="width: 100%" @click="addDataItem(selected, { id: genMid(), name: '' })">添加选项</el-button>
            </template>

            <!-- video（对照截图重做：与上方距离/视频高度%/来源/链接/封面/背景色/播放方式/循环/静音/控件/全屏/表现形式/按钮位置） -->
            <template v-else-if="selected.temp === 'video'">
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="vidMarginY" :min="0" :max="100" />
                  <el-input-number v-model="vidMarginY" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="视频高度">
                <div class="de-slider-row">
                  <el-slider v-model="vidHeight" :min="10" :max="100" />
                  <el-input-number v-model="vidHeight" :min="10" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">%</span>
                </div>
              </el-form-item>
              <el-form-item label="视频来源">
                <el-radio-group v-model="selected.params.type">
                  <el-radio value="0">上传视频</el-radio>
                  <el-radio value="1">选择视频</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item v-if="selected.params.type === '0'" label="视频链接">
                <div class="de-data-card-row">
                  <el-input v-model="selected.params.src" size="small" placeholder="填写视频链接或上传视频" />
                  <el-button size="small" :loading="videoUploading" @click="triggerVideoUpload">上传视频</el-button>
                </div>
                <input ref="videoFileInput" type="file" accept="video/*" style="display: none" @change="onVideoFileChange" />
                <div class="de-form-tip">填写视频链接或上传视频，视频链接必须为mp4格式的原始链接</div>
              </el-form-item>
              <el-form-item v-else label="视频链接">
                <div class="de-data-card-row">
                  <el-input size="small" readonly :model-value="selected.params.src" placeholder="未选择" />
                  <el-button size="small" @click="openVideoPicker">选择视频</el-button>
                </div>
                <div class="de-form-tip">从视频列表中选择视频</div>
              </el-form-item>
              <el-form-item label="封面图片">
                <span class="de-pick" @click="openImgPick((u) => (selected!.params.pic = u))">
                  <img v-if="selected.params.pic" class="de-thumb" :src="selected.params.pic" />
                  <span v-else class="de-pick-plus">+</span>
                </span>
              </el-form-item>
              <el-form-item label="播放方式">
                <el-radio-group v-model="selected.params.autoplay">
                  <el-radio value="0">点击播放</el-radio>
                  <el-radio value="1">自动播放</el-radio>
                </el-radio-group>
              </el-form-item>
              <div class="de-form-tip">公众号不支持自动播放</div>
              <el-form-item label="循环播放">
                <el-radio-group v-model="selected.params.loop">
                  <el-radio value="0">关</el-radio>
                  <el-radio value="1">开</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="静音播放">
                <el-radio-group v-model="selected.params.muted">
                  <el-radio value="0">关</el-radio>
                  <el-radio value="1">开</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="播放控件">
                <el-radio-group v-model="selected.params.controls">
                  <el-radio value="1">显示</el-radio>
                  <el-radio value="0">隐藏</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="全屏按钮">
                <el-radio-group v-model="selected.params.fullscreen">
                  <el-radio value="1">显示</el-radio>
                  <el-radio value="0">隐藏</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="表现形式">
                <el-radio-group v-model="selected.params.objectfit">
                  <el-radio value="contain">包含</el-radio>
                  <el-radio value="fill">填充</el-radio>
                  <el-radio value="cover">覆盖</el-radio>
                </el-radio-group>
              </el-form-item>
              <div class="de-form-tip">1、包含：保持原有尺寸比例，内容缩放。<br />2、填充：不保证保持原有的比例，内容拉伸填充整个内容容器。<br />3、覆盖：保持原有尺寸比例，但部分内容可能被剪切。</div>
              <el-form-item label="播放按钮位置">
                <el-radio-group v-model="selected.params.butposition">
                  <el-radio value="center">居中</el-radio>
                  <el-radio value="bar">控制条</el-radio>
                </el-radio-group>
              </el-form-item>
              <div class="de-form-tip">H5端和公众号不支持选择播放的按钮位置</div>
            </template>

            <!-- button（对照截图重做：与上方距离/文字/背景色/边框色/字体大小/字体颜色/宽高/圆角/背景颜色/跳转链接） -->
            <template v-else-if="selected.temp === 'button'">
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="btnMarginY" :min="0" :max="100" />
                  <el-input-number v-model="btnMarginY" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="按钮文字"><el-input v-model="selected.params.btntext" /></el-form-item>
              <el-form-item label="按钮背景">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.btnbgcolor }}</code>
                  <el-color-picker v-model="selected.params.btnbgcolor" />
                </div>
              </el-form-item>
              <el-form-item label="按钮边框颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.btnbordercolor }}</code>
                  <el-color-picker v-model="selected.params.btnbordercolor" />
                </div>
              </el-form-item>
              <el-form-item label="按钮字体大小">
                <div class="de-slider-row">
                  <el-slider v-model="btnFontsize" :min="10" :max="60" />
                  <el-input-number v-model="btnFontsize" :min="10" :max="60" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="按钮字体颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.btncolor }}</code>
                  <el-color-picker v-model="selected.params.btncolor" />
                </div>
              </el-form-item>
              <el-form-item label="按钮宽度">
                <div class="de-slider-row">
                  <el-slider v-model="btnWidth" :min="40" :max="375" />
                  <el-input-number v-model="btnWidth" :min="40" :max="375" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="按钮高度">
                <div class="de-slider-row">
                  <el-slider v-model="btnHeight" :min="20" :max="200" />
                  <el-input-number v-model="btnHeight" :min="20" :max="200" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="按钮圆角">
                <div class="de-slider-row">
                  <el-slider v-model="btnRadius" :min="0" :max="100" />
                  <el-input-number v-model="btnRadius" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="跳转链接">
                <div class="de-data-card-row">
                  <el-input size="small" readonly :model-value="selected.params.hrefurl" placeholder="选择链接" />
                  <el-button size="small" @click="openChooseUrl((u, n) => { selected!.params.hrefurl = u; selected!.params.hrefname = n })">选链接</el-button>
                </div>
              </el-form-item>
            </template>

            <!-- dhlist（对照截图重做：与上方距离/标题大小/标题颜色/标题左间距/备注大小/备注颜色/右箭头 + 列表图标项） -->
            <template v-else-if="selected.temp === 'dhlist'">
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="dhMarginY" :min="0" :max="100" />
                  <el-input-number v-model="dhMarginY" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="标题大小">
                <div class="de-slider-row">
                  <el-slider v-model="dhFontsize1" :min="10" :max="60" />
                  <el-input-number v-model="dhFontsize1" :min="10" :max="60" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="标题颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.color1 }}</code>
                  <el-color-picker v-model="selected.params.color1" />
                </div>
              </el-form-item>
              <el-form-item label="标题左间距">
                <div class="de-slider-row">
                  <el-slider v-model="dhTitleLeft" :min="0" :max="100" />
                  <el-input-number v-model="dhTitleLeft" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="备注大小">
                <div class="de-slider-row">
                  <el-slider v-model="dhFontsize2" :min="10" :max="60" />
                  <el-input-number v-model="dhFontsize2" :min="10" :max="60" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="备注颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.color2 }}</code>
                  <el-color-picker v-model="selected.params.color2" />
                </div>
              </el-form-item>
              <el-form-item label="右箭头">
                <el-radio-group v-model="selected.params.arrowshow">
                  <el-radio value="1">显示</el-radio>
                  <el-radio value="0">不显示</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-divider>列表图标</el-divider>
              <div v-for="(d, di) in selected.data" :key="d.id || di" class="de-data-card col">
                <div class="de-data-card-row" style="justify-content: flex-end">
                  <el-button link :disabled="di === 0" title="上移" @click="moveDataItemUp(selected, di)">↑</el-button>
                  <el-button link type="danger" title="删除" @click="removeDataItem(selected, di)">✕</el-button>
                </div>
                <el-form-item label="图标">
                  <el-radio-group v-model="d.showicon">
                    <el-radio value="1">显示</el-radio>
                    <el-radio value="0">不显示</el-radio>
                  </el-radio-group>
                </el-form-item>
                <el-form-item label="选择图标">
                  <span class="de-pick" @click="openIconPick((u) => (d.imgurl = u))">
                    <img v-if="d.imgurl" class="de-thumb sm" :src="d.imgurl" />
                    <el-button v-else size="small">上传图标</el-button>
                  </span>
                </el-form-item>
                <el-form-item label="图标大小"><DeSlider v-model="d.iconsize" :min="10" :max="60" /></el-form-item>
                <el-form-item label="标题名称"><el-input v-model="d.title1" /></el-form-item>
                <el-form-item label="备注内容"><el-input v-model="d.title2" /></el-form-item>
                <el-form-item label="跳转链接">
                  <div class="de-data-card-row">
                    <el-input size="small" readonly :model-value="d.hrefurl" placeholder="跳转链接" />
                    <el-button size="small" @click="openChooseUrl((u, n) => { d.hrefurl = u; d.hrefname = n })">选链接</el-button>
                  </div>
                </el-form-item>
              </div>
              <el-button style="width: 100%" size="small" @click="addDataItem(selected, { id: genMid(), showicon: '1', imgurl: '', iconsize: '16', title1: '', title2: '', hrefurl: '', hrefname: '' })">添加项</el-button>
            </template>

            <!-- cover（对照截图重做：文本/图标双类型 + 悬浮定位，无通用设置） -->
            <template v-else-if="selected.temp === 'cover'">
              <el-form-item label="类型">
                <el-radio-group v-model="selected.params.style">
                  <el-radio value="1">文本</el-radio>
                  <el-radio value="2">图标</el-radio>
                </el-radio-group>
              </el-form-item>
              <template v-if="selected.params.style === '1'">
                <el-form-item label="文字"><el-input v-model="selected.params.text" /></el-form-item>
                <el-form-item label="字体大小">
                  <div class="de-slider-row">
                    <el-slider v-model="covFontsize" :min="10" :max="60" />
                    <el-input-number v-model="covFontsize" :min="10" :max="60" size="small" :controls="false" class="de-slider-num" />
                    <span class="de-slider-unit">px</span>
                  </div>
                </el-form-item>
                <el-form-item label="字体颜色">
                  <div class="de-slider-row">
                    <code class="de-link-val tt-color-val">{{ selected.params.color }}</code>
                    <el-color-picker v-model="selected.params.color" show-alpha />
                  </div>
                </el-form-item>
              </template>
              <template v-else>
                <el-form-item label="选择图标">
                  <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 6px">
                    <span class="de-pick" @click="openIconPick((u) => (selected!.params.pic = u))">
                      <img v-if="selected.params.pic" class="de-thumb" style="width: 80px; height: 80px" :src="selected.params.pic" />
                      <span v-else class="de-pick-plus">+</span>
                    </span>
                    <el-button size="small" @click="openIconPick((u) => (selected!.params.pic = u))">重新选择图标</el-button>
                  </div>
                </el-form-item>
                <el-form-item label="图标宽度">
                  <div class="de-slider-row">
                    <el-slider v-model="covPicW" :min="10" :max="200" />
                    <el-input-number v-model="covPicW" :min="10" :max="200" size="small" :controls="false" class="de-slider-num" />
                    <span class="de-slider-unit">px</span>
                  </div>
                </el-form-item>
                <el-form-item label="图标高度">
                  <div class="de-slider-row">
                    <el-slider v-model="covPicH" :min="10" :max="200" />
                    <el-input-number v-model="covPicH" :min="10" :max="200" size="small" :controls="false" class="de-slider-num" />
                    <span class="de-slider-unit">px</span>
                  </div>
                </el-form-item>
              </template>
              <el-form-item label="背景颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.bgcolor }}</code>
                  <el-color-picker v-model="selected.params.bgcolor" show-alpha />
                </div>
              </el-form-item>
              <el-form-item label="边框">
                <div class="de-slider-row">
                  <el-slider v-model="covBorder" :min="0" :max="20" />
                  <el-input-number v-model="covBorder" :min="0" :max="20" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="边框颜色">
                <div class="de-slider-row">
                  <code class="de-link-val tt-color-val">{{ selected.params.bordercolor }}</code>
                  <el-color-picker v-model="selected.params.bordercolor" show-alpha />
                </div>
              </el-form-item>
              <el-form-item label="距顶端">
                <div class="de-slider-row">
                  <el-slider v-model="covTop" :min="0" :max="100" />
                  <el-input-number v-model="covTop" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">vh</span>
                </div>
              </el-form-item>
              <el-form-item label="距左端">
                <div class="de-slider-row">
                  <el-slider v-model="covLeft" :min="0" :max="100" />
                  <el-input-number v-model="covLeft" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">vw</span>
                </div>
              </el-form-item>
              <el-form-item label="宽度">
                <div class="de-slider-row">
                  <el-slider v-model="covWidth" :min="20" :max="200" />
                  <el-input-number v-model="covWidth" :min="20" :max="200" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="高度">
                <div class="de-slider-row">
                  <el-slider v-model="covHeight" :min="20" :max="200" />
                  <el-input-number v-model="covHeight" :min="20" :max="200" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="圆角度">
                <div class="de-slider-row">
                  <el-slider v-model="covRadius" :min="0" :max="100" />
                  <el-input-number v-model="covRadius" :min="0" :max="100" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="跳转链接">
                <div class="de-data-card-row">
                  <el-input size="small" readonly :model-value="selected.params.hrefurl" placeholder="选择链接" />
                  <el-button size="small" @click="openChooseUrl((u, n) => { selected!.params.hrefurl = u; selected!.params.hrefname = n })">选链接</el-button>
                </div>
                <div v-if="selected.params.hrefname" class="de-form-tip">功能&gt;{{ selected.params.hrefname }}</div>
              </el-form-item>
            </template>

            <!-- map（任务45） -->
            <template v-else-if="selected.temp === 'map'">
              <el-form-item label="地址"><el-input v-model="selected.params.address" /></el-form-item>
              <div class="de-grid2">
                <el-form-item label="经度"><el-input v-model="selected.params.longitude" /></el-form-item>
                <el-form-item label="纬度"><el-input v-model="selected.params.latitude" /></el-form-item>
              </div>
              <el-form-item label="地图高度"><DeSlider v-model="selected.params.height" :max="750" /></el-form-item>
            </template>

            <!-- hotspot（任务45） -->
            <template v-else-if="selected.temp === 'hotspot'">
              <el-form-item label="背景图片">
                <span class="de-pick" @click="openImgPick((u) => (selected!.params.imgurl = u))">
                  <img v-if="selected.params.imgurl" class="de-thumb lg" :src="selected.params.imgurl" />
                  <el-button v-else size="small">选择图片</el-button>
                </span>
              </el-form-item>
              <el-divider>热区设置</el-divider>
              <div v-for="(s, si) in selected.data" :key="si" class="de-data-card col">
                <div class="de-data-card-row">
                  <el-button size="small" @click="openChooseUrl((u, n) => { s.hrefurl = u; s.hrefname = n })">热区链接</el-button>
                  <code class="de-link-val">{{ s.hrefurl || '未设置' }}</code>
                  <el-button v-if="s.hrefurl" link @click="s.hrefurl = ''">清除</el-button>
                </div>
                <el-form-item label="宽度"><DeSlider v-model="s.width" :max="750" /></el-form-item>
                <el-form-item label="高度"><DeSlider v-model="s.height" :max="750" /></el-form-item>
                <el-form-item label="距左"><DeSlider v-model="s.left" :max="750" /></el-form-item>
                <el-form-item label="距顶"><DeSlider v-model="s.top" :max="750" /></el-form-item>
                <el-button link type="danger" @click="removeDataItem(selected, si)">删除</el-button>
              </div>
              <el-button size="small" @click="addDataItem(selected, { id: genMid(), hrefurl: '', hrefname: '', width: '80', height: '80', left: '0', top: '0' })">+ 添加热区</el-button>
            </template>

            <!-- cube（任务100 全量：16宫格布局编辑 + 图位编辑卡） -->
            <template v-else-if="selected.temp === 'cube'">
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="cubeMarginY" :min="0" :max="40" />
                  <el-input-number v-model="cubeMarginY" :min="0" :max="40" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="背景颜色">
                <el-color-picker v-model="selected.params.bgcolor" show-alpha />
                <code class="de-link-val">{{ selected.params.bgcolor }}</code>
              </el-form-item>
              <el-form-item label="设置图片布局">
                <div class="cube-grid">
                  <template v-for="c in cubeCells" :key="`${c.x}-${c.y}`">
                    <div
                      v-if="c.slot"
                      class="cube-cell slot"
                      :class="{ active: cubeSlotIndex === c.index }"
                      :style="{ gridColumn: `${c.x + 1} / span ${c.slot.w}`, gridRow: `${c.y + 1} / span ${c.slot.h}`, backgroundColor: cubeSlotColor(c.index) }"
                      @click="selectCubeSlot(c.index)"
                    >
                      <img v-if="c.slot.imgurl" :src="c.slot.imgurl" />
                      <span v-else class="cube-cell-size">{{ c.slot.w * CUBE_UNIT }} * {{ c.slot.h * CUBE_UNIT }}</span>
                    </div>
                    <div
                      v-else
                      class="cube-cell empty"
                      :style="{ gridColumn: `${c.x + 1}`, gridRow: `${c.y + 1}` }"
                      @click="openCubeLayout(c.x, c.y)"
                    >+</div>
                  </template>
                </div>
              </el-form-item>

              <!-- 选中图位编辑卡：缩略图/重选图片/建议尺寸+链接/删除 -->
              <div v-if="cubeActiveSlot" class="cube-edit-card">
                <span class="cube-edit-del" title="移除图位" @click="removeCubeSlot">✕</span>
                <div class="cube-edit-body">
                  <span class="cube-edit-thumb" @click="openImgPick((u) => { if (cubeActiveSlot) cubeActiveSlot.imgurl = u })">
                    <img v-if="cubeActiveSlot.imgurl" :src="cubeActiveSlot.imgurl" />
                    <span v-else class="cube-edit-plus">+</span>
                    <em v-if="cubeActiveSlot.imgurl" class="cube-edit-mask">重新选择图片</em>
                  </span>
                  <div class="cube-edit-right">
                    <div class="cube-edit-size">建议尺寸: {{ cubeActiveSlot.w * CUBE_UNIT }} × {{ cubeActiveSlot.h * CUBE_UNIT }} 像素</div>
                    <div class="cube-edit-link">
                      <el-input v-model="cubeActiveSlot.hrefurl" size="small" placeholder="链接地址" />
                      <el-button size="small" @click="openChooseUrl((u, n) => { if (cubeActiveSlot) { cubeActiveSlot.hrefurl = u; cubeActiveSlot.hrefname = n } })">选链接</el-button>
                    </div>
                  </div>
                </div>
              </div>

              <el-form-item label="左右边距">
                <div class="de-slider-row">
                  <el-slider v-model="cubeMarginX" :min="0" :max="40" />
                  <el-input-number v-model="cubeMarginX" :min="0" :max="40" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="左右内边距">
                <div class="de-slider-row">
                  <el-slider v-model="cubePaddingX" :min="0" :max="40" />
                  <el-input-number v-model="cubePaddingX" :min="0" :max="40" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="上下内边距">
                <div class="de-slider-row">
                  <el-slider v-model="cubePaddingY" :min="0" :max="40" />
                  <el-input-number v-model="cubePaddingY" :min="0" :max="40" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
            </template>

            <!-- shop（任务02 店招：对齐参考口径/样式/背景图/logo/店铺名/导航/按钮颜色/按钮配置） -->
            <template v-else-if="selected.temp === 'shop'">
              <el-form-item label="选择样式">
                <el-radio-group v-model="selected.params.style">
                  <el-radio value="1">样式一</el-radio>
                  <el-radio value="2">样式二</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item label="背景图片">
                <span class="de-pick" @click="openImgPick((u) => (selected!.params.bgimg = u))">
                  <img v-if="selected.params.bgimg" class="de-thumb lg" :src="selected.params.bgimg" />
                  <el-button v-else size="small">选择背景图片</el-button>
                </span>
                <el-button v-if="selected.params.bgimg" link @click="selected!.params.bgimg = ''">重新选择</el-button>
              </el-form-item>
              <el-form-item label="店铺名称"><el-input v-model="selected.params.shopname" placeholder="店铺名称" /></el-form-item>
              <el-form-item label="商城logo">
                <div class="de-data-card-row">
                  <el-switch v-model="selected.params.logo" active-value="1" inactive-value="2" active-text="显示" inactive-text="不显示" />
                  <span v-if="selected.params.logo === '1'" class="de-pick" @click="openImgPick((u) => (selected!.params.logourl = u))">
                    <img v-if="selected.params.logourl" class="de-thumb sm" :src="selected.params.logourl" />
                    <el-button v-else size="small">选择logo</el-button>
                  </span>
                </div>
              </el-form-item>
              <el-form-item label="商城名称"><el-switch v-model="selected.params.name" active-value="1" inactive-value="2" active-text="显示" inactive-text="不显示" /></el-form-item>
              <el-form-item label="默认导航"><el-switch v-model="selected.params.menu" active-value="1" inactive-value="2" active-text="显示" inactive-text="不显示" /></el-form-item>
              <el-form-item label="按钮颜色">
                <el-color-picker v-model="selected.params.navcolor" />
                <code class="de-link-val">{{ selected.params.navcolor }}</code>
              </el-form-item>
              <el-form-item label="菜单高度">
                <div class="de-slider-row">
                  <el-slider v-model="shopNavH" :min="24" :max="60" />
                  <el-input-number v-model="shopNavH" :min="24" :max="60" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="菜单图标">
                <div class="de-slider-row">
                  <el-slider v-model="shopNavIcon" :min="10" :max="30" />
                  <el-input-number v-model="shopNavIcon" :min="10" :max="30" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="菜单文字">
                <div class="de-slider-row">
                  <el-slider v-model="shopNavFont" :min="10" :max="20" />
                  <el-input-number v-model="shopNavFont" :min="10" :max="20" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <template v-if="selected.params.menu === '1'">
                <el-divider>按钮配置</el-divider>
                <div v-for="(d, di) in selected.data" :key="di" class="de-data-card col">
                  <el-input v-model="d.text" placeholder="按钮文字" />
                  <div class="de-data-card-row">
                    <span class="de-pick" @click="openIconPick((u) => (d.imgurl = u))">
                      <img v-if="d.imgurl" class="de-thumb sm" :src="d.imgurl" />
                      <el-button v-else size="small">图标</el-button>
                    </span>
                    <el-button size="small" @click="openChooseUrl((u, n) => { d.hrefurl = u; d.hrefname = n })">选链接</el-button>
                    <code class="de-link-val">{{ d.hrefurl || '未设置' }}</code>
                    <el-button v-if="d.hrefurl" link @click="d.hrefurl = ''">清除</el-button>
                  </div>
                  <el-button link type="danger" @click="removeDataItem(selected, di)">删除</el-button>
                </div>
                <el-button size="small" @click="addDataItem(selected, { id: genMid(), text: '', imgurl: '', hrefurl: '', hrefname: '' })">+ 添加按钮</el-button>
              </template>
            </template>

            <!-- featured（任务03 重做：左轮播+右双图，图片组件口径） -->
            <template v-else-if="selected.temp === 'featured'">
              <el-form-item label="与上方间距">
                <div class="de-slider-row">
                  <el-slider v-model="featMarginY" :min="0" :max="40" />
                  <el-input-number v-model="featMarginY" :min="0" :max="40" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="组件高度">
                <div class="de-slider-row">
                  <el-slider v-model="featHeight" :min="120" :max="500" />
                  <el-input-number v-model="featHeight" :min="120" :max="500" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-divider>左侧轮播图片</el-divider>
              <div class="vp-tip">建议尺寸: 340px*480px; 具体尺寸可根据组件高度调整</div>
              <div v-for="(d, di) in selected.data" :key="di" class="de-data-card col">
                <div class="de-data-card-row feat-card-top">
                  <el-button link :disabled="di === 0" title="上移" @click="moveDataItem(selected, di, -1)">↑</el-button>
                  <el-button link type="danger" title="删除" @click="removeDataItem(selected, di)">✕</el-button>
                </div>
                <div class="de-data-card-row">
                  <span class="de-pick" @click="openImgPick((u) => (d.imgurl = u))">
                    <img v-if="d.imgurl" class="de-thumb" :src="d.imgurl" />
                    <em v-if="d.imgurl" class="feat-pick-mask">重新选择图片</em>
                    <el-button v-else size="small">选择图片</el-button>
                  </span>
                  <el-input v-model="d.hrefurl" size="small" placeholder="请选择跳转链接" />
                  <el-button size="small" @click="openChooseUrl((u, n) => { d.hrefurl = u; d.hrefname = n })">选链接</el-button>
                </div>
              </div>
              <el-button size="small" style="width: 100%" @click="addDataItem(selected, { id: genMid(), imgurl: '', hrefurl: '', hrefname: '' })">添加轮播</el-button>
              <el-form-item label="指示点" style="margin-top: 12px">
                <el-radio-group v-model="selected.params.indicatordots">
                  <el-radio value="1">显示</el-radio>
                  <el-radio value="0">不显示</el-radio>
                </el-radio-group>
              </el-form-item>
              <template v-if="selected.params.indicatordots === '1'">
                <el-form-item label="指示点形状">
                  <el-radio-group v-model="selected.params.dotshape">
                    <el-radio value="0">默认</el-radio>
                    <el-radio value="1">长方形</el-radio>
                    <el-radio value="2">圆形</el-radio>
                    <el-radio value="3">正方形</el-radio>
                  </el-radio-group>
                </el-form-item>
                <el-form-item label="指示点位置">
                  <el-radio-group v-model="selected.params.dotposition">
                  <el-radio value="left">居左</el-radio>
                  <el-radio value="center">居中</el-radio>
                  <el-radio value="right">居右</el-radio>
                  </el-radio-group>
                </el-form-item>
                <el-form-item label="与下方距离">
                  <div class="de-slider-row">
                    <el-slider v-model="featDotBottom" :min="0" :max="40" />
                    <el-input-number v-model="featDotBottom" :min="0" :max="40" size="small" :controls="false" class="de-slider-num" />
                    <span class="de-slider-unit">px</span>
                  </div>
                </el-form-item>
                <el-form-item label="指示点颜色">
                  <el-color-picker v-model="selected.params.dotcolor" />
                  <code class="de-link-val">{{ selected.params.dotcolor }}</code>
                </el-form-item>
                <el-form-item label="选中指示点颜色">
                  <el-color-picker v-model="selected.params.dotactivecolor" />
                  <code class="de-link-val">{{ selected.params.dotactivecolor }}</code>
                </el-form-item>
              </template>
              <el-form-item label="自动切换时间">
                <div class="de-slider-row">
                  <el-slider v-model="featInterval" :min="1" :max="10" />
                  <el-input-number v-model="featInterval" :min="1" :max="10" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">秒</span>
                </div>
              </el-form-item>
              <el-divider>右侧图片设置</el-divider>
              <div class="vp-tip">建议尺寸: 340px*240px; 具体尺寸可根据组件高度调整</div>
              <el-form-item label="右上方图">
                <div class="de-data-card-row">
                  <span class="de-pick" @click="openImgPick((u) => (selected!.params.imgtop = u))">
                    <img v-if="selected.params.imgtop" class="de-thumb" :src="selected.params.imgtop" />
                    <em v-if="selected.params.imgtop" class="feat-pick-mask">重新选择图片</em>
                    <el-button v-else size="small">选择图片</el-button>
                  </span>
                  <el-input v-model="selected.params.imgtopurl" size="small" placeholder="请选择跳转链接" />
                  <el-button size="small" @click="openChooseUrl((u, n) => { selected!.params.imgtopurl = u; selected!.params.imgtopname = n })">选链接</el-button>
                </div>
              </el-form-item>
              <el-form-item label="右下方图">
                <div class="de-data-card-row">
                  <span class="de-pick" @click="openImgPick((u) => (selected!.params.imgbot = u))">
                    <img v-if="selected.params.imgbot" class="de-thumb" :src="selected.params.imgbot" />
                    <em v-if="selected.params.imgbot" class="feat-pick-mask">重新选择图片</em>
                    <el-button v-else size="small">选择图片</el-button>
                  </span>
                  <el-input v-model="selected.params.imgboturl" size="small" placeholder="请选择跳转链接" />
                  <el-button size="small" @click="openChooseUrl((u, n) => { selected!.params.imgboturl = u; selected!.params.imgbotname = n })">选链接</el-button>
                </div>
              </el-form-item>
              <el-divider>组件样式</el-divider>
              <el-form-item label="背景颜色">
                <el-input v-model="selected.params.bgcolor" size="small" style="width: 150px" />
                <el-color-picker v-model="selected.params.bgcolor" show-alpha />
              </el-form-item>
              <el-form-item label="左右边距">
                <div class="de-slider-row">
                  <el-slider v-model="featMarginX" :min="0" :max="40" />
                  <el-input-number v-model="featMarginX" :min="0" :max="40" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="左右内边距">
                <div class="de-slider-row">
                  <el-slider v-model="featPaddingX" :min="0" :max="40" />
                  <el-input-number v-model="featPaddingX" :min="0" :max="40" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
              <el-form-item label="上下内边距">
                <div class="de-slider-row">
                  <el-slider v-model="featPaddingY" :min="0" :max="40" />
                  <el-input-number v-model="featPaddingY" :min="0" :max="40" size="small" :controls="false" class="de-slider-num" />
                  <span class="de-slider-unit">px</span>
                </div>
              </el-form-item>
            </template>

            <!-- orderbroadcast（任务20：订单播报，数值统一 DeSlider 口径） -->
            <template v-else-if="selected.temp === 'orderbroadcast'">
              <el-form-item label="与上方间距"><DeSlider v-model="selected.params.margin_y" :max="100" /></el-form-item>
              <el-form-item label="组件高度"><DeSlider v-model="selected.params.height" :min="24" :max="120" /></el-form-item>
              <el-form-item label="左右边距"><DeSlider v-model="selected.params.margin_x" :max="100" /></el-form-item>
              <el-form-item label="圆角度"><DeSlider v-model="selected.params.borderradius" :max="60" /></el-form-item>
              <el-form-item label="背景颜色">
                <el-input v-model="selected.params.bgcolor" size="small" style="width: 150px" />
                <el-color-picker v-model="selected.params.bgcolor" show-alpha />
              </el-form-item>
              <el-form-item label="文字颜色">
                <el-input v-model="selected.params.color" size="small" style="width: 150px" />
                <el-color-picker v-model="selected.params.color" />
              </el-form-item>
              <el-form-item label="头像显示">
                <el-switch v-model="selected.params.showavatar" active-value="1" inactive-value="0" />
              </el-form-item>
              <el-form-item label="自动轮播间隔"><DeSlider v-model="selected.params.interval" :min="1" :max="30" unit="秒" /></el-form-item>
              <div class="de-form-tip">数据源为最近购买记录；系统设置基础设置关闭订单播报后 C 端不展示</div>
            </template>

            <!-- coupon：优惠券组件属性（商品组设置+ yhq.png） -->
            <template v-else-if="selected.temp === 'coupon'">
              <!-- 标题颜色 -->
              <el-form-item label="标题颜色">
                <el-input v-model="selected.params.titlecolor" size="small" style="width: 120px" />
                <el-color-picker v-model="selected.params.titlecolor" />
              </el-form-item>
              <!-- 备注颜色 -->
              <el-form-item label="备注颜色">
                <el-input v-model="selected.params.remarkcolor" size="small" style="width: 120px" />
                <el-color-picker v-model="selected.params.remarkcolor" />
              </el-form-item>
              <!-- 背景样式：四个预设单选 + 预览图 + 选择图标改色 -->
              <el-form-item label="背景样式">
                <el-radio-group v-model="selected.params.bgpic" style="display:grid; grid-template-columns:1fr 1fr; gap:6px 16px; margin-bottom:8px;">
                  <el-radio value="/imgsrc/coupon1.png">样式一</el-radio>
                  <el-radio value="/imgsrc/coupon2.png">样式二</el-radio>
                  <el-radio value="/imgsrc/coupon3.png">样式三</el-radio>
                  <el-radio value="/imgsrc/coupon4.png">样式四</el-radio>
                </el-radio-group>
                <div class="coupon-bg-upload" @click="openImgPick((u) => { selected!.params.bgpic = u })">
                  <img v-if="selected.params.bgpic" :src="selected.params.bgpic" />
                  <span v-else>自己上传</span>
                </div>
                <div style="margin-top:6px; display:flex; gap:6px; align-items:center;">
                  <el-button size="small" @click="couponIconPickerVisible = true">选择图标(可改色)</el-button>
                  <el-button v-if="selected.params.bgpic" size="small" type="danger" plain @click="selected.params.bgpic = ''">清除</el-button>
                </div>
              </el-form-item>
              <!-- 背景颜色 -->
              <el-form-item label="背景颜色">
                <el-input v-model="selected.params.bgcolor" size="small" style="width: 120px" />
                <el-color-picker v-model="selected.params.bgcolor" />
              </el-form-item>
              <!-- 来源：0手动选择 / 1自动获取 -->
              <el-form-item label="来源">
                <el-radio-group v-model="selected.params.couponfrom">
                  <el-radio value="0">手动选择</el-radio>
                  <el-radio value="1">自动获取</el-radio>
                </el-radio-group>
              </el-form-item>
              <!-- 手动选择：已选优惠券列表 -->
              <template v-if="selected.params.couponfrom === '0'">
                <el-divider>优惠券设置</el-divider>
                <el-form-item label="" style="margin-bottom: 0;">
                  <el-button class="de-coupon-add-btn" @click="openCouponPicker">添加</el-button>
                </el-form-item>
                <div v-if="selected.data && selected.data.length > 0">
                  <div v-for="(coupon, idx) in selected.data" :key="coupon.id || idx" class="de-coupon-card">
                    <button class="de-coupon-up" @click="moveCouponUp(idx)" :disabled="idx === 0" title="上移" :style="{ opacity: idx === 0 ? 0.4 : 1, cursor: idx === 0 ? 'not-allowed' : 'pointer' }">↑</button>
                    <button class="de-coupon-close" @click="removeCoupon(idx)" title="移除">×</button>
                    <div class="de-coupon-row">
                      <span class="de-coupon-label">优惠券名称</span>
                      <span class="de-coupon-value">{{ coupon.name }}</span>
                    </div>
                    <div class="de-coupon-row" v-if="String(coupon.type) === '1'">
                      <span class="de-coupon-label">优惠金额</span>
                      <span class="de-coupon-value"><span style="font-size:16px;">¥{{ coupon.money }}</span></span>
                    </div>
                    <div class="de-coupon-row" v-if="String(coupon.type) === '1'">
                      <span class="de-coupon-label">使用条件</span>
                      <span class="de-coupon-value">{{ coupon.minprice > 0 ? '满' + coupon.minprice + '元可用' : '无门槛' }}</span>
                    </div>
                    <div class="de-coupon-row" v-if="String(coupon.type) !== '1'">
                      <span class="de-coupon-label">类型</span>
                      <span class="de-coupon-value"><el-tag size="small" :type="getTypeTag(coupon.type)">{{ coupon.type_txt || coupon.type_name }}</el-tag></span>
                    </div>
                    <div class="de-coupon-row" v-if="String(coupon.type) === '3'">
                      <span class="de-coupon-label">可用次数</span>
                      <span class="de-coupon-value">{{ coupon.limit_count || coupon.value || '-' }}</span>
                    </div>
                  </div>
                </div>
                <el-empty v-else description="暂未添加优惠券" :image-size="60" style="padding: 16px 0;" />
              </template>
              <!-- 自动获取：排序方式 + 显示数量 -->
              <template v-if="selected.params.couponfrom === '1'">
                <el-divider>排序设置</el-divider>
                <el-form-item label="排序方式">
                  <el-radio-group v-model="selected.params.sortby">
                    <el-radio value="sort">按排序</el-radio>
                    <el-radio value="createtimedesc">创建时间↓</el-radio>
                    <el-radio value="createtime">创建时间↑</el-radio>
                    <el-radio value="stock">库存</el-radio>
                  </el-radio-group>
                </el-form-item>
                <el-form-item label="显示数量">
                  <el-input-number v-model="selected.params.shownum" :min="1" :max="100" />
                </el-form-item>
              </template>
              <!-- 四个边距 -->
              <el-divider>边距设置</el-divider>
              <el-form-item label="左右外边距"><DeSlider v-model="selected.params.margin_x" :max="40" /></el-form-item>
              <el-form-item label="上下外边距"><DeSlider v-model="selected.params.margin_y" :max="40" /></el-form-item>
              <el-form-item label="左右内边距"><DeSlider v-model="selected.params.padding_x" :max="40" /></el-form-item>
              <el-form-item label="上下内边距"><DeSlider v-model="selected.params.padding_y" :max="40" /></el-form-item>
            </template>


            <template v-if="selected && selected.temp !== 'cube' && selected.temp !== 'featured' && selected.temp !== 'orderbroadcast' && selected.temp !== 'banner' && selected.temp !== 'picture' && selected.temp !== 'pictures' && selected.temp !== 'title' && selected.temp !== 'text' && selected.temp !== 'cover' && selected.temp !== 'product' && selected.temp !== 'coupon'">
              <el-divider>通用设置</el-divider>
              <el-form-item label="背景颜色"><el-color-picker v-model="selected.params.bgcolor" show-alpha /></el-form-item>
              <el-form-item label="左右边距"><DeSlider v-model="selected.params.margin_x" :max="100" /></el-form-item>
              <el-form-item label="上下边距"><DeSlider v-model="selected.params.margin_y" :max="100" /></el-form-item>
              <el-form-item label="左右内边距"><DeSlider v-model="selected.params.padding_x" :max="100" /></el-form-item>
              <el-form-item label="上下内边距"><DeSlider v-model="selected.params.padding_y" :max="100" /></el-form-item>
            </template>
            <!-- 显示设置（任务45 通用参数：platform 端可见 / quanxian 会员等级可见；悬浮按钮也保留） -->
            <el-divider>显示设置</el-divider>
            <el-form-item label="显示平台" class="de-top-item">
              <div class="de-plat-grid">
                <el-checkbox v-model="platformAll">所有平台</el-checkbox>
                <el-checkbox-group v-model="platformChecked">
                  <el-checkbox v-for="p in PLATFORM_OPTIONS" :key="p.value" :value="p.value">{{ p.label }}</el-checkbox>
                </el-checkbox-group>
              </div>
            </el-form-item>
            <el-form-item label="显示范围" class="de-top-item">
              <div class="de-plat-grid">
                <el-checkbox v-model="quanxianAll">所有人</el-checkbox>
                <el-checkbox-group v-model="quanxianChecked">
                  <el-checkbox v-for="lv in MEMBER_LEVEL_OPTIONS" :key="lv.value" :value="lv.value">{{ lv.label }}</el-checkbox>
                </el-checkbox-group>
              </div>
            </el-form-item>
          </el-form>
        </template>
      </div>
    </div>

    <!-- 预览弹窗：渲染完整页面 -->
    <el-dialog v-model="previewVisible" title="页面预览" width="460px" align-center>
      <div class="de-preview-phone">
        <div class="de-canvas-topbar static" :style="{ backgroundColor: canvasNavBg }">
          <span class="de-canvas-topbar-title" :style="{ color: canvasNavText }">{{ pageTitle || '页面标题' }}</span>
        </div>
        <div class="de-phone-body" :style="{ background: canvasPageBg }">
        <div v-for="mod in items" :key="mod.id">
          <div :style="boxStyle(mod.params, mod.temp)">
            <div v-if="mod.temp === 'banner'" class="pv-banner" :style="{ height: `${Number(mod.params.height || 200) / 2}px`, borderRadius: `${mod.params.borderradius || 0}px` }">
              <img v-if="mod.data?.length && mod.data[0].imgurl" :src="mod.data[0].imgurl" />
              <span v-else class="pv-placeholder">轮播图</span>
            </div>
            <div v-else-if="mod.temp === 'search'" class="pv-search" :style="{ backgroundColor: mod.params.bgcolor, borderRadius: `${mod.params.borderradius || 5}px` }">
              <span class="pv-search-icon">🔍</span><span :style="{ color: mod.params.color }">{{ mod.params.placeholder }}</span>
            </div>
            <div v-else-if="mod.temp === 'notice'" class="pv-notice" :style="{ color: mod.params.color, fontSize: `${mod.params.fontsize || 14}px` }">
              <img v-if="String(mod.params.showimg) === '1' && mod.params.img" class="pv-notice-img" :src="mod.params.img" />
              <img v-if="String(mod.params.showicon) === '1' && mod.params.icon" class="pv-notice-icon-img" :src="mod.params.icon" />
              <span v-else-if="String(mod.params.showicon) === '1'" class="pv-notice-icon">📢</span><span>{{ mod.data?.[0]?.title || '暂无公告' }}</span>
            </div>
            <div v-else-if="mod.temp === 'menu'" class="pv-menu" :style="{ gridTemplateColumns: `repeat(${mod.params.pernum || 5}, 1fr)` }">
              <div v-for="(d, di) in mod.data || []" :key="di" class="pv-menu-item">
                <img v-if="d.imgurl" :src="d.imgurl" :style="{ width: `${mod.params.iconsize || 40}px`, height: `${mod.params.iconsize || 40}px` }" />
                <span v-else class="pv-menu-icon-ph" :style="{ width: `${mod.params.iconsize || 40}px`, height: `${mod.params.iconsize || 40}px` }">{{ di + 1 }}</span>
                <em v-if="mod.params.showtitle === '1'">{{ d.title || '菜单' }}</em>
              </div>
            </div>
            <div v-else-if="mod.temp === 'picture'" class="pv-picture" :style="{ borderRadius: `${mod.params.borderradius || 0}px` }">
              <template v-for="(d, di) in mod.data || []" :key="di">
                <img v-if="d.imgurl" :src="d.imgurl" />
              </template>
              <span v-if="!(mod.data || []).some((d: any) => d && d.imgurl)" class="pv-placeholder">单图</span>
            </div>
            <div v-else-if="mod.temp === 'pictures'" class="pv-pictures" :class="[`lay-${picsLay(mod)}`]">
              <div v-for="(d, di) in mod.data || []" :key="di" class="pv-pictures-item" :style="{ borderRadius: `${mod.params.imageRadius || 0}px` }">
                <img v-if="d.imgurl" :src="d.imgurl" /><span v-else class="pv-placeholder sm">图{{ di + 1 }}</span>
              </div>
            </div>
            <div v-else-if="mod.temp === 'title'" class="pv-title" :style="{ ...boxStyle(mod.params), backgroundColor: mod.params.bgcolor || '#F6F6F6', color: mod.params.color || '#333', fontSize: `${mod.params.fontsize || 14}px` }">
              <i class="pv-title-line" :style="{ borderColor: mod.params.linecolor || '#DDDDDD' }"></i>
              <img v-if="String(mod.params.showicon ?? '1') === '1' && mod.params.imgurl" class="pv-title-icon" :src="mod.params.imgurl" :style="{ width: `${mod.params.iconsize || 14}px`, height: `${mod.params.iconsize || 14}px` }" />
              <span>{{ mod.params.title || '标题' }}</span>
              <i class="pv-title-line" :style="{ borderColor: mod.params.linecolor || '#DDDDDD' }"></i>
            </div>
            <div v-else-if="mod.temp === 'text'" class="pv-text" :style="{ ...boxStyle(mod.params), backgroundColor: mod.params.bgcolor || '#FFFFFF', color: mod.params.color || '#000', fontSize: `${mod.params.fontsize || 14}px`, lineHeight: `${mod.params.lineheight || 20}px`, letterSpacing: `${mod.params.letterspacing || 0}px`, fontWeight: String(mod.params.isbold ?? mod.params.fontbold ?? '0') !== '0' ? 'bold' : 'normal', backgroundImage: mod.params.bgimg ? `url(${mod.params.bgimg})` : '', backgroundSize: 'cover', textAlign: mod.params.align || 'left' }">{{ mod.params.content }}</div>
            <div v-else-if="mod.temp === 'richtext'" class="pv-richtext" v-html="mod.content || '富文本内容'"></div>
            <div v-else-if="mod.temp === 'blank'" class="pv-blank" :style="{ height: `${mod.params.height || 50}px` }"></div>
            <div v-else-if="mod.temp === 'line'" class="pv-line" :style="lineStyle(mod.params)"></div>
            <div v-else-if="mod.temp === 'product'" class="pv-product-box" :style="{ backgroundColor: mod.params.bgmode === '1' ? undefined : (mod.params.bgcolor || '#FFFFFF'), borderRadius: `${mod.params.borderradius || 0}px`, backgroundImage: mod.params.bgmode === '1' && mod.params.bgimg ? `url(${mod.params.bgimg})` : undefined, backgroundSize: 'cover', backgroundPosition: 'center' }">
              <div v-if="mod.params.titleshow === '1'" class="pv-product-head" :style="{ padding: `${mod.params.padding_y || 8}px ${mod.params.padding_x || 8}px 0` }">
                <b :style="{ fontSize: `${mod.params.titlefontsize || 14}px`, color: mod.params.titlecolor || '#000000' }">{{ mod.params.titletext || '商城商品' }}</b>
                <span :style="{ fontSize: `${mod.params.notefontsize || 12}px`, color: mod.params.notecolor || '#999999' }">{{ mod.params.notetext || '查看更多' }}<i v-if="mod.params.arrowshow === '1'" class="pv-product-arrow">›</i></span>
              </div>
              <div class="pv-product" :class="[`lay-${mod.params.style || '2'}`]" :style="{ padding: `${mod.params.pro_margin_y || 0}px ${mod.params.pro_margin_x || 0}px` }">
                <!-- 有手动选品时取 data，否则用示例兜底（最多显示 4 个） -->
                <div v-for="(item, di) in (mod.data || []).slice(0, 4)" :key="di" class="pv-product-item" :style="{ borderRadius: `${mod.params.proradius || 5}px` }">
                  <div class="pv-product-img" :style="{ borderRadius: `${mod.params.imgradius || 0}px` }">
                    <img v-if="mod.params.badgeimg" :src="mod.params.badgeimg" class="pv-product-badgeimg" />
                    <span v-else-if="mod.params.probadge !== '0'" class="pv-product-badge">{{ prodBadgeText(mod.params) }}</span>
                    <img v-else-if="item.image" :src="item.image" class="pv-product-example" />
                  </div>
                  <div v-if="mod.params.nameshow === '1'" class="pv-product-name" :style="{ fontSize: `${mod.params.namefontsize || 16}px`, color: mod.params.namecolor || '#000000', fontWeight: mod.params.namebold === '1' ? '600' : 'normal' }">{{ item.name || '商品名称示例' }}</div>
                  <div class="pv-product-foot">
                    <span v-if="mod.params.priceshow !== '0'" class="pv-product-price" :style="{ color: prodPriceColor(mod.params), fontWeight: mod.params.pricebold === '1' ? '600' : 'normal' }">¥{{ Number(item.price || 19.00).toFixed(2) }}<s v-if="mod.params.priceshow === '1'" class="pv-product-market">¥{{ Number(item.market_price || item.sell_price || 29.00).toFixed(2) }}</s></span>
                    <span v-if="mod.params.salesshow === '1'" class="pv-product-sales" :style="{ color: mod.params.salescolor || '#999999', fontWeight: mod.params.salesbold === '1' ? '600' : 'normal' }">销量{{ item.sales || 0 }}</span>
                    <img v-if="mod.params.buybtnshow === '2' && mod.params.buybtnimg" :src="mod.params.buybtnimg" class="pv-product-buyimg" />
                    <span v-else-if="mod.params.buybtnshow === '1'" class="pv-product-cart" :style="{ backgroundColor: prodPriceColor(mod.params) }">🛒</span>
                  </div>
                </div>
                <!-- 空数据时用示例填充到 4 个 -->
                <template v-if="!(mod.data || []).length">
                  <div v-for="n in 4" :key="n" class="pv-product-item" :style="{ borderRadius: `${mod.params.proradius || 5}px` }">
                    <div class="pv-product-img" :style="{ borderRadius: `${mod.params.imgradius || 0}px` }">
                      <img v-if="mod.params.badgeimg" :src="mod.params.badgeimg" class="pv-product-badgeimg" />
                      <span v-else-if="mod.params.probadge !== '0'" class="pv-product-badge">{{ prodBadgeText(mod.params) }}</span>
                    </div>
                    <div v-if="mod.params.nameshow === '1'" class="pv-product-name" :style="{ fontSize: `${mod.params.namefontsize || 16}px`, color: mod.params.namecolor || '#000000', fontWeight: mod.params.namebold === '1' ? '600' : 'normal' }">商品名称示例</div>
                    <div class="pv-product-foot">
                      <span v-if="mod.params.priceshow !== '0'" class="pv-product-price" :style="{ color: prodPriceColor(mod.params), fontWeight: mod.params.pricebold === '1' ? '600' : 'normal' }">¥19.00<s v-if="mod.params.priceshow === '1'">¥29.00</s></span>
                      <span v-if="mod.params.salesshow === '1'" class="pv-product-sales" :style="{ color: mod.params.salescolor || '#999999', fontWeight: mod.params.salesbold === '1' ? '600' : 'normal' }">销量 26</span>
                      <img v-if="mod.params.buybtnshow === '2' && mod.params.buybtnimg" :src="mod.params.buybtnimg" class="pv-product-buyimg" />
                      <span v-else-if="mod.params.buybtnshow === '1'" class="pv-product-cart" :style="{ backgroundColor: prodPriceColor(mod.params) }">🛒</span>
                    </div>
                  </div>
                </template>
              </div>
            </div>
            <div v-else-if="mod.temp === 'tab'" class="pv-tab" :style="{ borderRadius: `${mod.params.borderradius || 0}px`, overflow: 'hidden' }">
              <div class="pv-tab-head" :class="{ s2: mod.params.style === '2' }" :style="mod.params.style === '2' ? { backgroundColor: 'transparent' } : { backgroundColor: mod.params.bgcolor || '#F7F7F8' }">
                <span
                  v-for="(d, di) in mod.data || []"
                  :key="di"
                  class="pv-tab-item"
                  :class="{ s2: mod.params.style === '2', on: di === 0 }"
                  :style="tabItemStyle(mod, di)"
                >
                  {{ d.name || `选项${di + 1}` }}
                  <i v-if="di === 0 && mod.params.style !== '2' && mod.params.arrowshow === '1'" :style="{ backgroundColor: mod.params.arrowcolor || '#FF9900' }"></i>
                </span>
              </div>
              <div class="pv-tab-body" :style="{ backgroundColor: mod.params.bgcolor2 || '#FFFFFF' }">选项卡内容区域</div>
            </div>
            <div v-else-if="mod.temp === 'video'" class="pv-video" :style="{ height: vidHeightPx(mod.params) + 'px' }">
              <img v-if="mod.params.pic" :src="mod.params.pic" /><span class="pv-video-play">▶</span><span v-if="!mod.params.pic" class="pv-placeholder">视频</span>
            </div>
            <div v-else-if="mod.temp === 'button'" class="pv-button">
              <span :style="{ width: `${mod.params.btnwidth || 120}px`, height: `${mod.params.btnheight || 40}px`, backgroundColor: mod.params.btnbgcolor || '#FD4A46', color: mod.params.btncolor || '#FFFFFF', fontSize: `${mod.params.btnfontsize || 14}px`, borderRadius: `${mod.params.btnradius || 0}px` }">{{ mod.params.btntext || '按钮' }}</span>
            </div>
            <div v-else-if="mod.temp === 'dhlist'" class="pv-dhlist" :style="{ backgroundColor: mod.params.bgcolor || '#FFFFFF' }">
              <div v-for="(d, di) in mod.data || []" :key="di" class="pv-dhlist-item">
                <span v-if="d.showicon === '1'" class="pv-dhlist-icon" :style="{ width: `${d.iconsize || 24}px`, height: `${d.iconsize || 24}px` }"><img v-if="d.imgurl" :src="d.imgurl" /></span>
                <div class="pv-dhlist-txt"><b :style="{ color: mod.params.color1 || '#333333' }">{{ d.title1 || '标题' }}</b><em :style="{ color: mod.params.color2 || '#999999' }">{{ d.title2 || '' }}</em></div>
                <i v-if="mod.params.arrowshow === '1'" class="pv-dhlist-arrow">›</i>
              </div>
            </div>
            <div v-else-if="mod.temp === 'map'" class="pv-map" :style="{ height: `${mod.params.height || 200}px` }">
              <span class="pv-map-pin">📍</span><span class="pv-map-addr">{{ mod.params.address || '地图地址' }}</span>
            </div>
            <div v-else-if="mod.temp === 'hotspot'" class="pv-hotspot">
              <img v-if="mod.params.imgurl" :src="mod.params.imgurl" /><span v-else class="pv-placeholder">热区背景</span>
              <div v-for="(s, si) in mod.data || []" :key="si" class="pv-hotspot-area" :style="{ width: `${s.width || 80}px`, height: `${s.height || 80}px`, left: `${s.left || 0}px`, top: `${s.top || 0}px` }"></div>
            </div>
            <div v-else-if="mod.temp === 'cube'" class="pv-cube-box" :style="{ aspectRatio: `4 / ${cubeMaxH(mod)}` }">
              <template v-if="cubeSlotsOf(mod).length">
                <div v-for="(s, si) in cubeSlotsOf(mod)" :key="si" class="pv-cube-cell" :style="cubeCellStyle(s)">
                  <img v-if="s.imgurl" :src="s.imgurl" />
                </div>
              </template>
              <div v-else class="pv-cube-none">未布置图位</div>
            </div>
            <div v-else-if="mod.temp === 'coupon'">
              <div v-if="(mod.data || []).length > 0" class="pv-coupon-list">
                <div v-for="(coupon, idx) in mod.data" :key="coupon.id || idx" class="pv-coupon" :style="mod.params.bgpic ? { backgroundImage: `url(${mod.params.bgpic})`, backgroundSize: '100% 100%', backgroundRepeat: 'no-repeat' } : {}">
                  <div class="pv-coupon-left" :style="{ color: mod.params.titlecolor || '#FFFFFF' }">
                    <template v-if="String(coupon.type) === '1'">
                      <b>¥{{ coupon.money }}</b>
                      <span style="font-size:10px; margin-top:2px; display:block;">优惠券</span>
                    </template>
                    <template v-else>
                      <b style="font-size:14px;">{{ coupon.name }}</b>
                    </template>
                  </div>
                  <div class="pv-coupon-right">
                    <template v-if="String(coupon.type) === '1'">
                      <span class="pv-coupon-name" :style="{ color: mod.params.remarkcolor || '#E5E5E5' }">{{ coupon.minprice > 0 ? '满' + coupon.minprice + '元可用' : '无门槛' }}</span>
                    </template>
                    <template v-else>
                      <span class="pv-coupon-name" :style="{ color: mod.params.remarkcolor || '#E5E5E5' }">{{ coupon.type_txt || coupon.type_name }}</span>
                    </template>
                  </div>
                </div>
              </div>
              <div v-else-if="mod.params.couponfrom === '1'" class="pv-coupon-list">

                <div v-for="n in Math.min(Number(mod.params.shownum) || 4, 4)" :key="'auto-'+n" class="pv-coupon" :style="mod.params.bgpic ? { backgroundImage: `url(${mod.params.bgpic})`, backgroundSize: '100% 100%', backgroundRepeat: 'no-repeat' } : {}">

                  <div class="pv-coupon-left" :style="{ color: mod.params.titlecolor || '#FFFFFF' }">

                    <b>¥{{ 10 * n }}</b>

                    <span style="font-size:10px; margin-top:2px; display:block;">优惠券</span>

                  </div>

                  <div class="pv-coupon-right">

                    <span class="pv-coupon-name" :style="{ color: mod.params.titlecolor || '#FFFFFF' }">自动获取优惠券{{ n }}</span>

                    <span class="pv-coupon-btn" :style="{ color: mod.params.remarkcolor || '#E5E5E5' }">领取</span>

                  </div>

                </div>

              </div>

              <div v-else class="pv-coupon-list">

                <div class="pv-coupon" :style="mod.params.bgpic ? { backgroundImage: `url(${mod.params.bgpic})`, backgroundSize: '100% 100%', backgroundRepeat: 'no-repeat' } : {}">

                  <div class="pv-coupon-left" :style="{ color: mod.params.titlecolor || '#FFFFFF' }">

                    <b>--</b>

                    <span style="font-size:10px; margin-top:2px; display:block;">优惠券</span>

                  </div>

                  <div class="pv-coupon-right">

                    <span class="pv-coupon-name" :style="{ color: mod.params.titlecolor || '#FFFFFF' }">请添加优惠券</span>

                    <span class="pv-coupon-btn" :style="{ color: mod.params.remarkcolor || '#E5E5E5' }">领取</span>

                  </div>

                </div>

              </div>
            </div>
            <div v-else-if="mod.temp === 'shop'" class="pv-shop" :class="{ s2: mod.params.style === '2' }">
              <img v-if="mod.params.bgimg" class="pv-shop-bg" :src="mod.params.bgimg" />
              <div v-else class="pv-shop-bg-ph">店招背景</div>
              <template v-if="mod.params.style === '2'">
                <div class="pv-shop-logo sq" :style="{ bottom: `${Number(mod.params.navheight || 34) - 31}px` }"><img v-if="mod.params.logo === '1' && mod.params.logourl" :src="mod.params.logourl" /></div>
                <div v-if="mod.params.name === '1'" class="pv-shop-name s2" :style="{ bottom: `${Number(mod.params.navheight || 34) * 0.75 + 31}px`, transform: 'translateY(50%)' }">{{ mod.params.shopname || '店铺名称' }}</div>
                <div v-if="mod.params.menu === '1' && (mod.data || []).length" class="pv-shop-nav row" :style="{ height: `${Number(mod.params.navheight || 34)}px` }">
                  <span v-for="(d, di) in mod.data" :key="di" :style="{ color: mod.params.navcolor || '#FFFFFF', fontSize: `${Number(mod.params.navfontsize || 11)}px` }"><img v-if="d.imgurl" :src="d.imgurl" :style="{ width: `${Number(mod.params.naviconsize || 16)}px`, height: `${Number(mod.params.naviconsize || 16)}px` }" />{{ d.text || '按钮' }}</span>
                </div>
              </template>
              <template v-else>
                <div v-if="mod.params.logo === '1'" class="pv-shop-logo" :style="{ bottom: `${Number(mod.params.navheight || 34) + 26}px` }"><img v-if="mod.params.logourl" :src="mod.params.logourl" /><span v-else>LOGO</span></div>
                <div v-if="mod.params.name === '1'" class="pv-shop-name" :style="{ bottom: `${Number(mod.params.navheight || 34) + 4}px` }">{{ mod.params.shopname || '店铺名称' }}</div>
                <div v-if="mod.params.menu === '1' && (mod.data || []).length" class="pv-shop-nav" :style="{ height: `${Number(mod.params.navheight || 34)}px` }">
                  <span v-for="(d, di) in mod.data" :key="di" :style="{ color: mod.params.navcolor || '#FFFFFF', fontSize: `${Number(mod.params.navfontsize || 11)}px` }"><img v-if="d.imgurl" :src="d.imgurl" :style="{ width: `${Number(mod.params.naviconsize || 16)}px`, height: `${Number(mod.params.naviconsize || 16)}px` }" />{{ d.text || '按钮' }}</span>
                </div>
              </template>
            </div>
            <div v-else-if="mod.temp === 'featured'" class="pv-featured" :style="{ height: `${Number(mod.params.height || 249)}px`, gap: `${Number(mod.params.padding_x || 0)}px` }">
              <div class="pv-featured-left">
                <img v-if="(mod.data || [])[featIdxSafe(mod)]?.imgurl" :src="mod.data?.[featIdxSafe(mod)]?.imgurl" />
                <span v-else class="pv-placeholder">轮播图</span>
                <div
                  v-if="mod.params.indicatordots === '1' && (mod.data || []).length"
                  class="pv-featured-dots"
                  :class="[`pos-${mod.params.dotposition || 'center'}`, `shape-${mod.params.dotshape || '0'}`]"
                  :style="{ bottom: `${Number(mod.params.dotbottom || 8)}px` }"
                >
                  <i v-for="(_d, di) in mod.data" :key="di" :style="{ backgroundColor: di === featIdxSafe(mod) ? (mod.params.dotactivecolor || '#3DB51E') : (mod.params.dotcolor || '#ffffff') }"></i>
                </div>
              </div>
              <div class="pv-featured-right" :style="{ gap: `${Number(mod.params.padding_y || 0)}px` }">
                <div class="pv-featured-rimg"><img v-if="mod.params.imgtop" :src="mod.params.imgtop" /><span v-else class="pv-placeholder">右上方图</span></div>
                <div class="pv-featured-rimg"><img v-if="mod.params.imgbot" :src="mod.params.imgbot" /><span v-else class="pv-placeholder">右下方图</span></div>
              </div>
            </div>
          </div>
        </div>
        <div v-if="items.length === 0" class="de-canvas-empty">页面还没有添加任何组件</div>
        </div>
        <!-- 悬浮按钮覆盖层（预览） -->
        <div v-for="mod in items.filter((m) => m.temp === 'cover')" :key="'cov-' + mod.id" class="pv-cover">
          <div class="pv-cover-btn" :style="covBtnStyle(mod.params)">
            <img v-if="mod.params.style === '2' && mod.params.pic" :src="mod.params.pic" :style="{ width: `${mod.params.picwidth || 48}px`, height: `${mod.params.picheight || 48}px` }" />
            <span v-else :style="{ fontSize: `${mod.params.fontsize || 14}px`, color: mod.params.color || '#FFFFFF' }">{{ mod.params.text || '悬浮' }}</span>
          </div>
        </div>
      </div>
    </el-dialog>

    <!-- 编辑选项内容弹窗（任务50：逐选项标题+子内容，tabGet 回显 / tabSave 保存） -->
    <el-dialog v-model="tabDialogVisible" title="编辑选项内容" width="620px" align-center>
      <div v-loading="tabDialogLoading">
        <el-alert type="info" :closable="false" title="选项标题随页面「保存并发布」生效；选项子内容保存后独立存储，立即生效。" style="margin-bottom: 12px" />
        <div v-for="(item, oi) in tabDialogMod?.data || []" :key="item.id" class="de-tabdlg-section">
          <div class="de-tabdlg-title">
            <span class="de-tabdlg-idx">选项{{ oi + 1 }}</span>
            <el-input v-model="item.name" placeholder="选项标题" style="flex: 1" maxlength="20" />
          </div>
          <SimpleRichEditor v-model="tabHtmlRows[item.id]" />
        </div>
        <el-empty v-if="!(tabDialogMod?.data || []).length" description="请先在属性面板添加选项" />
      </div>
      <template #footer>
        <el-button @click="tabDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="tabDialogSaving" @click="saveTabDialog">保存选项内容</el-button>
      </template>
    </el-dialog>

    <!-- 链接选择弹窗（任务 63:FrontendLinks pick 模式可拖拽大弹窗，选中后回写 hrefurl 字段） -->
    <DesignerChooseUrl
      v-if="chooseUrlVisible"
      picker
      @select="onChooseUrlSelect"
      @close="chooseUrlVisible = false"
    />
    
    <!-- 优惠券选择器 -->
    <CouponPicker
      v-if="couponPickerVisible"
      :visible="couponPickerVisible"
      @update:visible="couponPickerVisible = false"
      @select="onCouponSelected"
    />

    <!-- 优惠券图标选择器（任务#3） -->
    <ImgIconPicker 
      v-model:visible="couponIconPickerVisible" 
      :icon-only="true"
      @pick="(urls: string[]) => onCouponIconPicked(urls)" 
    />

    <!-- 图片/图标统一选择器（任务192） -->
    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="(urls: string[]) => onPick(urls)" />
    <ImgIconPicker v-model:visible="iconPickerVisible" icon-only @pick="(urls: string[]) => onPick(urls)" />
    
        <!-- 商城商品 手动选品弹窗（任务302） -->
        <el-dialog v-model="prodGoodsVisible" title="选择商品" width="720px" align-center>
          <div style="display: flex; gap: 8px; margin-bottom: 10px">
            <el-input v-model="prodGoodsQuery.keyword" placeholder="搜索商品名称" size="small" style="width: 220px" @keyup.enter="prodGoodsQuery.page = 1; fetchProdGoods()" />
            <el-button size="small" type="primary" @click="prodGoodsQuery.page = 1; fetchProdGoods()">搜索</el-button>
          </div>
          <el-table :data="prodGoodsList" v-loading="prodGoodsLoading" row-key="id" size="small" max-height="380" @selection-change="onProdGoodsSelChange">
            <el-table-column type="selection" reserve-selection width="42" />
            <el-table-column label="图片" width="70">
              <template #default="{ row }"><img v-if="row.image" :src="row.image" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px" /></template>
            </el-table-column>
            <el-table-column prop="name" label="商品名称" show-overflow-tooltip />
            <el-table-column prop="price" label="销售价" width="90" />
          </el-table>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px">
            <span style="font-size: 12px; color: #909399">已选 {{ prodGoodsSel.length }} 个</span>
            <el-pagination small background layout="prev, pager, next" :total="prodGoodsTotal" :page-size="8" v-model:current-page="prodGoodsQuery.page" @current-change="fetchProdGoods" />
          </div>
          <template #footer>
            <el-button @click="prodGoodsVisible = false">取消</el-button>
            <el-button type="primary" @click="confirmProdGoods">确定</el-button>
          </template>
        </el-dialog>

    <!-- 视频独立选择弹窗（任务97） -->
    <VideoPicker v-model:visible="videoPickerVisible" @pick="onVideoPick" />

    <!-- 图片魔方·选择布局弹窗（任务100：锚点迷你网格，滑动扩展选区，点击确认） -->
    <el-dialog v-model="cubeLayoutVisible" title="选择布局" width="420px" align-center>
      <div class="cube-layout-grid" :style="{ gridTemplateColumns: `repeat(${cubeLayoutMaxW}, 44px)` }">
        <template v-for="r in cubeLayoutMaxH" :key="`r${r}`">
          <template v-for="c in cubeLayoutMaxW" :key="`r${r}c${c}`">
            <div
              v-if="cubeLayoutHas(c, r)"
              class="cube-layout-cell"
              :class="{ on: cubeLayoutHover && c <= cubeLayoutHover.w && r <= cubeLayoutHover.h }"
              @mouseenter="cubeLayoutHover = { w: c, h: r }"
              @click="confirmCubeLayout(c, r)"
            ></div>
            <div v-else class="cube-layout-cell off"></div>
          </template>
        </template>
      </div>
      <div class="cube-layout-strip">
        <span
          v-for="o in cubeLayoutOpts"
          :key="`${o.w}-${o.h}`"
          class="cube-layout-chip"
          :class="{ on: cubeLayoutHover && cubeLayoutHover.w === o.w && cubeLayoutHover.h === o.h }"
          @click="confirmCubeLayout(o.w, o.h)"
        >{{ o.w }}×{{ o.h }}</span>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.de-page {
  height: 100vh;
  display: flex;
  flex-direction: column;
  background-color: #f5f7fa;
}

/* ===== 顶部工具栏 ===== */
.de-topbar {
  height: 52px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  background-color: #ffffff;
  border-bottom: 1px solid #ececf5;
}

.de-topbar-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.de-logo {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  background: linear-gradient(135deg, #9a9cf0 0%, #7b7de5 100%);
  color: #ffffff;
  font-weight: 700;
  font-size: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.de-title-input {
  width: 160px;
}

.de-top-actions {
  display: flex;
  align-items: center;
  gap: 6px;
}

.de-music-panel {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.de-music-mode .el-radio {
  display: flex;
  height: 28px;
  margin-right: 0;
}

.de-music-list {
  max-height: 260px;
  overflow-y: auto;
  border-top: 1px solid #ebeef5;
  padding-top: 6px;
}

.de-music-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 8px;
  border-radius: 4px;
  font-size: 13px;
  color: #303133;
  cursor: pointer;
}

.de-music-item:hover {
  background: #f5f7fa;
}

.de-music-item.active {
  color: #409eff;
  background: #ecf5ff;
}

.de-music-ico {
  flex-shrink: 0;
}

.de-music-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.de-music-empty {
  padding: 10px 8px;
  font-size: 12px;
  color: #909399;
}

.de-topbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* ===== 涓夋爮甯冨眬 ===== */
.de-body {
  flex: 1;
  display: flex;
  overflow: hidden;
}

.de-left {
  width: 280px;
  flex-shrink: 0;
  background-color: #ffffff;
  border-right: 1px solid #ececf5;
  overflow-y: auto;
}

.de-center {
  flex: 1;
  overflow-y: auto;
  display: flex;
  justify-content: center;
  padding: 24px 16px;
}

.de-right {
  width: 360px;
  flex-shrink: 0;
  background-color: #ffffff;
  border-left: 1px solid #ececf5;
  overflow-y: auto;
  padding-bottom: 24px;
}

/* 三栏滚动条瘦一半，避免拥挤错位 */
.de-left::-webkit-scrollbar,
.de-center::-webkit-scrollbar,
.de-right::-webkit-scrollbar {
  width: 5px;
  height: 5px;
}
.de-left::-webkit-scrollbar-thumb,
.de-center::-webkit-scrollbar-thumb,
.de-right::-webkit-scrollbar-thumb {
  background-color: #d4d7de;
  border-radius: 4px;
}
.de-left::-webkit-scrollbar-track,
.de-center::-webkit-scrollbar-track,
.de-right::-webkit-scrollbar-track {
  background: transparent;
}

.de-panel-title {
  padding: 12px 14px;
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  border-bottom: 1px solid #f0f1f5;
  position: sticky;
  top: 0;
  background-color: #ffffff;
  z-index: 2;
}

.de-right-empty {
  padding: 40px 16px;
  text-align: center;
  font-size: 13px;
  color: #909399;
}

/* ===== 页面信息设置提示/颜色行（任务205） ===== */
.de-form-tip {
  font-size: 12px;
  line-height: 1.4;
  color: #909399;
  margin-top: 2px;
}

.de-form-tip--warn {
  color: #e6a23c;
}

.de-color-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
}

/* ===== 左：组件面板（任务299 三分类） ===== */
.de-left-search {
  padding: 10px 12px 0;
}

.de-left-tip {
  padding: 8px 14px 2px;
  font-size: 12px;
  color: #a8abb2;
}

.de-left-empty {
  padding: 24px 12px;
  text-align: center;
  font-size: 12px;
  color: #a8abb2;
}

.de-cat-head {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 14px 6px;
  font-size: 13px;
  font-weight: 600;
  color: #303133;
  cursor: pointer;
  user-select: none;
}

.de-cat-head--static {
  cursor: default;
}

.de-cat-arrow {
  display: inline-block;
  font-size: 12px;
  color: #909399;
  transition: transform 0.15s;
  transform: rotate(-90deg);
}

.de-cat-arrow.open {
  transform: rotate(0deg);
}

.de-menu-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  padding: 4px 12px 10px;
}

.de-menu-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 10px 2px;
  background-color: #f7f8fa;
  border-radius: 8px;
  cursor: pointer;
  transition: background-color 0.15s;
  min-width: 0;
}

.de-menu-card:hover {
  background-color: #e8eaf6;
}

.de-menu-card-icon {
  font-size: 20px;
  line-height: 1;
}

.de-menu-card-name {
  font-size: 12px;
  color: #303133;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ===== 中：手机画布 ===== */
.de-phone {
  width: 375px;
  position: relative;
  flex-shrink: 0;
  min-height: 640px;
  background-color: #ffffff;
  border: 6px solid #303133;
  border-radius: 28px;
  overflow: hidden;
  align-self: flex-start;
  box-shadow: 0 10px 28px rgba(48, 49, 51, 0.16);
}

.de-canvas-topbar {
  height: 44px;
  z-index: 30;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
  position: relative;
}

/* 主区域背景容器（任务07：页面背景色作用于内容区，非导航栏） */
.de-phone-body {
  min-height: 596px;
}

.de-canvas-topbar.active {
  outline: 2px solid #7b7de5;
  outline-offset: -2px;
}

.de-canvas-topbar-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.de-canvas-topbar-lock {
  font-size: 11px;
  color: #909399;
}

.de-canvas-empty {
  padding: 60px 20px;
  text-align: center;
  font-size: 13px;
  color: #a8abb2;
}

.de-canvas-item {
  position: relative;
  cursor: pointer;
}

.de-canvas-item:hover {
  outline: 1px dashed #a9abec;
  outline-offset: -1px;
}

.de-canvas-item.active {
  outline: 2px solid #7b7de5;
  outline-offset: -2px;
}

.de-canvas-tools {
  display: none;
  position: absolute;
  top: 0;
  right: 0;
  z-index: 3;
  align-items: center;
  gap: 2px;
  background-color: #7b7de5;
  color: #ffffff;
  border-radius: 0 0 0 8px;
  padding: 2px 6px;
  font-size: 12px;
}

.de-canvas-item.active .de-canvas-tools,
.de-canvas-item:hover .de-canvas-tools {
  display: flex;
}

.de-canvas-tools-name {
  margin-right: 4px;
  opacity: 0.9;
}

.de-tool {
  cursor: pointer;
  padding: 0 4px;
  user-select: none;
}

.de-tool:hover {
  opacity: 0.8;
}

.de-tool.disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.de-tool.danger:hover {
  color: #ffd6d6;
}

/* ===== 鍙筹細灞炴€ч潰鏉?===== */
.de-form {
  padding: 12px 14px;
}

/* 任务04：属性面板全局紧凑化（所有组件属性统一收紧） */
.de-form :deep(.el-form-item) {
  margin-bottom: 10px;
}

/* 任务29：左标签单行不换行 */
.de-form :deep(.el-form-item__label) {
  white-space: nowrap;
}

/* 任务30：显示设置区恢复标签在上+复选全横流 */
.de-top-item {
  flex-direction: column;
  align-items: flex-start;
}

.de-top-item :deep(.el-form-item__label) {
  width: auto;
  flex: none;
  padding: 0 0 4px;
  line-height: 22px;
}

.de-top-item :deep(.el-form-item__content) {
  width: 100%;
}

/* 任务30：选择图标行按钮右对齐 */
.tt-icon-row :deep(.el-form-item__content) {
  justify-content: flex-end;
}

.de-form :deep(.el-form-item__label) {
  line-height: 32px;
  font-size: 13px;
}

.de-form :deep(.el-divider) {
  margin: 10px 0 8px;
}

.de-form :deep(.el-divider__text) {
  padding: 0 8px;
  font-size: 12px;
}

.de-grid2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 10px;
}

.de-data-card {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px;
  border: 1px solid #f0f1f5;
  border-radius: 8px;
  margin-bottom: 8px;
}

.de-data-card.col {
  flex-direction: column;
  align-items: stretch;
}

.de-data-card-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.de-data-input {
  flex: 1;
}

.de-tab-label {
  flex: none;
  width: 34px;
  font-size: 12px;
  color: #606266;
}

.de-thumb {
  width: 60px;
  height: 36px;
  object-fit: cover;
  border-radius: 4px;
  border: 1px solid #ececf5;
  cursor: pointer;
}

.de-thumb.sm {
  width: 36px;
  height: 36px;
}

.de-thumb.lg {
  width: 120px;
  height: 68px;
}

.de-thumb.xl {
  width: 100px;
  height: 80px;
  object-fit: contain;
  background: #f5f5f7;
}

.de-pick {
  position: relative;
  display: inline-flex;
  align-items: center;
  cursor: pointer;
}

.de-pick-plus {
  width: 80px;
  height: 80px;
  border: 1px dashed #dcdfe6;
  border-radius: 6px;
  background-color: #fbfbfd;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 22px;
}

/* ===== 简版预览组件 ===== */
.pv-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  color: #b1b3b8;
  font-size: 12px;
  background: repeating-linear-gradient(45deg, #f4f5f7, #f4f5f7 8px, #eceef1 8px, #eceef1 16px);
}

.pv-placeholder.sm {
  font-size: 11px;
}

/* 优惠券无数据占位提示（窄小版本） */
.pv-placeholder-sm {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 60px;
  color: #b1b3b8;
  font-size: 12px;
  background: repeating-linear-gradient(45deg, #f4f5f7, #f4f5f7 8px, #eceef1 8px, #eceef1 16px);
  border-radius: 4px;
  margin: 10px 0;
}

.pv-placeholder-box {
  padding: 20px;
  text-align: center;
  color: #b1b3b8;
  font-size: 12px;
  background-color: #f7f8fa;
}

.pv-banner {
  position: relative;
  overflow: hidden;
  background-color: #f2f3f5;
}

.pv-banner img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-banner-dots {
  position: absolute;
  left: 0;
  right: 0;
  display: flex;
  gap: 4px;
}

.pv-banner-dots.pos-center {
  justify-content: center;
}

.pv-banner-dots.pos-left {
  justify-content: flex-start;
  padding-left: 8px;
}

.pv-banner-dots.pos-right {
  justify-content: flex-end;
  padding-right: 8px;
}

.pv-banner-dots i {
  width: 6px;
  height: 6px;
  border-radius: 50%;
}

.pv-banner-dots.shape-1 i {
  width: 12px;
  height: 4px;
  border-radius: 1px;
}

.pv-banner-dots.shape-2 i {
  width: 7px;
  height: 7px;
  border-radius: 50%;
}

.pv-banner-dots.shape-3 i {
  width: 6px;
  height: 6px;
  border-radius: 0;
}

.pv-search {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  font-size: 12px;
  border: 1px solid #e6e7eb;
}

.pv-notice {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 4px;
  overflow: hidden;
  white-space: nowrap;
}

.pv-notice-img {
  height: 18px;
  width: auto;
  object-fit: contain;
  padding-right: 6px;
  margin-right: 2px;
  border-right: 1px solid #e2e2e2;
  flex-shrink: 0;
}

.pv-notice-icon-img {
  width: 16px;
  height: 16px;
  object-fit: contain;
  flex-shrink: 0;
}

.pv-menu {
  display: grid;
  gap: 6px 0;
  padding: 6px 0;
}

.pv-menu-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  min-width: 0;
}

.pv-menu-item img {
  object-fit: cover;
  border-radius: 8px;
}

.pv-menu-icon-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #eef0f4;
  border-radius: 8px;
  color: #b1b3b8;
  font-size: 12px;
}

.pv-menu-item em {
  font-style: normal;
  font-size: 12px;
  color: #606266;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pv-picture {
  overflow: hidden;
  background-color: #f2f3f5;
  min-height: 80px;
}

.pv-picture img {
  width: 100%;
  display: block;
}

.pv-pictures {
  display: grid;
  gap: 2px;
  grid-auto-rows: 70px;
}

.pv-pictures.lay-2 {
  grid-template-columns: repeat(2, 1fr);
}

.pv-pictures.lay-3 {
  grid-template-columns: repeat(3, 1fr);
}

.pv-pictures.lay-4 {
  grid-template-columns: repeat(4, 1fr);
}

.pv-pictures.lay-showcase {
  grid-template-columns: repeat(2, 1fr);
}

.pv-pictures.lay-showcase .pv-pictures-item:first-child {
  grid-row: span 2;
}

/* 多图卡片行内紧凑（任务27） */
.pg-card-body {
  align-items: flex-start;
}

.pg-card-right {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.pg-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: nowrap;
  white-space: nowrap;
}

.pg-row .el-input {
  flex: 1;
}

.pg-label {
  flex: none;
  font-size: 12px;
  color: #606266;
}

.pv-pictures-item {
  height: 70px;
  overflow: hidden;
  background-color: #f2f3f5;
}

.pv-pictures-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-title {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 8px;
  font-weight: 600;
}

.pv-title-line {
  flex: 1;
  height: 0;
  border-top: 1px solid #dddddd;
}

.pv-title-icon {
  flex: none;
  object-fit: contain;
}

.tt-color-val {
  flex: 1;
  min-width: 0;
  background: #f5f7fa;
  border: 1px solid #e4e7ed;
  border-radius: 4px;
  padding: 0 8px;
  height: 24px;
  line-height: 22px;
  font-size: 12px;
  color: #606266;
  word-break: normal;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pv-text {
  word-break: break-all;
}

.pv-richtext {
  font-size: 13px;
  color: #606266;
  word-break: break-all;
}

/* 富文本编辑器（属性面板内嵌） */
.rt-editor {
  width: 100%;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  overflow: hidden;
  background-color: #ffffff;
  z-index: 30;
}

.rt-toolbar {
  border-bottom: 1px solid #e4e7ed;
}

.rt-area {
  min-height: 220px;
  max-height: 320px;
  overflow-y: auto;
  font-size: 13px;
}

.pv-blank {
  background: repeating-linear-gradient(45deg, #fafafa, #fafafa 6px, #f2f3f5 6px, #f2f3f5 12px);
}

.pv-line {
  width: 100%;
}

.pv-product {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  padding: 8px;
}

.pv-product-box {
  overflow: hidden;
}

.pv-product-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.pv-product-head b {
  font-weight: 600;
}

.pv-product-arrow {
  font-style: normal;
  margin-left: 2px;
}

.pv-product.lay-1 {
  grid-template-columns: 1fr;
}

.pv-product.lay-3 {
  grid-template-columns: 1fr 1fr 1fr;
}

.pv-product.lay-4,
.pv-product.lay-5 {
  grid-template-columns: repeat(3, 1fr);
}

.pv-product-item {
  border: 1px solid #f0f1f5;
  border-radius: 8px;
  overflow: hidden;
}

.pv-product-img {
  position: relative;
  height: 80px;
  background: repeating-linear-gradient(45deg, #f4f5f7, #f4f5f7 8px, #eceef1 8px, #eceef1 16px);
}

.pv-product-badge {
  position: absolute;
  left: 0;
  top: 0;
  background: rgba(255, 68, 0, 0.85);
  color: #fff;
  font-size: 10px;
  padding: 1px 5px;
  border-radius: 0 0 6px 0;
}

.pv-product-badgeimg {
  position: absolute;
  left: 0;
  top: 0;
  width: 24px;
  height: 24px;
  object-fit: contain;
}

.pv-product.lay-1 .pv-product-img {
  height: 60px;
}

.pv-product-name {
  padding: 4px 6px 0;
  font-size: 12px;
  color: #303133;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.pv-product-foot {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 2px 6px 6px;
}

.pv-product-price {
  font-size: 12px;
  font-weight: 600;
}

.pv-product-price s {
  margin-left: 3px;
  font-size: 10px;
  color: #c0c4cc;
  font-weight: normal;
}

.pv-product-sales {
  font-size: 10px;
}

.pv-product-market {
  margin-left: 3px;
  font-size: 10px;
  color: #c0c4cc;
}

.pv-product-example {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.pv-product-cart {
  margin-left: auto;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  flex: none;
}

.pv-product-buyimg {
  margin-left: auto;
  width: 20px;
  height: 20px;
  object-fit: contain;
}

.de-badge-upload {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
}

.de-badge-box {
  width: 56px;
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px dashed #dcdfe6;
  border-radius: 6px;
  color: #909399;
  font-size: 18px;
  cursor: pointer;
  background: #f7f8fa;
}

.de-badge-thumb {
  width: 40px;
  height: 40px;
  object-fit: cover;
  border-radius: 4px;
  border: 1px solid #ebeef5;
}

.de-badge-label {
  font-size: 12px;
  color: #909399;
}

/* ===== 优惠券预览 ===== */
.pv-coupon-list {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  padding: 4px;
}

/* 网格布局：每行最多 4 张，自动均分宽度 */
.pv-coupon-grid .pv-coupon {
  display: flex;
  align-items: stretch;
  border-radius: 8px;
  overflow: hidden;
  background: linear-gradient(90deg, #ff9a57, #f56c6c);
  color: #ffffff;
  height: 64px;
  position: relative;
}

/* 5 张以上时，增加一行 */
.pv-coupon-grid .pv-coupon:nth-last-child(n+6):nth-child(-n+5),
.pv-coupon-grid .pv-coupon:nth-last-child(n+11):nth-child(-n+10) {
  flex: 0 0 calc(25% - 6px);
}

/* 移动端自适应 */
@media (max-width: 768px) {
  .pv-coupon-grid .pv-coupon {
  display: flex;
  align-items: stretch;
  border-radius: 8px;
  overflow: hidden;
  background: linear-gradient(90deg, #ff9a57, #f56c6c);
  color: #ffffff;
  height: 64px;
  position: relative;
}
}

@media (max-width: 480px) {
  .pv-coupon-grid .pv-coupon {
  display: flex;
  align-items: stretch;
  border-radius: 8px;
  overflow: hidden;
  background: linear-gradient(90deg, #ff9a57, #f56c6c);
  color: #ffffff;
  height: 64px;
  position: relative;
}
}

.pv-coupon {
  display: flex;
  align-items: stretch;
  border-radius: 8px;
  overflow: hidden;
  background: linear-gradient(90deg, #ff9a57, #f56c6c);
  color: #ffffff;
  height: 64px;
  position: relative;
}

.pv-coupon-left {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 64px;
  font-size: 12px;
  border-right: 1px dashed rgba(255, 255, 255, 0.5);
  flex-shrink: 0;
}

.pv-coupon-left b {
  font-size: 20px;
}

.pv-coupon-right {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
}

.pv-coupon-name {
  font-size: 12px;
}

.pv-coupon-btn {
  font-size: 11px;
  background-color: #ffffff;
  color: #f56c6c;
  border-radius: 10px;
  padding: 2px 10px;
}

/* 优惠券属性面板：背景上传预览框 */
.coupon-bg-upload {
  width: 88px;
  height: 88px;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: #fafafa;
  flex-shrink: 0;
}
.coupon-bg-upload img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.coupon-bg-upload span {
  font-size: 12px;
  color: #909399;
}

/* ===== 选项卡预览（任务332 双样式） ===== */
.pv-tab {
  overflow: hidden;
}

.pv-tab-head {
  display: flex;
}

.pv-tab-head.s2 {
  justify-content: flex-start;
  gap: 10px;
  padding: 10px;
}

.pv-tab-item {
  flex: 1;
  text-align: center;
  padding: 10px 0;
  position: relative;
  overflow: hidden;
  white-space: nowrap;
}

.pv-tab-item.s2 {
  flex: 1;
  text-align: center;
  padding: 4px 0;
  border-radius: 4px;
}

.pv-tab-item i {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 28px;
  height: 3px;
  border-radius: 2px;
}

.pv-tab-body {
  height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 13px;
}

/* ===== 编辑选项内容弹窗 ===== */
.de-tabdlg-section {
  border: 1px solid #f0f1f5;
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 10px;
}

.de-tabdlg-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.de-tabdlg-idx {
  font-size: 12px;
  font-weight: 600;
  color: #7b7de5;
  white-space: nowrap;
}

.de-tabdlg-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 6px;
}

.de-tabdlg-raw {
  flex: 1;
  font-size: 12px;
  color: #909399;
  padding: 6px 8px;
  background-color: #f7f8fa;
  border-radius: 4px;
}

/* ===== 预览弹窗 ===== */
.de-preview-phone {
  width: 375px;
  position: relative;
  margin: 0 auto;
  min-height: 500px;
  max-height: 70vh;
  overflow-y: auto;
  background-color: #ffffff;
  border: 4px solid #303133;
  border-radius: 20px;
}

.de-preview-phone .de-canvas-topbar {
  cursor: default;
}

/* ===== 任务45 扩展组件预览 ===== */
.pv-video {
  position: relative;
  overflow: hidden;
  background-color: #1f2024;
}

.pv-video img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-video-play {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background-color: rgba(0, 0, 0, 0.45);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}

.pv-button {
  display: flex;
  justify-content: center;
  padding: 6px 0;
}

.pv-button span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.pv-dhlist-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 8px;
  border-bottom: 1px solid #f2f3f5;
}

.pv-dhlist-item:last-child {
  border-bottom: none;
}

.pv-dhlist-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #eef0f4;
  border-radius: 6px;
  flex-shrink: 0;
}

.pv-dhlist-icon img {
  width: 70%;
  height: 70%;
  object-fit: contain;
}

.pv-dhlist-txt {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-width: 0;
}

.pv-dhlist-txt b {
  font-weight: 600;
}

.pv-dhlist-txt em {
  font-style: normal;
}

.pv-dhlist-arrow {
  font-style: normal;
  color: #c0c4cc;
  font-size: 16px;
}

.pv-cover {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 20;
}

.pv-cover-btn {
  position: absolute;
  pointer-events: auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18);
}

.pv-cover-btn img {
  display: block;
}

.pv-cover-btn.active {
  outline: 2px dashed #7b7de5;
  outline-offset: 2px;
}

.pv-cover-btn .de-canvas-tools {
  top: -26px;
  right: 0;
  border-radius: 4px;
}

.pv-cover-btn:hover .de-canvas-tools {
  display: flex;
}

.pv-map {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  background: linear-gradient(135deg, #e8f0e8 0%, #dce8f0 100%);
}

.pv-map-pin {
  font-size: 22px;
}

.pv-map-addr {
  font-size: 12px;
  color: #606266;
  padding: 0 10px;
  text-align: center;
}

.pv-hotspot {
  position: relative;
  min-height: 120px;
  overflow: hidden;
  background-color: #f2f3f5;
}

.pv-hotspot img {
  width: 100%;
  display: block;
}

.pv-hotspot-area {
  position: absolute;
  border: 1px dashed #f56c6c;
  background-color: rgba(245, 108, 108, 0.12);
  box-sizing: border-box;
}

.pv-cube-box {
  position: relative;
  width: 100%;
  overflow: hidden;
}

.pv-cube-cell {
  position: absolute;
  overflow: hidden;
}

.pv-cube-cell img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-cube-none {
  border: 1px dashed #409eff;
  color: #909399;
  text-align: center;
  line-height: 170px;
  font-size: 14px;
  background: #f7f9fc;
}

/* ===== 店招/精选（任务302） ===== */
.pv-shop {
  position: relative;
  height: 170px;
  overflow: hidden;
  background: #e8e9ee;
}

.pv-shop-bg {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-shop-bg-ph {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #909399;
  font-size: 13px;
}

.pv-shop-logo {
  position: absolute;
  left: 50%;
  bottom: 60px;
  transform: translateX(-50%);
  width: 62px;
  height: 62px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.9);
  border: 2px solid #ffffff;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  color: #909399;
  box-sizing: border-box;
}

.pv-shop-logo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-shop-logo.sq {
  left: 8px;
  bottom: 0;
  transform: none;
  border-radius: 4px;
  width: 62px;
  height: 62px;
  z-index: 5;
}

.pv-shop-name {
  position: absolute;
  left: 50%;
  bottom: 38px;
  transform: translateX(-50%);
  padding: 2px 12px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.25);
  color: #ffffff;
  font-size: 12px;
  white-space: nowrap;
  max-width: 70%;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pv-shop-name.s2 {
  left: 78px;
  bottom: 56px;
  transform: none;
  background: transparent;
  padding: 0;
  border-radius: 0;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
  font-size: 13px;
  font-weight: 600;
  width: 64px;
  height: 39px;
  display: flex;
  align-items: center;
  justify-content: flex-start;
}

.pv-shop-nav {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 34px;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: space-around;
}

/* ===== 优惠券组件属性面板（任务：严格参照 yhq.png） ===== */
.de-coupon-style-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  padding: 8px 0;
}

.de-coupon-style-card {
  border: 2px solid #e4e7ed;
  border-radius: 8px;
  padding: 8px;
  cursor: pointer;
  transition: all 0.2s;
  position: relative;
  background: #fff;
  text-align: center;
}

.de-coupon-style-card:hover {
  border-color: #409eff;
  box-shadow: 0 2px 8px rgba(64, 158, 255, 0.2);
}

.de-coupon-style-card.active {
  border-color: #409eff;
  background: #ecf5ff;
}

.de-coupon-thumb {
  width: 100%;
  max-width: 120px;
  height: auto;
  border-radius: 4px;
  margin-bottom: 6px;
}

.de-coupon-style-label {
  font-size: 12px;
  color: #606266;
}

/* 优惠券列表样式 */
.de-coupon-list {
  margin-top: 10px;
  max-height: 300px;
  overflow-y: auto;
}

.de-coupon-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  margin-bottom: 8px;
  background: #fff;
}

.de-coupon-info {
  flex: 1;
  min-width: 0;
}

.de-coupon-name {
  font-size: 14px;
  font-weight: 500;
  color: #303133;
  margin-bottom: 4px;
}

.de-coupon-detail {
  font-size: 12px;
  color: #909399;
}

.de-coupon-actions {
  display: flex;
  gap: 8px;
  margin-left: 15px;
}

.de-icon-upload {
  position: absolute;
  top: 8px;
  right: 8px;
  font-size: 16px;
  color: #909399;
  cursor: pointer;
  padding: 4px;
  line-height: 1;
  z-index: 2;
}

.de-icon-upload:hover {
  color: #409eff;
}

.pv-shop-nav span {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  font-size: 11px;
  line-height: 1.1;
}

.pv-shop-nav span img {
  width: 16px;
  height: 16px;
  border-radius: 2px;
}

.pv-shop-nav.row {
  padding-left: 74px;
}

.pv-featured {
  display: flex;
  width: 100%;
  overflow: hidden;
}

.pv-featured-left {
  position: relative;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  background: #f2f3f5;
}

.pv-featured-left img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-featured-left .pv-placeholder {
  height: 100%;
}

.pv-featured-right {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.pv-featured-rimg {
  position: relative;
  flex: 1;
  min-height: 0;
  overflow: hidden;
  background: #f2f3f5;
}

.pv-featured-rimg img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-featured-dots {
  position: absolute;
  left: 0;
  right: 0;
  display: flex;
  gap: 4px;
}

.pv-featured-dots.pos-center {
  justify-content: center;
}

.pv-featured-dots.pos-left {
  justify-content: flex-start;
  padding-left: 8px;
}

.pv-featured-dots.pos-right {
  justify-content: flex-end;
  padding-right: 8px;
}

.pv-featured-dots i {
  width: 14px;
  height: 3px;
  border-radius: 2px;
}

.pv-featured-dots.shape-1 i {
  width: 12px;
  height: 4px;
  border-radius: 1px;
}

.pv-featured-dots.shape-2 i {
  width: 6px;
  height: 6px;
  border-radius: 50%;
}

.pv-featured-dots.shape-3 i {
  width: 6px;
  height: 6px;
  border-radius: 0;
}

.feat-card-top {
  justify-content: flex-end;
  gap: 8px;
}

.de-card-title {
  margin-right: auto;
  font-size: 12px;
  color: #606266;
}

.de-color-row {
  display: flex;
  align-items: center;
  gap: 6px;
  width: 100%;
}

.de-color-row .el-input {
  flex: 1;
}

.de-plat-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  row-gap: 4px;
  width: 100%;
}

.de-plat-grid .el-checkbox-group {
  display: contents;
}

.de-plat-grid .el-checkbox {
  margin-right: 0;
  height: auto;
}

.feat-pick-mask {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 2px 0;
  background: rgba(0, 0, 0, 0.45);
  color: #ffffff;
  font-size: 11px;
  font-style: normal;
  text-align: center;
}

/* ===== 订单播报简版预览（任务20） ===== */
.pv-orderbroadcast {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 10px;
  overflow: hidden;
  box-sizing: border-box;
}

.pv-orderbroadcast-avatar {
  flex: none;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #d4d6da;
}

.pv-orderbroadcast-text {
  flex: 1;
  min-width: 0;
  font-size: 11px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ===== 图片魔方编辑（任务100） ===== */
.de-slider-row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
}

.de-slider-row .el-slider {
  flex: 1;
}

.de-slider-num {
  width: 46px;
}

.de-slider-num :deep(.el-input__wrapper) {
  height: 32px;
  padding: 0 4px;
}

.de-slider-num :deep(.el-input__inner) {
  padding: 0;
  text-align: center;
}

.de-slider-row :deep(.el-slider__button) {
  width: 16px;
  height: 16px;
}

.de-slider-unit {
  font-size: 12px;
  color: #909399;
}

.cube-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  grid-auto-rows: 64px;
  width: 100%;
}

.cube-cell.empty {
  border: 1px dashed #dcdfe6;
  background: #fafafa;
  color: #c0c4cc;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  cursor: pointer;
  box-sizing: border-box;
}

.cube-cell.empty:hover {
  border-color: #409eff;
  color: #409eff;
}

.cube-cell.slot {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  cursor: pointer;
  box-sizing: border-box;
  border: 1px solid #ffffff;
}

.cube-cell.slot.active {
  outline: 2px solid #f56c6c;
}

.cube-cell.slot img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.cube-cell-size {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.9);
}

.cube-edit-card {
  position: relative;
  border: 1px solid #ebeef5;
  border-radius: 6px;
  background: #fbfcfd;
  padding: 10px;
  margin: 0 0 14px;
}

.cube-edit-del {
  position: absolute;
  top: 4px;
  right: 8px;
  font-size: 16px;
  color: #909399;
  cursor: pointer;
}

.cube-edit-del:hover {
  color: #f56c6c;
}

.cube-edit-body {
  display: flex;
  gap: 10px;
}

.cube-edit-thumb {
  position: relative;
  flex: none;
  width: 88px;
  height: 88px;
  border: 1px solid #e4e7ed;
  border-radius: 4px;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  cursor: pointer;
}

.cube-edit-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cube-edit-plus {
  font-size: 22px;
  color: #c0c4cc;
}

.cube-edit-mask {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 22px;
  line-height: 22px;
  text-align: center;
  font-size: 12px;
  font-style: normal;
  color: #ffffff;
  background: rgba(0, 0, 0, 0.5);
}

.cube-edit-right {
  flex: 1;
  min-width: 0;
}

.cube-edit-size {
  font-size: 12px;
  color: #606266;
  margin-bottom: 8px;
}

.cube-edit-link {
  display: flex;
  gap: 6px;
}

.cube-layout-grid {
  display: grid;
  gap: 2px;
  justify-content: center;
  padding: 8px 0 12px;
}

.cube-layout-cell {
  width: 44px;
  height: 44px;
  border: 1px solid #e4e7ed;
  background: #f7f8fa;
  box-sizing: border-box;
}

.cube-layout-cell.on {
  background: #d9ecff;
  border-color: #409eff;
  cursor: pointer;
}

.cube-layout-cell.off {
  border: none;
  background: transparent;
}

.cube-layout-strip {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  border-top: 1px solid #ebeef5;
  padding: 8px 2px 4px;
}

.cube-layout-chip {
  flex: none;
  padding: 3px 10px;
  border: 1px solid #dcdfe6;
  border-radius: 12px;
  background: #ffffff;
  font-size: 12px;
  color: #606266;
  cursor: pointer;
}

.cube-layout-chip.on {
  border-color: #409eff;
  color: #409eff;
  background: #ecf5ff;
}

/* ===== 优惠券组件属性面板（任务：严格参照 yhq.png） ===== */
.de-coupon-card {
  position: relative;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  padding: 12px;
  margin-bottom: 10px;
  background: #f5f7fa;
}

.de-coupon-up {
  position: absolute;
  top: 6px;
  right: 32px;
  width: 20px;
  height: 20px;
  border: none;
  background: transparent;
  color: #909399;
  font-size: 14px;
  cursor: pointer;
  line-height: 1;
  padding: 0;
}
.de-coupon-up:hover:not(:disabled) {
  color: #409eff;
}

.de-coupon-close {
  position: absolute;
  top: 6px;
  right: 8px;
  width: 20px;
  height: 20px;
  border: none;
  background: transparent;
  color: #909399;
  font-size: 16px;
  cursor: pointer;
  line-height: 1;
  padding: 0;
}
.de-coupon-close:hover {
  color: #f56c6c;
}

.de-coupon-add-btn {
  width: 100%;
  background: #f5f7fa;
  border: 1px solid #dcdfe6;
  color: #606266;
  border-radius: 4px;
  padding: 8px 0;
  margin-bottom: 12px;
  cursor: pointer;
}
.de-coupon-add-btn:hover {
  background: #ecf5ff;
  border-color: #409eff;
  color: #409eff;
}

.de-coupon-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}

.de-coupon-row:last-child {
  margin-bottom: 0;
}

.de-coupon-label {
  font-size: 12px;
  color: #606266;
  min-width: 80px;
  white-space: nowrap;
}

.de-coupon-value {
  flex: 1;
  font-size: 13px;
  color: #303133;
}

.de-coupon-actions {
  display: flex;
  gap: 6px;
  flex-shrink: 0;
}

.btn-move-up {
  width: 24px;
  height: 24px;
  border: none;
  border-radius: 3px;
  background: #409eff;
  color: white;
  font-size: 14px;
  cursor: pointer;
}

.btn-move-up:hover:not(:disabled) {
  opacity: 0.9;
}

/* 下移按钮 */
.btn-move-down {
  width: 24px;
  height: 24px;
  border: none;
  border-radius: 3px;
  background: #67c23a;
  color: white;
  font-size: 14px;
  cursor: pointer;
}

.btn-move-down:hover:not(:disabled) {
  opacity: 0.9;
}

.btn-delete {
  width: 24px;
  height: 24px;
  border: none;
  border-radius: 3px;
  background: #f56c6c;
  color: white;
  font-size: 18px;
  line-height: 22px;
  cursor: pointer;
}

.btn-delete:hover {
  opacity: 0.9;
}
.de-link-val {
  flex: 1;
  font-size: 12px;
  color: #7b7de5;
  word-break: break-all;
}

/* ===== 视频面板提示（任务 97） ===== */
.vp-tip {
  width: 100%;
  color: #909399;
  font-size: 12px;
  line-height: 1.5;
}

/* ===== 积分兑换组件 ===== */
.pv-scoreshop-box {
  background: #fff;
}

.pv-scoreshop-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.pv-scoreshop-arrow {
  margin-left: 4px;
  font-size: 20px;
  line-height: 1;
}

.pv-scoreshop-layout {
  display: grid;
  gap: 10px;
  overflow-x: auto;
}

/* 单排 - 横向排列 */
.lay-1 .pv-scoreshop-item {
  min-width: 100%;
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 16px;
  padding: 8px 0;
}

.lay-1 .pv-scoreshop-img {
  width: 120px;
  height: 120px;
  flex-shrink: 0;
}

.lay-1 .pv-scoreshop-name {
  max-width: 200px;
}

/* 双排 - 2 列网格 */
.lay-2 .pv-scoreshop-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lay-2 .pv-scoreshop-img {
  width: 100%;
  aspect-ratio: 1 / 1;
}

/* 三排 - 3 列网格 */
.lay-3 .pv-scoreshop-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lay-3 .pv-scoreshop-img {
  width: 100%;
  aspect-ratio: 1 / 1;
}

/* 横排 - 水平滚动 */
.lay-4 .pv-scoreshop-item {
  min-width: 140px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex-shrink: 0;
}

.lay-4 .pv-scoreshop-img {
  width: 100%;
  aspect-ratio: 1 / 1;
}

/* 左右滑动 - 双列滚动 */
.lay-5 .pv-scoreshop-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lay-5 .pv-scoreshop-img {
  width: 100%;
  aspect-ratio: 1 / 1;
}

.lay-5 {
  scroll-behavior: smooth;
  -webkit-overflow-scrolling: touch;
}

/* 瀑布流 - 自动填充 */
.lay-6 .pv-scoreshop-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lay-6 .pv-scoreshop-img {
  width: 100%;
  aspect-ratio: auto;
}

/* ===== 积分兑换组件 - 属性面板商品卡片 ===== */
.de-scoreshop-card {
  display: flex;
  align-items: stretch;
  gap: 12px;
  margin-bottom: 8px;
  background: #fff;
}

.de-scoreshop-card-left {
  flex: 1;
  display: flex;
  gap: 12px;
  padding: 8px;
  border: 1px solid #f0f1f5;
  border-radius: 6px;
  background: #fafafa;
}

.de-scoreshop-thumb {
  width: 60px;
  height: 60px;
  flex-shrink: 0;
  border: 1px solid #e4e7ed;
  border-radius: 4px;
  overflow: hidden;
  position: relative;
}

.de-scoreshop-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.de-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: #999;
  background: #f5f5f5;
}

.de-scoreshop-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 4px;
}

.de-scoreshop-name {
  font-size: 13px;
  color: #333;
  line-height: 1.4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.de-scoreshop-price {
  font-size: 12px;
  color: #666;
  display: flex;
  gap: 8px;
}

.de-scoreshop-price span:first-child {
  color: #999;
}

.de-scoreshop-price span:last-child {
  color: #ff4400;
  font-weight: bold;
}

.de-scoreshop-card-right {
  display: flex;
  flex-direction: column;
  gap: 4px;
  justify-content: center;
}

.de-scoreshop-btn-row {
  display: flex;
  justify-content: center;
}

.de-scoreshop-btn-row i {
  font-size: 16px;
}
</style>
