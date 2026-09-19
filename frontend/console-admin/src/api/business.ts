import request from './request'

// ============ 商户域整组（批次8-1 任务221） ============

export interface BusinessOption {
  id: number
  name: string
}

// ============ 城市商户（business_city） ============

export interface BusinessCityItem {
  id: number
  name: string
  province: string
  city: string
  district: string
  status: number
  sort: number
  createtime: number
}

export interface BusinessCityListResult {
  list: BusinessCityItem[]
  total: number
}

export function getBusinessCityList(params: {
  page: number
  pagesize: number
  name?: string
  province?: string
  city?: string
  district?: string
  status?: number | ''
}) {
  return request.get<unknown, BusinessCityListResult>('/admin/businessCity/list', { params })
}

export function saveBusinessCity(data: {
  id?: number
  name: string
  province: string
  city: string
  district: string
  status: number
  sort: number
}) {
  return request.post<unknown, { id: number }>('/admin/businessCity/save', data)
}

export function setBusinessCitySt(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/businessCity/setst', { ids, st })
}

export function deleteBusinessCity(ids: number[]) {
  return request.post<unknown, null>('/admin/businessCity/delete', { ids })
}

// ============ 商户分类（business_category） ============

export interface BusinessCategoryItem {
  id: number
  name: string
  pic: string
  sort: number
  status: number
  createtime: number
}

export interface BusinessCategoryListResult {
  list: BusinessCategoryItem[]
  total: number
}

export function getBusinessCategoryList(params: {
  page: number
  pagesize: number
  name?: string
  status?: number | ''
  field?: string
  order?: string
}) {
  return request.get<unknown, BusinessCategoryListResult>('/admin/businessCategory/list', { params })
}

export function getBusinessCategoryOptions() {
  return request.get<unknown, BusinessOption[]>('/admin/businessCategory/options')
}

export function saveBusinessCategory(data: {
  id?: number
  name: string
  pic: string
  sort: number
  status: number
}) {
  return request.post<unknown, { id: number }>('/admin/businessCategory/save', data)
}

export function deleteBusinessCategory(ids: number[]) {
  return request.post<unknown, null>('/admin/businessCategory/delete', { ids })
}

// ============ 商户销量（business_sales + payorder 聚合） ============

export interface BusinessSalesItem {
  bid: number
  name: string
  total_money: number
  [key: string]: number | string
}

export interface BusinessSalesAuth {
  all: boolean
  [key: string]: boolean
}

export interface BusinessSalesListResult {
  list: BusinessSalesItem[]
  total: number
  sales_auth: BusinessSalesAuth
  types: string[]
}

export function getBusinessSalesList(params: {
  page: number
  pagesize: number
  bid?: string
  name?: string
  field?: string
  order?: string
}) {
  return request.get<unknown, BusinessSalesListResult>('/admin/businessSales/list', { params })
}

export function deleteBusinessSales(bids: number[]) {
  return request.post<unknown, null>('/admin/businessSales/delete', { bids })
}

// ============ 余额明细（business_moneylog） ============

export interface BusinessMoneyLogItem {
  id: number
  bid: number
  name: string
  money: number
  after: number
  type: string
  ordernum: string
  remark: string
  createtime: number
}

export interface BusinessMoneyLogListResult {
  list: BusinessMoneyLogItem[]
  total: number
  business_options: BusinessOption[]
}

export function getBusinessMoneyLogList(params: {
  page: number
  pagesize: number
  bid?: string
  name?: string
  remark?: string
  start?: string
  end?: string
}) {
  return request.get<unknown, BusinessMoneyLogListResult>('/admin/businessMoneyLog/list', { params })
}

export function deleteBusinessMoneyLog(ids: number[]) {
  return request.post<unknown, null>('/admin/businessMoneyLog/delete', { ids })
}

// ============ 提现记录（business_withdrawlog） ============

export interface BusinessWithdrawLogItem {
  id: number
  bid: number
  name: string
  money: number
  txmoney: number
  paytype: string
  account: string
  status: number
  reason: string
  createtime: number
  paytime: number
}

export interface BusinessWithdrawLogListResult {
  list: BusinessWithdrawLogItem[]
  total: number
  business_options: BusinessOption[]
}

export function getBusinessWithdrawLogList(params: {
  page: number
  pagesize: number
  bid?: string
  name?: string
  status?: number | ''
  start?: string
  end?: string
}) {
  return request.get<unknown, BusinessWithdrawLogListResult>('/admin/businessWithdrawLog/list', { params })
}

// st：1=审核通过 2=驳回（返还余额） 3=标记已打款
export function setBusinessWithdrawLogSt(id: number, st: number, reason = '') {
  return request.post<unknown, null>('/admin/businessWithdrawLog/setst', { id, st, reason })
}

export function deleteBusinessWithdrawLog(ids: number[]) {
  return request.post<unknown, null>('/admin/businessWithdrawLog/delete', { ids })
}

