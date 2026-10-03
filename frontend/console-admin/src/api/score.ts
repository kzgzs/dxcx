import request from './request'
import type {
  ScoreAdjustParams,
  ScoreConfigData,
  ScoreLogListResult,
  ScoreMemberListResult,
} from './types'

// 获取积分配置
export function getScoreConfig() {
  return request.get<unknown, ScoreConfigData>('/admin/score/config')
}

// 保存积分配置
export function saveScoreConfig(data: ScoreConfigData) {
  return request.post<unknown, null>('/admin/score/config', data)
}

// 会员积分列表
export function getScoreMemberList(params: {
  page?: number
  pagesize?: number
  keyword?: string
}) {
  return request.get<unknown, ScoreMemberListResult>('/admin/score/memberlist', {
    params,
  })
}

// 积分调整（score 整数可负）
export function adjustScore(data: ScoreAdjustParams) {
  return request.post<unknown, null>('/admin/score/adjust', data)
}

// 积分流水
export function getScoreLog(params: {
  mid: number
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, ScoreLogListResult>('/admin/score/log', { params })
}

// ============ 任务对齐点大 score/scorelog.html：积分明细完整列表 / 删除 / 撤销 / 导出（类型就近放，不改 types.ts） ============

/** 积分明细行（含会员头像/昵称/变更后剩余/操作显隐） */
export interface ScoreLogItem {
  id: number
  mid: number
  nickname: string
  headimg: string
  tel: string
  score: number
  after_score: number
  remark: string
  type: number
  create_time: number
  is_cancel: number
  un: string
  can_cancel: boolean
  can_delete: boolean
}

export interface ScoreLogPageResult {
  list: ScoreLogItem[]
  total: number
  page: number
  pagesize: number
  stat: { count: number }
}

export interface ScoreLogListQuery {
  page?: number
  pagesize?: number
  mid?: number
  nickname?: string
  remark?: string
  start_date?: string
  end_date?: string
}

/** 积分明细完整列表 */
export function getScoreLogList(params: ScoreLogListQuery) {
  return request.get<unknown, ScoreLogPageResult>('/admin/score/logList', { params })
}

/** 积分明细导出（blob） */
export function exportScoreLog(params: ScoreLogListQuery) {
  return request.get('/admin/score/logExport', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}

/** 积分明细批量删除（兼容单 id 与 ids[]） */
export function deleteScoreLog(data: { id?: number; ids?: number[] }) {
  return request.post<unknown, { deleted: number }>('/admin/score/logDelete', data)
}

/** 积分撤销（对齐点大 Score::cancel，未撤销才处理） */
export function cancelScoreLog(data: { id?: number; ids?: number[] }) {
  return request.post<unknown, { canceled: number }>('/admin/score/cancel', data)
}
