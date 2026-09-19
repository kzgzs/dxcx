<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteFreight,
  getFreightList,
  saveFreight,
  setFreightStatus,
} from '../../api/tenantSet'
import { getStoreList } from '../../api/store'
import type {
  FreightFormRow,
  FreightItem,
  FreightMapConfig,
  FreightPriceRow,
  FreightTimeRow,
  StoreItem,
} from '../../api/types'

// 配送方式枚举（对照参考系统 pstype，新增 6 预约服务-同城自提）
const PSTYPE_TEXT: Record<number, string> = {
  0: '快递',
  1: '到店自提',
  2: '同城配送',
  3: '自动发货',
  5: '门店配送',
  6: '预约服务',
}
const PSTYPE_DEFAULT_NAME: Record<number, string> = {
  0: '普通快递',
  1: '到店自提',
  2: '同城配送',
  3: '自动发货',
  5: '门店配送',
  6: '预约服务',
}
const PSTYPE_OPTIONS = [0, 1, 2, 3, 5, 6]

// 自提类（无起订限制）：到店自提/自动发货/预约服务
const PICKUP_TYPES = [1, 3, 6]

// 附加表单字段类型
const FORM_KEY_OPTIONS = [
  { value: 'input', label: '单行输入' },
  { value: 'textarea', label: '多行输入' },
  { value: 'radio', label: '单项选择' },
  { value: 'checkbox', label: '多项选择' },
  { value: 'selector', label: '普通选择' },
  { value: 'time', label: '时间选择' },
  { value: 'date', label: '日期选择' },
  { value: 'upload', label: '上传图片' },
]
// 时段选项
const DAY_OPTIONS = Array.from({ length: 15 }, (_, i) => ({
  value: i + 1,
  label: i === 0 ? '当天' : `第${i + 1}天`,
}))
const HOUR_OPTIONS = Array.from({ length: 24 }, (_, i) => i)
const MINUTE_OPTIONS = Array.from({ length: 60 }, (_, i) => i)

// ============ 视图切换 ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<FreightItem[]>([])

// 地图配置（后端从系统设置下发，对照参考系统用高德）
const mapConfig = ref<FreightMapConfig>({ map_type: '', map_key: '', map_jscode: '', map_qq_key: '' })
const hasMapKey = computed(() => mapConfig.value.map_type === 'amap' && mapConfig.value.map_key !== '')

