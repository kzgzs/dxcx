import request from './request'

/* ================= 类型 ================= */

// 短视频系统设置
export interface ShortvideoSyssetData {
  list_type: number // 列表样式
  can_upload: number // 会员投稿开关
  upload_maxsize: number // 投稿视频大小上限（M）
  upload_maxduration: number // 投稿视频时长上限（秒）
  upload_check: number // 投稿审核
  show_business_video: number // 多商户视频 C 端展示
}

// 短视频分类行
export interface ShortvideoCategoryItem {
  id: number
  bid: number
  bname: string
  name: string
  status: number
  sort: number
  videonum: number
  createtime: number
}

// 短视频行
export interface ShortvideoItem {
  id: number
  bid: number
  bname: string
  mid: number
  nickname: string
  headimg: string
  cid: number
  cname: string
  name: string
  url: string
  video_duration: number
  coverimg: string
  description: string
  productids: number[]
  linkurl: string
  linkname: string
  comment: number
  comment_check: number
  comment_num: number
  view_num: number
  zan_num: number
  share_num: number
  sort: number
  status: number
  status_text: string
  reason: string
  createtime: number
}

// 评论行
export interface ShortvideoCommentItem {
  id: number
  vid: number
  vname: string
  mid: number
  nickname: string
  headimg: string
  content: string
  zan: number
  status: number
  status_text: string
  replycount: number
  createtime: number
}

// 回评行
export interface ShortvideoReplyItem {
  id: number
  pid: number
  pcontent: string
  vid: number
  vname: string
  mid: number
  nickname: string
  headimg: string
  content: string
  status: number
  status_text: string
  createtime: number
}

export interface ShortvideoListResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

/* ================= 系统设置 ================= */

export function getShortvideoSysset() {
  return request.get<unknown, ShortvideoSyssetData>('/admin/shortvideo/sysset/get')
}

export function saveShortvideoSysset(data: Partial<ShortvideoSyssetData>) {
  return request.post<unknown, null>('/admin/shortvideo/sysset/save', data)
}

/* ================= 分类 ================= */

export function getShortvideoCategoryList(params: {
  page?: number
  pagesize?: number
  keyword?: string
  bid?: string
}) {
  return request.get<unknown, ShortvideoListResult<ShortvideoCategoryItem>>(
    '/admin/shortvideo/category/list',
    { params },
  )
}

export function getShortvideoCategoryOptions(bid?: string) {
  return request.get<unknown, Array<{ id: number; name: string }>>(
    '/admin/shortvideo/category/options',
    { params: bid ? { bid } : {} },
  )
}

export function saveShortvideoCategory(data: {
  id?: number
  name: string
  status: number
  sort: number
  bid?: number
}) {
  return request.post<unknown, { id: number }>('/admin/shortvideo/category/save', data)
}

export function deleteShortvideoCategory(id: number) {
  return request.post<unknown, null>('/admin/shortvideo/category/delete', { id })
}

/* ================= 视频 ================= */

export function getShortvideoList(params: {
  page?: number
  pagesize?: number
  keyword?: string
  status?: string | number
  cid?: number
  bid?: string
  createtime?: string
}) {
  return request.get<unknown, ShortvideoListResult<ShortvideoItem>>('/admin/shortvideo/video/list', {
    params,
  })
}

export function getShortvideoDetail(id: number) {
  return request.get<unknown, ShortvideoItem>('/admin/shortvideo/video/detail', {
    params: { id },
  })
}

export function saveShortvideo(data: Record<string, unknown> & { id?: number; name: string }) {
  return request.post<unknown, { id: number }>('/admin/shortvideo/video/save', data)
}

export function setShortvideoStatus(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/shortvideo/video/status', { ids, status })
}

export function checkShortvideo(id: number, status: number, reason = '') {
  return request.post<unknown, null>('/admin/shortvideo/video/check', { id, status, reason })
}

export function deleteShortvideo(ids: number[]) {
  return request.post<unknown, null>('/admin/shortvideo/video/delete', { ids })
}

/* ================= 评论 ================= */

export function getShortvideoCommentList(params: {
  page?: number
  pagesize?: number
  status?: string | number
  vid?: number
  content?: string
}) {
  return request.get<unknown, ShortvideoListResult<ShortvideoCommentItem>>(
    '/admin/shortvideo/comment/list',
    { params },
  )
}

export function setShortvideoCommentStatus(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/shortvideo/comment/setst', { ids, status })
}

export function replyShortvideoComment(id: number, content: string) {
  return request.post<unknown, null>('/admin/shortvideo/comment/reply', { id, content })
}

export function deleteShortvideoComment(ids: number[]) {
  return request.post<unknown, null>('/admin/shortvideo/comment/delete', { ids })
}

/* ================= 回评 ================= */

export function getShortvideoReplyList(params: {
  page?: number
  pagesize?: number
  status?: string | number
  pid?: number
  content?: string
}) {
  return request.get<unknown, ShortvideoListResult<ShortvideoReplyItem>>(
    '/admin/shortvideo/reply/list',
    { params },
  )
}

export function setShortvideoReplyStatus(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/shortvideo/reply/setst', { ids, status })
}

export function deleteShortvideoReply(ids: number[]) {
  return request.post<unknown, null>('/admin/shortvideo/reply/delete', { ids })
}
