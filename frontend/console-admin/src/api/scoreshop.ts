import request from './request'

// ============ 类型（本模块私有，避免改动共享 types.ts） ============

export interface ScoreshopSysset {
  autoclose: number
  autoshdays: number
  gettj: string
  gettjtip: string
  gettjurl: string
  comment: number
  comment_check: number
  showjd: number
  showcommission: number
}

export interface ScoreshopCategoryItem {
  id: number
  name: string
  pic: string
  status: number
  sort: number
  goods_count: number
  createtime: number
}

export interface ScoreshopGuige {
  id: number
  name: string
  score_price: number
  money_price: number
  stock: number
  sales: number
}

export interface ScoreshopProductItem {
  id: number
  cid: number
  cname: string
  name: string
  pic: string
  score_price: number
  money_price: number
  sell_price: number
  stock: number
  sales: number
  buymax: number
  status: number
  sort: number
  createtime: number
  guige: ScoreshopGuige[]
}

export interface ScoreshopOrderGoods {
  name: string
  pic: string
  ggname: string
  num: number
  score_price: number
  money_price: number
}

export interface ScoreshopOrderItem {
  id: number
  ordernum: string
  mid: number
  nickname: string
  headimg: string
  title: string
  totalscore: number
  totalmoney: number
  totalprice: number
  totalnum: number
  status: number
  linkman: string
  tel: string
  address: string
  paytype: string
  createtime: number
  paytime: number
  send_time: number
  remark: string
  goods: ScoreshopOrderGoods[]
}

export interface PageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

export interface ScoreshopProductSaveParams {
  id?: number
  cid: number
  name: string
  pic?: string
  detail?: string
  sell_price?: number
  score_price: number
  money_price: number
  buymax: number
  sort?: number
  status?: number
  guige?: { id?: number; name: string; score_price: number; money_price: number; stock: number }[]
}

// ============ 设置 ============

export function getScoreshopSet() {
  return request.get<unknown, ScoreshopSysset>('/admin/scoreshop/set')
}

export function saveScoreshopSet(data: Partial<ScoreshopSysset>) {
  return request.post<unknown, null>('/admin/scoreshop/set', data)
}

// ============ 分类 ============

export function getScoreshopCategoryList(keyword?: string) {
  return request.get<unknown, { list: ScoreshopCategoryItem[] }>('/admin/scoreshop/category/list', {
    params: { keyword: keyword || undefined },
  })
}

export function saveScoreshopCategory(data: {
  id?: number
  name: string
  pic?: string
  sort?: number
  status?: number
}) {
  return request.post<unknown, { id: number }>('/admin/scoreshop/category/save', data)
}

export function deleteScoreshopCategory(id: number) {
  return request.post<unknown, null>('/admin/scoreshop/category/delete', { id })
}

// ============ 商品 ============

export function getScoreshopProductList(params: {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
  cid?: number
}) {
  return request.get<unknown, PageResult<ScoreshopProductItem>>('/admin/scoreshop/product/list', {
    params,
  })
}

export function saveScoreshopProduct(data: ScoreshopProductSaveParams) {
  return request.post<unknown, { id: number }>('/admin/scoreshop/product/save', data)
}

export function deleteScoreshopProduct(id: number) {
  return request.post<unknown, null>('/admin/scoreshop/product/delete', { id })
}

export function setScoreshopProductStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/scoreshop/product/status', { id, status })
}

// ============ 兑换订单 ============

export function getScoreshopOrderList(params: {
  page?: number
  pagesize?: number
  status?: number | ''
  ordernum?: string
  title?: string
  linkman?: string
  tel?: string
  start_date?: string
  end_date?: string
}) {
  return request.get<unknown, PageResult<ScoreshopOrderRow>>('/admin/scoreshop/order/list', {
    params,
  })
}

export function closeScoreshopOrder(id: number) {
  return request.post<unknown, null>('/admin/scoreshop/order/close', { id })
}

// 兑换订单导出（按筛选条件；返回 xlsx Blob）
export function exportScoreshopOrders(params: {
  status?: number | ''
  ordernum?: string
  linkman?: string
  tel?: string
  start_date?: string
  end_date?: string
}) {
  return request.get('/admin/scoreshop/order/export', {
    params,
    responseType: 'blob',
    timeout: 180000,
  }) as Promise<Blob>
}

// 商品批量删除（ids 数组）
export function batchDeleteScoreshopProducts(ids: number[]) {
  return request.post<unknown, null>('/admin/scoreshop/product/batchDelete', { ids })
}

// 商品批量上下架（ids 数组 + status）
export function batchSetScoreshopProductStatus(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/scoreshop/product/batchStatus', { ids, status })
}

