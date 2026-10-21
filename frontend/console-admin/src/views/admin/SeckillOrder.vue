<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  closeSeckillOrder,
  deleteSeckillOrder,
  exportSeckillOrder,
  getSeckillOrderDetail,
  getSeckillOrderList,
  getSeckillOrderTrace,
  getSeckillDeliveryNote,
  getSeckillExpressOptions,
  hexiaoSeckillOrder,
  ispaySeckillOrder,
  shipSeckillOrder,
  type SeckillTrace,
  type SeckillDeliveryNote,
} from '../../api/seckill'
import { prepareEwaybill, addEwaybill, type EwaybillPrepare } from '../../api/ewaybill'
import { formatTime } from '../../utils/time'

interface OrderRow {
  id: number
  ordernum: string
  mid: number
  nickname: string
  headimg: string
  proname: string
  propic: string
  ggname: string
  num: number
  sell_price: number
  product_price: number
  totalprice: number
  status: number
  linkman: string
  tel: string
  address: string
  platform: string
  paytype: string
  freight_type: number
  freight_text: string
  express_com: string
  express_no: string
  createtime: number
  paytime: number
  send_time: number
  collect_time: number
  remark: string
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<OrderRow[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  status: '' as string,
  ordernum: '',
  linkman: '',
  tel: '',
  dateRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getSeckillOrderList({
      page: query.page,
      pagesize: query.pagesize,
      status: query.status === '' ? undefined : Number(query.status),
      ordernum: query.ordernum || undefined,
      linkman: query.linkman || undefined,
      tel: query.tel || undefined,
      start_date: query.dateRange ? query.dateRange[0] : undefined,
      end_date: query.dateRange ? query.dateRange[1] : undefined,
    })
    tableData.value = (data.list || []) as unknown as OrderRow[]
    total.value = data.total || 0
  } catch {
    /* 错误已由拦截器弹出 */
  } finally {
    loading.value = false
  }
}

function handleSearch() { query.page = 1; fetchList() }
function handlePageChange(p: number) { query.page = p; fetchList() }
function handleSizeChange(s: number) { query.pagesize = s; query.page = 1; fetchList() }

async function handleExport() {
  try {
    await exportSeckillOrder({
      status: query.status === '' ? undefined : Number(query.status),
      ordernum: query.ordernum || undefined,
      linkman: query.linkman || undefined,
      tel: query.tel || undefined,
    })
  } catch {
    /* 错误已由拦截器弹出 */
  }
}
function handleBatchShip() {
  ElMessage.info('请在订单行内逐单发货')
}

function fmtTs(ts: number) { return ts ? formatTime(ts) : '-' }
function money(v: number | string) { return '￥' + Number(v || 0).toFixed(2) }
function statusText(row: OrderRow) {
  if (row.status === 3) return '已完成'
  return ['未支付', '已支付', '已发货'][row.status] ?? '已关闭'
}
function statusColor(row: OrderRow) {
  switch (row.status) {
    case 1: return '#21ba45'
    case 2: return '#ffc702'
    case 3: return '#21ba45'
    case 4: return '#f56c6c'
    default: return '#ff8758'
  }
}
// 来源（按 platform 粗略映射）
function sourceText(row: OrderRow) {
  switch (row.platform) {
    case 'wx': return '微信小程序'
    case 'h5': return '手机H5'
    case 'wechat': return '微信小程序'
    default: return row.platform ? row.platform : '-'
  }
}

// ============ 详情 ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<{ order: OrderRow; member: Record<string, any> } | null>(null)
async function openDetail(row: OrderRow) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = (await getSeckillOrderDetail(row.id)) as unknown as { order: OrderRow; member: Record<string, any> }
  } catch { /* ignore */ } finally { detailLoading.value = false }
}

function handleDelete(row: OrderRow) {
  ElMessageBox.confirm(`确定要删除秒杀订单「${row.ordernum}」吗？删除后无法恢复。`, '删除确认', {
    confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    await deleteSeckillOrder(row.id)
    ElMessage.success('删除成功')
    fetchList()
  }).catch(() => {})
}
function handleClose(row: OrderRow) {
  ElMessageBox.confirm(`确定要关闭订单「${row.ordernum}」吗？`, '关单确认', {
    confirmButtonText: '关闭订单', cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    await closeSeckillOrder(row.id)
    ElMessage.success('订单已关闭')
    fetchList()
  }).catch(() => {})
}
function handleIspay(row: OrderRow) {
  ElMessageBox.confirm(`确定要把订单「${row.ordernum}」改为已支付吗？`, '操作确认', {
    confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    await ispaySeckillOrder(row.id)
    ElMessage.success('操作成功')
    fetchList()
  }).catch(() => {})
}
function handleHexiao(row: OrderRow) {
  ElMessageBox.confirm('确定核销并改为已完成状态吗?', '核销确认', {
    confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning',
  }).then(async () => {
    await hexiaoSeckillOrder(row.id)
    ElMessage.success('核销成功')
    fetchList()
  }).catch(() => {})
}

