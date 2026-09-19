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

/** 保存参数：追加轮播图 pics */
export interface CollageProductSaveParamsExt extends CollageProductSaveParams {
  pics?: string
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
