import request from './request'

// ============ 类型定义（财务中心八页） ============

/** 分页列表通用返回 */
export interface FinPageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

/** 分页查询通用参数 */
export interface FinPageQuery {
  page?: number
  pagesize?: number
  start_date?: string
  end_date?: string
}

// ---- 1. 消费明细（支付单） ----
export interface PayorderItem {
  id: number
  ordernum: string
  title: string
  money: number
  paytype: string
  paytypeid: number
  paytype_text: string
  paynum: string
  paytime: number
  type: string
  type_text: string
  status: number
  refund_money: number
  createtime: number
  mid: number
  nickname: string
  tel: string
}

export interface PayorderStat {
  total_received: number
  total_refund: number
  total_cost: number
}

export interface PayorderListQuery extends FinPageQuery {
  status?: string
  paytypeid?: number
  nickname?: string
  mid?: number
  tel?: string
  ordernum?: string
}

export type PayorderListResult = FinPageResult<PayorderItem> & { stat: PayorderStat }

// ---- 2. 余额明细 ----
export interface MoneyLogItem {
  id: number
  mid: number
  nickname: string
  tel: string
  money: number
  after: number
  remark: string
  paytype: string
  uid: number
  createtime: number
}

export interface MoneyLogStat {
  income: number
  expense: number
  net: number
}

export interface MoneyLogQuery extends FinPageQuery {
  nickname?: string
  mid?: number
  inout?: number
}

export type MoneyLogListResult = FinPageResult<MoneyLogItem> & { stat: MoneyLogStat }

// ---- 3. 充值记录 ----
export interface RechargeItem {
  id: number
  ordernum: string
  money: number
  status: number
  paytype: string
  paytypeid: number
  paytype_text: string
  paynum: string
  paytime: number
  createtime: number
  mid: number
  nickname: string
  tel: string
}

export interface RechargeStat {
  paid_total: number
  paid_count: number
  refund_count: number
}

export interface RechargeQuery extends FinPageQuery {
  nickname?: string
  mid?: number
  status?: string
  ordernum?: string
}

export type RechargeListResult = FinPageResult<RechargeItem> & { stat: RechargeStat }

// ---- 4. 余额提现 / 5b. 佣金提现 ----
export interface WithdrawItem {
  id: number
  mid: number
  nickname: string
  tel: string
  ordernum: string
  money: number
  txmoney: number
  paytype: string
  aliaccount: string
  aliaccountname: string
  bankname: string
  bankcarduser: string
  bankcardnum: string
  status: number
  status_text: string
  reason: string
  createtime: number
  paytime: number
  paynum: string
}

export interface WithdrawStat {
  pending: number
  approved: number
  rejected: number
  paid: number
  pending_amount: number
}

export interface WithdrawQuery extends FinPageQuery {
  nickname?: string
  mid?: number
  status?: string
  paytype?: string
}

export type WithdrawListResult = FinPageResult<WithdrawItem> & { stat: WithdrawStat }

// ---- 5a. 佣金明细 ----
export interface CommissionItem {
  id: number
  mid: number
  nickname: string
  tel: string
  commission: number
  after: number
  fhtype: string
  frommid: number
  from_nickname: string
  remark: string
  createtime: number
}

export interface CommissionQuery extends FinPageQuery {
  nickname?: string
  mid?: number
  fhtype?: string
}

export type CommissionListResult = FinPageResult<CommissionItem> & { stat: MoneyLogStat }

// ---- 6. 核销记录 ----
export interface HexiaoItem {
  id: number
  ordernum: string
  title: string
  type: string
  type_text: string
  mid: string
  nickname: string
  tel: string
  uid: number
  mdid: number
  mendian_name: string
  remark: string
  createtime: number
}

export interface HexiaoQuery extends FinPageQuery {
  keyword?: string
  type?: string
}

// ---- 7. 发票管理 ----
export interface InvoiceItem {
  id: number
  ordernum: string
  order_type: string
  type: number
  type_text: string
  invoice_name: string
  name_type: number
  tax_no: string
  address: string
  tel: string
  mobile: string
  email: string
  status: number
  check_remark: string
  create_time: number
  check_time: number
  pay_money: number
}

