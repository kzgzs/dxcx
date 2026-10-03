<template>
  <div class="tg-order">
    <!-- 工具栏一行：左 导出/批量发货；右 搜索条件 -->
    <div class="toolbar">
      <div class="toolbar-left">
        <el-button @click="doExport">导出</el-button>
        <el-button type="primary" @click="openBatch">批量发货</el-button>
      </div>
      <div class="toolbar-right">
        <el-input v-model="query.ordernum" placeholder="订单号" clearable size="small" @keyup.enter="reload" />
        <el-input v-model="query.linkman" placeholder="收货人" clearable size="small" @keyup.enter="reload" />
        <el-input v-model="query.tel" placeholder="手机号" clearable size="small" @keyup.enter="reload" />
        <el-date-picker
          v-model="query.ctimeRange"
          type="daterange"
          size="small"
          range-separator="-"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          value-format="YYYY-MM-DD"
          style="width: 260px"
        />
        <el-select v-model="query.status" placeholder="状态" size="small" style="width: 110px" clearable>
          <el-option label="全部" value="" />
          <el-option label="未支付" value="0" />
          <el-option label="已支付" value="1" />
          <el-option label="已发货" value="2" />
          <el-option label="已完成" value="3" />
          <el-option label="已关闭" value="4" />
        </el-select>
        <el-button type="primary" size="small" circle :icon="Search" @click="reload" />
      </div>
    </div>

    <el-table v-loading="loading" :data="list" border size="small" class="order-table" @sort-change="onSortChange">
      <el-table-column prop="id" label="ID" width="80" sortable="custom" />
      <el-table-column label="商品信息" min-width="220">
        <template #default="{ row }">
          <div class="goods-cell">
            <el-image :src="row.propic" fit="cover" class="goods-img" />
            <div class="goods-meta">
              <div class="goods-name">{{ row.proname }}</div>
              <div class="goods-price">￥{{ row.sell_price }} × {{ row.num }}</div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="订单号/下单时间" width="190" sortable="custom" prop="ordernum">
        <template #default="{ row }">
          <div class="ordernum">{{ row.ordernum }}</div>
          <div class="time">{{ fmtTime(row.createtime) }}</div>
        </template>
      </el-table-column>
      <el-table-column label="商品总价/实付款" width="120">
        <template #default="{ row }">
          <div>总价 ￥{{ row.product_price }}</div>
          <div>实付 ￥{{ row.totalprice }}</div>
          <div v-if="row.refund_money > 0" class="refund">已退 ￥{{ row.refund_money }}</div>
        </template>
      </el-table-column>
      <el-table-column label="收货地址" min-width="200">
        <template #default="{ row }">
          <div>{{ row.linkman }} {{ row.tel }}</div>
          <div class="muted">{{ row.address }}</div>
        </template>
      </el-table-column>
      <el-table-column prop="platform_text" label="来源" width="90" />
      <el-table-column label="头像昵称" width="110">
        <template #default="{ row }">
          <div class="buyer-cell">
            <el-avatar :src="row.buyer?.headimg" :size="24">{{ (row.buyer?.nickname || '?').slice(0, 1) }}</el-avatar>
            <span>{{ row.buyer?.nickname || '-' }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column prop="paytype_text" label="支付方式" width="90" />
      <el-table-column prop="freight_type_text" label="配送方式" width="90" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag size="small" :type="statusTag(row)">{{ row.status_text }}</el-tag>
          <div v-if="row.refund_status > 0" class="refund-tag">{{ row.refund_text }}</div>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="230" fixed="right">
        <template #default="{ row }">
          <div class="ops">
            <el-button link type="primary" size="small" @click="openDetail(row)">查看详情</el-button>
            <el-button link type="danger" size="small" @click="doDelete(row)">删除订单</el-button>
            <el-button v-if="row.status === 0" link type="warning" size="small" @click="doClose(row)">关闭订单</el-button>
            <el-button v-if="row.status === 0" link type="success" size="small" @click="doIspay(row)">改为已支付</el-button>
            <el-button v-if="row.status === 1" link type="primary" size="small" @click="openSendWay(row)">发货</el-button>
            <el-button v-if="row.status === 1 && !isExpress(row)" link type="danger" size="small" @click="doHexiao(row)">核销</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      class="pager"
      :current-page="page"
      :page-size="pagesize"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="(p: number) => { page = p; load() }"
    />

    <!-- 发货方式选择小弹窗 -->
    <el-dialog v-model="sendWayVisible" title="请选择发货方式" width="380px" append-to-body>
      <p class="way-tip">是否要使用电子面单发货？</p>
      <template #footer>
        <el-button type="primary" @click="useEwaybill">使用电子面单发货</el-button>
        <el-button @click="useManual">手动发货</el-button>
      </template>
    </el-dialog>

    <!-- 电子面单（创建电子面单）三段 -->
    <el-dialog v-model="ewaybillVisible" title="创建电子面单" width="680px" append-to-body>
      <el-form label-width="110px" size="small" class="ewb-form">
        <el-divider content-position="left">发货信息</el-divider>
        <el-form-item label="订单号">
          <el-input v-model="ewb.ordernum" disabled />
        </el-form-item>
        <el-form-item label="面单订单号">
          <el-input v-model="ewb.ordercode" disabled />
        </el-form-item>
        <el-form-item label="快递公司" required>
          <el-select v-model="ewb.delivery_info" placeholder="请选择" style="width: 100%">
            <el-option v-for="o in ewbOptions" :key="o.code" :label="o.name" :value="o.code" />
          </el-select>
        </el-form-item>
        <el-form-item label="通知快递员">
          <el-switch v-model="ewb.notify" />
          <span class="muted">通知快递员上门揽件</span>
        </el-form-item>
        <el-divider content-position="left">包裹信息</el-divider>
        <el-form-item label="包裹数量">
          <el-input-number v-model="ewb.quantity" :min="1" />
        </el-form-item>
        <el-form-item label="包裹总重量(kg)">
          <el-input-number v-model="ewb.weight" :min="0.1" :step="0.1" />
        </el-form-item>
        <el-form-item label="包裹总体积(m³)">
          <el-input-number v-model="ewb.volume" :min="0.001" :step="0.001" :precision="3" />
        </el-form-item>
        <div class="muted ewb-hint">一个包裹对应一个运单号，数量&gt;1，则按照子母件的方式返回母运单号和子运单号</div>
        <el-form-item label="是否保价">
          <el-switch v-model="ewb.insured" />
        </el-form-item>
        <el-form-item label="备注信息">
          <el-input v-model="ewb.remark" type="textarea" :rows="2" placeholder="快递备注信息，该内容会打印在快递单上；不超过200字符" />
        </el-form-item>

        <el-divider content-position="left">收件人信息</el-divider>
        <el-form-item label="姓名" required><el-input v-model="ewb.receiver_name" /></el-form-item>
        <el-form-item label="手机号码" required><el-input v-model="ewb.receiver_mobile" /></el-form-item>
        <el-form-item label="地区" required>
          <el-input v-model="ewb.receiver_region" placeholder="省/市/区" />
        </el-form-item>
        <el-form-item label="详细地址" required><el-input v-model="ewb.receiver_address" /></el-form-item>
        <el-form-item label="座机号码"><el-input v-model="ewb.receiver_tel" /></el-form-item>
        <el-form-item label="公司名称"><el-input v-model="ewb.receiver_company" /></el-form-item>
        <el-form-item label="邮编"><el-input v-model="ewb.receiver_post_code" /></el-form-item>

        <el-divider content-position="left">发件人信息</el-divider>
        <el-alert type="info" :closable="false" show-icon title="发件人信息会自动获取上一单的发件人信息（仅需填写一次），如需要修改请手动输入。" style="margin-bottom:10px" />
        <el-form-item label="姓名" required><el-input v-model="ewb.sender_name" /></el-form-item>
        <el-form-item label="手机号码" required><el-input v-model="ewb.sender_mobile" /></el-form-item>
        <el-form-item label="地区" required><el-input v-model="ewb.sender_region" placeholder="省/市/区" /></el-form-item>
        <el-form-item label="详细地址" required><el-input v-model="ewb.sender_address" /></el-form-item>
        <el-form-item label="座机号码"><el-input v-model="ewb.sender_tel" /></el-form-item>
        <el-form-item label="公司名称"><el-input v-model="ewb.sender_company" /></el-form-item>
        <el-form-item label="邮编"><el-input v-model="ewb.sender_post_code" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="ewaybillVisible = false">取消</el-button>
        <el-button type="primary" @click="submitEwaybill">提交</el-button>
      </template>
    </el-dialog>

    <!-- 手动发货 -->
    <el-dialog v-model="manualVisible" title="手动发货" width="420px" append-to-body>
      <el-form label-width="90px" size="small">
        <el-form-item label="快递公司">
          <el-select v-model="manualForm.express_com" style="width: 100%">
            <el-option v-for="o in expressOptions" :key="o.code" :label="o.name" :value="o.name" />
          </el-select>
        </el-form-item>
        <el-form-item label="快递单号">
          <el-input v-model="manualForm.express_no" placeholder="请输入快递单号" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="primary" @click="submitManual">确定</el-button>
        <el-button @click="manualVisible = false">取消</el-button>
      </template>
    </el-dialog>

    <!-- 批量发货 -->
    <el-dialog v-model="batchVisible" title="导入EXCEL文件" width="480px" append-to-body>
      <el-form label-width="100px" size="small">
        <el-form-item label="快递公司">
          <el-select v-model="batchForm.express_com" style="width: 100%">
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

    <!-- 订单详情 -->
    <el-dialog v-model="detailVisible" title="查看详情" width="640px" append-to-body>
      <el-descriptions v-if="detail" :column="2" border size="small">
        <el-descriptions-item label="订单号">{{ detail.ordernum }}</el-descriptions-item>
        <el-descriptions-item label="状态">{{ detail.status_text }}</el-descriptions-item>
        <el-descriptions-item label="商品">{{ detail.proname }}</el-descriptions-item>
        <el-descriptions-item label="数量">×{{ detail.num }}</el-descriptions-item>
        <el-descriptions-item label="商品总价">￥{{ detail.product_price }}</el-descriptions-item>
        <el-descriptions-item label="实付款">￥{{ detail.totalprice }}</el-descriptions-item>
        <el-descriptions-item label="收货人">{{ detail.linkman }} {{ detail.tel }}</el-descriptions-item>
        <el-descriptions-item label="地址">{{ detail.address }}</el-descriptions-item>
        <el-descriptions-item label="快递公司">{{ detail.express_com || '-' }}</el-descriptions-item>
        <el-descriptions-item label="快递单号">{{ detail.express_no || '-' }}</el-descriptions-item>
        <el-descriptions-item label="下单时间">{{ fmtTime(detail.createtime) }}</el-descriptions-item>
        <el-descriptions-item label="支付方式">{{ detail.paytype_text }}</el-descriptions-item>
      </el-descriptions>
      <div v-if="(detail?.status === 2 || detail?.status === 3) && detail?.express_no" style="margin-top:10px">
        <el-button type="primary" size="small" @click="openTrace(detail)">查物流</el-button>
      </div>
    </el-dialog>

    <!-- 物流轨迹 -->
    <el-dialog v-model="traceVisible" title="查物流" width="520px" append-to-body>
      <el-timeline v-if="trace.traces.length">
        <el-timeline-item v-for="(t, i) in trace.traces" :key="i" :timestamp="t.time">{{ t.station }}</el-timeline-item>
      </el-timeline>
      <el-empty v-else description="暂无物流轨迹" />
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import {
  getTuangouOrderList,
  getTuangouExpressOptions,
  sendTuangouOrderExpress,
  getTuangouOrderTrace,
  closeTuangouOrder,
  ispayTuangouOrder,
  hexiaoTuangouOrder,
  deleteTuangouOrder,
  batchSendTuangouOrder,
  ewaybillPrepare,
  ewaybillAdd,
  getTuangouOrderDetail,
  exportTuangouOrder,
} from '../../api/tuangou'

interface Row {
  id: number
  ordernum: string
  proname: string
  propic: string
  num: number
  sell_price: number
  product_price: number
  totalprice: number
  refund_money: number
  freight_type: number
  freight_type_text: string
  platform_text: string
  paytype: string
  paytype_text: string
  status: number
  status_text: string
  refund_status: number
  refund_text: string
  linkman: string
  tel: string
  address: string
  express_com: string
  express_no: string
  createtime: number
  buyer?: { mid: number; nickname: string; headimg: string }
}

const loading = ref(false)
const list = ref<Row[]>([])
const page = ref(1)
const pagesize = ref(10)
const total = ref(0)

const query = ref({
  ordernum: '',
  linkman: '',
  tel: '',
  ctimeRange: [] as string[],
  status: '',
})
const sort = ref({ sortfield: 'id', sortorder: 'desc' })

function isExpress(row: Row) { return !inArray(row.freight_type, [1, 3, 4]) }
function inArray(n: number, arr: number[]) { return arr.includes(n) }

function statusTag(row: Row) {
  if (row.status === 1) return 'success'
  if (row.status === 0) return 'warning'
  if (row.status === 2) return 'primary'
  if (row.status === 3) return ''
  if (row.status === 4) return 'info'
  return ''
}

function fmtTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

async function load() {
  loading.value = true
  try {
    const [st, et] = query.value.ctimeRange || []
    const res = await getTuangouOrderList({
      page: page.value,
      pagesize: pagesize.value,
      status: query.value.status,
      ordernum: query.value.ordernum,
      linkman: query.value.linkman,
      tel: query.value.tel,
      starttime: st || '',
      endtime: et || '',
      sortfield: sort.value.sortfield,
      sortorder: sort.value.sortorder,
    })
    list.value = res.list || []
    total.value = res.total || 0
  } finally { loading.value = false }
}
function reload() { page.value = 1; load() }
function onSortChange({ prop, order }: { prop: string; order: string | null }) {
  if (!order) { sort.value = { sortfield: 'id', sortorder: 'desc' } }
  else {
    sort.value = {
      sortfield: prop === 'ordernum' ? 'ordernum' : prop === 'id' ? 'id' : 'createtime',
      sortorder: order === 'ascending' ? 'asc' : 'desc',
    }
  }
  load()
}

// 工具栏：导出 / 批量发货
async function doExport() {
  const [st, et] = query.value.ctimeRange || []
  const blob: any = await exportTuangouOrder({ status: query.value.status, starttime: st || '', endtime: et || '' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = '团购订单_' + Date.now() + '.xlsx'
  a.click()
  URL.revokeObjectURL(url)
  ElMessage.success('已导出')
}

// 行操作：查看详情
const detailVisible = ref(false)
const detail = ref<Row | null>(null)
async function openDetail(row: Row) {
  detail.value = (await getTuangouOrderDetail(row.id)) as unknown as Row
  detailVisible.value = true
}

async function doDelete(row: Row) {
  await ElMessageBox.confirm('确定删除该订单？', '提示', { type: 'warning' })
  await deleteTuangouOrder([row.id])
  ElMessage.success('删除成功')
  load()
}
async function doClose(row: Row) {
  await ElMessageBox.confirm('确定关闭该订单？', '提示', { type: 'warning' })
  await closeTuangouOrder(row.id)
  ElMessage.success('订单已关闭')
  load()
}
async function doIspay(row: Row) {
  await ElMessageBox.confirm('确定将该订单改为已支付？', '提示', { type: 'warning' })
  await ispayTuangouOrder(row.id)
  ElMessage.success('操作成功')
  load()
}
async function doHexiao(row: Row) {
  await ElMessageBox.confirm('确定要核销并改为已完成状态吗?', '核销确认', { type: 'warning' })
  await hexiaoTuangouOrder(row.id)
  ElMessage.success('核销成功')
  load()
}

// 发货方式小弹窗
const sendWayVisible = ref(false)
const currentRow = ref<Row | null>(null)
function openSendWay(row: Row) { currentRow.value = row; sendWayVisible.value = true }

// 手动发货
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
  await sendTuangouOrderExpress(currentRow.value!.id, manualForm.value.express_com, manualForm.value.express_no)
  ElMessage.success('发货成功')
  manualVisible.value = false
  load()
}

// 电子面单
const ewaybillVisible = ref(false)
const ewbOptions = ref<{ code: string; name: string }[]>([])
const ewb = ref<any>({
  ordernum: '', ordercode: '', delivery_info: '', notify: false,
  quantity: 1, weight: 1, volume: 0.001, insured: false, remark: '',
  receiver_name: '', receiver_mobile: '', receiver_region: '', receiver_address: '', receiver_tel: '', receiver_company: '', receiver_post_code: '',
  sender_name: '', sender_mobile: '', sender_region: '', sender_address: '', sender_tel: '', sender_company: '', sender_post_code: '',
})
async function useEwaybill() {
  sendWayVisible.value = false
  const row = currentRow.value!
  const p = await ewaybillPrepare('tuangou', row.id)
  ewbOptions.value = p.express_options || []
  ewb.value = {
    ordernum: row.ordernum,
    ordercode: p.ordercode,
    delivery_info: p.express_options?.[0]?.code || '',
    notify: false,
    quantity: 1, weight: 1, volume: 0.001, insured: false, remark: '',
    receiver_name: row.linkman,
    receiver_mobile: row.tel,
    receiver_region: '',
    receiver_address: row.address,
    receiver_tel: '', receiver_company: '', receiver_post_code: '',
    sender_name: p.last_order?.sender_name || '',
    sender_mobile: p.last_order?.sender_mobile || '',
    sender_region: (p.last_order?.sender_province || '') + (p.last_order?.sender_city || '') + (p.last_order?.sender_area || ''),
    sender_address: p.last_order?.sender_address || '',
    sender_tel: p.last_order?.sender_tel || '',
    sender_company: p.last_order?.sender_company || '',
    sender_post_code: p.last_order?.sender_post_code || '',
  }
  ewaybillVisible.value = true
}
async function submitEwaybill() {
  const row = currentRow.value!
  const [rc, ra] = ['', ''] // 地区三段合并为整串
  await ewaybillAdd({
    delivery_info: ewb.value.delivery_info,
    ordercode: ewb.value.ordercode,
    ordernum: ewb.value.ordernum,
    ordertype: 'tuangou',
    orderid: row.id,
    quantity: ewb.value.quantity,
    weight: ewb.value.weight,
    volume: ewb.value.volume,
    remark: ewb.value.remark,
    receiver_name: ewb.value.receiver_name,
    receiver_mobile: ewb.value.receiver_mobile,
    receiver_province: ewb.value.receiver_region,
    receiver_city: rc,
    receiver_area: ra,
    receiver_address: ewb.value.receiver_address,
    receiver_tel: ewb.value.receiver_tel,
    receiver_company: ewb.value.receiver_company,
    receiver_post_code: ewb.value.receiver_post_code,
    sender_name: ewb.value.sender_name,
    sender_mobile: ewb.value.sender_mobile,
    sender_province: ewb.value.sender_region,
    sender_city: rc,
    sender_area: ra,
    sender_address: ewb.value.sender_address,
    sender_tel: ewb.value.sender_tel,
    sender_company: ewb.value.sender_company,
    sender_post_code: ewb.value.sender_post_code,
  })
  ElMessage.success('发货成功')
  ewaybillVisible.value = false
  load()
}

// 批量发货
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
  const res: any = await batchSendTuangouOrder(batchForm.value.express_com, batchForm.value.file!)
  ElMessage.success(res?.msg || '批量发货完成')
  batchVisible.value = false
  load()
}

// 查物流
const traceVisible = ref(false)
const trace = ref<{ express_com: string; express_no: string; traces: { time: string; station: string }[] }>({ express_com: '', express_no: '', traces: [] })
async function openTrace(row: Row) {
  trace.value = await getTuangouOrderTrace(row.id)
  traceVisible.value = true
}

onMounted(async () => {
  try { expressOptions.value = await getTuangouExpressOptions() } catch { expressOptions.value = [] }
  load()
})
</script>

<style scoped>
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px; flex-wrap: nowrap; gap: 8px; }
.toolbar-left { display: flex; gap: 8px; flex-shrink: 0; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.toolbar-right .el-input { width: 130px; }
.order-table { width: 100%; }
.order-table :deep(th) { background: #e6e6e6; }
.goods-cell { display: flex; gap: 8px; align-items: center; }
.goods-img { width: 40px; height: 40px; border-radius: 4px; flex-shrink: 0; }
.goods-name { font-size: 12px; line-height: 1.3; }
.goods-price { color: #f60; font-size: 12px; }
.muted { color: #999; font-size: 12px; }
.refund { color: #f56c6c; font-size: 12px; }
.refund-tag { color: #f56c6c; font-size: 12px; }
.buyer-cell { display: flex; align-items: center; gap: 4px; font-size: 12px; }
.ordernum { font-size: 12px; }
.time { color: #999; font-size: 12px; }
.ops { display: flex; flex-wrap: nowrap; gap: 2px; white-space: nowrap; }
.pager { margin-top: 10px; justify-content: flex-end; }
.way-tip { margin: 0 0 10px; color: #333; }
.ewb-hint { color: #999; font-size: 12px; margin: 4px 0 10px 110px; }
</style>
