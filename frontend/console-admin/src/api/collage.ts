import request from './request'
import type {
  CollageSyssetData,
  CollageSyssetSaveParams,
  CollageProductDetail,
  CollageProductItem,
  CollageProductListQuery,
  CollageProductSaveParams,
  CollageTeamListQuery,
  CollageTeamListResult,
  CollageOrderListQuery,
  CollageOrderListResult,
} from './types'

/* ================= 本地扩展类型（不改 types.ts） ================= */

/** 列表行：在基础商品行上追加分类名/所属商户名 */
export interface CollageProductRow extends CollageProductItem {
  cname: string
  bname: string
}

/** 列表查询：追加分类过滤与排序白名单参数 */
export interface CollageProductListQueryExt extends CollageProductListQuery {
  cid?: number
  orderField?: string
  orderType?: string
}

export interface CollageProductListResultExt {
  list: CollageProductRow[]
  total: number
  page: number
  pagesize: number
}

/** 保存参数：追加轮播图 pics 及详情页补齐的扩展字段 */
export interface CollageProductSaveParamsExt extends CollageProductSaveParams {
  pics?: string
  goods_id?: number
  no_discount?: number
  freighttype?: number
  leaderscore?: number
  commissionset?: number
  sales?: number
  /** SKU 行扩展字段（规格编号/重量/图片），后端若缺列由统筹方补 */
  options?: Array<{
    name: string
    sell_price: number | string
    market_price: number | string
    cost_price?: number | string
    stock: number
    procode?: string
    weight?: number
    pic?: string
  }>
}

/* ================= 拼团设置 ================= */

// 读取拼团设置
export function getCollageSet() {
  return request.get<unknown, CollageSyssetData>('/admin/collage/set')
}

// 保存拼团设置
export function saveCollageSet(data: CollageSyssetSaveParams) {
  return request.post<unknown, null>('/admin/collage/set', data)
}

/* ================= 拼团商品 ================= */

// 商品列表
export function getCollageProductList(params: CollageProductListQueryExt) {
  return request.get<unknown, CollageProductListResultExt>('/admin/collage/product/list', {
    params,
  })
}

// 商品详情（含规格行）
export function getCollageProductDetail(id: number) {
  return request.get<unknown, CollageProductDetail>('/admin/collage/product/detail', {
    params: { id },
  })
}

// 新增/编辑拼团商品
export function saveCollageProduct(data: CollageProductSaveParamsExt) {
  return request.post<unknown, { id: number }>('/admin/collage/product/save', data)
}

// 商品上架/下架
export function setCollageProductStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/collage/product/status', { id, status })
}

// 商品审核（ischecked 1通过 2驳回）
export function checkCollageProduct(id: number, ischecked: number, reason = '') {
  return request.post<unknown, null>('/admin/collage/product/check', { id, ischecked, reason })
}

// 删除商品
export function deleteCollageProduct(id: number) {
  return request.post<unknown, null>('/admin/collage/product/delete', { id })
}

// 批量上架/下架（ids 数组）
export function batchSetCollageProductStatus(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/collage/product/status', { ids, status })
}

// 批量删除（ids 数组）
export function batchDeleteCollageProducts(ids: number[]) {
  return request.post<unknown, null>('/admin/collage/product/delete', { ids })
}

/* ================= 团管理 ================= */

// 团列表
export function getCollageTeamList(params: CollageTeamListQuery) {
  return request.get<unknown, CollageTeamListResult>('/admin/collage/team/list', {
    params,
  })
}

// 删除团（进行中禁删）
export function deleteCollageTeam(id: number) {
  return request.post<unknown, null>('/admin/collage/team/delete', { id })
}

/* ================= 拼团订单 ================= */

// 订单列表
export function getCollageOrderList(params: CollageOrderListQuery) {
  return request.get<unknown, CollageOrderListResult>('/admin/collage/order/list', {
    params,
  })
}

// 订单改价（仅未支付）
export function changeCollageOrderPrice(id: number, price: number | string) {
  return request.post<unknown, null>('/admin/collage/order/changePrice', { id, price })
}

