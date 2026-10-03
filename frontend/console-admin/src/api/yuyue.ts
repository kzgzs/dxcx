import request from './request'
import type {
  YuyueCategoryListResult,
  YuyueCategorySaveParams,
  YuyueCommentListQuery,
  YuyueCommentListResult,
  YuyueFuwuListQuery,
  YuyueFuwuListResult,
  YuyueFuwuSaveParams,
  YuyueMoneyLogExportResult,
  YuyueMoneyLogListQuery,
  YuyueMoneyLogListResult,
  YuyueOrderListQuery,
  YuyueOrderListResult,
  YuyueSetConfig,
  YuyueSetSaveParams,
  YuyueWithdrawLogListQuery,
  YuyueWithdrawLogListResult,
  YuyueWorkerCategoryItem,
  YuyueWorkerCategorySaveParams,
  YuyueWorkerCommentListQuery,
  YuyueWorkerCommentListResult,
  YuyueWorkerListQuery,
  YuyueWorkerListResult,
  YuyueWorkerSaveParams,
} from './types'

// ============ 预约分类 ============
export function getYuyueCategoryList() {
  return request.get<unknown, YuyueCategoryListResult>('/admin/yuyue/category/list')
}

export function addYuyueCategory(data: YuyueCategorySaveParams) {
  return request.post<unknown, null>('/admin/yuyue/category/add', data)
}

export function editYuyueCategory(data: YuyueCategorySaveParams) {
  return request.post<unknown, null>('/admin/yuyue/category/edit', data)
}

export function deleteYuyueCategory(id: number) {
  return request.post<unknown, null>('/admin/yuyue/category/delete', { id })
}

// ============ 服务商品 YuyueProduct（服务商品页） ============
export interface YuyueProductListItem {
  id: number
  name: string
  pic: string
  pics: string[]
  cid: string
  cname: string
  bname: string
  bid: number
  sellpoint: string
  sell_price: number
  sales: number
  sort: number
  status: number
  ischecked: number
  check_reason: string
  create_time: string
}

export interface YuyueProductListResult {
  list: YuyueProductListItem[]
  total: number
  page: number
  pagesize: number
}

export interface YuyueProductListQuery {
  page: number
  pagesize: number
  name?: string
  status?: number | ''
  bid?: string
  checked?: number | ''
  orderField?: string
  orderType?: string
}

export interface YuyueProductDetail {
  id: number
  name: string
  cid: string
  pic: string
  pics: string[]
  sellpoint: string
  sell_price: number
  sales: number
  sort: number
  status: number
  detail: string
  [k: string]: unknown
}

export interface YuyueProductSaveParams {
  id?: number
  name: string
  cid?: number | string
  pic?: string
  pics?: string[]
  sellpoint?: string
  sell_price?: number
  sales?: number
  sort?: number
  status?: number
  detail?: string
}

export function getYuyueProductList(params: YuyueProductListQuery) {
  return request.get<unknown, YuyueProductListResult>('/admin/yuyue/product/list', { params })
}

export function getYuyueProductDetail(id: number) {
  return request.get<unknown, YuyueProductDetail>('/admin/yuyue/product/detail', { params: { id } })
}

export function addYuyueProduct(data: YuyueProductSaveParams) {
  return request.post<unknown, { id: number }>('/admin/yuyue/product/add', data)
}

export function editYuyueProduct(data: YuyueProductSaveParams) {
  return request.post<unknown, null>('/admin/yuyue/product/edit', data)
}

export function deleteYuyueProduct(ids: number | number[]) {
  const data = Array.isArray(ids) ? { ids } : { id: ids }
  return request.post<unknown, null>('/admin/yuyue/product/delete', data)
}

export function setYuyueProductStatus(ids: number | number[], status: number) {
  const data = Array.isArray(ids) ? { ids, status } : { id: ids, status }
  return request.post<unknown, null>('/admin/yuyue/product/status', data)
}

export function checkYuyueProduct(id: number, ischecked: number, reason = '') {
  return request.post<unknown, null>('/admin/yuyue/product/check', { id, ischecked, reason })
}

export function copyYuyueProduct(id: number) {
  return request.post<unknown, { id: number }>('/admin/yuyue/product/copy', { id })
}

// ============ 技师 ============
export function getYuyueWorkerList(params: YuyueWorkerListQuery) {
  return request.get<unknown, YuyueWorkerListResult>('/admin/yuyue/worker/list', { params })
}

export function addYuyueWorker(data: YuyueWorkerSaveParams) {
  return request.post<unknown, null>('/admin/yuyue/worker/add', data)
}

export function editYuyueWorker(data: YuyueWorkerSaveParams) {
  return request.post<unknown, null>('/admin/yuyue/worker/edit', data)
}

export function deleteYuyueWorker(id: number) {
  return request.post<unknown, null>('/admin/yuyue/worker/delete', { id })
}

export function setYuyueWorkerStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/yuyue/worker/status', { id, status })
}
// 加余额（money 负为扣除），回读变更后余额
export function addYuyueWorkerMoney(id: number, money: number, remark: string) {
  return request.post<unknown, { money: number }>('/admin/yuyue/worker/addMoney', { id, money, remark })
}

// 查看服务记录（按人员过滤的订单列表）
export function getYuyueWorkerOrders(params: Record<string, unknown>) {
  return request.get<unknown, YuyueOrderListResult>('/admin/yuyue/worker/orders', { params })
}

// 查进度（服务单时间线）
export function getYuyueWorkerOrderProgress(id: number) {
  return request.get<unknown, {
    order_id: number
    order_num: string
    status: number
    reassign: boolean
    current: number
    steps: { time: string; text: string }[]
  }>('/admin/yuyue/worker/orderProgress', { params: { id } })
}

