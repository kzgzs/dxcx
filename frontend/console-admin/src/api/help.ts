import request from './request'

// ============ 类型定义 ============

/** 帮助文章（help 表，对照参考系统 WebHelp 字段） */
export interface HelpItem {
  id: number
  name: string
  content?: string
  readcount: number
  sort: number
  createtime: number
  status: number
}

/** 列表筛选参数 */
export interface HelpQuery {
  page: number
  pagesize: number
  name?: string
  status?: '' | 0 | 1
  field?: string
  order?: string
}

export interface HelpListResult {
  list: HelpItem[]
  total: number
}

/** 保存表单（添加 id=0 / 编辑 id>0） */
export interface HelpSaveData {
  id: number
  name: string
  content: string
  readcount: number
  sort: number
  status: number
}

// ============ 接口 ============

// 文章列表（筛选 + 排序 + 分页）
export function getHelpList(params: HelpQuery) {
  return request.get<unknown, HelpListResult>('/console/help/list', { params })
}

// 文章详情（编辑回显）
export function getHelpDetail(id: number) {
  return request.get<unknown, HelpItem>('/console/help/detail', { params: { id } })
}

// 添加 / 编辑文章（当页保存，不刷新不跳转）
export function saveHelp(data: HelpSaveData) {
  return request.post<unknown, { id: number }>('/console/help/save', data)
}

// 改状态（批量，st: 0 关闭 / 1 开启）
export function setHelpStatus(ids: number[], st: 0 | 1) {
  return request.post<unknown, null>('/console/help/setst', { ids, st })
}

// 删除（批量）
export function deleteHelp(ids: number[]) {
  return request.post<unknown, null>('/console/help/delete', { ids })
}
