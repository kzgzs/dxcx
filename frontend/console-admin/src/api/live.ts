import request from './request'

// ============ 小程序直播（批次7-6 任务214） ============

/** 直播间行 */
export interface LiveRoomRow {
  id: number
  roomId: number
  name: string
  coverImg: string
  shareImg: string
  feedsImg: string
  startTime: number
  endTime: number
  anchorName: string
  anchorWechat: string
  subAnchorWechat: string
  createrWechat: string
  isFeedsPublic: number
  type: number
  screenType: number
  closeLike: number
  closeGoods: number
  closeComment: number
  closeReplay: number
  closeShare: number
  closeKf: number
  qrcode_url: string
  live_status: string
  sort: number
  status: number
  createtime: number
  goods?: unknown[]
}

export interface LiveListQuery {
  page?: number
  pagesize?: number
  name?: string
}

// 直播间列表
export function liveList(params: LiveListQuery) {
  return request.get<unknown, { list: LiveRoomRow[]; total: number; nowtime: number }>('/admin/live/list', { params })
}

// 直播间详情
export function liveDetail(id: number) {
  return request.get<unknown, LiveRoomRow>('/admin/live/detail', { params: { id } })
}

/** 保存载荷（时间允许字符串，后端 strtotime 转换） */
export type LiveSavePayload = Partial<Omit<LiveRoomRow, 'startTime' | 'endTime'>> & {
  id?: number
  startTime?: string | number
  endTime?: string | number
}

// 新增/编辑直播间
export function liveSave(info: LiveSavePayload) {
  return request.post<unknown, { id: number }>('/admin/live/save', { info })
}

// 改显示状态
export function liveSetst(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/live/setst', { ids, st })
}

// 删除直播间
export function liveDel(ids: number[]) {
  return request.post<unknown, null>('/admin/live/del', { ids })
}

// 主播推流二维码（mock 返回占位图路径）
export function livePusherQr(id: number) {
  return request.post<unknown, { url: string; mock?: boolean }>('/admin/live/getpusherqr', { id })
}
