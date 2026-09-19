import request from './request'

/* ================= 类型 ================= */

// 团购设置
export interface TuangouSyssetData {
  pics: string // 活动说明图
  autoshdays: number // 自动收货天数
  comment: number // 评价开关
  comment_check: number // 评价审核
  showjd: number // 显示购买进度
}

export type TuangouSyssetSaveParams = Partial<TuangouSyssetData>

// 团购分类
export interface TuangouCategoryItem {
  id: number
  pid: number
  name: string
  pic: string
  status: number
  sort: number
  createtime: number
}

// 阶梯价档位
export interface TuangouPriceTier {
  num: number
  money: number
}

// 团购商品行
export interface TuangouProductItem {
  id: number
  cid: number
  name: string
  pic: string
  pics: string
  sellpoint: string
  fuwupoint: string
  procode: string
  market_price: number
  sell_price: number // 初始价（起价）
  nowprice: number // 当前达成价
  minprice: number // 最低档价
  buynum: number // 已购总人数
  pricedata: TuangouPriceTier[]
  cost_price: number
  weight: number
  stock: number
  sales: number
  perlimit: number
  starttime: number
  endtime: number
  showtj: string
  gettj: string
  freighttype: number
  freightdata: string
  scoredkmaxset: number
  scoredkmaxval: number
  contact_require: number
  sort: number
  status: number
  ischecked: number
  check_reason: string
  comment_score: number
  comment_num: number
  comment_haopercent: number
  detail: string
  createtime: number
  bid?: number
  bname?: string
  cname?: string
}

export interface TuangouProductListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: string | number
  checked?: string | number
  cid?: number
  bid?: string
  orderField?: string
  orderType?: string
}

export interface TuangouListResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

export type TuangouProductSaveParams = Record<string, unknown> & { id?: number; name: string }

// 团购订单行
export interface TuangouOrderItem {
  id: number
  ordernum: string
  proid: number
  proname: string
  propic: string
  num: number
  sell_price: number
  totalprice: number
  product_price: number
  freight_price: number
  freight_type: number
  freight_text: string
  freight_type_text: string
  refund_money: number
  refund_reason: string
  status: number
  status_text: string
  refund_status: number
  refund_text: string
  buyer: { mid: number; nickname: string; headimg: string }
  linkman: string
  tel: string
  address: string
  message: string
  remark: string
  express_com: string
  express_no: string
  hexiao_code: string
  iscomment: number
  paytype: string
  paytime: number
  send_time: number
  collect_time: number
  createtime: number
}

export interface TuangouOrderListQuery {
  page?: number
  pagesize?: number
  status?: string | number
  keyword?: string
  bid?: string
}

// 团购评价行
export interface TuangouCommentItem {
  id: number
  mid: number
  proid: number
  proname: string
  propic: string
  ordernum: string
  nickname: string
  headimg: string
  score: number
  content: string
  content_pic: string
  reply_content: string
  reply_time: number
  status: number
  status_text: string
  createtime: number
}

// 团购卡密行
export interface TuangouCodeItem {
  id: number
  proid: number
  content: string
  ordernum: string
  orderid: number
  nickname: string
  status: number
  create_time: string
  buy_time: string
}

/* ================= 团购设置 ================= */

export function getTuangouSet() {
  return request.get<unknown, TuangouSyssetData>('/admin/tuangou/set')
}

export function saveTuangouSet(data: TuangouSyssetSaveParams) {
  return request.post<unknown, null>('/admin/tuangou/set', data)
}

/* ================= 团购分类 ================= */

export function getTuangouCategoryList() {
  return request.get<unknown, TuangouCategoryItem[]>('/admin/tuangou/category/list')
}

export function saveTuangouCategory(data: Partial<TuangouCategoryItem>) {
  return request.post<unknown, { id: number }>('/admin/tuangou/category/save', data)
}

export function deleteTuangouCategory(id: number) {
  return request.post<unknown, null>('/admin/tuangou/category/delete', { id })
}

/* ================= 团购商品 ================= */

export function getTuangouProductList(params: TuangouProductListQuery) {
  return request.get<unknown, TuangouListResult<TuangouProductItem>>('/admin/tuangou/product/list', {
    params,
  })
}

export function getTuangouProductDetail(id: number) {
  return request.get<unknown, TuangouProductItem>('/admin/tuangou/product/detail', {
    params: { id },
  })
}

export function saveTuangouProduct(data: TuangouProductSaveParams) {
  return request.post<unknown, { id: number }>('/admin/tuangou/product/save', data)
}

