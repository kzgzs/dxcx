import request from './request'

/**
 * 扩展·同城配送·快递鸟即时配送（片C）
 * 后端：app/admin/controller/ExpressKdn.php
 * 覆盖：快递鸟设置(express_set) / 快递鸟门店(express_shop) / 快递鸟记录(express_order) + 物流 trace
 */

export interface KdnPageResult<T> {
  list: T[]
  total: number
}

// ==================== 快递鸟设置 ====================

export interface ExpressSetInfo {
  id: number
  status: number
  /** 快递鸟用户ID(EBusinessID) */
  user_id: string
  /** 快递鸟 API KEY */
  api_key: string
  /** 派单模式 0手动派单 1自动派单 */
  paidan_mode: number
  /** 默认配送渠道 standard优配标准 special优配特惠 premium优配尊享 */
  default_channel: string
  /** 会员配送费加价策略 0百分比 1固定金额 */
  member_fee_type: number
  member_fee_value: string
  /** 商户配送费加价策略 0百分比 1固定金额 */
  merchant_fee_type: number
  merchant_fee_value: string
}

export function getExpressSet() {
  return request.get<unknown, { info: ExpressSetInfo }>('/admin/expresskdn/set')
}

export function saveExpressSet(data: Partial<ExpressSetInfo>) {
  return request.post<unknown, Partial<ExpressSetInfo>>('/admin/expresskdn/set', data)
}

// ==================== 快递鸟门店 ====================

export interface ExpressShopItem {
  id: number
  /** 快递鸟门店编码(只读) */
  kdn_shop_no: string
  /** 门店编码(只读自动) */
  shop_no: string
  shop_name: string
  cargo_type: string
  cargo_type_name?: string
  province: string
  city: string
  district: string
  region?: string
  address: string
  lng: string
  lat: string
  contact: string
  mobile: string
  id_card_front: string
  id_card_back: string
  id_card_no: string
  id_card_name: string
  business_license: string
  credit_code: string
  shop_pic: string
  holder_pic: string
  createtime?: number
  updatetime?: number
}

export function getExpressShopList(params: { page?: number; pagesize?: number; keyword?: string }) {
  return request.get<unknown, KdnPageResult<ExpressShopItem>>('/admin/expresskdn/shop/list', { params })
}

export function saveExpressShop(data: Partial<ExpressShopItem>) {
  return request.post<unknown, { id: number }>('/admin/expresskdn/shop/save', data)
}

export function delExpressShop(ids: number[]) {
  return request.post<unknown, null>('/admin/expresskdn/shop/del', { ids })
}

// ==================== 快递鸟记录 + 物流 ====================

export interface ExpressRecordItem {
  id: number
  mid: string | number
  ordernum: string
  createtime: number
  kuaidinum: string
  taskId: string
  cargo: string
  recManName: string
  recManMobile: string
  recManPrintAddr: string
  weight: string
  status: number
  status_name: string
  member: { id: number; nickname: string; headimg: string }
}

export interface ExpressRecordQuery {
  page?: number
  pagesize?: number
  /** 订单编号/快递鸟配送编号 */
  ordernum?: string
  /** 下单人ID */
  mid?: string | number
  /** 关键字(下单人昵称/姓名/手机号) */
  keyword?: string
  status?: string | number
}

export function getExpressRecordList(params: ExpressRecordQuery) {
  return request.get<unknown, KdnPageResult<ExpressRecordItem>>('/admin/expresskdn/record/list', { params })
}

export interface TraceStep {
  time: string
  /** 轨迹标题（创建时间/推单时间/取消时间/揽收…） */
  title: string
  text?: string
}

export interface TraceResult {
  order_status: string
  state: string
  steps: TraceStep[]
  trace_msg: string
  is_mock?: boolean
  /** 快递公司名称 */
  company_name?: string
  /** 运单号 */
  logistic_no?: string
}

export function getExpressRecordTrace(id: number) {
  return request.post<unknown, TraceResult>('/admin/expresskdn/record/trace', { id })
}