// 关闭订单
export function closeCollageOrder(id: number) {
  return request.post<unknown, null>('/admin/collage/order/close', { id })
}

// 退款审核（pass 1同意 2驳回）
export function checkCollageOrderRefund(id: number, pass: number, remark = '') {
  return request.post<unknown, null>('/admin/collage/order/refundCheck', { id, pass, remark })
}

// 发货
export function sendCollageOrderExpress(id: number, express_com: string, express_no: string) {
  return request.post<unknown, null>('/admin/collage/order/sendExpress', {
    id,
    express_com,
    express_no,
  })
}

// 快递公司下拉（手动发货）
export function getCollageExpressOptions() {
  return request.get<unknown, { list: { name: string; code: string }[] }>('/admin/collage/order/expressOptions')
}

// 直接退款（管理员发起，原路退回）
export function refundCollageOrder(id: number, reason: string) {
  return request.post<unknown, null>('/admin/collage/order/refund', { id, reason })
}

// Excel 批量发货（multipart：file + express_com）
export function batchShipCollageByExcel(formData: FormData) {
  return request.post<unknown, { total: number; success: number; fail: number }>(
    '/admin/collage/order/batchShip',
    formData,
    { timeout: 120000 },
  )
}

// 订单导出（返回 xlsx Blob）
export function exportCollageOrders(params: Record<string, unknown>) {
  return request.get('/admin/collage/order/export', {
    params,
    responseType: 'blob',
    timeout: 180000,
  }) as Promise<Blob>
}

// 电子面单前置数据（复用 ewaybill，ordertype=collage）
export function getCollageEwaybillPrepare(orderid: number) {
  return request.get<unknown, Record<string, any>>('/admin/ewaybill/prepare', {
    params: { ordertype: 'collage', orderid },
  })
}

// 创建电子面单（复用 ewaybill/add，ordertype=collage）
export function addCollageEwaybill(data: Record<string, any>) {
  return request.post<unknown, { id: number; express_no: string; order_code: string }>('/admin/ewaybill/add', data, {
    timeout: 120000,
  })
}

// 订单详情
export function getCollageOrderDetail(id: number) {
  return request.get<unknown, Record<string, unknown>>('/admin/collage/order/detail', {
    params: { id },
  })
}

// 删除订单（兼容 id 单条 / ids 批量）
export function deleteCollageOrder(data: { id?: number; ids?: number[] }) {
  return request.post<unknown, null>('/admin/collage/order/delete', data)
}

// 设置订单备注
export function setCollageOrderRemark(id: number, content: string) {
  return request.post<unknown, null>('/admin/collage/order/setRemark', { id, content })
}

// 改为已支付
export function payCollageOrder(id: number) {
  return request.post<unknown, null>('/admin/collage/order/ispay', { id })
}

/* ================= 评价管理 ================= */

export interface CollageCommentItem {
  id: number
  proid: number
  proname: string
  propic: string
  ggname: string
  nickname: string
  headimg: string
  score: number
  content: string
  content_pic: string
  reply_content: string
  reply_time: number
  status: number
  createtime: number
}

// 评价列表
export function getCollageCommentList(params: Record<string, unknown>) {
  return request.get<unknown, { list: CollageCommentItem[]; total: number; page: number; pagesize: number }>(
    '/admin/collage/comment/list',
    { params },
  )
}

// 评价审核/批量审核（status 1通过 0隐藏）
export function setCollageCommentSt(data: { id?: number; ids?: number[]; status: number }) {
  return request.post<unknown, null>('/admin/collage/comment/setst', data)
}

// 评价详情
export function getCollageCommentDetail(id: number) {
  return request.get<unknown, CollageCommentItem>('/admin/collage/comment/detail', { params: { id } })
}

// 评价回复
export function replyCollageComment(id: number, content: string) {
  return request.post<unknown, null>('/admin/collage/comment/reply', { id, content })
}

// 删除评价（兼容 id 单条 / ids 批量）
export function deleteCollageComment(data: { id?: number; ids?: number[] }) {
  return request.post<unknown, null>('/admin/collage/comment/delete', data)
}
