import request from './request'

// ============ 物流面单组（批次8 任务224）类型与接口 ============
// 物流助手（miandan）/ 电子面单记录（ewaybill）/ 面单设置（ewaybillSet）
// 快递鸟全链路 EWAYBILL_MOCK=1 时返回固定面单号 + 占位打印模板

interface PageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
  is_mock?: boolean
}

/* ================= 物流助手 ================= */

/** 物流助手运单（miandan_order） */
export interface MiandanOrder {
  id: number
  aid: number
  order_id: string
  ordertype: string
  openid: string
  biz_id: string
  delivery_id: string
  delivery_name: string
  service_type: number
  service_name: string
  expect_time: string
  custom_remark: string
  receiver_name: string
  receiver_mobile: string
  receiver_tel: string
  receiver_province: string
  receiver_city: string
  receiver_area: string
  receiver_address: string
  sender_name: string
  sender_mobile: string
  cargo_detail_list: string
  shop_goods_name: string
  status: number
  status_name: string
  waybill_id: string
  createtime: number
  createtime_text: string
}

/** 物流助手设置（miandan_set） */
export interface MiandanSet {
  id: number
  aid: number
  status: number
  query_status: number
  /** 0二联单 1一联单 */
  print_type: number
  is_mock?: boolean
}

/** 快递公司下拉选项 */
export interface DeliveryOption {
  delivery_id: string
  delivery_name: string
}

/** 物流账号（miandan_account） */
export interface MiandanAccount {
  id: number
  biz_id: string
  delivery_id: string
  delivery_name: string
  status_code: number
  status_name: string
  password: string
  remark_content: string
  quota_num: number
  create_time: number
  create_time_text: string
}

/** 打印员（miandan_printer） */
export interface MiandanPrinter {
  id: number
  openid: string
  nickname: string
  headimg: string
  createtime: number
}

/** 运单轨迹步骤 */
export interface PathStep {
  time: string
  text: string
}

/** 运单轨迹结果 */
export interface PathResult {
  delivery_id: string
  delivery_name: string
  waybill_id: string
  state: number
  state_text: string
  steps: PathStep[]
  is_mock?: boolean
}

/** 查询运单（打印数据）结果 */
export interface MiandanOrderPrint {
  order_status: number
  waybill_id: string
  print_html: string
  print_type: number
  is_mock?: boolean
}

export interface MiandanListQuery {
  page?: number
  pagesize?: number
  order_id?: string
  waybill_id?: string
  name?: string
  tel?: string
  status?: string
  starttime?: string
  endtime?: string
  sort_field?: string
  sort_order?: string
}

export function getMiandanList(params: MiandanListQuery) {
  return request.get<unknown, PageResult<MiandanOrder>>('/admin/miandan/list', { params })
}

export function getMiandanOrder(data: { order_id: string; delivery_id: string; waybill_id: string }) {
  return request.post<unknown, MiandanOrderPrint>('/admin/miandan/getOrder', data)
}

export function getMiandanPath(data: { order_id: string; delivery_id: string; waybill_id: string }) {
  return request.post<unknown, PathResult>('/admin/miandan/getPath', data)
}

export function cancelMiandan(data: { order_id: string; delivery_id: string; waybill_id: string }) {
  return request.post<unknown, null>('/admin/miandan/cancel', data)
}

export function deleteMiandan(ids: number[]) {
  return request.post<unknown, null>('/admin/miandan/del', { ids })
}

export function getDeliveryOptions() {
  return request.get<unknown, { list: DeliveryOption[]; is_mock?: boolean }>('/admin/miandan/deliveryOptions')
}

export function getAccountList() {
  return request.get<unknown, { list: MiandanAccount[]; total: number; is_mock?: boolean }>('/admin/miandan/accountList')
}

export function bindMiandanAccount(data: { delivery_id: string; biz_id: string; password?: string; remark_content?: string }) {
  return request.post<unknown, null>('/admin/miandan/accountBind', data)
}

export function unbindMiandanAccount(data: { biz_id: string; delivery_id: string }) {
  return request.post<unknown, null>('/admin/miandan/accountUnbind', data)
}

export function getPrinterList() {
  return request.get<unknown, { list: MiandanPrinter[]; total: number; is_mock?: boolean }>('/admin/miandan/printerList')
}

export function addPrinter(data: { openid: string; nickname?: string }) {
  return request.post<unknown, null>('/admin/miandan/printerAdd', data)
}

export function delPrinter(data: { openid: string }) {
  return request.post<unknown, null>('/admin/miandan/printerDel', data)
}

export function getMiandanSet() {
  return request.get<unknown, MiandanSet>('/admin/miandan/set')
}

export function saveMiandanSet(data: Partial<MiandanSet>) {
  return request.post<unknown, null>('/admin/miandan/set', data)
}

/* ================= 电子面单记录 ================= */

/** 电子面单（ewaybill_order，列表/详情口径） */
export interface EwaybillOrder {
  id: number
  aid: number
  orderid: number
  ordertype: string
  ordertype_name: string
  ordernum: string
  order_code: string
  express_id: number
  shipper_code: string
  exp_type: number
  pay_type: number
  paytype_text: string
  quantity: number
  weight: number
  volume: number
  insure: number
  insure_value: number
  warm_layer: string
  warm_layer_text?: string
  remark: string
  receiver_name: string
  receiver_mobile: string
  receiver_tel: string
  receiver_full_address: string
  sender_name: string
  sender_mobile: string
  sender_tel: string
  sender_full_address: string
  express_com: string
  express_no: string
  sub_express_no: string
  goods_detail: string
  status: number
  status_name: string
  expect_start_time_text: string
  expect_end_time_text: string
  createtime: number
  createtime_text: string
}

