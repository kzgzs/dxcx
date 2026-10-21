import request from './request'
import { reactive } from 'vue'

/**
 * 跨页导航共享状态：活动列表行操作「选手列表/投票记录」、选手列表行操作「投票记录」
 * 通过 adminNavigateByKey 切页后，目标页 onActivated 读取本状态完成筛选与返回。
 */
export const voteNavState = reactive({
  /** 选手列表页要锁定的活动 id（从活动列表「选手列表」进入） */
  joinHid: 0,
  /** 投票记录页要锁定的活动 id */
  recordHid: 0,
  /** 投票记录页要锁定的选手 id（从选手行「投票记录」进入） */
  recordJoinid: 0,
  /** 嵌套打开时的来源叶子 key，X 返回用；空=菜单直达（不显示 X） */
  returnTo: '',
})

// ============ 类型（本模块私有，避免改动共享 types.ts） ============

export interface VoteActivityItem {
  id: number
  name: string
  banner: string
  color1: string
  color2: string
  listtype: number
  helptext: string
  join_num_text?: string
  join_name_text?: string
  starttime: number
  endtime: number
  canapply: number
  apply_check: number
  help_check: number
  sort: number
  guize: string
  sharetitle: string
  sharepic: string
  sharedesc: string
  sharelink: string
  per_daycount: number
  per_allcount: number
  gettj: string
  jump_url: string
  group_id: number
  status: number
  createtime: number
  group_name?: string
  joinnum?: number
  helpnum?: number
}

export interface VoteGroupItem {
  id: number
  name: string
  sort: number
  status: number
  createtime: number
  activity_count?: number
}

export interface VoteJoinItem {
  id: number
  aid: number
  mid: number
  hid: number
  name: string
  weixin: string
  pic: string
  pics: string
  detail_txt: string
  detail: string
  helpnum: number
  readcount: number
  createtime: number
  status: number
  reason: string
  number: string
  sort: number
  hdname?: string
  mingci?: number
}

export interface VoteHelpItem {
  id: number
  mid: number
  headimg: string
  nickname: string
  tel: string
  hid: number
  joinid: number
  createtime: number
  hdname?: string
  joinname?: string
  number?: string
}

export interface VoteStatResult {
  activity: VoteActivityItem
  joinnum: number
  helpnum: number
  toplist: VoteJoinItem[]
}

export interface PageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

export interface VoteActivitySaveParams {
  id?: number
  name: string
  banner?: string
  color1?: string
  color2?: string
  listtype?: number
  helptext?: string
  join_num_text?: string
  join_name_text?: string
  starttime: number | string
  endtime: number | string
  canapply?: number
  apply_check?: number
  help_check?: number
  sort?: number
  guize?: string
  sharetitle?: string
  sharepic?: string
  sharedesc?: string
  sharelink?: string
  per_daycount?: number
  per_allcount?: number
  gettj?: string
  jump_url?: string
  group_id?: number
  status?: number
}

// ============ 活动 ============

export function getVoteList(params: {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
  group_id?: number
}) {
  return request.get<unknown, PageResult<VoteActivityItem>>('/admin/toupiao/list', { params })
}

export function saveVoteActivity(data: VoteActivitySaveParams) {
  return request.post<unknown, { id: number }>('/admin/toupiao/save', data)
}

export function deleteVoteActivity(id: number) {
  return request.post<unknown, null>('/admin/toupiao/del', { id })
}

export function setVoteActivityStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/toupiao/status', { id, status })
}

// ============ 分组 ============

export function getVoteGroupList(keyword?: string) {
  return request.get<unknown, { list: VoteGroupItem[] }>('/admin/toupiao/group/list', {
    params: { keyword: keyword || undefined },
  })
}

export function saveVoteGroup(data: { id?: number; name: string; sort?: number; status?: number }) {
  return request.post<unknown, { id: number }>('/admin/toupiao/group/save', data)
}

export function deleteVoteGroup(id: number) {
  return request.post<unknown, null>('/admin/toupiao/group/del', { id })
}

// ============ 选手 ============

export function getVoteJoinList(params: {
  page?: number
  pagesize?: number
  hid?: number
  status?: number | ''
  keyword?: string
}) {
  return request.get<unknown, PageResult<VoteJoinItem>>('/admin/toupiao/join/list', { params })
}

export function saveVoteJoin(data: {
  id?: number
  hid: number
  name: string
  pic?: string
  pics?: string
  detail_txt?: string
  detail?: string
  weixin?: string
  status?: number
  reason?: string
  sort?: number
}) {
  return request.post<unknown, { id: number }>('/admin/toupiao/join/save', data)
}

export function auditVoteJoin(ids: number[], status: 1 | 2, reason?: string) {
  return request.post<unknown, null>('/admin/toupiao/join/audit', { ids, status, reason })
}

export function deleteVoteJoin(id: number) {
  return request.post<unknown, null>('/admin/toupiao/join/del', { id })
}

export function addVoteJoinPiao(id: number, num: number) {
  return request.post<unknown, null>('/admin/toupiao/join/addpiao', { id, num })
}

// ============ 统计 / 投票记录 / 投票 ============

export function getVoteStat(hid: number, top?: number) {
  return request.get<unknown, VoteStatResult>('/admin/toupiao/stat', {
    params: { hid, top: top || undefined },
  })
}

export function getVoteHelpList(params: { page?: number; pagesize?: number; hid?: number; joinid?: number; ctime?: string }) {
  return request.get<unknown, PageResult<VoteHelpItem>>('/admin/toupiao/help/list', { params })
}

export function deleteVoteHelp(ids: number[]) {
  return request.post<unknown, null>('/admin/toupiao/help/del', { ids })
}

export function doVote(mid: number, joinid: number) {
  return request.post<unknown, null>('/admin/toupiao/vote', { mid, joinid })
}
