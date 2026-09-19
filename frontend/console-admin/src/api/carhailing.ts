import request from './request'
import type {
  CarCategoryListResult,
  CarCategorySaveParams,
  CarOrderListQuery,
  CarOrderListResult,
  CarProductListQuery,
  CarProductListResult,
  CarProductSaveParams,
} from './types'

// ============ 车辆分类 ============
export function getCarCategoryList(params: { page?: number; pagesize?: number }) {
  return request.get<unknown, CarCategoryListResult>('/admin/carhailing/category/list', { params })
}

export function addCarCategory(data: CarCategorySaveParams) {
  return request.post<unknown, null>('/admin/carhailing/category/add', data)
}

export function editCarCategory(data: CarCategorySaveParams) {
  return request.post<unknown, null>('/admin/carhailing/category/edit', data)
}

export function deleteCarCategory(id: number) {
  return request.post<unknown, null>('/admin/carhailing/category/delete', { id })
}

// ============ 车辆商品 ============
export function getCarProductList(params: CarProductListQuery) {
  return request.get<unknown, CarProductListResult>('/admin/carhailing/product/list', { params })
}

export function addCarProduct(data: CarProductSaveParams) {
  return request.post<unknown, null>('/admin/carhailing/product/add', data)
}

export function editCarProduct(data: CarProductSaveParams) {
  return request.post<unknown, null>('/admin/carhailing/product/edit', data)
}

export function deleteCarProduct(id: number) {
  return request.post<unknown, null>('/admin/carhailing/product/delete', { id })
}

export function setCarProductStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/carhailing/product/status', { id, status })
}

// ============ 租车订单 ============
export function getCarOrderList(params: CarOrderListQuery) {
  return request.get<unknown, CarOrderListResult>('/admin/carhailing/order/list', { params })
}

export function finishCarOrder(id: number) {
  return request.post<unknown, null>('/admin/carhailing/order/finish', { id })
}
