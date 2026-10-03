import request from './request'

// ============ 类型定义（批次4-E 任务190） ============

export interface PageResult<T> {
  list: T[]
  total: number
}

// ----- 口令/谜语 -----
export type KoulingType = 'kouling' | 'riddle'

export interface KoulingSetData {
  title: string
  bgpic: string
  guize: string
  status: number
}

export interface KoulingItem {
  id: number
  name: string
  title?: string
  alias?: string
  content?: string
  starttime: number
  endtime: number
  starttime_text?: string
  endtime_text?: string
  status: number
  gettj: string | string[]
  num: number
  zjnum: number
  perday: number
  pertotal: number
  give_coupon: number
  coupon_ids: string | string[]
  coupon_names?: Record<string, string>
  money: number
  score: number
  [key: string]: unknown
}

export interface KoulingRecordItem {
  id: number
  objid: number
  name: string
  mid: number
  headimg: string
  nickname: string
  money: number
  score: number
  createtime: number
  createtime_text?: string
}

// ----- 微信红包 -----
export interface HongbaoItem {
  id: number
  name: string
  sendname: string
  wishing: string
  minmoney: number
  maxmoney: number
  totalmoney: number
  givenmoney: number
  pernum: number
  starttime: number
  endtime: number
  starttime_text?: string
  endtime_text?: string
  status: number
  sharepic: string
  gettj: string | string[]
  needcode: number
  [key: string]: unknown
}

export interface HongbaoRecordItem {
  id: number
  hid: number
  name: string
  mid: number
  headimg: string
  nickname: string
  money: number
  createtime: number
  createtime_text?: string
  status: number
  remark: string
}

export interface HongbaoCodeItem {
  id: number
  hid: number
  code: string
  status: number
  mid: number
  nickname: string
  money: number
  createtime: number
  createtime_text?: string
  usetime: number
  usetime_text?: string
}

// ----- Fifa 竞猜 -----
export interface FifaMatchItem {
  id: number
  matchId: string
  startDate: string
  startTime: string
  matchStage: string
  matchDesc: string
  matchStatus: string
  matchStatusText: string
  leftTeam_name: string
  leftTeam_logo: string
  leftTeam_score: string
  rightTeam_name: string
  rightTeam_logo: string
  rightTeam_score: string
  startTimestamp: number
  open?: boolean
  [key: string]: unknown
}

export interface FifaSetData {
  name: string
  givescore1: number
  givescore2: number
  guess1set: string[]
  guess2set: string[]
  status: number
  guize: string
  starthour: number
  [key: string]: unknown
}

export interface FifaRecordItem {
  id: number
  hid: number
  mid: number
  headimg: string
  nickname: string
  guess1: string
  guess2: string
  guess1st: number
  guess2st: number
  givescore1: number
  givescore2: number
  createtime: number
  createtime_text?: string
  duiju?: string
  startTime?: string
}

// ----- 拼团/秒杀评价 -----
export type CommentType = 'collage' | 'seckill'

export interface ActivityCommentItem {
  id: number
  mid: number
  headimg: string
  nickname: string
  proname: string
  score: number
  content: string
  content_pic: string
  reply_content: string
  status: number
  createtime: number
  createtime_text?: string
  [key: string]: unknown
}

export interface ActivityCommentListResult {
  list: ActivityCommentItem[]
  total: number
  page: number
  pagesize: number
  stat: { total: number; approved: number; pending: number; avg_score: number; haopercent: number }
}

// ----- 确认收货奖励记录 -----
export interface CollectRewardRecordItem {
  id: number
  ordertype: string
  ordertype_text?: string
  goods_title?: string
  orderid: number
  ordernum: string
  ordertime: number
  ordertime_text?: string
  bid: number
  business?: string
  mid: number
  nickname: string
  headimg: string
  tel?: string
  reward_type: number
  reward_type_text?: string
  reward: string
  reward_text?: string
  createtime: number
  createtime_text?: string
  [key: string]: unknown
}

// ----- 营销数据统计 -----
export interface StatXszbItem {
  name: string
  value: number
  percent: number
}

export interface StatTypeDateItem {
  name: string
  total_money: number
  order_num: number
  member_num: number
  type_kdj: number
  z_refund_money: number
  tkl: number
}

export interface StatSxqsdbItem {
  name: string
  type: string
  data: number[]
}

export interface StatMemberDataItem {
  value: number
  zb: number
  totalConsumption: number
  rjxf: number
}

export interface StatIndexResult {
  total_money: number
  total_order_num: number
  total_member_num: number
  total_kdj: number
  xszb: StatXszbItem[]
  type_date_arr: StatTypeDateItem[]
  date_arr: string[]
  sxqsdb: StatSxqsdbItem[]
  member_data: StatMemberDataItem[]
  money_arr: string[]
}

export interface StatOptionsResult {
  business: { id: number; name: string }[]
  freight: { id: number; name: string }[]
}

export interface StatIndexParams {
  range: string
  custom_start?: string
  custom_end?: string
  bid?: string
  freight_id?: string
  qus?: string
}

// ============ 口令/谜语游戏 ============

export function getKoulingSet() {
  return request.get<unknown, KoulingSetData>('/admin/kouling/set')
}

export function saveKoulingSet(data: KoulingSetData) {
  return request.post<unknown, null>('/admin/kouling/set', data)
}

