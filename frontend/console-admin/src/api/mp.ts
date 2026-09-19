import request from './request'

// ============ 公众号运营（批次7-2 任务210：菜单设置/关键字回复/被关注回复） ============

/** 回复消息类型选项（七种，对标参考系统 Mpkeyword） */
export const MSGTYPE_OPTIONS = [
  { value: 'text', label: '文本' },
  { value: 'image', label: '图片' },
  { value: 'voice', label: '语音' },
  { value: 'video', label: '视频' },
  { value: 'music', label: '音乐' },
  { value: 'news', label: '图文' },
  { value: 'miniprogrampage', label: '小程序页面' },
]

/** 消息类型中文标签 */
export function msgtypeLabel(value: string) {
  return MSGTYPE_OPTIONS.find((t) => t.value === value)?.label || value
}


/** 菜单按钮（本地 menudata 结构，对标参考系统 Mpmenu 编辑器字段） */
export interface MpMenuButton {
  menu_name: string
  /** view 链接 / click 关键字(素材) / miniprogram 小程序 */
  menu_type?: string
  /** view 链接 / miniprogram 兜底H5链接 */
  menu_url?: string
  menu_appid?: string
  menu_pagepath?: string
  /** click 类消息内容：text/image/voice/video/news */
  message_type?: string
  text_content?: string
  image_content?: string
  voice_content?: string
  video_content?: string
  video_title?: string
  video_introduction?: string
  /** news 类存图文素材 media_id */
  news_content?: string
  sub_button?: MpMenuButton[]
}

/** 关键字/关注回复记录（mp_keyword） */
export interface MpKeywordItem {
  id: number
  aid?: number
  keyword: string
  /** 0完全匹配 1包含匹配 2关注回复 */
  ktype: number
  /** text/image/voice/video/music/news/miniprogrampage */
  msgtype: string
  content: string
  sort: number
  createtime: number
  status: number
}

/** 图文素材条目（batchget_material type=news） */
export interface MpNewsItem {
  media_id: string
  update_time?: number
  content?: {
    news_item?: { title: string; digest?: string; thumb_url?: string; url?: string }[]
  }
}

// ---------- 菜单 ----------

// 读取菜单编辑数据
export function getMpMenu() {
  return request.get<unknown, { menudata: MpMenuButton[]; appid: string }>('/admin/mp/menu')
}

// 保存菜单编辑数据（仅落库不同步）
export function saveMpMenu(menudata: MpMenuButton[]) {
  return request.post<unknown, null>('/admin/mp/menu/save', { menudata })
}

// 同步菜单到微信（menuCreate/menuDelete）
export function syncMpMenu() {
  return request.post<unknown, { action: string; button: unknown[]; is_mock: boolean }>('/admin/mp/menu/sync')
}

// 获取线上菜单回填
export function fetchMpMenu() {
  return request.post<unknown, { menudata: MpMenuButton[] }>('/admin/mp/menu/fetch')
}

// 素材文件上传（image/voice/video，multipart 字段 file）
export function uploadMpMedia(file: File, type: 'image' | 'voice' | 'video') {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('type', type)
  return request.post<unknown, { url: string }>('/admin/mp/media/upload', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

// 图文素材列表（news 选择器）
export function getMpNewsList(page = 1, limit = 20) {
  return request.get<unknown, { count: number; data: MpNewsItem[] }>('/admin/mp/material/newslist', {
    params: { page, limit },
  })
}

// ---------- 关键字回复 ----------

// 关键字回复列表（ktype 0/1）
export function getMpKeywordList(params: { page?: number; limit?: number; keyword?: string }) {
  return request.get<unknown, { count: number; data: MpKeywordItem[] }>('/admin/mp/keyword/index', { params })
}

// 关键字回复保存（新增/编辑，素材类后端自动换取 MediaId）
export function saveMpKeyword(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/mp/keyword/save', data)
}

// 关键字回复删除
export function deleteMpKeyword(ids: number[]) {
  return request.post<unknown, null>('/admin/mp/keyword/del', { ids })
}

// ---------- 被关注回复 ----------

// 被关注回复列表（ktype=2，最多3条）
export function getMpSubscribeList() {
  return request.get<unknown, { list: MpKeywordItem[]; limit: number }>('/admin/mp/keyword/subscribe')
}

// 被关注回复保存（新增时后端校验3条上限）
export function saveMpSubscribe(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/mp/keyword/subscribeSave', data)
}

// 被关注回复删除
export function deleteMpSubscribe(ids: number[]) {
  return request.post<unknown, null>('/admin/mp/keyword/subscribeDel', { ids })
}
