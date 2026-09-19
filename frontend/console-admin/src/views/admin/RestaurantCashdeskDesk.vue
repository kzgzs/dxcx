<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type {
  CashdeskOrderDetail,
  CashdeskOrderRow,
  DeskProduct,
  DeskTableItem,
  JialiaoItem,
  PayTypeOption,
  ProductCategory,
} from '../../api/restaurantCashdesk'
import {
  addGoods,
  changeNum,
  changePrice,
  fetchCategories,
  fetchDesk,
  fetchOrderDetail,
  fetchOrderList,
  fetchPayList,
  fetchProducts,
  openTable,
  payOrder,
  refundGoods,
  reverseAudit,
  reversePay,
  setRemoveZero,
} from '../../api/restaurantCashdesk'

// ============ 桌台宫格 ============
const deskLoading = ref(false)
const areas = ref<{ id: number; name: string }[]>([])
const tables = ref<DeskTableItem[]>([])
const summary = ref({ free: 0, busy: 0, clean: 0, booked: 0 })
const areaFilter = ref(0)

const filteredTables = computed(() =>
  areaFilter.value > 0 ? tables.value.filter((t) => t.cid === areaFilter.value) : tables.value,
)

async function loadDesk() {
  deskLoading.value = true
  try {
    const data = await fetchDesk()
    areas.value = data.areas
    tables.value = data.tables
    summary.value = data.summary
  } catch {
    // 错误提示由拦截器弹出
  } finally {
    deskLoading.value = false
  }
}

function tableClass(t: DeskTableItem): string {
  switch (t.status) {
    case 2:
      return 'busy'
    case 3:
      return 'clean'
    case 1:
      return 'booked'
    default:
      return 'free'
  }
}

// ============ 开台 ============
async function handleTableClick(t: DeskTableItem) {
  if (t.status === -1) {
    ElMessage.warning('该桌台已禁用')
    return
  }
  if (t.orderid > 0 && t.order) {
    await loadOrder(t.orderid)
    return
  }
  if (t.status === 2) {
    ElMessage.warning('该桌台正在使用中')
    return
  }
  try {
    await ElMessageBox.confirm(`确定为「${t.name}」开台？`, '开台确认', { type: 'info' })
  } catch {
    return
  }
  try {
    const result = await openTable(t.id)
    ElMessage.success(result.reopened ? '该桌台已有进行中订单' : '开台成功')
    await loadOrder(result.order_id)
    await loadDesk()
  } catch {
    // 拦截器已提示
  }
}

// ============ 订单面板 ============
const currentOrder = ref<CashdeskOrderDetail | null>(null)
const orderLoading = ref(false)

async function loadOrder(orderId: number) {
  orderLoading.value = true
  try {
    currentOrder.value = await fetchOrderDetail(orderId)
  } catch {
    currentOrder.value = null
  } finally {
    orderLoading.value = false
  }
}

function closeOrderPanel() {
  currentOrder.value = null
}

const editable = computed(() => currentOrder.value?.status === 0)

async function applyDetail(detail: CashdeskOrderDetail) {
  currentOrder.value = detail
}

// ============ 数量增减 ============
async function onNumChange(goodsId: number, num: number) {
  if (!currentOrder.value) return
  try {
    applyDetail(await changeNum(currentOrder.value.id, goodsId, Math.max(0, num)))
  } catch {
    await loadOrder(currentOrder.value.id)
  }
}

// ============ 单品改价 ============
const priceDialog = reactive({ visible: false, goodsId: 0, name: '', price: '' })

function openPriceDialog(goodsId: number, name: string, price: number) {
  priceDialog.goodsId = goodsId
  priceDialog.name = name
  priceDialog.price = String(price)
  priceDialog.visible = true
}

async function submitPrice() {
  if (!currentOrder.value) return
  const price = Number(priceDialog.price)
  if (!Number.isFinite(price) || price < 0) {
    ElMessage.warning('请输入有效的价格')
    return
  }
  try {
    applyDetail(await changePrice(currentOrder.value.id, priceDialog.goodsId, price))
    priceDialog.visible = false
  } catch {
    // 拦截器已提示
  }
}

