import request from './request'

// ============ 录入订单（代客下单，批次2-B） ============

export interface LrMember {
  id: number
  nickname: string
  headimg: string
  tel: string
  money: number
  score: number
  levelid: number
  levelname: string
}

export interface LrAddress {
  id: number
  mid: string
  name: string
  tel: string
  province: string
  city: string
  district: string
  address: string
  isdefault: number
}

export interface LrGoodsOption {
  id: number
  name: string
  pic: string
  sell_price: number
  stock: number
  lvprice: number
  freighttype: number
  givescore_time: number
}

export interface LrGuigeOption {
  id: number
  name: string
  pic: string
  sell_price: string | number
  cost_price: string | number
  stock: number
  lvprice_data: Record<string, number | string>
}

export interface LrOrderItemPayload {
  proid: number
  ggid: number
  num: number
}

export interface LrOrderSavePayload {
  mid: number
  items: LrOrderItemPayload[]
  linkman: string
  tel: string
  province: string
  city: string
  district: string
  address: string
  freight_id: number
  freightprice: number
  mdid: number
  paytype: string
  paytypeid: number
  paytime?: string
}

export interface LrOrderSaveResult {
  id: number
  ordernum: string
  totalprice: number
}

// 会员查询（mid 精确 / keyword 模糊：ID/手机号/昵称）
export function lrGetUser(params: { mid?: number; keyword?: string }) {
  return request.get<unknown, { member?: LrMember | null; list?: LrMember[] }>(
    '/admin/shopOrderlr/getUser',
    { params },
  )
}

// 会员收货地址列表
export function lrAddressList(mid: number) {
  return request.get<unknown, { list: LrAddress[] }>('/admin/shopOrderlr/addressList', {
    params: { mid },
  })
}

// 商品候选列表
export function lrGoodsOptions(params: { keyword?: string; page?: number; pagesize?: number }) {
  return request.get<unknown, { list: LrGoodsOption[]; total: number }>(
    '/admin/shopOrderlr/goodsOptions',
    { params },
  )
}

// 商品规格行（含库存与等级价数据）
export function lrGuigeOptions(proid: number) {
  return request.get<unknown, { list: LrGuigeOption[] }>('/admin/shopOrderlr/guigeOptions', {
    params: { proid },
  })
}

// 保存录入订单
export function lrSaveOrder(data: LrOrderSavePayload) {
  return request.post<unknown, LrOrderSaveResult>('/admin/shopOrderlr/save', data)
}
