import request from './request'

/* ================= 类型（P24-7 批次8 任务227：周期购评价） ================= */

export interface CycleCommentItem {
  id: number
  mid: number
  proid: number
  proname: string
  propic: string
  ggname: string
  nickname: string
  headimg: string
  score: number
  content: string
  content_pic: string
  reply_content: string
  reply_content_pic: string
  status: number
  createtime: number
  createtime_text: string
  reply_time_text: string
  [key: string]: unknown
}

export interface CycleCommentStat {
  total: number
  approved: number
  pending: number
  avg_score: number
  haopercent: number
}

export interface CycleCommentListResult {
  list: CycleCommentItem[]
  total: number
  page: number
  pagesize: number
  stat: CycleCommentStat
}

/* ================= 接口 ================= */

export function getCycleCommentList(params: Record<string, unknown>) {
  return request.get<unknown, CycleCommentListResult>('/admin/cycleComment/list', { params })
}

export function checkCycleComment(id: number, status: number) {
  return request.post<unknown, null>('/admin/cycleComment/check', { id, status })
}

export function replyCycleComment(data: {
  id: number
  reply_content: string
  reply_content_pic?: string
}) {
  return request.post<unknown, null>('/admin/cycleComment/reply', data)
}

export function deleteCycleComment(id: number) {
  return request.post<unknown, null>('/admin/cycleComment/delete', { id })
}
