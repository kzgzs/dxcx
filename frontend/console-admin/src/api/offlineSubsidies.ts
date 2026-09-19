import request from './request'

/* ================= 类型（P24-7 批次8 任务227：线下补助） ================= */

export interface OfflineSubsidiesSet {
  id: number
  status: number
  effective_ratio: number | string
  cycle: number
  fwtype: number
  base: number
  productids: string
  scene: string
  upgrade_levelid: string
  upgrade_ratio: number | string
  upgrade_text: string
  upgrade_num: number
  upgrade_auto_uplevelid: number
  pass_levelid: string
  pass_ratio: number | string
  pass_text: string
  recommend_levelid: string
  recommend_ratio: number | string
  recommend_text: string
  lecturer_levelid: string
  lecturer_ratio: number | string
  lecturer_text: string
  lovefund_levelid: string
  lovefund_ratio: number | string
  lovefund_text: string
  updatetime: number
}

export interface OfflineSubsidiesLogItem {
  id: number
  mid: number
  frommid: number
  orderid: number
  ordernum: string
  scene: string
  scene_text: string
  type: number
  type_text: string
  score: number | string
  commission: number | string
  order_money: number | string
  remark: string
  status: number
  nickname: string
  from_nickname: string
  createtime: number
  createtime_text: string
}

export interface OfflineSubsidiesLogStat {
  total: number
  sum_commission: number
  sum_score: number
  today_num: number
}

export interface OfflineSubsidiesLogResult {
  list: OfflineSubsidiesLogItem[]
  total: number
  page: number
  pagesize: number
  stat: OfflineSubsidiesLogStat
}

/* ================= 接口 ================= */

export function getOfflineSubsidiesSet() {
  return request.get<unknown, OfflineSubsidiesSet>('/admin/offlineSubsidies/set')
}

export function saveOfflineSubsidiesSet(data: Record<string, unknown>) {
  return request.post<unknown, null>('/admin/offlineSubsidies/set', data)
}

export function getOfflineSubsidiesLogList(params: Record<string, unknown>) {
  return request.get<unknown, OfflineSubsidiesLogResult>('/admin/offlineSubsidies/log/list', {
    params,
  })
}

export function deleteOfflineSubsidiesLog(id: number) {
  return request.post<unknown, null>('/admin/offlineSubsidies/log/delete', { id })
}
