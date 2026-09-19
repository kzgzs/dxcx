import request from './request'

// ============ 小程序代码发布链（P15-2） ============

export interface WxappOverview {
  appid: string
  nickname: string
  is_mock: boolean
  last_audit: Record<string, unknown> | null
  last_release: Record<string, unknown> | null
  logs: Array<Record<string, unknown>>
}

export interface WxappTemplate {
  template_id: number
  user_version: string
  user_desc: string
  create_time: number | string
  source_miniprogram?: string
  source_miniprogram_name?: string
}

export interface WxappAuditStatus {
  audit_id: number
  status: number
  reason: string
  audit_status: number
}

export interface WxalogPage {
  list: Array<Record<string, unknown>>
  total: number
  page: number
  pagesize: number
}

export interface WxappCommitParams {
  template_id: number
  version: string
  desc: string
  homeNavigationCustom?: number
  usercenterNavigationCustom?: number
  hideHomeButton?: number
  navigationBarBackgroundColor?: string
  navigationBarTextStyle?: string
}

// 发布链总览（绑定信息 + 最近提审/发布 + 最近流水）
export function getWxappOverview() {
  return request.get<unknown, WxappOverview>('/admin/wxapp/overview')
}

// 模板列表（超管开放平台模板库）
export function getWxappTemplateList() {
  return request.get<unknown, { list: WxappTemplate[] }>('/admin/wxapp/templateList')
}

// 上传代码（commit：ext.json 注入 aid/baseurl/底部菜单）
export function commitWxappCode(data: WxappCommitParams) {
  return request.post<unknown, { log_id: number }>('/admin/wxapp/commit', data)
}

// 提交审核
export function submitWxappAudit() {
  return request.post<unknown, Record<string, unknown>>('/admin/wxapp/submitAudit')
}

// 审核状态（默认最近一次提审）
export function getWxappAuditStatus(auditId = 0) {
  return request.get<unknown, WxappAuditStatus>('/admin/wxapp/getAuditStatus', {
    params: { audit_id: auditId },
  })
}

// 发布（审核通过后）
export function releaseWxapp() {
  return request.post<unknown, null>('/admin/wxapp/release')
}

// 撤回审核
export function undoWxappAudit() {
  return request.post<unknown, null>('/admin/wxapp/undocodeaudit')
}

// 版本回退
export function revertWxappRelease(appversion: string) {
  return request.post<unknown, null>('/admin/wxapp/revertCodeRelease', { appversion })
}

// 体验版二维码（data:image/png;base64 前缀）
export function getWxappTrialQrcode() {
  return request.get<unknown, { qrcode: string }>('/admin/wxapp/getTrialQrcode')
}

// 绑定体验者
export function bindWxappTester(wechatid: string) {
  return request.post<unknown, null>('/admin/wxapp/bindTester', { wechatid })
}

// 更新记录（发布流水）
export function getWxalogList(page = 1, pagesize = 20) {
  return request.get<unknown, WxalogPage>('/admin/wxapp/wxalogList', {
    params: { page, pagesize },
  })
}

// ============ 小程序运营设置 ============

export interface WxappAccountInfo {
  appid: string
  nickname: string
  headimg: string
  signature: string
  search_status: number
}

export interface WxappServerDomain {
  requestdomain: string
  wsrequestdomain: string
  uploaddomain: string
  downloaddomain: string
}

export interface WxappPrivacyKey {
  privacy_key: string
  privacy_desc: string
}

export interface WxappPrivacyInfo {
  owner_setting: Record<string, string>
  setting_list: Array<Record<string, unknown>>
  privacy_keys: WxappPrivacyKey[]
}

// 账号资料概览
export function getWxappAccountInfo() {
  return request.get<unknown, WxappAccountInfo>('/admin/wxappSet/accountInfo')
}

export function setWxappNickname(nickname: string) {
  return request.post<unknown, null>('/admin/wxappSet/setNickname', { nickname })
}

export function setWxappHeadimg(headimg: string) {
  return request.post<unknown, null>('/admin/wxappSet/setHeadimg', { headimg })
}

export function setWxappSignature(signature: string) {
  return request.post<unknown, null>('/admin/wxappSet/setSignature', { signature })
}

export function setWxappSearchStatus(open: 0 | 1) {
  return request.post<unknown, null>('/admin/wxappSet/setSearchStatus', { open })
}

// 服务器域名快配（GET 读取 / POST 保存）
export function getWxappServerDomain() {
  return request.get<unknown, WxappServerDomain>('/admin/wxappSet/serverDomain')
}

export function saveWxappServerDomain(info: WxappServerDomain) {
  return request.post<unknown, null>('/admin/wxappSet/serverDomain', { info })
}

// 业务域名（GET 读取 / POST 保存）
export function getWxappJumpDomain() {
  return request.get<unknown, { webviewdomain: string }>('/admin/wxappSet/jumpDomain')
}

export function saveWxappJumpDomain(webviewdomain: string) {
  return request.post<unknown, null>('/admin/wxappSet/jumpDomain', { webviewdomain })
}

// 生成业务域名校验文件（写 public/ 下，返回下载链接）
export function getWxappJumpDomainFile() {
  return request.get<unknown, { file_name: string; url: string; content: string }>(
    '/admin/wxappSet/jumpDomainFile',
  )
}

// 隐私指引（GET 查询 / POST 保存）
export function getWxappPrivacy() {
  return request.get<unknown, WxappPrivacyInfo>('/admin/wxappSet/privacy')
}

export function saveWxappPrivacy(ownerSetting: Record<string, string>, settingList: Array<Record<string, unknown>>) {
  return request.post<unknown, null>('/admin/wxappSet/privacy', {
    owner_setting: ownerSetting,
    setting_list: settingList,
  })
}

// ICP 备案（admin_wxicp：信息/保存/人脸核身桩/提交）
export function getWxappIcpInfo() {
  return request.get<unknown, Record<string, unknown>>('/admin/wxappSet/icpInfo')
}

export function saveWxappIcp(data: Record<string, unknown>) {
  return request.post<unknown, null>('/admin/wxappSet/icpSave', data)
}

export function launchWxappIcpFace() {
  return request.post<unknown, Record<string, unknown>>('/admin/wxappSet/icpFaceLaunch')
}

export function queryWxappIcpFace() {
  return request.get<unknown, Record<string, unknown>>('/admin/wxappSet/icpFaceQuery')
}

export function submitWxappIcp() {
  return request.post<unknown, Record<string, unknown>>('/admin/wxappSet/icpSubmit')
}
