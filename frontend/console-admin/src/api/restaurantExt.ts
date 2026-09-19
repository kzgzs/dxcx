import request from './request'

// ====================================================================
// 餐饮余量域 API（批次9 任务232）
// 覆盖：经营概览/菜品采集/外卖评价/点餐评价/配送方式/外卖设置/
//       餐饮优惠券/餐饮促销/餐饮统计/餐饮设置
// 区域打印（wifiprint）沿用 api/dining.ts 的 getDiningPrinterList 等
// ====================================================================

// ==================== 经营概览（RestaurantIndex） ====================

/** 经营概览统计卡 */
export interface RestaurantOverview {
  productCount: number
  productCount0: number
  productCount1: number
  tableCount: number
  tableCount0: number
  tableCount2: number
  shopOrderCount: number
  shopOrderLastDayCount: number
  shopOrderThisMonthCount: number
  shopOrderMoney: number
  shopOrderMoneyLastDayCount: number
  shopOrderMoneyThisMonthCount: number
  refundMoney: number
  refundMoneyLastDay: number
  refundMoneyThisMonth: number
  takeawayOrderCount: number
  takeawayOrderLastDayCount: number
  takeawayOrderThisMonthCount: number
  takeawayOrderMoney: number
  takeawayOrderMoneyLastDayCount: number
  takeawayOrderMoneyThisMonthCount: number
  takeawayRefundMoney: number
  takeawayRefundMoneyLastDay: number
  takeawayRefundMoneyThisMonth: number
}

export function getRestaurantOverview() {
  return request.get<unknown, RestaurantOverview>('/admin/restaurant/index/overview')
}

/** 最近30天曲线 type：1客户总数 2新增客户 3收款金额 4订单金额 5订单数 */
export function getRestaurantChart(type: number) {
  return request.get<unknown, { dateArr: string[]; dataArr: number[] }>('/admin/restaurant/index/chart', {
    params: { type },
  })
}

// ==================== 菜品采集（RestaurantTaobao，第三方抓取 mock） ====================

export function getRestaurantTaobaoConfig() {
  return request.get<unknown, { apikey: string }>('/admin/restaurant/taobao/config')
}

export function saveRestaurantTaobaoKey(apikey: string) {
  return request.post<unknown, null>('/admin/restaurant/taobao/saveKey', { apikey })
}

/** 采集平台：0淘宝 1天猫 2京东 3拼多多 4=1688 */
export function collectRestaurantTaobao(data: { type: number; taobaourl: string; apikey?: string }) {
  return request.post<unknown, { proid: number }>('/admin/restaurant/taobao/collect', data)
}

// ==================== 评价管理（外卖/点餐双域，scope 区分路径） ====================

export type RestaurantCommentScope = 'takeaway' | 'shop'

export interface RestaurantCommentRow {
  id: number
  mid: number
  proid: number
  proname?: string
  nickname?: string
  headimg?: string
  score: number
  content: string
  content_pic: string[]
  status: number
  reply_content?: string
  reply_time: number
  createtime: number
}

export interface RestaurantCommentListQuery {
  page?: number
  limit?: number
  content?: string
  status?: number | ''
  stime?: string
  etime?: string
}

const COMMENT_PREFIX: Record<RestaurantCommentScope, string> = {
  takeaway: '/admin/restaurant/takeawayComment',
  shop: '/admin/restaurant/shopComment',
}

export function getRestaurantCommentList(scope: RestaurantCommentScope, params: RestaurantCommentListQuery) {
  return request.get<unknown, { count: number; list: RestaurantCommentRow[] }>(
    `${COMMENT_PREFIX[scope]}/list`,
    { params },
  )
}

export function getRestaurantCommentDetail(scope: RestaurantCommentScope, id: number) {
  return request.get<unknown, { detail: RestaurantCommentRow; member: { nickname: string; headimg: string } }>(
    `${COMMENT_PREFIX[scope]}/detail`,
    { params: { id } },
  )
}

export function setRestaurantCommentStatus(scope: RestaurantCommentScope, ids: number[], st: number) {
  return request.post<unknown, null>(`${COMMENT_PREFIX[scope]}/setst`, { ids, st })
}

export function replyRestaurantComment(scope: RestaurantCommentScope, id: number, content: string) {
  return request.post<unknown, null>(`${COMMENT_PREFIX[scope]}/reply`, { id, content })
}