/** 创建面单前置数据 */
export interface EwaybillPrepare {
  ordercode: string
  express_options: { code: string; name: string; iswarm: number }[]
  warm_layer: Record<string, string>
  last_order: Partial<EwaybillOrder> | Record<string, unknown>
  is_mock?: boolean
}

/** 创建面单入参（对标参考系统 addorder 表单） */
export interface EwaybillAddParams {
  delivery_info: string
  ordercode?: string
  ordertype?: string
  orderid?: number
  ordernum?: string
  quantity: number
  weight: number
  volume: number
  insure?: number
  insure_value?: number
  warm_layer?: string
  remark?: string
  is_notice?: number
  expect_start_time?: string
  expect_end_time?: string
  receiver_name: string
  receiver_mobile?: string
  receiver_tel?: string
  receiver_province: string
  receiver_city: string
  receiver_area: string
  receiver_address: string
  receiver_company?: string
  receiver_post_code?: string
  sender_name: string
  sender_mobile?: string
  sender_tel?: string
  sender_province: string
  sender_city: string
  sender_area: string
  sender_address: string
  sender_company?: string
  sender_post_code?: string
}

export interface EwaybillListQuery {
  page?: number
  pagesize?: number
  ordernum?: string
  ordertype?: string
  name?: string
  status?: string
}

export function getEwaybillList(params: EwaybillListQuery) {
  return request.get<unknown, PageResult<EwaybillOrder>>('/admin/ewaybill/list', { params })
}

export function getEwaybillDetail(id: number) {
  return request.get<unknown, EwaybillOrder>('/admin/ewaybill/detail', { params: { id } })
}

export function prepareEwaybill(params: { ordertype?: string; orderid?: number }) {
  return request.get<unknown, EwaybillPrepare>('/admin/ewaybill/prepare', { params })
}

export function addEwaybill(data: EwaybillAddParams) {
  return request.post<unknown, { id: number; express_no: string; order_code: string; is_mock?: boolean }>(
    '/admin/ewaybill/add',
    data,
  )
}

export function cancelEwaybill(id: number) {
  return request.post<unknown, null>('/admin/ewaybill/cancel', { id })
}

export function deleteEwaybill(ids: number[]) {
  return request.post<unknown, null>('/admin/ewaybill/del', { ids })
}

export function printEwaybill(ids: number[]) {
  return request.post<unknown, { list: string[] }>('/admin/ewaybill/print', { ids })
}

/* ================= 面单设置 ================= */

/** 面单设置（ewaybill_set） */
export interface EwaybillSet {
  id: number
  aid: number
  account_id: string
  account_secret: string
  print_remarks: number
  print_shop: number
  print_shop_content: string[]
  print_content_map?: Record<string, string>
  status: number
  is_mock?: boolean
}

/** 快递公司字典项 */
export interface ExpressDictItem {
  code: string
  name: string
  field: string[]
  customerMap: string[]
  expType: Record<string, string>
  cancel: number
  isYuyue: number
  isBind: number
}

/** 快递公司账号（ewaybill_express_account） */
export interface ExpressAccount {
  id: number
  aid: number
  express: string
  express_text: string
  express_code: string
  customer_name: string
  customer_pwd: string
  month_code: string
  send_site: string
  send_staff: string
  paytype: number
  paytype_text: string
  createtime_text: string
  param1: string
  param2: string
  param3: string
  param4: string
  param5: string
}

export function getEwaybillSet() {
  return request.get<unknown, EwaybillSet>('/admin/ewaybillSet/index')
}

export function saveEwaybillSet(data: {
  status: number
  account_id: string
  account_secret: string
  print_remarks: number
  print_shop: number
  print_shop_content: string[]
}) {
  return request.post<unknown, null>('/admin/ewaybillSet/save', data)
}

export function getExpressDict() {
  return request.get<unknown, { list: ExpressDictItem[]; paytype: Record<string, string> }>(
    '/admin/ewaybillSet/expressDict',
  )
}

export function getExpressAccountList(params: { page?: number; pagesize?: number }) {
  return request.get<unknown, PageResult<ExpressAccount>>('/admin/ewaybillSet/accountList', { params })
}

export function saveExpressAccount(data: Record<string, unknown>) {
  return request.post<unknown, null>('/admin/ewaybillSet/accountSave', data)
}

export function deleteExpressAccount(ids: number[]) {
  return request.post<unknown, null>('/admin/ewaybillSet/accountDel', { ids })
}

/* ================= 面单记录：导出 / 通知取件（隐藏动作 ewaybill-excel / ewaybill-del） ================= */

/** 导出面单记录（xlsx Blob，与列表同口径筛选） */
export function exportEwaybill(params: EwaybillListQuery) {
  return request.get('/admin/ewaybill/excel', {
    params,
    responseType: 'blob',
    timeout: 180000,
  }) as Promise<Blob>
}

/** 通知取件候选快递公司（isYuyue=1 的已出单面单去重） */
export function getNoticeExpressList() {
  return request.get<unknown, { list: { name: string; code: string }[] }>('/admin/ewaybill/noticeInit')
}

/** 预约（通知）取件 */
export function yuyueNoticeEwaybill(codes: string[]) {
  return request.post<unknown, null>('/admin/ewaybill/yuyueNotice', { codes })
}
