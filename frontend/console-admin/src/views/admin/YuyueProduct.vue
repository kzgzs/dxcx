<script setup lang="ts">
/**
 * 扩展·预约服务 - 服务商品
 * 对齐截图：列表(搜索/批量/上架下架)；平铺增改(服务信息三部分+服务详情，右上X)；
 * 4 个条件组（服务方式含限制区域省市弹窗 / 结算方式 / 可预约日期 / 可预约时间）切换-保存-回填打通。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getYuyueProductList,
  getYuyueProductDetail,
  addYuyueProduct,
  editYuyueProduct,
  deleteYuyueProduct,
  setYuyueProductStatus,
  copyYuyueProduct,
  getYuyueFuwuList,
  getYuyueWorkerList,
} from '../../api/yuyue'
import { getYuyueCategoryList } from '../../api/yuyue'
import type { YuyueProductListItem } from '../../api/yuyue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'
import type { DesignerModule } from '../../api/designer'
import { defaultModule } from './designerModules'
import { areaProvinces } from '../../utils/areaData'

/* ---------------- 列表 ---------------- */
const loading = ref(false)
const rows = ref<YuyueProductListItem[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(10)
const query = reactive({ name: '', status: '' as number | '' })
const checkedIds = ref<Set<number>>(new Set())

async function loadList() {
  loading.value = true
  try {
    const res = await getYuyueProductList({
      page: page.value,
      pagesize: pageSize.value,
      name: query.name || undefined,
      status: query.status,
    })
    rows.value = res.list ?? []
    total.value = res.total ?? 0
  } finally {
    loading.value = false
  }
}

function onCheckChange(row: YuyueProductListItem, checked: boolean) {
  if (checked) checkedIds.value.add(row.id)
  else checkedIds.value.delete(row.id)
}

function onSearch() {
  page.value = 1
  loadList()
}

async function batchDelete() {
  const ids = Array.from(checkedIds.value)
  if (!ids.length) {
    ElMessage.warning('请先勾选')
    return
  }
  await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个商品？`, '提示', { type: 'warning' })
  await deleteYuyueProduct(ids)
  checkedIds.value.clear()
  ElMessage.success('删除成功')
  loadList()
}

async function batchStatus(status: number) {
  const ids = Array.from(checkedIds.value)
  if (!ids.length) {
    ElMessage.warning('请先勾选')
    return
  }
  await setYuyueProductStatus(ids, status)
  checkedIds.value.clear()
  ElMessage.success(status === 1 ? '已上架' : '已下架')
  loadList()
}

async function removeRow(row: YuyueProductListItem) {
  await ElMessageBox.confirm(`确定删除商品「${row.name}」？`, '提示', { type: 'warning' })
  await deleteYuyueProduct(row.id)
  ElMessage.success('删除成功')
  loadList()
}

async function toggleStatus(row: YuyueProductListItem) {
  await setYuyueProductStatus([row.id], row.status === 1 ? 0 : 1)
  ElMessage.success(row.status === 1 ? '已下架' : '已上架')
  loadList()
}

async function copyRow(row: YuyueProductListItem) {
  await copyYuyueProduct(row.id)
  ElMessage.success('已复制')
  loadList()
}

/* ---------------- 查看链接弹窗 ---------------- */
const linkVisible = ref(false)
const linkPath = ref('')
function openLink(row: YuyueProductListItem) {
  linkPath.value = `/yuyue/yuyue/product?id=${row.id}`
  linkVisible.value = true
}

/* ---------------- 增改平铺面板 ---------------- */
const editing = ref(false)
const editId = ref(0)
const activeTab = ref<'info' | 'detail'>('info')

const form = reactive<any>({
  name: '',
  pic: '',
  pics: [] as string[],
  danwei: '',
  fwid: [] as string[],
  sellpoint: '',
  cid: '',
  // 条件组1 服务方式
  fwtype: [] as string[],
  isareaxz: 0,
  areadata: '',
  fwpeople: 0,
  fwpeoid: [] as string[],
  // 规格
  guigedata: [] as any[],
  level_discount: 0,
  balance: 0,
  // 条件组2 结算方式
  jiesuantype: 0,
  tcmoney: 0,
  tichengtype: 0,
  tc_bfb: 0,
  // 条件组3 可预约日期
  rqtype: 1,
  yyzhouqi: [] as string[],
  yybegintime: '',
  yyendtime: '',
  yytimeday: [] as string[],
  // 条件组4 可预约时间
  datetype: 1,
  zaohour: 7,
  wanhour: 18,
  timejg: 30,
  timepoint: [] as string[],
  yynum: 1,
  pdprehour: 4,
  // 设置表单
  formdata: [] as any[],
  sales: 0,
  sort: 0,
  gettj: ['-1'],
  status: 1,
  start_time: '',
  end_time: '',
  is_open: 1,
  noopentip: '',
  sell_price: 0,
  detailModules: [] as DesignerModule[],
})

/* 服务详情 DIY 模块：新增默认一个富文本模块（对齐 Goods.defaultDetailModules） */
function defaultDetailModules(): DesignerModule[] {
  return [defaultModule('richtext')]
}

/**
 * 把后端 detail 列还原为 DIY 模块数组（点大口径：detail 存模块数组 JSON）。
 * 兼容旧版富文本 HTML（非 JSON）：包裹成一个富文本模块，不丢内容。
 */
function parseDetailModules(raw: unknown): DesignerModule[] {
  const str = typeof raw === 'string' ? raw.trim() : ''
  if (str) {
    try {
      const parsed = JSON.parse(str)
      if (Array.isArray(parsed)) return parsed as DesignerModule[]
    } catch {
      // 旧 HTML，落到下方包裹逻辑
    }
    const m = defaultModule('richtext')
    ;(m as Record<string, unknown>).content = str
    return [m]
  }
  return defaultDetailModules()
}

/* 下拉选项 */
const categoryOptions = ref<{ id: number; name: string }[]>([])
const fuwuOptions = ref<{ id: number; name: string }[]>([])
const workerOptions = ref<{ id: number; realname: string }[]>([])

async function loadOptions() {
  try {
    const cat = await getYuyueCategoryList()
    const flat: { id: number; name: string }[] = []
    for (const c of cat.list ?? []) {
      flat.push({ id: c.id, name: c.name })
      for (const child of c.children ?? []) flat.push({ id: child.id, name: child.name })
    }
    categoryOptions.value = flat
  } catch { /* 忽略 */ }
  try {
    const f = await getYuyueFuwuList({ page: 1, pagesize: 100 })
    fuwuOptions.value = (f.list ?? []).map((x: any) => ({ id: x.id, name: x.name }))
  } catch { /* 忽略 */ }
  try {
    const w = await getYuyueWorkerList({ page: 1, pagesize: 200 })
    workerOptions.value = (w.list ?? []).map((x: any) => ({ id: x.id, realname: x.name ?? x.realname ?? '' }))
  } catch { /* 忽略 */ }
}

/* 条件组显隐 */
const isShangmen = computed(() => form.fwtype.map(String).includes('2'))
const showAreaLimit = computed(() => isShangmen.value && form.isareaxz === 1)
const showDanji = computed(() => form.jiesuantype === 0)
const showBilü = computed(() => form.jiesuantype === 1)
const showRiqīCycle = computed(() => form.rqtype === 1)
const showRiqīRange = computed(() => form.rqtype === 2)
const showRiqīFixed = computed(() => form.rqtype === 3)
const showTimeSection = computed(() => form.datetype === 1)
const showTimePoint = computed(() => form.datetype === 2)

const ZHOUQI = [
  { label: '周一', value: '1' },
  { label: '周二', value: '2' },
  { label: '周三', value: '3' },
  { label: '周四', value: '4' },
  { label: '周五', value: '5' },
  { label: '周六', value: '6' },
  { label: '周日', value: '0' },
]
const FIXED_DAYS = [
  { label: '当天', value: '1' },
  { label: '第一天', value: '2' },
  { label: '第二天', value: '3' },
  { label: '第三天', value: '4' },
  { label: '第四天', value: '5' },
  { label: '第五天', value: '6' },
  { label: '第六天', value: '7' },
  { label: '第七天', value: '8' },
]

/* 时间段滑块 */
const timeRange = computed({
  get: () => [form.zaohour, form.wanhour],
  set: (v: number[]) => {
    form.zaohour = v[0]
    form.wanhour = v[1]
  },
})
function rangeText() {
  const endDay = form.wanhour > 24 ? '次日' : '当日'
  const endHour = form.wanhour > 24 ? form.wanhour - 24 : form.wanhour
  return `当日：${form.zaohour}时 ~ ${endDay}：${endHour}时`
}

/* 时间点 */
const newTimePoint = ref('')
function addTimePoint() {
  if (!newTimePoint.value) return
  if (!form.timepoint.includes(newTimePoint.value)) form.timepoint.push(newTimePoint.value)
  newTimePoint.value = ''
}
function removeTimePoint(t: string) {
  form.timepoint = form.timepoint.filter((x: string) => x !== t)
}

/* 固定周期 */
const newFixedDay = ref('1')
function addFixedDay() {
  if (!form.yytimeday.includes(newFixedDay.value)) form.yytimeday.push(newFixedDay.value)
}
function removeFixedDay(v: string) {
  form.yytimeday = form.yytimeday.filter((x: string) => x !== v)
}
function fixedDayLabel(v: string) {
  return FIXED_DAYS.find((x) => x.value === v)?.label ?? v
}

/* ---------------- 限制区域省市弹窗 ---------------- */
const areaModalVisible = ref(false)
// 选中结构：{ [province]: 'all' | string[] }
const areaSelection = ref<Record<string, string[]>>({})
function openAreaModal() {
  // 由 areadata 反解为 areaSelection
  const sel: Record<string, string[]> = {}
  ;(form.areadata as string).split(';').filter(Boolean).forEach((seg: string) => {
    const m = seg.match(/^(.+?)\[(.*)\]$/)
    if (!m) return
    const prov = m[1]
    const cities = m[2]
    sel[prov] = cities === '全部地区' ? [] : cities.split(',').filter(Boolean)
  })
  areaSelection.value = sel
  areaModalVisible.value = true
}

function toggleProvince(provName: string, cityCount: number) {
  const sel = { ...areaSelection.value }
  if (sel[provName]) {
    // 取消整省
    delete sel[provName]
  } else {
    // 选省 = 全选该省（全部地区）
    sel[provName] = []
    void cityCount
  }
  areaSelection.value = sel
}
function toggleCity(provName: string, cityName: string) {
  const sel = { ...areaSelection.value }
  const exist = sel[provName] ?? null
  if (!exist) {
    sel[provName] = [cityName]
  } else {
    // 已是全部地区([]) → 转为包含模型，从空开始累计选中市（=仅选个别市）
    let cities: string[] = exist.length === 0 ? [] : [...exist]
    if (cities.includes(cityName)) cities = cities.filter((c) => c !== cityName)
    else cities.push(cityName)
    sel[provName] = cities
  }
  areaSelection.value = sel
}
function isProvinceChecked(provName: string) {
  return !!areaSelection.value[provName]
}
function isCityChecked(provName: string, cityName: string) {
  const v = areaSelection.value[provName]
  if (!v) return false
  if (v.length === 0) return true // 全部地区
  return v.includes(cityName)
}
function confirmArea() {
  let out = ''
  for (const [prov, cities] of Object.entries(areaSelection.value)) {
    out += cities.length === 0 ? `${prov}[全部地区];` : `${prov}[${cities.join(',')}];`
  }
  form.areadata = out
  areaModalVisible.value = false
}

/* ---------------- 设置表单字段 ---------------- */
const newFormField = reactive({ type: '单行输入', name: '', content: '', req: false })
function addFormField() {
  if (!newFormField.name.trim()) {
    ElMessage.warning('请输入字段名称')
    return
  }
  form.formdata.push({ ...newFormField, name: newFormField.name.trim() })
  newFormField.name = ''
  newFormField.content = ''
  newFormField.req = false
}
function removeFormField(idx: number) {
  form.formdata.splice(idx, 1)
}

/* ---------------- 打开增改 / 回填 / 提交 ---------------- */
function resetForm() {
  Object.assign(form, {
    name: '', pic: '', pics: [], danwei: '', fwid: [], sellpoint: '', cid: '',
    fwtype: [], isareaxz: 0, areadata: '', fwpeople: 0, fwpeoid: [],
    guigedata: [], level_discount: 0, balance: 0,
    jiesuantype: 0, tcmoney: 0, tichengtype: 0, tc_bfb: 0,
    rqtype: 1, yyzhouqi: [], yybegintime: '', yyendtime: '', yytimeday: [],
    datetype: 1, zaohour: 7, wanhour: 18, timejg: 30, timepoint: [],
    yynum: 1, pdprehour: 4, formdata: [],
    sales: 0, sort: 0, gettj: ['-1'], status: 1,
    start_time: '', end_time: '', is_open: 1, noopentip: '',
    sell_price: 0, detailModules: defaultDetailModules(),
  })
}

function openAdd() {
  editId.value = 0
  activeTab.value = 'info'
  resetForm()
  editing.value = true
}

async function openEdit(row: YuyueProductListItem) {
  editId.value = row.id
  activeTab.value = 'info'
  resetForm()
  const d: any = await getYuyueProductDetail(row.id)
  Object.assign(form, {
    name: d.name ?? '',
    pic: d.pic ?? '',
    pics: d.pics ?? [],
    danwei: d.danwei ?? '',
    fwid: (d.fwid ?? []).map(String),
    sellpoint: d.sellpoint ?? '',
    cid: d.cid ?? '',
    fwtype: (d.fwtype ?? []).map(String),
    isareaxz: d.isareaxz ?? 0,
    areadata: d.areadata ?? '',
    fwpeople: d.fwpeople ?? 0,
    fwpeoid: (d.fwpeoid ?? []).map(String),
    guigedata: d.guigedata ?? [],
    level_discount: d.level_discount ?? 0,
    balance: d.balance ?? 0,
    jiesuantype: d.jiesuantype ?? 0,
    tcmoney: d.tcmoney ?? 0,
    tichengtype: d.tichengtype ?? 0,
    tc_bfb: d.tc_bfb ?? 0,
    rqtype: d.rqtype ?? 1,
    yyzhouqi: (d.yyzhouqi ?? []).map(String),
    yybegintime: d.yybegintime ?? '',
    yyendtime: d.yyendtime ?? '',
    yytimeday: (d.yytimeday ?? []).map(String),
    datetype: d.datetype ?? 1,
    zaohour: d.zaohour ?? 7,
    wanhour: d.wanhour ?? 18,
    timejg: d.timejg ?? 30,
    timepoint: d.timepoint ?? [],
    yynum: d.yynum ?? 1,
    pdprehour: d.pdprehour ?? 4,
    formdata: d.formdata ?? [],
    sales: d.sales ?? 0,
    sort: d.sort ?? 0,
    gettj: (d.gettj ?? []).map(String),
    status: d.status ?? 1,
    start_time: d.start_time ?? '',
    end_time: d.end_time ?? '',
    is_open: d.is_open ?? 1,
    noopentip: d.noopentip ?? '',
    sell_price: d.sell_price ?? 0,
    detailModules: parseDetailModules(d.detail),
  })
  editing.value = true
}

function buildPayload() {
  return {
    id: editId.value || undefined,
    name: form.name,
    cid: form.cid,
    pic: form.pic,
    pics: form.pics,
    sellpoint: form.sellpoint,
    sell_price: form.sell_price,
    sales: form.sales,
    sort: form.sort,
    status: form.status,
    detail: JSON.stringify(form.detailModules || []),
    danwei: form.danwei,
    fwid: form.fwid,
    fwtype: form.fwtype,
    isareaxz: form.isareaxz,
    areadata: form.areadata,
    fwpeople: form.fwpeople,
    fwpeoid: form.fwpeoid,
    guigedata: form.guigedata,
    level_discount: form.level_discount,
    balance: form.balance,
    jiesuantype: form.jiesuantype,
    tcmoney: form.tcmoney,
    tichengtype: form.tichengtype,
    tc_bfb: form.tc_bfb,
    rqtype: form.rqtype,
    yyzhouqi: form.yyzhouqi,
    yybegintime: form.yybegintime,
    yyendtime: form.yyendtime,
    yytimeday: form.yytimeday,
    datetype: form.datetype,
    zaohour: form.zaohour,
    wanhour: form.wanhour,
    timejg: form.timejg,
    timepoint: form.timepoint,
    yynum: form.yynum,
    pdprehour: form.pdprehour,
    formdata: form.formdata,
    gettj: form.gettj,
    start_time: form.start_time,
    end_time: form.end_time,
    is_open: form.is_open,
    noopentip: form.noopentip,
  }
}

async function submit() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写服务名称')
    return
  }
  if (editId.value) {
    await editYuyueProduct(buildPayload() as any)
    ElMessage.success('保存成功')
  } else {
    await addYuyueProduct(buildPayload() as any)
    ElMessage.success('添加成功')
  }
  editing.value = false
  loadList()
}

/* 图片选择器 */
const pickerKind = ref<'main' | 'gallery' | ''>('')
function openPicker(kind: 'main' | 'gallery') {
  pickerKind.value = kind
  pickerVisible.value = true
}
const pickerVisible = ref(false)
function onPickImage(urls: string[]) {
  if (pickerKind.value === 'main') form.pic = urls[0] ?? ''
  else if (pickerKind.value === 'gallery') form.pics = urls
  pickerVisible.value = false
}

onMounted(() => {
  loadOptions()
  loadList()
})
</script>

<template>
  <div class="page">
    <!-- 列表态 -->
    <template v-if="!editing">
      <div class="page-head"><span class="page-title">服务商品</span></div>

      <div class="toolbar">
        <el-button type="primary" @click="openAdd">添加</el-button>
        <el-button type="danger" :disabled="!checkedIds.size" @click="batchDelete">删除</el-button>
        <el-button type="success" :disabled="!checkedIds.size" @click="batchStatus(1)">上架</el-button>
        <el-button type="warning" :disabled="!checkedIds.size" @click="batchStatus(0)">下架</el-button>

        <div class="search-bar">
          <el-input v-model="query.name" placeholder="服务名称" class="search-input" @keyup.enter="onSearch" />
          <el-select v-model="query.status" class="search-select" placeholder="状态">
            <el-option label="全部" :value="''" />
            <el-option label="已上架" :value="1" />
            <el-option label="未上架" :value="0" />
          </el-select>
          <el-button type="primary" @click="onSearch">搜索</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="rows" border size="small" class="data-table">
        <el-table-column width="42">
          <template #default="{ row }">
            <el-checkbox :model-value="checkedIds.has(row.id)" @change="(v: boolean) => onCheckChange(row, v)" />
          </template>
        </el-table-column>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="服务信息" min-width="260">
          <template #default="{ row }">
            <div class="svc-cell">
              <img v-if="row.pic" :src="row.pic" class="svc-thumb" />
              <div class="svc-meta">
                <div class="svc-name"><span v-if="row.cname" class="svc-cat">[{{ row.cname }}]</span>{{ row.name }}</div>
                <div class="svc-price">价格¥{{ row.sell_price }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="sales" label="已售" width="80" />
        <el-table-column prop="sort" label="序号" width="80" />
        <el-table-column prop="create_time" label="创建时间" width="150" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag v-if="row.status === 1" type="success" size="small">已上架</el-tag>
            <el-tag v-else type="info" size="small">未上架</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="340">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openLink(row)">查看链接</el-button>
            <el-button link type="primary" size="small" @click="copyRow(row)">复制</el-button>
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="removeRow(row)">删除</el-button>
            <el-button link type="warning" size="small" @click="toggleStatus(row)">{{ row.status === 1 ? '下架' : '上架' }}</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :total="total"
          layout="prev, pager, next, jumper, total, sizes"
          @change="loadList"
        />
      </div>
    </template>

    <!-- 增改平铺面板 -->
    <template v-else>
      <div class="goods-edit">
        <div class="ge-head">
          <span class="ge-title">{{ editId ? '编辑服务商品' : '添加服务商品' }}</span>
          <button class="ge-close" @click="editing = false">×</button>
        </div>

        <div class="tab-bar">
          <span :class="['tab', { active: activeTab === 'info' }]" @click="activeTab = 'info'">服务信息</span>
          <span :class="['tab', { active: activeTab === 'detail' }]" @click="activeTab = 'detail'">服务详情</span>
        </div>

        <!-- 服务信息 -->
        <div v-if="activeTab === 'info'" class="ge-body">
          <div class="form-row">
            <label class="form-label">服务名称</label>
            <el-input v-model="form.name" class="form-control" />
          </div>

          <div class="form-row">
            <label class="form-label">服务主图</label>
            <div>
              <el-button size="small" @click="openPicker('main')">上传图片</el-button>
              <span class="hint">建议尺寸640x640像素</span>
              <div v-if="form.pic" class="img-preview"><img :src="form.pic" /></div>
            </div>
          </div>

          <div class="form-row">
            <label class="form-label">服务图片</label>
            <div>
              <el-button size="small" @click="openPicker('gallery')">批量上传</el-button>
              <span class="hint">建议尺寸640x640像素</span>
              <div class="img-row">
                <img v-for="p in form.pics" :key="p" :src="p" class="img-item" />
              </div>
            </div>
          </div>

          <div class="form-row">
            <label class="form-label">单位</label>
            <el-input v-model="form.danwei" class="form-control" placeholder="如：次/小时" />
          </div>

          <div class="form-row">
            <label class="form-label">商品服务</label>
            <el-checkbox-group v-model="form.fwid">
              <el-checkbox v-for="o in fuwuOptions" :key="o.id" :value="String(o.id)">{{ o.name }}</el-checkbox>
            </el-checkbox-group>
          </div>

          <div class="form-row">
            <label class="form-label">服务介绍</label>
            <el-input v-model="form.sellpoint" class="form-control" placeholder="在服务详情页标题下面展示卖点信息，建议60字以内" />
          </div>

          <div class="form-row">
            <label class="form-label">服务类目</label>
            <el-select v-model="form.cid" multiple class="form-control" placeholder="-请选择-" :multiple-limit="5">
              <el-option v-for="o in categoryOptions" :key="o.id" :label="o.name" :value="String(o.id)" />
            </el-select>
            <span class="hint">最多可选5个分类</span>
          </div>

          <!-- 条件组1 服务方式 -->
          <div class="form-row">
            <label class="form-label">服务方式</label>
            <el-checkbox-group v-model="form.fwtype">
              <el-checkbox value="2">上门服务</el-checkbox>
              <el-checkbox value="1">到店服务</el-checkbox>
            </el-checkbox-group>
          </div>

          <template v-if="isShangmen">
            <div class="form-row">
              <label class="form-label">区域限制</label>
              <el-radio-group v-model="form.isareaxz">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
              <span class="hint">选中区域为可下单区域，仅上门服务方式可以</span>
            </div>

            <div v-if="showAreaLimit" class="form-row">
              <label class="form-label">限制区域</label>
              <span class="area-text">{{ form.areadata }}</span>
              <el-button size="small" @click="openAreaModal">编辑</el-button>
            </div>
          </template>

          <div class="form-row">
            <label class="form-label">派单方式</label>
            <el-radio-group v-model="form.fwpeople">
              <el-radio :value="0">后台派单</el-radio>
              <el-radio :value="1">用户选择</el-radio>
            </el-radio-group>
          </div>

          <div class="form-row">
            <label class="form-label">服务人员</label>
            <el-select v-model="form.fwpeoid" multiple class="form-control" placeholder="-请选择-">
              <el-option v-for="w in workerOptions" :key="w.id" :label="w.realname" :value="String(w.id)" />
            </el-select>
          </div>

          <div class="form-row">
            <label class="form-label">会员等级折扣</label>
            <el-radio-group v-model="form.level_discount">
              <el-radio :value="0">跟随系统</el-radio>
              <el-radio :value="1">参与</el-radio>
              <el-radio :value="2">不参与</el-radio>
            </el-radio-group>
            <span class="hint">跟随系统：预约服务-系统设置-会员等级折扣</span>
          </div>

          <div class="form-row">
            <label class="form-label">尾款</label>
            <el-input-number v-model="form.balance" :min="0" :max="100" class="form-control" />
            <span class="hint">%</span>
          </div>

          <!-- 条件组2 结算方式 -->
          <div class="form-row">
            <label class="form-label">结算方式</label>
            <el-radio-group v-model="form.jiesuantype">
              <el-radio :value="0">按单计算提成</el-radio>
              <el-radio :value="1">按提成比例计费提成</el-radio>
            </el-radio-group>
          </div>
          <div v-if="showDanji" class="form-row">
            <label class="form-label">提成金额</label>
            <el-input-number v-model="form.tcmoney" :min="0" class="form-control" />
            <span class="hint">元每单</span>
            <span class="hint">师傅完成一单拿到的金额</span>
          </div>
          <div v-if="showBilü" class="form-row">
            <label class="form-label">提成比例</label>
            <el-radio-group v-model="form.tichengtype">
              <el-radio :value="0">按商品价格</el-radio>
              <el-radio :value="1">按实际支付</el-radio>
            </el-radio-group>
            <el-input-number v-model="form.tc_bfb" :min="0" :max="100" class="form-control" />
            <span class="hint">%</span>
            <span class="hint">师傅可拿订单金额的比例</span>
          </div>

          <!-- 条件组3 可预约日期 -->
          <div class="form-row">
            <label class="form-label">可预约日期</label>
            <el-radio-group v-model="form.rqtype">
              <el-radio :value="1">按周期</el-radio>
              <el-radio :value="2">按时间</el-radio>
              <el-radio :value="3">按固定周期</el-radio>
            </el-radio-group>
          </div>
          <div v-if="showRiqīCycle" class="form-row">
            <label class="form-label">预约周期</label>
            <el-checkbox-group v-model="form.yyzhouqi">
              <el-checkbox v-for="z in ZHOUQI" :key="z.value" :value="z.value">{{ z.label }}</el-checkbox>
            </el-checkbox-group>
          </div>
          <div v-if="showRiqīRange" class="form-row">
            <label class="form-label">选择日期</label>
            <el-date-picker v-model="form.yybegintime" type="date" value-format="YYYY-MM-DD" class="form-control" />
            <span class="hint">到</span>
            <el-date-picker v-model="form.yyendtime" type="date" value-format="YYYY-MM-DD" class="form-control" />
          </div>
          <div v-if="showRiqīFixed" class="form-row">
            <label class="form-label">选择日期</label>
            <el-select v-model="newFixedDay" class="form-control">
              <el-option v-for="d in FIXED_DAYS" :key="d.value" :label="d.label" :value="d.value" />
            </el-select>
            <el-button size="small" @click="addFixedDay">+</el-button>
            <div class="tag-row">
              <span v-for="v in form.yytimeday" :key="v" class="tag-chip">
                {{ fixedDayLabel(v) }}<i @click="removeFixedDay(v)">×</i>
              </span>
            </div>
          </div>

          <!-- 条件组4 可预约时间 -->
          <div class="form-row">
            <label class="form-label">可预约时间</label>
            <el-radio-group v-model="form.datetype">
              <el-radio :value="1">时间段</el-radio>
              <el-radio :value="2">时间点</el-radio>
            </el-radio-group>
          </div>
          <div v-if="showTimeSection" class="form-row">
            <label class="form-label">设置时间段</label>
            <div class="slider-wrap">
              <el-slider v-model="timeRange" range :min="0" :max="24" :step="1" />
              <span class="hint">{{ rangeText() }}</span>
            </div>
          </div>
          <div v-if="showTimeSection" class="form-row">
            <label class="form-label">时间间隔</label>
            <el-input-number v-model="form.timejg" :min="1" class="form-control" />
            <span class="hint">分钟</span>
          </div>
          <div v-if="showTimePoint" class="form-row">
            <label class="form-label">选择时间点</label>
            <el-time-picker v-model="newTimePoint" value-format="HH:mm" format="HH:mm" class="form-control" />
            <el-button size="small" @click="addTimePoint">+</el-button>
            <div class="tag-row">
              <span v-for="t in form.timepoint" :key="t" class="tag-chip">{{ t }}<i @click="removeTimePoint(t)">×</i></span>
            </div>
          </div>

          <div class="form-row">
            <label class="form-label">预约人数</label>
            <el-input-number v-model="form.yynum" :min="1" class="form-control" />
            <span class="hint">同一时间预约人数限制，默认为1</span>
          </div>

          <div class="form-row">
            <label class="form-label">可选条件</label>
            <span class="hint">下单时间大于可选时间</span>
            <el-input-number v-model="form.pdprehour" :min="0" class="form-control" />
            <span class="hint">小时</span>
          </div>

          <!-- 设置表单 -->
          <div class="form-row">
            <label class="form-label">设置表单</label>
            <div class="form-grid-btns">
              <el-button size="small">单行输入</el-button>
              <el-button size="small">多行输入</el-button>
              <el-button size="small">单项选择</el-button>
              <el-button size="small">多项选择</el-button>
              <el-button size="small">普通选择</el-button>
              <el-button size="small">时间选择</el-button>
              <el-button size="small">日期选择</el-button>
              <el-button size="small">上传图片</el-button>
            </div>
          </div>
          <div class="form-row">
            <label class="form-label"></label>
            <div class="field-add">
              <el-select v-model="newFormField.type" class="mini-select">
                <el-option label="单行输入" value="单行输入" />
                <el-option label="多行输入" value="多行输入" />
              </el-select>
              <el-input v-model="newFormField.name" placeholder="字段名称" class="mini-input" />
              <el-input v-model="newFormField.content" placeholder="选填，请输入备注信息" class="mini-input" />
              <el-select v-model="newFormField.req" class="mini-select">
                <el-option label="文本" :value="false" />
              </el-select>
              <el-switch v-model="newFormField.req" />
              <el-button size="small" type="primary" @click="addFormField">添加</el-button>
            </div>
          </div>
          <div v-if="form.formdata.length" class="form-row">
            <label class="form-label"></label>
            <el-table :data="form.formdata" border size="small" class="mini-table">
              <el-table-column prop="type" label="字段类型" width="120" />
              <el-table-column prop="name" label="字段名称" />
              <el-table-column prop="content" label="字段内容" />
              <el-table-column label="是否必填" width="90">
                <template #default="{ row }">{{ row.req ? '是' : '否' }}</template>
              </el-table-column>
              <el-table-column label="操作" width="80">
                <template #default="{ $index }">
                  <el-button link type="danger" size="small" @click="removeFormField($index)">×</el-button>
                </template>
              </el-table-column>
            </el-table>
          </div>

          <div class="form-row">
            <label class="form-label">销量</label>
            <el-input-number v-model="form.sales" :min="0" class="form-control" />
          </div>
          <div class="form-row">
            <label class="form-label">序号</label>
            <el-input-number v-model="form.sort" :min="0" class="form-control" />
            <span class="hint">用于排序,越大越靠前</span>
          </div>

          <div class="form-row">
            <label class="form-label">购买条件</label>
            <el-checkbox-group v-model="form.gettj">
              <el-checkbox value="-1">所有人</el-checkbox>
              <el-checkbox value="0">关注用户</el-checkbox>
            </el-checkbox-group>
          </div>

          <div class="form-row">
            <label class="form-label">状态</label>
            <el-radio-group v-model="form.status">
              <el-radio :value="1">已上架</el-radio>
              <el-radio :value="0">未上架</el-radio>
              <el-radio :value="2">设置上架时间</el-radio>
            </el-radio-group>
          </div>
          <div v-if="form.status === 2" class="form-row">
            <label class="form-label">上架时间</label>
            <el-date-picker v-model="form.start_time" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" class="form-control" />
            <span class="hint">到</span>
            <el-date-picker v-model="form.end_time" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" class="form-control" />
          </div>

          <div class="form-row">
            <label class="form-label">显示状态</label>
            <el-radio-group v-model="form.is_open">
              <el-radio :value="1">营业中</el-radio>
              <el-radio :value="0">停业中</el-radio>
            </el-radio-group>
            <el-input v-model="form.noopentip" class="form-control" placeholder="停业提示(默认休息中，100个字符以内)" />
          </div>

          <div class="form-row">
            <label class="form-label"></label>
            <el-button type="primary" @click="activeTab = 'detail'">下一步:编辑服务详情</el-button>
            <el-button type="warning" @click="submit">提交</el-button>
          </div>
        </div>

        <!-- 服务详情（DIY 装修器：左手机预览 + 右组件库/属性，对齐截图） -->
        <div v-else class="ge-body">
          <GoodsDetailEditor v-model="form.detailModules" title="服务详情" />
          <div class="form-row" style="margin-top:14px">
            <label class="form-label"></label>
            <el-button type="primary" @click="activeTab = 'info'">上一步:编辑服务信息</el-button>
            <el-button type="warning" @click="submit">提交</el-button>
          </div>
        </div>
      </div>
    </template>

    <ImgIconPicker v-model:visible="pickerVisible" :multi="pickerKind === 'gallery'" @pick="onPickImage" />

    <LinkAddressModal
      v-model:visible="linkVisible"
      name="服务商品"
      :path="linkPath"
    />

    <!-- 限制区域省市弹窗 -->
    <el-dialog v-model="areaModalVisible" title="选择可下单区域（省/市）" width="520px" append-to-body>
      <div class="area-tree">
        <div v-for="p in areaProvinces" :key="p.name" class="area-prov">
          <el-checkbox
            :model-value="isProvinceChecked(p.name)"
            @change="() => toggleProvince(p.name, p.cityList.length)"
          >{{ p.name }}</el-checkbox>
          <el-checkbox
            v-for="c in p.cityList"
            :key="c.name"
            :model-value="isCityChecked(p.name, c.name)"
            @change="() => toggleCity(p.name, c.name)"
            class="area-city"
          >{{ c.name }}</el-checkbox>
        </div>
      </div>
      <template #footer>
        <el-button @click="areaModalVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmArea">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page { padding: 3px; }
.page-head { margin-bottom: 3px; }
.page-title { font-weight: 600; font-size: 15px; }
.toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 3px; flex-wrap: wrap; }
.search-bar { margin-left: auto; display: flex; gap: 6px; align-items: center; }
.search-input { width: 160px; }
.search-select { width: 110px; }
.data-table :deep(th) { background: #e6e6e6; }
.svc-cell { display: flex; gap: 8px; align-items: center; }
.svc-thumb { width: 44px; height: 44px; object-fit: cover; border-radius: 3px; }
.svc-cat { color: #f56c6c; margin-right: 2px; }
.svc-price { color: #666; font-size: 12px; margin-top: 2px; }
.pager { margin-top: 8px; }

/* 平铺面板 */
.goods-edit { border: 1px solid #e6e6e6; border-radius: 4px; background: #fff; }
.ge-head { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid #eee; }
.ge-title { font-weight: 600; }
.ge-close { border: none; background: transparent; font-size: 22px; cursor: pointer; color: #666; }
.tab-bar { display: flex; gap: 24px; padding: 10px 14px; border-bottom: 1px solid #eee; }
.tab { cursor: pointer; padding-bottom: 6px; color: #333; }
.tab.active { color: #f56c6c; border-bottom: 2px solid #f56c6c; font-weight: 600; }
.ge-body { padding: 16px; }
.form-row { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
.form-label { width: 110px; text-align: right; color: #333; flex-shrink: 0; line-height: 32px; }
.form-control { flex: 0 1 340px; text-align: left; }
.hint { color: #999; font-size: 12px; line-height: 32px; }
.img-preview img { width: 80px; height: 80px; object-fit: cover; border: 1px solid #eee; border-radius: 3px; }
.img-row { display: flex; gap: 6px; margin-top: 6px; }
.img-item { width: 56px; height: 56px; object-fit: cover; border: 1px solid #eee; border-radius: 3px; }
.area-text { line-height: 32px; color: #333; max-width: 400px; }
.slider-wrap { width: 400px; }
.tag-row { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.tag-chip { background: #f0f9eb; border: 1px solid #e1f3d8; border-radius: 3px; padding: 2px 8px; font-size: 12px; }
.tag-chip i { margin-left: 4px; cursor: pointer; font-style: normal; color: #f56c6c; }
.form-grid-btns { display: flex; flex-wrap: wrap; gap: 6px; }
.field-add { display: flex; gap: 6px; align-items: center; }
.mini-input { width: 160px; }
.mini-select { width: 110px; }
.mini-table { width: 600px; }
.area-prov { line-height: 30px; }
.area-city { margin-left: 16px; }
</style>
