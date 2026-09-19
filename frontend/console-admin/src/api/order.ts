import request from './request'
import type {
  BatchDeleteResult,
  BatchShipResult,
  BatchShipSelectedParams,
  OrderItem,
  OrderListQuery,
  OrderListResult,
  OrderShipParams,
  OrderStat,
  ShdSetData,
} from './types'

// 订单列表
export function getOrderList(params: OrderListQuery) {
  return request.get<unknown, OrderListResult>('/admin/order/list', { params })
}

// 订单详情（全量）
export function getOrderDetail(id: number) {
  return request.get<unknown, OrderItem>('/admin/order/detail', {
    params: { id },
  })
}

// 发货
export function shipOrder(data: OrderShipParams) {
  return request.post<unknown, null>('/admin/order/ship', data)
}

// 订单统计
export function getOrderStat() {
  return request.get<unknown, OrderStat>('/admin/order/stat')
}

// ============ 订单批量操作（P17-2） ============

// Excel 批量发货（multipart：file + express_com；表格第一列订单号、第二列运单号）
export function batchShipByExcel(formData: FormData) {
  return request.post<unknown, BatchShipResult>('/admin/order/batchShip', formData, {
    timeout: 120000,
  })
}

// 选中项批量发货（同一快递+运单号发多单）
export function batchShipSelected(data: BatchShipSelectedParams) {
  return request.post<unknown, BatchShipResult>('/admin/order/batchShipSelected', data, {
    timeout: 120000,
  })
}

// 批量删除（先回滚库存/券/积分，再级联删除）
export function batchDeleteOrders(ids: number[]) {
  return request.post<unknown, BatchDeleteResult>('/admin/order/batchDelete', { ids })
}

// 设置商家备注
export function setOrderRemark(data: { id: number; remark: string }) {
  return request.post<unknown, null>('/admin/order/remark', data)
}

// 后台改价（仅未支付订单）
export function changeOrderPrice(data: { id: number; price: number }) {
  return request.post<unknown, null>('/admin/order/changePrice', data)
}

// 后台关闭订单（仅未支付，回补库存/券/积分）
export function closeOrder(id: number) {
  return request.post<unknown, null>('/admin/order/close', { id })
}

// 配送方式选项（订单筛选下拉）
export function getFreightOptions() {
  return request.get<unknown, { id: number; name: string }[]>('/admin/order/freightOptions')
}

// 商户下拉选项（商户订单视图筛选，对照参考系统 ShopOrder showtype=2 的 business_list）
export function getOrderBusinessOptions() {
  return request.get<unknown, { id: number; name: string }[]>('/admin/order/businessOptions')
}

// 订单导出（选中 ids 优先，否则按筛选条件；返回 xlsx Blob）
export function exportOrders(params: OrderListQuery & { ids?: string }) {
  return request.get('/admin/order/export', {
    params,
    responseType: 'blob',
    timeout: 180000,
  }) as Promise<Blob>
}

// ============ 送货单 ============

// 送货单设置读取
export function getShdSet() {
  return request.get<unknown, ShdSetData>('/admin/shd/set')
}

// 送货单设置保存
export function saveShdSet(data: ShdSetData) {
  return request.post<unknown, null>('/admin/shd/set', data)
}

// 送货单打印页（返回 HTML Blob，浏览器 @media print 方案）
export function getShdPrintHtml(ids: number[]) {
  return request.get('/admin/shd/print', {
    params: { ids: ids.join(',') },
    responseType: 'blob',
    timeout: 120000,
  }) as Promise<Blob>
}
