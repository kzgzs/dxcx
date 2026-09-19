import request from './request'
import type {
  CouponListQuery,
  CouponListResult,
  CouponRecordListResult,
  CouponRecordQuery,
  CouponSaveParams,
} from './types'

// 优惠券列表
export function getCouponList(params: CouponListQuery) {
  return request.get<unknown, CouponListResult>('/admin/coupon/list', { params })
}

// 新增优惠券
export function addCoupon(data: CouponSaveParams) {
  return request.post<unknown, null>('/admin/coupon/add', data)
}

// 编辑优惠券
export function editCoupon(data: CouponSaveParams) {
  return request.post<unknown, null>('/admin/coupon/edit', data)
}

// 启用 / 停用
export function setCouponStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/coupon/status', { id, status })
}

// 删除优惠券
export function deleteCoupon(id: number) {
  return request.post<unknown, null>('/admin/coupon/delete', { id })
}

// 领取记录
export function getCouponRecords(params: CouponRecordQuery) {
  return request.get<unknown, CouponRecordListResult>('/admin/coupon/records', {
    params,
  })
}
