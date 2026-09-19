import request from './request'
import type {
  CycleSyssetData,
  CycleSyssetSaveParams,
  CycleCategoryItem,
  CycleCategorySaveParams,
  CycleProductListQuery,
  CycleProductListResult,
  CycleProductDetail,
  CycleGuigeSaveItem,
  CycleOrderListQuery,
  CycleOrderListResult,
  CycleOrderDetail,
} from './types'

/* ================= 本地扩展（不改 types.ts） ================= */

// 列表查询扩展：商户维度 bid / 审核 checked / 排序 orderField+orderType
export interface CycleProductListParams extends CycleProductListQuery {
  bid?: string
  checked?: string | number
  orderField?: string
  orderType?: string
}

// 列表行扩展：后端附加所属商户名/分类名/审核态
export interface CycleProductRow extends Omit<CycleProductDetail, 'cname'> {
  pics?: string
  ps_cycle?: number
  sales?: number
  bid?: number
  bname?: string
  cname?: string
  ischecked?: number
  check_reason?: string
}

// 保存入参扩展（后端 productSave 支持 pics/ps_cycle）
export interface CycleProductSavePayload {
  id?: number
  name: string
  pic?: string
  pics?: string
  cid?: number
  ps_cycle?: number
  sort?: number
  status?: number
  detail?: string
  guiges: CycleGuigeSaveItem[]
}

/* ================= 周期购设置 ================= */

// 读取周期购设置（自动收货天数/评价开关）
export function getCycleSysset() {
  return request.get<unknown, CycleSyssetData>('/admin/cycle/sysset')
}

// 保存周期购设置
export function saveCycleSysset(data: CycleSyssetSaveParams) {
  return request.post<unknown, null>('/admin/cycle/sysset', data)
}

/* ================= 商品分类 ================= */

// 分类列表
export function getCycleCategoryList() {
  return request.get<unknown, { list: CycleCategoryItem[] }>('/admin/cycle/category/list')
}

// 新增/编辑分类
export function saveCycleCategory(data: CycleCategorySaveParams) {
  return request.post<unknown, { id: number }>('/admin/cycle/category/save', data)
}

// 删除分类（已有商品时后端拒绝）
export function deleteCycleCategory(id: number) {
  return request.post<unknown, null>('/admin/cycle/category/del', { id })
}

/* ================= 周期购商品 ================= */

// 商品列表
export function getCycleProductList(params: CycleProductListParams) {
  return request.get<unknown, CycleProductListResult>('/admin/cycle/product/list', {
    params,
  })
}

// 商品详情（编辑回显）
export function getCycleProductDetail(id: number) {
  return request.get<unknown, CycleProductDetail>('/admin/cycle/product/detail', {
    params: { id },
  })
}

// 新增/编辑商品（含规格动态行）
export function saveCycleProduct(data: CycleProductSavePayload) {
  return request.post<unknown, { id: number }>('/admin/cycle/product/save', data)
}

// 删除商品
export function deleteCycleProduct(ids: number | number[]) {
  return request.post<unknown, null>('/admin/cycle/product/del', { ids: Array.isArray(ids) ? ids : [ids] })
}

// 商品上架/下架
export function setCycleProductStatus(ids: number | number[], status: number) {
  return request.post<unknown, null>('/admin/cycle/product/status', { ids: Array.isArray(ids) ? ids : [ids], status })
}

// 商品审核（ischecked 1通过 2驳回）
export function checkCycleProduct(id: number, ischecked: number, reason = '') {
  return request.post<unknown, null>('/admin/cycle/product/check', { id, ischecked, reason })
}

/* ================= 周期购订单 ================= */

// 订单列表
export function getCycleOrderList(params: CycleOrderListQuery) {
  return request.get<unknown, CycleOrderListResult>('/admin/cycle/order/list', {
    params,
  })
}

// 订单详情（含分期发货计划）
export function getCycleOrderDetail(id: number) {
  return request.get<unknown, CycleOrderDetail>('/admin/cycle/order/detail', {
    params: { id },
  })
}

// 按期发货（传当期 stage id）
export function shipCycleOrderStage(stageid: number, express_com: string, express_no: string) {
  return request.post<unknown, null>('/admin/cycle/order/ship', {
    stageid,
    express_com,
    express_no,
  })
}

// 分期确认收货
export function collectCycleOrderStage(stageid: number) {
  return request.post<unknown, null>('/admin/cycle/order/stageCollect', { stageid })
}

// 退款审核（pass 1同意 2驳回）
export function checkCycleOrderRefund(id: number, pass: number, remark = '') {
  return request.post<unknown, null>('/admin/cycle/order/refundCheck', { id, pass, remark })
}

// 订单备注
export function remarkCycleOrder(id: number, remark: string) {
  return request.post<unknown, null>('/admin/cycle/order/remark', { id, remark })
}
