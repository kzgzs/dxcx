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
  /** 详情接口下发：评价图片数组（后端按 content_pic 逗号切分） */
  content_pic_arr: string[]
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

// 审核：单条 {id,status} 或批量 {ids:[],status}；status 1通过 2驳回 0取消审核
export function checkCycleComment(id: number, status: number, ids?: number[]) {
  const data: Record<string, unknown> = { status }
  if (ids && ids.length) data.ids = ids
  else data.id = id
  return request.post<unknown, null>('/admin/cycleComment/check', data)
}

export function replyCycleComment(data: {
  id: number
  reply_content: string
  reply_content_pic?: string
}) {
  return request.post<unknown, null>('/admin/cycleComment/reply', data)
}

// 删除：单条 {id} 或批量 {ids:[]}
export function deleteCycleComment(id: number, ids?: number[]) {
  const data: Record<string, unknown> = ids && ids.length ? { ids } : { id }
  return request.post<unknown, null>('/admin/cycleComment/delete', data)
}

// 评价详情（含评价人信息）
export function getCycleCommentDetail(id: number) {
  return request.post<unknown, { detail: CycleCommentItem; member: Record<string, unknown> }>(
    '/admin/cycleComment/detail',
    { id },
  )
}
