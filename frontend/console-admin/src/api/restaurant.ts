import request from './request'
import type {
  RestaurantAreaListResult,
  RestaurantAreaSaveParams,
  RestaurantCategoryListResult,
  RestaurantCategorySaveParams,
  RestaurantOrderListQuery,
  RestaurantOrderListResult,
  RestaurantProductListQuery,
  RestaurantProductListResult,
  RestaurantProductSaveParams,
  RestaurantTableListQuery,
  RestaurantTableListResult,
  RestaurantTableSaveParams,
} from './types'

// ============ 区域 ============
export function getRestaurantAreaList() {
  return request.get<unknown, RestaurantAreaListResult>('/admin/restaurant/area/list')
}

export function addRestaurantArea(data: RestaurantAreaSaveParams) {
  return request.post<unknown, null>('/admin/restaurant/area/add', data)
}

export function editRestaurantArea(data: RestaurantAreaSaveParams) {
  return request.post<unknown, null>('/admin/restaurant/area/edit', data)
}

export function deleteRestaurantArea(id: number) {
  return request.post<unknown, null>('/admin/restaurant/area/delete', { id })
}

// ============ 桌台 ============
export function getRestaurantTableList(params: RestaurantTableListQuery) {
  return request.get<unknown, RestaurantTableListResult>('/admin/restaurant/table/list', { params })
}

export function addRestaurantTable(data: RestaurantTableSaveParams) {
  return request.post<unknown, null>('/admin/restaurant/table/add', data)
}

export function editRestaurantTable(data: RestaurantTableSaveParams) {
  return request.post<unknown, null>('/admin/restaurant/table/edit', data)
}

export function deleteRestaurantTable(id: number) {
  return request.post<unknown, null>('/admin/restaurant/table/delete', { id })
}

// ============ 菜品分类 ============
export function getRestaurantCategoryList() {
  return request.get<unknown, RestaurantCategoryListResult>('/admin/restaurant/category/list')
}

export function addRestaurantCategory(data: RestaurantCategorySaveParams) {
  return request.post<unknown, null>('/admin/restaurant/category/add', data)
}

export function editRestaurantCategory(data: RestaurantCategorySaveParams) {
  return request.post<unknown, null>('/admin/restaurant/category/edit', data)
}

export function deleteRestaurantCategory(id: number) {
  return request.post<unknown, null>('/admin/restaurant/category/delete', { id })
}

// ============ 菜品 ============
export function getRestaurantProductList(params: RestaurantProductListQuery) {
  return request.get<unknown, RestaurantProductListResult>('/admin/restaurant/product/list', { params })
}

export function addRestaurantProduct(data: RestaurantProductSaveParams) {
  return request.post<unknown, null>('/admin/restaurant/product/add', data)
}

export function editRestaurantProduct(data: RestaurantProductSaveParams) {
  return request.post<unknown, null>('/admin/restaurant/product/edit', data)
}

export function deleteRestaurantProduct(id: number) {
  return request.post<unknown, null>('/admin/restaurant/product/delete', { id })
}

export function setRestaurantProductStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/restaurant/product/status', { id, status })
}

// ============ 餐饮订单 ============
export function getRestaurantOrderList(params: RestaurantOrderListQuery) {
  return request.get<unknown, RestaurantOrderListResult>('/admin/restaurant/order/list', { params })
}

export function finishRestaurantOrder(id: number) {
  return request.post<unknown, null>('/admin/restaurant/order/finish', { id })
}

export function cancelRestaurantOrder(id: number) {
  return request.post<unknown, null>('/admin/restaurant/order/cancel', { id })
}
