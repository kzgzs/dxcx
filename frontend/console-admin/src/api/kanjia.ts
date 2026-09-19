import request from './request'
import type {
  KanjiaSyssetData,
  KanjiaSyssetSaveParams,
  KanjiaProductListQuery,
  KanjiaJoinListQuery,
  KanjiaJoinListResult,
  KanjiaHelpItem,
  KanjiaOrderListQuery,
  KanjiaOrderListResult,
  KanjiaCodelistListQuery,
  KanjiaCodelistListResult,
} from './types'

/* ================= 砍价设置 ================= */

// 读取砍价设置（横幅/自动收货天数/进度显示/必须支付）
export function getKanjiaSysset() {
  return request.get<unknown, KanjiaSyssetData>('/admin/kanjia/sysset')
}

// 保存砍价设置
export function saveKanjiaSysset(data: KanjiaSyssetSaveParams) {
  return request.post<unknown, null>('/admin/kanjia/sysset', data)
}

/* ================= 砍价商品 ================= */

/** 分段砍价区间行 */
export interface KanjiaProductKjRow {
  startnum: number
  endnum: number | string
  startmoney: number
  endmoney: number
}

/** 砍价商品列表行（商户视图含 bname / 审核 / 活动时间） */
export interface KanjiaProductRow {
  id: number
  name: string
  pic: string
  pics: string
  sell_price: number
  min_price: number
  stock: number
  sales: number
  saleing: number
  helpnum: number
  limitHour: number
  directbuy: number
  sort: number
  status: number
  detail: string
  starttime: number
  endtime: number
  sellpoint: string
  ischecked: number
  check_reason: string
  bname: string
  bid: number
  kjdata: string
}

export interface KanjiaProductListResultEx {
  list: KanjiaProductRow[]
  total: number
  page: number
  pagesize: number
}

export interface KanjiaProductListQueryEx extends KanjiaProductListQuery {
  bid?: string
  checked?: number | ''
  orderField?: string
  orderType?: string
}

export interface KanjiaProductDetailResult extends KanjiaProductRow {
  kjdata_list: KanjiaProductKjRow[]
}

export interface KanjiaProductSaveData {
  id?: number
  name: string
  pic: string
  pics?: string
  sell_price: number
  min_price: number
  stock: number
  helpnum?: number
  limitHour?: number
  directbuy?: number
  starttime?: number | string
  endtime?: number | string
  sort?: number
  status?: number
  detail?: string
  sellpoint?: string
  kjdata?: KanjiaProductKjRow[]
}

// 商品列表
export function getKanjiaProductList(params: KanjiaProductListQueryEx) {
  return request.get<unknown, KanjiaProductListResultEx>('/admin/kanjia/product/list', {
    params,
  })
}

// 商品详情（含分段砍价区间 kjdata_list）
export function getKanjiaProductDetail(id: number) {
  return request.get<unknown, KanjiaProductDetailResult>('/admin/kanjia/product/detail', {
    params: { id },
  })
}

// 新增/编辑商品
export function saveKanjiaProduct(data: KanjiaProductSaveData) {
  return request.post<unknown, { id: number }>('/admin/kanjia/product/save', data)
}

// 删除商品（单 id 或批量 ids[]；已有发起记录/订单时后端拒绝）
export function deleteKanjiaProduct(id: number | number[]) {
  const payload = Array.isArray(id) ? { ids: id } : { id }
  return request.post<unknown, null>('/admin/kanjia/product/del', payload)
}

// 商品上架/下架（单 id 或批量 ids[]）
export function setKanjiaProductStatus(id: number | number[], status: number) {
  const payload = Array.isArray(id) ? { ids: id, status } : { id, status }
  return request.post<unknown, null>('/admin/kanjia/product/status', payload)
}

// 商品审核（ischecked 1通过 2驳回；驳回须填原因）
export function checkKanjiaProduct(id: number, ischecked: number, reason = '') {
  return request.post<unknown, null>('/admin/kanjia/product/check', { id, ischecked, reason })
}

/* ================= 发起记录 ================= */

// 发起记录列表
export function getKanjiaJoinList(params: KanjiaJoinListQuery) {
  return request.get<unknown, KanjiaJoinListResult>('/admin/kanjia/join/list', {
    params,
  })
}

// 帮砍明细
export function getKanjiaHelpList(joinid: number) {
  return request.get<unknown, KanjiaHelpItem[]>('/admin/kanjia/join/help', {
    params: { joinid },
  })
}

// 删除发起记录（进行中可删）
export function deleteKanjiaJoin(id: number) {
  return request.post<unknown, null>('/admin/kanjia/join/del', { id })
}

/* ================= 砍价订单 ================= */

// 订单列表
export function getKanjiaOrderList(params: KanjiaOrderListQuery) {
  return request.get<unknown, KanjiaOrderListResult>('/admin/kanjia/order/list', {
    params,
  })
}

// 发货
export function shipKanjiaOrder(id: number, express_com: string, express_no: string) {
  return request.post<unknown, null>('/admin/kanjia/order/ship', { id, express_com, express_no })
}

// 退款审核（pass 1同意 2驳回）
export function checkKanjiaOrderRefund(id: number, pass: number, remark = '') {
  return request.post<unknown, null>('/admin/kanjia/order/refundCheck', { id, pass, remark })
}

// 关闭订单（仅未支付）
export function closeKanjiaOrder(id: number) {
  return request.post<unknown, null>('/admin/kanjia/order/close', { id })
}

// 订单备注
export function remarkKanjiaOrder(id: number, remark: string) {
  return request.post<unknown, null>('/admin/kanjia/order/remark', { id, remark })
}

/* ================= 核销码 ================= */

// 核销码列表
export function getKanjiaCodelist(params: KanjiaCodelistListQuery) {
  return request.get<unknown, KanjiaCodelistListResult>('/admin/kanjia/codelist/list', {
    params,
  })
}

// 核销（核销码/订单号 → 确认收货）
export function hexiaoKanjia(code: string) {
  return request.post<unknown, { ordernum: string; proname: string }>(
    '/admin/kanjia/codelist/hexiao',
    { code },
  )
}
