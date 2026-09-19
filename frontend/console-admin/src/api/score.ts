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
