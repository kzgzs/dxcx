import request from './request'
import type { AdminMenuItem } from './types'

/** 菜单权限返回结构（批次2-C：mode=all 全量 / keys 按 auth_data 过滤） */
export interface AdminMenuAuthResult {
  mode: 'all' | 'keys'
  keys: string[]
  menus: AdminMenuItem[]
}

// 租户后台菜单（按当前账号权限过滤返回；silent 为 true 时不弹错误提示）
export function getAdminMenu(silent = false) {
  return request.get<unknown, AdminMenuAuthResult>('/admin/menu', {
    silentError: silent,
  })
}

/** 清缓存结果（对齐 console/system/clearCache 返回结构） */
export interface AdminClearCacheResult {
  results: string[]
  file_count: number
}

// 一键清理缓存（租户侧，当页执行不刷新不跳转）
export function adminClearCache() {
  return request.post<unknown, AdminClearCacheResult>('/admin/system/clearCache')
}
