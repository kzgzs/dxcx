import request from './request'

/* ================= 类型（P24-7 批次8 任务227：移动端后台 + 链接地址选择器） ================= */

/** 移动端后台配置（designer_mobile 表，aid 单行，data 整体 JSON） */
export interface DesignerMobileInfo {
  id: number
  data: {
    bgimg?: string
    [key: string]: unknown
  } | null
  updatetime: number
}

/** 链接地址选择器：固定链接项 */
export interface ChooseUrlFixedLink {
  name: string
  url: string
}

/** 链接地址选择器：分类树节点（child 递归） */
export interface ChooseUrlCategoryNode {
  id: number
  name: string
  child?: ChooseUrlCategoryNode[]
}

/** 链接地址选择器：自定义页面项 */
export interface ChooseUrlPage {
  id: number
  name: string
  indexurl: string
}

/** 链接地址选择器完整数据（GET /admin/designer/chooseurl） */
export interface ChooseUrlData {
  fixed: ChooseUrlFixedLink[]
  shop_category: ChooseUrlCategoryNode[]
  article_category: ChooseUrlCategoryNode[]
  kecheng_category: ChooseUrlCategoryNode[]
  pages: ChooseUrlPage[]
}

/* ================= 接口 ================= */

/** 移动端后台配置读取 */
export function getDesignerMobile() {
  return request.get<unknown, DesignerMobileInfo>('/admin/designer/mobile/get')
}

/** 移动端后台配置保存（data 整体 JSON 落库） */
export function saveDesignerMobile(data: { id?: number; data: Record<string, unknown> }) {
  return request.post<unknown, { id: number }>('/admin/designer/mobile/save', data)
}

/** 链接地址选择器数据 */
export function getDesignerChooseUrl() {
  return request.get<unknown, ChooseUrlData>('/admin/designer/chooseurl')
}
