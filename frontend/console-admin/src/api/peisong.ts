import request from './request'

/**
 * 同城配送整组 API（批次8 任务223）
 * 后端：app/admin/controller/Peisong.php
 * 覆盖：配送员 / 配送单 / 评价 / 提成明细 / 提现记录 / 系统设置 / 码科跑腿（mock）
 */

// ==================== 公共 ====================

export interface PsPageResult<T> {
  list: T[]
  total: number
}

export interface PsUserOption {
  id: number
  realname: string
  tel: string
  /** 配送中单量（0待接单+1已接单） */
  dan: number
}

// ==================== 1. 配送员（peisong_user） ====================

export interface PeisongUserItem {
  id: number
  mid: number
  realname: string
  tel: string
  status: number
  createtime: number
  sort: number
  comment_score: string | number
  comment_num: number
  comment_haopercent: number
  money: string
  totalmoney: string
  totalnum: number
  weixin: string
  aliaccount: string
  bankname: string
  bankcarduser: string
  bankcardnum: string
  longitude: string
  latitude: string
  nickname?: string
  headimg?: string
  /** 配送中单量（status 0,1,2,3） */
  pszCount?: number
  /** 已完成单量（status 4） */
  ywcCount?: number
}

export interface PeisongUserQuery {
  page?: number
  pagesize?: number
  realname?: string
  status?: string | number
}

export function getPeisongUserList(params: PeisongUserQuery) {
  return request.get<unknown, PsPageResult<PeisongUserItem>>('/admin/peisong/user/list', { params })
}

export function savePeisongUser(data: Partial<PeisongUserItem>) {
  return request.post<unknown, { id: number }>('/admin/peisong/user/save', data)
}

export function setstPeisongUser(data: { ids: number[]; st: number }) {
  return request.post<unknown, null>('/admin/peisong/user/setst', data)
}

export function delPeisongUser(ids: number[]) {
  return request.post<unknown, null>('/admin/peisong/user/del', { ids })
}

/** 启用中配送员下拉 */
export function getPeisongUserOptions() {
  return request.get<unknown, { list: PsUserOption[] }>('/admin/peisong/user/options')
}

// ==================== 2. 配送单（peisong_order） ====================

export interface PeisongOrderItem {
  id: number
  bid: number
  mid: number
  psid: number
  ordernum: string
  createtime: number
  starttime: number
  daodiantime: number
  quhuotime: number
  endtime: number
  status: number
  type: string
  ticheng: string
  psfee: string
  iscomment: number
  make_ordernum: string
  make_rider_name: string
  make_rider_mobile: string
  member: { id: number; nickname: string; headimg: string }
  psuser: { realname: string; tel: string }
  orderinfo: Record<string, unknown>
  binfo: Record<string, unknown>
  prolist: Array<Record<string, unknown>>
  status_name: string
  pstype: string
}

export interface PeisongOrderQuery {
  page?: number
  pagesize?: number
  ordernum?: string
  psid?: string | number
  status?: string | number
  /** 1系统配送 2码科配送 3已拒绝 */
  pstype?: string | number
  nickname?: string
  /** 商户过滤（merchantViewOf 注入 'gt0'） */
  bid?: string
}

export function getPeisongOrderList(params: PeisongOrderQuery) {
  return request.get<unknown, PsPageResult<PeisongOrderItem>>('/admin/peisong/order/list', { params })
}

export function setstPeisongOrder(data: { ids: number[]; st: number; bid?: string }) {
  return request.post<unknown, null>('/admin/peisong/order/setst', data)
}

export function delPeisongOrder(ids: number[], bid?: string) {
  return request.post<unknown, null>('/admin/peisong/order/del', { ids, bid })
}

// ==================== 3. 配送评价（peisong_order_comment） ====================

export interface PeisongCommentItem {
  id: number
  bid: number
  mid: number
  psid: number
  ordernum: string
  nickname: string
  headimg: string
  score: number
  content: string
  content_pic: string[]
  reply_content: string
  status: number
  createtime: number
  psuser: { realname: string; tel: string }
  business: { name: string; tel: string }
}

export interface PeisongCommentQuery {
  page?: number
  pagesize?: number
  psid?: string | number
  content?: string
  start_date?: string
  end_date?: string
  bid?: string
}

export function getPeisongCommentList(params: PeisongCommentQuery) {
  return request.get<unknown, PsPageResult<PeisongCommentItem>>('/admin/peisong/comment/list', { params })
}

