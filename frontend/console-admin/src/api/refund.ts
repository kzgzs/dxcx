import request from './request'
import type {
  RefundAuditParams,
  RefundDetail,
  RefundListQuery,
  RefundListResult,
  RefundStat,
} from './types'

// 退款列表
export function getRefundList(params: RefundListQuery) {
  return request.get<unknown, RefundListResult>('/admin/refund/list', { params })
}

// 退款详情（含订单商品快照）
export function getRefundDetail(id: number) {
  return request.get<unknown, RefundDetail>('/admin/refund/detail', {
    params: { id },
  })
}

// 退款审核（pass：1同意 2驳回）
export function auditRefund(data: RefundAuditParams) {
  return request.post<unknown, null>('/admin/refund/audit', data)
}

// 退款统计
export function getRefundStat() {
  return request.get<unknown, RefundStat>('/admin/refund/stat')
}

// 删除退款单（软删）
export function deleteRefund(id: number) {
  return request.post<unknown, null>('/admin/refund/del', { id })
}
