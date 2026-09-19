import request from './request'

// ============ 小程序扩展组（批次7-5 任务213） ============
// 服务类目 / 外部链接 / 半屏小程序 / 小程序客服关键字回复

// ---------- 服务类目（纯接口透传，无本地表） ----------

export interface WxCategoryItem {
  first: number
  second: number
  first_name?: string
  second_name?: string
  first_class?: string
  second_class?: string
  audit_status?: number
  audit_reason?: string
}

export interface WxCategoryOption {
  first: number
  first_name: string
  second: number
  second_name: string
  exter_list: string[]
}

export interface WxCategoryListResult {
  list: WxCategoryItem[]
  count: number
  createtype: number
  is_mock: boolean
}

// 已设置类目列表
export function getWxleimuList() {
  return request.get<unknown, WxCategoryListResult>('/admin/wxleimu/index')
}

// 可选类目（添加表单下拉）
export function getWxleimuOptions() {
  return request.get<unknown, { list: WxCategoryOption[] }>('/admin/wxleimu/options')
}

// 添加类目（certicates 为已上传图片 URL，后端转微信素材）
export interface WxleimuSaveItem {
  first: number
  second: number
  certicates: string[]
}

export function saveWxleimu(categories: WxleimuSaveItem[]) {
  return request.post<unknown, Record<string, unknown>>('/admin/wxleimu/save', { categories })
}

// 删除类目
export function delWxleimu(first: number, second: number) {
  return request.post<unknown, null>('/admin/wxleimu/del', { first, second })
}

// ---------- 外部链接（wx_url 本地表 + code 混淆码） ----------

export interface WxurlItem {
  id: number
  aid: number
  name: string
  path: string
  url: string
  createtime: number
  endtime: number
  days: number
  sort: number
  code: string
  expired?: number
}

export interface WxurlListResult {
  list: WxurlItem[]
  total: number
  page: number
  pagesize: number
}

export function getWxurlList(params: {
  page?: number
  pagesize?: number
  name?: string
  path?: string
  url?: string
}) {
  return request.get<unknown, WxurlListResult>('/admin/wxurl/index', { params })
}

export interface WxurlSaveInfo {
  id?: number
  name: string
  path: string
  url: string
  days: number
}

export function saveWxurl(info: WxurlSaveInfo) {
  return request.post<unknown, { id: number; code?: string }>('/admin/wxurl/save', { info })
}

export function delWxurl(ids: number[]) {
  return request.post<unknown, null>('/admin/wxurl/del', { ids })
}

// ---------- 半屏小程序（仅代授权 authtype=1 可用） ----------

export interface WxEmbeddedItem {
  appid: string
  nickname?: string
  status?: number
}

export interface WxEmbeddedResult {
  authtype: number
  list: WxEmbeddedItem[]
  count: number
  is_mock: boolean
}

export function getWxembedded() {
  return request.get<unknown, WxEmbeddedResult>('/admin/wxembedded/index')
}

// ---------- 小程序客服关键字回复（wx_keyword，与 mp_keyword 同构） ----------

export interface WxKeywordItem {
  id: number
  aid: number
  keyword: string
  ktype: number
  msgtype: string
  content: string
  sort: number
  createtime: number
  status: number
}

export interface WxKeywordListResult {
  list: WxKeywordItem[]
  total: number
  page: number
  pagesize: number
}

export function getWxKeywordList(params: {
  page?: number
  pagesize?: number
  keyword?: string
  ktype?: number | string
}) {
  return request.get<unknown, WxKeywordListResult>('/admin/wxkeyword/index', { params })
}

export interface WxKeywordSaveInfo {
  id?: number
  keyword: string
  ktype: number
  msgtype: string
  content: string
  sort: number
  status: number
}

export function saveWxKeyword(info: WxKeywordSaveInfo) {
  return request.post<unknown, { id: number }>('/admin/wxkeyword/save', { info })
}

export function delWxKeyword(ids: number[]) {
  return request.post<unknown, null>('/admin/wxkeyword/del', { ids })
}
