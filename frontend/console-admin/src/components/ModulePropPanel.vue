<script setup lang="ts">
/**
 * 组件属性面板（页面设计器 / 商品详情装修器 共享）
 * 逐组件、逐字段与「设计-页面设计」DesignerEditor 右侧属性保持一致；
 * 商品详情装修器 GoodsDetailEditor 复用同一组件，杜绝两套属性不一致。
 * 通过 props.module 传入当前选中组件，就地修改其 params/data/content，
 * 父组件持有同一响应式对象即自动同步（无需 v-model）。
 */
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { getDesignerTabContent, saveDesignerTabContent, type DesignerModule } from '../api/designer'
import { PLATFORM_OPTIONS, MEMBER_LEVEL_OPTIONS, genMid, type CubeSlot } from '../views/admin/designerModules'
import DesignerChooseUrl from '../views/admin/DesignerChooseUrl.vue'
import ImgIconPicker from './ImgIconPicker.vue'
import CouponPicker from './CouponPicker.vue'
import DeSlider from './DeSlider.vue'
import SimpleRichEditor from './SimpleRichEditor.vue'
import VideoPicker, { type PickedVideo } from './VideoPicker.vue'
import { uploadFile, getGoodsList, getCategoryList } from '../api/goods'
import '@wangeditor/editor/dist/css/style.css'
import { Editor as RtEditor, Toolbar as RtToolbar } from '@wangeditor/editor-for-vue'
import type { IDomEditor } from '@wangeditor/editor'

const props = defineProps<{ module: DesignerModule }>()
// 模板逐字沿用 DesignerEditor 的 selected.* 写法：selected 即当前选中组件
const selected = computed<DesignerModule>(() => props.module)

// ============ 图片/图标选择 ============
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

// ============ 商城商品组件：分类/分组 ============
const prodCategories = ref<{ id: number; name: string }[]>([])

watch(
  () => selected.value?.temp,
  async (t) => {
    if (t !== 'product') return
    const sel = selected.value
    if (sel && !sel.params.category) sel.params.category = 'all'
    if (prodCategories.value.length) return
    try {
      const data = await getCategoryList({ page: 1, pagesize: 100 })
      prodCategories.value = (data.list || []).map((c) => ({ id: c.id, name: c.name }))
    } catch {
      // 错误提示由 axios 拦截器统一弹出
    }
  },
)

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
const prodShownum = computed<number>({
  get: () => Number(selected.value?.params.proshownum || 6),
  set: (n) => { if (selected.value) selected.value.params.proshownum = String(n) },
})

// ============ 积分兑换组件 ============
function moveScoreshopUp(index: number) {
  if (index <= 0 || !selected.value) return
  const data = selected.value.data || []
  const temp = data[index - 1]
  data[index - 1] = data[index]
  data[index] = temp
}
function removeScoreshopItem(index: number) {
  if (!selected.value) return
  const data = selected.value.data || []
  data.splice(index, 1)
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
    // 错误提示由 axios 拦截器统一弹出
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

// ============ 视频选择 ============
const videoPickerVisible = ref(false)
const videoUploading = ref(false)
const videoFileInput = ref<HTMLInputElement | null>(null)
function openVideoPicker() {
  videoPickerVisible.value = true
}
function onVideoPick(v: PickedVideo) {
  if (!selected.value) return
  selected.value.params.type = '1'
  selected.value.params.src = String(v.id)
  if (v.cover) selected.value.params.pic = v.cover
}
function triggerVideoUpload() {
  videoFileInput.value?.click()
}
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
    // 错误提示由 axios 拦截器统一弹窗
  } finally {
    videoUploading.value = false
  }
}

// ============ data 数组增删 ============
function addDataItem(mod: DesignerModule, template: Record<string, any>) {
  if (!Array.isArray(mod.data)) mod.data = []
  mod.data.push({ ...template })
}
function removeDataItem(mod: DesignerModule, index: number) {
  if (!Array.isArray(mod.data)) return
  mod.data.splice(index, 1)
}
function moveDataItem(mod: DesignerModule, index: number, dir: -1 | 1) {
  const list = mod.data || []
  const to = index + dir
  if (to < 0 || to >= list.length) return
  const tmp = list[index]
  list[index] = list[to]
  list[to] = tmp
}
function moveDataItemUp(mod: DesignerModule, index: number) {
  if (!Array.isArray(mod.data) || index <= 0 || index >= mod.data.length) return
  const [item] = mod.data.splice(index, 1)
  mod.data.splice(index - 1, 0, item)
}

