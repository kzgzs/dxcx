import request from './request'

// ============ 类型（批次6-T6-4 餐饮取餐模块） ============

/** 取餐状态：0制作中 1待取餐 2已取餐 -1过期 */
export type TakeFoodStatus = -1 | 0 | 1 | 2

export interface TakeFoodItem {
  id: number
  pickup_number: string
  status: number
  status_text: string
  orderid: number
  ordernum: string
  table_name: string
  member_nickname: string
  call_text: string
  call_time: number
  need_play: number
  create_time: number
}

export interface TakeFoodListQuery {
  page?: number
  pagesize?: number
  status?: string
  pickup_number?: string
  ordernum?: string
}

export interface TakeFoodListResult {
  list: TakeFoodItem[]
  total: number
  page: number
  pagesize: number
}

/** 取餐设置 */
export interface TakeFoodSet {
  banner: string[]
  screen_pic: string
  banner_interval: number
}

// ============ 取餐列表与流转 ============

export function getTakeFoodList(params: TakeFoodListQuery) {
  return request.get<unknown, TakeFoodListResult>('/admin/restaurant/takefood/list', { params })
}

/** 出餐（制作中 → 待取餐） */
export function takeFoodOut(id: number) {
  return request.post<unknown, null>('/admin/restaurant/takefood/out', { id })
}

/** 叫号（置 call_time + call_text） */
export function takeFoodCall(id: number, callText?: string) {
  return request.post<unknown, null>('/admin/restaurant/takefood/call', { id, call_text: callText })
}

/** 完成取餐 */
export function takeFoodFinish(id: number) {
  return request.post<unknown, null>('/admin/restaurant/takefood/finish', { id })
}

// ============ 取餐设置 ============

export function getTakeFoodSet() {
  return request.get<unknown, TakeFoodSet>('/admin/restaurant/takefood/set')
}

export function saveTakeFoodSet(data: TakeFoodSet) {
  return request.post<unknown, null>('/admin/restaurant/takefood/set', data)
}
