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

export interface CouponOption {
  id: number
  name: string
  type?: number
  money?: number
}

// 读取配置
export function getRegisterGiveConfig() {
  return request.get<unknown, RegisterGiveConfig>('/admin/registerGive/config')
}

// 保存配置
export function saveRegisterGiveConfig(data: Partial<RegisterGiveConfig>) {
  return request.post<unknown, null>('/admin/registerGive/save', data)
}

// 优惠券候选（送券多选）
export function getCouponOptions() {
  return request.get<unknown, { list: CouponOption[]; total: number }>('/admin/coupon/list', {
    params: { page: 1, pagesize: 200 },
  })
}