// ============ 预约订单 ============
export function getYuyueOrderList(params: YuyueOrderListQuery) {
  return request.get<unknown, YuyueOrderListResult>('/admin/yuyue/order/list', { params })
}

export function verifyYuyueOrder(id: number, hexiaoCode: string) {
  return request.post<unknown, null>('/admin/yuyue/order/verify', {
    id,
    hexiao_code: hexiaoCode,
  })
}

// 查看进度（时间线）
export interface YuyueOrderProgressNode {
  time: string
  context: string
}
export interface YuyueOrderProgressResult {
  list: YuyueOrderProgressNode[]
  has_worker_order: boolean
}
export function getYuyueOrderProgress(id: number) {
  return request.get<unknown, YuyueOrderProgressResult>('/admin/yuyue/order/progress', { params: { id } })
}

// 订单详情
export function getYuyueOrderDetail(id: number) {
  return request.get<unknown, { order: Record<string, any>; member: Record<string, any>; worker: Record<string, any> }>(
    '/admin/yuyue/order/detail',
    { params: { id } },
  )
}

// 服务完成
export function finishYuyueOrder(id: number) {
  return request.post<unknown, null>('/admin/yuyue/order/finish', { id })
}

// 删除订单（软删）
export function deleteYuyueOrder(id: number) {
  return request.post<unknown, null>('/admin/yuyue/order/delete', { id })
}

// ============ 商品服务（任务222） ============
export function getYuyueFuwuList(params: YuyueFuwuListQuery) {
  return request.get<unknown, YuyueFuwuListResult>('/admin/yuyue/fuwu/list', { params })
}

export function saveYuyueFuwu(data: YuyueFuwuSaveParams) {
  return request.post<unknown, { id: number }>('/admin/yuyue/fuwu/save', data)
}

export function deleteYuyueFuwu(ids: number[]) {
  return request.post<unknown, null>('/admin/yuyue/fuwu/delete', { ids })
}

// ============ 商品评价（任务222） ============
export function getYuyueCommentList(params: YuyueCommentListQuery) {
  return request.get<unknown, YuyueCommentListResult>('/admin/yuyue/comment/list', { params })
}

export function getYuyueCommentDetail(id: number) {
  return request.get<unknown, Record<string, unknown>>('/admin/yuyue/comment/detail', { params: { id } })
}

export function setYuyueCommentStatus(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/yuyue/comment/setst', { ids, st })
}

export function replyYuyueComment(id: number, content: string) {
  return request.post<unknown, null>('/admin/yuyue/comment/reply', { id, content })
}

export function deleteYuyueComment(ids: number[]) {
  return request.post<unknown, null>('/admin/yuyue/comment/delete', { ids })
}

// ============ 人员类型（任务222） ============
export function getYuyueWorkerCategoryList(params?: { name?: string; status?: number | ''; bid?: string }) {
  return request.get<unknown, { list: YuyueWorkerCategoryItem[] }>('/admin/yuyue/workerCategory/list', { params })
}

export function saveYuyueWorkerCategory(data: YuyueWorkerCategorySaveParams) {
  return request.post<unknown, { id: number }>('/admin/yuyue/workerCategory/save', data)
}

export function setYuyueWorkerCategoryStatus(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/yuyue/workerCategory/setst', { ids, st })
}

export function deleteYuyueWorkerCategory(ids: number[]) {
  return request.post<unknown, null>('/admin/yuyue/workerCategory/delete', { ids })
}

// ============ 人员评价（任务222） ============
export function getYuyueWorkerCommentList(params: YuyueWorkerCommentListQuery) {
  return request.get<unknown, YuyueWorkerCommentListResult>('/admin/yuyue/workerComment/list', { params })
}

export function getYuyueWorkerCommentDetail(id: number) {
  return request.get<unknown, Record<string, unknown>>('/admin/yuyue/workerComment/detail', { params: { id } })
}

export function deleteYuyueWorkerComment(ids: number[]) {
  return request.post<unknown, null>('/admin/yuyue/workerComment/delete', { ids })
}

// ============ 提成明细（任务222） ============
export function getYuyueMoneyLogList(params: YuyueMoneyLogListQuery) {
  return request.get<unknown, YuyueMoneyLogListResult>('/admin/yuyue/moneylog/list', { params })
}

export function exportYuyueMoneyLog(params: YuyueMoneyLogListQuery) {
  return request.get<unknown, YuyueMoneyLogExportResult>('/admin/yuyue/moneylog/export', { params })
}

export function deleteYuyueMoneyLog(ids: number[]) {
  return request.post<unknown, null>('/admin/yuyue/moneylog/delete', { ids })
}

// ============ 提现记录（任务222） ============
export function getYuyueWithdrawLogList(params: YuyueWithdrawLogListQuery) {
  return request.get<unknown, YuyueWithdrawLogListResult>('/admin/yuyue/withdrawlog/list', { params })
}

export function auditYuyueWithdrawLog(id: number, pass: number, reason: string) {
  return request.post<unknown, null>('/admin/yuyue/withdrawlog/audit', { id, pass, reason })
}

export function payYuyueWithdrawLog(id: number, paynum?: string) {
  return request.post<unknown, null>('/admin/yuyue/withdrawlog/pay', { id, paynum })
}

export function deleteYuyueWithdrawLog(ids: number[]) {
  return request.post<unknown, null>('/admin/yuyue/withdrawlog/delete', { ids })
}

// ============ 系统设置（任务222） ============
export function getYuyueSetConfig() {
  return request.get<unknown, YuyueSetConfig>('/admin/yuyue/set/config')
}

export function saveYuyueSetConfig(data: YuyueSetSaveParams) {
  return request.post<unknown, null>('/admin/yuyue/set/save', data)
}