export function deleteRestaurantComment(scope: RestaurantCommentScope, ids: number[]) {
  return request.post<unknown, null>(`${COMMENT_PREFIX[scope]}/del`, { ids })
}

// ==================== 外卖配送方式（RestaurantTakeawayFreight） ====================

/** pstype：0快递模板 1自提门店 2同城配送 3发货信息 5配送门店 */
export interface RestaurantFreightRow {
  id: number
  pstype: number
  name: string
  type: number
  status: number
  sort: number
  summary: string
  freeset?: number
  free_price?: number
  minpriceset?: number
  minprice?: number
  pricedata?: RestaurantFreightPrice[]
  storetype?: number
  storeids?: number[]
  field_list?: unknown[]
  pscontenttype?: number
  pscontent?: string
  needlinkinfo?: number
  fwprice?: number
  pstimeset?: number
  pstimedata?: unknown[]
  psprehour?: number
  peisong_juli1?: number
  peisong_fee1?: number
  peisong_juli2?: number
  peisong_fee2?: number
  peisong_lng?: string
  peisong_lat?: string
  peisong_lng2?: string
  peisong_lat2?: string
  peisong_range?: number
  peisong_rangetype?: number
  peisong_rangepath?: string
}

export interface RestaurantFreightPrice {
  region: string
  fristweight: string
  fristprice: string
  secondweight: string
  secondprice: string
}

export interface RestaurantStoreOption {
  id: number
  name: string
}

export function getRestaurantFreightList(params: { page?: number; limit?: number; name?: string; status?: number | '' }) {
  return request.get<unknown, { count: number; list: RestaurantFreightRow[] }>(
    '/admin/restaurant/takeawayFreight/list',
    { params },
  )
}

export function getRestaurantFreightDetail(id: number) {
  return request.get<unknown, { info: RestaurantFreightRow; stores: RestaurantStoreOption[] }>(
    '/admin/restaurant/takeawayFreight/detail',
    { params: { id } },
  )
}

export function saveRestaurantFreight(info: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/restaurant/takeawayFreight/save', { info })
}

export function deleteRestaurantFreight(ids: number[]) {
  return request.post<unknown, null>('/admin/restaurant/takeawayFreight/del', { ids })
}

// ==================== 外卖设置（RestaurantTakeawaySet，含广告设置字段组） ====================

export interface RestaurantTakeawaySetInfo {
  id: number
  banner: string
  banner_show: number
  pack_fee: number
  min_price: number
  start_hours: string
  end_hours: string
  confirm_auto: number
  status: number
  autoshdays: number
  business_info_show: number
  comment_show: number
  open_restaurant_detail_status: number
  takeaway_show: number
  takeaway_name: string
  business_info_name: string
  comment_name: string
  alone_hexiao_status: number
  auto_send_order: number
}

export function getRestaurantTakeawaySet() {
  return request.get<unknown, { info: RestaurantTakeawaySetInfo }>('/admin/restaurant/takeawaySet/index')
}

export function saveRestaurantTakeawaySet(info: Partial<RestaurantTakeawaySetInfo>) {
  return request.post<unknown, null>('/admin/restaurant/takeawaySet/save', { info })
}

// ==================== 餐饮优惠券（RestaurantCoupon：coupon/coupon_record + type=5 + bid=0） ====================

export interface RestaurantCouponRow {
  id: number
  name: string
  money: number
  discount?: number
  minprice: number
  stock: number
  getnum: number
  used: number
  perlimit: number
  starttime: string
  endtime: string
  yxqtype?: number
  yxqdate?: number
  gettj?: string[] | string
  fwtype?: number
  categoryids?: string
  restaurant_productids?: string
  gettips?: string
  usetips?: string
  sort: number
  status?: number
  status_text?: string
  levels?: { id: number; name: string }[]
}

export function getRestaurantCouponList(params: { page?: number; limit?: number; name?: string }) {
  return request.get<unknown, { count: number; list: RestaurantCouponRow[] }>('/admin/restaurant/coupon/list', {
    params,
  })
}

export function getRestaurantCouponDetail(id: number) {
  return request.get<unknown, { info: RestaurantCouponRow }>('/admin/restaurant/coupon/detail', {
    params: { id },
  })
}

export function saveRestaurantCoupon(info: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/restaurant/coupon/save', { info })
}

export function deleteRestaurantCoupon(ids: number[]) {
  return request.post<unknown, null>('/admin/restaurant/coupon/del', { ids })
}

export function copyRestaurantCoupon(id: number) {
  return request.post<unknown, { id: number }>('/admin/restaurant/coupon/copy', { id })
}