export function getPeisongCommentDetail(id: number) {
  return request.post<unknown, { detail: PeisongCommentItem; member: { nickname: string; headimg: string } }>(
    '/admin/peisong/comment/detail',
    { id },
  )
}

export function delPeisongComment(ids: number[]) {
  return request.post<unknown, null>('/admin/peisong/comment/del', { ids })
}

// ==================== 4. 提成明细（peisong_moneylog） ====================

export interface PeisongMoneyLogItem {
  id: number
  uid: number
  money: string
  after: string
  createtime: number
  remark: string
  realname: string
  tel: string
}

export interface PeisongMoneyLogQuery {
  page?: number
  pagesize?: number
  realname?: string
  uid?: string | number
}

export function getPeisongMoneyLogList(params: PeisongMoneyLogQuery) {
  return request.get<unknown, PsPageResult<PeisongMoneyLogItem>>('/admin/peisong/moneylog/list', { params })
}

export function delPeisongMoneyLog(ids: number[]) {
  return request.post<unknown, null>('/admin/peisong/moneylog/del', { ids })
}

// ==================== 5. 提现记录（peisong_withdrawlog） ====================

export interface PeisongWithdrawLogItem {
  id: number
  uid: number
  money: string
  txmoney: string
  weixin: string
  aliaccount: string
  ordernum: string
  paytype: string
  status: number
  createtime: number
  bankname: string
  bankcarduser: string
  bankcardnum: string
  paytime: number
  paynum: string
  reason: string
  realname: string
  tel: string
  status_name: string
}

export interface PeisongWithdrawLogQuery {
  page?: number
  pagesize?: number
  realname?: string
  uid?: string | number
  status?: string | number
}

export function getPeisongWithdrawLogList(params: PeisongWithdrawLogQuery) {
  return request.get<unknown, PsPageResult<PeisongWithdrawLogItem>>('/admin/peisong/withdrawlog/list', { params })
}

/** st：1审核通过 2驳回(返还余额) 3已打款 10打款(mock即时成功) */
export function setstPeisongWithdrawLog(data: { id: number; st: number; reason?: string }) {
  return request.post<unknown, { mock?: boolean; status?: number } | null>('/admin/peisong/withdrawlog/setst', data)
}

export function delPeisongWithdrawLog(ids: number[]) {
  return request.post<unknown, null>('/admin/peisong/withdrawlog/del', { ids })
}

// ==================== 6. 系统设置（peisong_set） ====================

export interface PeisongSetInfo {
  status: number | string
  paidantype: number | string
  express_paidan: number | string
  yuji_sendminute: number | string
  yuji_psjuli1: number | string
  yuji_psminute1: number | string
  yuji_psjuli2: number | string
  yuji_psminute2: number | string
  jiesuantype: number | string
  tcmoney: number | string
  peisong_juli1: number | string
  peisong_tcmoney1: number | string
  peisong_juli2: number | string
  peisong_tcmoney2: number | string
  peisong_tcmoneymax: number | string
  withdraw_weixin: number | string
  withdraw_aliaccount: number | string
  withdraw_bankcard: number | string
  withdrawmin: number | string
  withdrawfee: number | string
  businessst: number | string
  businessfee: number | string
}

export function getPeisongSet() {
  return request.get<unknown, { info: PeisongSetInfo }>('/admin/peisong/set')
}

export function savePeisongSet(data: Partial<PeisongSetInfo>) {
  return request.post<unknown, null>('/admin/peisong/set', data)
}

// ==================== 7. 码科跑腿对接（mock） ====================

export interface PeisongMakeSetInfo {
  make_status: number
  make_domain: string
  make_appid: string
  make_token: string
  make_shopkoufei: number
  make_access_token: string
  make_expire_time: number
  express_wx_status: number
  mock: boolean
}

export function getPeisongMakeset() {
  return request.get<unknown, { info: PeisongMakeSetInfo }>('/admin/peisong/makeset')
}

export function savePeisongMakeset(data: Partial<PeisongMakeSetInfo>) {
  return request.post<unknown, { mock: boolean; make_access_token: string; make_expire_time: number }>(
    '/admin/peisong/makeset',
    data,
  )
}

// ==================== 8. 派单参考（getpeisonguser 口径） ====================

export interface PeisongUserPickResult {
  peisonguser: Array<{ id: number; title: string }>
  paidantype: number
  psfee: string
  ticheng: string
  mock: boolean
}

export function getPeisongUserPick(params: { showtype?: number; juli?: number }) {
  return request.get<unknown, PeisongUserPickResult>('/admin/peisong/getpeisonguser', { params })
}
