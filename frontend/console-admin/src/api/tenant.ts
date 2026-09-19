import request from './request'
import { TOKEN_KEY } from './request'
import type {
  TenantAddParams,
  TenantEditParams,
  TenantListQuery,
  TenantListResult,
  ProxyLoginResult,
} from './types'

// 租户列表
export function getTenantList(params: TenantListQuery) {
  return request.get<unknown, TenantListResult>('/console/tenant/list', { params })
}

// 新增租户
export function addTenant(data: TenantAddParams) {
  return request.post<unknown, { id: number }>('/console/tenant/add', data)
}

// 编辑租户
export function editTenant(data: TenantEditParams) {
  return request.post<unknown, null>('/console/tenant/edit', data)
}

// 删除租户
export function deleteTenant(id: number) {
  return request.post<unknown, null>('/console/tenant/delete', { id })
}

// 修改租户状态（1正常 / 2关闭）
export function setTenantStatus(id: number, status: number) {
  return request.post<unknown, null>('/console/tenant/status', { id, status })
}

// 租户续费
export function renewTenant(id: number, days: number) {
  return request.post<unknown, null>('/console/tenant/renew', { id, days })
}

// 代登录（以该租户身份登录租户后台）
export function proxyLogin(id: number) {
  return request.post<unknown, ProxyLoginResult>('/console/tenant/proxyLogin', { id })
}

// 下载 uni-app 代码包（zip 字节流，携带鉴权头触发浏览器下载）
export async function downloadUniappZip(): Promise<void> {
  const token = localStorage.getItem(TOKEN_KEY) || ''
  const resp = await fetch('/console/tenant/uniappZip', {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
  if (!resp.ok) {
    let msg = '下载失败'
    try {
      const j = (await resp.json()) as { msg?: string }
      if (j && j.msg) msg = j.msg
    } catch {
      // 非 JSON 响应体，忽略
    }
    throw new Error(msg)
  }
  const blob = await resp.blob()
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'uniapp-code-package.zip'
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}
