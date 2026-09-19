import request from './request'

// ============ 买单收款（批次2-B） ============

export interface MaidanMember {
  mid: number
  nickname: string
  headimg: string
  tel: string
  money: number
  score: number
  levelid: number
  levelname: string
  discount: number
}

export interface MaidanCoupon {
  id: number
  couponname: string
  money: number
  minprice: number
  endtime: number
}

export interface MaidanPreviewResult {
  member: MaidanMember | null
  money: number
  disprice: number
  couponmoney: number
  scoredk: number
  decscore: number
  max_scoredk: number
  paymoney: number
  coupons: MaidanCoupon[]
  enough: boolean
}

export interface MaidanOrderRow {
  id: number
  mid: number
  ordernum: string
  title: string
  money: number
  paymoney: number
  disprice: number
  scoredk: number
  decscore: number
  decmoney: number
  couponrid: number
  couponmoney: number
  createtime: number
  platform: string
  status: number
  paytypeid: number
  paytype: string
  paytime: number
  mdid: number
  uid: number
  remark: string
  refund_money: number
  nickname: string
  headimg: string | null
  mdname: string
  un: string
  can_refund_money: number
  refund_reason: string
}

export interface MaidanListQuery {
  page?: number
  pagesize?: number
  ordernum?: string
  mid?: number
  mdid?: number
  status?: number | ''
  nickname?: string
  starttime?: string
  endtime?: string
}

export interface MaidanSetData {
  maidan_login: number
  maidan_getlocation: number
  maidan_payaftertourl: string
  h5_url: string
  shop_name: string
}

// 手机号找会员（含等级/余额/积分/折扣）
export function mdMemberByTel(tel: string) {
  return request.get<unknown, MaidanMember | null>('/admin/maidan/memberByTel', {
    params: { tel },
  })
}

// 会员可用优惠券（按消费金额过滤门槛）
export function mdCoupons(mid: number, money: number) {
  return request.get<unknown, { list: MaidanCoupon[] }>('/admin/maidan/coupons', {
    params: { mid, money },
  })
}

// 买单试算（折扣/券/积分/实付预览）
export function mdPreview(data: { tel: string; money: number; couponrid: number; usescore: number }) {
  return request.post<unknown, MaidanPreviewResult>('/admin/maidan/preview', data)
}

// 买单扣费（余额支付）
export function mdPay(data: { tel: string; money: number; couponrid: number; usescore: number; mdid?: number }) {
  return request.post<unknown, { orderid: number; ordernum: string; paymoney: number }>(
    '/admin/maidan/add',
    data,
  )
}

// 买单记录列表
export function mdList(params: MaidanListQuery) {
  return request.get<unknown, { list: MaidanOrderRow[]; total: number; page: number; pagesize: number }>(
    '/admin/maidan/list',
    { params },
  )
}

// 买单退款（余额原路退回；全额退返还积分并释放券）
export function mdRefund(data: { id: number; money: number; reason: string }) {
  return request.post<unknown, null>('/admin/maidan/refund', data)
}

// 买单记录导出（xlsx Blob）
export function mdExport(params: MaidanListQuery) {
  return request.get('/admin/maidan/export', {
    params,
    responseType: 'blob',
    timeout: 180000,
  }) as Promise<Blob>
}

// 收款码设置读取
export function mdGetSet() {
  return request.get<unknown, MaidanSetData>('/admin/maidan/set')
}

// 收款码设置保存
export function mdSaveSet(data: { maidan_login: number; maidan_getlocation: number; maidan_payaftertourl: string }) {
  return request.post<unknown, null>('/admin/maidan/saveSet', data)
}
