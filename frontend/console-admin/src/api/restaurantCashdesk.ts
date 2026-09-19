import request from './request'

// ==================== 类型定义 ====================

/** 桌台上挂载的订单摘要 */
export interface DeskTableOrder {
  id: number
  ordernum: string
  totalprice: number
  createtime: number
  status: number
}

/** 桌台宫格项 */
export interface DeskTableItem {
  id: number
  cid: number
  name: string
  seat: number
  status: number
  status_text: string
  orderid: number
  minprice: number
  order: DeskTableOrder | null
}

export interface DeskArea {
  id: number
  name: string
}

export interface DeskData {
  areas: DeskArea[]
  tables: DeskTableItem[]
  summary: { free: number; busy: number; clean: number; booked: number }
}

/** 开台结果 */
export interface OpenTableResult {
  order_id: number
  table_id: number
  reopened: boolean
}

/** 加料字典项 */
export interface JialiaoItem {
  id: number
  title: string
  price: number
}

/** 点餐菜品 */
export interface DeskProduct {
  id: number
  name: string
  pic: string
  sell_price: number
  cid: number
  jialiao: JialiaoItem[]
}

export interface ProductCategory {
  id: number
  name: string
}

/** 订单商品行 */
export interface OrderGoodsItem {
  id: number
  proid: number
  name: string
  pic: string
  num: number
  sell_price: number
  njlprice: number
  njltitle: string
  totalprice: number
  is_gj: number
  remark: string
}

/** 收银订单详情 */
export interface CashdeskOrderDetail {
  id: number
  ordernum: string
  tableid: number
  table_name: string
  status: number
  status_text: string
  mid: number
  uid: number
  createtime: number
  paytime: number
  paytypeid: number
  paytype: string
  paynum: string
  pickup_number: string
  refund_status: number
  product_price: number
  service_money: number
  moling_money: number
  totalprice: number
  pay_amount: number
  remove_zero: number
  remove_zero_length: number
  goods: OrderGoodsItem[]
}

/** 收银订单列表行 */
export interface CashdeskOrderRow {
  id: number
  ordernum: string
  table_name: string
  status: number
  status_text: string
  refund_status: number
  totalprice: number
  pay_amount: number
  moling_money: number
  paytypeid: number
  paytype_text: string
  pickup_number: string
  createtime: number
  paytime: number
  uid: number
}

export interface OrderListData {
  list: CashdeskOrderRow[]
  total: number
  page: number
  pagesize: number
}

/** 可用支付方式（沿用一期口径） */
export interface PayTypeOption {
  value: string
  lable: string
  tip: string
  need_authcode: number
}

/** 自定义支付字典项（字段名 title） */
export interface CustomPayItem {
  id: number
  title: string
  sort: number
  status: number
  paytypeid: number
  createtime: number
}

/** 餐饮收银设置 */
export interface CashdeskConfig {
  id: number
  name: string
  wxpay: number
  cashpay: number
  moneypay: number
  remove_zero_length: number
  paypwd_use_status: number
  jiaoban_print_ids: string
  reverse_audit: number
  custom_pays: CustomPayItem[]
}

/** 统计：支付方式维度 */
export interface StatPayTypeRow {
  paytypeid: number
  paytype_text: string
  count: number
  amount: number
}

/** 统计：桌台维度 */
export interface StatTableRow {
  tableid: number
  table_name: string
  count: number
  amount: number
}

export interface CashdeskStatData {
  order_count: number
  total_amount: number
  moling_amount: number
  pay_types: StatPayTypeRow[]
  tables: StatTableRow[]
  start_time: string
  end_time: string
}

/** 点餐条目 */
export interface AddGoodsItem {
  product_id: number
  num: number
  jialiao_ids?: number[]
}

// ==================== 接口 ====================

/** 桌台宫格 */
export function fetchDesk() {
  return request.get<unknown, DeskData>('/admin/restaurant/cashdesk/desk')
}

/** 开台 */
export function openTable(tableId: number, mid = 0) {
  return request.post<unknown, OpenTableResult>('/admin/restaurant/cashdesk/open', {
    table_id: tableId,
    mid,
  })
}

/** 菜品分类 */
export function fetchCategories() {
  return request.get<unknown, { list: ProductCategory[] }>('/admin/restaurant/cashdesk/categories')
}

