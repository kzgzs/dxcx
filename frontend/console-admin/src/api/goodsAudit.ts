import request from './request'

// ============ 多商户商品审核（商户频道） ============

/** ischecked：0=待审核 1=已通过 2=已驳回 */
export interface GoodsAuditItem {
  id: number
  bid: number
  /** 所属商户名 */
  bname: string
  name: string
  pic: string
  sell_price: number
  market_price: number
  stock: number
  /** 显示销量 */
  sales: number
  /** 实际销量 */
  realsalenum: number
  ischecked: number
  check_reason: string
  /** Unix 秒 */
  createtime: number
}

export interface GoodsAuditListResult {
  list: GoodsAuditItem[]
  total: number
}

export interface GoodsAuditQuery {
  page: number
  pagesize: number
  /** 商品ID精确 */
  goods_id?: number | ''
  /** 商品名称模糊 */
  name?: string
  /** 分类ID */
  cid?: number | ''
  /** 分组ID */
  gid?: number | ''
  /** 审核状态 0/1/2 */
  ischecked?: number | ''
  /** 所属商户ID */
  bid?: number | ''
  field?: string
  order?: string
}

export function getGoodsAuditList(params: GoodsAuditQuery) {
  return request.get<unknown, GoodsAuditListResult>('/admin/goodsAudit/list', { params })
}

/** 审核：st=1 通过，st=2 驳回（reason 必填） */
export function setGoodsAuditStatus(id: number, st: number, reason = '') {
  return request.post<unknown, null>('/admin/goodsAudit/setcheckst', { id, st, reason })
}
