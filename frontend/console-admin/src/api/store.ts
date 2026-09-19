import request from './request'
import type {
  StoreListQuery,
  StoreListResult,
  StoreSaveParams,
} from './types'

// 门店列表
export function getStoreList(params: StoreListQuery) {
  return request.get<unknown, StoreListResult>('/admin/store/list', { params })
}

// 新增门店
export function addStore(data: StoreSaveParams) {
  return request.post<unknown, null>('/admin/store/add', data)
}

// 编辑门店
export function editStore(data: StoreSaveParams) {
  return request.post<unknown, null>('/admin/store/edit', data)
}

// 删除门店
export function deleteStore(id: number) {
  return request.post<unknown, null>('/admin/store/delete', { id })
}
