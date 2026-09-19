import request from './request'

// ===== 名片管理整组（批次8-5 任务225），类型自包含 =====

// 名片行（附加浏览/收藏统计与会员信息）
export interface MingpianItem {
  id: number
  aid: number
  mid: number
  bgpic: string
  headimg: string
  realname: string
  touxian1: string
  touxian2: string
  touxian3: string
  tel: string
  weixin: string
  address: string
  email: string
  douyin: string
  weibo: string
  toutiao: string
  field1: string
  field2: string
  field3: string
  field4: string
  field5: string
  longitude: string
  latitude: string
  detail: string
  sharetitle: string
  createtime: number
  updatetime: number
  nickname?: string
  readcount?: number
  favcount?: number
}

// 联系信息字段配置项
export interface MingpianFieldItem {
  field: string
  name: string
  icon: string
  isshow: number
  required: number
}

// 名片设置
export interface MingpianSetData {
  bgpics: string[]
  createtj: string[]
  field_list: MingpianFieldItem[]
  status: number
  bgbtncolor: number
}

// 浏览/收藏记录行
export interface MingpianLogItem {
  id: number
  aid: number
  mid: number
  mpid: number
  createtime: number
  nickname?: string
  headimg?: string
  realname?: string
}

export interface MingpianListQuery {
  page?: number
  pagesize?: number
  realname?: string
  tel?: string
  nickname?: string
}

export interface MingpianLogQuery {
  page?: number
  pagesize?: number
  mpid?: number
  mid?: number
  nickname?: string
  starttime?: string
  endtime?: string
}

export function getMingpianList(params: MingpianListQuery) {
  return request.get<unknown, { list: MingpianItem[]; total: number }>('/admin/mingpian/list', { params })
}

export function getMingpianDetail(id: number) {
  return request.get<unknown, MingpianItem>('/admin/mingpian/detail', { params: { id } })
}

export function saveMingpian(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/mingpian/save', data)
}

export function deleteMingpian(ids: number[]) {
  return request.post<unknown, null>('/admin/mingpian/delete', { ids })
}

export function getMingpianSet() {
  return request.get<unknown, MingpianSetData>('/admin/mingpian/set')
}

export function saveMingpianSet(data: MingpianSetData) {
  return request.post<unknown, null>('/admin/mingpian/set', data)
}

export function getMingpianReadlog(params: MingpianLogQuery) {
  return request.get<unknown, { list: MingpianLogItem[]; total: number }>('/admin/mingpian/readlog', { params })
}

export function getMingpianFavoritelog(params: MingpianLogQuery) {
  return request.get<unknown, { list: MingpianLogItem[]; total: number }>('/admin/mingpian/favoritelog', { params })
}

export function deleteMingpianLog(table: string, ids: number[]) {
  return request.post<unknown, null>('/admin/mingpian/logdelete', { table, ids })
}