// ============ 优惠券 ============
const couponPickerVisible = ref(false)
const couponIconPickerVisible = ref(false)
function openCouponPicker() {
  if (!selected.value) {
    ElMessage.warning('请先选中优惠券组件')
    return
  }
  couponPickerVisible.value = true
}
function onCouponSelected(item: any) {
  if (!selected.value) return
  if (!selected.value.data) selected.value.data = []
  const couponid = item.couponid || item.id
  const existingIds = (selected.value.data || []).map((c: any) => c.couponid || c.id)
  if (existingIds.includes(couponid)) {
    ElMessage.warning('该优惠券已添加')
    return
  }
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
    endtime: item.endtime,
  })
}
function onCouponIconPicked(urls: string[]) {
  if (!selected.value || !urls[0]) return
  selected.value.params.bgpic = urls[0]
}
function moveCouponUp(idx: number) {
  if (!selected.value?.data || idx <= 0) return
  const arr = selected.value.data
  ;[arr[idx - 1], arr[idx]] = [arr[idx], arr[idx - 1]]
}
function removeCoupon(idx: number) {
  if (!selected.value?.data) return
  selected.value.data.splice(idx, 1)
}
function getTypeTag(type: any): string {
  const map: Record<string, string> = { '1': 'danger', '2': 'warning', '3': 'success', '4': 'info', '10': 'danger', '11': 'info' }
  return map[String(type)] || ''
}

// ============ 链接选择弹窗 ============
const chooseUrlVisible = ref(false)
let chooseUrlSetter: ((url: string, name: string) => void) | null = null
function openChooseUrl(setter: (url: string, name: string) => void) {
  chooseUrlSetter = setter
  chooseUrlVisible.value = true
}
function onChooseUrlSelect(url: string, name: string) {
  chooseUrlSetter?.(url, name)
  chooseUrlVisible.value = false
}

// ============ 显示平台 / 显示范围 ============
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
const platformAll = computed<boolean>({
  get: () => platformChecked.value.length === 0,
  set: (v: boolean) => { if (v) platformChecked.value = [] },
})
const quanxianAll = computed<boolean>({
  get: () => quanxianChecked.value.length === 0,
  set: (v: boolean) => { if (v) quanxianChecked.value = [] },
})

// ============ 选项卡「编辑选项内容」弹窗 ============
const tabDialogVisible = ref(false)
const tabDialogLoading = ref(false)
const tabDialogSaving = ref(false)
const tabDialogMod = ref<DesignerModule | null>(null)
const tabHtmlRows = ref<Record<string, string>>({})
function ensureTabItemIds(mod: DesignerModule) {
  if (!Array.isArray(mod.data)) mod.data = []
  mod.data.forEach((d) => {
    if (!d.id) d.id = genMid()
    if (d.name === undefined || d.name === null) d.name = ''
  })
}
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

// ============ 图片魔方 ============
const CUBE_SIZE = 4
const CUBE_UNIT = 160
const CUBE_COLORS = ['#F9E79F', '#E0A352', '#A9DF9B', '#7FBF7F', '#AED6F1', '#D7BDE2', '#F5B7B1', '#A3E4D7', '#F8C471', '#85C1E9']
function cubeSlotsOf(mod: DesignerModule): CubeSlot[] {
  if (!Array.isArray(mod.params.slots)) mod.params.slots = []
  return mod.params.slots as CubeSlot[]
}
function cubeCoverAt(mod: DesignerModule, x: number, y: number): CubeSlot | null {
  return cubeSlotsOf(mod).find((s) => x >= s.x && x < s.x + s.w && y >= s.y && y < s.y + s.h) || null
}
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
const cubeLayoutVisible = ref(false)
const cubeLayoutAnchor = ref<{ x: number; y: number }>({ x: 0, y: 0 })
const cubeLayoutHover = ref<{ w: number; h: number } | null>(null)
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
watch(() => props.module?.id, () => {
  cubeSlotIndex.value = -1
  cubeLayoutVisible.value = false
})

