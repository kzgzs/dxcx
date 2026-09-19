import request from './request'

// ============ 类型定义 ============

export interface PageResult<T> {
  list: T[]
  total: number
}

// 商品分组
export interface ShopGroupRow {
  id: number
  name: string
  pic: string
  sort: number
  status: number
  create_time?: string
}

// 商品参数
export interface ShopParamRow {
  id: number
  name: string
  cid: string
  type: number
  params: string
  tips: string
  is_required: number
  sort: number
  status: number
  catname?: string
  create_time?: string
}

export interface ParamCategoryNode {
  id: number
  name: string
  child?: ParamCategoryNode[]
}

// 商品服务
export interface ShopFuwuRow {
  id: number
  name: string
  desc: string
  sort: number
  status: number
  create_time?: string
}

// 商城设置
export interface ShopSetConfigResult {
  info: Record<string, unknown>
  levellist: { id: number; name: string; can_agent?: number }[]
}

// 商品采集
export interface TaobaoConfigResult {
  apikey: string
}

export interface CollectResult {
  proid: number
  pic_count: number
}

export interface PicLocalResult {
  done: boolean
  processed: number
  failed: number
}

// ============ 商品分组 ============

export function getShopGroupList(params: Record<string, unknown>) {
  return request.get<unknown, PageResult<ShopGroupRow>>('/admin/shopGroup/list', { params })
}

export function getShopGroupDetail(id: number) {
  return request.get<unknown, { info: ShopGroupRow }>('/admin/shopGroup/detail', { params: { id } })
}

export function saveShopGroup(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/shopGroup/save', data)
}

export function deleteShopGroup(ids: number[]) {
  return request.post<unknown, null>('/admin/shopGroup/delete', { ids })
}

// ============ 商品参数 ============

export function getShopParamList(params: Record<string, unknown>) {
  return request.get<unknown, PageResult<ShopParamRow>>('/admin/shopParam/list', { params })
}

export function getShopParamCategoryTree() {
  return request.get<unknown, { tree: ParamCategoryNode[] }>('/admin/shopParam/categoryTree')
}

export function getShopParamDetail(id: number) {
  return request.get<unknown, { info: Record<string, unknown> }>('/admin/shopParam/detail', { params: { id } })
}

export function saveShopParam(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/shopParam/save', data)
}

export function deleteShopParam(ids: number[]) {
  return request.post<unknown, null>('/admin/shopParam/delete', { ids })
}

// ============ 商品服务 ============

export function getShopFuwuList(params: Record<string, unknown>) {
  return request.get<unknown, PageResult<ShopFuwuRow>>('/admin/shopFuwu/list', { params })
}

export function getShopFuwuDetail(id: number) {
  return request.get<unknown, { info: ShopFuwuRow }>('/admin/shopFuwu/detail', { params: { id } })
}

export function saveShopFuwu(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/shopFuwu/save', data)
}

export function deleteShopFuwu(ids: number[]) {
  return request.post<unknown, null>('/admin/shopFuwu/delete', { ids })
}

// ============ 商城设置 ============

export function getShopSetConfig() {
  return request.get<unknown, ShopSetConfigResult>('/admin/shopSet/config')
}

export function saveShopSet(info: Record<string, unknown>) {
  return request.post<unknown, null>('/admin/shopSet/save', { info })
}

// ============ 商品采集 ============

export function getTaobaoConfig() {
  return request.get<unknown, TaobaoConfigResult>('/admin/shopTaobao/config')
}

export function saveTaobaoKey(apikey: string) {
  return request.post<unknown, null>('/admin/shopTaobao/saveKey', { apikey })
}

export function collectProduct(data: { type: number; url: string; apikey?: string }) {
  return request.post<unknown, CollectResult>('/admin/shopTaobao/collect', data)
}

export function picLocal(limit?: number) {
  return request.post<unknown, PicLocalResult>('/admin/shopTaobao/picLocal', { limit })
}
