import request from './request'

/* ================= 类型 ================= */

export interface LuckyCollageSyssetData {
  pics: string
  autoshdays: number
  comment: number
  comment_check: number
  showjd: number
  robot_get: number
  tklx: number
}

export interface LuckyCollageCategoryItem {
  id: number
  name: string
  pic: string
  status: number
  sort: number
  createtime: number
}

export interface LuckyCollageProductItem {
  id: number
  name: string
  pic: string
  cid: number
  sell_price: number
  stock: number
  sales: number
  teamnum: number
  teamhour: number
  gua_num: number
  status: number
  ischecked: number
  cname: string
  bname: string
  createtime: number
  [key: string]: unknown
}

export interface LuckyCollageGuigeOption {
  id?: number
  name: string
  sell_price: number
  market_price: number
  cost_price?: number
  stock: number
}

export interface LuckyCollageTeamItem {
  id: number
  proid: number
  product_name: string
  teamnum: number
  num: number
  status: number
  status_text: string
  members: { mid: number; nickname: string; headimg: string; isjiqiren: number; iszj: number; iszj_text: string }[]
  createtime: number
  deadline: number
}

export interface LuckyCollageOrderItem {
  id: number
  ordernum: string
  buytype: number
  buytype_text: string
  teamid: number
  proname: string
  propic: string
  ggname: string
  num: number
  totalprice: number
  refund_money: number
  status: number
  status_text: string
  refund_status: number
  refund_text: string
  iszj: number
  iszj_text: string
  money: number
  isjiqiren: number
  buyer: { mid: number; nickname: string; headimg: string; isjiqiren: number }
  express_com: string
  express_no: string
  createtime: number
  [key: string]: unknown
}

export interface LuckyCollageCommentItem {
  id: number
  proname: string
  propic: string
  ggname: string
  nickname: string
  headimg: string
  score: number
  content: string
  content_pic: string
  reply_content: string
  status: number
  createtime: number
}

export interface LuckyCollageCodeItem {
  id: number
  proid: number
  proname: string
  content: string
  ordernum: string
  status: number
  nickname: string
  createtime: number
  buytime: number
}

export interface LuckyCollageJiqiItem {
  id: number
  nickname: string
  headimg: string
  status: number
  sort: number
  createtime: number
}

interface PageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

/* ================= 系统设置 ================= */

export function getLuckyCollageSet() {
  return request.get<unknown, LuckyCollageSyssetData>('/admin/luckyCollage/set')
}

export function saveLuckyCollageSet(data: Partial<LuckyCollageSyssetData>) {
  return request.post<unknown, null>('/admin/luckyCollage/set', data)
}

/* ================= 商品分类 ================= */

export function getLuckyCollageCategoryList() {
  return request.get<unknown, { list: LuckyCollageCategoryItem[] }>(
    '/admin/luckyCollage/category/list',
  )
}

export function saveLuckyCollageCategory(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/luckyCollage/category/save', data)
}

export function deleteLuckyCollageCategory(id: number) {
  return request.post<unknown, null>('/admin/luckyCollage/category/delete', { id })
}

/* ================= 商品管理 ================= */

export function getLuckyCollageProductList(params: Record<string, unknown>) {
  return request.get<unknown, PageResult<LuckyCollageProductItem>>(
    '/admin/luckyCollage/product/list',
    { params },
  )
}

export function getLuckyCollageProductDetail(id: number) {
  return request.get<unknown, Record<string, unknown>>('/admin/luckyCollage/product/detail', {
    params: { id },
  })
}

export function saveLuckyCollageProduct(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/luckyCollage/product/save', data)
}

export function setLuckyCollageProductStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/luckyCollage/product/status', { id, status })
}

export function checkLuckyCollageProduct(id: number, ischecked: number, reason = '') {
  return request.post<unknown, null>('/admin/luckyCollage/product/check', {
    id,
    ischecked,
    reason,
  })
}

export function deleteLuckyCollageProduct(id: number) {
  return request.post<unknown, null>('/admin/luckyCollage/product/delete', { id })
}

// 批量上架/下架（ids 数组）
export function batchSetLuckyCollageProductStatus(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/luckyCollage/product/status', { ids, status })
}

// 批量删除（ids 数组）
export function batchDeleteLuckyCollageProducts(ids: number[]) {
  return request.post<unknown, null>('/admin/luckyCollage/product/delete', { ids })
}

/* ================= 团管理 ================= */

export function getLuckyCollageTeamList(params: Record<string, unknown>) {
  return request.get<unknown, PageResult<LuckyCollageTeamItem>>('/admin/luckyCollage/team/list', {
    params,
  })
}

export function deleteLuckyCollageTeam(id: number) {
  return request.post<unknown, null>('/admin/luckyCollage/team/delete', { id })
}

/* ================= 订单管理 ================= */

export function getLuckyCollageOrderList(params: Record<string, unknown>) {
  return request.get<unknown, PageResult<LuckyCollageOrderItem>>(
    '/admin/luckyCollage/order/list',
    { params },
  )
}

export function closeLuckyCollageOrder(id: number) {
  return request.post<unknown, null>('/admin/luckyCollage/order/close', { id })
}

export function sendLuckyCollageOrderExpress(id: number, express_com: string, express_no: string) {
  return request.post<unknown, null>('/admin/luckyCollage/order/sendExpress', {
    id,
    express_com,
    express_no,
  })
}

/* ================= 评价管理 ================= */

export function getLuckyCollageCommentList(params: Record<string, unknown>) {
  return request.get<unknown, PageResult<LuckyCollageCommentItem>>(
    '/admin/luckyCollage/comment/list',
    { params },
  )
}

export function replyLuckyCollageComment(id: number, content: string) {
  return request.post<unknown, null>('/admin/luckyCollage/comment/reply', { id, content })
}

export function deleteLuckyCollageComment(id: number) {
  return request.post<unknown, null>('/admin/luckyCollage/comment/delete', { id })
}

/* ================= 卡密管理 ================= */

export function getLuckyCollageCodeList(params: Record<string, unknown>) {
  return request.get<unknown, PageResult<LuckyCollageCodeItem>>('/admin/luckyCollage/code/list', {
    params,
  })
}

export function importLuckyCollageCode(proid: number, contents: string) {
  return request.post<unknown, { added: number; skipped: number }>(
    '/admin/luckyCollage/code/import',
    { proid, contents },
  )
}

export function deleteLuckyCollageCode(id: number) {
  return request.post<unknown, null>('/admin/luckyCollage/code/delete', { id })
}

/* ================= 机器人管理 ================= */

export function getLuckyCollageJiqiList(params: Record<string, unknown>) {
  return request.get<unknown, PageResult<LuckyCollageJiqiItem>>('/admin/luckyCollage/jiqi/list', {
    params,
  })
}

export function saveLuckyCollageJiqi(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/luckyCollage/jiqi/save', data)
}

export function deleteLuckyCollageJiqi(id: number) {
  return request.post<unknown, null>('/admin/luckyCollage/jiqi/delete', { id })
}

// 机器人开团/参团（凑人数，满员触发开奖）
export function luckyCollageKaituan(proid: number, buytype: number, teamid = 0) {
  return request.post<unknown, { team_id: number; order_id: number; kaijiang: boolean }>(
    '/admin/luckyCollage/jiqi/kaituan',
    { proid, buytype, teamid },
  )
}