// ============ 数值/字符串 params 代理（存字符串口径） ============
function numParamProxy(key: string) {
  return computed({
    get: () => Number(selected.value?.params[key] || 0),
    set: (v: number) => {
      if (selected.value) selected.value.params[key] = String(v)
    },
  })
}
function strParamProxy(key: string, def: string) {
  return computed({
    get: () => String(selected.value?.params[key] ?? def),
    set: (v: string) => {
      if (selected.value) selected.value.params[key] = v
    },
  })
}

// 轮播 banner
const bnMarginY = numParamProxy('margin_y')
const bnMarginX = numParamProxy('margin_x')
const bnPaddingX = numParamProxy('padding_x')
const bnPaddingY = numParamProxy('padding_y')
const bnHeight = numParamProxy('height')
const bnRadius = numParamProxy('borderradius')
const bnDotBottom = numParamProxy('dotbottom')
const bnInterval = numParamProxy('interval')
// 单图 picture
const picMarginY = numParamProxy('margin_y')
const picMarginX = numParamProxy('margin_x')
const picPaddingX = numParamProxy('padding_x')
const picPaddingY = numParamProxy('padding_y')
const picRadius = numParamProxy('borderradius')
// 多图 pictures
const pgMarginY = numParamProxy('margin_y')
const pgMarginX = numParamProxy('margin_x')
const pgPaddingX = numParamProxy('padding_x')
const pgPaddingY = numParamProxy('padding_y')
// 标题 title
const ttMarginY = numParamProxy('margin_y')
const ttFontsize = numParamProxy('fontsize')
const ttIconsize = numParamProxy('iconsize')
const ttMarginX = numParamProxy('margin_x')
const ttPaddingX = numParamProxy('padding_x')
const ttPaddingY = numParamProxy('padding_y')
// 文本 text
const txMarginY = numParamProxy('margin_y')
const txFontsize = numParamProxy('fontsize')
const txLineheight = numParamProxy('lineheight')
const txLetterspacing = numParamProxy('letterspacing')
const txMarginX = numParamProxy('margin_x')
const txPaddingX = numParamProxy('padding_x')
const txPaddingY = numParamProxy('padding_y')
// 选项卡 tab
const tabMarginY = numParamProxy('margin_y')
const tabFontsize = numParamProxy('fontsize1')
const tabRadius = numParamProxy('borderradius')
// 视频 video
const vidMarginY = numParamProxy('margin_y')
const vidHeight = numParamProxy('videoheight')
// 按钮 button
const btnMarginY = numParamProxy('margin_y')
const btnFontsize = numParamProxy('btnfontsize')
const btnWidth = numParamProxy('btnwidth')
const btnHeight = numParamProxy('btnheight')
const btnRadius = numParamProxy('btnradius')
// 列表导航 dhlist
const dhMarginY = numParamProxy('margin_y')
const dhFontsize1 = numParamProxy('fontsize1')
const dhTitleLeft = numParamProxy('titlemarginleft')
const dhFontsize2 = numParamProxy('fontsize2')
// 悬浮按钮 cover
const covFontsize = numParamProxy('fontsize')
const covPicW = numParamProxy('picwidth')
const covPicH = numParamProxy('picheight')
const covBorder = numParamProxy('border')
const covTop = numParamProxy('top')
const covLeft = numParamProxy('left')
const covWidth = numParamProxy('width')
const covHeight = numParamProxy('height')
const covRadius = numParamProxy('radius')
// 店招 shop
const shopNavH = numParamProxy('navheight')
const shopNavFont = numParamProxy('navfontsize')
const shopNavIcon = numParamProxy('naviconsize')
// 精选 featured
const featMarginY = numParamProxy('margin_y')
const featMarginX = numParamProxy('margin_x')
const featPaddingX = numParamProxy('padding_x')
const featPaddingY = numParamProxy('padding_y')
const featHeight = numParamProxy('height')
const featDotBottom = numParamProxy('dotbottom')
const featInterval = numParamProxy('interval')
// 魔方 cube
const cubeMarginY = numParamProxy('margin_y')
const cubeMarginX = numParamProxy('margin_x')
const cubePaddingX = numParamProxy('padding_x')
const cubePaddingY = numParamProxy('padding_y')
// 公告 notice 显隐（字符串 radio）
const ntShowImg = strParamProxy('showimg', '0')
const ntShowIcon = strParamProxy('showicon', '1')

// ============ 富文本 ============
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
</script>

<template>
  <div class="mpp-root">

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
