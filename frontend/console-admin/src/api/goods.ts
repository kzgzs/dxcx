import request from './request'
import type {
  CategoryAddParams,
  CategoryEditParams,
  CategoryListQuery,
  CategoryListResult,
  GoodsDetailResult,
  GoodsListQuery,
  GoodsListResult,
  GoodsSaveParams,
  StockImportResult,
  UploadResult,
} from './types'

// ============ 商品分类 ============

// 分类列表
export function getCategoryList(params: CategoryListQuery) {
  return request.get<unknown, CategoryListResult>('/admin/goods/category/list', {
    params,
  })
}

// 新增分类
export function addCategory(data: CategoryAddParams) {
  return request.post<unknown, null>('/admin/goods/category/add', data)
}

// 编辑分类
export function editCategory(data: CategoryEditParams) {
  return request.post<unknown, null>('/admin/goods/category/edit', data)
}

// 删除分类（支持批量）
export function deleteCategory(id: number | number[]) {
  const payload = Array.isArray(id) ? { ids: id } : { id }
  return request.post<unknown, null>('/admin/goods/category/delete', payload)
}

// ============ 商品 ============

// 商品列表
export function getGoodsList(params: GoodsListQuery) {
  return request.get<unknown, GoodsListResult>('/admin/goods/list', { params })
}

// 商品详情（编辑回显：基础字段 + specs + SKU 列表）
export function getGoodsDetail(id: number) {
  return request.get<unknown, GoodsDetailResult>('/admin/goods/detail', {
    params: { id },
  })
}

// 新增商品
export function addGoods(data: GoodsSaveParams) {
  return request.post<unknown, null>('/admin/goods/add', data)
}

// 编辑商品
export function editGoods(data: GoodsSaveParams) {
  return request.post<unknown, null>('/admin/goods/edit', data)
}

// 删除商品（支持单个 id 或批量 ids 数组）
export function deleteGoods(id: number | number[]) {
  const payload = Array.isArray(id) ? { ids: id } : { id }
  return request.post<unknown, null>('/admin/goods/delete', payload)
}

// 上下架（支持单个 id 或批量 ids 数组）
export function setGoodsStatus(id: number | number[], status: number) {
  const payload = Array.isArray(id) ? { ids: id, status } : { id, status }
  return request.post<unknown, null>('/admin/goods/status', payload)
}

// 复制商品
export function copyGoods(id: number) {
  return request.post<unknown, { id: number }>('/admin/goods/copy', { id })
}

// 修改价格
export function changeGoodsPrice(data: { id: number; sell_price?: number; market_price?: number }) {
  return request.post<unknown, null>('/admin/goods/changePrice', data)
}

// Excel 库存导入（multipart 字段 file：商品编码+规格名+库存）
export function stockImport(file: File) {
  const formData = new FormData()
  formData.append('file', file)
  return request.post<unknown, StockImportResult>('/admin/goods/stockImport', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

// 文件上传（multipart 字段 file），供编程式调用；el-upload 直接走 action
export function uploadFile(file: File) {
  const formData = new FormData()
  formData.append('file', file)
  return request.post<unknown, UploadResult>('/admin/upload', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}
