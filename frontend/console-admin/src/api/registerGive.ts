import request from './request'

// ============ 注册赠送设置（批次4-A 任务186） ============

export interface RegisterGiveConfig {
  money: number
  score: number
  wanshan_score: number
  give_coupon: number
  coupon_ids: string
  starttime: string
  endtime: string
  status: number
}

export interface RegisterGiveSavePayload {
  status: number
  money: number
  score: number
  wanshan_score: number
  give_coupon: number
  coupon_ids: string
  starttime: string
  endtime: string
}

// 读取配置
export function getRegisterGiveConfig() {
  return request.get<unknown, RegisterGiveConfig>('/admin/registerGive/config')
}

// 保存配置
export function saveRegisterGiveConfig(data: RegisterGiveSavePayload) {
  return request.post<unknown, null>('/admin/registerGive/save', data)
}
