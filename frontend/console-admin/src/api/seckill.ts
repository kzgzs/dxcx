import request from './request'
// ============ 类型（本模块私有，避免改动共享 types.ts） ============

export interface SeckillSysset {
  timeset: number[]
  duration: number
  status: number
  autoshdays: number
  autoclose: number
  comment: number
  comment_check: number
  showjd: number
  showcommission: number
}

export interface SeckillGuige {
  id: number
  name: string
  sell_price: number
  stock: number
  sales: number
}

export interface SeckillProductItem {
  id: number
  name: string
  pic: string
  pics: string
  procode: string
  market_price: number
  sell_price: number
  stock: number
  sales: number
  buymax: number
  perlimit: number
  status: number
  sort: number
  createtime: number
  seckill_date: string
  seckill_time: number
  starttime: number
  sellpoint: string
  detail?: string
  ischecked: number
  check_reason: string
  bname: string
  bid: number
  salepercent: number
  salestotal: number
  guige: SeckillGuige[]
}

export interface SeckillProdataItem {
  proid: number
  name: string
  pic: string
  prostatus: number
  seckill_date: string
  seckill_time: number
  starttime: number
  endtime: number
  seckill_price: number
  seckill_num: number
  sales: number
  salepercent: number
  buymax: number
  ids: number[]
}

export interface SeckillOrderItem {
  id: number
  ordernum: string
  mid: number
  nickname: string
  headimg: string
  title: string
  proname: string
  propic: string
  ggname: string
  num: number
  sell_price: number
  totalprice: number
  status: number
  linkman: string
  tel: string
  address: string
  paytype: string
  createtime: number
  paytime: number
  send_time: number
  remark: string
}

export interface PageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

export interface SeckillProdataListResult extends PageResult<SeckillProdataItem> {
  timeset: number[]
  duration: number
}

export interface SeckillProductSaveParams {
  id?: number
  goods_id?: number
  name?: string
  pic?: string
  pics?: string
  market_price?: number
  sell_price: number
  stock?: number
  buymax?: number
  perlimit?: number
  sort?: number
  status?: number
  seckill_date?: string
  seckill_time?: number
  starttime?: number
  sellpoint?: string
  detail?: string
  video?: string
  cost_price?: number
  gettj?: string
  guige?: { id?: number; name: string; market_price?: number; cost_price?: number; sell_price: number; weight?: number; stock: number; givescore?: number; pic?: string }[]
}

export type SeckillGuigeEdit = Omit<SeckillGuige, 'id'> & { id?: number }

export interface SeckillProductDetailResult extends SeckillProductItem {
  guige: SeckillGuige[]
}

export interface SeckillProdataSaveParams {
  proid: number
  dates: string[]
  times: number[]
  buymax: number
  options: Record<string, { seckill_price: number; seckill_num: number }>
}

// ============ 设置 ============

export function getSeckillSet() {
  return request.get<unknown, SeckillSysset>('/admin/seckill/set')
}

export function saveSeckillSet(data: Partial<SeckillSysset>) {
  return request.post<unknown, null>('/admin/seckill/set', data)
}

// ============ 秒杀商品 ============

export function getSeckillProductList(params: {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
  /** 商户维度：空/all=全部 0=自营 gt0=商户（任务177） */
  bid?: string
  /** 审核状态：0待审 1通过 2驳回 */
  checked?: number | ''
  /** 秒杀日期 Y-m-d */
  seckill_date?: string
  /** 秒杀场次小时 */
  seckill_time?: number | ''
  orderField?: string
  orderType?: string
}) {
  return request.get<unknown, PageResult<SeckillProductItem>>('/admin/seckill/product/list', {
    params,
  })
}

// 商品详情（含规格行）
export function getSeckillProductDetail(id: number) {
  return request.get<unknown, SeckillProductDetailResult>('/admin/seckill/product/detail', {
    params: { id },
  })
}

export function saveSeckillProduct(data: SeckillProductSaveParams) {
  return request.post<unknown, { id: number }>('/admin/seckill/product/save', data)
}

// 删除（单 id 或批量 ids[]；已有场次/订单时后端拒绝）
export function deleteSeckillProduct(id: number | number[]) {
  const payload = Array.isArray(id) ? { ids: id } : { id }
  return request.post<unknown, null>('/admin/seckill/product/delete', payload)
}

// 上下架（单 id 或批量 ids[]）
export function setSeckillProductStatus(id: number | number[], status: number) {
  const payload = Array.isArray(id) ? { ids: id, status } : { id, status }
  return request.post<unknown, null>('/admin/seckill/product/status', payload)
}

// 审核（ischecked 1通过 2驳回；驳回须填原因）
export function checkSeckillProduct(id: number, ischecked: number, reason = '') {
  return request.post<unknown, null>('/admin/seckill/product/check', { id, ischecked, reason })
}

