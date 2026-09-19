import request from './request'

// ============ 商品促销 cuxiao（批次4-A 任务186） ============

export interface CuxiaoListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  type?: string
}

export interface CuxiaoItem {
  id: number
  name: string
  type: number // 0仅显示 1满减 2满赠 3换购
  tip: string
  gettj: string
  fwtype: number // 0全部 1分类 2指定商品
  categoryids: string
  productids: string
  minprice: number
  minnum: number
  money: number
  proid: number
  ggid: number
  proname: string
  starttime: string
  endtime: string
  sort: number
  status: number
  create_time: number
}

export interface CuxiaoListResult {
  list: CuxiaoItem[]
  total: number
  page: number
  pagesize: number
}

export interface GoodsOption {
  id: number
  name: string
  sell_price?: number
}

export interface CategoryOption {
  id: number
  name: string
}

export function getCuxiaoList(params: CuxiaoListQuery) {
  return request.get<unknown, CuxiaoListResult>('/admin/cuxiao/list', { params })
}

export function getCuxiaoDetail(id: number) {
  return request.get<unknown, CuxiaoItem>('/admin/cuxiao/detail', { params: { id } })
}

export function addCuxiao(data: Partial<CuxiaoItem>) {
  return request.post<unknown, { id: number }>('/admin/cuxiao/add', data)
}

export function editCuxiao(data: Partial<CuxiaoItem>) {
  return request.post<unknown, null>('/admin/cuxiao/edit', data)
}

export function setCuxiaoStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/cuxiao/status', { id, status })
}

export function deleteCuxiao(id: number) {
  return request.post<unknown, null>('/admin/cuxiao/delete', { id })
}

// 赠品/换购品候选（商品列表）
export function getGoodsOptions(keyword = '') {
  return request.get<unknown, { list: GoodsOption[]; total: number }>('/admin/goods/list', {
    params: { page: 1, pagesize: 200, keyword },
  })
}

// 适用分类候选
export function getCategoryOptions() {
  return request.get<unknown, { list: CategoryOption[] }>('/admin/goods/category/list')
}