export interface RestaurantCouponRecordRow {
  id: number
  mid: number
  couponid: number
  couponname: string
  money: number
  minprice: number
  status: number
  nickname: string
  headimg: string
  starttime: number
  endtime: number
  usetime: number
  createtime: number
  remark?: string
}

export function getRestaurantCouponRecord(params: {
  couponid: number
  page?: number
  limit?: number
  nickname?: string
}) {
  return request.get<unknown, { count: number; list: RestaurantCouponRecordRow[] }>(
    '/admin/restaurant/coupon/record',
    { params },
  )
}

export function setRestaurantCouponRecordStatus(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/restaurant/coupon/recordsetst', { ids, st })
}

export function deleteRestaurantCouponRecord(ids: number[]) {
  return request.post<unknown, null>('/admin/restaurant/coupon/recorddel', { ids })
}

export function sendRestaurantCoupon(couponid: number, mids: number[], num: number) {
  return request.post<unknown, { sucnum: number; errnum: number }>('/admin/restaurant/coupon/send', {
    couponid,
    mids,
    num,
  })
}

// ==================== 餐饮促销（RestaurantCuxiao） ====================

/** type：1满减 2满赠 3换购 4打折 5满件打折 6满件立减 7第二件打折 */
export interface RestaurantCuxiaoRow {
  id: number
  type: number
  type_text?: string
  tip?: string
  name: string
  starttime: number
  endtime: number
  gettj?: string[] | string
  sort: number
  fwtype?: number
  categoryids?: number[] | string
  productids?: number[] | string
  minprice: number
  money: number
  minnum: number
  zhekou: number
  proid: number
  ggid: number
  status?: number
  status_text?: string
  levels?: { id: number; name: string }[]
}

export function getRestaurantCuxiaoList(params: { page?: number; limit?: number; name?: string }) {
  return request.get<unknown, { count: number; list: RestaurantCuxiaoRow[] }>('/admin/restaurant/cuxiao/list', {
    params,
  })
}

export function getRestaurantCuxiaoDetail(id: number) {
  return request.get<unknown, { info: RestaurantCuxiaoRow }>('/admin/restaurant/cuxiao/detail', {
    params: { id },
  })
}

export function saveRestaurantCuxiao(info: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/restaurant/cuxiao/save', { info })
}

export function deleteRestaurantCuxiao(ids: number[]) {
  return request.post<unknown, null>('/admin/restaurant/cuxiao/del', { ids })
}

// ==================== 餐饮统计（RestaurantTongji） ====================

export interface RestaurantTongjiRow {
  date: string
  orderCount: number
  orderMoney: number
  refundCount: number
  refundMoney: number
  netMoney: number
}

export interface RestaurantTongjiTotals {
  orderCount: number
  orderMoney: number
  refundCount: number
  refundMoney: number
  netMoney: number
}

export function getRestaurantTongjiList(params: { stime: string; etime: string; scope?: string }) {
  return request.get<unknown, { list: RestaurantTongjiRow[]; totals: RestaurantTongjiTotals }>(
    '/admin/restaurant/tongji/list',
    { params },
  )
}

export interface RestaurantTongjiOverview {
  shop: RestaurantTongjiTotals
  takeaway: RestaurantTongjiTotals
}

export function getRestaurantTongjiOverview(params: { stime: string; etime: string }) {
  return request.get<unknown, RestaurantTongjiOverview>('/admin/restaurant/tongji/overview', { params })
}

// ==================== 餐饮设置（RestaurantSysset） ====================

export interface RestaurantSyssetInfo {
  id: number
  shop_comment: number
  shop_comment_check: number
  shop_showcommission: number
  takeaway_autoclose: number
  takeaway_comment: number
  takeaway_comment_check: number
  takeaway_showcommission: number
  queue_appid: string
  queue_secretid: string
  queue_secretkey: string
  queue_voicetype: string
  business_cashdesk_wxpay_type: number
  business_cashdesk_sxpay_type: number
  business_cashdesk_alipay_type: number
  business_cashdesk_yue: number
  business_cashdesk_cashpay: number
}

export function getRestaurantSysset() {
  return request.get<unknown, { info: RestaurantSyssetInfo }>('/admin/restaurant/sysset/index')
}

export function saveRestaurantSysset(info: Partial<RestaurantSyssetInfo>) {
  return request.post<unknown, null>('/admin/restaurant/sysset/save', { info })
}