/** 菜品列表（含加料字典） */
export function fetchProducts(keyword = '', categoryId = 0) {
  return request.get<unknown, { list: DeskProduct[] }>('/admin/restaurant/cashdesk/products', {
    params: { keyword, category_id: categoryId },
  })
}

/** 点餐/加菜 */
export function addGoods(orderId: number, items: AddGoodsItem[]) {
  return request.post<unknown, CashdeskOrderDetail>('/admin/restaurant/cashdesk/addgoods', {
    order_id: orderId,
    items,
  })
}

/** 改数量（num=0 删除该行） */
export function changeNum(orderId: number, goodsId: number, num: number) {
  return request.post<unknown, CashdeskOrderDetail>('/admin/restaurant/cashdesk/changenum', {
    order_id: orderId,
    goods_id: goodsId,
    num,
  })
}

/** 单品改价 */
export function changePrice(orderId: number, goodsId: number, price: number) {
  return request.post<unknown, CashdeskOrderDetail>('/admin/restaurant/cashdesk/changeprice', {
    order_id: orderId,
    goods_id: goodsId,
    price,
  })
}

/** 整单抹零（0不抹 1抹分 2抹角 3抹元） */
export function setRemoveZero(orderId: number, length: number) {
  return request.post<unknown, CashdeskOrderDetail>('/admin/restaurant/cashdesk/removezero', {
    order_id: orderId,
    length,
  })
}

/** 退菜 */
export function refundGoods(orderId: number, goodsId: number, num: number) {
  return request.post<unknown, CashdeskOrderDetail>('/admin/restaurant/cashdesk/refundgoods', {
    order_id: orderId,
    goods_id: goodsId,
    num,
  })
}

/** 订单详情 */
export function fetchOrderDetail(id: number) {
  return request.get<unknown, CashdeskOrderDetail>('/admin/restaurant/cashdesk/orderdetail', {
    params: { id },
  })
}

/** 收银订单列表 */
export function fetchOrderList(params: {
  status?: string
  start_time?: string
  end_time?: string
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, OrderListData>('/admin/restaurant/cashdesk/orderlist', { params })
}

/** 结账 */
export function payOrder(orderId: number, paytype: number) {
  return request.post<unknown, CashdeskOrderDetail>('/admin/restaurant/cashdesk/pay', {
    order_id: orderId,
    paytype,
  })
}

/** 可用支付方式 */
export function fetchPayList() {
  return request.get<unknown, { list: PayTypeOption[] }>('/admin/restaurant/cashdesk/paylist')
}

/** 反结账 */
export function reversePay(orderId: number) {
  return request.post<unknown, { audited: boolean }>('/admin/restaurant/cashdesk/reversepay', {
    order_id: orderId,
  })
}

/** 反结账审核 */
export function reverseAudit(orderId: number, pass: boolean) {
  return request.post<unknown, unknown>('/admin/restaurant/cashdesk/reverseaudit', {
    order_id: orderId,
    pass: pass ? 1 : 0,
  })
}

/** 收银设置 */
export function fetchConfig() {
  return request.get<unknown, CashdeskConfig>('/admin/restaurant/cashdesk/config')
}

/** 保存收银设置 */
export function saveConfig(data: Partial<Pick<CashdeskConfig, 'wxpay' | 'cashpay' | 'moneypay' | 'remove_zero_length' | 'reverse_audit'>>) {
  return request.post<unknown, unknown>('/admin/restaurant/cashdesk/saveconfig', data)
}

/** 自定义支付列表 */
export function fetchCustomPayList() {
  return request.get<unknown, { list: CustomPayItem[] }>('/admin/restaurant/cashdesk/customlist')
}

/** 保存自定义支付 */
export function saveCustomPay(data: { id?: number; title: string; sort?: number; status?: number }) {
  return request.post<unknown, { id: number }>('/admin/restaurant/cashdesk/customsave', data)
}

/** 删除自定义支付 */
export function deleteCustomPay(id: number) {
  return request.post<unknown, unknown>('/admin/restaurant/cashdesk/customdelete', { id })
}

/** 收银统计（自定义时间范围） */
export function fetchStat(startTime = '', endTime = '') {
  return request.get<unknown, CashdeskStatData>('/admin/restaurant/cashdesk/stat', {
    params: { start_time: startTime, end_time: endTime },
  })
}
