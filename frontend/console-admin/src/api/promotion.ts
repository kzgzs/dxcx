import request from './request'
import type {
  PromotionListQuery,
  PromotionListResult,
  PromotionSaveParams,
} from './types'

// 促销列表
export function getPromotionList(params: PromotionListQuery) {
  return request.get<unknown, PromotionListResult>('/admin/promotion/list', {
    params,
  })
}

// 新增促销
export function addPromotion(data: PromotionSaveParams) {
  return request.post<unknown, null>('/admin/promotion/add', data)
}

// 编辑促销
export function editPromotion(data: PromotionSaveParams) {
  return request.post<unknown, null>('/admin/promotion/edit', data)
}

// 启用 / 停用
export function setPromotionStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/promotion/status', { id, status })
}

// 删除促销
export function deletePromotion(id: number) {
  return request.post<unknown, null>('/admin/promotion/delete', { id })
}
