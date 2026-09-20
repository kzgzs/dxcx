import request from './request'
import type {
  MemberAddressItem,
  MemberDetail,
  MemberLevelDetail,
  MemberLevelItem,
  MemberLevelRecordItem,
  MemberLevelRecordSaveParams,
  MemberLevelSaveParams,
  MemberListQuery,
  MemberLogResult,
  MemberMoneyLogItem,
  MemberOrderItem,
  MemberScoreLogItem,
} from './types'

/* ============ 会员列表（对齐点大，字段扩展） ============ */

export interface MemberRow {
  id: number
  headimg: string
  nickname: string
  realname: string
  sex: string
  tel: string
  pid: number
  parent_nickname: string
  levelid: number
  level_name: string
  money: number
  score: number
  commission: number
  platform: string
  subscribe: number
  has_card: number
  coupon_count: number
  buynum: number
  buymoney: number
  createtime: number
  last_visittime: number
  isfreeze: number
  islock: number
  remark: string
}

export interface MemberRowListQuery extends MemberListQuery {
  id?: number
  pid?: number
  hascard?: string // 1已领卡 2未领卡
  field?: string
  order?: string
}

export interface MemberRowListResult {
  list: MemberRow[]
  total: number
  page: number
  pagesize: number
}

// 会员列表
export function getMemberList(params: MemberRowListQuery) {
  return request.get<unknown, MemberRowListResult>('/admin/member/list', { params })
}

/* ============ 会员详情 / 编辑 ============ */

export interface MemberEditDetail extends MemberDetail {
  usercard: string
  birthday: string
  pid: number
  mpopenid: string
  commission: number
  subscribe: number
  has_card: number
  has_paypwd: number
}

export interface MemberEditForm {
  id: number
  headimg: string
  mpopenid?: string
  nickname: string
  realname: string
  tel: string
  usercard: string
  sex: string
  birthday: string
  levelid: number
  pid: number
  levelendtime?: string
  clear_paypwd?: number
  remark: string
}

// 会员详情
export function getMemberDetail(id: number) {
  return request.get<unknown, MemberEditDetail>('/admin/member/detail', { params: { id } })
}

// 编辑会员
export function editMember(data: MemberEditForm) {
  return request.post<unknown, null>('/admin/member/edit', data)
}

/* ============ 余额 / 积分 / 佣金 ============ */

// 余额充值/扣费（money 正=充值 负=扣款；paytype: wxpay/cash/alipay/admin）
export function rechargeMember(data: { id: number; money: number; remark?: string; paytype?: string }) {
  return request.post<unknown, { money: number }>('/admin/member/recharge', data)
}

// 积分调整（score 正负）
export function addMemberScore(data: { id: number; score: number; remark?: string }) {
  return request.post<unknown, { score: number }>('/admin/member/addscore', data)
}

// 加/扣佣金（commission 正负）
export function addMemberCommission(data: { id: number; commission: number; remark?: string }) {
  return request.post<unknown, { commission: number }>('/admin/member/addcommission', data)
}

// 冻结/解冻
export function freezeMember(id: number) {
  return request.post<unknown, { isfreeze: number }>('/admin/member/freeze', { id })
}

// 锁定/解锁
export function lockMember(id: number) {
  return request.post<unknown, { islock: number }>('/admin/member/lock', { id })
}

// 批量删除会员
export function deleteMembers(ids: number[]) {
  return request.post<unknown, { count: number }>('/admin/member/del', { ids })
}

// 会员列表导出
export function exportMembers(params: Record<string, unknown>) {
  return request.get<unknown, Blob>('/admin/member/excel', { params, responseType: 'blob' })
}

/* ============ 明细 / 地址 / 旧订单 ============ */

// 余额明细
export function getMemberMoneyLog(params: { mid: number; page?: number; pagesize?: number }) {
  return request.get<unknown, MemberLogResult<MemberMoneyLogItem>>('/admin/member/moneylog', { params })
}

// 积分明细
export function getMemberScoreLog(params: { mid: number; page?: number; pagesize?: number }) {
  return request.get<unknown, MemberLogResult<MemberScoreLogItem>>('/admin/member/scorelog', { params })
}

// 会员订单（简版，详情抽屉用）
export function getMemberOrders(params: { mid: number; page?: number; pagesize?: number }) {
  return request.get<unknown, MemberLogResult<MemberOrderItem>>('/admin/member/orders', { params })
}

// 收货地址
export function getMemberAddress(params: { mid: number; page?: number; pagesize?: number }) {
  return request.get<unknown, MemberLogResult<MemberAddressItem>>('/admin/member/address', { params })
}

/* ============ 会员维度：商城订单（平铺页） ============ */

