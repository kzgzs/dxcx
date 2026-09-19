import request from './request'

/* ================= 类型（批次6-T6-3 任务204 寄存模块） ================= */

// 寄存订单行
export interface DepositOrderItem {
  id: number
  mid: number
  nickname: string
  headimg: string
  member_tel: string
  name: string
  num: number
  linkman: string
  tel: string
  pic: string
  message: string
  remark: string
  position_id: number
  position_name: string
  number_id: number
  number_name: string
  tableid?: number
  status: number
  status_text: string
  platform?: string
  waiter_id?: number
  createtime: number
  takeout_time: number
}

// 寄存流水行
export interface DepositLogItem {
  id: number
  type: number
  type_text: string
  num: number
  remark: string
  platform?: string
  createtime: number
}

// 位置字典行
export interface DepositPositionItem {
  id: number
  name: string
  sort: number
  status: number
  createtime: number
  number_count: number
}

// 编号字典行
export interface DepositNumberItem {
  id: number
  position_id: number
  position_name: string
  name: string
  sort: number
  status: number
  createtime: number
}

// 寄存设置
export interface DepositSyssetData {
  id: number
  aid: number
  bid: number
  time: string
  status: number
}

// 添加寄存表单位置选项
export interface DepositPositionOption {
  id: number
  name: string
  numbers: { id: number; name: string }[]
}

export interface DepositListResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

/* ================= 寄存管理 ================= */

export function getDepositOrderList(params: {
  st?: string | number
  keyword?: string
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, DepositListResult<DepositOrderItem>>(
    '/admin/restaurant/deposit/list',
    { params },
  )
}

export function getDepositOrderDetail(id: number) {
  return request.get<unknown, { order: DepositOrderItem; log: DepositLogItem[] }>(
    '/admin/restaurant/deposit/detail',
    { params: { id } },
  )
}

export function checkDepositOrder(id: number, type: 'access' | 'refuse') {
  return request.post<unknown, null>('/admin/restaurant/deposit/check', { id, type })
}

export function takeoutDepositOrder(id: number, numbers: number) {
  return request.post<unknown, { left: number; taken: number }>(
    '/admin/restaurant/deposit/takeout',
    { id, numbers },
  )
}

export function setDepositRemark(id: number, remark: string) {
  return request.post<unknown, null>('/admin/restaurant/deposit/setremark', { id, remark })
}

export function deleteDepositOrder(id: number) {
  return request.post<unknown, null>('/admin/restaurant/deposit/del', { id })
}

/* ================= 添加寄存（B端录入） ================= */

export function getDepositForm() {
  return request.get<unknown, { positions: DepositPositionOption[]; sysset: DepositSyssetData }>(
    '/admin/restaurant/deposit/form',
  )
}

export function addDepositOrder(data: {
  name: string
  num: number
  linkman: string
  tel: string
  pic?: string
  message?: string
  mid?: number
  position_id?: number
  number_id?: number
}) {
  return request.post<unknown, { id: number }>('/admin/restaurant/deposit/add', data)
}

/* ================= 位置/编号字典 ================= */

export function getDepositPositionList(keyword?: string) {
  return request.get<unknown, { list: DepositPositionItem[] }>(
    '/admin/restaurant/deposit/position/list',
    { params: { keyword } },
  )
}

export function saveDepositPosition(data: {
  id?: number
  name: string
  sort?: number
  status?: number
}) {
  return request.post<unknown, { id: number }>('/admin/restaurant/deposit/position/save', data)
}

export function deleteDepositPosition(id: number) {
  return request.post<unknown, null>('/admin/restaurant/deposit/position/del', { id })
}

export function getDepositNumberList(params: { position_id?: number; keyword?: string }) {
  return request.get<unknown, { list: DepositNumberItem[] }>(
    '/admin/restaurant/deposit/number/list',
    { params },
  )
}

export function saveDepositNumber(data: {
  id?: number
  position_id: number
  name: string
  sort?: number
  status?: number
}) {
  return request.post<unknown, { id: number }>('/admin/restaurant/deposit/number/save', data)
}

export function deleteDepositNumber(id: number) {
  return request.post<unknown, null>('/admin/restaurant/deposit/number/del', { id })
}

/* ================= 寄存设置 ================= */

export function getDepositSysset() {
  return request.get<unknown, DepositSyssetData>('/admin/restaurant/deposit/set/config')
}

export function saveDepositSysset(data: { time: string; status: number }) {
  return request.post<unknown, null>('/admin/restaurant/deposit/set/save', data)
}
