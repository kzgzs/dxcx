import request from './request'

// ============ 类型定义 ============

/** 系统升级页面信息（对照参考系统 WebUpgrade/index） */
export interface UpgradeInfo {
  myversion: string
  newversion: string
  needupgrade: number
  remark: string
}

/** 检测更新结果（message 为参考系统原文案） */
export interface UpgradeCheckResult {
  myversion: string
  newversion: string
  needupgrade: number
  message: string
}

/** 升级日志（对照参考系统 getupgradelog 时间轴数据） */
export interface UpgradeLogItem {
  id: number
  version: string
  remark: string
  createtime: number
}

// ============ 接口 ============

// 页面信息：当前版本 / 最新版本 / 是否需要升级 / 版本说明
export function getUpgradeInfo() {
  return request.get<unknown, UpgradeInfo>('/console/upgrade/info')
}

// 检测更新（当页提示结果）
export function checkUpgrade() {
  return request.post<unknown, UpgradeCheckResult>('/console/upgrade/check')
}

// 执行升级
export function doUpgrade() {
  return request.post<unknown, { version: string }>('/console/upgrade/doup')
}

// 升级日志列表
export function getUpgradeLog() {
  return request.get<unknown, UpgradeLogItem[]>('/console/upgrade/log')
}
