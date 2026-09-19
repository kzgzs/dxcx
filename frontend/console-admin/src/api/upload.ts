/**
 * 素材上传域接口封装（任务291：ImgIconPicker 消费）
 * - 信封 code=0 由 api/request.ts 响应拦截器统一处理，函数直接返回 data
 * - 上传本体沿用现有 POST /admin/upload（api/goods.ts 的 uploadFile，此处不再重复封装）
 */
import request from './request'

/** 素材行 */
export interface UploadAssetRow {
  id: number
  name: string
  url: string
  /** 缩略图（缺省时前端以 url 代入） */
  thumb?: string
  /** 分组ID */
  gid?: number
  size?: number
  addtime?: string | number
  /** 素材扩展名（后端下发，用于区分视频/图片） */
  type?: string
}

/** 分组节点（两级树：一级含 children 二级，与后端 groupTree 键名一致） */
export interface UploadGroupNode {
  id: number
  name: string
  children?: UploadGroupNode[]
}

/** GET /admin/upload/browser 查询参数 */
export interface UploadBrowserQuery {
  /** 分组ID（二级分组ID） */
  gid?: number | ''
  keyword?: string
  /** 排序：new=最新（默认）/old=最早/name=名称 */
  sort?: 'new' | 'old' | 'name'
  page: number
  pagesize?: number
}

/** GET /admin/upload/browser 返回 data */
export interface UploadBrowserData {
  list: UploadAssetRow[]
  total: number
  /** 分组两级树（随列表一并下发，前端无需二次请求） */
  groups: UploadGroupNode[]
}

/** POST /admin/upload/iconrender 返回 data */
export interface IconRenderResult {
  url: string
}

/** 素材浏览（分组两级树+关键词+排序+分页） */
export function getUploadBrowser(params: UploadBrowserQuery) {
  return request.get<unknown, UploadBrowserData>('/admin/upload/browser', { params })
}

/** 素材删除（批量ID） */
export function deleteUploadFiles(ids: number[]) {
  return request.post<unknown, null>('/admin/upload/delete', { ids })
}

/** 分组两级树（独立获取，browser 返回的 groups 亦可直接复用） */
export function getUploadGroups() {
  return request.get<unknown, UploadGroupNode[]>('/admin/upload/group')
}

/** 新增分组（pid=0 为一级） */
export function addUploadGroup(name: string, pid = 0) {
  return request.post<unknown, { id: number }>('/admin/upload/addgroup', { name, pid })
}

/** 编辑分组名称 */
export function editUploadGroup(id: number, name: string) {
  return request.post<unknown, null>('/admin/upload/editgroup', { id, name })
}

/** 删除分组（子组一并删除，组内文件归回未分组） */
export function delUploadGroup(id: number) {
  return request.post<unknown, null>('/admin/upload/delgroup', { id })
}

/** 批量移动素材到分组（gid=0 归回未分组） */
export function moveUploadFiles(ids: number[], gid: number) {
  return request.post<unknown, null>('/admin/upload/changegroup', { ids, gid })
}

/** 图标渲染入库：前端 canvas 转 PNG dataURL 上载，换回素材 URL */
export function renderUploadIcon(dataurl: string, name: string, width: number, height: number) {
  return request.post<unknown, IconRenderResult>('/admin/upload/iconrender', {
    dataurl,
    name,
    width,
    height,
  })
}

/**
 * 素材访问地址助手（任务296裂图修复）：
 * - 默认同域部署：素材 url 为 /upload/、/uploads/ 相对路径，直接返回即可
 * - 跨域部署（前后端不同源）：设 VITE_API_BASE_URL 为后端完整域名前缀
 * - 绝对地址（http/https/协议相对）与 data: 内联图原样返回
 */
export function assetUrl(url?: string): string {
  const u = (url || '').trim()
  if (!u) return u
  if (/^(https?:)?\/\//.test(u) || u.startsWith('data:')) return u
  const base = (import.meta.env.VITE_API_BASE_URL as string | undefined) || ''
  return base + u
}