export function copyTuangouProduct(id: number) {
  return request.post<unknown, { id: number }>('/admin/tuangou/product/copy', { id })
}

export function setTuangouProductStatus(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/tuangou/product/status', { ids, status })
}

export function checkTuangouProduct(id: number, ischecked: number, reason = '') {
  return request.post<unknown, null>('/admin/tuangou/product/check', { id, ischecked, reason })
}

export function deleteTuangouProduct(ids: number | number[]) {
  return request.post<unknown, null>('/admin/tuangou/product/delete', { ids: Array.isArray(ids) ? ids : [ids] })
}

export function refundTuangouProduct(id: number) {
  return request.post<unknown, { money: number; total: number }>('/admin/tuangou/product/refundDiff', { id })
}

/* ================= 团购订单 ================= */

export function getTuangouOrderList(params: TuangouOrderListQuery) {
  return request.get<unknown, TuangouListResult<TuangouOrderItem>>('/admin/tuangou/order/list', {
    params,
  })
}

export function getTuangouOrderDetail(id: number) {
  return request.get<unknown, TuangouOrderItem>('/admin/tuangou/order/detail', {
    params: { id },
  })
}

export function changeTuangouOrderPrice(id: number, price: number | string) {
  return request.post<unknown, null>('/admin/tuangou/order/changePrice', { id, price })
}

export function closeTuangouOrder(id: number) {
  return request.post<unknown, null>('/admin/tuangou/order/close', { id })
}

export function remarkTuangouOrder(id: number, remark: string) {
  return request.post<unknown, null>('/admin/tuangou/order/remark', { id, remark })
}

export function checkTuangouOrderRefund(id: number, pass: number, remark = '') {
  return request.post<unknown, null>('/admin/tuangou/order/refundCheck', { id, pass, remark })
}

export function sendTuangouOrderExpress(id: number, express_com: string, express_no: string) {
  return request.post<unknown, null>('/admin/tuangou/order/sendExpress', {
    id,
    express_com,
    express_no,
  })
}

export function hexiaoTuangouOrder(id: number) {
  return request.post<unknown, null>('/admin/tuangou/order/hexiao', { id })
}

// 订单导出（blob 下载）
export async function exportTuangouOrder(params: { status?: string; keyword?: string }) {
  const res = await request.get('/admin/tuangou/order/export', {
    params,
    responseType: 'blob',
    transformResponse: [(data: unknown) => data],
  })
  const blob = res instanceof Blob ? res : new Blob([res as unknown as BlobPart])
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `团购订单_${new Date().getTime()}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

/* ================= 团购评价 ================= */

export function getTuangouCommentList(params: {
  page?: number
  pagesize?: number
  status?: string | number
  proid?: number
}) {
  return request.get<unknown, TuangouListResult<TuangouCommentItem>>('/admin/tuangou/comment/list', {
    params,
  })
}

export function setTuangouCommentStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/tuangou/comment/setst', { id, status })
}

export function replyTuangouComment(id: number, content: string) {
  return request.post<unknown, null>('/admin/tuangou/comment/reply', { id, content })
}

export function deleteTuangouComment(id: number) {
  return request.post<unknown, null>('/admin/tuangou/comment/delete', { id })
}

/* ================= 团购卡密 ================= */

export function getTuangouCodeList(params: {
  page?: number
  pagesize?: number
  proid?: number
  content?: string
  ordernum?: string
  nickname?: string
  status?: string | number
}) {
  return request.get<unknown, TuangouListResult<TuangouCodeItem>>('/admin/tuangou/code/list', {
    params,
  })
}

export function makeTuangouCode(data: {
  proid: number
  num: number
  length: number
  type: number
}) {
  return request.post<unknown, { created: number }>('/admin/tuangou/code/make', data)
}

export function importTuangouCode(proid: number, file: File) {
  const formData = new FormData()
  formData.append('proid', String(proid))
  formData.append('file', file)
  return request.post<unknown, { inserted: number; duplicated: number }>(
    '/admin/tuangou/code/import',
    formData,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
}

export async function exportTuangouCode(proid: number) {
  const res = await request.get('/admin/tuangou/code/export', {
    params: proid > 0 ? { proid } : {},
    responseType: 'blob',
    transformResponse: [(data: unknown) => data],
  })
  const blob = res instanceof Blob ? res : new Blob([res as unknown as BlobPart])
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `团购卡密_${new Date().getTime()}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

export function deleteTuangouCode(ids: number[]) {
  return request.post<unknown, { deleted: number }>('/admin/tuangou/code/delete', { ids })
}
