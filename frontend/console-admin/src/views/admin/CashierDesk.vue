<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  addCashierGoods,
  cancelHangupCashierOrder,
  cashierPay,
  cashierPayPreview,
  changeCashierNum,
  changeCashierPrice,
  changeCashierRemark,
  clearCashierCart,
  deleteCashierGoods,
  getCashierCart,
  getCashierCategories,
  getCashierHangupList,
  getCashierOrderDetail,
  getCashierProducts,
  hangupCashierOrder,
  searchCashierMember,
} from '../../api/cashier'
import type {
  CartView,
  CashierCategory,
  CashierMember,
  CashierOrderItem,
  CashierProduct,
  HangupItem,
  PayPreviewData,
} from '../../api/cashier'
import { formatTime } from '../../utils/time'

/* ============ 商品区 ============ */
const categories = ref<CashierCategory[]>([])
const activeCate = ref(0)
const keyword = ref('')
const barcodeInput = ref('')
const products = ref<CashierProduct[]>([])
const productLoading = ref(false)

async function fetchCategories() {
  try {
    const data = await getCashierCategories()
    categories.value = data?.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function fetchProducts() {
  productLoading.value = true
  try {
    const data = await getCashierProducts({
      cateid: activeCate.value || undefined,
      keyword: keyword.value || undefined,
      page: 1,
      pagesize: 100,
    })
    products.value = data?.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    productLoading.value = false
  }
}

function handleCateChange(id: number) {
  activeCate.value = activeCate.value === id ? 0 : id
  fetchProducts()
}

/* ============ 购物车 ============ */
const cart = ref<CartView | null>(null)
const removeZero = ref(0)
const member = ref<CashierMember | null>(null)
const cartLoading = ref(false)

async function fetchCart() {
  cartLoading.value = true
  try {
    const data = await getCashierCart({
      remove_zero: removeZero.value,
      mid: member.value?.id || 0,
    })
    cart.value = data
    if (data?.remove_zero !== undefined) {
      removeZero.value = Number(data.remove_zero)
    }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    cartLoading.value = false
  }
}

const cartCount = computed(() => cart.value?.totalnum || 0)

async function handleAddProduct(p: CashierProduct) {
  if (p.guigelist && p.guigelist.length > 0) {
    // 有规格：弹规格选择
    specProduct.value = p
    specDialog.value = true
    return
  }
  try {
    await addCashierGoods({ proid: p.id, num: 1 })
    fetchCart()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

const specDialog = ref(false)
const specProduct = ref<CashierProduct | null>(null)

async function handlePickSpec(ggid: number) {
  if (!specProduct.value) return
  try {
    await addCashierGoods({ proid: specProduct.value.id, ggid, num: 1 })
    specDialog.value = false
    fetchCart()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleBarcodeAdd() {
  const code = barcodeInput.value.trim()
  if (!code) return
  try {
    await addCashierGoods({ barcode: code })
    barcodeInput.value = ''
    ElMessage.success('已加入购物车')
    fetchCart()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

/* 直接收款 */
const directDialog = ref(false)
const directPrice = ref<number>(0)

async function handleDirectPay() {
  if (!directPrice.value || directPrice.value <= 0) {
    ElMessage.warning('请输入收款金额')
    return
  }
  try {
    await addCashierGoods({ proid: -99, protype: 2, price: directPrice.value })
    directDialog.value = false
    directPrice.value = 0
    fetchCart()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

/* 数量 / 改价 / 删除 / 备注 */
async function handleNum(goodsId: number, num: number) {
  try {
    await changeCashierNum(goodsId, num)
    fetchCart()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

const priceDialog = ref(false)
const priceTarget = ref<{ goodsid: number; price: number }>({ goodsid: 0, price: 0 })

function openPriceDialog(row: { id: number; sell_price: number }) {
  priceTarget.value = { goodsid: row.id, price: row.sell_price }
  priceDialog.value = true
}

async function handlePriceSave() {
  try {
    await changeCashierPrice(priceTarget.value.goodsid, priceTarget.value.price)
    priceDialog.value = false
    fetchCart()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleDelGoods(goodsId: number) {
  try {
    await deleteCashierGoods(goodsId)
    fetchCart()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleRemark() {
  if (!cart.value?.orderid) return
  try {
    const { value } = await ElMessageBox.prompt('请输入订单备注', '订单备注', {
      inputValue: cart.value.remark,
      confirmButtonText: '保存',
      cancelButtonText: '取消',
    })
    await changeCashierRemark(cart.value.orderid, value || '')
    fetchCart()
  } catch {
    // 取消或接口错误
  }
}

function handleClearCart() {
  ElMessageBox.confirm('确认清空当前购物车吗？', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await clearCashierCart()
        fetchCart()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleRemoveZeroChange() {
  fetchCart()
}

function onZeroChange(v: string | number | boolean) {
  removeZero.value = v ? 1 : 0
  handleRemoveZeroChange()
}

/* ============ 挂单 / 取单 ============ */
const hangupDialog = ref(false)
const hangupList = ref<HangupItem[]>([])

async function handleHangup() {
  try {
    await hangupCashierOrder(member.value?.id || 0)
    ElMessage.success('挂单成功')
    fetchCart()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function openHangupList() {
  try {
    const data = await getCashierHangupList()
    hangupList.value = data?.list || []
    hangupDialog.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleCancelHangup(id: number) {
  try {
    await cancelHangupCashierOrder(id)
    ElMessage.success('取单成功')
    hangupDialog.value = false
    fetchCart()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

/* ============ 会员绑定 ============ */
const memberDialog = ref(false)
const memberKeyword = ref('')
const memberResults = ref<CashierMember[]>([])

async function handleMemberSearch() {
  if (!memberKeyword.value.trim()) {
    ElMessage.warning('请输入会员ID/手机号/昵称')
    return
  }
  try {
    const data = await searchCashierMember(memberKeyword.value.trim())
    memberResults.value = data?.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handlePickMember(m: CashierMember) {
  member.value = m
  memberDialog.value = false
  fetchCart()
}

function handleUnbindMember() {
  member.value = null
  fetchCart()
}

/* ============ 结算 ============ */
const payDialog = ref(false)
const preview = ref<PayPreviewData | null>(null)
const previewLoading = ref(false)
const paytype = ref('')
const authCode = ref('')
const paying = ref(false)

async function openPayDialog() {
  previewLoading.value = true
  try {
    const data = await cashierPayPreview({
      mid: member.value?.id || 0,
      remove_zero: removeZero.value,
    })
    preview.value = data
    paytype.value = data?.paylist?.length ? data.paylist[0].value : ''
    authCode.value = ''
    payDialog.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    previewLoading.value = false
  }
}

const currentPayOption = computed(() =>
  preview.value?.paylist.find((v) => v.value === paytype.value) || null,
)

async function handlePay() {
  if (!paytype.value) {
    ElMessage.warning('请选择支付方式')
    return
  }
  if (currentPayOption.value?.need_authcode === 1 && !authCode.value.trim()) {
    ElMessage.warning('请扫描/输入付款码')
    return
  }
  paying.value = true
  try {
    const result = await cashierPay({
      mid: member.value?.id || 0,
      remove_zero: removeZero.value,
      paytype: Number(paytype.value),
      auth_code: authCode.value.trim(),
    })
    payDialog.value = false
    member.value = null
    fetchCart()
    // 拉取订单详情用于小票
    try {
      const detail = await getCashierOrderDetail(result.orderid)
      receiptOrder.value = detail
      receiptDialog.value = true
    } catch {
      ElMessage.success('收款成功')
    }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    paying.value = false
  }
}

/* ============ 小票预览与打印 ============ */
const receiptDialog = ref(false)
const receiptOrder = ref<CashierOrderItem | null>(null)

function handlePrintReceipt() {
  const o = receiptOrder.value
  if (!o) return
  const rows = (o.prolist || [])
    .map(
      (g) => `<tr>
        <td>${g.proname}${g.ggname ? '(' + g.ggname + ')' : ''}</td>
        <td style="text-align:center">×${g.num}</td>
        <td style="text-align:right">¥${Number(g.totalprice).toFixed(2)}</td>
      </tr>`,
    )
    .join('')
  const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>收银小票 ${o.ordernum}</title>
<style>
  body{font-family:'Microsoft YaHei',sans-serif;width:280px;margin:0 auto;font-size:12px;color:#333}
  h2{text-align:center;margin:8px 0}
  .meta{line-height:1.9}
  table{width:100%;border-collapse:collapse;margin-top:6px}
  th,td{padding:3px 2px;border-bottom:1px dashed #bbb}
  th{border-bottom:1px solid #333;text-align:left}
  .total{text-align:right;font-size:15px;font-weight:700;margin:8px 0}
  .line{border-top:1px dashed #333;margin:6px 0}
</style></head><body>
<h2>收银小票</h2>
<div class="meta">
  单号：${o.ordernum}<br>
  时间：${formatTime(o.paytime || o.createtime)}<br>
  收银员：${o.admin_user || '-'}<br>
  ${o.nickname ? '会员：' + o.nickname + '<br>' : ''}
</div>
<table><thead><tr><th>商品</th><th style="text-align:center">数量</th><th style="text-align:right">金额</th></tr></thead>
<tbody>${rows}</tbody></table>
<div class="line"></div>
<div class="meta">
  原价：¥${Number(o.pre_totalprice).toFixed(2)}<br>
  ${Number(o.leveldk_money) > 0 ? '会员折扣：-¥' + Number(o.leveldk_money).toFixed(2) + '<br>' : ''}
  ${Number(o.moling_money) > 0 ? '抹零：-¥' + Number(o.moling_money).toFixed(2) + '<br>' : ''}
  支付方式：${o.paytype || '-'}
</div>
<div class="total">实收：¥${Number(o.totalprice).toFixed(2)}</div>
<div style="text-align:center">谢谢惠顾，欢迎再次光临</div>
<script>window.onload=function(){window.print()}<\/script>
</body></html>`
  const win = window.open('', '_blank', 'width=420,height=640')
  if (!win) {
    ElMessage.warning('请允许弹窗后重试打印')
    return
  }
  win.document.write(html)
  win.document.close()
}

onMounted(() => {
  fetchCategories()
  fetchProducts()
  fetchCart()
})
</script>

<template>
  <div class="cashier-desk">
    <!-- 左侧：商品 -->
    <el-card shadow="never" class="left-panel">
      <div class="cate-bar">
        <el-tag
          :effect="activeCate === 0 ? 'dark' : 'plain'"
          class="cate-tag"
          @click="handleCateChange(0)"
        >
          全部
        </el-tag>
        <el-tag
          v-for="c in categories"
          :key="c.id"
          :effect="activeCate === c.id ? 'dark' : 'plain'"
          class="cate-tag"
          @click="handleCateChange(c.id)"
        >
          {{ c.name }}
        </el-tag>
      </div>
      <div class="search-bar">
        <el-input
          v-model="keyword"
          placeholder="商品名称 / 编码搜索"
          clearable
          style="width: 200px"
          @keyup.enter="fetchProducts"
          @clear="fetchProducts"
        />
        <el-button type="primary" @click="fetchProducts">搜索</el-button>
        <el-input
          v-model="barcodeInput"
          placeholder="条码扫码加购"
          clearable
          style="width: 180px; margin-left: 12px"
          @keyup.enter="handleBarcodeAdd"
        />
      </div>
      <div v-loading="productLoading" class="product-grid">
        <div
          v-for="p in products"
          :key="p.id"
          class="product-card"
          @click="handleAddProduct(p)"
        >
          <div class="product-name">{{ p.name }}</div>
          <div class="product-price">
            ¥{{ Number(p.sell_price).toFixed(2) }}
            <span class="product-stock">库存{{ p.stock }}</span>
          </div>
        </div>
        <el-empty v-if="!products.length && !productLoading" description="暂无商品" />
      </div>
    </el-card>

    <!-- 右侧：购物车 -->
    <el-card shadow="never" class="right-panel">
      <div class="cart-header">
        <div class="list-title">购物车（{{ cartCount }} 件）</div>
        <div>
          <el-button size="small" @click="openHangupList">取单</el-button>
          <el-button size="small" :disabled="!cart?.has_order" @click="handleHangup">挂单</el-button>
          <el-button size="small" type="warning" plain @click="directDialog = true">
            直接收款
          </el-button>
        </div>
      </div>

      <!-- 会员 -->
      <div class="member-bar">
        <template v-if="member">
          <el-tag type="success">
            {{ member.nickname || member.tel }}（{{ member.levelname || '普通' }}
            {{ member.discount < 10 ? member.discount + '折' : '' }}）
          </el-tag>
          <el-button link type="danger" @click="handleUnbindMember">解绑</el-button>
        </template>
        <template v-else>
          <el-button size="small" @click="memberDialog = true">绑定会员</el-button>
        </template>
      </div>

      <!-- 购物车列表 -->
      <el-table v-loading="cartLoading" :data="cart?.prolist || []" stripe size="small" max-height="360">
        <el-table-column prop="proname" label="商品" min-width="140">
          <template #default="{ row }">
            {{ row.proname }}
            <span v-if="row.ggname" class="gg-name">({{ row.ggname }})</span>
            <el-tag v-if="row.is_gj === 1" size="small" type="warning" style="margin-left: 4px">
              改价
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="单价" width="100">
          <template #default="{ row }">
            <el-link type="primary" @click="openPriceDialog(row)">
              ¥{{ Number(row.sell_price).toFixed(2) }}
            </el-link>
          </template>
        </el-table-column>
        <el-table-column label="数量" width="130">
          <template #default="{ row }">
            <el-input-number
              :model-value="row.num"
              size="small"
              :min="0"
              :max="9999"
              controls-position="right"
              style="width: 100px"
              @change="(v: number | undefined) => handleNum(row.id, v ?? 0)"
            />
          </template>
        </el-table-column>
        <el-table-column label="小计" width="90">
          <template #default="{ row }">¥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="" width="60">
          <template #default="{ row }">
            <el-button link type="danger" @click="handleDelGoods(row.id)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <!-- 合计与操作 -->
      <div class="cart-footer">
        <div class="zero-line">
          <el-checkbox :model-value="removeZero === 1" @change="onZeroChange">
            抹零
          </el-checkbox>
          <span v-if="cart?.discount_money" class="discount-tip">
            已优惠 ¥{{ Number(cart.discount_money).toFixed(2) }}
          </span>
          <el-button link @click="handleRemark">备注</el-button>
          <el-button link type="danger" :disabled="!cart?.has_order" @click="handleClearCart">
            清空
          </el-button>
        </div>
        <div class="total-line">
          <span class="total-label">合计：</span>
          <span class="total-money">¥{{ Number(cart?.totalprice || 0).toFixed(2) }}</span>
          <el-button
            type="primary"
            size="large"
            :loading="previewLoading"
            :disabled="!cart?.has_order"
            @click="openPayDialog"
          >
            结算
          </el-button>
        </div>
      </div>
    </el-card>

    <!-- 改价 -->
    <el-dialog v-model="priceDialog" title="修改价格" width="360px">
      <el-form label-width="80px">
        <el-form-item label="新单价">
          <el-input-number v-model="priceTarget.price" :min="0" :precision="2" :step="1" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="priceDialog = false">取消</el-button>
        <el-button type="primary" @click="handlePriceSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- 规格选择 -->
    <el-dialog v-model="specDialog" :title="specProduct?.name || '选择规格'" width="400px">
      <div v-if="specProduct" class="spec-list">
        <div
          v-for="g in specProduct.guigelist"
          :key="g.id"
          class="spec-item"
          @click="handlePickSpec(g.id)"
        >
          <span>{{ g.name }}</span>
          <span>¥{{ Number(g.sell_price).toFixed(2) }}（库存{{ g.stock }}）</span>
        </div>
      </div>
    </el-dialog>

    <!-- 直接收款 -->
    <el-dialog v-model="directDialog" title="直接收款" width="380px">
      <el-form label-width="80px">
        <el-form-item label="收款金额">
          <el-input-number v-model="directPrice" :min="0.01" :precision="2" :step="1" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="directDialog = false">取消</el-button>
        <el-button type="primary" @click="handleDirectPay">加入购物车</el-button>
      </template>
    </el-dialog>

    <!-- 挂单列表 -->
    <el-dialog v-model="hangupDialog" title="挂单列表（取单）" width="520px">
      <el-table :data="hangupList" size="small">
        <el-table-column prop="ordernum" label="单号" min-width="150" />
        <el-table-column label="金额" width="90">
          <template #default="{ row }">¥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="挂单时间" width="160">
          <template #default="{ row }">{{ formatTime(row.hangup_time) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleCancelHangup(row.id)">取单</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!hangupList.length" description="暂无挂单" />
    </el-dialog>

    <!-- 会员搜索 -->
    <el-dialog v-model="memberDialog" title="绑定会员" width="560px">
      <div class="search-bar" style="margin-bottom: 12px">
        <el-input
          v-model="memberKeyword"
          placeholder="会员ID / 手机号 / 昵称"
          clearable
          style="width: 260px"
          @keyup.enter="handleMemberSearch"
        />
        <el-button type="primary" @click="handleMemberSearch">搜索</el-button>
      </div>
      <el-table :data="memberResults" size="small">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="nickname" label="昵称" min-width="120" />
        <el-table-column prop="tel" label="手机号" width="130" />
        <el-table-column label="等级" width="110">
          <template #default="{ row }">
            {{ row.levelname || '普通' }}
            <span v-if="row.discount < 10">（{{ row.discount }}折）</span>
          </template>
        </el-table-column>
        <el-table-column label="余额" width="90">
          <template #default="{ row }">¥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="70">
          <template #default="{ row }">
            <el-button link type="primary" @click="handlePickMember(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <!-- 结算弹窗 -->
    <el-dialog v-model="payDialog" title="结算收款" width="560px">
      <template v-if="preview">
        <div class="preview-box">
          <div>原价：¥{{ Number(preview.totalprice).toFixed(2) }}</div>
          <div v-if="Number(preview.leveldk_money) > 0">
            会员折扣：-¥{{ Number(preview.leveldk_money).toFixed(2) }}
          </div>
          <div v-if="Number(preview.moling_money) > 0">
            抹零：-¥{{ Number(preview.moling_money).toFixed(2) }}
          </div>
          <div class="preview-total">
            应收：¥{{ Number(preview.final_totalprice).toFixed(2) }}
          </div>
        </div>
        <div class="paytype-grid">
          <div
            v-for="p in preview.paylist"
            :key="p.value"
            :class="['paytype-item', { active: paytype === p.value }]"
            @click="paytype = p.value"
          >
            {{ p.lable }}
          </div>
        </div>
        <div v-if="currentPayOption?.tip" class="pay-tip">{{ currentPayOption.tip }}</div>
        <el-form v-if="currentPayOption?.need_authcode === 1" label-width="80px" style="margin-top: 12px">
          <el-form-item label="付款码">
            <el-input
              v-model="authCode"
              placeholder="扫描或输入顾客付款码"
              clearable
              @keyup.enter="handlePay"
            />
          </el-form-item>
        </el-form>
      </template>
      <template #footer>
        <el-button @click="payDialog = false">取消</el-button>
        <el-button type="primary" :loading="paying" @click="handlePay">确认收款</el-button>
      </template>
    </el-dialog>

    <!-- 小票预览 -->
    <el-dialog v-model="receiptDialog" title="收款成功 · 小票预览" width="400px">
      <template v-if="receiptOrder">
        <div class="receipt-box">
          <div class="receipt-title">收银小票</div>
          <div>单号：{{ receiptOrder.ordernum }}</div>
          <div>时间：{{ formatTime(receiptOrder.paytime || receiptOrder.createtime) }}</div>
          <div>收银员：{{ receiptOrder.admin_user || '-' }}</div>
          <div class="receipt-line" />
          <div v-for="g in receiptOrder.prolist || []" :key="g.id" class="receipt-goods">
            <span>{{ g.proname }}{{ g.ggname ? '(' + g.ggname + ')' : '' }} ×{{ g.num }}</span>
            <span>¥{{ Number(g.totalprice).toFixed(2) }}</span>
          </div>
          <div class="receipt-line" />
          <div>原价：¥{{ Number(receiptOrder.pre_totalprice).toFixed(2) }}</div>
          <div v-if="Number(receiptOrder.leveldk_money) > 0">
            会员折扣：-¥{{ Number(receiptOrder.leveldk_money).toFixed(2) }}
          </div>
          <div v-if="Number(receiptOrder.moling_money) > 0">
            抹零：-¥{{ Number(receiptOrder.moling_money).toFixed(2) }}
          </div>
          <div>支付方式：{{ receiptOrder.paytype || '-' }}</div>
          <div class="receipt-total">
            实收：¥{{ Number(receiptOrder.totalprice).toFixed(2) }}
          </div>
        </div>
      </template>
      <template #footer>
        <el-button @click="receiptDialog = false">关闭</el-button>
        <el-button type="primary" @click="handlePrintReceipt">打印小票</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.cashier-desk {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}

.left-panel {
  flex: 1;
  min-width: 0;
}

.right-panel {
  width: 560px;
  flex-shrink: 0;
}

.cate-bar {
  margin-bottom: 10px;
}

.cate-tag {
  margin-right: 8px;
  margin-bottom: 8px;
  cursor: pointer;
}

.search-bar {
  margin-bottom: 12px;
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 10px;
  min-height: 200px;
}

.product-card {
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  padding: 10px;
  cursor: pointer;
  transition: border-color 0.2s;
}

.product-card:hover {
  border-color: #409eff;
}

.product-name {
  font-size: 13px;
  color: #303133;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.product-price {
  margin-top: 6px;
  color: #f56c6c;
  font-weight: 600;
  font-size: 14px;
}

.product-stock {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
  font-weight: 400;
}

.cart-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.list-title {
  font-size: 16px;
  font-weight: 600;
}

.member-bar {
  margin-bottom: 10px;
}

.gg-name {
  color: #909399;
  font-size: 12px;
}

.cart-footer {
  margin-top: 12px;
}

.zero-line {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 10px;
}

.discount-tip {
  color: #e6a23c;
  font-size: 12px;
}

.total-line {
  display: flex;
  align-items: center;
  gap: 12px;
  justify-content: flex-end;
}

.total-label {
  font-size: 14px;
  color: #606266;
}

.total-money {
  font-size: 22px;
  font-weight: 700;
  color: #f56c6c;
}

.spec-list {
  max-height: 320px;
  overflow-y: auto;
}

.spec-item {
  display: flex;
  justify-content: space-between;
  padding: 10px 8px;
  border-bottom: 1px solid #f0f2f5;
  cursor: pointer;
}

.spec-item:hover {
  background: #f5f7fa;
}

.preview-box {
  background: #f5f7fa;
  border-radius: 6px;
  padding: 12px 16px;
  line-height: 2;
  margin-bottom: 12px;
}

.preview-total {
  font-size: 18px;
  font-weight: 700;
  color: #f56c6c;
}

.paytype-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}

.paytype-item {
  border: 1px solid #dcdfe6;
  border-radius: 6px;
  padding: 10px;
  text-align: center;
  cursor: pointer;
  font-size: 14px;
}

.paytype-item.active {
  border-color: #409eff;
  color: #409eff;
  background: #ecf5ff;
}

.pay-tip {
  margin-top: 8px;
  color: #909399;
  font-size: 12px;
}

.receipt-box {
  font-size: 13px;
  line-height: 1.9;
  background: #fafafa;
  padding: 12px 16px;
  border-radius: 6px;
}

.receipt-title {
  text-align: center;
  font-size: 16px;
  font-weight: 700;
  margin-bottom: 6px;
}

.receipt-line {
  border-top: 1px dashed #c0c4cc;
  margin: 6px 0;
}

.receipt-goods {
  display: flex;
  justify-content: space-between;
}

.receipt-total {
  font-size: 16px;
  font-weight: 700;
  color: #f56c6c;
  margin-top: 4px;
}
</style>
