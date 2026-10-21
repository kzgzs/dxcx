import request from './request'

// ============ 类型（P14-2 餐饮订单侧，自含定义避免与既有 restaurant 类型冲突） ============

export interface ShopOrderItem {
  id: number
  ordernum: string
  mid: number
  member_nickname: string
  member_tel: string
  table_id: number
  table_name: string
  renshu: number
  totalprice: number
  product_price: number
  coupon_money: number
  score_money: number
  pay_amount: number
  use_score: number
  eattype: number
  remark: string
  status: number
  status_text: string
  refund_status: number
  refund_text: string
  refund_reason: string
  refund_money: number
  refund_checkremark: string
  paytype: string
  pay_time: number
  createtime: number
}

export interface TakeawayOrderItem {
  id: number
  ordernum: string
  mid: number
  member_nickname: string
  member_tel: string
  linkman: string
  tel: string
  address: string
  message: string
  remark: string
  totalprice: number
  product_price: number
  pack_fee: number
  freight_price: number
  coupon_money: number
  use_score: number
  status: number
  status_text: string
  refund_status: number
  refund_text: string
  refund_reason: string
  refund_money: number
  express_com: string
  express_no: string
  paytype: string
  pay_time: number
  createtime: number
}

export interface OrderGoodsItem {
  id?: number
  product_id: number
  name: string
  pic: string
  ggname?: string
  num: number
  price: number
  totalprice?: number
}

export interface ShopOrderDetailResult {
  order: ShopOrderItem
  goods: OrderGoodsItem[]
  table: { id: number; name: string; persons: number; area_name: string } | null
  member: { mid: number; nickname: string; headimg: string; tel: string } | null
}

export interface TakeawayOrderDetailResult {
  order: TakeawayOrderItem
  goods: OrderGoodsItem[]
  member: { mid: number; nickname: string; headimg: string; tel: string } | null
}

export interface ShopOrderListResult {
  list: ShopOrderItem[]
  total: number
  page: number
  pagesize: number
  stats: { today_count: number; today_money: number }
}

export interface TakeawayOrderListResult {
  list: TakeawayOrderItem[]
  total: number
  page: number
  pagesize: number
}

// ============ 堂食订单 ============
export function getShopOrderList(params: Record<string, unknown>) {
  return request.get<unknown, ShopOrderListResult>('/admin/restaurant/shopOrder/list', { params })
}

export function getShopOrderDetail(id: number) {
  return request.get<unknown, ShopOrderDetailResult>('/admin/restaurant/shopOrder/detail', { params: { id } })
}

export function setShopOrderRemark(id: number, remark: string) {
  return request.post<unknown, null>('/admin/restaurant/shopOrder/setremark', { id, remark })
}

export function changeShopOrderPrice(id: number, newprice: number) {
  return request.post<unknown, null>('/admin/restaurant/shopOrder/changeprice', { id, newprice })
}

export function closeShopOrder(id: number) {
  return request.post<unknown, null>('/admin/restaurant/shopOrder/close', { id })
}

export function shopOrderIspay(id: number) {
  return request.post<unknown, null>('/admin/restaurant/shopOrder/ispay', { id })
}

export function checkShopOrderRefund(id: number, st: number, remark = '') {
  return request.post<unknown, null>('/admin/restaurant/shopOrder/refundCheck', { id, st, remark })
}

export function printShopOrder(id: number) {
  return request.post<unknown, { printed: boolean }>('/admin/restaurant/shopOrder/print', { id })
}

// ============ 外卖订单 ============
export function getTakeawayOrderList(params: Record<string, unknown>) {
  return request.get<unknown, TakeawayOrderListResult>('/admin/restaurant/takeawayOrder/list', { params })
}

export function getTakeawayOrderDetail(id: number) {
  return request.get<unknown, TakeawayOrderDetailResult>('/admin/restaurant/takeawayOrder/detail', { params: { id } })
}

export function acceptTakeawayOrder(id: number) {
  return request.post<unknown, null>('/admin/restaurant/takeawayOrder/jiedan', { id })
}

export function closeTakeawayOrder(id: number) {
  return request.post<unknown, null>('/admin/restaurant/takeawayOrder/close', { id })
}

export function sendTakeawayOrderExpress(id: number, express_com: string, express_no: string) {
  return request.post<unknown, null>('/admin/restaurant/takeawayOrder/sendExpress', { id, express_com, express_no })
}
