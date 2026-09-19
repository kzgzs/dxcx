import request from './request'

// ============ 类型定义（奖金池：利润池 / 区域池 / 达标池；任务184） ============

/** 分页列表通用返回 */
export interface PpPageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

/** 利润池配置（prize_pool_set） */
export interface PrizePoolProfitConfig {
  id: number
  aid: number
  status: number | string
  pool_num: number | string
  pool_time: number | string
  send_bili: number | string
  levelids: string
  send_time: number | string
  show_pool: number | string
}

/** 利润池保存参数 */
export interface PrizePoolProfitSaveParams {
  status: number
  pool_time: number
  send_bili: number
  levelids: string
  send_time: number
  show_pool: number
}

/** 利润池流水行 */
export interface PrizePoolProfitLogItem {
  id: number
  mid: number
  nickname: string
  orderid: number
  ordernum: string
  type: string
  num: number | string
  levelid: number
  createtime: number
  remark: string
}

/** 区域/达标池配置行（areareach_prize_pool_set） */
export interface AreareachPoolItem {
  id: number
  aid: number
  status: number
  type: number
  name: string
  province_id: number
  province_name: string
  money: number | string
  mids: string
  mids_text: string
  send_type: number
  gettj: string
  reach_money: number | string
  remark: string
  createtime: number
  updatetime: number
}

/** 区域/达标池保存参数 */
export interface AreareachPoolSaveParams {
  id: number
  type: number
  name: string
  status: number
  province_name: string
  mids: number[]
  send_type: number
  gettj: string
  reach_money: number
  remark: string
}

/** 入池订单日志行 */
export interface PrizePoolOrderLogItem {
  id: number
  type: number
  orderid: number
  ordernum: string
  ogid: number
  prize_pool_id: number
  money: number | string
  before_money: number | string
  remark: string
  createtime: number
}

/** 发放记录行（三池合并视图） */
export interface PrizePoolSendLogItem {
  id: number
  pool_type: 'profit' | 'area' | 'reach'
  name: string
  mid: number
  nickname: string
  send_money: number
  pool_money: number
  level_text: string
  member_count: number
  send_type: number
  createtime: number
  detail: string
}

export interface ProvinceItem {
  id: number
  name: string
}

// ============ 接口 ============

/** 利润池配置读取 */
export function getProfitConfig() {
  return request.get<unknown, PrizePoolProfitConfig>('/admin/prizepool/profit/config')
}

/** 利润池配置保存 */
export function saveProfitConfig(data: PrizePoolProfitSaveParams) {
  return request.post<unknown, null>('/admin/prizepool/profit/config', data)
}

/** 利润池手动发放 */
export function sendProfitNow() {
  return request.post<unknown, { sent: number }>('/admin/prizepool/profit/send')
}

/** 利润池流水 */
export function getProfitLog(params: { page?: number; pagesize?: number }) {
  return request.get<unknown, PpPageResult<PrizePoolProfitLogItem>>('/admin/prizepool/profit/log', { params })
}

/** 区域/达标池配置列表（type 0区域 1达标） */
export function getPoolList(params: { type: number; page?: number; pagesize?: number }) {
  return request.get<unknown, PpPageResult<AreareachPoolItem>>('/admin/prizepool/pool/list', { params })
}

/** 区域/达标池配置保存 */
export function savePool(data: AreareachPoolSaveParams) {
  return request.post<unknown, null>('/admin/prizepool/pool/save', data)
}

/** 区域/达标池配置删除 */
export function deletePool(id: number) {
  return request.post<unknown, null>('/admin/prizepool/pool/delete', { id })
}

/** 入池订单日志 */
export function getPoolOrderLog(params: { type?: number; pool_id?: number; page?: number; pagesize?: number }) {
  return request.get<unknown, PpPageResult<PrizePoolOrderLogItem>>('/admin/prizepool/pool/orderlog', { params })
}

/** 发放记录（pool_type profit/area/reach） */
export function getSendLogList(params: { pool_type: string; page?: number; pagesize?: number }) {
  return request.get<unknown, PpPageResult<PrizePoolSendLogItem>>('/admin/prizepool/sendlog/list', { params })
}

/** 省份列表 */
export function getProvinces() {
  return request.get<unknown, { list: ProvinceItem[] }>('/admin/prizepool/provinces')
}
