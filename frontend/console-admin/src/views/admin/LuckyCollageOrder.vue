<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules, type UploadFile } from 'element-plus'
import {
  addLuckyCollageEwaybill,
  batchShipLuckyCollageByExcel,
  checkLuckyCollageOrderRefund,
  closeLuckyCollageOrder,
  collectLuckyCollageOrder,
  deleteLuckyCollageOrder,
  exportLuckyCollageOrders,
  getLuckyCollageEwaybillPrepare,
  getLuckyCollageExpress,
  getLuckyCollageExpressOptions,
  getLuckyCollageOrderList,
  getLuckyCollageShd,
  hexiaoLuckyCollageOrder,
  payLuckyCollageOrder,
  sendLuckyCollageOrderExpress,
} from '../../api/luckyCollage'
import { formatDate, formatTime } from '../../utils/time'
import { areaProvinces } from '../../utils/areaData'

const props = defineProps<{
  embedded?: boolean
  teamid?: number
}>()

const emit = defineEmits<{ (e: 'close'): void }>()

const route = useRoute()

// ============ 视图状态：list 列表 / shd 送货单 ============
const viewMode = ref<'list' | 'shd'>('list')

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<Record<string, any>[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const fixedTeamId = props.embedded ? Number(props.teamid || 0) : Number(route.query.teamid || 0)

const query = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  linkman: '',
  tel: '',
  ctime: [] as string[],
  status: '',
})

function buildParams(): Record<string, any> {
  const params: Record<string, any> = {
    page: query.page,
    pagesize: query.pagesize,
    ordernum: query.ordernum || undefined,
    linkman: query.linkman || undefined,
    tel: query.tel || undefined,
    status: query.status || undefined,
  }
  if (fixedTeamId > 0) params.teamid = fixedTeamId
  if (query.ctime && query.ctime.length === 2) {
    params.ctime_start = query.ctime[0]
    params.ctime_end = query.ctime[1]
  }
  return params
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getLuckyCollageOrderList(buildParams())
    tableData.value = (data as any).list || []
    total.value = (data as any).total || 0
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: Record<string, any>[]) {
  selectedIds.value = rows.map((r) => r.id)
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

// ============ 删除（单个 / 批量） ============
function handleDelete(row: Record<string, any>) {
  ElMessageBox.confirm(`确定要删除订单「${row.ordernum}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLuckyCollageOrder({ id: row.id })
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选要删除的订单')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${selectedIds.value.length} 个订单吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLuckyCollageOrder({ ids: selectedIds.value })
        ElMessage.success('删除成功')
        selectedIds.value = []
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

// ============ 导出 ============
async function handleExport() {
  try {
    ElMessage.info('正在生成订单Excel…')
    const blob = await exportLuckyCollageOrders(buildParams())
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `幸运拼团订单_${Date.now()}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch (e) {
    ElMessage.error((e as Error)?.message || '导出失败')
  }
}

// ============ 关闭订单 / 改为已支付 ============
function handleClose(row: Record<string, any>) {
  ElMessageBox.confirm(`确定要关闭订单「${row.ordernum}」吗？`, '关闭订单', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await closeLuckyCollageOrder(row.id)
        ElMessage.success('订单已关闭')
        fetchList()
      } catch {
        /* ignore */
      }
    })
    .catch(() => {})
}

function handleIspay(row: Record<string, any>) {
  ElMessageBox.confirm(`确定将订单「${row.ordernum}」改为已支付吗？`, '提示', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await payLuckyCollageOrder(row.id)
        ElMessage.success('操作成功')
        fetchList()
      } catch {
        /* ignore */
      }
    })
    .catch(() => {})
}

// ============ 核销 ============
function handleHexiao(row: Record<string, any>) {
  ElMessageBox.confirm('确定要核销并改为已完成状态吗？', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await hexiaoLuckyCollageOrder(row.id)
        ElMessage.success('已完成')
        fetchList()
      } catch {
        /* ignore */
      }
    })
    .catch(() => {})
}

// ============ 确认收货 ============
function handleCollect(row: Record<string, any>) {
  ElMessageBox.confirm('确定要改为已完成状态吗？', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await collectLuckyCollageOrder(row.id)
        ElMessage.success('操作成功')
        fetchList()
      } catch {
        /* ignore */
      }
    })
    .catch(() => {})
}

// ============ 查物流 ============
const expressVisible = ref(false)
const expressLoading = ref(false)
const expressData = ref<Record<string, any> | null>(null)

