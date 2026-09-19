import request from './request'

// ============ 类型（批次6-T6-1 预定订座，自含定义） ============

export interface BookingOrderItem {
  id: number
  ordernum: string
  mid: number
  member_nickname: string
  member_headimg: string
  table_id: number
  table_name: string
  booking_time: string
  seat: number
  tel: string
  linkman: string
  message: string
  remark: string
  booking_fee: number
  totalprice: number
  status: number
  status_text: string
  check_status: number
  check_text: string
  refund_status: number
  refund_text: string
  refund_money: number
  refund_reason: string
  paytype: string
  pay_time: number
  createtime: number
}

export interface BookingOrderListResult {
  list: BookingOrderItem[]
  total: number
  page: number
  pagesize: number
  stats: { today_count: number; today_money: number }
}

export interface BookingOrderDetailResult {
  order: BookingOrderItem
  table: { id: number; name: string; seat: number } | null
  member: { mid: number; nickname: string; headimg: string; tel: string } | null
}

export interface BookingTimeItem {
  day: number
  hour: string
  minute: string
  hour2: string
  minute2: string
}

export interface BookingSetResult {
  status: number
  pay: number
  pay_money: number
  prehour: number
  timedata: BookingTimeItem[]
  time_slots: { day: number; day_text: string; date: string; start: string; end: string; title: string; value: string }[]
}

// ============ 预定订单 ============
export function getBookingOrderList(params: Record<string, unknown>) {
  return request.get<unknown, BookingOrderListResult>('/admin/restaurant/booking/list', { params })
}

export function getBookingOrderDetail(id: number) {
  return request.get<unknown, BookingOrderDetailResult>('/admin/restaurant/booking/detail', { params: { id } })
}

export function checkBookingOrder(id: number, type: 'access' | 'refuse') {
  return request.post<unknown, null>('/admin/restaurant/booking/check', { id, type })
}

export function setBookingOrderRemark(id: number, remark: string) {
  return request.post<unknown, null>('/admin/restaurant/booking/setremark', { id, remark })
}

export function closeBookingOrder(id: number) {
  return request.post<unknown, null>('/admin/restaurant/booking/close', { id })
}

// ============ 预定设置 ============
export function getBookingSet() {
  return request.get<unknown, BookingSetResult>('/admin/restaurant/booking/set')
}

export function saveBookingSet(data: {
  status: number
  pay: number
  pay_money: number
  prehour: number
  timedata: BookingTimeItem[]
}) {
  return request.post<unknown, null>('/admin/restaurant/booking/set', data)
}
