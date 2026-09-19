import request from './request'

// ============ 类型定义（分红体系：团队/股东/区域三套，任务183） ============

/** 分红类型：fenhong=股东 teamfenhong=团队 areafenhong=区域 */
export type FenhongType = 'fenhong' | 'teamfenhong' | 'areafenhong'

/** 时段类型：1上月 2本月 3昨日 4今日 10全部 */
export type FenhongRange = 1 | 2 | 3 | 4 | 10

/** 预览名单行 */
export interface FenhongPreviewMember {
  mid: number
  nickname: string
  levelid: number
  levelname: string
  commission: number
  ogcount: number
}

/** 时段预览结果 */
export interface FenhongPreviewResult {
  range: { start: number; end: number }
  ogcount: number
  allprice: number
  total: number
  membercount: number
  list: FenhongPreviewMember[]
}

/** 结算结果 */
export interface FenhongSettleResult {
  ogcount: number
  logs: number
  total: number
  detail: { fenhong: number; teamfenhong: number; areafenhong: number }
}

/** 分红记录行 */
export interface FenhongLogItem {
  id: number
  mid: number
  nickname: string
  tel: string
  type: string
  commission: number
  money: number
  score: number
  status: number
  status_text: string
  send_type?: string
  createtime: number
  sendtime?: number
  remark?: string
}

/** 分红记录统计 */
export interface FenhongLogStat {
  pending: number
  paid: number
  pending_money: number
}

/** 分红记录列表结果 */
export interface FenhongLogListResult {
  list: FenhongLogItem[]
  total: number
  page: number
  pagesize: number
  stat: FenhongLogStat
}

/** 发放审核结果 */
export interface FenhongExamineResult {
  status: number
  msg: string
}

/** 一键发放结果 */
export interface FenhongSendAllResult {
  sent: number
  skipped: number
  total: number
}

// ============ 接口 ============

/** 时段预览（只读试算） */
export function fenhongPreview(type: FenhongType, range: FenhongRange) {
  return request.get<unknown, FenhongPreviewResult>('/admin/fenhong/preview', { params: { type, range } })
}

/** 结算生成（三套同算：扫描 og.isfenhong=0 → 写 member_fenhonglog 待发放） */
export function fenhongSettle(range: FenhongRange) {
  return request.post<unknown, FenhongSettleResult>('/admin/fenhong/settle', { range })
}

/** 分红记录列表（按类型 + 状态，status=-1 全部） */
export function fenhongLogList(type: FenhongType, status: number, page = 1, pagesize = 10) {
  return request.get<unknown, FenhongLogListResult>('/admin/fenhong/loglist', { params: { type, status, page, pagesize } })
}

/** 发放审核：st=1 通过入账 / st=2 驳回 */
export function fenhongExamineSend(id: number, st: 1 | 2) {
  return request.post<unknown, FenhongExamineResult>('/admin/fenhong/examinesend', { id, st })
}

/** 一键发放：按类型批量通过全部待发放记录 */
export function fenhongSendAll(type: FenhongType) {
  return request.post<unknown, FenhongSendAllResult>('/admin/fenhong/sendall', { type })
}