// ============ 发货方式选择小弹窗 ============
const shipChooserVisible = ref(false)
const shipRow = ref<OrderRow | null>(null)
function openShip(row: OrderRow) {
  shipRow.value = row
  shipChooserVisible.value = true
}
function chooseManual() {
  shipChooserVisible.value = false
  openManualShip(shipRow.value!)
}
function chooseEwaybill() {
  shipChooserVisible.value = false
  openEwaybill(shipRow.value!)
}

// ============ 手动发货弹窗 ============
const manualVisible = ref(false)
const manualRow = ref<OrderRow | null>(null)
const manualFormRef = ref<FormInstance>()
const manualSubmitting = ref(false)
const expressOptions = ref<{ code: string; name: string }[]>([])
const manualForm = reactive({ express_com: 'EMS', express_no: '' })
const manualRules: FormRules = {
  express_com: [{ required: true, message: '请选择快递公司', trigger: 'change' }],
  express_no: [{ required: true, message: '请输入快递单号', trigger: 'blur' }],
}
async function openManualShip(row: OrderRow) {
  manualRow.value = row
  manualForm.express_com = row.express_com || 'EMS'
  manualForm.express_no = row.express_no || ''
  manualVisible.value = true
  if (expressOptions.value.length === 0) {
    try { expressOptions.value = await getSeckillExpressOptions() } catch { /* ignore */ }
  }
}
async function handleManualSubmit() {
  if (!manualFormRef.value || !manualRow.value) return
  await manualFormRef.value.validate(async (valid) => {
    if (!valid) return
    manualSubmitting.value = true
    try {
      await shipSeckillOrder(manualRow.value!.id, manualForm.express_com, manualForm.express_no)
      ElMessage.success('发货成功')
      manualVisible.value = false
      fetchList()
    } catch { /* ignore */ } finally { manualSubmitting.value = false }
  })
}

// ============ 电子面单发货弹窗（三段） ============
const ewayVisible = ref(false)
const ewayRow = ref<OrderRow | null>(null)
const ewayPrepare = ref<EwaybillPrepare | null>(null)
const ewayLoading = ref(false)
const ewaySubmitting = ref(false)
const ewayForm = reactive({
  delivery_info: '',
  quantity: 1,
  weight: 1,
  volume: 0.001,
  is_notice: 0,
  insure: 0,
  remark: '',
  receiver_name: '',
  receiver_mobile: '',
  receiver_province: '',
  receiver_city: '',
  receiver_area: '',
  receiver_address: '',
  receiver_post_code: '',
  receiver_company: '',
  sender_name: '',
  sender_mobile: '',
  sender_province: '',
  sender_city: '',
  sender_area: '',
  sender_address: '',
  sender_post_code: '',
  sender_company: '',
})
async function openEwaybill(row: OrderRow) {
  ewayRow.value = row
  ewayVisible.value = true
  ewayLoading.value = true
  // 预填收件人
  ewayForm.receiver_name = row.linkman
  ewayForm.receiver_mobile = row.tel
  ewayForm.receiver_address = row.address
  ewayForm.receiver_province = ''
  ewayForm.receiver_city = ''
  ewayForm.receiver_area = ''
  ewayForm.remark = `${row.proname} ${row.ggname || '默认规格'} x${row.num}`
  try {
    ewayPrepare.value = await prepareEwaybill({ ordertype: 'seckill', orderid: row.id })
    const last = (ewayPrepare.value.last_order || {}) as Record<string, any>
    ewayForm.sender_name = last.sender_name || ''
    ewayForm.sender_mobile = last.sender_mobile || ''
    ewayForm.sender_province = last.sender_province || ''
    ewayForm.sender_city = last.sender_city || ''
    ewayForm.sender_area = last.sender_area || ''
    ewayForm.sender_address = last.sender_address || ''
    ewayForm.sender_post_code = last.sender_post_code || ''
  } catch { /* ignore */ } finally { ewayLoading.value = false }
}
async function handleEwaySubmit() {
  if (!ewayRow.value || !ewayPrepare.value) return
  if (!ewayForm.delivery_info) { ElMessage.warning('请选择快递公司'); return }
  if (!ewayForm.receiver_name || !ewayForm.receiver_mobile) { ElMessage.warning('请填写收件人姓名和手机号'); return }
  ewaySubmitting.value = true
  try {
    await addEwaybill({
      delivery_info: ewayForm.delivery_info,
      ordercode: ewayPrepare.value.ordercode,
      ordertype: 'seckill',
      orderid: ewayRow.value.id,
      quantity: ewayForm.quantity,
      weight: ewayForm.weight,
      volume: ewayForm.volume,
      is_notice: ewayForm.is_notice,
      insure: ewayForm.insure,
      remark: ewayForm.remark,
      receiver_name: ewayForm.receiver_name,
      receiver_mobile: ewayForm.receiver_mobile,
      receiver_province: ewayForm.receiver_province,
      receiver_city: ewayForm.receiver_city,
      receiver_area: ewayForm.receiver_area,
      receiver_address: ewayForm.receiver_address,
      receiver_post_code: ewayForm.receiver_post_code,
      receiver_company: ewayForm.receiver_company,
      sender_name: ewayForm.sender_name,
      sender_mobile: ewayForm.sender_mobile,
      sender_province: ewayForm.sender_province,
      sender_city: ewayForm.sender_city,
      sender_area: ewayForm.sender_area,
      sender_address: ewayForm.sender_address,
      sender_post_code: ewayForm.sender_post_code,
      sender_company: ewayForm.sender_company,
    })
    ElMessage.success('发货成功')
    ewayVisible.value = false
    fetchList()
  } catch { /* ignore */ } finally { ewaySubmitting.value = false }
}

