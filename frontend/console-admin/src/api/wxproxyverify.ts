import request from './request'

// ============ 微信代认证（批次7-8 任务216）类型与接口 ============

/** 已授权小程序（聚合 open_authorizer + wx_proxyverify_authorizer） */
export interface ProxyverifyAuthorizer {
  id: number
  authorizer_appid: string
  nick_name: string
  head_img: string
  principal_name: string
  user_name: string
  qrcode_url: string
  /** 0未认证 1认证中 2已认证 */
  verify_status: number
  verify_status_name: string
  auth_time: number
  auth_time_text: string
  remark: string
}

/** 认证订单 */
export interface ProxyverifyOrder {
  id: number
  aid: number
  authorizer_appid: string
  customer_type: number
  customer_type_name: string
  company_name: string
  company_pic: string
  company_license_number: string
  company_address: string
  company_tel: string
  contact_name: string
  contact_email: string
  contact_mobile: string
  invoice_type: number
  electronic_id: string
  electronic_desc: string
  vat_enterprise_phone: string
  vat_id: string
  vat_enterprise_address: string
  vat_desc: string
  vat_bank_name: string
  vat_bank_account: string
  qualification_other: string
  account_name: string
  account_name_type: number
  account_supplemental: string
  pay_type: number
  pay_type_name: string
  third_party_phone: string
  taskid: string
  errmsg: string
  task_status: number
  task_status_name: string
  apply_status: number
  apply_status_name: string
  auth_url: string
  auth_url_img: string
  createtime: number
  createtime_text: string
  updatetime: number
  updatetime_text: string
  service_appid: string
  orderid: string
  appid: string
  refill_reason: string
  fail_reason: string
  service_money: string
  auth_identification: string
  auth_ident_material: string
  remark: string
}

/** 个人认证身份选项（打平树） */
export interface IdentityNode {
  id: number
  name: string
  showname: string
  desc: string
  deep: number
}

/** 系统设置（三类代缴额度） */
export interface ProxyverifySet {
  id: number
  aid: number
  status: number
  quota: string
  used: number
  all: number
  num: number
  used2: number
  all2: number
  num2: number
  used3: number
  all3: number
  num3: number
  service_appid: string
  third_party_phone: string
  updatetime: number
  createtime: number
  is_mock?: boolean
}

interface PageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

/** 认证订单保存入参（资料表单） */
export interface ProxyverifyOrderSaveParams {
  id?: number
  authorizer_appid?: string
  customer_type: number
  company_name?: string
  company_pic?: string
  company_license_number?: string
  company_address?: string
  company_tel?: string
  contact_name: string
  contact_email: string
  contact_mobile?: string
  invoice_type: number
  electronic_id?: string
  electronic_desc?: string
  vat_enterprise_phone?: string
  vat_id?: string
  vat_enterprise_address?: string
  vat_desc?: string
  vat_bank_name?: string
  vat_bank_account?: string
  qualification_other?: string
  account_name: string
  account_name_type: number
  account_supplemental?: string
  pay_type: number
  auth_identification?: string
  auth_ident_material?: string
}

// ============ 小程序列表 ============

export function getProxyverifyAuthorizerList(params: { page?: number; pagesize?: number; keyword?: string }) {
  return request.get<unknown, PageResult<ProxyverifyAuthorizer>>('/admin/wxproxyverify/index', { params })
}

export function saveAuthorizerRemark(id: number, remark: string) {
  return request.post<unknown, null>('/admin/wxproxyverify/authorizer/remark', { id, remark })
}

export function deleteAuthorizers(ids: number[]) {
  return request.post<unknown, null>('/admin/wxproxyverify/authorizer/del', { ids })
}

// ============ 认证订单 ============

export interface ProxyverifyOrderQuery {
  page?: number
  pagesize?: number
  customer_type?: string
  contact_name?: string
  account_name?: string
  pay_type?: string
  task_status?: string
  apply_status?: string
}

export function getProxyverifyOrderList(params: ProxyverifyOrderQuery) {
  return request.get<unknown, PageResult<ProxyverifyOrder>>('/admin/wxproxyverify/order', { params })
}

export function getProxyverifyOrderDetail(id: number) {
  return request.get<unknown, ProxyverifyOrder>('/admin/wxproxyverify/order/detail', { params: { id } })
}

export function saveProxyverifyOrder(data: ProxyverifyOrderSaveParams) {
  return request.post<unknown, { id: number; taskid: string; auth_url: string }>(
    '/admin/wxproxyverify/order/save',
    data,
  )
}

export function deleteProxyverifyOrders(ids: number[]) {
  return request.post<unknown, null>('/admin/wxproxyverify/order/del', { ids })
}

export function saveOrderRemark(id: number, remark: string) {
  return request.post<unknown, null>('/admin/wxproxyverify/order/remark', { id, remark })
}

/** 查询认证进度（回写 task_status/apply_status） */
export function queryTaskStatus(id: number) {
  return request.get<unknown, ProxyverifyOrder>('/admin/wxproxyverify/queryTaskStatus', { params: { id } })
}

// ============ 认证辅助 ============

export function chooseIdentity(authorizer_appid?: string) {
  return request.get<unknown, { list: IdentityNode[] }>('/admin/wxproxyverify/chooseidentity', {
    params: { authorizer_appid },
  })
}

export function checkNickName(authorizer_appid: string, nick_name: string) {
  return request.post<unknown, { msg: string; wording: string; hit_keyword: boolean }>(
    '/admin/wxproxyverify/checkNickName',
    { authorizer_appid, nick_name },
  )
}

export function uploadAuthMaterial(authorizer_appid: string, media: string) {
  return request.post<unknown, { status: number; msg: string; mediaid: string }>(
    '/admin/wxproxyverify/uploadauthmaterial',
    { authorizer_appid, media },
  )
}

// ============ 系统设置 ============

export function getProxyverifySet() {
  return request.get<unknown, ProxyverifySet>('/admin/wxproxyverify/set')
}

export function saveProxyverifySet(data: Partial<ProxyverifySet>) {
  return request.post<unknown, null>('/admin/wxproxyverify/set', data)
}