// 复制秒杀商品（连带规格）
export function copySeckillProduct(id: number) {
  return request.post<unknown, { id: number }>('/admin/seckill/product/procopy', { id })
}

// ============ 场次明细 ============

export function getSeckillProdataList(params: {
  page?: number
  pagesize?: number
  proid?: number
  date?: string
  time?: number | ''
  keyword?: string
}) {
  return request.get<unknown, SeckillProdataListResult>('/admin/seckill/prodata/list', {
    params,
  })
}

export function saveSeckillProdata(data: SeckillProdataSaveParams) {
  return request.post<unknown, { count: number }>('/admin/seckill/prodata/save', data)
}

export function deleteSeckillProdata(ids: number[]) {
  return request.post<unknown, null>('/admin/seckill/prodata/delete', { ids })
}

// ============ 秒杀订单 ============

export function getSeckillOrderList(params: {
  page?: number
  pagesize?: number
  status?: number | ''
  ordernum?: string
  proname?: string
  linkman?: string
  tel?: string
  start_date?: string
  end_date?: string
}) {
  return request.get<unknown, PageResult<SeckillOrderItem>>('/admin/seckill/order/list', {
    params,
  })
}

export function closeSeckillOrder(id: number) {
  return request.post<unknown, null>('/admin/seckill/order/close', { id })
}

// 订单详情（含下单会员）
export function getSeckillOrderDetail(id: number) {
  return request.get<unknown, { order: Record<string, any>; member: Record<string, any> }>(
    '/admin/seckill/order/detail',
    { params: { id } },
  )
}

// 删除订单（单 id 或批量 ids[]）
export function deleteSeckillOrder(id: number | number[]) {
  const payload = Array.isArray(id) ? { ids: id } : { id }
  return request.post<unknown, null>('/admin/seckill/order/del', payload)
}

// 改为已支付（仅未支付）
export function ispaySeckillOrder(id: number) {
  return request.post<unknown, null>('/admin/seckill/order/ispay', { id })
}

// 核销并确认收货（1/2 → 3）
export function hexiaoSeckillOrder(id: number) {
  return request.post<unknown, null>('/admin/seckill/order/hexiao', { id })
}

// 手动发货（已支付→已发货；已发货时更新物流）
export function shipSeckillOrder(id: number, express_com: string, express_no: string) {
  return request.post<unknown, null>('/admin/seckill/order/sendExpress', { id, express_com, express_no })
}

// 快递公司下拉（手动发货）
export function getSeckillExpressOptions() {
  return request.get<unknown, { code: string; name: string }[]>('/admin/seckill/order/expressOptions')
}

// 查物流（快递鸟轨迹）
export interface SeckillTrace {
  express_com: string
  express_no: string
  send_time: number
  traces: { time: string; station: string }[]
}
export function getSeckillOrderTrace(id: number) {
  return request.get<unknown, SeckillTrace>('/admin/seckill/order/trace', { params: { id } })
}

// 送货单（整页打印数据）
export interface SeckillDeliveryNote {
  ordernum: string
  createtime: string
  proname: string
  propic: string
  ggname: string
  num: number
  sell_price: number
  totalprice: number
  linkman: string
  tel: string
  address: string
  freight_text: string
  express_com: string
  express_no: string
}
export function getSeckillDeliveryNote(id: number) {
  return request.get<unknown, SeckillDeliveryNote>('/admin/seckill/order/deliveryNote', { params: { id } })
}

// 订单导出（blob 下载真实 xlsx）
export async function exportSeckillOrder(params: Record<string, unknown>) {
  const res = await request.get('/admin/seckill/order/export', {
    params,
    responseType: 'blob',
    transformResponse: [(data: unknown) => data],
  })
  const blob = res instanceof Blob ? res : new Blob([res as unknown as BlobPart])
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `秒杀订单_${new Date().getTime()}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// ============ 秒杀评价 ============

export interface SeckillCommentItem {
  id: number
  mid: number
  proid: number
  proname: string
  propic: string
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

export function getSeckillCommentList(params: {
  page?: number
  pagesize?: number
  status?: number | ''
  content?: string
  proid?: number
  start_date?: string
  end_date?: string
}) {
  return request.get<unknown, PageResult<SeckillCommentItem>>('/admin/seckill/comment/list', { params })
}

export function setSeckillCommentStatus(id: number | number[], status: number) {
  const payload = Array.isArray(id) ? { ids: id, status } : { id, status }
  return request.post<unknown, null>('/admin/seckill/comment/setStatus', payload)
}

export function replySeckillComment(id: number, content: string) {
  return request.post<unknown, null>('/admin/seckill/comment/reply', { id, content })
}

export function deleteSeckillComment(id: number | number[]) {
  const payload = Array.isArray(id) ? { ids: id } : { id }
  return request.post<unknown, null>('/admin/seckill/comment/delete', payload)
}