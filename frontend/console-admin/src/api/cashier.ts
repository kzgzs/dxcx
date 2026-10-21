import request from './request'

/* ================= 类型（批次6-T6-5 任务206 通用收银台一期） ================= */

// 收银设置
export interface CashdeskConfig {
  id: number
  name: string
  color1: string
  wxpay: number
  cashpay: number
  moneypay: number
  remove_zero_length: number
  paypwd_use_status: number
  jiaoban_print_ids: string
  is_use_mid_search: number
  cashdesk_give_score_set: number
  printers: { id: number; name: string }[]
  cashier_url: string
  login_url: string
  pinfo: {
    wxpay_type: number
    appid: string
    wxpay_sub_mchid: string
    wxpay_mchid: string
    wxpay_mchkey: string
    wxpay_apiclient_cert: string
    wxpay_apiclient_key: string
  }
}

// 结算弹窗可用支付方式
export interface PayTypeOption {
  value: string
  lable: string
  tip: string
  need_authcode: number
}

// 商品分类
export interface CashierCategory {
  id: number
  pid: number
  name: string
}

// 商品规格
export interface CashierGuige {
  id: number
  name: string
  pic: string
  sell_price: number
  cost_price: number
  stock: number
  barcode: string
}

// 商品行
export interface CashierProduct {
  id: number
  name: string
  pic: string
  sell_price: number
  cost_price: number
  stock: number
  perlimitdan: number
  limit_start: number
  guige_num: number
  guigelist: CashierGuige[]
}

// 会员
export interface CashierMember {
  id: number
  nickname: string
  realname: string
  tel: string
  headimg: string
  money: number
  score: number
  levelid: number
  levelname: string
  discount: number
}

// 购物车商品行
export interface CartGoodsItem {
  id: number
  proid: string
  proname: string
  propic: string
  protype: number
  ggid: number
  ggname: string
  sell_price: number
  num: number
  totalprice: number
  is_gj: number
  stock: number
}

// 购物车视图
export interface CartView {
  has_order: boolean
  orderid: number
  ordernum: string
  prolist: CartGoodsItem[]
  totalnum: number
  totalprice: number
  discount_money: number
  remove_zero: number
  remove_zero_length: number
  mid: number
  remark: string
}

// 挂单行
export interface HangupItem {
  id: number
  ordernum: string
  totalprice: number
  totalnum: number
  mid: number
  hangup_time: number
  remark: string
}

// 结算预览
export interface PayPreviewData {
  orderid: number
  ordernum: string
  totalprice: number
  final_totalprice: number
  leveldk_money: number
  moling_money: number
  coupon_money: number
  scoredk_money: number
  remove_zero: number
  memberinfo: CashierMember | ''
  paylist: PayTypeOption[]
}

// 支付结果
export interface PayResult {
  orderid: number
  ordernum: string
  totalprice: number
  paytype: string
  paytypeid: number
  paynum: string
  mid: number
}

// 订单商品行（详情）
export interface OrderGoodsItem {
  id: number
  proid: string
  proname: string
  propic: string
  protype: number
  ggname: string
  sell_price: number
  num: number
  totalprice: number
  real_totalprice: number
  is_gj: number
}

// 收银订单
export interface CashierOrderItem {
  id: number
  ordernum: string
  status: number
  status_desc: string
  mid: number
  nickname: string
  headimg: string
  admin_user: string
  uid: number
  paytypeid: number
  paytype: string
  paynum: string
  pre_totalprice: number
  totalprice: number
  coupon_money: number
  scoredk_money: number
  leveldk_money: number
  moling_money: number
  remove_zero: number
  remark: string
  createtime: number
  paytime: number
  refund_status: number
  refund_status_desc: string
  refund_money: number
  refund_reason: string
  refund_time: number
  refund_checkremark: string
  prolist?: OrderGoodsItem[]
}

// 统计
export interface StatPayRow {
  paytypeid: number
  paytype: string
  count: number
  money: number
}

export interface StatGoodsRow {
  proname: string
  num: number
  money: number
}

export interface CashierStatData {
  starttime: string
  endtime: string
  order_count: number
  total_money: number
  member_count: number
  refund_count: number
  refund_money: number
  pay_stat: StatPayRow[]
  goods_stat: StatGoodsRow[]
}

// 交接班
export interface JiaobanData {
  logintime: string
  jiaobantime: string
  cashdesk_user: string
  ordercount: number
  today_total_money: number
  pay_stat: StatPayRow[]
  refund_money: number
  member_count: number
  member_money: number
}

export interface CashierListResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

/* ================= 收银设置 ================= */

export function getCashdeskConfig() {
  return request.get<unknown, CashdeskConfig>('/admin/cashier/set')
}

export function saveCashdeskConfig(data: {
  name?: string
  color1?: string
  wxpay: number
  cashpay: number
  moneypay: number
  remove_zero_length: number
  paypwd_use_status?: number
  jiaoban_print_ids?: string
  is_use_mid_search?: number
  cashdesk_give_score_set?: number
  pinfo?: Record<string, unknown>
}) {
  return request.post<unknown, null>('/admin/cashier/set', data)
}

export function getPayList() {
  return request.get<unknown, { list: PayTypeOption[] }>('/admin/cashier/set/paylist')
}

/* ================= 收银台操作 ================= */

export function getCashierCategories() {
  return request.get<unknown, { list: CashierCategory[] }>('/admin/cashier/categories')
}

