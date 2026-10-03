import request from './request'

// 购物满减设置（对齐参考系统 Manjian/set）
export interface ManjianTier {
  money: number
  jian: number
}

export interface ManjianConfig {
  mjdata: ManjianTier[]
  fwtype: number
  categoryids: string
  productids: string
  total_status: number
  status: number
}

export interface ManjianSavePayload {
  fwtype: number
  categoryids: string
  productids: string
  total_status: number
  status: number
  money: number[]
  jian: number[]
}

// 读取购物满减配置
export function getManjianConfig() {
  return request.get<unknown, ManjianConfig>('/admin/manjian/config')
}

// 保存购物满减配置
export function saveManjianConfig(data: ManjianSavePayload) {
  return request.post<unknown, null>('/admin/manjian/save', data)
}
