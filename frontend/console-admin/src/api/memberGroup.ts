import request from './request'

/* ================= 类型定义 ================= */

// 升级申请表单字段（后端解析「标题^_^值^_^类型」后的结构）
export interface LevelupFormField {
  title: string
  value: string
  type: string
}

// 升级申请记录
export interface LevelupOrderItem {
  id: number
  mid: number
  nickname: string
  headimg: string
  realname: string
  pid: number
  pnickname: string
  pheadimg: string
  ordernum: string
  title: string
  type: number // 0升级 1降级
  type_text: string
  status: number // 0待支付 1待审核 2已通过 3已驳回
  status_text: string
  totalprice: number
  paytype: string
  paytime: number
  beforelevelid: number
  before_level: string
  levelid: number
  apply_level: string
  createtime: number
  levelup_time: number
  platform: string
  formdata: LevelupFormField[]
}

export interface LevelupListQuery {
  page?: number
  pagesize?: number
  orderid?: number | ''
  mid?: number | ''
  nickname?: string
  realname?: string
  type?: string // all|0|1
  status?: string // all|0|1|2|3
  start_time?: string
  end_time?: string
  formx?: string
}

export interface LevelupListResult {
  list: LevelupOrderItem[]
  total: number
  page: number
  pagesize: number
}

// 会员关系图节点
export interface RelationNode {
  id: number
  name: string
  headimg: string
  realname: string
  tel: string
  pid: number
  levelid: number
  level_name: string
  downcount: number
  downcountall: number
  deeplevel: number
  hasMore: boolean
  children: RelationNode[]
}

// 海报画布元素（img/text/textarea/head/qrwx/qrmp）
export interface PosterElement {
  left: string
  top: string
  type:
    | 'img'
    | 'text'
    | 'textarea'
    | 'head'
    | 'shadow'
    | 'pro_img'
    | 'qrmp'
    | 'qrwx'
    | 'qralipay'
    | 'qrbaidu'
    | 'qrtoutiao'
    | 'qrqq'
  width: string
  height: string
  src?: string
  size?: string
  color?: string
  content?: string
  radius?: string
}

export interface PosterTemplate {
  id: number
  type: string
  platform: string
  poster_bg: string
  poster_data: PosterElement[]
  guize: string
  createtime: number
}

export interface PosterListItem {
  id: number
  createtime: number
  poster_bg: string
  element_num: number
}

/* ================= 升级申请审核 ================= */

// 升级申请列表
export function getLevelupList(params: LevelupListQuery) {
  return request.get<unknown, LevelupListResult>('/admin/memberLevelup/list', { params })
}

// 审核（st: 2通过 3驳回）
export function auditLevelup(data: { id: number; st: number; remark?: string }) {
  return request.post<unknown, { status: number; refund: Record<string, unknown> }>(
    '/admin/memberLevelup/audit',
    data,
  )
}

// 删除升级申请记录（单条/批量）
export function deleteLevelup(ids: number[]) {
  return request.post<unknown, { count: number }>('/admin/memberLevelup/delete', { ids })
}

/* ================= 会员关系图 ================= */

// 关系图主接口（mid 或 keyword 定位根节点）
export function getRelationChart(params: { mid?: number; keyword?: string; depth?: number }) {
  return request.get<unknown, { root: RelationNode | null; deeplevel: number }>(
    '/admin/memberRelation/charts',
    { params },
  )
}

// 懒加载：指定会员的下级子树
export function getRelationMore(mid: number) {
  return request.get<unknown, { node: RelationNode; deeplevel: number }>(
    '/admin/memberRelation/chartsMore',
    { params: { mid } },
  )
}

/* ================= 共享海报 ================= */

// 海报模板列表（index 多模板管理）
export function getPosterList(params: { type: string; platform: string }) {
  return request.get<unknown, { list: PosterListItem[] }>('/admin/poster/list', { params })
}

// 读取海报模板（无模板时后端自动初始化默认模板）
export function getPoster(params: { type: string; platform: string; id?: number }) {
  return request.get<unknown, PosterTemplate>('/admin/poster/get', { params })
}

// 保存海报模板
export function savePoster(data: {
  type: string
  platform: string
  id: number
  poster_bg: string
  poster_data: PosterElement[]
  guize: string
  clearhistory?: number
}) {
  return request.post<unknown, { id: number }>('/admin/poster/save', data)
}

// 新建海报模板（默认元素）
export function addPoster(data: { type: string; platform: string }) {
  return request.post<unknown, { id: number }>('/admin/poster/add', data)
}

// 删除海报模板（仅 index）
export function deletePoster(data: { type: string; id: number }) {
  return request.post<unknown, null>('/admin/poster/del', data)
}

// 二维码元素目标链接说明
export function getPosterQrcode(params: { platform: string; mid?: number }) {
  return request.get<unknown, { platform: string; aid: number; url: string; tip: string }>(
    '/admin/poster/qrcode',
    { params },
  )
}