// ============ 退菜 ============
const refundDialog = reactive({ visible: false, goodsId: 0, name: '', num: 1, max: 1 })

function openRefundDialog(goodsId: number, name: string, num: number) {
  refundDialog.goodsId = goodsId
  refundDialog.name = name
  refundDialog.num = 1
  refundDialog.max = num
  refundDialog.visible = true
}

async function submitRefund() {
  if (!currentOrder.value) return
  try {
    applyDetail(await refundGoods(currentOrder.value.id, refundDialog.goodsId, refundDialog.num))
    refundDialog.visible = false
    ElMessage.success('退菜成功')
  } catch {
    // 拦截器已提示
  }
}

// ============ 抹零 ============
async function onRemoveZero(length: number) {
  if (!currentOrder.value) return
  try {
    applyDetail(await setRemoveZero(currentOrder.value.id, length))
  } catch {
    await loadOrder(currentOrder.value.id)
  }
}

// ============ 点餐/加菜 ============
const addDrawer = reactive({ visible: false, keyword: '', categoryId: 0 })
const categories = ref<ProductCategory[]>([])
const products = ref<DeskProduct[]>([])
const productsLoading = ref(false)

async function openAddDrawer() {
  if (!currentOrder.value) {
    ElMessage.warning('请先开台')
    return
  }
  addDrawer.visible = true
  if (categories.value.length === 0) {
    try {
      const data = await fetchCategories()
      categories.value = data.list
    } catch {
      // 拦截器已提示
    }
  }
  await loadProducts()
}

async function loadProducts() {
  productsLoading.value = true
  try {
    const data = await fetchProducts(addDrawer.keyword, addDrawer.categoryId)
    products.value = data.list
  } catch {
    // 拦截器已提示
  } finally {
    productsLoading.value = false
  }
}

// 加料选择
const jialiaoDialog = reactive({ visible: false, product: null as DeskProduct | null, selected: [] as number[] })

function pickProduct(p: DeskProduct) {
  if (p.jialiao.length > 0) {
    jialiaoDialog.product = p
    jialiaoDialog.selected = []
    jialiaoDialog.visible = true
  } else {
    void doAddGoods(p, [])
  }
}

function toggleJialiao(item: JialiaoItem) {
  const idx = jialiaoDialog.selected.indexOf(item.id)
  if (idx >= 0) {
    jialiaoDialog.selected.splice(idx, 1)
  } else {
    jialiaoDialog.selected.push(item.id)
  }
}

const jialiaoExtra = computed(() => {
  if (!jialiaoDialog.product) return 0
  return jialiaoDialog.product.jialiao
    .filter((j) => jialiaoDialog.selected.includes(j.id))
    .reduce((sum, j) => sum + j.price, 0)
})

async function confirmJialiao() {
  if (!jialiaoDialog.product) return
  await doAddGoods(jialiaoDialog.product, [...jialiaoDialog.selected])
  jialiaoDialog.visible = false
}

async function doAddGoods(p: DeskProduct, jialiaoIds: number[]) {
  if (!currentOrder.value) return
  try {
    applyDetail(await addGoods(currentOrder.value.id, [{ product_id: p.id, num: 1, jialiao_ids: jialiaoIds }]))
    ElMessage.success(`已加入「${p.name}」`)
  } catch {
    // 拦截器已提示
  }
}

// ============ 结账 ============
const payDialog = reactive({ visible: false, paytype: '', list: [] as PayTypeOption[], paying: false })

async function openPayDialog() {
  if (!currentOrder.value) return
  if (currentOrder.value.goods.length === 0) {
    ElMessage.warning('订单无菜品，请先点餐')
    return
  }
  try {
    const data = await fetchPayList()
    payDialog.list = data.list
    payDialog.paytype = data.list.length > 0 ? data.list[0].value : ''
    payDialog.visible = true
  } catch {
    // 拦截器已提示
  }
}

async function submitPay() {
  if (!currentOrder.value) return
  if ('' === payDialog.paytype) {
    ElMessage.warning('请选择支付方式')
    return
  }
  payDialog.paying = true
  try {
    const detail = await payOrder(currentOrder.value.id, Number(payDialog.paytype))
    payDialog.visible = false
    ElMessage.success(`结账成功，实收 ¥${detail.pay_amount.toFixed(2)}`)
    currentOrder.value = null
    await loadDesk()
  } catch {
    // 拦截器已提示
  } finally {
    payDialog.paying = false
  }
}