export function getKoulingList(params: {
  type: KoulingType
  keyword?: string
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, PageResult<KoulingItem>>('/admin/kouling/list', { params })
}

export function getKoulingDetail(type: KoulingType, id: number) {
  return request.get<unknown, KoulingItem>('/admin/kouling/detail', { params: { type, id } })
}

export function saveKouling(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/kouling/save', data)
}

export function deleteKouling(type: KoulingType, ids: number[]) {
  return request.post<unknown, null>('/admin/kouling/delete', { type, ids })
}

export function getKoulingRecords(params: {
  type: KoulingType
  objid?: number
  nickname?: string
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, PageResult<KoulingRecordItem>>('/admin/kouling/records', { params })
}

// ============ 微信红包 ============

export function getHongbaoList(params: { keyword?: string; page?: number; pagesize?: number }) {
  return request.get<unknown, PageResult<HongbaoItem>>('/admin/hongbao/list', { params })
}

export function getHongbaoDetail(id: number) {
  return request.get<unknown, HongbaoItem>('/admin/hongbao/detail', { params: { id } })
}

export function saveHongbao(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/hongbao/save', data)
}

export function deleteHongbao(ids: number[]) {
  return request.post<unknown, null>('/admin/hongbao/delete', { ids })
}

export function getHongbaoRecords(params: {
  hid?: number
  nickname?: string
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, PageResult<HongbaoRecordItem>>('/admin/hongbao/records', { params })
}

export function getHongbaoCodelist(params: {
  hid?: number
  status?: number
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, PageResult<HongbaoCodeItem>>('/admin/hongbao/codelist', { params })
}

export function makeHongbaoCodes(data: { hid: number; num: number; length: number }) {
  return request.post<unknown, { num: number }>('/admin/hongbao/makecode', data)
}

export function importHongbaoCodes(hid: number, file: File) {
  const formData = new FormData()
  formData.append('hid', String(hid))
  formData.append('file', file)
  return request.post<unknown, { imported: number; skipped: number }>(
    '/admin/hongbao/importcode',
    formData,
    { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 120000 },
  )
}

export async function exportHongbaoCodes(hid: number) {
  const res = await request.get('/admin/hongbao/exportcode', {
    params: { hid },
    responseType: 'blob',
    timeout: 180000,
    transformResponse: [(data: unknown) => data],
  })
  const blob = res instanceof Blob ? res : new Blob([res as unknown as BlobPart])
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `红包抽奖码${hid}.xlsx`
  link.click()
  URL.revokeObjectURL(url)
}

// ============ Fifa 竞猜 ============

export function getFifaList(params: { keyword?: string; page?: number; pagesize?: number }) {
  return request.get<unknown, PageResult<FifaMatchItem>>('/admin/fifa/list', { params })
}

export function getFifaDetail(id: number) {
  return request.get<unknown, FifaMatchItem>('/admin/fifa/detail', { params: { id } })
}

export function saveFifa(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/fifa/save', data)
}

export function deleteFifa(ids: number[]) {
  return request.post<unknown, null>('/admin/fifa/delete', { ids })
}

export function getFifaSet() {
  return request.get<unknown, FifaSetData>('/admin/fifa/set')
}

export function saveFifaSet(data: Record<string, unknown>) {
  return request.post<unknown, null>('/admin/fifa/set', data)
}

export function getFifaRecords(params: {
  hid?: number
  nickname?: string
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, PageResult<FifaRecordItem>>('/admin/fifa/records', { params })
}

export function deleteFifaRecords(ids: number[]) {
  return request.post<unknown, null>('/admin/fifa/recordDelete', { ids })
}

export function openFifaMatch(hid: number) {
  return request.post<unknown, { total: number; win1: number; win2: number }>(
    '/admin/fifa/open',
    { hid },
  )
}

// ============ 拼团/秒杀评价 ============

export function getActivityCommentList(params: {
  type: CommentType
  page?: number
  pagesize?: number
  keyword?: string
  score?: number
  status?: number
  start_time?: number
  end_time?: number
}) {
  return request.get<unknown, ActivityCommentListResult>('/admin/activityComment/list', {
    params,
  })
}

export function checkActivityComment(type: CommentType, id: number, status: number) {
  return request.post<unknown, null>('/admin/activityComment/check', { type, id, status })
}

export function replyActivityComment(data: {
  type: CommentType
  id: number
  reply_content: string
}) {
  return request.post<unknown, null>('/admin/activityComment/reply', data)
}

export function deleteActivityComment(type: CommentType, id: number) {
  return request.post<unknown, null>('/admin/activityComment/delete', { type, id })
}

// ============ 确认收货奖励记录 ============

export function getCollectRewardRecords(params: {
  ordertype?: string
  reward_type?: number
  ordernum?: string
  keyword?: string
  mid?: number
  ordertime_start?: number
  ordertime_end?: number
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, PageResult<CollectRewardRecordItem>>(
    '/admin/orderCollectReward/records',
    { params },
  )
}

export function deleteCollectRewardRecords(ids: number[]) {
  return request.post<unknown, null>('/admin/orderCollectReward/del', { ids })
}

// ============ 营销数据统计 ============

export function getStatIndex(params: StatIndexParams) {
  return request.get<unknown, StatIndexResult>('/admin/dataStatistics/index', { params })
}

export function getStatOptions() {
  return request.get<unknown, StatOptionsResult>('/admin/dataStatistics/options')
}