async function openExpress(row: Record<string, any>) {
  expressVisible.value = true
  expressLoading.value = true
  expressData.value = null
  try {
    expressData.value = await getLuckyCollageExpress(row.id)
  } catch {
    /* ignore */
  } finally {
    expressLoading.value = false
  }
}

// ============ 发货方式选择弹窗 ============
const shipChooseVisible = ref(false)
const shipRow = ref<Record<string, any> | null>(null)

function openShip(row: Record<string, any>) {
  shipRow.value = row
  shipChooseVisible.value = true
}

// ============ 手动发货弹窗 ============
const manualVisible = ref(false)
const manualFormRef = ref<FormInstance>()
const manualSubmitting = ref(false)
const expressList = ref<{ name: string; code: string }[]>([])
const manualForm = reactive({ express_com: '', express_no: '' })
const manualRules: FormRules = {
  express_com: [{ required: true, message: '请选择快递公司', trigger: 'change' }],
  express_no: [{ required: true, message: '请输入快递单号', trigger: 'blur' }],
}

async function openManual() {
  shipChooseVisible.value = false
  manualForm.express_com = shipRow.value?.express_com || ''
  manualForm.express_no = shipRow.value?.express_no || ''
  if (!expressList.value.length) {
    try {
      const res = await getLuckyCollageExpressOptions()
      expressList.value = res.list || []
    } catch {
      /* ignore */
    }
  }
  manualVisible.value = true
}

async function submitManual() {
  if (!manualFormRef.value || !shipRow.value) return
  await manualFormRef.value.validate(async (valid) => {
    if (!valid) return
    manualSubmitting.value = true
    try {
      await sendLuckyCollageOrderExpress(shipRow.value!.id, manualForm.express_com, manualForm.express_no)
      ElMessage.success('发货成功')
      manualVisible.value = false
      fetchList()
    } catch {
      /* 拦截器统一提示 */
    } finally {
      manualSubmitting.value = false
    }
  })
}

// ============ 电子面单弹窗 ============
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
function onEwbProvince(p: EwbParty) {
  p.city = ''
  p.area = ''
}
function onEwbCity(p: EwbParty) {
  p.area = ''
}
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
  const parts = text.split(/[\s,，/、]+/).filter(Boolean)
  for (const prov of areaProvinces) {
    const hitP = parts.some((t) => t === prov.name || ewbNorm(t) === ewbNorm(prov.name))
    if (!hitP) continue
    p.province = prov.name
    for (const c of prov.cityList) {
      const hitC = parts.some((t) => t === c.name || ewbNorm(t) === ewbNorm(c.name))
      if (!hitC) continue
      p.city = c.name
      for (const a of c.areaList) {
        if (parts.some((t) => t === a || ewbNorm(t) === ewbNorm(a))) {
          p.area = a
          break
        }
      }
      break
    }
    break
  }
}

async function openEwb() {
  if (!shipRow.value) return
  shipChooseVisible.value = false
  ewbVisible.value = true
  ewbLoading.value = true
  Object.assign(ewbForm, {
    ordercode: '',
    ordernum: shipRow.value.ordernum || '',
    delivery_info: '',
    is_notice: 0,
    quantity: shipRow.value.num || 1,
    weight: 1,
    volume: 0.001,
    insure: 0,
    insure_value: '',
    warm_layer: '',
    remark: `${shipRow.value.ggname || shipRow.value.proname} x${shipRow.value.num}`,
    expect_start_time: '',
    expect_end_time: '',
    receiver: emptyParty(),
    sender: emptyParty(),
  })
  fillPartyFromRow(ewbForm.receiver, shipRow.value)
  try {
    const prep = await getLuckyCollageEwaybillPrepare(shipRow.value.id)
    ewbForm.ordercode = (prep.ordercode as string) || ''
    ewbExpressOptions.value = (prep.express_options as any[]) || []
    ewbWarmLayer.value = (prep.warm_layer as Record<string, string>) || {}
    const last = (prep.last_order as any) || {}
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

async function submitEwb() {
  if (!shipRow.value) return
  if (!ewbForm.delivery_info) {
    ElMessage.warning('请选择快递公司')
    return
  }
  const rc = ewbForm.receiver as EwbParty
  const sd = ewbForm.sender as EwbParty
  ewbSubmitting.value = true
  try {
    const payload: Record<string, any> = {
      ordertype: 'lucky_collage',
      orderid: shipRow.value.id,
      ordercode: ewbForm.ordercode,
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
    }
    await addLuckyCollageEwaybill(payload)
    ElMessage.success('电子面单发货成功')
    ewbVisible.value = false
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  } finally {
    ewbSubmitting.value = false
  }
}

// ============ 退款审核弹窗 ============
const refundCheckVisible = ref(false)
const refundCheckRow = ref<Record<string, any> | null>(null)

function openRefundCheck(row: Record<string, any>) {
  refundCheckRow.value = row
  refundCheckVisible.value = true
}

async function submitRefundCheck(pass: number) {
  if (!refundCheckRow.value) return
  try {
    await checkLuckyCollageOrderRefund(refundCheckRow.value.id, pass)
    ElMessage.success(1 === pass ? '已同意并退款' : '已驳回')
    refundCheckVisible.value = false
    fetchList()
  } catch {
    /* ignore */
  }
}

// ============ 批量发货弹窗（Excel） ============
const batchVisible = ref(false)
const batchSubmitting = ref(false)
const batchForm = reactive({ express_com: '' })
const batchFile = ref<File | null>(null)
const batchExpressList = ref<{ name: string; code: string }[]>([])

function openBatch() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选要发货的订单')
    return
  }
  batchForm.express_com = ''
  batchFile.value = null
  batchVisible.value = true
}