async function fetchList() {
  loading.value = true
  try {
    const data = await getFreightList()
    tableData.value = data.list || []
    if (data.map_config) {
      mapConfig.value = data.map_config
    }
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

// ============ 配送范围地图（高德 JS API，对照参考系统 edit.html） ============
const mapContainerRef = ref<HTMLDivElement>()
const mapLoading = ref(false)
const mapError = ref('')
const mapActive = computed(() => viewMode.value === 'edit' && form.pstype === 2)

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let amapPromise: Promise<any> | null = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function loadAMap(key: string, jscode: string): Promise<any> {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const w = window as any
  if (w.AMap) return Promise.resolve(w.AMap)
  if (!amapPromise) {
    amapPromise = new Promise((resolve, reject) => {
      if (jscode) {
        w._AMapSecurityConfig = { securityJsCode: jscode }
      }
      const script = document.createElement('script')
      script.src = `https://webapi.amap.com/maps?v=1.4.0&key=${encodeURIComponent(key)}&plugin=AMap.PolyEditor,AMap.CircleEditor,AMap.Geocoder`
      script.onload = () => {
        if (w.AMap) {
          resolve(w.AMap)
        } else {
          amapPromise = null
          reject(new Error('地图加载失败'))
        }
      }
      script.onerror = () => {
        amapPromise = null
        reject(new Error('地图加载失败'))
      }
      document.head.appendChild(script)
    })
  }
  return amapPromise
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let mapInst: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let circleOverlay: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let circleEditor: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let polygonOverlay: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let polyEditor: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let storeMarker: any = null

function defaultCenter(): [number, number] {
  const lng = parseFloat(form.peisong_lng)
  const lat = parseFloat(form.peisong_lat)
  if (!isNaN(lng) && !isNaN(lat) && lng !== 0 && lat !== 0) {
    return [lng, lat]
  }
  return [116.397428, 39.90923] // 未设置坐标时默认北京
}

function destroyMap() {
  if (mapInst) {
    try {
      mapInst.destroy()
    } catch {
      // 忽略销毁异常
    }
  }
  mapInst = null
  circleOverlay = null
  circleEditor = null
  polygonOverlay = null
  polyEditor = null
  storeMarker = null
}

async function initMap() {
  if (!hasMapKey.value || !mapActive.value) return
  mapLoading.value = true
  mapError.value = ''
  try {
    const AMap = await loadAMap(mapConfig.value.map_key, mapConfig.value.map_jscode)
    if (!mapActive.value) return
    await nextTick()
    if (!mapContainerRef.value) return
    destroyMap()
    const center = defaultCenter()
    // 对照参考系统：无坐标时回填地图中心、无半径默认 2000 米
    if (!form.peisong_lng || !form.peisong_lat) {
      form.peisong_lng = String(center[0])
      form.peisong_lat = String(center[1])
    }
    if (!form.peisong_range) {
      form.peisong_range = 2000
    }
    mapInst = new AMap.Map(mapContainerRef.value, {
      resizeEnable: true,
      dragEnable: true,
      keyboardEnable: false,
      doubleClickZoom: false,
      scrollWheel: true,
      center,
      zoom: 13,
    })
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    mapInst.on('click', (e: any) => {
      setStoreCoord(e.lnglat.getLng(), e.lnglat.getLat())
    })
    renderOverlay(false)
  } catch {
    mapError.value = '地图加载失败，请检查系统设置中的高德地图 key 是否正确'
  } finally {
    mapLoading.value = false
  }
}

// 设置商家坐标（地图点击/标记拖拽）
function setStoreCoord(lng: number, lat: number) {
  form.peisong_lng = String(lng)
  form.peisong_lat = String(lat)
  if (storeMarker) {
    storeMarker.setPosition([lng, lat])
  }
  if (form.peisong_rangetype === 0 && circleOverlay) {
    circleOverlay.setCenter([lng, lat])
  }
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function addStoreMarker(AMap: any, position: [number, number]) {
  storeMarker = new AMap.Marker({ position, draggable: true })
  storeMarker.on('dragend', () => {
    const p = storeMarker.getPosition()
    form.peisong_lng = String(p.lng)
    form.peisong_lat = String(p.lat)
    if (form.peisong_rangetype === 0 && circleOverlay) {
      circleOverlay.setCenter([p.lng, p.lat])
    }
  })
  mapInst.add(storeMarker)
}

// 按当前范围类型渲染覆盖物（对照参考系统 addcircle/addpoly）
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function renderOverlay(regenerate: boolean, AMapRef?: any) {
  if (!mapInst) return
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const w = window as any
  const AMap = AMapRef || w.AMap
  mapInst.clearMap()
  circleOverlay = null
  circleEditor = null
  polygonOverlay = null
  polyEditor = null
  storeMarker = null
  const lng = parseFloat(form.peisong_lng)
  const lat = parseFloat(form.peisong_lat)
  const center: [number, number] = !isNaN(lng) && !isNaN(lat) ? [lng, lat] : defaultCenter()
  addStoreMarker(AMap, center)
  if (form.peisong_rangetype === 0) {
    addCircle(AMap, center)
  } else {
    addPolygon(AMap, center, regenerate)
  }
}

// 圆形范围：Circle + CircleEditor（拖动圆心回填坐标，拖半径回填 range）
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function addCircle(AMap: any, center: [number, number]) {
  const range = form.peisong_range || 2000
  circleOverlay = new AMap.Circle({
    center,
    radius: range,
    strokeColor: '#4e73f1',
    strokeOpacity: 1,
    strokeWeight: 3,
    fillColor: '#4e73f1',
    fillOpacity: 0.35,
  })
  mapInst.add(circleOverlay)
  mapInst.setFitView([circleOverlay])
  circleEditor = new AMap.CircleEditor(mapInst, circleOverlay)
  circleEditor.open()
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  circleEditor.on('move', (event: any) => {
    form.peisong_lng = String(event.lnglat.lng)
    form.peisong_lat = String(event.lnglat.lat)
    if (storeMarker) {
      storeMarker.setPosition([event.lnglat.lng, event.lnglat.lat])
    }
  })
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  circleEditor.on('adjust', (event: any) => {
    if (event.radius) {
      form.peisong_range = Math.round(event.radius)
    }
  })
}

// 多边形范围：Polygon + PolyEditor（逐点点击/拖动顶点，回填 rangepath）
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function addPolygon(AMap: any, center: [number, number], forceNew: boolean) {
  let path: [number, number][] = []
  if (!forceNew && form.peisong_rangepath.trim() !== '') {
    form.peisong_rangepath.split(';').forEach((seg) => {
      const p = seg.split(',')
      const a = parseFloat(p[0])
      const b = parseFloat(p[1])
      if (!isNaN(a) && !isNaN(b)) {
        path.push([a, b])
      }
    })
  }
  if (path.length < 3) {
    // 对照参考系统：无路径时以中心点生成 ±0.02 默认四边形
    const k = 0.02
    path = [
      [center[0] - k, center[1] - k],
      [center[0] + k, center[1] - k],
      [center[0] + k, center[1] + k],
      [center[0] - k, center[1] + k],
    ]
  }
  polygonOverlay = new AMap.Polygon({
    path,
    strokeColor: '#FF33FF',
    strokeOpacity: 0.2,
    fillOpacity: 0.4,
    fillColor: '#1791fc',
    zIndex: 50,
    draggable: true,
  })
  mapInst.add(polygonOverlay)
  mapInst.setFitView([polygonOverlay])
  polyEditor = new AMap.PolyEditor(mapInst, polygonOverlay)
  polyEditor.open()
  syncRangePath()
  ;['addnode', 'adjust', 'removenode', 'end'].forEach((ev) => {
    polyEditor.on(ev, syncRangePath)
  })
  polygonOverlay.on('change', syncRangePath)
}

function syncRangePath() {
  if (!polygonOverlay) return
  const pts = polygonOverlay.getPath()
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  form.peisong_rangepath = pts.map((p: any) => `${p.lng},${p.lat}`).join(';')
}

// 范围类型切换（对照参考系统：切多边形时以当前坐标重新生成默认四边形）
function onRangeTypeChange() {
  if (!mapInst) return
  renderOverlay(form.peisong_rangetype === 1)
}

async function handleStatus(row: FreightItem, status: number) {
  try {
    await setFreightStatus(row.id, status)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

function handleDelete(row: FreightItem) {
  ElMessageBox.confirm(`确定删除配送方式「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteFreight(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示由 axios 拦截器统一处理
      }
    })
    .catch(() => {})
}

// 列表计费摘要
function priceSummary(row: FreightItem): string {
  if (row.pstype === 1 || row.pstype === 5 || row.pstype === 6) {
    return (row.storetype ?? 0) === 0 ? '门店：全部' : `门店：${(row.storeids || []).length} 家`
  }
  if (row.pstype === 2) {
    return `${row.peisong_juli1 || 0}公里内${row.peisong_fee1 || 0}元，每超${row.peisong_juli2 || 0}公里加${row.peisong_fee2 || 0}元`
  }
  if (row.pstype === 3) {
    return (row.pscontenttype ?? 0) === 1 ? '在线卡密' : '固定发货信息'
  }
  return `${(row.pricedata || []).length} 个地区分组`
}

// ============ 编辑表单 ============
const editSubmitting = ref(false)
const formRef = ref<FormInstance>()
const storeOptions = ref<StoreItem[]>([])

function emptyPriceRow(): FreightPriceRow {
  return { region: '', fristweight: 1000, fristprice: '0.00', secondweight: 1000, secondprice: '0.00' }
}
function emptyTimeRow(): FreightTimeRow {
  return { day: 1, hour: 9, minute: 0, hour2: 18, minute2: 0 }
}
function emptyFormRow(): FreightFormRow {
  return { key: 'input', val1: '', val2: '', val3: 0 }
}

const form = reactive({
  id: 0,
  name: '',
  pstype: 0,
  type: 1,
  pricedata: [emptyPriceRow()] as FreightPriceRow[],
  freeset: 0,
  free_price: '0.00',
  minpriceset: 0,
  minprice: '0.00',
  minnumset: 0,
  minnum: 0,
  status: 1,
  sort: 0,
  storetype: 0,
  storeids: [] as number[],
  pscontenttype: 0,
  pscontent: '',
  needlinkinfo: 1,
  fwprice: '0.00',
  pstimeset: 0,
  pstimedata: [emptyTimeRow()] as FreightTimeRow[],
  psprehour: 4,
  peisong_juli1: '5.0',
  peisong_fee1: '3.00',
  peisong_juli2: '1.0',
  peisong_fee2: '1.00',
  peisong_lng: '',
  peisong_lat: '',
  peisong_range: 0,
  peisong_rangetype: 0,
  peisong_rangepath: '',
  peisong_lng2: '',
  peisong_lat2: '',
  formdata: [] as FreightFormRow[],
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
}

// pstype 联动显示
const showPricing = computed(() => form.pstype === 0)
const showStore = computed(() => [1, 5, 6].includes(form.pstype))
const showDistance = computed(() => form.pstype === 2)
const showAutoContent = computed(() => form.pstype === 3)
const showLimit = computed(() => !PICKUP_TYPES.includes(form.pstype))
const showFwprice = computed(() => [1, 5, 6].includes(form.pstype))
const showLinkinfo = computed(() => [1, 3, 5, 6].includes(form.pstype))
const timeLabel = computed(() => (form.pstype === 1 || form.pstype === 6 ? '提货时间' : '配送时间'))
const storeLabel = computed(() => (form.pstype === 5 ? '配送门店' : '自提门店'))

// 半径输入实时联动圆形（依赖 form，须定义于 form 声明之后）
watch(
  () => form.peisong_range,
  (v) => {
    if (circleOverlay) {
      circleOverlay.setRadius(v || 0)
    }
  },
)

// 进入/退出同城配送编辑视图时初始化/销毁地图（key 后到位时也触发初始化）
watch(
  [() => viewMode.value, () => form.pstype, hasMapKey],
  ([v, p, k], [ov, op, ok]) => {
    const active = v === 'edit' && p === 2 && k
    const wasActive = ov === 'edit' && op === 2 && ok
    if (active && !wasActive) {
      initMap()
    } else if (!active && wasActive) {
      destroyMap()
    }
  },
)

onBeforeUnmount(() => {
  destroyMap()
})

async function fetchStoreOptions() {
  try {
    const data = await getStoreList({ page: 1, pagesize: 100 })
    storeOptions.value = data.list || []
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    name: '',
    pstype: 0,
    type: 1,
    pricedata: [emptyPriceRow()],
    freeset: 0,
    free_price: '0.00',
    minpriceset: 0,
    minprice: '0.00',
    minnumset: 0,
    minnum: 0,
    status: 1,
    sort: 0,
    storetype: 0,
    storeids: [],
    pscontenttype: 0,
    pscontent: '',
    needlinkinfo: 1,
    fwprice: '0.00',
    pstimeset: 0,
    pstimedata: [emptyTimeRow()],
    psprehour: 4,
    peisong_juli1: '5.0',
    peisong_fee1: '3.00',
    peisong_juli2: '1.0',
    peisong_fee2: '1.00',
    peisong_lng: '',
    peisong_lat: '',
    peisong_range: 0,
    peisong_rangetype: 0,
    peisong_rangepath: '',
    peisong_lng2: '',
    peisong_lat2: '',
    formdata: [],
  })
}

function openAdd() {
  resetForm()
  viewMode.value = 'edit'
  fetchStoreOptions()
}

function openEdit(row: FreightItem) {
  resetForm()
  Object.assign(form, {
    id: row.id,
    name: row.name,
    pstype: row.pstype,
    type: row.type,
    pricedata:
      row.pricedata && row.pricedata.length > 0
        ? row.pricedata.map((item) => ({ ...item }))
        : [emptyPriceRow()],
    freeset: row.freeset,
    free_price: row.free_price,
    minpriceset: row.minpriceset,
    minprice: row.minprice,
    minnumset: row.minnumset,
    minnum: row.minnum,
    status: row.status,
    sort: row.sort,
    storetype: row.storetype ?? 0,
    storeids: [...(row.storeids || [])],
    pscontenttype: row.pscontenttype ?? 0,
    pscontent: row.pscontent ?? '',
    needlinkinfo: row.needlinkinfo ?? 1,
    fwprice: row.fwprice ?? '0.00',
    pstimeset: row.pstimeset ?? 0,
    pstimedata:
      row.pstimedata && row.pstimedata.length > 0
        ? row.pstimedata.map((item) => ({ ...item }))
        : [emptyTimeRow()],
    psprehour: row.psprehour ?? 4,
    peisong_juli1: row.peisong_juli1 ?? '5.0',
    peisong_fee1: row.peisong_fee1 ?? '3.00',
    peisong_juli2: row.peisong_juli2 ?? '1.0',
    peisong_fee2: row.peisong_fee2 ?? '1.00',
    peisong_lng: row.peisong_lng ?? '',
    peisong_lat: row.peisong_lat ?? '',
    peisong_range: row.peisong_range ?? 0,
    peisong_rangetype: row.peisong_rangetype ?? 0,
    peisong_rangepath: row.peisong_rangepath ?? '',
    peisong_lng2: row.peisong_lng2 ?? '',
    peisong_lat2: row.peisong_lat2 ?? '',
    formdata: (row.formdata || []).map((item) => ({ ...item })),
  })
  viewMode.value = 'edit'
  fetchStoreOptions()
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

function cancelEdit() {
  viewMode.value = 'list'
}

// pstype 联动：自提类无起订限制；新增时同步默认名称
function onPstypeChange() {
  if (PICKUP_TYPES.includes(form.pstype)) {
    form.minpriceset = 0
    form.minnumset = 0
  }
  if (form.id === 0) {
    form.name = PSTYPE_DEFAULT_NAME[form.pstype] || ''
  }
}

function addPriceRow() {
  form.pricedata.push(emptyPriceRow())
}
function removePriceRow(index: number) {
  form.pricedata.splice(index, 1)
}
function addTimeRow() {
  form.pstimedata.push(emptyTimeRow())
}
function removeTimeRow(index: number) {
  form.pstimedata.splice(index, 1)
}
function addFormRow() {
  form.formdata.push(emptyFormRow())
}
function removeFormRow(index: number) {
  form.formdata.splice(index, 1)
}

function handleSubmit() {
  if (!formRef.value) return
  formRef.value.validate(async (valid) => {
    if (!valid) return
    // pstype 专属校验
    if (form.pstype === 0) {
      const rows = form.pricedata.filter((r) => r.region.trim() !== '')
      if (rows.length === 0) {
        ElMessage.warning('请至少添加一条计费地区')
        return
      }
    }
    if (form.pstype === 1 || form.pstype === 5 || form.pstype === 6) {
      if (form.storetype === 1 && form.storeids.length === 0) {
        ElMessage.warning('请至少选择一家门店')
        return
      }
    }
    const payload = {
      ...form,
      pricedata: form.pricedata.filter((r) => r.region.trim() !== ''),
      pstimedata: form.pstimeset === 1 ? form.pstimedata : [],
      formdata: form.formdata.filter((r) => r.val1.trim() !== ''),
    }
    editSubmitting.value = true
    try {
      await saveFreight(payload)
      ElMessage.success('保存成功')
      backToList()
    } catch {
      // 错误提示由 axios 拦截器统一处理
    } finally {
      editSubmitting.value = false
    }
  })
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- ============ 列表视图 ============ -->
    <el-card v-if="viewMode === 'list'" shadow="never">
      <template #header>
        <div class="card-header">
          <span class="card-title">配送方式</span>
          <el-button type="primary" @click="openAdd">新增配送方式</el-button>
        </div>
      </template>

      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="名称" min-width="130" />
        <el-table-column label="配送方式" width="110">
          <template #default="{ row }">{{ PSTYPE_TEXT[row.pstype] || '未知' }}</template>
        </el-table-column>
        <el-table-column label="计费规则" min-width="220">
          <template #default="{ row }">{{ priceSummary(row) }}</template>
        </el-table-column>
        <el-table-column label="配送时段" width="100">
          <template #default="{ row }">
            {{ row.pstimeset === 1 ? `已开启` : '关闭' }}
          </template>
        </el-table-column>
        <el-table-column label="附加表单" width="100">
          <template #default="{ row }">
            {{ (row.formdata || []).length }} 项
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'">
              {{ row.status === 1 ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button
              v-if="row.status === 1"
              link
              type="warning"
              @click="handleStatus(row, 0)"
            >停用</el-button>
            <el-button v-else link type="success" @click="handleStatus(row, 1)">启用</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- ============ 编辑视图（铺满主区域） ============ -->
    <el-card v-else shadow="never">
      <template #header>
        <div class="card-header">
          <span class="card-title">{{ form.id > 0 ? '编辑配送方式' : '新增配送方式' }}</span>
          <div>
            <el-button @click="cancelEdit">取消</el-button>
            <el-button type="primary" :loading="editSubmitting" @click="handleSubmit">保存</el-button>
          </div>
        </div>
      </template>

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="110px"
        label-position="right"
        class="compact-form"
      >
        <!-- 基本信息 -->
        <div class="section-title">基本信息</div>
        <el-form-item label="配送方式" prop="pstype">
          <el-radio-group v-model="form.pstype" @change="onPstypeChange">
            <el-radio v-for="pt in PSTYPE_OPTIONS" :key="pt" :value="pt">
              {{ PSTYPE_TEXT[pt] }}
            </el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="如：顺丰快递 / 到店自提" style="width: 260px" clearable />
        </el-form-item>

        <!-- 快递：计价方式+地区分组 -->
        <template v-if="showPricing">
          <div class="section-title">运费计价</div>
          <el-form-item label="计价方式" prop="type">
            <el-radio-group v-model="form.type">
              <el-radio :value="1">按重量（克）</el-radio>
              <el-radio :value="2">按件数</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="区域及价格">
            <div class="price-table">
              <div class="price-head">
                <span class="col-region">区域</span>
                <span class="col-num">首{{ form.type === 2 ? '件' : '重(克)' }}</span>
                <span class="col-num">首价(元)</span>
                <span class="col-num">续{{ form.type === 2 ? '件' : '重(克)' }}</span>
                <span class="col-num">续价(元)</span>
                <span class="col-op">操作</span>
              </div>
              <div v-for="(row, index) in form.pricedata" :key="index" class="price-row">
                <el-input v-model="row.region" placeholder="如：江浙沪 / 全国(默认运费)" class="col-region" clearable />
                <el-input-number
                  v-model="row.fristweight"
                  :min="0"
                  :step="form.type === 2 ? 1 : 100"
                  controls-position="right"
                  class="col-num"
                />
                <el-input v-model="row.fristprice" placeholder="0.00" class="col-num" clearable />
                <el-input-number
                  v-model="row.secondweight"
                  :min="0"
                  :step="form.type === 2 ? 1 : 100"
                  controls-position="right"
                  class="col-num"
                />
                <el-input v-model="row.secondprice" placeholder="0.00" class="col-num" clearable />
                <el-button
                  link
                  type="danger"
                  class="col-op"
                  :disabled="form.pricedata.length <= 1"
                  @click="removePriceRow(index)"
                >删除</el-button>
              </div>
              <el-button type="primary" plain class="add-row" @click="addPriceRow">+ 添加区域</el-button>
            </div>
          </el-form-item>
        </template>

        <!-- 自提/门店：门店选择 -->
        <template v-if="showStore">
          <div class="section-title">{{ storeLabel }}</div>
          <el-form-item :label="storeLabel">
            <el-radio-group v-model="form.storetype">
              <el-radio :value="0">全部</el-radio>
              <el-radio :value="1">指定门店</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="form.storetype === 1" label="选择门店">
            <el-checkbox-group v-model="form.storeids">
              <el-checkbox v-for="store in storeOptions" :key="store.id" :value="store.id">
                {{ store.name }}
              </el-checkbox>
            </el-checkbox-group>
            <span v-if="storeOptions.length === 0" class="form-tip">暂无门店，请先在门店管理中添加</span>
          </el-form-item>
          <el-form-item v-if="showFwprice" label="服务费">
            <el-input v-model="form.fwprice" placeholder="0.00" style="width: 140px" clearable />
            <span class="inline-label">元</span>
          </el-form-item>
        </template>

        <!-- 同城配送：距离计费+坐标+范围 -->
        <template v-if="showDistance">
          <div class="section-title">同城配送计费与范围</div>
          <el-form-item label="配送费">
            <el-input v-model="form.peisong_juli1" style="width: 90px" clearable />
            <span class="inline-label">公里内</span>
            <el-input v-model="form.peisong_fee1" style="width: 90px" clearable />
            <span class="inline-label">元；每超出</span>
            <el-input v-model="form.peisong_juli2" style="width: 90px" clearable />
            <span class="inline-label">公里，加</span>
            <el-input v-model="form.peisong_fee2" style="width: 90px" clearable />
            <span class="inline-label">元</span>
          </el-form-item>
          <el-form-item label="商家坐标">
            <el-input v-model="form.peisong_lng" placeholder="经度" style="width: 150px" clearable />
            <span class="inline-label">,</span>
            <el-input v-model="form.peisong_lat" placeholder="纬度" style="width: 150px" clearable />
            <span class="form-tip">配送范围中心点坐标，可在下方地图点击设置</span>
          </el-form-item>
          <el-form-item label="配送范围">
            <el-radio-group v-model="form.peisong_rangetype" @change="onRangeTypeChange">
              <el-radio :value="0">圆形范围</el-radio>
              <el-radio :value="1">多边形范围</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="范围地图">
            <div class="map-wrap">
              <template v-if="hasMapKey">
                <div ref="mapContainerRef" v-loading="mapLoading" class="map-container"></div>
                <div v-if="mapError" class="map-error">{{ mapError }}</div>
                <div v-else class="map-hint">
                  点击地图设置商家坐标；圆形范围可拖动圆心移动、拖动边缘调半径；多边形范围点击地图加点、拖动顶点调整
                </div>
              </template>
              <el-alert
                v-else
                title="请先在系统设置中配置地图key（高德地图 key）后，再编辑配送范围地图"
                type="warning"
                show-icon
                :closable="false"
              />
            </div>
          </el-form-item>
          <el-form-item v-if="form.peisong_rangetype === 0" label="中心点坐标">
            <el-input v-model="form.peisong_lng" placeholder="经度" style="width: 130px" clearable />
            <span class="inline-label">,</span>
            <el-input v-model="form.peisong_lat" placeholder="纬度" style="width: 130px" clearable />
            <span class="inline-label">配送半径</span>
            <el-input-number v-model="form.peisong_range" :min="0" :step="100" controls-position="right" style="width: 140px" />
            <span class="inline-label">米</span>
          </el-form-item>
          <template v-else>
            <el-form-item label="范围路径">
              <el-input
                v-model="form.peisong_rangepath"
                type="textarea"
                :rows="2"
                readonly
                placeholder="在地图上绘制多边形后自动回填，格式：lng1,lat1;lng2,lat2;..."
                style="width: 520px"
              />
              <div class="form-tip">注：多边形的形状尽量简洁，不要有交叉线</div>
            </el-form-item>
            <el-form-item label="配送点坐标">
              <el-input v-model="form.peisong_lng2" placeholder="经度" style="width: 150px" clearable />
              <span class="inline-label">-</span>
              <el-input v-model="form.peisong_lat2" placeholder="纬度" style="width: 150px" clearable />
            </el-form-item>
          </template>
        </template>

        <!-- 自动发货 -->
        <template v-if="showAutoContent">
          <div class="section-title">发货信息</div>
          <el-form-item label="发货信息">
            <el-radio-group v-model="form.pscontenttype">
              <el-radio :value="0">固定信息</el-radio>
              <el-radio :value="1">不固定信息（在线卡密）</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="form.pscontenttype === 0" label="发货内容">
            <el-input
              v-model="form.pscontent"
              type="textarea"
              :rows="3"
              placeholder="请输入发货信息"
              style="width: 520px"
            />
          </el-form-item>
          <el-form-item v-else label="卡密说明">
            <span class="form-tip">请稍后在配送列表中上传卡密信息；用户购买几件将发送几个卡密信息</span>
          </el-form-item>
        </template>

        <!-- 运费限制（自提类隐藏） -->
        <template v-if="showLimit">
          <div class="section-title">运费与起送限制</div>
          <el-form-item label="满额包邮">
            <el-switch v-model="form.freeset" :active-value="1" :inactive-value="0" />
            <template v-if="form.freeset === 1">
              <span class="inline-label">满</span>
              <el-input v-model="form.free_price" placeholder="0.00" style="width: 120px" clearable />
              <span class="inline-label">元包邮</span>
            </template>
          </el-form-item>
          <el-form-item label="满额起送">
            <el-switch v-model="form.minpriceset" :active-value="1" :inactive-value="0" />
            <template v-if="form.minpriceset === 1">
              <span class="inline-label">满</span>
              <el-input v-model="form.minprice" placeholder="0.00" style="width: 120px" clearable />
              <span class="inline-label">元起送</span>
            </template>
          </el-form-item>
          <el-form-item label="满件起送">
            <el-switch v-model="form.minnumset" :active-value="1" :inactive-value="0" />
            <template v-if="form.minnumset === 1">
              <span class="inline-label">满</span>
              <el-input-number v-model="form.minnum" :min="0" :step="1" controls-position="right" style="width: 120px" />
              <span class="inline-label">件起送</span>
            </template>
          </el-form-item>
        </template>

        <!-- 联系方式（自提/自动发货类） -->
        <el-form-item v-if="showLinkinfo" label="联系方式">
          <el-radio-group v-model="form.needlinkinfo">
            <el-radio :value="1">必填</el-radio>
            <el-radio :value="0">选填</el-radio>
          </el-radio-group>
          <span class="form-tip">提交订单时，联系人和联系电话是否必填</span>
        </el-form-item>

        <!-- 配送/提货时段 -->
        <div class="section-title">{{ timeLabel }}</div>
        <el-form-item :label="timeLabel">
          <el-radio-group v-model="form.pstimeset">
            <el-radio :value="0">关闭</el-radio>
            <el-radio :value="1">开启</el-radio>
          </el-radio-group>
          <span class="form-tip">开启后用户下单时可以选择{{ timeLabel }}</span>
        </el-form-item>
        <template v-if="form.pstimeset === 1">
          <el-form-item label="可选时段">
            <div class="time-table">
              <div v-for="(row, index) in form.pstimedata" :key="index" class="time-row">
                <el-select v-model="row.day" style="width: 100px">
                  <el-option v-for="d in DAY_OPTIONS" :key="d.value" :label="d.label" :value="d.value" />
                </el-select>
                <el-select v-model="row.hour" style="width: 80px">
                  <el-option v-for="h in HOUR_OPTIONS" :key="h" :label="`${h}点`" :value="h" />
                </el-select>
                <el-select v-model="row.minute" style="width: 80px">
                  <el-option v-for="m in MINUTE_OPTIONS" :key="m" :label="`${m}分`" :value="m" />
                </el-select>
                <span class="inline-label">到</span>
                <el-select v-model="row.hour2" style="width: 80px">
                  <el-option v-for="h in HOUR_OPTIONS" :key="h" :label="`${h}点`" :value="h" />
                </el-select>
                <el-select v-model="row.minute2" style="width: 80px">
                  <el-option v-for="m in MINUTE_OPTIONS" :key="m" :label="`${m}分`" :value="m" />
                </el-select>
                <el-button link type="primary" @click="addTimeRow">+</el-button>
                <el-button
                  link
                  type="danger"
                  :disabled="form.pstimedata.length <= 1"
                  @click="removeTimeRow(index)"
                >-</el-button>
              </div>
            </div>
          </el-form-item>
          <el-form-item label="可选条件">
            <span class="inline-label-nomargin">下单时间早于可选时间</span>
            <el-input-number v-model="form.psprehour" :min="0" :step="1" controls-position="right" style="width: 110px" />
            <span class="inline-label">小时以上</span>
          </el-form-item>
        </template>

        <!-- 下单附加表单 -->
        <div class="section-title">下单附加表单</div>
        <el-form-item label="附加表单项">
          <div class="form-table">
            <div v-for="(row, index) in form.formdata" :key="index" class="form-row">
              <el-select v-model="row.key" style="width: 120px">
                <el-option v-for="o in FORM_KEY_OPTIONS" :key="o.value" :label="o.label" :value="o.value" />
              </el-select>
              <el-input v-model="row.val1" placeholder="字段名称" style="width: 160px" clearable />
              <el-input
                v-model="row.val2"
                :placeholder="['radio', 'checkbox', 'selector'].includes(row.key) ? '选项，用|分隔' : '占位提示'"
                style="width: 240px"
                clearable
              />
              <el-select v-model="row.val3" style="width: 90px">
                <el-option label="选填" :value="0" />
                <el-option label="必填" :value="1" />
              </el-select>
              <el-button link type="danger" @click="removeFormRow(index)">删除</el-button>
            </div>
            <div class="add-btns">
              <el-button type="primary" plain size="small" @click="addFormRow">+ 添加表单项</el-button>
            </div>
          </div>
        </el-form-item>

        <!-- 排序与状态 -->
        <div class="section-title">其他</div>
        <el-form-item label="序号" prop="sort">
          <el-input-number v-model="form.sort" :min="0" :step="1" controls-position="right" />
          <span class="form-tip">用于排序，越大越靠前</span>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label=" ">
          <el-button @click="cancelEdit">取消</el-button>
          <el-button type="primary" :loading="editSubmitting" @click="handleSubmit">保存</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
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

.inline-label {
  margin: 0 8px;
  color: #606266;
}

.inline-label-nomargin {
  margin-right: 8px;
  color: #606266;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.section-title {
  margin: 6px 0 10px;
  padding-left: 8px;
  border-left: 3px solid var(--el-color-primary);
  color: #303133;
  font-weight: 600;
  font-size: 14px;
  line-height: 16px;
}

/* 紧凑表单：行距压缩 */
.compact-form :deep(.el-form-item) {
  margin-bottom: 10px;
}

.compact-form :deep(.el-form-item__label) {
  line-height: 32px;
}

/* 计费规则表格 */
.price-table {
  width: 100%;
}

.price-head,
.price-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.price-head {
  padding: 2px 0 6px;
  color: #909399;
  font-size: 12px;
}

.price-row {
  margin-bottom: 6px;
}

.col-region {
  width: 200px;
  flex: none;
}

.col-num {
  width: 105px;
  flex: none;
}

.col-op {
  width: 50px;
  flex: none;
  text-align: center;
}

.add-row {
  margin-top: 2px;
}

/* 时段行 */
.time-table {
  width: 100%;
}

.time-row {
  display: flex;
  align-items: center;
  margin-bottom: 6px;
}

/* 附加表单行 */
.form-table {
  width: 100%;
}

.form-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}

.add-btns {
  margin-top: 2px;
}

/* 配送范围地图 */
.map-wrap {
  width: 100%;
  max-width: 800px;
}

.map-container {
  width: 100%;
  height: 400px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 4px;
}

.map-hint {
  margin-top: 4px;
  color: #909399;
  font-size: 12px;
}

.map-error {
  margin-top: 4px;
  color: var(--el-color-danger);
  font-size: 12px;
}
</style>