export interface MemberShopOrderGoods {
  id: number
  name: string
  pic: string
  ggname: string
  num: number
  sell_price: number
  totalprice: number
}

export interface MemberShopOrderItem {
  id: number
  bid: number
  business_name: string
  ordernum: string
  sysOrderNo: string
  goods: MemberShopOrderGoods[]
  product_price: number
  totalprice: number
  freight_price: number
  paytype: string
  status: number
  createtime: number
  paytime: number
  linkman: string
  tel: string
  address: string
  remark: string
  freight_text: string
  freight_type: number
  platform: string
}

export interface MemberShopOrderQuery {
  mid: number
  page?: number
  pagesize?: number
  status?: number | ''
  ordernum?: string
  freight_type?: number | ''
  min_price?: string
  max_price?: string
  start_time?: string
  end_time?: string
}

export function getMemberShopOrders(params: MemberShopOrderQuery) {
  return request.get<unknown, MemberLogResult<MemberShopOrderItem>>('/admin/member/shopOrders', { params })
}

export function exportMemberShopOrders(params: Record<string, unknown>) {
  return request.get<unknown, Blob>('/admin/member/shopOrdersExcel', { params, responseType: 'blob' })
}

/* ============ 会员维度：优惠券领取记录（平铺页） ============ */

export interface MemberCouponItem {
  id: number
  bid: number
  business_name: string
  couponname: string
  money: number
  discount: number
  minprice: number
  status: number
  limit_count: number
  used_count: number
  createtime: number
  receive_time: number
  starttime: number
  endtime: number
  usetime: number
  remark: string
}

export interface MemberCouponQuery {
  mid: number
  page?: number
  pagesize?: number
  status?: number | ''
  remark?: string
  start_time?: string
  end_time?: string
}

export function getMemberCoupons(params: MemberCouponQuery) {
  return request.get<unknown, MemberLogResult<MemberCouponItem>>('/admin/member/coupons', { params })
}

export function setMemberCouponStatus(data: { mid: number; ids: number[]; status: number }) {
  return request.post<unknown, null>('/admin/member/couponSetSt', data)
}

export function deleteMemberCoupons(data: { mid: number; ids: number[] }) {
  return request.post<unknown, { count: number }>('/admin/member/couponDel', data)
}

export function exportMemberCoupons(params: Record<string, unknown>) {
  return request.get<unknown, Blob>('/admin/member/couponsExcel', { params, responseType: 'blob' })
}

/* ============ 会员维度：分销订单（佣金订单记录，平铺页） ============ */

export interface MemberFenxiaoItem {
  id: number
  orderid: number
  bid: number
  business_name: string
  type: string
  type_text: string
  mid: number
  nickname: string
  headimg: string
  goods_name: string
  goods_pic: string
  goods_gg: string
  goods_num: number
  ordernum: string
  order_createtime: number
  level: number
  real_level: number
  commission: number
  score: number
  status: number
  status_text: string
  remark: string
  createtime: number
}

export interface MemberFenxiaoQuery {
  mid: number // 分销员（当前会员）
  page?: number
  pagesize?: number
  buy_mid?: number // 下单人会员ID
  status?: number | ''
  type?: string
  orderid?: number
  ordernum?: string
  start_time?: string
  end_time?: string
}

export function getMemberFenxiaoOrders(params: MemberFenxiaoQuery) {
  return request.get<unknown, MemberLogResult<MemberFenxiaoItem>>('/admin/member/fenxiaoOrders', { params })
}

export function exportMemberFenxiao(params: Record<string, unknown>) {
  return request.get<unknown, Blob>('/admin/member/fenxiaoExcel', { params, responseType: 'blob' })
}

/* ============ 会员等级 ============ */

// 等级列表
export function getMemberLevelList() {
  return request.get<unknown, { list: MemberLevelItem[] }>('/admin/member/level/list')
}

// 等级详情（编辑回读全字段）
export function getMemberLevelDetail(id: number) {
  return request.get<unknown, MemberLevelDetail>('/admin/member/level/detail', { params: { id } })
}

// 等级新增/编辑
export function saveMemberLevel(data: MemberLevelSaveParams) {
  return request.post<unknown, null>('/admin/member/level/save', data)
}

// 等级删除
export function deleteMemberLevel(id: number) {
  return request.post<unknown, null>('/admin/member/level/delete', { id })
}

// 区域代理归属记录列表（按等级）
export function getMemberLevelRecordList(levelid: number) {
  return request.get<unknown, { list: MemberLevelRecordItem[] }>('/admin/member/level/record/list', {
    params: { levelid },
  })
}

// 区域代理归属保存
export function saveMemberLevelRecord(data: MemberLevelRecordSaveParams) {
  return request.post<unknown, null>('/admin/member/level/record/save', data)
}