export function getCashierProducts(params: {
  cateid?: number
  keyword?: string
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, CashierListResult<CashierProduct>>('/admin/cashier/products', {
    params,
  })
}

export function searchCashierMember(keyword: string) {
  return request.get<unknown, { list: CashierMember[] }>('/admin/cashier/member', {
    params: { keyword },
  })
}

export function getCashierCart(params?: { remove_zero?: number; mid?: number }) {
  return request.get<unknown, CartView>('/admin/cashier/cart', { params })
}

export function addCashierGoods(data: {
  proid?: number
  ggid?: number
  num?: number
  barcode?: string
  price?: number | null
  protype?: number
}) {
  return request.post<unknown, { orderid: number }>('/admin/cashier/addgoods', data)
}

export function changeCashierNum(goodsid: number, num: number) {
  return request.post<unknown, null>('/admin/cashier/changenum', { goodsid, num })
}

export function changeCashierPrice(goodsid: number, price: number) {
  return request.post<unknown, null>('/admin/cashier/changeprice', { goodsid, price })
}

export function changeCashierRemark(orderid: number, remark: string) {
  return request.post<unknown, null>('/admin/cashier/changeremark', { orderid, remark })
}

export function deleteCashierGoods(goodsid: number) {
  return request.post<unknown, null>('/admin/cashier/delgoods', { goodsid })
}

export function clearCashierCart() {
  return request.post<unknown, null>('/admin/cashier/clearcart')
}

export function hangupCashierOrder(mid?: number) {
  return request.post<unknown, null>('/admin/cashier/hangup', { mid: mid || 0 })
}

export function cancelHangupCashierOrder(orderid: number) {
  return request.post<unknown, null>('/admin/cashier/cancelhangup', { orderid })
}

export function getCashierHangupList() {
  return request.get<unknown, { list: HangupItem[] }>('/admin/cashier/hanguplist')
}

export function cashierPayPreview(data: { mid?: number; remove_zero?: number }) {
  return request.post<unknown, PayPreviewData>('/admin/cashier/paypreview', data)
}

export function cashierPay(data: {
  mid?: number
  remove_zero?: number
  paytype: number
  auth_code?: string
}) {
  return request.post<unknown, PayResult>('/admin/cashier/pay', data)
}

/* ================= 收银订单 ================= */

export function getCashierOrderList(params: {
  status?: string | number
  refund_status?: string | number
  ordernum?: string
  mid?: number
  paytypeid?: number
  starttime?: string
  endtime?: string
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, CashierListResult<CashierOrderItem>>('/admin/cashier/order', {
    params,
  })
}

export function getCashierOrderDetail(id: number) {
  return request.get<unknown, CashierOrderItem>('/admin/cashier/order/detail', {
    params: { id },
  })
}

export function cashierRefundApply(orderid: number, refund_money: number, reason: string) {
  return request.post<unknown, null>('/admin/cashier/order/refundapply', {
    orderid,
    refund_money,
    reason,
  })
}

export function cashierRefundAudit(orderid: number, agree: number, check_remark?: string) {
  return request.post<unknown, null>('/admin/cashier/order/refundaudit', {
    orderid,
    agree,
    check_remark: check_remark || '',
  })
}

export function deleteCashierOrders(ids: number[]) {
  return request.post<unknown, null>('/admin/cashier/order/delete', { ids })
}

// 直接退款（对齐点大：一步到位）
export function cashierRefund(orderid: number, refund_money: number, remark: string) {
  return request.post<unknown, null>('/admin/cashier/order/refund', {
    orderid,
    refund_money,
    remark,
  })
}

// 打印小票
export function cashierWifiprint(id: number) {
  return request.post<unknown, { status: number; msg: string; content: string }>(
    '/admin/cashier/order/wifiprint',
    { id },
  )
}

// 导出订单（返回 title+data，前端生成 CSV）
export function exportCashierOrders(params: {
  status?: string | number
  ordernum?: string
  mid?: number
  starttime?: string
  endtime?: string
}) {
  return request.get<unknown, { title: string[]; data: (string | number)[][]; count: number }>(
    '/admin/cashier/order/excel',
    { params },
  )
}

/* ================= 统计与交接班 ================= */

export function getCashierStat(params?: { starttime?: string; endtime?: string }) {
  return request.get<unknown, CashierStatData>('/admin/cashier/stat', { params })
}

export function getCashierJiaoban() {
  return request.get<unknown, JiaobanData>('/admin/cashier/jiaoban')
}

// 订单统计行（按商品/按规格）
export interface CashierTongjiRow {
  ph: number
  proid: string
  name: string
  pic: string
  ggname: string
  num: number
  totalprice: number
  avgprice: number
  chengben: number
  lirun: number
}

export interface CashierTongjiResult {
  list: CashierTongjiRow[]
  total: number
  page: number
  pagesize: number
  totaldata: { num: number; totalprice: number; chengben: number; lirun: number } | null
}

// 订单统计（按商品/按规格）
export function getCashierTongji(params: {
  type?: number
  ctime_start?: string
  ctime_end?: string
  paytime_start?: string
  paytime_end?: string
  field?: string
  order?: string
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, CashierTongjiResult>('/admin/cashier/stat/tongji', { params })
}

// 订单统计导出
export function exportCashierTongji(params: {
  type?: number
  ctime_start?: string
  ctime_end?: string
  paytime_start?: string
  paytime_end?: string
  field?: string
  order?: string
}) {
  return request.get<unknown, { title: string[]; data: (string | number)[][]; count: number }>(
    '/admin/cashier/stat/tjexcel',
    { params },
  )
}
