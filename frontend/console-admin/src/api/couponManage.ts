import request from './request'
import type {
  CollectRewardData,
  CouponManageListQuery,
  CouponManageListResult,
  CouponManageItem,
  CouponManageRecordQuery,
  CouponManageRecordResult,
  CouponManageSaveParams,
  CouponSendParams,
  CouponSendResult,
  CouponSetData,
  PayaftergiveData,
  SignRecordQuery,
  SignRecordResult,
  SignSetData,
} from './types'

// 供组件直接从本模块导入列表项类型
export type { CouponManageItem }

// ============ 优惠券管理（参考系统口径补全） ============

// 券列表
export function getCouponManageList(params: CouponManageListQuery) {
  return request.get<unknown, CouponManageListResult>('/admin/couponManage/list', { params })
}

// 券详情（编辑回显）
export function getCouponManageDetail(id: number) {
  return request.get<unknown, CouponManageItem>('/admin/couponManage/detail', { params: { id } })
}

// 新增/编辑
export function saveCouponManage(data: CouponManageSaveParams) {
  return request.post<unknown, { id: number }>('/admin/couponManage/save', data)
}

// 删除
export function deleteCouponManage(id: number) {
  return request.post<unknown, null>('/admin/couponManage/delete', { id })
}

// 启用/停用
export function setCouponManageStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/couponManage/status', { id, status })
}

// 复制
export function copyCouponManage(id: number) {
  return request.post<unknown, { id: number }>('/admin/couponManage/copy', { id })
}

// 领取记录（过期为查询时实时计算）
export function getCouponManageRecords(params: CouponManageRecordQuery) {
  return request.get<unknown, CouponManageRecordResult>('/admin/couponManage/records', { params })
}

// 批量发放（事务 + 行锁防超发）
export function sendCoupon(data: CouponSendParams) {
  return request.post<unknown, CouponSendResult>('/admin/couponManage/send', data)
}

// 记录手工核销/反核销（计次券禁止）
export function setRecordStatus(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/couponManage/recordStatus', { ids, st })
}

// 过期提醒设置
export function getCouponSet() {
  return request.get<unknown, CouponSetData>('/admin/couponManage/set')
}

export function saveCouponSet(data: CouponSetData) {
  return request.post<unknown, null>('/admin/couponManage/set', data)
}

// ============ 积分签到 ============

// 签到设置
export function getSignSet() {
  return request.get<unknown, SignSetData>('/admin/sign/set')
}

export function saveSignSet(data: SignSetData) {
  return request.post<unknown, null>('/admin/sign/set', data)
}

// 签到记录
export function getSignRecords(params: SignRecordQuery) {
  return request.get<unknown, SignRecordResult>('/admin/sign/records', { params })
}

export function deleteSignRecords(ids: number[]) {
  return request.post<unknown, null>('/admin/sign/recordDelete', { ids })
}

// ============ 赠送奖励设置 ============

// 支付后赠配置
export function getPayaftergive() {
  return request.get<unknown, PayaftergiveData>('/admin/reward/payaftergive')
}

export function savePayaftergive(data: PayaftergiveData) {
  return request.post<unknown, null>('/admin/reward/payaftergive', data)
}

// 确认收货奖励配置
export function getCollectReward() {
  return request.get<unknown, CollectRewardData>('/admin/reward/collect')
}

export function saveCollectReward(data: CollectRewardData) {
  return request.post<unknown, null>('/admin/reward/collect', data)
}
