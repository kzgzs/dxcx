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
  type: number // 0无 1满额立减 2满额赠送 3加价换购 4满额打折 5满件打折 6满件立减
  tip: string
  gettj: string
  fwtype: number // 0全部 1分类 2指定商品
  categoryids: string
  productids: string
  minprice: number
  minnum: number
  money: number
  zhekou: number
  proid: number
  ggid: number
  proname: string
  prozk: string // 逗号分隔：指定商品单独折扣（按 productids 顺序对齐）
  pronum: string // 逗号分隔：指定商品单独最低件数（按 productids 顺序对齐）
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

export function deleteCuxiaoBatch(ids: number[]) {
  return request.post<unknown, null>('/admin/cuxiao/delete', { ids })
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
