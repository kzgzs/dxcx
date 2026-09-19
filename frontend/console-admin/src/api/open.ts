import request from './request'
import type {
  OpenConfigData,
  OpenConfigSaveParams,
  AuthorizerListQuery,
  AuthorizerListResult,
  AuthorizerDetail,
  BindUrlParams,
  BindUrlResult,
} from './types'

// 获取开放平台配置
export function getOpenConfig() {
  return request.get<unknown, OpenConfigData>('/console/open/config')
}

// 保存开放平台配置（component_appsecret 传空串表示不修改）
export function saveOpenConfig(data: OpenConfigSaveParams) {
  return request.post<unknown, null>('/console/open/config', data)
}

// 上传代码到小程序模板库（草稿箱最新草稿转模板）
export function uploadTemplateCode() {
  return request.post<unknown, { template_id: number; draft_id: number }>('/console/open/uploadTemplate')
}

// 上传域名校验文件（txt 写入 public 根目录）
export function uploadVerifyFile(file: File) {
  const fd = new FormData()
  fd.append('file', file)
  return request.post<unknown, { filename: string; url: string }>('/console/open/uploadVerifyFile', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

// 授权账号列表（支持昵称/AppID/账号类型筛选）
export function getAuthorizerList(params: AuthorizerListQuery) {
  return request.get<unknown, AuthorizerListResult>('/console/open/authorizer/list', { params })
}

// 授权账号完整详情
export function getAuthorizerDetail(id: number) {
  return request.get<unknown, AuthorizerDetail>('/console/open/authorizer/detail', { params: { id } })
}

// 刷新授权方令牌（后端模拟/真实刷新）
export function refreshAuthorizerToken(id: number) {
  return request.post<unknown, null>('/console/open/authorizer/refresh', { id })
}

// 发起授权：获取微信授权页 URL
export function getBindUrl(data: BindUrlParams) {
  return request.post<unknown, BindUrlResult>('/console/open/bindUrl', data)
}

// 解绑授权账号
export function unbindAuthorizer(id: number) {
  return request.post<unknown, null>('/console/open/unbind', { id })
}