// ============ 收银订单列表 ============
const activeTab = ref('desk')
const orderQuery = reactive({ status: '', range: null as [string, string] | null, page: 1, pagesize: 20 })
const orderRows = ref<CashdeskOrderRow[]>([])
const orderTotal = ref(0)
const orderListLoading = ref(false)

async function loadOrderList() {
  orderListLoading.value = true
  try {
    const data = await fetchOrderList({
      status: orderQuery.status,
      start_time: orderQuery.range?.[0] || '',
      end_time: orderQuery.range?.[1] || '',
      page: orderQuery.page,
      pagesize: orderQuery.pagesize,
    })
    orderRows.value = data.list
    orderTotal.value = data.total
  } catch {
    // 拦截器已提示
  } finally {
    orderListLoading.value = false
  }
}

function onTabChange(tab: string | number) {
  if ('orders' === tab) {
    void loadOrderList()
  }
}

function fmtTime(ts: number): string {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

// ============ 反结账 ============
async function handleReverse(row: CashdeskOrderRow) {
  try {
    await ElMessageBox.confirm(`确定对订单 ${row.ordernum} 执行反结账？订单将撤销回进行中。`, '反结账确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    const result = await reversePay(row.id)
    ElMessage.success(result.audited ? '反结账申请已提交，待审核' : '反结账成功')
    await loadOrderList()
    await loadDesk()
  } catch {
    // 拦截器已提示
  }
}

async function handleAudit(row: CashdeskOrderRow, pass: boolean) {
  try {
    await reverseAudit(row.id, pass)
    ElMessage.success(pass ? '审核通过，订单已撤销回进行中' : '已驳回')
    await loadOrderList()
    await loadDesk()
  } catch {
    // 拦截器已提示
  }
}

onMounted(loadDesk)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">餐饮收银台</span>
        <span class="summary-tip">
          空闲 {{ summary.free }} · 就餐中 {{ summary.busy }} · 待清台 {{ summary.clean }} · 预定 {{ summary.booked }}
        </span>
      </div>
    </template>

    <el-tabs :model-value="activeTab" @update:model-value="(v: string | number) => { activeTab = String(v); onTabChange(v) }">
      <!-- 收银操作 -->
      <el-tab-pane label="收银操作" name="desk">
        <div class="desk-layout">
          <!-- 左侧桌台宫格 -->
          <div v-loading="deskLoading" class="desk-grid-wrap">
            <div class="area-filter">
              <el-radio-group v-model="areaFilter" size="small" @change="() => {}">
                <el-radio-button :value="0">全部</el-radio-button>
                <el-radio-button v-for="a in areas" :key="a.id" :value="a.id">{{ a.name }}</el-radio-button>
              </el-radio-group>
              <el-button size="small" style="margin-left: 8px" @click="loadDesk">刷新</el-button>
            </div>
            <div class="desk-grid">
              <div
                v-for="t in filteredTables"
                :key="t.id"
                class="table-cell"
                :class="tableClass(t)"
                @click="handleTableClick(t)"
              >
                <div class="table-name">{{ t.name }}</div>
                <div class="table-sub">{{ t.seat }}人 · {{ t.status_text }}</div>
                <div v-if="t.order" class="table-amount">¥{{ t.order.totalprice.toFixed(2) }}</div>
              </div>
              <el-empty v-if="filteredTables.length === 0" description="暂无桌台，请先在餐桌管理添加" />
            </div>
          </div>

          <!-- 右侧订单面板 -->
          <div v-loading="orderLoading" class="order-panel">
            <template v-if="currentOrder">
              <div class="order-head">
                <div>
                  <div class="order-title">
                    {{ currentOrder.table_name || '吧台' }}
                    <el-tag size="small" :type="currentOrder.status === 0 ? 'warning' : 'success'">
                      {{ currentOrder.status_text }}
                    </el-tag>
                  </div>
                  <div class="order-sub">{{ currentOrder.ordernum }} · {{ fmtTime(currentOrder.createtime) }}</div>
                </div>
                <div>
                  <el-button v-if="editable" size="small" type="primary" @click="openAddDrawer">点餐/加菜</el-button>
                  <el-button size="small" @click="closeOrderPanel">收起</el-button>
                </div>
              </div>

              <el-table :data="currentOrder.goods" size="small" border>
                <el-table-column prop="name" label="菜品" min-width="140">
                  <template #default="{ row }">
                    <div>{{ row.name }}<el-tag v-if="row.is_gj === 1" size="small" type="warning" style="margin-left: 4px">改价</el-tag></div>
                    <div v-if="row.njltitle" class="goods-jialiao">加料：{{ row.njltitle }}</div>
                  </template>
                </el-table-column>
                <el-table-column label="数量" width="130">
                  <template #default="{ row }">
                    <el-input-number
                      v-if="editable"
                      :model-value="row.num"
                      :min="0"
                      :max="999"
                      size="small"
                      controls-position="right"
                      style="width: 100px"
                      @change="(v: number | undefined) => onNumChange(row.id, v ?? 0)"
                    />
                    <span v-else>{{ row.num }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="单价" width="90" align="right">
                  <template #default="{ row }">¥{{ row.sell_price.toFixed(2) }}</template>
                </el-table-column>
                <el-table-column label="小计" width="90" align="right">
                  <template #default="{ row }">¥{{ row.totalprice.toFixed(2) }}</template>
                </el-table-column>
                <el-table-column v-if="editable" label="操作" width="120">
                  <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="openPriceDialog(row.id, row.name, row.sell_price)">改价</el-button>
                    <el-button link type="danger" size="small" @click="openRefundDialog(row.id, row.name, row.num)">退菜</el-button>
                  </template>
                </el-table-column>
              </el-table>

              <div class="order-summary">
                <div class="summary-row">
                  <span>菜品合计：¥{{ currentOrder.product_price.toFixed(2) }}</span>
                  <span v-if="currentOrder.service_money > 0">服务费/低消补足：¥{{ currentOrder.service_money.toFixed(2) }}</span>
                </div>
                <div class="summary-row">
                  <span>
                    抹零：
                    <el-select
                      v-if="editable"
                      :model-value="currentOrder.remove_zero_length"
                      size="small"
                      style="width: 110px"
                      @change="(v: number) => onRemoveZero(v)"
                    >
                      <el-option :value="0" label="不抹零" />
                      <el-option :value="1" label="抹分" />
                      <el-option :value="2" label="抹角" />
                      <el-option :value="3" label="抹元" />
                    </el-select>
                    <template v-else>
                      {{ currentOrder.remove_zero_length > 0 ? ['', '抹分', '抹角', '抹元'][currentOrder.remove_zero_length] : '不抹零' }}
                    </template>
                  </span>
                  <span>抹零金额：-¥{{ currentOrder.moling_money.toFixed(2) }}</span>
                </div>
                <div class="summary-total">
                  应付：<b>¥{{ currentOrder.totalprice.toFixed(2) }}</b>
                  <el-button v-if="editable" type="primary" style="margin-left: 16px" @click="openPayDialog">结账</el-button>
                </div>
              </div>
            </template>
            <el-empty v-else description="点击左侧桌台开台或查看订单" />
          </div>
        </div>
      </el-tab-pane>

      <!-- 收银订单 -->
      <el-tab-pane label="收银订单" name="orders">
        <div class="order-filter">
          <el-select v-model="orderQuery.status" placeholder="订单状态" clearable style="width: 130px" @change="orderQuery.page = 1; loadOrderList()">
            <el-option value="" label="全部" />
            <el-option value="0" label="进行中" />
            <el-option value="2" label="已完成" />
            <el-option value="4" label="已关闭" />
          </el-select>
          <el-date-picker
            v-model="orderQuery.range"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            style="margin-left: 8px"
            @change="orderQuery.page = 1; loadOrderList()"
          />
          <el-button style="margin-left: 8px" type="primary" @click="orderQuery.page = 1; loadOrderList()">查询</el-button>
        </div>

        <el-table v-loading="orderListLoading" :data="orderRows" border style="margin-top: 12px">
          <el-table-column prop="ordernum" label="订单号" min-width="200" />
          <el-table-column prop="table_name" label="桌台" width="100" />
          <el-table-column prop="status_text" label="状态" width="90">
            <template #default="{ row }">
              <el-tag size="small" :type="row.status === 2 ? 'success' : row.status === 0 ? 'warning' : 'info'">
                {{ row.status_text }}
              </el-tag>
              <el-tag v-if="row.refund_status === 1" size="small" type="danger" style="margin-left: 4px">反结账审核中</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="金额" width="100" align="right">
            <template #default="{ row }">¥{{ row.pay_amount.toFixed(2) }}</template>
          </el-table-column>
          <el-table-column prop="paytype_text" label="支付方式" width="110" />
          <el-table-column label="下单时间" width="150">
            <template #default="{ row }">{{ fmtTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="loadOrder(row.id); activeTab = 'desk'">详情</el-button>
              <el-button v-if="row.status === 2 && row.refund_status === 0" link type="danger" size="small" @click="handleReverse(row)">反结账</el-button>
              <template v-if="row.status === 2 && row.refund_status === 1">
                <el-button link type="success" size="small" @click="handleAudit(row, true)">通过</el-button>
                <el-button link type="warning" size="small" @click="handleAudit(row, false)">驳回</el-button>
              </template>
            </template>
          </el-table-column>
        </el-table>

        <el-pagination
          v-model:current-page="orderQuery.page"
          :total="orderTotal"
          :page-size="orderQuery.pagesize"
          layout="total, prev, pager, next"
          style="margin-top: 12px"
          @current-change="loadOrderList"
        />
      </el-tab-pane>
    </el-tabs>

    <!-- 点餐抽屉 -->
    <el-drawer v-model="addDrawer.visible" title="点餐/加菜" size="46%">
      <div class="product-filter">
        <el-select v-model="addDrawer.categoryId" placeholder="全部分类" clearable style="width: 150px" @change="loadProducts">
          <el-option v-for="c in categories" :key="c.id" :value="c.id" :label="c.name" />
        </el-select>
        <el-input
          v-model="addDrawer.keyword"
          placeholder="搜索菜品"
          clearable
          style="width: 200px; margin-left: 8px"
          @keyup.enter="loadProducts"
          @clear="loadProducts"
        />
        <el-button style="margin-left: 8px" type="primary" @click="loadProducts">查询</el-button>
      </div>
      <div v-loading="productsLoading" class="product-grid">
        <div v-for="p in products" :key="p.id" class="product-cell" @click="pickProduct(p)">
          <div class="product-name">{{ p.name }}</div>
          <div class="product-price">¥{{ p.sell_price.toFixed(2) }}</div>
          <div v-if="p.jialiao.length > 0" class="product-jialiao">含加料 {{ p.jialiao.length }} 项</div>
        </div>
        <el-empty v-if="products.length === 0" description="暂无可点菜品" />
      </div>
    </el-drawer>

    <!-- 加料选择 -->
    <el-dialog v-model="jialiaoDialog.visible" :title="`选择加料 - ${jialiaoDialog.product?.name || ''}`" width="420px">
      <div v-if="jialiaoDialog.product">
        <el-checkbox-group :model-value="jialiaoDialog.selected" style="display: flex; flex-direction: column; gap: 8px">
          <el-checkbox
            v-for="j in jialiaoDialog.product.jialiao"
            :key="j.id"
            :value="j.id"
            @change="toggleJialiao(j)"
          >
            {{ j.title }}（+¥{{ j.price.toFixed(2) }}）
          </el-checkbox>
        </el-checkbox-group>
      </div>
      <template #footer>
        <span style="margin-right: 12px; color: #f56c6c">加料合计：¥{{ jialiaoExtra.toFixed(2) }}</span>
        <el-button @click="jialiaoDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="confirmJialiao">加入订单</el-button>
      </template>
    </el-dialog>

    <!-- 单品改价 -->
    <el-dialog v-model="priceDialog.visible" :title="`改价 - ${priceDialog.name}`" width="380px">
      <el-input v-model="priceDialog.price" placeholder="新单价" type="number">
        <template #prepend>¥</template>
      </el-input>
      <template #footer>
        <el-button @click="priceDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="submitPrice">确定</el-button>
      </template>
    </el-dialog>

    <!-- 退菜 -->
    <el-dialog v-model="refundDialog.visible" :title="`退菜 - ${refundDialog.name}`" width="380px">
      <el-input-number v-model="refundDialog.num" :min="1" :max="refundDialog.max" />
      <template #footer>
        <el-button @click="refundDialog.visible = false">取消</el-button>
        <el-button type="danger" @click="submitRefund">确认退菜</el-button>
      </template>
    </el-dialog>

    <!-- 结账 -->
    <el-dialog v-model="payDialog.visible" title="结账" width="440px">
      <div class="pay-amount">应付金额：<b>¥{{ (currentOrder?.totalprice || 0).toFixed(2) }}</b></div>
      <el-radio-group v-model="payDialog.paytype" style="display: flex; flex-direction: column; gap: 10px; margin-top: 12px">
        <el-radio v-for="p in payDialog.list" :key="p.value" :value="p.value" border>
          {{ p.lable }}
          <span v-if="p.tip" class="pay-tip">{{ p.tip }}</span>
        </el-radio>
      </el-radio-group>
      <el-empty v-if="payDialog.list.length === 0" description="无可用支付方式，请先在收银设置开启" />
      <template #footer>
        <el-button @click="payDialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="payDialog.paying" :disabled="payDialog.list.length === 0" @click="submitPay">确认收款</el-button>
      </template>
    </el-dialog>
  </el-card>
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

.summary-tip {
  color: #909399;
  font-size: 13px;
}

.desk-layout {
  display: flex;
  gap: 16px;
}

.desk-grid-wrap {
  flex: 1;
  min-width: 0;
}

.area-filter {
  margin-bottom: 12px;
  display: flex;
  align-items: center;
}

.desk-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 12px;
}

.table-cell {
  border-radius: 8px;
  padding: 12px;
  cursor: pointer;
  border: 1px solid #e4e7ed;
  background: #f0f9eb;
  transition: box-shadow 0.15s;
}

.table-cell:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}

.table-cell.busy {
  background: #fef0f0;
  border-color: #fab6b6;
}

.table-cell.clean {
  background: #fdf6ec;
  border-color: #f5dab1;
}

.table-cell.booked {
  background: #ecf5ff;
  border-color: #b3d8ff;
}

.table-name {
  font-weight: 600;
  font-size: 15px;
}

.table-sub {
  color: #909399;
  font-size: 12px;
  margin-top: 4px;
}

.table-amount {
  margin-top: 6px;
  color: #f56c6c;
  font-weight: 600;
}

.order-panel {
  width: 520px;
  flex-shrink: 0;
  border: 1px solid #e4e7ed;
  border-radius: 8px;
  padding: 12px;
}

.order-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 10px;
}

