import request from './request'

export interface CouponItem {
  id: number
  couponid?: number
  name: string
  type: number
  type_txt?: string
  money?: number
  discount?: number
  minprice?: number
  limit_count?: number
  value?: number
  stock?: number
  getnum?: number
  starttime?: string
  endtime?: string
  usetips?: string
  price?: number
  score?: number
  status?: number
  phase?: string
}

export interface CouponListResult {
  list: CouponItem[]
  total: number
}

export interface CouponDetailResult {
  coupon: CouponItem & Record<string, any>
  record: CouponItem & Record<string, any>
}

/** 优惠券列表 */
export function getCouponList(params?: { page?: number; pagesize?: number; keyword?: string }) {
  return request.get<unknown, CouponListResult>('/api/coupon/list', { params })
}

/** 优惠券详情（含当前用户领取记录） */
export function getCouponDetail(id: number) {
  return request.get<unknown, CouponDetailResult>('/api/coupon/detail', { params: { id } })
}

/** 领取优惠券 */
export function receiveCoupon(coupon_id: number) {
  return request.post<unknown, { msg: string }>('/api/coupon/receive', { coupon_id })
}

/** 我的优惠券 */
export function getMyCoupons(params?: { page?: number; pagesize?: number; status?: number }) {
  return request.get<unknown, CouponListResult>('/api/coupon/mine', { params })
}
