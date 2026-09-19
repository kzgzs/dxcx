import request from './request'

// ===== 视频管理整组（批次8-5 任务225）：video_list 表，类型自包含 =====

// 视频号扩展参数
export interface VideoExtParam {
  feedtype?: string
  finderuser?: string
  feedid?: string
  feedtoken?: string
  [key: string]: string | undefined
}

// 视频行
export interface VideoListItem {
  id: number
  aid: number
  bid: number
  name: string
  pic: string
  status: number
  sort: number
  createtime: number
  type: number
  video_url: string
  video_duration: string
  ext_param: string
  ext?: VideoExtParam
}

export interface VideoListQuery {
  page?: number
  pagesize?: number
  name?: string
  type?: string
  status?: string
  bid?: string
}

export function getVideoList(params: VideoListQuery) {
  return request.get<unknown, { list: VideoListItem[]; total: number }>('/admin/videoList/list', { params })
}

export function getVideoDetail(id: number) {
  return request.get<unknown, VideoListItem>('/admin/videoList/detail', { params: { id } })
}

export function saveVideo(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/videoList/save', data)
}

export function deleteVideo(ids: number[]) {
  return request.post<unknown, null>('/admin/videoList/delete', { ids })
}