.order-title {
  font-weight: 600;
  font-size: 16px;
}

.order-sub {
  color: #909399;
  font-size: 12px;
  margin-top: 4px;
}

.goods-jialiao {
  color: #e6a23c;
  font-size: 12px;
}

.order-summary {
  margin-top: 12px;
  border-top: 1px dashed #e4e7ed;
  padding-top: 10px;
}

.summary-row {
  display: flex;
  justify-content: space-between;
  color: #606266;
  font-size: 13px;
  margin-bottom: 8px;
}

.summary-total {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  font-size: 15px;
}

.summary-total b {
  color: #f56c6c;
  font-size: 20px;
  margin-left: 4px;
}

.order-filter {
  display: flex;
  align-items: center;
}

.product-filter {
  display: flex;
  align-items: center;
  margin-bottom: 12px;
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 10px;
}

.product-cell {
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  padding: 10px;
  cursor: pointer;
}

.product-cell:hover {
  border-color: #409eff;
}

.product-name {
  font-weight: 600;
}

.product-price {
  color: #f56c6c;
  margin-top: 4px;
}

.product-jialiao {
  color: #909399;
  font-size: 12px;
  margin-top: 2px;
}

.pay-amount {
  font-size: 16px;
}

.pay-amount b {
  color: #f56c6c;
  font-size: 22px;
}

.pay-tip {
  color: #909399;
  font-size: 12px;
  margin-left: 6px;
}
</style>