// ============ 商品详情/编辑回填 ============

export interface ScoreshopGuigeRow {
  id?: number
  name: string
  pic?: string
  market_price?: number
  cost_price?: number
  score_price: number
  money_price: number
  weight?: number
  stock: number
}

export interface ScoreshopProductDetail {
  id: number
  cid: number
  name: string
  pic: string
  pics: string
  detail: string
  sell_price: number
  cost_price: number
  score_price: number
  money_price: number
  buymax: number
  everyday_buymax: number
  lvprice: number
  weight: number
  sales: number
  stock: number
  sort: number
  status: number
  freighttype: number
  freightdata: string
  freightcontent: string
  contact_require: number
  commissionset: number
  commissiondata1: Record<string, Record<string, string | { money: string; score: string }>>
  commissiondata2: Record<string, Record<string, string | { money: string; score: string }>>
  commissiondata3: Record<string, Record<string, string | { money: string; score: string }>>
  commissiondata5: Record<string, Record<string, string | { money: string; score: string }>>
  showtj: string[]
  gettj: string[]
  guige: ScoreshopGuigeRow[]
}

export function getScoreshopProductDetail(id: number) {
  return request.get<unknown, ScoreshopProductDetail>('/admin/scoreshop/product/detail', { params: { id } })
}

// ============ 订单操作 ============

export interface ScoreshopOrderRow extends ScoreshopOrderItem {
  freight_type: number
  freight_text: string
  express_com: string
  express_no: string
  mdid: number
  refund_status: number
  refund_text: string
  refund_money: number
  platform: string
  platform_text: string
  area: string
  hexiao_code: string
}

export function sendScoreshopOrderExpress(id: number, express_com: string, express_no: string) {
  return request.post<unknown, null>('/admin/scoreshop/order/sendExpress', { id, express_com, express_no })
}

export function hexiaoScoreshopOrder(id: number) {
  return request.post<unknown, null>('/admin/scoreshop/order/hexiao', { id })
}

export function refundScoreshopOrder(id: number, reason: string) {
  return request.post<unknown, null>('/admin/scoreshop/order/refund', { id, reason })
}

export function deliverScoreshopOrder(id: number, mdid: number) {
  return request.post<unknown, null>('/admin/scoreshop/order/deliver', { id, mdid })
}

export function ispayScoreshopOrder(id: number) {
  return request.post<unknown, null>('/admin/scoreshop/order/ispay', { id })
}

export function deleteScoreshopOrder(id: number | number[]) {
  const payload = Array.isArray(id) ? { ids: id } : { id }
  return request.post<unknown, null>('/admin/scoreshop/order/del', payload)
}

export function getScoreshopExpressOptions() {
  return request.get<unknown, { code: string; name: string }[]>('/admin/scoreshop/order/expressOptions')
}

export function getScoreshopOrderTrace(id: number) {
  return request.get<unknown, { express_com: string; express_no: string; traces: { time: string; station: string }[] }>(
    '/admin/scoreshop/order/trace', { params: { id } })
}

// 送货单打印数据（按订单自动生成商品信息）
export function getScoreshopShd(id: number) {
  return request.get<unknown, Record<string, any>>('/admin/scoreshop/order/shd', { params: { id } })
}

export function batchSendScoreshopOrder(express_com: string, file: File) {
  const fd = new FormData()
  fd.append('express_com', express_com)
  fd.append('file', file)
  return request.post<unknown, { success: number; fail: number; fail_list: string[] }>(
    '/admin/scoreshop/order/batchSend', fd,
    { headers: { 'Content-Type': 'multipart/form-data' } })
}

export function getScoreshopAgentLevels() {
  return request.get<unknown, { id: number; name: string; can_agent: number }[]>('/admin/scoreshop/agentLevels')
}

export function getScoreshopDeliveryStaff() {
  return request.get<unknown, { id: number; name: string; mobile: string; doing: number }[]>('/admin/scoreshop/deliveryStaff')
}

// 电子面单（ordertype=scoreshop，复用通用 ewaybill 接口）
export function ewaybillPrepareScoreshop(orderid: number) {
  return request.get<unknown, {
    ordercode: string
    express_options: { code: string; name: string; shipperCode?: string; expCode?: string; iswarm?: number }[]
    warm_layer: Record<string, string>
    last_order: Record<string, any>
    is_mock: boolean
  }>('/admin/ewaybill/prepare', { params: { ordertype: 'scoreshop', orderid } })
}

export function ewaybillAddScoreshop(data: Record<string, any>) {
  return request.post<unknown, any>('/admin/ewaybill/add', { ...data, ordertype: 'scoreshop' })
}
