import request from './request'

// ============ 购物返现 cashback（批次4-A 任务186） ============

export interface CashbackListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: string
}

export interface CashbackItem {
  id: number
  name: string
  back_ratio: number
  back_type: number // 1余额 2佣金 3积分
  receiver_type: number
  goods_multiple_max: number
  gettj: string
  fwtype: number
  categoryids: string
  productids: string
  minprice: number
  starttime: number
  endtime: number
  sort: number
  status: number
  shuoming: string
  create_time: number
}

export interface CashbackListResult {
  list: CashbackItem[]
  total: number
  page: number
  pagesize: number
}

export interface CashbackMemberRow {
  id: number
  mid: number
  phone: string
  nickname: string
  cashback_id: number
  cashback_name: string
  pro_id: number
  pro_num: number
  cashback_money: number
  cashback_money_max: number
  score: number
  commission: number
  back_type: number
  type: string
  create_time: string
}

export interface CashbackMemberLogRow {
  id: number
  mid: number
  phone: string
  nickname: string
  cashback_id: number
  cashback_name: string
  pro_id: number
  cashback_money: number
  score: number
  commission: number
  back_type: number
  type: string
  create_time: string
}

export function getCashbackList(params: CashbackListQuery) {
  return request.get<unknown, CashbackListResult>('/admin/cashback/list', { params })
}

export function getCashbackDetail(id: number) {
  return request.get<unknown, CashbackItem>('/admin/cashback/detail', { params: { id } })
}

export function addCashback(data: Partial<CashbackItem>) {
  return request.post<unknown, { id: number }>('/admin/cashback/add', data)
}

export function editCashback(data: Partial<CashbackItem>) {
  return request.post<unknown, null>('/admin/cashback/edit', data)
}

export function setCashbackStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/cashback/status', { id, status })
}

export function deleteCashback(id: number) {
  return request.post<unknown, null>('/admin/cashback/delete', { id })
}

// 参与记录：会员台账
export function getCashbackMemberList(params: { cashback_id: number; page?: number; pagesize?: number }) {
  return request.get<unknown, { list: CashbackMemberRow[]; total: number; page: number; pagesize: number }>(
    '/admin/cashback/memberList',
    { params }
  )
}

// 参与记录：返现流水
export function getCashbackMemberLogList(params: { cashback_id: number; page?: number; pagesize?: number }) {
  return request.get<unknown, { list: CashbackMemberLogRow[]; total: number; page: number; pagesize: number }>(
    '/admin/cashback/memberLogList',
    { params }
  )
}
