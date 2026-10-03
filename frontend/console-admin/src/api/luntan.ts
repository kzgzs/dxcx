import request from './request'

// ===== 用户论坛整组（批次5-B 任务195），类型自包含 =====

// 帖子行
export interface LuntanPostItem {
  id: number
  aid: number
  cid: number
  mid: number
  nickname: string
  headimg: string
  content: string
  pics: string
  video: string
  readcount: number
  zan: number
  status: number
  is_top: number
  createtime: number
  cname?: string
  plcount?: number
  pics_arr?: string[]
  createtime_text?: string
}

// 版块行（树形含 children）
export interface LuntanCategoryItem {
  id: number
  pid: number
  name: string
  pic: string
  banner: string
  sort: number
  status: number
  goods_status: number
  post_pay: number
  post_score: number
  top_options: { hour: number; price: number }[]
  coupon_ids: string
  starttime: number
  endtime: number
  limitnum: number
  postnum?: number
  starttime_text?: string
  endtime_text?: string
  children?: LuntanCategoryItem[]
}

// 评论行
export interface LuntanPinglunItem {
  id: number
  sid: number
  mid: number
  nickname: string
  headimg: string
  content: string
  status: number
  zan: number
  createtime: number
  post_content?: string
  post_pics?: string[]
  post_video?: string
  replycount?: number
  createtime_text?: string
}

// 回评行
export interface LuntanPlreplyItem {
  id: number
  sid: number
  pid: number
  mid: number
  nickname: string
  headimg: string
  content: string
  status: number
  zan: number
  createtime: number
  pinglun_content?: string
  createtime_text?: string
}

// 系统设置
export interface LuntanSyssetData {
  title: string
  pic: string
  picurl: string
  sendtj: string[]
  sendcheck: number
  pingluncheck: number
  cansave: number
  status: number
  member_levels?: { id: number; name: string }[]
}

export interface LuntanListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: string
  cid?: number
  starttime?: string
  endtime?: string
}

export interface LuntanCategorySaveParams {
  id?: number
  pid: number
  name: string
  pic?: string
  banner?: string
  sort?: number
  status?: number
  goods_status?: number
  post_pay?: number
  post_score?: number
  top_options?: { hour: number; price: number }[]
  coupon_ids?: string | string[]
  starttime?: string | number
  endtime?: string | number
  limitnum?: number
}

export interface LuntanPageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

// ===== B端 - 帖子 =====
export function getLuntanList(params: LuntanListQuery) {
  return request.get<unknown, LuntanPageResult<LuntanPostItem>>('/admin/luntan/list', { params })
}

export function auditLuntan(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/luntan/audit', { ids, status })
}

export function setLuntanTop(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/luntan/setTop', { ids, st })
}

export function deleteLuntan(ids: number[]) {
  return request.post<unknown, null>('/admin/luntan/delete', { ids })
}

// 帖子新增/编辑入参
export interface LuntanPostSaveParams {
  id?: number
  mid: number
  cid: number
  content: string
  pics?: string
  video?: string
  readcount?: number
  zan?: number
  status: number
}

export function getLuntanDetail(id: number) {
  return request.get<unknown, LuntanPostItem>('/admin/luntan/detail', { params: { id } })
}

export function saveLuntanPost(data: LuntanPostSaveParams) {
  return request.post<unknown, { id: number }>('/admin/luntan/save', data)
}

export function getLuntanCategoryOptions() {
  return request.get<unknown, LuntanCategoryItem[]>('/admin/luntan/categoryOptions')
}

export function getLuntanSysset() {
  return request.get<unknown, LuntanSyssetData>('/admin/luntan/sysset')
}

export function saveLuntanSysset(data: LuntanSyssetData) {
  return request.post<unknown, null>('/admin/luntan/syssetSave', data)
}

// ===== B端 - 版块 =====
export function getLuntanCategoryList() {
  return request.get<unknown, LuntanCategoryItem[]>('/admin/luntanCategory/list')
}

export function saveLuntanCategory(data: LuntanCategorySaveParams) {
  return request.post<unknown, { id: number }>('/admin/luntanCategory/save', data)
}

export function deleteLuntanCategory(ids: number[]) {
  return request.post<unknown, null>('/admin/luntanCategory/delete', { ids })
}

// ===== B端 - 评论 =====
export function getLuntanPinglunList(params: LuntanListQuery & { sid?: number }) {
  return request.get<unknown, LuntanPageResult<LuntanPinglunItem>>('/admin/luntanPinglun/list', { params })
}

export function auditLuntanPinglun(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/luntanPinglun/audit', { ids, status })
}

export function deleteLuntanPinglun(ids: number[]) {
  return request.post<unknown, null>('/admin/luntanPinglun/delete', { ids })
}

// ===== B端 - 回评 =====
export function getLuntanPlreplyList(params: LuntanListQuery) {
  return request.get<unknown, LuntanPageResult<LuntanPlreplyItem>>('/admin/luntanPlreply/list', { params })
}

export function auditLuntanPlreply(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/luntanPlreply/audit', { ids, status })
}

export function deleteLuntanPlreply(ids: number[]) {
  return request.post<unknown, null>('/admin/luntanPlreply/delete', { ids })
}
