import request from './request'
import type {
  MemberAddressItem,
  MemberDetail,
  MemberEditParams,
  MemberLevelDetail,
  MemberLevelItem,
  MemberLevelRecordItem,
  MemberLevelRecordSaveParams,
  MemberLevelSaveParams,
  MemberListQuery,
  MemberListResult,
  MemberLogResult,
  MemberMoneyLogItem,
  MemberOrderItem,
  MemberScoreLogItem,
} from './types'

// 会员列表
export function getMemberList(params: MemberListQuery) {
  return request.get<unknown, MemberListResult>('/admin/member/list', { params })
}

// 会员详情
export function getMemberDetail(id: number) {
  return request.get<unknown, MemberDetail>('/admin/member/detail', { params: { id } })
}

// 编辑会员（昵称/手机/等级/等级到期/备注）
export function editMember(data: MemberEditParams) {
  return request.post<unknown, null>('/admin/member/edit', data)
}

// 余额充值/扣费（money 正=充值 负=扣款）
export function rechargeMember(data: { id: number; money: number; remark?: string }) {
  return request.post<unknown, { money: number }>('/admin/member/recharge', data)
}

// 积分调整（score 正负）
export function addMemberScore(data: { id: number; score: number; remark?: string }) {
  return request.post<unknown, { score: number }>('/admin/member/addscore', data)
}

// 冻结/解冻
export function freezeMember(id: number) {
  return request.post<unknown, { isfreeze: number }>('/admin/member/freeze', { id })
}

// 锁定/解锁
export function lockMember(id: number) {
  return request.post<unknown, { islock: number }>('/admin/member/lock', { id })
}

// 余额明细
export function getMemberMoneyLog(params: { mid: number; page?: number; pagesize?: number }) {
  return request.get<unknown, MemberLogResult<MemberMoneyLogItem>>('/admin/member/moneylog', { params })
}

// 积分明细
export function getMemberScoreLog(params: { mid: number; page?: number; pagesize?: number }) {
  return request.get<unknown, MemberLogResult<MemberScoreLogItem>>('/admin/member/scorelog', { params })
}

// 会员订单
export function getMemberOrders(params: { mid: number; page?: number; pagesize?: number }) {
  return request.get<unknown, MemberLogResult<MemberOrderItem>>('/admin/member/orders', { params })
}

// 收货地址
export function getMemberAddress(params: { mid: number; page?: number; pagesize?: number }) {
  return request.get<unknown, MemberLogResult<MemberAddressItem>>('/admin/member/address', { params })
}

// 等级列表
export function getMemberLevelList() {
  return request.get<unknown, { list: MemberLevelItem[] }>('/admin/member/level/list')
}

// 等级详情（编辑回读全字段）
export function getMemberLevelDetail(id: number) {
  return request.get<unknown, MemberLevelDetail>('/admin/member/level/detail', { params: { id } })
}

// 等级新增/编辑
export function saveMemberLevel(data: MemberLevelSaveParams) {
  return request.post<unknown, null>('/admin/member/level/save', data)
}

// 等级删除
export function deleteMemberLevel(id: number) {
  return request.post<unknown, null>('/admin/member/level/delete', { id })
}

// 区域代理归属记录列表（按等级）
export function getMemberLevelRecordList(levelid: number) {
  return request.get<unknown, { list: MemberLevelRecordItem[] }>('/admin/member/level/record/list', {
    params: { levelid },
  })
}

// 区域代理归属保存
export function saveMemberLevelRecord(data: MemberLevelRecordSaveParams) {
  return request.post<unknown, null>('/admin/member/level/record/save', data)
}