// ============ 通知公告（admin_notice type=2） ============

export interface BusinessNoticeItem {
  id: number
  bid: number
  uid: number
  un: string
  title: string
  content: string
  isread: number
  status: number
  createtime: number
}

export interface BusinessNoticeReceiver {
  id: number
  un: string
}

export interface BusinessNoticeListResult {
  list: BusinessNoticeItem[]
  total: number
}

export function getBusinessNoticeList(params: {
  page: number
  pagesize: number
  un?: string
  title?: string
  isread?: number | ''
  start?: string
  end?: string
  field?: string
  order?: string
}) {
  return request.get<unknown, BusinessNoticeListResult>('/admin/businessNotice/list', { params })
}

export function getBusinessNoticeDetail(id: number) {
  return request.get<unknown, BusinessNoticeItem>('/admin/businessNotice/detail', { params: { id } })
}

/** 接收账号选项：main=商家主账号（isadmin>=1），all=主账号+分账号 */
export function getBusinessNoticeReceivers() {
  return request.get<unknown, { main: BusinessNoticeReceiver[]; all: BusinessNoticeReceiver[] }>(
    '/admin/businessNotice/receivers',
  )
}

// sendtype：4=仅商家主账号 5=主账号+分账号；senduser：0=范围内全部，>0=指定账号 id
export function saveBusinessNotice(data: {
  title: string
  content: string
  sendtype: number
  senduser: number
}) {
  return request.post<unknown, { count: number }>('/admin/businessNotice/save', data)
}

/** 批量开启/关闭公告（st：1开启 0关闭） */
export function setBusinessNoticeStatus(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/businessNotice/setst', { ids, st })
}

export function deleteBusinessNotice(ids: number[]) {
  return request.post<unknown, null>('/admin/businessNotice/delete', { ids })
}

// ============ 多商户系统设置（business_sysset） ============

export interface BusinessSyssetInfo {
  id: number
  default_rate: string | number
  withdraw_weixin: number
  withdraw_aliaccount: number
  withdraw_bankcard: number
  withdrawmin: string | number
  withdrawfee: string | number
  weixin_withdraw_max: string | number
  alipay_withdraw_max: string | number
  product_isshow: number
  commission_canset: number
  coupon_canset: number
  commission_kouchu: number
  scoredk_kouchu: number
  leveldk_kouchu: number
  product_showset: number
  xieyi_show: number
  xieyi: string
  seckill_canset: number
  collage_canset: number
  diy_canset: number
  product_check: number
  article_check: number
  shortvideo_check: number
  status: number
  commission_autotransfer: number
  wxfw_status: number
  wxfw_mchname: string
  wxfw_appid: string
  wxfw_mchid: string
  wxfw_mchkey: string
  wxfw_apiclient_cert: string
  wxfw_apiclient_key: string
  wxfw2_mchname: string
  wxfw2_mchid: string
  moneypay: number
  scorein_money: string | number
  scorein_score: number
  parentcommission: string | number
  show_product: number
  show_comment: number
  show_detail: number
  show_link: number
  show_linktext: string
  show_producttext: string
  show_commenttext: string
  show_detailtext: string
  business_useplatmendian: number
  business_useplatshopparam: number
  business_selfscore: number
  business_selfscore_minus: number
  business_selfscore2: number
  business_cashdesk_wxpay_type: number
  business_cashdesk_yue: number
  business_cashdesk_cashpay: number
  business_cashdesk_alipay_type: number
  business_cashdesk_sxpay_type: number
  show_shopdetail_menu: number
  platform_fee_type: number
  [key: string]: string | number
}

export function getBusinessSysset() {
  return request.get<unknown, BusinessSyssetInfo>('/admin/businessSysset/get')
}

export function saveBusinessSysset(info: Partial<BusinessSyssetInfo>) {
  return request.post<unknown, null>('/admin/businessSysset/save', { info })
}

// 微信支付证书上传（.pem/.key），多商户系统设置「微信服务商」凭证使用
export function uploadBusinessCert(file: File) {
  const formData = new FormData()
  formData.append('file', file)
  return request.post<unknown, { url: string }>('/admin/upload/cert', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

// ============ 多商户默认底部导航（designer_menu_business） ============
// menudata 类型复用 designer.ts 的 NavMenudata / NavMenuInfo

export { type NavMenudata, type NavTabItem, type NavMenuInfo } from './designer'

export function getBusinessMenu(platform: string) {
  return request.get<unknown, import('./designer').NavMenuInfo>('/admin/designer/business/get', {
    params: { platform },
  })
}

export function saveBusinessMenu(info: {
  platform: string
  tongbu: number
  menucount: number
  indexurl: string
  menudata: import('./designer').NavMenudata
}) {
  return request.post<unknown, null>('/admin/designer/business/save', { info })
}