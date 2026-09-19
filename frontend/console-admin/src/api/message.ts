import request from './request'

// ============ 类型定义 ============

/** 网站留言（webmessage 表，对照参考系统 WebMessage 字段） */
export interface WebMessageItem {
  id: number
  realname: string
  tel: string
  content: string
  ip: string
  createtime: number
  status: number
}

/** 列表筛选参数 */
export interface WebMessageQuery {
  page: number
  pagesize: number
  realname?: string
  tel?: string
  status?: '' | 0 | 1
  start_date?: string
  end_date?: string
  field?: string
  order?: string
}

export interface WebMessageListResult {
  list: WebMessageItem[]
  total: number
}

// ============ 接口 ============

// 留言列表（筛选 + 排序 + 分页）
export function getWebMessageList(params: WebMessageQuery) {
  return request.get<unknown, WebMessageListResult>('/console/webmessage/list', { params })
}

// 改状态（批量，st: 0 未处理 / 1 已处理）
export function setWebMessageStatus(ids: number[], st: 0 | 1) {
  return request.post<unknown, null>('/console/webmessage/setst', { ids, st })
}

// 删除（批量）
export function deleteWebMessage(ids: number[]) {
  return request.post<unknown, null>('/console/webmessage/delete', { ids })
}
