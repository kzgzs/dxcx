import request from './request'

// ============ 多商户管理（business 表，商户-商户列表） ============

/** status：0 待审核 1 已通过 2 已驳回 3 已过期；is_open：1 营业 0 停业 */
export interface BusinessMerchantItem {
  id: number
  cid: string
  cname: string
  name: string
  logo: string
  pics: string
  province: string
  city: string
  district: string
  address: string
  /** 联系人 */
  linkman: string
  /** 联系电话 */
  linktel: string
  /** 客服电话 */
  tel: string
  /** 店主登录账号 */
  un: string
  money: number
  viewnum: number
  feepercent: number
  sort: number
  status: number
  is_open: number
  createtime: number
  endtime: number
}

export interface BusinessMerchantQuery {
  page: number
  pagesize: number
  id?: number | ''
  name?: string
  cid?: number | ''
  status?: number | ''
  is_open?: number | ''
  field?: string
  order?: string
}

export interface BusinessMerchantAccount {
  id: number
  un: string
  auth_data: string[]
  wxauth_data: string[]
  notice_auth_data: string[]
  hexiao_auth_data: string[]
}

export interface BusinessMerchantDetail {
  info: Record<string, any>
  account: BusinessMerchantAccount
}

/** 保存入参：info 为 business 字段，uinfo 为登录账号；四类权限为 key 数组 */
export interface BusinessMerchantSaveParams {
  info: Record<string, any>
  uinfo: { id?: number; un: string; pwd?: string }
  auth_data: string[]
  wxauth_data: string[]
  notice_auth_data: string[]
  hexiao_auth_data: string[]
}

export function getBusinessMerchantList(params: BusinessMerchantQuery) {
  return request.get<unknown, { list: BusinessMerchantItem[]; total: number }>('/admin/business/list', {
    params,
  })
}

export function getBusinessMerchantDetail(id: number) {
  return request.get<unknown, { info: any; account: BusinessMerchantAccount }>('/admin/business/detail', {
    params: { id },
  })
}

export function saveBusinessMerchant(data: BusinessMerchantSaveParams) {
  return request.post<unknown, { id: number }>('/admin/business/save', data)
}

export function deleteBusinessMerchant(ids: number[]) {
  return request.post<unknown, null>('/admin/business/delete', { ids })
}

export function setBusinessMerchantStatus(ids: number[], st: number, reason = '') {
  return request.post<unknown, null>('/admin/business/setst', { ids, st, reason })
}

export function setBusinessMerchantOpen(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/business/setopen', { ids, st })
}

export function rechargeBusinessMerchant(id: number, money: number) {
  return request.post<unknown, null>('/admin/business/recharge', { id, money })
}

export function getBusinessLoginUrl() {
  return request.get<unknown, { pc_url: string; mobile_url: string }>('/admin/business/loginUrl')
}
