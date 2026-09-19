import request from './request'

// ============ 类型（本模块私有，避免改动共享 types.ts） ============

export interface ScoreshopSysset {
  autoclose: number
  autoshdays: number
  gettj: string
  gettjtip: string
  gettjurl: string
  comment: number
  comment_check: number
  showjd: number
  showcommission: number
}

export interface ScoreshopCategoryItem {
  id: number
  name: string
  pic: string
  status: number
  sort: number
  goods_count: number
  createtime: number
}

export interface ScoreshopGuige {
  id: number
  name: string
  score_price: number
  money_price: number
  stock: number
  sales: number
}

export interface ScoreshopProductItem {
  id: number
  cid: number
  cname: string
  name: string
  pic: string
  score_price: number
  money_price: number
  sell_price: number
  stock: number
  sales: number
  buymax: number
  status: number
  sort: number
  createtime: number
  guige: ScoreshopGuige[]
}

export interface ScoreshopOrderGoods {
  name: string
  pic: string
  ggname: string
  num: number
  score_price: number
  money_price: number
}

export interface ScoreshopOrderItem {
  id: number
  ordernum: string
  mid: number
  nickname: string
  headimg: string
  title: string
  totalscore: number
  totalmoney: number
  totalprice: number
  totalnum: number
  status: number
  linkman: string
  tel: string
  address: string
  paytype: string
  createtime: number
  paytime: number
  send_time: number
  remark: string
  goods: ScoreshopOrderGoods[]
}

export interface PageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

export interface ScoreshopProductSaveParams {
  id?: number
  cid: number
  name: string
  pic?: string
  detail?: string
  sell_price?: number
  score_price: number
  money_price: number
  buymax: number
  sort?: number
  status?: number
  guige?: { id?: number; name: string; score_price: number; money_price: number; stock: number }[]
}

// ============ 设置 ============

export function getScoreshopSet() {
  return request.get<unknown, ScoreshopSysset>('/admin/scoreshop/set')
}

export function saveScoreshopSet(data: Partial<ScoreshopSysset>) {
  return request.post<unknown, null>('/admin/scoreshop/set', data)
}

// ============ 分类 ============

export function getScoreshopCategoryList(keyword?: string) {
  return request.get<unknown, { list: ScoreshopCategoryItem[] }>('/admin/scoreshop/category/list', {
    params: { keyword: keyword || undefined },
  })
}

export function saveScoreshopCategory(data: {
  id?: number
  name: string
  pic?: string
  sort?: number
  status?: number
}) {
  return request.post<unknown, { id: number }>('/admin/scoreshop/category/save', data)
}

export function deleteScoreshopCategory(id: number) {
  return request.post<unknown, null>('/admin/scoreshop/category/delete', { id })
}

// ============ 商品 ============

export function getScoreshopProductList(params: {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
  cid?: number
}) {
  return request.get<unknown, PageResult<ScoreshopProductItem>>('/admin/scoreshop/product/list', {
    params,
  })
}

export function saveScoreshopProduct(data: ScoreshopProductSaveParams) {
  return request.post<unknown, { id: number }>('/admin/scoreshop/product/save', data)
}

export function deleteScoreshopProduct(id: number) {
  return request.post<unknown, null>('/admin/scoreshop/product/delete', { id })
}

export function setScoreshopProductStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/scoreshop/product/status', { id, status })
}

// ============ 兑换订单 ============

export function getScoreshopOrderList(params: {
  page?: number
  pagesize?: number
  status?: number | ''
  ordernum?: string
  title?: string
  linkman?: string
  tel?: string
  start_date?: string
  end_date?: string
}) {
  return request.get<unknown, PageResult<ScoreshopOrderItem>>('/admin/scoreshop/order/list', {
    params,
  })
}

export function closeScoreshopOrder(id: number) {
  return request.post<unknown, null>('/admin/scoreshop/order/close', { id })
}
