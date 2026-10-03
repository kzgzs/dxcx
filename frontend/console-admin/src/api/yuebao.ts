import request from './request'

// ============ 类型定义（余额宝：收益明细 / 提现审核 / 设置；任务181） ============

/** 分页列表通用返回 */
export interface YbPageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

export interface YbPageQuery {
  page?: number
  pagesize?: number
  start_date?: string
  end_date?: string
}

// ---- 1. 余额宝明细（member_yuebao_moneylog） ----
export interface YuebaoMoneyLogItem {
  id: number
  mid: number
  nickname: string
  tel: string
  money: number
  after: number
  type: number
  type_text: string
  remark: string
  createtime: number
}

export interface YuebaoMoneyLogStat {
  income: number
  expense: number
  net: number
}

export interface YuebaoMoneyLogQuery extends YbPageQuery {
  nickname?: string
  mid?: number
  type?: string
}

export type YuebaoMoneyLogListResult = YbPageResult<YuebaoMoneyLogItem> & { stat: YuebaoMoneyLogStat }

// ---- 2. 余额宝提现（member_yuebao_withdrawlog） ----
export interface YuebaoWithdrawItem {
  id: number
  mid: number
  nickname: string
  tel: string
  money: number
  txmoney: number
  ordernum: string
  paytype: string
  aliaccount: string
  aliaccountname: string
  bankname: string
  bankcarduser: string
  bankcardnum: string
  status: number
  status_text: string
  reason: string
  createtime: number
  paytime: number
  paynum: string
}

export interface YuebaoWithdrawStat {
  pending: number
  approved: number
  rejected: number
  paid: number
  pending_amount: number
}

export interface YuebaoWithdrawQuery extends YbPageQuery {
  nickname?: string
  mid?: number
  status?: string
  paytype?: string
}

export type YuebaoWithdrawListResult = YbPageResult<YuebaoWithdrawItem> & { stat: YuebaoWithdrawStat }

// ---- 3. 余额宝设置（tenant_config sys_ 键；后端返回数值，保存时可传字符串） ----
export interface YuebaoConfig {
  open_yuebao: number | string
  yuebao_rate: number | string
  yuebao_withdrawmin: number | string
  yuebao_withdrawfee: number | string
  yuebao_turn_yue: number | string
}

// ============ 接口 ============

/** 余额宝明细列表 */
export function getYuebaoMoneylogList(params: YuebaoMoneyLogQuery) {
  return request.get<unknown, YuebaoMoneyLogListResult>('/admin/yuebao/moneylog/list', { params })
}

/** 余额宝提现列表 */
export function getYuebaoWithdrawList(params: YuebaoWithdrawQuery) {
  return request.get<unknown, YuebaoWithdrawListResult>('/admin/yuebao/withdraw/list', { params })
}

/** 提现审核（pass 1通过→待打款 / 2驳回→退回余额宝） */
export function auditYuebaoWithdraw(data: { id: number; pass: number; reason?: string }) {
  return request.post<unknown, null>('/admin/yuebao/withdraw/audit', data)
}

/** 提现打款（mock 即时成功；真实模式预留 V3 转账桩） */
export function payYuebaoWithdraw(id: number) {
  return request.post<unknown, { mock: boolean; status: number }>('/admin/yuebao/withdraw/pay', { id })
}

/** 余额宝设置读取 */
export function getYuebaoConfig() {
  return request.get<unknown, YuebaoConfig>('/admin/yuebao/config')
}

/** 余额宝设置保存 */
export function saveYuebaoConfig(data: YuebaoConfig) {
  return request.post<unknown, null>('/admin/yuebao/config', data)
}

// ============ 任务对齐：余额宝明细/提现 批量删除 / 导出 ============

/** 余额宝明细导出（blob） */
export function exportYuebaoMoneyLog(params: YuebaoMoneyLogQuery) {
  return request.get('/admin/yuebao/moneylog/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}

/** 余额宝明细批量删除（兼容单 id 与 ids[]） */
export function deleteYuebaoMoneyLog(data: { id?: number; ids?: number[] }) {
  return request.post<unknown, { deleted: number }>('/admin/yuebao/moneylog/delete', data)
}

/** 余额宝提现导出（blob） */
export function exportYuebaoWithdraw(params: YuebaoWithdrawQuery) {
  return request.get('/admin/yuebao/withdraw/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}

/** 余额宝提现批量删除（兼容单 id 与 ids[]） */
export function deleteYuebaoWithdraw(data: { id?: number; ids?: number[] }) {
  return request.post<unknown, { deleted: number }>('/admin/yuebao/withdraw/delete', data)
}
