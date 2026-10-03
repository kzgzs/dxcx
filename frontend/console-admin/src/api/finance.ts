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
  headimg: string
  bid: number
  bname: string
  refund_time: number
  check_status: number
  issettle: number
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
  bname?: string
  bid?: number
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
  headimg: string
  un: string
  createtime: number
  rechargeid: number
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
  tel?: string
  remark?: string
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
  headimg: string
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
  headimg: string
  realname: string
  usercard: string
  wx_transfer_bill_no: string
  wx_state: string
  can_audit: boolean
  can_reject: boolean
  can_mark_paid: boolean
  can_wx_pay: boolean
  can_delete: boolean
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
  headimg: string
  uid: number
  un: string
  createtime: number
  rechargeid: number
}

export interface CommissionQuery extends FinPageQuery {
  nickname?: string
  mid?: number
  fhtype?: string
  remark?: string
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
  headimg: string
  remark: string
  createtime: number
}

export interface HexiaoQuery extends FinPageQuery {
  keyword?: string
  type?: string
  mid?: string
  nickname?: string
  title?: string
  remark?: string
}

// ---- 7. 发票管理 ----
export interface InvoiceItem {
  id: number
  ordernum: string
  orderid: number
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
  bank_name: string
  bank_account: string
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
  ordernum?: string
  invoice_name?: string
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
  from_headimg: string
  levelid: number
  level_text: string
  can_view_order: boolean
  can_delete: boolean
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
  send_score: number
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

// ============ 任务对齐点大：批量删除 / 导出 / 微信转账 / 授权 / 订单详情（P1 后端+API 契约层） ============

/** 删除入参（兼容单 id 与 ids[]） */
export interface DeleteIds {
  id?: number
  ids?: number[]
}

// ---- 5. 微信转账记录（对齐点大 Money::wx_transfer_log，表 wx_transfer_log） ----
export interface WxTransferLogItem {
  id: number
  mid: number
  nickname: string
  headimg: string
  money: number
  ordernum: string
  desc: string
  createtime: number
  state: string
  state_str: string
  transfer_bill_no: string
  message: string
  status_code: string
  data_tbl: string
  data_id: number
}

export interface WxTransferLogQuery extends FinPageQuery {
  mid?: number
  state?: string
}

export type WxTransferLogListResult = FinPageResult<WxTransferLogItem> & { stat: { count: number } }

// ---- 6. 微信转账授权记录（新页；通道未接入，返回空骨架） ----
export interface WxTransferAuthItem {
  id: number
  mid: number
  nickname: string
  headimg: string
  appid: string
  platform: string
  ordernum: string
  createtime: number
  confirm_time: number
  status: string
  status_text: string
  transfer_bill_no: string
  message: string
}

export type WxTransferAuthListResult = FinPageResult<WxTransferAuthItem> & {
  available: boolean
  stat: { count: number }
  note: string
}

/** 佣金记录 查看订单 详情 */
export interface CommissionRecordOrderDetail {
  type: string
  orderid: number
  ordernum: string
  orderstatus: number
  money?: number
  createtime?: number
}

// ---- 批量删除 ----
export function deletePayorder(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/payorder/delete', data)
}

// 抖音（头条）小程序分账（批量 ids[]，paytypeid=12）
export function payorderDatasettle(ids: number[]) {
  return request.post<unknown, { success: number }>('/admin/finance/payorder/datasettle', { ids })
}

// 抖音（头条）小程序订单核销/推送（批量 ids[]，paytypeid=12）
export function payorderDatapushorder(ids: number[]) {
  return request.post<unknown, { success: number }>('/admin/finance/payorder/datapushorder', { ids })
}
export function deleteMoneyLog(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/moneylog/delete', data)
}
export function deleteRecharge(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/recharge/delete', data)
}
export function deleteWithdraw(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/withdraw/delete', data)
}
export function deleteCommissionLog(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/commissionLog/delete', data)
}
export function deleteCommissionWithdraw(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/commissionWithdraw/delete', data)
}
export function deleteCommissionRecord(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/commissionRecord/delete', data)
}

// ---- 各日志 EXCEL 导出（返回 blob） ----
export function exportPayorder(params: PayorderListQuery) {
  return request.get('/admin/finance/payorder/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}
export function exportMoneyLog(params: MoneyLogQuery) {
  return request.get('/admin/finance/moneylog/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}
export function exportRecharge(params: RechargeQuery) {
  return request.get('/admin/finance/recharge/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}
export function exportWithdraw(params: WithdrawQuery) {
  return request.get('/admin/finance/withdraw/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}
export function exportCommissionLog(params: CommissionQuery) {
  return request.get('/admin/finance/commissionLog/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}
export function exportCommissionWithdraw(params: WithdrawQuery) {
  return request.get('/admin/finance/commissionWithdraw/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}

// ---- 微信转账记录 / 授权记录 ----
export function getWxTransferLogList(params: WxTransferLogQuery) {
  return request.get<unknown, WxTransferLogListResult>('/admin/finance/wxTransferLog/list', { params })
}
export function exportWxTransferLog(params: WxTransferLogQuery) {
  return request.get('/admin/finance/wxTransferLog/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}
export function getWxTransferAuthList(params: FinPageQuery) {
  return request.get<unknown, WxTransferAuthListResult>('/admin/finance/wxTransferAuth/list', { params })
}
export function queryWxTransferAuth(data: { id?: number }) {
  return request.post<unknown, { available: boolean; msg: string }>('/admin/finance/wxTransferAuth/query', data)
}

// 微信转账撤销（对齐点大 wx_transfer_cancel）
export function cancelWxTransfer(id: number) {
  return request.post<unknown, { id: number; state: string }>('/admin/finance/wxTransferLog/cancel', { id })
}

// ---- 佣金记录 查看订单 详情 ----
export function getCommissionRecordOrderDetail(params: { type: string; orderid: number }) {
  return request.get<unknown, CommissionRecordOrderDetail>('/admin/finance/commissionRecord/orderDetail', { params })
}

// ============ 片2：分红/核销/计次核销/发票 补删除·导出·详情（对齐点大） ============

export function deleteFenhongLog(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/fenhonglog/delete', data)
}

export function exportHexiao(params: HexiaoQuery) {
  return request.get('/admin/finance/hexiao/export', { params, responseType: 'blob', timeout: 180000 }) as Promise<Blob>
}

export function deleteHexiao(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/hexiao/delete', data)
}

export function deleteHexiaoShopproduct(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/hexiaoShopproduct/delete', data)
}

export function deleteInvoice(data: DeleteIds) {
  return request.post<unknown, { deleted: number }>('/admin/finance/invoice/delete', data)
}

export function getInvoiceDetail(params: { id: number }) {
  return request.get<unknown, InvoiceItem>('/admin/finance/invoice/detail', { params })
}