<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  checkKanjiaOrderRefund,
  closeKanjiaOrder,
  deleteKanjiaOrder,
  exportKanjiaOrder,
  getKanjiaOrderDetail,
  getKanjiaOrderList,
  hexiaoKanjiaOrder,
  ispayKanjiaOrder,
  shipKanjiaOrder,
} from '../../api/kanjia'
import { formatTime } from '../../utils/time'

interface OrderRow {
  id: number
  ordernum: string
  proname: string
  propic: string
  sell_price: number | string
  product_price: number | string
  totalprice: number | string
  linkman: string
  tel: string
  area: string
  address: string
  platform: string
  paytype: string
  freight_text: string
  freight_type: number
  status: number
  status_text: string
  refund_status: number
  refund_money: number | string
  refund_reason: string
  refund_text: string
  createtime: number
  paytime: number
  send_time: number
  collect_time: number
  express_com: string
  express_no: string
  remark: string
  mid: number
  nickname: string
  headimg: string
}

// 快递公司下拉（对齐点大发货弹窗）
const EXPRESS_COMS = ['EMS', '顺丰速运', '中通快递', '圆通速递', '韵达快递', '申通快递', '百世快递', '京东物流', '德邦快递', '邮政快递包裹']

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
    const data = await getKanjiaOrderList({
      page: query.page,
      pagesize: query.pagesize,
      status: query.status || undefined,
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

function handleSearch() {
  query.page = 1
  fetchList()
}
function handlePageChange(p: number) {
  query.page = p
  fetchList()
}
function handleSizeChange(s: number) {
  query.pagesize = s
  query.page = 1
  fetchList()
}

async function handleExport() {
  try {
    await exportKanjiaOrder({
      status: query.status || undefined,
      ordernum: query.ordernum || undefined,
      linkman: query.linkman || undefined,
      tel: query.tel || undefined,
      start_date: query.dateRange ? query.dateRange[0] : undefined,
      end_date: query.dateRange ? query.dateRange[1] : undefined,
    })
  } catch {
    /* 错误已由拦截器弹出 */
  }
}

// 批量发货：勾选后统一弹窗发货（对齐点大「批量发货」按钮入口）
function handleBatchShip() {
  ElMessage.info('请在订单行内逐单发货')
}

function fmtTs(ts: number) {
  return ts ? formatTime(ts) : '-'
}
function money(v: number | string) {
  return '￥' + Number(v || 0).toFixed(2)
}
// 状态文案：后端 status=3 返回「已收货」，对齐点大显示「已完成」
function statusText(row: OrderRow) {
  if (row.status === 3) return '已完成'
  return row.status_text
}
function statusColor(row: OrderRow) {
  switch (row.status) {
    case 1: return '#ffc702'
    case 2: return '#ff4246'
    case 3: return '#999999'
    case 4: return '#bbbbbb'
    default: return '#ff8758'
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
    detail.value = (await getKanjiaOrderDetail(row.id)) as unknown as { order: OrderRow; member: Record<string, any> }
  } catch {
    /* 错误已由拦截器弹出 */
  } finally {
    detailLoading.value = false
  }
}

// ============ 删除 ============
function handleDelete(row: OrderRow) {
  ElMessageBox.confirm(`确定要删除砍价订单「${row.ordernum}」吗？删除后无法恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await deleteKanjiaOrder(row.id)
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

// ============ 退款审核 ============
function handleRefundPass(row: OrderRow) {
  ElMessageBox.confirm(
    `同意退款「${row.ordernum}」将退回 ￥${Number(row.refund_money || row.totalprice).toFixed(2)}，确定吗？`,
    '退款确认',
    { confirmButtonText: '同意并退款', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      await checkKanjiaOrderRefund(row.id, 1)
      ElMessage.success('已同意并退款')
      fetchList()
    })
    .catch(() => {})
}
function handleRefundReject(row: OrderRow) {
  ElMessageBox.prompt('请输入驳回原因', '驳回退款', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '请填写驳回原因',
    type: 'warning',
  })
    .then(async ({ value }) => {
      await checkKanjiaOrderRefund(row.id, 2, (value || '').trim())
      ElMessage.success('已驳回')
      fetchList()
    })
    .catch(() => {})
}

// ============ 关单 / 改为已支付 ============
function handleClose(row: OrderRow) {
  ElMessageBox.confirm(`确定要关闭订单「${row.ordernum}」吗？`, '关单确认', {
    confirmButtonText: '关闭订单',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await closeKanjiaOrder(row.id)
      ElMessage.success('订单已关闭')
      fetchList()
    })
    .catch(() => {})
}
function handleIspay(row: OrderRow) {
  ElMessageBox.confirm(`确定要把订单「${row.ordernum}」改为已支付吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await ispayKanjiaOrder(row.id)
      ElMessage.success('操作成功')
      fetchList()
    })
    .catch(() => {})
}

// ============ 发货 / 修改物流（小弹窗：快递公司下拉 + 快递单号） ============
const shipVisible = ref(false)
const shipRow = ref<OrderRow | null>(null)
const shipFormRef = ref<FormInstance>()
const shipSubmitting = ref(false)
const shipForm = reactive({ express_com: '', express_no: '' })
const shipRules: FormRules = {
  express_com: [{ required: true, message: '请选择快递公司', trigger: 'change' }],
  express_no: [{ required: true, message: '请输入快递单号', trigger: 'blur' }],
}
function openShip(row: OrderRow) {
  shipRow.value = row
  shipForm.express_com = row.express_com || ''
  shipForm.express_no = row.express_no || ''
  shipVisible.value = true
}
async function handleShipSubmit() {
  if (!shipFormRef.value || !shipRow.value) return
  await shipFormRef.value.validate(async (valid) => {
    if (!valid) return
    shipSubmitting.value = true
    try {
      await shipKanjiaOrder(shipRow.value!.id, shipForm.express_com, shipForm.express_no)
      ElMessage.success('发货成功')
      shipVisible.value = false
      logisticsVisible.value = false
      fetchList()
    } catch {
      /* 错误已由拦截器弹出 */
    } finally {
      shipSubmitting.value = false
    }
  })
}

// ============ 查物流（小弹窗：时间线 + 修改/关闭） ============
const logisticsVisible = ref(false)
const logisticsRow = ref<OrderRow | null>(null)
function openLogistics(row: OrderRow) {
  logisticsRow.value = row
  logisticsVisible.value = true
}
function logisticsModify() {
  // 物流弹窗下的「修改」→ 弹出发货弹窗进行修改（对齐点大）
  if (logisticsRow.value) openShip(logisticsRow.value)
}

// ============ 核销（已支付且到店自提） ============
function handleHexiao(row: OrderRow) {
  ElMessageBox.confirm('确定要核销并改为已完成状态吗?', '核销确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await hexiaoKanjiaOrder(row.id)
      ElMessage.success('核销成功')
      fetchList()
    })
    .catch(() => {})
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
          <el-input v-model="query.ordernum" placeholder="订单号" clearable style="width: 140px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="收货人">
          <el-input v-model="query.linkman" placeholder="收货人" clearable style="width: 110px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input v-model="query.tel" placeholder="手机号" clearable style="width: 120px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="下单时间">
          <el-date-picker
            v-model="query.dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="未支付" value="0" />
            <el-option label="已支付" value="1" />
            <el-option label="已发货" value="2" />
            <el-option label="已完成" value="3" />
            <el-option label="已关闭" value="4" />
            <el-option label="退款待审核" value="r1" />
            <el-option label="已退款" value="r2" />
            <el-option label="退款驳回" value="r3" />
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
        <el-table-column label="商品信息" min-width="220">
          <template #default="{ row }">
            <div class="goods-info">
              <el-image v-if="row.propic" :src="row.propic" :preview-src-list="[row.propic]" fit="cover" class="goods-img" preview-teleported />
              <div v-else class="goods-img goods-img-empty">无图</div>
              <div class="goods-meta">
                <div class="goods-name">{{ row.proname }}</div>
                <div class="goods-sub">原价：￥{{ Number(row.sell_price).toFixed(2) }}</div>
                <div class="goods-price">购买价：￥{{ Number(row.product_price ?? row.totalprice).toFixed(2) }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="订单号/下单时间" width="170">
          <template #default="{ row }">
            <div>{{ row.ordernum }}</div>
            <div class="muted">{{ formatTime(row.createtime) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="商品总价/实付款" width="130">
          <template #default="{ row }">
            <div>总价：{{ money(row.product_price ?? row.sell_price) }}</div>
            <div class="strong red">实付：{{ money(row.totalprice) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="收货地址" min-width="190">
          <template #default="{ row }">
            <div class="strong">{{ row.linkman }} {{ row.tel }}</div>
            <div class="muted">{{ row.area }} {{ row.address }}</div>
          </template>
        </el-table-column>
        <el-table-column label="头像昵称" width="110">
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
        <el-table-column label="状态" width="90" align="left">
          <template #default="{ row }">
            <div class="st" :style="{ color: statusColor(row) }">{{ statusText(row) }}</div>
            <div v-if="row.refund_status" class="refund">已退款{{ row.refund_money ? '￥' + Number(row.refund_money).toFixed(2) : '' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openDetail(row)">查看详情</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除订单</el-button>
              <!-- 退款待审核 -->
              <template v-if="row.refund_status === 1">
                <el-button link type="success" @click="handleRefundPass(row)">同意退款</el-button>
                <el-button link type="warning" @click="handleRefundReject(row)">驳回</el-button>
              </template>
              <!-- 未支付 -->
              <template v-if="row.status === 0">
                <el-button link type="warning" @click="handleClose(row)">关闭订单</el-button>
                <el-button link type="primary" @click="handleIspay(row)">改为已支付</el-button>
              </template>
              <!-- 已支付：发货 + 到店自提核销 -->
              <template v-if="row.status === 1">
                <el-button link type="primary" @click="openShip(row)">发货</el-button>
                <el-button v-if="row.freight_type === 1" link type="success" @click="handleHexiao(row)">核销</el-button>
              </template>
              <!-- 已发货 / 已完成：查物流 -->
              <template v-if="row.status === 2 || row.status === 3">
                <el-button link type="primary" @click="openLogistics(row)">查物流</el-button>
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

    <!-- 订单详情（平铺右上X） -->
    <el-dialog v-model="detailVisible" title="订单详情" width="560px">
      <div v-loading="detailLoading">
        <template v-if="detail">
          <el-descriptions :column="1" border size="small">
            <el-descriptions-item label="订单号">{{ detail.order.ordernum }}</el-descriptions-item>
            <el-descriptions-item label="商品">{{ detail.order.proname }}</el-descriptions-item>
            <el-descriptions-item label="收货地址">{{ detail.order.linkman }} {{ detail.order.tel }}<br />{{ detail.order.area }} {{ detail.order.address }}</el-descriptions-item>
            <el-descriptions-item label="下单人">{{ detail.member.nickname || `会员${detail.order.mid}` }}</el-descriptions-item>
            <el-descriptions-item label="订单金额">{{ money(detail.order.totalprice) }}</el-descriptions-item>
            <el-descriptions-item label="支付方式">{{ detail.order.paytype || '-' }}</el-descriptions-item>
            <el-descriptions-item label="配送方式">{{ detail.order.freight_text || '-' }}</el-descriptions-item>
            <el-descriptions-item label="状态">{{ statusText(detail.order as OrderRow) }}<span v-if="detail.order.refund_text">（{{ detail.order.refund_text }}）</span></el-descriptions-item>
            <el-descriptions-item v-if="detail.order.refund_reason" label="退款原因">{{ detail.order.refund_reason }}</el-descriptions-item>
            <el-descriptions-item label="下单时间">{{ fmtTs(detail.order.createtime) }}</el-descriptions-item>
            <el-descriptions-item v-if="detail.order.paytime" label="支付时间">{{ fmtTs(detail.order.paytime) }}</el-descriptions-item>
            <el-descriptions-item v-if="detail.order.send_time" label="发货时间">{{ fmtTs(detail.order.send_time) }}</el-descriptions-item>
            <el-descriptions-item v-if="detail.order.express_com" label="快递">{{ detail.order.express_com }} {{ detail.order.express_no }}</el-descriptions-item>
            <el-descriptions-item v-if="detail.order.remark" label="商家备注">{{ detail.order.remark }}</el-descriptions-item>
          </el-descriptions>
        </template>
      </div>
    </el-dialog>

    <!-- 查物流小弹窗（时间线 + 修改/关闭） -->
    <el-dialog v-model="logisticsVisible" :title="logisticsRow?.express_com || '物流信息'" width="440px">
      <el-timeline v-if="logisticsRow">
        <el-timeline-item v-if="logisticsRow.send_time" :timestamp="fmtTs(logisticsRow.send_time)" placement="top">
          已发货（{{ logisticsRow.express_com }} {{ logisticsRow.express_no }}）
        </el-timeline-item>
        <el-timeline-item v-else :timestamp="fmtTs(logisticsRow.paytime)" placement="top">
          订单已支付，待发货
        </el-timeline-item>
      </el-timeline>
      <template #footer>
        <el-button type="primary" @click="logisticsModify">修改</el-button>
        <el-button @click="logisticsVisible = false">关闭</el-button>
      </template>
    </el-dialog>

    <!-- 发货/修改物流小弹窗（快递公司下拉 + 快递单号） -->
    <el-dialog v-model="shipVisible" :title="shipRow && shipRow.status === 2 ? '修改物流' : '订单发货'" width="380px">
      <el-form ref="shipFormRef" :model="shipForm" :rules="shipRules" label-width="80px" label-position="left">
        <el-form-item label="快递公司" prop="express_com">
          <el-select v-model="shipForm.express_com" placeholder="请选择" style="width: 100%">
            <el-option v-for="c in EXPRESS_COMS" :key="c" :label="c" :value="c" />
          </el-select>
        </el-form-item>
        <el-form-item label="快递单号" prop="express_no">
          <el-input v-model="shipForm.express_no" placeholder="请输入快递单号" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="primary" :loading="shipSubmitting" @click="handleShipSubmit">确定</el-button>
        <el-button @click="shipVisible = false">取消</el-button>
      </template>
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

:deep(.el-table) { background: #fff; }
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
.refund { color: #ff4246; font-size: 12px; }

.row-ops { display: flex; flex-direction: row; flex-wrap: wrap; gap: 2px 8px; line-height: 1.6; align-items: center; }
.row-ops .el-button { margin: 0; height: auto; min-height: 0; padding: 0; font-size: 12px; }
.row-ops :deep(.el-button) { height: auto; padding: 1px 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 12px; justify-content: flex-end; }
</style>