// ============ 查物流（真实快递鸟轨迹） ============
const traceVisible = ref(false)
const traceLoading = ref(false)
const traceData = ref<SeckillTrace | null>(null)
async function openTrace(row: OrderRow) {
  traceVisible.value = true
  traceLoading.value = true
  traceData.value = null
  try {
    traceData.value = await getSeckillOrderTrace(row.id)
  } catch { /* ignore */ } finally { traceLoading.value = false }
}

// ============ 送货单（整页打印） ============
async function printDeliveryNote(row: OrderRow) {
  try {
    const note = await getSeckillDeliveryNote(row.id)
    const html = renderDeliveryHtml(note)
    const w = window.open('', '_blank', 'width=900,height=700')
    if (!w) { ElMessage.warning('请允许浏览器弹出窗口'); return }
    w.document.write(html)
    w.document.close()
    w.focus()
    setTimeout(() => w.print(), 300)
  } catch { /* ignore */ }
}
function renderDeliveryHtml(n: SeckillDeliveryNote): string {
  return `<!doctype html><html><head><meta charset="utf-8"><title>送货单 ${n.ordernum}</title>
<style>body{font-family:"Microsoft YaHei",sans-serif;padding:32px;color:#333}
h1{text-align:center;font-size:24px;margin:0 0 6px}
.sub{text-align:center;color:#666;font-size:13px;margin-bottom:20px}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:14px}
td,th{border:1px solid #999;padding:8px 10px;text-align:left}
th{background:#f0f0f0}
.row{display:flex;justify-content:space-between;margin-top:18px;font-size:14px}
.sign{margin-top:40px;display:flex;justify-content:space-between;font-size:14px}</style></head>
<body>
<h1>送 货 单</h1><div class="sub">订单号：${n.ordernum}　下单时间：${n.createtime}</div>
<table>
<tr><th>商品名称</th><th>规格</th><th>单价</th><th>数量</th><th>小计</th></tr>
<tr><td>${n.proname}</td><td>${n.ggname}</td><td>${money(n.sell_price)}</td><td>${n.num}</td><td>${money(n.totalprice)}</td></tr>
</table>
<div class="row"><span>收货人：${n.linkman}　${n.tel}</span></div>
<div class="row"><span>收货地址：${n.address || '-'}</span></div>
<div class="row"><span>配送方式：${n.freight_text || '快递配送'}　快递公司：${n.express_com || '-'}　运单号：${n.express_no || '-'}</span></div>
<div class="sign"><span>收货人签字：____________</span><span>日期：____________</span></div>
</body></html>`
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-left">
        <el-button @click="handleExport">导出</el-button>
        <el-button @click="handleBatchShip">批量发货</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" placeholder="订单号" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="收货人">
          <el-input v-model="query.linkman" placeholder="收货人" clearable style="width: 110px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input v-model="query.tel" placeholder="手机号" clearable style="width: 120px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="下单时间">
          <el-date-picker v-model="query.dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width: 220px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 100px">
            <el-option label="未支付" value="0" />
            <el-option label="已支付" value="1" />
            <el-option label="已发货" value="2" />
            <el-option label="已完成" value="3" />
            <el-option label="已关闭" value="4" />
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
      <el-table v-loading="loading" :data="tableData" border>
        <el-table-column prop="id" label="ID" width="60" align="left" />
        <el-table-column label="商品信息" min-width="200">
          <template #default="{ row }">
            <div class="goods-info">
              <el-image v-if="row.propic" :src="row.propic" :preview-src-list="[row.propic]" fit="cover" class="goods-img" preview-teleported />
              <div v-else class="goods-img goods-img-empty">无图</div>
              <div class="goods-meta">
                <div class="goods-name">{{ row.proname }}</div>
                <div class="goods-sub">{{ row.ggname || '默认规格' }}</div>
                <div class="goods-price">￥{{ Number(row.sell_price).toFixed(2) }} × {{ row.num }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="订单号/下单时间" width="160">
          <template #default="{ row }">
            <div>{{ row.ordernum }}</div>
            <div class="muted">{{ formatTime(row.createtime) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="商品总价/实付款" width="120">
          <template #default="{ row }">
            <div>总价：{{ money(row.product_price) }}</div>
            <div class="strong red">实付：{{ money(row.totalprice) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="收货地址" min-width="180">
          <template #default="{ row }">
            <div class="strong">{{ row.linkman }} {{ row.tel }}</div>
            <div class="muted">{{ row.address }}</div>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="100" align="left">
          <template #default="{ row }">{{ sourceText(row) }}</template>
        </el-table-column>
        <el-table-column label="头像/昵称" width="110">
          <template #default="{ row }">
            <div class="nick">
              <el-image v-if="row.headimg" :src="row.headimg" fit="cover" class="headimg" />
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="paytype" label="支付方式" width="90" align="left">
          <template #default="{ row }">{{ row.paytype || '-' }}</template>
        </el-table-column>
        <el-table-column prop="freight_text" label="配送方式" width="100" align="left">
          <template #default="{ row }">{{ row.freight_text || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="left">
          <template #default="{ row }">
            <div class="st" :style="{ color: statusColor(row) }">{{ statusText(row) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openDetail(row)">查看详情</el-button>
              <el-button link type="primary" @click="printDeliveryNote(row)">送货单</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除订单</el-button>
              <!-- 未支付 -->
              <template v-if="row.status === 0">
                <el-button link type="warning" @click="handleClose(row)">关闭订单</el-button>
                <el-button link type="primary" @click="handleIspay(row)">改为已支付</el-button>
              </template>
              <!-- 已支付：发货；到店自提显核销 -->
              <template v-if="row.status === 1">
                <el-button link type="primary" @click="openShip(row)">发货</el-button>
                <el-button v-if="row.freight_type === 1" link type="success" @click="handleHexiao(row)">核销</el-button>
              </template>
              <!-- 已发货/已完成：查物流 -->
              <template v-if="row.status === 2 || row.status === 3">
                <el-button link type="primary" @click="openTrace(row)">查物流</el-button>
              </template>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination class="pagination" background layout="total, sizes, prev, pager, next, jumper" :total="total" :current-page="query.page" :page-size="query.pagesize" :page-sizes="[10, 20, 50, 100]" @current-change="handlePageChange" @size-change="handleSizeChange" />
    </el-card>

    <!-- 订单详情 -->
    <el-dialog v-model="detailVisible" title="订单详情" width="560px">
      <div v-loading="detailLoading">
        <template v-if="detail">
          <el-descriptions :column="1" border size="small">
            <el-descriptions-item label="订单号">{{ detail.order.ordernum }}</el-descriptions-item>
            <el-descriptions-item label="商品">{{ detail.order.proname }}（{{ detail.order.ggname || '默认' }}）× {{ detail.order.num }}</el-descriptions-item>
            <el-descriptions-item label="下单人">{{ detail.member.nickname || `会员${detail.order.mid}` }}</el-descriptions-item>
            <el-descriptions-item label="订单金额">{{ money(detail.order.totalprice) }}</el-descriptions-item>
            <el-descriptions-item label="收货地址">{{ detail.order.linkman }} {{ detail.order.tel }}<br />{{ detail.order.address }}</el-descriptions-item>
            <el-descriptions-item label="支付方式">{{ detail.order.paytype || '-' }}</el-descriptions-item>
            <el-descriptions-item label="配送方式">{{ detail.order.freight_text || '-' }}</el-descriptions-item>
            <el-descriptions-item label="状态">{{ statusText(detail.order as OrderRow) }}</el-descriptions-item>
            <el-descriptions-item label="下单时间">{{ fmtTs(detail.order.createtime) }}</el-descriptions-item>
            <el-descriptions-item v-if="detail.order.paytime" label="支付时间">{{ fmtTs(detail.order.paytime) }}</el-descriptions-item>
            <el-descriptions-item v-if="detail.order.send_time" label="发货时间">{{ fmtTs(detail.order.send_time) }}</el-descriptions-item>
            <el-descriptions-item v-if="detail.order.express_com" label="快递">{{ detail.order.express_com }} {{ detail.order.express_no }}</el-descriptions-item>
          </el-descriptions>
        </template>
      </div>
    </el-dialog>

    <!-- 发货方式选择 -->
    <el-dialog v-model="shipChooserVisible" title="请选择发货方式" width="360px" align-center>
      <div style="text-align:center; padding: 8px 0 4px; color:#606266;">是否要使用电子面单发货？</div>
      <template #footer>
        <el-button type="primary" @click="chooseEwaybill">使用电子面单发货</el-button>
        <el-button @click="chooseManual">手动发货</el-button>
      </template>
    </el-dialog>

    <!-- 手动发货 -->
    <el-dialog v-model="manualVisible" title="订单发货" width="380px" align-center>
      <el-form ref="manualFormRef" :model="manualForm" :rules="manualRules" label-width="80px" label-position="left">
        <el-form-item label="快递公司" prop="express_com">
          <el-select v-model="manualForm.express_com" placeholder="请选择" style="width:100%">
            <el-option v-for="c in expressOptions" :key="c.code" :label="c.name" :value="c.name" />
          </el-select>
        </el-form-item>
        <el-form-item label="快递单号" prop="express_no">
          <el-input v-model="manualForm.express_no" placeholder="请输入快递单号" class="field-md" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="primary" :loading="manualSubmitting" @click="handleManualSubmit">确定</el-button>
        <el-button @click="manualVisible = false">取消</el-button>
      </template>
    </el-dialog>

    <!-- 电子面单发货（三段） -->
    <el-dialog v-model="ewayVisible" title="创建电子面单" width="640px">
      <div v-loading="ewayLoading">
        <div v-if="ewayRow" class="ew-section">
          <div class="ew-row"><span class="ew-label">订单号：</span>{{ ewayRow.ordernum }}</div>
          <div class="ew-row"><span class="ew-label">面单订单号：</span>{{ ewayPrepare?.ordercode || '-' }}</div>
          <el-form label-width="110px" label-position="left" size="default">
            <el-form-item label="快递公司" required>
              <el-select v-model="ewayForm.delivery_info" placeholder="请选择快递公司" style="width:100%">
                <el-option v-for="o in ewayPrepare?.express_options || []" :key="o.code" :label="o.name" :value="o.code" />
              </el-select>
              <div v-if="(ewayPrepare?.express_options || []).length === 0" class="ew-tip">未检测到已绑定的快递公司账号，请到「物流面单-面单设置」绑定后使用</div>
            </el-form-item>
            <el-form-item label="通知快递员">
              <el-switch v-model="ewayForm.is_notice" :active-value="1" :inactive-value="0" />
              <span class="ew-tip">通知快递员上门揽件</span>
            </el-form-item>
            <div class="ew-block">包裹信息</div>
            <el-form-item label="包裹数量">
              <el-input-number v-model="ewayForm.quantity" :min="1" :controls="false" style="width:110px" />
              <span class="ew-unit">包裹总重量(kg)</span>
              <el-input-number v-model="ewayForm.weight" :min="0" :precision="1" :controls="false" style="width:110px" />
              <span class="ew-unit">包裹总体积(m³)</span>
              <el-input-number v-model="ewayForm.volume" :min="0" :precision="3" :controls="false" style="width:110px" />
            </el-form-item>
            <el-form-item label="备注信息">
              <el-input v-model="ewayForm.remark" type="textarea" :rows="2" maxlength="200" />
            </el-form-item>

            <div class="ew-block">收件人信息</div>
            <el-form-item label="姓名" required>
              <el-input v-model="ewayForm.receiver_name" class="field-md" />
            </el-form-item>
            <el-form-item label="手机号码" required>
              <el-input v-model="ewayForm.receiver_mobile" class="field-md" />
            </el-form-item>
            <el-form-item label="地区">
              <el-input v-model="ewayForm.receiver_province" placeholder="省" style="width:120px" />
              <el-input v-model="ewayForm.receiver_city" placeholder="市" style="width:120px" />
              <el-input v-model="ewayForm.receiver_area" placeholder="区" style="width:120px" />
            </el-form-item>
            <el-form-item label="详细地址">
              <el-input v-model="ewayForm.receiver_address" />
            </el-form-item>
            <el-form-item label="邮编">
              <el-input v-model="ewayForm.receiver_post_code" placeholder="邮政/EMS 必填 可填000000" class="field-md" />
            </el-form-item>

            <div class="ew-tip-bar">发件人信息会自动获取上一单的发件人信息（仅需填写一次），如需要修改请手动输入。</div>
            <div class="ew-block">发件人信息</div>
            <el-form-item label="姓名" required>
              <el-input v-model="ewayForm.sender_name" class="field-md" />
            </el-form-item>
            <el-form-item label="手机号码" required>
              <el-input v-model="ewayForm.sender_mobile" class="field-md" />
            </el-form-item>
            <el-form-item label="地区">
              <el-input v-model="ewayForm.sender_province" placeholder="省" style="width:120px" />
              <el-input v-model="ewayForm.sender_city" placeholder="市" style="width:120px" />
              <el-input v-model="ewayForm.sender_area" placeholder="区" style="width:120px" />
            </el-form-item>
            <el-form-item label="详细地址">
              <el-input v-model="ewayForm.sender_address" />
            </el-form-item>
            <el-form-item label="邮编">
              <el-input v-model="ewayForm.sender_post_code" class="field-md" />
            </el-form-item>
          </el-form>
        </div>
      </div>
      <template #footer>
        <el-button type="danger" :loading="ewaySubmitting" @click="handleEwaySubmit">提交</el-button>
      </template>
    </el-dialog>

    <!-- 查物流 -->
    <el-dialog v-model="traceVisible" :title="traceData?.express_com || '物流信息'" width="440px">
      <div v-loading="traceLoading">
        <template v-if="traceData">
          <div class="trace-head">{{ traceData.express_com }}　{{ traceData.express_no }}</div>
          <el-timeline>
            <el-timeline-item v-for="(t, i) in traceData.traces" :key="i" :timestamp="t.time" :type="i === 0 ? 'primary' : ''">{{ t.station }}</el-timeline-item>
          </el-timeline>
        </template>
      </div>
    </el-dialog>
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
.goods-img { width: 46px; height: 46px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.goods-img-empty { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 12px; background: #fafafa; }
.goods-meta { min-width: 0; flex: 1; }
.goods-name { color: #303133; font-size: 13px; line-height: 1.5; word-break: break-all; }
.goods-sub { color: #999; font-size: 12px; margin-top: 2px; }
.goods-price { color: #ff6600; font-weight: 600; font-size: 13px; margin-top: 2px; }
.nick { display: flex; align-items: center; gap: 6px; }
.headimg { width: 30px; height: 30px; border-radius: 50%; }
.muted { color: #909399; font-size: 12px; }
.strong { font-weight: 600; }
.red { color: #ff4246; }
.st { font-size: 12px; }
.row-ops { display: flex; flex-direction: row; flex-wrap: wrap; gap: 2px 8px; line-height: 1.6; align-items: center; }
.row-ops .el-button { margin: 0; height: auto; min-height: 0; padding: 0; font-size: 12px; }
.row-ops :deep(.el-button) { height: auto; padding: 1px 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 12px; justify-content: flex-end; }

.ew-section label { font-size: 13px; }
.ew-block { font-weight: 600; color: #303133; margin: 14px 0 8px; padding-left: 8px; border-left: 3px solid #009688; }
.ew-row { margin: 6px 0; font-size: 13px; }
.ew-label { color: #909399; }
.ew-unit { color: #909399; font-size: 12px; margin: 0 6px; }
.ew-tip { color: #909399; font-size: 12px; margin-left: 8px; }
.ew-tip-bar { background:#f5f7fa; color:#606266; font-size:12px; padding:8px 10px; border-left:3px solid #009688; margin:10px 0; }
.trace-head { font-size: 13px; color: #606266; margin-bottom: 12px; }
</style>
