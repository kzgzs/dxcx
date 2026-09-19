import request from './request'
import type {
  ExpressDataMap,
  ExpressDataResult,
  FreightListResult,
  FreightSaveParams,
  TenantSyssetData,
} from './types'

// ============ 租户系统设置（admin_set） ============

// 读取系统设置
export function getTenantSysset() {
  return request.get<unknown, TenantSyssetData>('/admin/sysset')
}

// 保存系统设置
export function saveTenantSysset(data: TenantSyssetData) {
  return request.post<unknown, null>('/admin/sysset', data)
}

// ============ 配送方式（freight） ============

// 配送方式列表
export function getFreightList() {
  return request.get<unknown, FreightListResult>('/admin/freight/list')
}

// 新增/编辑配送方式
export function saveFreight(data: FreightSaveParams) {
  return request.post<unknown, { id: number }>('/admin/freight/save', data)
}

// 删除配送方式
export function deleteFreight(id: number) {
  return request.post<unknown, null>('/admin/freight/delete', { id })
}

// 启用/停用配送方式
export function setFreightStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/freight/status', { id, status })
}

// ============ 快递设置（express_data） ============

// 读取快递设置（含内置候选字典）
export function getExpressData() {
  return request.get<unknown, ExpressDataResult>('/admin/expressdata')
}

// 保存快递设置（启用的 公司名=>编码 映射）
export function saveExpressData(data: ExpressDataMap) {
  return request.post<unknown, { data: ExpressDataMap }>('/admin/expressdata', { data })
}
