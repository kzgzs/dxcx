import request from './request'
import type {
  CycleSyssetData,
  CycleSyssetSaveParams,
  CycleCategoryItem,
  CycleCategorySaveParams,
  CycleProductListQuery,
  CycleProductListResult,
  CycleProductDetail,
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
  // 片A 新增字段（对齐截图/canzhao）
  market_price?: number
  weight?: number
  everyday_item?: string
  advance_pay_days?: number
  advance_pay_time?: number
  advance_extend_days?: number
  min_num?: number
  min_qsnum?: number
  freighttype?: number
  freightdata?: string
  commissionset?: number
  guigedata?: string
}

// 规格项目表行（cycle_guige）
export interface CycleGuigeItem {
  name: string
  procode: string
  market_price: number
  sell_price: number
  weight: number
  stock: number
  pic: string
}

// 保存入参扩展（对齐截图两部分字段）
export interface CycleProductSavePayload {
  id?: number
  name: string
  pic?: string
  pics?: string
  cid?: number
  // 第一部分
  commissionset?: number
  // 第二部分
  ps_cycle?: number
  everyday_item?: number[]
  advance_pay_days?: number
  advance_pay_time?: number
  advance_extend_days?: number
  min_num?: number
  min_qsnum?: number
  freighttype?: number
  freightdata?: string
  sales?: number
  sort?: number
  status?: number
  detail?: string
  guigedata?: string
  guiges: CycleGuigeItem[]
}

/* ================= 周期购设置 ================= */

// 读取周期购设置（自动收货天数/商品评价/评价审核/会员等级折扣）
export function getCycleSysset() {
  return request.get<unknown, CycleSyssetData>('/admin/cycleSet/get')
}

// 保存周期购设置
export function saveCycleSysset(data: CycleSyssetSaveParams) {
  return request.post<unknown, null>('/admin/cycleSet/save', data)
}

// 分享海报：进页面幂等确保 8 平台默认模板行已生成（对齐参考系统首次加载播种）
export function ensureCyclePoster() {
  return request.get<unknown, { platforms: string[] }>('/admin/cyclePoster/index')
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

/* ================= 片B：配送管理 / 周期计划 ================= */

/** 分期配送列表（配送三视图 + 订单「周期计划」平铺页共用） */
export function cycleStageList(params: {
  page?: number
  pagesize?: number
  status?: string | number
  orderid?: number
  ordernum?: string
  linkman?: string
  tel?: string
  start_date?: string
  end_date?: string
}) {
  return request.get<unknown, { list: any[]; total: number }>('/admin/cycle/order/stageList', { params })
}

/** 核销（到店自提：当期 → 已完成） */
export function cycleOrderHexiao(stageid: number) {
  return request.post<unknown, null>('/admin/cycle/order/hexiao', { stageid })
}

/** 送货单数据（按 stage 生成，前端平铺打印） */
export function cycleOrderShd(stageid: number) {
  return request.get<unknown, any>('/admin/cycle/order/shd', { params: { stageid } })
}

/** 查物流 / 配送信息 */
export function cycleOrderExpress(stageid: number) {
  return request.get<unknown, any>('/admin/cycle/order/express', { params: { stageid } })
}

/** 删除订单（软删） */
export function cycleOrderDelete(id: number) {
  return request.post<unknown, null>('/admin/cycle/order/delete', { id })
}

/** 完成（整单收口） */
export function cycleOrderFinish(id: number) {
  return request.post<unknown, null>('/admin/cycle/order/finish', { id })
}

/** 订单导出 CSV（blob 下载） */
export function cycleOrderExport(params: Record<string, any>) {
  return request.get('/admin/cycle/order/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}
