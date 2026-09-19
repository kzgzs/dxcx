import request from './request'

// ============ 公众号粉丝与群发（批次7-4 任务212，对标参考系统 Mpfans） ============

export interface FansItem {
  id: number
  openid: string
  unionid: string
  nickname: string
  headimgurl: string
  sex: string
  city: string
  province: string
  country: string
  subscribe: number
  subscribe_time: number
  tag: string
  tagname: string
  remark: string
}

export interface FansTagItem {
  id: number
  name: string
  tagid: number
  count?: number
}

export interface TmplOption {
  field: string
  label: string
  template_id: string
  template_id_new: string
}

export interface TmplSendLogItem {
  id: number
  title: string
  template_id: string
  url: string
  to_type: number
  tagid: number
  tagname: string
  data: Record<string, unknown>
  createtime: number
  endtime: number
  sendcount: number
  successcount: number
  errorcount: number
  last_page: number
  pagelimit: number
}

export interface SendErrLogItem {
  id: number
  logid: number
  openid: string
  errcode: string
  errmsg: string
  createtime: number
}

export interface SourceItem {
  media_id: string
  update_time: number
  content: { news_item: Array<Record<string, unknown>> }
}

export interface SyncResult {
  total?: number
  totalpage?: number
  openids?: string[][]
  next_openid?: string
  count?: number
  fixed?: number
}

export interface SendResult {
  status: number
  logid: number
  pagenum: number
  sendcount: number
  successcount: number
  errorcount: number
}

// ---------- 粉丝列表 ----------

export function getFansList(params: {
  page: number
  pagesize: number
  keyword?: string
  tagid?: number
  subscribe?: string
}) {
  return request.get<unknown, { list: FansItem[]; total: number }>('/admin/mpfans/fansList', { params })
}

export function deleteFans(ids: number[]) {
  return request.post<unknown, unknown>('/admin/mpfans/fansDelete', { ids })
}

export function setFansTag(fansids: number[], tagids: number[]) {
  return request.post<unknown, unknown>('/admin/mpfans/setTag', { fansids, tagids })
}

// 粉丝四步同步（前端驱动：getpage→getopenid→getuserinfo→xiuzheng）
export function syncFans(data: { op: 'getpage' | 'getopenid' | 'getuserinfo' | 'xiuzheng'; next_openid?: string; openids?: string[] }) {
  return request.post<unknown, SyncResult>('/admin/mpfans/sync', data)
}

// ---------- 标签管理 ----------

export function getTagsList() {
  return request.get<unknown, { list: FansTagItem[] }>('/admin/mpfans/tagsList')
}

export function saveTag(data: { id?: number; name: string }) {
  return request.post<unknown, { id: number; tagid?: number }>('/admin/mpfans/tagsSave', data)
}

export function deleteTags(tagids: number[]) {
  return request.post<unknown, unknown>('/admin/mpfans/tagsDelete', { tagids })
}

export function syncTags() {
  return request.post<unknown, unknown>('/admin/mpfans/syncTags')
}

// ---------- 图文素材 ----------

export function getSourceList(params: { page: number; pagesize: number }) {
  return request.get<unknown, { list: SourceItem[]; total: number }>('/admin/mpfans/sourceList', { params })
}

export function getSourceDetail(mediaId: string) {
  return request.get<unknown, { media_id: string; news_item: Array<Record<string, unknown>> }>('/admin/mpfans/sourceDetail', {
    params: { media_id: mediaId },
  })
}

export function saveSource(data: { media_id?: string; news_item: Array<Record<string, unknown>>; old_news_item?: Array<Record<string, unknown>> }) {
  return request.post<unknown, { media_id: string }>('/admin/mpfans/sourceSave', data)
}

export function deleteSource(mediaId: string) {
  return request.post<unknown, unknown>('/admin/mpfans/sourceDelete', { media_id: mediaId })
}

// ---------- 模板消息群发 ----------

export function getTmplsendPrepare() {
  return request.get<unknown, { templates: TmplOption[]; tags: FansTagItem[]; fanscount: number }>('/admin/mpfans/tmplsendPrepare')
}

export function sendTmplMsg(data: {
  info?: { title?: string; template_id?: string; url?: string; to_type: number; tagid?: number; openids?: string }
  data?: Record<string, string>
  pagenum?: number
  pagelimit?: number
  logid?: number
}) {
  return request.post<unknown, SendResult>('/admin/mpfans/tmplSend', data)
}

export function getTmplSendLog(params: { page: number; pagesize: number }) {
  return request.get<unknown, { list: TmplSendLogItem[]; total: number }>('/admin/mpfans/tmplSendLog', { params })
}

export function deleteTmplSendLog(ids: number[]) {
  return request.post<unknown, unknown>('/admin/mpfans/tmplSendLogDelete', { ids })
}

export function getTmplSendErrLog(params: { logid: number; page: number; pagesize: number }) {
  return request.get<unknown, { list: SendErrLogItem[]; total: number }>('/admin/mpfans/tmplSendErrLog', { params })
}

// ---------- 客服图文群发（48小时，素材库数据源） ----------

export function sendKfMsg(data: {
  info?: { title?: string; media_id?: string; to_type: number; tagid?: number; openids?: string }
  pagenum?: number
  pagelimit?: number
  logid?: number
}) {
  return request.post<unknown, SendResult>('/admin/mpfans/kfmsgSend', data)
}

export function getKfMsgSendLog(params: { page: number; pagesize: number }) {
  return request.get<unknown, { list: Array<Record<string, unknown>>; total: number }>('/admin/mpfans/kfmsgSendLog', { params })
}

export function deleteKfMsgSendLog(ids: number[]) {
  return request.post<unknown, unknown>('/admin/mpfans/kfmsgSendLogDelete', { ids })
}

export function getKfMsgSendErrLog(params: { logid: number; page: number; pagesize: number }) {
  return request.get<unknown, { list: SendErrLogItem[]; total: number }>('/admin/mpfans/kfmsgSendErrLog', { params })
}
