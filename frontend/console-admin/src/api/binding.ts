import request from './request'

// ============ 平台绑定中心（八端配置） ============

export type PlatformKey = 'wx' | 'mp' | 'alipay' | 'baidu' | 'qq' | 'toutiao' | 'h5' | 'app'

export interface BindingOverviewItem {
  bound: boolean
  appid: string
  nickname: string
  // 仅 wx/mp
  authtype?: number
  mode?: string
  headimg?: string
  qrcode?: string
  level?: number
  auth_status?: number | null
}

export type BindingOverview = Record<PlatformKey, BindingOverviewItem>

export type BindingConfig = Record<string, string | number>

// 各端绑定状态聚合
export function getBindingOverview() {
  return request.get<unknown, BindingOverview>('/admin/binding/overview')
}

// 读取单端配置（密钥类字段脱敏）
export function getBindingConfig(platform: PlatformKey) {
  return request.get<unknown, BindingConfig>(`/admin/binding/config/${platform}`)
}

// 保存单端手动配置（密钥留空/*** 沿用原值）
export function saveBindingConfig(platform: PlatformKey, data: BindingConfig) {
  return request.post<unknown, null>(`/admin/binding/save/${platform}`, data)
}

// 生成开放平台授权链接（auth_type 1公众号 2小程序）
export function getWxAuthUrl(authType: 1 | 2) {
  return request.get<unknown, { url: string; expires_in: number }>('/admin/binding/wxAuthUrl', {
    params: { auth_type: authType },
  })
}

// 重新拉取授权信息（authtype=1 的微信绑定）
export function refreshBindingAuth(platform: 'wx' | 'mp') {
  return request.post<unknown, { appid: string; nickname: string; headimg: string; qrcode: string; level: number }>(
    '/admin/binding/refreshAuth',
    { platform },
  )
}