function onBatchChange(file: UploadFile) {
  batchFile.value = file.raw || null
}

function downloadBatchFormat() {
  // 两列模板：订单号、快递单号（.xls 为 HTML 表格，Excel 可直接打开）
  const html = '<table border="1"><tr><td>订单号</td><td>快递单号</td></tr><tr><td></td><td></td></tr></table>'
  const blob = new Blob(['﻿' + html], { type: 'application/vnd.ms-excel' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'demo_plfh.xls'
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

async function submitBatch() {
  if (!batchForm.express_com) {
    ElMessage.warning('请选择快递公司')
    return
  }
  if (!batchFile.value) {
    ElMessage.warning('请上传发货表格（第一列订单号、第二列快递单号）')
    return
  }
  batchSubmitting.value = true
  try {
    const fd = new FormData()
    fd.append('express_com', batchForm.express_com)
    fd.append('file', batchFile.value)
    const res = await batchShipLuckyCollageByExcel(fd)
    ElMessage.success(`共导入 ${res.total} 条，成功 ${res.success} 条，失败 ${res.fail} 条`)
    batchVisible.value = false
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  } finally {
    batchSubmitting.value = false
  }
}

// ============ 查看详情 ============
const detailVisible = ref(false)
const detailRow = ref<Record<string, any> | null>(null)
function openDetail(row: Record<string, any>) {
  detailRow.value = row
  detailVisible.value = true
}

// ============ 送货单 ============
const shdLoading = ref(false)
const shdData = ref<Record<string, any> | null>(null)
const emptyRows = computed(() => {
  const pagenum = Number(shdData.value?.pagenum || 0)
  const goodsCount = shdData.value?.goods?.length || 0
  return pagenum > goodsCount ? Math.min(pagenum - goodsCount, 20) : 0
})

async function openShd(row: Record<string, any>) {
  viewMode.value = 'shd'
  shdLoading.value = true
  shdData.value = null
  try {
    shdData.value = await getLuckyCollageShd(row.id)
  } catch {
    /* ignore */
  } finally {
    shdLoading.value = false
  }
}

function shdPrint() {
  window.print()
}

onMounted(() => {
  if (!batchExpressList.value.length) {
    getLuckyCollageExpressOptions()
      .then((res) => (batchExpressList.value = res.list || []))
      .catch(() => {})
  }
  fetchList()
})
</script>

<template>
  <div class="lucky-order" :class="{ embedded: props.embedded }">
    <!-- 嵌入模式：顶部标题栏（带关闭X） -->
    <div v-if="props.embedded" class="panel-head">
      <span class="panel-title">订单管理</span>
      <button class="panel-close" @click="emit('close')">✕</button>
    </div>

    <!-- ============ 列表视图 ============ -->
    <template v-if="viewMode === 'list'">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button size="small" @click="handleExport">导出</el-button>
          <el-button size="small" type="danger" plain @click="handleBatchDelete">删除</el-button>
          <el-button size="small" type="primary" plain @click="openBatch">批量发货</el-button>
        </div>
        <div class="toolbar-search">
          <el-input v-model="query.ordernum" placeholder="订单号" size="small" style="width: 125px" @keyup.enter="handleSearch" />
          <el-input v-model="query.linkman" placeholder="收货人" size="small" style="width: 100px" @keyup.enter="handleSearch" />
          <el-input v-model="query.tel" placeholder="手机号" size="small" style="width: 110px" @keyup.enter="handleSearch" />
          <el-date-picker
            v-model="query.ctime"
            type="daterange"
            size="small"
            range-separator="~"
            start-placeholder="下单时间"
            end-placeholder="~"
            value-format="YYYY-MM-DD"
            style="width: 210px"
          />
          <el-select v-model="query.status" placeholder="状态" size="small" style="width: 105px">
            <el-option label="全部" value="" />
            <el-option label="未支付" value="0" />
            <el-option label="已支付" value="1" />
            <el-option label="已发货" value="2" />
            <el-option label="已收货" value="3" />
            <el-option label="已关闭" value="4" />
            <el-option label="退款审核中" value="r1" />
            <el-option label="已退款" value="r2" />
            <el-option label="退款已驳回" value="r3" />
          </el-select>
          <button class="search-btn" @click="handleSearch" title="搜索">
            <svg viewBox="0 0 1024 1024" width="14" height="14"><path fill="#fff" d="M795.904 750.72l124.992 124.928a32 32 0 1 1-45.269 45.269l-124.928-124.992a416 416 0 1 1 45.269-45.269zM480 832a352 352 0 1 0 0-704 352 352 0 0 0 0 704z" /></svg>
          </button>
        </div>
      </div>

      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="36" />
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="商品信息" min-width="170">
          <template #default="{ row }">
            <div class="goods-cell">
              <el-image v-if="row.propic" :src="row.propic" fit="cover" class="goods-thumb" />
              <div class="goods-meta">
                <div class="goods-name">{{ row.proname }}</div>
                <div class="goods-spec">{{ row.ggname || '默认规格' }}</div>
                <div class="goods-price">￥{{ Number(row.sell_price).toFixed(2) }} × {{ row.num }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="订单号/下单时间" width="150">
          <template #default="{ row }">
            <div class="ordernum">{{ row.ordernum }}</div>
            <div class="cell-sub">{{ formatTime(row.createtime) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="商品总价/实付款" width="110">
          <template #default="{ row }">
            <div class="cell-sub">总价：￥{{ Number(row.product_price ?? row.totalprice).toFixed(2) }}</div>
            <div class="cell-sub">实付：￥{{ Number(row.totalprice).toFixed(2) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="收货地址" min-width="160">
          <template #default="{ row }">
            <div>{{ row.linkman }} {{ row.tel }}</div>
            <div class="cell-sub">{{ row.address }}</div>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="90">
          <template #default="{ row }">{{ row.platform || '-' }}</template>
        </el-table-column>
        <el-table-column label="头像昵称" width="100">
          <template #default="{ row }">
            <div class="buyer-cell">
              <el-avatar :size="24" :src="row.buyer?.headimg || undefined">
                {{ (row.buyer?.nickname || '?').slice(0, 1) }}
              </el-avatar>
              <span class="buyer-name">{{ row.buyer?.nickname || '-' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="支付方式" width="90">
          <template #default="{ row }">{{ row.paytype || '-' }}</template>
        </el-table-column>
        <el-table-column label="配送方式" width="100">
          <template #default="{ row }">{{ row.freight_text || '普通快递' }}</template>
        </el-table-column>
        <el-table-column label="类型" width="70">
          <template #default="{ row }">{{ row.buytype_text }}</template>
        </el-table-column>
        <el-table-column label="状态" width="120">
          <template #default="{ row }">
            <div v-if="row.status === 1 || row.status === 2" class="green-text">{{ row.status_text }}</div>
            <div v-else-if="row.status === 4" class="red-text">{{ row.status_text }}</div>
            <div v-else class="cell-sub">{{ row.status_text }}</div>
            <div v-if="row.team" :class="row.team.status === 2 ? 'green-text' : 'cell-sub'">
              {{ row.team.status_text }}
            </div>
            <div v-if="row.iszj === 1" class="green-text">中奖</div>
            <div v-else-if="row.iszj === 2" class="cell-sub">未中奖</div>
            <div v-if="row.refund_status === 3" class="red-text">退款驳回</div>
            <div v-else-if="row.refund_status === 2 && row.refund_money > 0" class="red-text">
              已退款,￥{{ Number(row.refund_money).toFixed(2) }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="180">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" size="small" @click="openDetail(row)">查看详情</el-button>
              <el-button link type="primary" size="small" @click="openShd(row)">送货单</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除订单</el-button>
              <el-button v-if="row.refund_status === 1" link type="warning" size="small" @click="openRefundCheck(row)">退款审核</el-button>
              <el-button v-if="row.status === 0" link type="info" size="small" @click="handleClose(row)">关闭订单</el-button>
              <el-button v-if="row.status === 0" link type="primary" size="small" @click="handleIspay(row)">改为已支付</el-button>
              <el-button
                v-if="row.status === 1 && row.team && row.team.status === 2"
                link type="primary" size="small" @click="openShip(row)"
              >发货</el-button>
              <el-button
                v-if="row.status === 1 && row.freight_type === 1 && row.team && row.team.status === 2"
                link type="primary" size="small" @click="handleHexiao(row)"
              >核销</el-button>
              <template v-if="row.status === 2">
                <el-button
                  v-if="row.freight_type === 1"
                  link type="primary" size="small" @click="handleHexiao(row)"
                >核销</el-button>
                <el-button
                  link type="primary" size="small" @click="handleCollect(row)"
                >确认收货</el-button>
              </template>
              <el-button
                v-if="(row.status === 2 || row.status === 3) && row.freight_type === 2"
                link type="primary" size="small" @click="openExpress(row)"
              >查物流</el-button>
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
    </template>

    <!-- ============ 送货单视图 ============ -->
    <template v-else>
      <div class="shd-toolbar">
        <el-button size="small" @click="viewMode = 'list'">返回列表</el-button>
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
              <span class="shd-date">送货日期：{{ formatDate(shdData.createtime) }}</span>
            </div>
            <div class="shd-info-row">
              <span>客户地址：{{ shdData.address }}</span>
            </div>
            <div class="shd-table-wrap">
              <table class="shd-table" border="1">
                <thead>
                  <tr>
                    <th class="w40">序号</th>
                    <th>品名及规格</th>
                    <th class="w60">数量</th>
                    <th class="w70">单价</th>
                    <th class="w80">金额</th>
                    <th class="w180">备注</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(g, i) in shdData.goods" :key="i">
                    <td>{{ Number(i) + 1 }}</td>
                    <td class="shd-left">{{ g.name }}</td>
                    <td>{{ g.num }}</td>
                    <td>{{ Number(g.price).toFixed(2) }}</td>
                    <td>{{ Number(g.money).toFixed(2) }}</td>
                    <td></td>
                  </tr>
                  <tr v-for="n in emptyRows" :key="'e' + n">
                    <td>{{ shdData.goods.length + n }}</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                  </tr>
                  <tr>
                    <td colspan="2" class="shd-right">运费</td>
                    <td>1</td>
                    <td></td>
                    <td>{{ Number(shdData.freight_price || 0).toFixed(2) }}</td>
                    <td></td>
                  </tr>
                  <tr>
                    <td colspan="2" class="shd-right">合计金额(小写)</td>
                    <td colspan="4">{{ Number(shdData.totalprice).toFixed(2) }}</td>
                  </tr>
                  <tr>
                    <td colspan="2" class="shd-right">合计金额(大写)</td>
                    <td colspan="4">{{ shdData.total_upper }}</td>
                  </tr>
                  <tr>
                    <td colspan="6" class="shd-left">买家留言：{{ shdData.message || '' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="shd-foot">
              <span>制单人：</span>
              <span>送货人：</span>
              <span>收货人：</span>
            </div>
          </div>
        </template>
      </div>
    </template>

    <!-- 发货方式选择 -->
    <el-dialog v-model="shipChooseVisible" title="请选择发货方式" width="380px" :close-on-click-modal="false">
      <div class="ship-choose-tip">是否要使用电子面单发货？</div>
      <div class="ship-choose-btns">
        <el-button type="primary" @click="openEwb">使用电子面单发货</el-button>
        <el-button @click="openManual">手动发货</el-button>
      </div>
    </el-dialog>

    <!-- 手动发货 -->
    <el-dialog v-model="manualVisible" title="手动发货" width="380px">
      <el-form ref="manualFormRef" :model="manualForm" :rules="manualRules" label-width="80px">
        <el-form-item label="快递公司" prop="express_com">
          <el-select v-model="manualForm.express_com" placeholder="请选择快递公司" style="width: 100%">
            <el-option v-for="e in expressList" :key="e.name" :label="e.name" :value="e.name" />
          </el-select>
        </el-form-item>
        <el-form-item label="快递单号" prop="express_no">
          <el-input v-model="manualForm.express_no" placeholder="请输入快递单号" class="field-md" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="manualVisible = false">取消</el-button>
        <el-button type="primary" :loading="manualSubmitting" @click="submitManual">确定</el-button>
      </template>
    </el-dialog>

    <!-- 电子面单创建 -->
    <el-dialog
      v-model="ewbVisible"
      title="创建电子面单"
      width="720px"
      :close-on-click-modal="false"
      destroy-on-close
    >
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
            <el-date-picker
              v-model="ewbForm.expect_start_time"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="选择开始时间"
              style="width: 300px"
            />
            <span class="ewb-aux">不填表示已事先约定；跨越速运、京东快运必填</span>
          </div>
          <div class="ewb-row">
            <label>揽件结束时间：</label>
            <el-date-picker
              v-model="ewbForm.expect_end_time"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="选择结束时间"
              style="width: 300px"
            />
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
          <el-input-number
            v-model="ewbForm.volume"
            :min="0.001"
            :step="0.001"
            :precision="3"
            :controls="false"
            class="w90"
          />
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
          <el-input
            v-model="ewbForm.remark"
            type="textarea"
            :rows="6"
            maxlength="200"
            show-word-limit
            style="width: 530px"
          />
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
          <el-select
            v-model="ewbForm.receiver.province"
            placeholder="省"
            style="width: 160px"
            @change="onEwbProvince(ewbForm.receiver)"
          >
            <el-option v-for="p in areaProvinces" :key="p.name" :label="p.name" :value="p.name" />
          </el-select>
          <el-select
            v-model="ewbForm.receiver.city"
            placeholder="市"
            style="width: 150px"
            @change="onEwbCity(ewbForm.receiver)"
          >
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
        <div class="ewb-banner">
          发件人信息会自动获取上一单的发件人信息（仅需填写一次），如需要修改请手动输入。
        </div>
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
          <el-select
            v-model="ewbForm.sender.province"
            placeholder="省"
            style="width: 160px"
            @change="onEwbProvince(ewbForm.sender)"
          >
            <el-option v-for="p in areaProvinces" :key="p.name" :label="p.name" :value="p.name" />
          </el-select>
          <el-select
            v-model="ewbForm.sender.city"
            placeholder="市"
            style="width: 150px"
            @change="onEwbCity(ewbForm.sender)"
          >
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
          <el-button type="danger" :loading="ewbSubmitting" @click="submitEwb">提 交</el-button>
        </div>
      </div>
    </el-dialog>

    <!-- 退款审核 -->
    <el-dialog v-model="refundCheckVisible" title="退款审核" width="420px">
      <div v-if="refundCheckRow" class="refund-check-body">
        <div class="cell-sub">订单号：{{ refundCheckRow.ordernum }}</div>
        <div class="cell-sub">退款金额：￥{{ Number(refundCheckRow.refund_money || refundCheckRow.totalprice).toFixed(2) }}</div>
      </div>
      <template #footer>
        <el-button @click="refundCheckVisible = false">取消</el-button>
        <el-button type="danger" @click="submitRefundCheck(1)">同意退款</el-button>
        <el-button type="info" @click="submitRefundCheck(2)">驳回</el-button>
      </template>
    </el-dialog>

    <!-- 批量发货（Excel 上传） -->
    <el-dialog v-model="batchVisible" title="导入EXCEl文件" width="440px">
      <el-form label-width="90px">
        <el-form-item label="快递公司">
          <el-select v-model="batchForm.express_com" placeholder="请选择快递公司" style="width: 100%">
            <el-option v-for="e in batchExpressList" :key="e.name" :label="e.name" :value="e.name" />
          </el-select>
        </el-form-item>
        <el-form-item label="发货表格">
          <el-upload :auto-upload="false" :show-file-list="true" :limit="1" :on-change="onBatchChange" accept=".xlsx,.xls,.csv">
            <el-button size="small">选择文件</el-button>
          </el-upload>
          <div class="cell-sub">
            <el-button link type="primary" size="small" @click="downloadBatchFormat">点击下载查看导入格式</el-button>
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="batchVisible = false">取消</el-button>
        <el-button type="primary" :loading="batchSubmitting" @click="submitBatch">确定导入发货</el-button>
      </template>
    </el-dialog>

    <!-- 查物流 -->
    <el-dialog v-model="expressVisible" title="物流信息" width="520px">
      <div v-loading="expressLoading">
        <template v-if="expressData">
          <div class="express-head">
            {{ expressData.express_com }} {{ expressData.express_no }}
          </div>
          <el-timeline v-if="expressData.traces && expressData.traces.length">
            <el-timeline-item
              v-for="(t, i) in expressData.traces"
              :key="i"
              :timestamp="t.time"
              placement="top"
            >
              {{ t.station }}
            </el-timeline-item>
          </el-timeline>
          <el-empty v-else description="暂无物流轨迹" :image-size="60" />
        </template>
      </div>
      <template #footer>
        <el-button @click="expressVisible = false">关闭</el-button>
      </template>
    </el-dialog>

    <!-- 查看详情 -->
    <el-dialog v-model="detailVisible" title="订单详情" width="600px">
      <el-descriptions v-if="detailRow" :column="2" border size="small">
        <el-descriptions-item label="订单号">{{ detailRow.ordernum }}</el-descriptions-item>
        <el-descriptions-item label="下单时间">{{ formatTime(detailRow.createtime) }}</el-descriptions-item>
        <el-descriptions-item label="商品" :span="2">{{ detailRow.proname }}（{{ detailRow.ggname || '默认规格' }}）× {{ detailRow.num }}</el-descriptions-item>
        <el-descriptions-item label="商品总价">￥{{ Number(detailRow.product_price ?? detailRow.totalprice).toFixed(2) }}</el-descriptions-item>
        <el-descriptions-item label="运费">￥{{ Number(detailRow.freight_price || 0).toFixed(2) }}</el-descriptions-item>
        <el-descriptions-item label="实付款">￥{{ Number(detailRow.totalprice).toFixed(2) }}</el-descriptions-item>
        <el-descriptions-item label="订单类型">{{ detailRow.buytype_text }}</el-descriptions-item>
        <el-descriptions-item label="开奖结果">
          <span v-if="detailRow.iszj === 1" class="green-text">中奖</span>
          <span v-else-if="detailRow.iszj === 2" class="cell-sub">未中奖</span>
          <span v-else class="cell-sub">待开奖</span>
        </el-descriptions-item>
        <el-descriptions-item label="拼团状态">{{ detailRow.team?.status_text || '-' }}</el-descriptions-item>
        <el-descriptions-item label="收货人">{{ detailRow.linkman }} {{ detailRow.tel }}</el-descriptions-item>
        <el-descriptions-item label="收货地址" :span="2">{{ detailRow.address }}</el-descriptions-item>
        <el-descriptions-item label="支付方式">{{ detailRow.paytype }}</el-descriptions-item>
        <el-descriptions-item label="配送方式">{{ detailRow.freight_text }}</el-descriptions-item>
        <el-descriptions-item label="下单人">{{ detailRow.buyer?.nickname || '-' }}</el-descriptions-item>
        <el-descriptions-item label="来源">{{ detailRow.platform || '-' }}</el-descriptions-item>
        <el-descriptions-item v-if="detailRow.express_no" label="快递单号" :span="2">
          {{ detailRow.express_com }} {{ detailRow.express_no }}
        </el-descriptions-item>
        <el-descriptions-item v-if="detailRow.refund_status === 2" label="退款" :span="2">
          <span class="red-text">已退款 ￥{{ Number(detailRow.refund_money).toFixed(2) }}</span>
        </el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.lucky-order {
  margin: 3px;
}
.lucky-order.embedded {
  margin: 0;
}
.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 8px;
  border-bottom: 1px solid #ebeef5;
}
.panel-title {
  font-size: 15px;
  font-weight: 600;
}
.panel-close {
  border: none;
  background: transparent;
  font-size: 18px;
  cursor: pointer;
  color: #909399;
}
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 3px 0;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
  gap: 6px;
  flex-shrink: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  gap: 5px;
  flex-wrap: nowrap;
  justify-content: flex-end;
}
.search-btn {
  width: 26px;
  height: 26px;
  border: none;
  border-radius: 4px;
  background: #67c23a;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
  flex: none;
}
.search-btn:hover {
  background: #529b2e;
}
.pagination {
  margin-top: 3px;
  justify-content: flex-end;
}
.goods-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.goods-thumb {
  width: 40px;
  height: 40px;
  border-radius: 4px;
  flex-shrink: 0;
}
.goods-meta {
  min-width: 0;
}
.goods-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
}
.goods-spec {
  font-size: 12px;
  color: #909399;
}
.goods-price {
  font-size: 12px;
  color: #f56c6c;
}
.ordernum {
  font-size: 13px;
  font-weight: 500;
  color: #0f7ae5;
}
.cell-sub {
  font-size: 12px;
  color: #909399;
  margin-top: 2px;
}
.green-text {
  color: #67c23a;
  font-size: 12px;
}
.red-text {
  color: #f56c6c;
  font-size: 12px;
}
.buyer-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}
.buyer-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 60px;
}
.row-ops {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  align-items: center;
  gap: 2px 6px;
}
.ship-choose-tip {
  text-align: center;
  margin-bottom: 16px;
  color: #606266;
}
.ship-choose-btns {
  display: flex;
  justify-content: center;
  gap: 12px;
}
:deep(.el-table__header th) {
  background: #e6e6e6;
}
:deep(.el-table .cell) {
  text-align: left;
}
.w70 {
  width: 70px;
}
.w80 {
  width: 80px;
}
.w90 {
  width: 90px;
}
.w100 {
  width: 100px;
}
.ewb-body {
  font-size: 13px;
  max-height: calc(100vh - 130px);
  overflow-y: auto;
}
.ewb-title {
  color: #333;
  font-weight: 600;
  margin: 12px 0 8px;
}
.ewb-title::after {
  content: '';
  display: block;
  border-top: 1px solid #dcdfe6;
  margin-top: 6px;
}
.ewb-row {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}
.ewb-row-top {
  align-items: flex-start;
}
.ewb-row label {
  width: 110px;
  text-align: right;
  color: #606266;
  flex: none;
}
.ewb-row label i {
  color: #f56c6c;
  font-style: normal;
  margin-right: 2px;
}
.ewb-static {
  color: #333;
}
.ewb-aux {
  color: #909399;
  font-size: 12px;
}
.ewb-hint {
  color: #909399;
  font-size: 12px;
  margin: -4px 0 8px 116px;
}
.pkg-tag {
  background: #e6e6e6;
  padding: 4px 8px;
  color: #606266;
}
.ewb-banner {
  background: #f4f4f5;
  border-left: 3px solid #2bb3a3;
  padding: 8px 10px;
  color: #606266;
  margin-bottom: 8px;
}
.ewb-submit {
  margin: 12px 0 4px 110px;
}
.express-head {
  font-weight: 600;
  margin-bottom: 10px;
}
/* 送货单 */
.shd-toolbar {
  display: flex;
  gap: 6px;
  padding: 3px 0;
}
.shd-print {
  background: #fff;
  padding: 16px;
}
.shd-sheet {
  max-width: 960px;
}
.shd-title-row {
  position: relative;
  text-align: center;
  font-weight: bold;
  margin-bottom: 8px;
}
.shd-title {
  font-size: 20px;
}
.shd-no {
  position: absolute;
  right: 0;
  top: 4px;
  font-size: 15px;
}
.shd-info-row {
  position: relative;
  display: flex;
  gap: 24px;
  font-size: 14px;
  font-weight: bold;
  line-height: 1.9;
}
.shd-date {
  position: absolute;
  right: 0;
}
.shd-table-wrap {
  margin-top: 6px;
}
.shd-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  table-layout: fixed;
}
.shd-table th {
  padding: 4px;
  text-align: center;
  border: 1px solid #666;
}
.shd-table td {
  padding: 4px 6px;
  text-align: center;
  border: 1px solid #666;
  word-break: break-all;
}
.shd-table .w40 { width: 40px; }
.shd-table .w60 { width: 60px; }
.shd-table .w70 { width: 70px; }
.shd-table .w80 { width: 80px; }
.shd-table .w180 { width: 180px; }
.shd-left {
  text-align: left !important;
}
.shd-right {
  text-align: right !important;
}
.shd-foot {
  display: flex;
  gap: 60px;
  margin-top: 20px;
  font-size: 14px;
  font-weight: bold;
}
</style>

<style>
/* 打印：仅输出送货单 */
@media print {
  body * {
    visibility: hidden;
  }
  .shd-print,
  .shd-print * {
    visibility: visible;
  }
  .shd-print {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    padding: 0;
  }
}
</style>
