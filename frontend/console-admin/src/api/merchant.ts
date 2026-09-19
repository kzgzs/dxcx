import request from './request'
import type {
  MerchantListQuery,
  MerchantListResult,
  MerchantSaveParams,
} from './types'

// 商户列表
export function getMerchantList(params: MerchantListQuery) {
  return request.get<unknown, MerchantListResult>('/admin/merchant/list', {
    params,
  })
}

// 新增商户
export function addMerchant(data: MerchantSaveParams) {
  return request.post<unknown, null>('/admin/merchant/add', data)
}

// 编辑商户
export function editMerchant(data: MerchantSaveParams) {
  return request.post<unknown, null>('/admin/merchant/edit', data)
}

// 删除商户
export function deleteMerchant(id: number) {
  return request.post<unknown, null>('/admin/merchant/delete', { id })
}
