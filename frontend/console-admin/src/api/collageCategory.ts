import request from './request'

/* ================= 类型 ================= */

export interface CollageCategoryItem {
  id: number
  pid: number
  name: string
  pic: string
  status: number
  sort: number
  createtime: number
}

/* ================= 接口（P24-7 批次8 任务227） ================= */

export function getCollageCategoryList() {
  return request.get<unknown, { list: CollageCategoryItem[] }>('/admin/collageCategory/list')
}

export function saveCollageCategory(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/collageCategory/save', data)
}

export function deleteCollageCategory(id: number) {
  return request.post<unknown, null>('/admin/collageCategory/delete', { id })
}