export interface InvoiceStat {
  pending: number
  passed: number
  rejected: number
}

export interface InvoiceQuery extends FinPageQuery {
  status?: string
  keyword?: string
}

export type InvoiceListResult = FinPageResult<InvoiceItem> & { stat: InvoiceStat }

// ---- 8. 微信支付流水 ----
export interface WxpayLogFinItem {
  id: number
  ordernum: string
  mch_id: string
  sub_mchid: string
  transaction_id: string
  total_fee: number
  fenzhangmoney: number
  isfenzhang: number
  fz_ordernum: string
  fz_errmsg: string
  refund_money: number
  createtime: number
  mid: number
  nickname: string
}

export interface WxpayLogFinQuery extends FinPageQuery {
  ordernum?: string
  transaction_id?: string
  isfenzhang?: string
}

export type WxpayLogFinListResult = FinPageResult<WxpayLogFinItem> & {
  stat: { count: number; total: number }
}

// ============ 接口 ============

// 1. 消费明细（支付单列表 + 顶部统计）
export function getPayorderList(params: PayorderListQuery) {
  return request.get<unknown, PayorderListResult>('/admin/finance/payorder/list', { params })
}

// 2. 余额明细
export function getMoneyLogList(params: MoneyLogQuery) {
  return request.get<unknown, MoneyLogListResult>('/admin/finance/moneylog/list', { params })
}

// 3. 充值记录
export function getRechargeList(params: RechargeQuery) {
  return request.get<unknown, RechargeListResult>('/admin/finance/recharge/list', { params })
}

// 3b. 充值退款（事务扣回余额）
export function refundRecharge(id: number) {
  return request.post<unknown, { money: number }>('/admin/finance/recharge/refund', { id })
}

// 4. 余额提现列表
export function getWithdrawList(params: WithdrawQuery) {
  return request.get<unknown, WithdrawListResult>('/admin/finance/withdraw/list', { params })
}

// 4b. 提现审核（pass 1通过 2驳回；驳回返还余额）
export function auditWithdraw(data: { id: number; pass: number; reason?: string }) {
  return request.post<unknown, null>('/admin/finance/withdraw/audit', data)
}

// 4c. 提现打款（mock 即时成功）
export function payWithdraw(id: number) {
  return request.post<unknown, { mock: boolean; status: number }>('/admin/finance/withdraw/pay', { id })
}

// 5a. 佣金明细
export function getCommissionList(params: CommissionQuery) {
  return request.get<unknown, CommissionListResult>('/admin/finance/commission/list', { params })
}

// 5b. 佣金提现列表
export function getCommissionWithdrawList(params: WithdrawQuery) {
  return request.get<unknown, WithdrawListResult>('/admin/finance/commission/withdraw', { params })
}

// 5c. 佣金提现审核（驳回返还佣金）
export function auditCommissionWithdraw(data: { id: number; pass: number; reason?: string }) {
  return request.post<unknown, null>('/admin/finance/commission/withdrawAudit', data)
}

// 6. 核销记录
export function getHexiaoList(params: HexiaoQuery) {
  return request.get<unknown, FinPageResult<HexiaoItem>>('/admin/finance/hexiao/list', { params })
}

// 7. 发票列表
export function getInvoiceList(params: InvoiceQuery) {
  return request.get<unknown, InvoiceListResult>('/admin/finance/invoice/list', { params })
}

// 7b. 发票批量审核
export function auditInvoice(data: { ids: number[]; pass: number; remark?: string }) {
  return request.post<unknown, { count: number }>('/admin/finance/invoice/audit', data)
}

// 7c. 发票回复（写审核备注+时间）
export function replyInvoice(data: { id: number; remark: string }) {
  return request.post<unknown, null>('/admin/finance/invoice/reply', data)
}

// 8. 微信支付流水
export function getFinWxpayLogList(params: WxpayLogFinQuery) {
  return request.get<unknown, WxpayLogFinListResult>('/admin/finance/wxpaylog/list', { params })
}

// ============ 9/10/11. 佣金记录 / 分红记录 / 计次核销记录（任务177） ============

/** 佣金记录行（member_commission_record，按 type 回查订单取状态单号） */
export interface CommissionRecordItem {
  id: number
  mid: number
  nickname: string
  headimg: string
  frommid: number
  from_nickname: string
  type: string
  commission: number
  score: number
  orderid: number
  ordernum: string
  orderstatus: number
  status: number
  status_text: string
  remark: string
  createtime: number
  endtime: number
}

export interface CommissionRecordStat {
  count: number
  pending: number
  paid: number
  backed: number
  total_commission: number
}

export interface CommissionRecordQuery extends FinPageQuery {
  nickname?: string
  mid?: number
  status?: string
  orderid?: string
  type?: string
}

export type CommissionRecordListResult = FinPageResult<CommissionRecordItem> & {
  stat: CommissionRecordStat
}

/** 分红记录行（member_fenhonglog，按 module+ogids 回查订单） */
export interface FenhongLogItem {
  id: number
  mid: number
  nickname: string
  headimg: string
  commission: number
  send_commission: number
  send_money: number
  type: string
  module: string
  ogids: string
  ordernum: string
  orderstatus: number
  status: number
  status_text: string
  remark: string
  createtime: number
}

export interface FenhongLogQuery extends FinPageQuery {
  nickname?: string
  mid?: number
  status?: string
  remark?: string
  ordernum?: string
  order_type?: string
}

export type FenhongLogListResult = FinPageResult<FenhongLogItem> & {
  stat: CommissionRecordStat
}

/** 计次核销记录行（hexiao_shopproduct） */
export interface HexiaoShopproductItem {
  id: number
  bid: number
  bname: string
  uid: number
  mid: number
  nickname: string
  headimg: string
  ordernum: string
  title: string
  pic: string
  num: number
  status: number
  remark: string
  delivery_time: number
  delivery_remark: string
  createtime: number
}

export interface HexiaoShopproductStat {
  count: number
  total_num: number
}

export interface HexiaoShopproductQuery extends FinPageQuery {
  nickname?: string
  title?: string
  mid?: number
  status?: string
  bid?: string
}

export type HexiaoShopproductListResult = FinPageResult<HexiaoShopproductItem> & {
  stat: HexiaoShopproductStat
}

// 9. 佣金记录列表 / 导出
export function getCommissionRecordList(params: CommissionRecordQuery) {
  return request.get<unknown, CommissionRecordListResult>('/admin/finance/commissionRecord/list', { params })
}

export function exportCommissionRecord(params: CommissionRecordQuery) {
  return request.get('/admin/finance/commissionRecord/export', {
    params,
    responseType: 'blob',
    timeout: 180000,
  }) as Promise<Blob>
}

// 9.1 佣金记录手动结算（任务182：对照参考系统 jiesuan 手动按钮）
export interface CommissionRecordSettleResult {
  settled: number
  void: number
  skipped: number
}

export function settleCommissionRecord(ids: number[]) {
  return request.post<unknown, CommissionRecordSettleResult>('/admin/finance/commissionRecord/settle', { ids })
}

// 10. 分红记录列表 / 导出
export function getFenhongLogList(params: FenhongLogQuery) {
  return request.get<unknown, FenhongLogListResult>('/admin/finance/fenhonglog/list', { params })
}

export function exportFenhongLog(params: FenhongLogQuery) {
  return request.get('/admin/finance/fenhonglog/export', {
    params,
    responseType: 'blob',
    timeout: 180000,
  }) as Promise<Blob>
}

// 11. 计次核销记录列表 / 导出
export function getHexiaoShopproductList(params: HexiaoShopproductQuery) {
  return request.get<unknown, HexiaoShopproductListResult>('/admin/finance/hexiaoShopproduct/list', { params })
}

export function exportHexiaoShopproduct(params: HexiaoShopproductQuery) {
  return request.get('/admin/finance/hexiaoShopproduct/export', {
    params,
    responseType: 'blob',
    timeout: 180000,
  }) as Promise<Blob>
}
