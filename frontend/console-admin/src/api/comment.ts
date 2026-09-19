import request from './request'
import type {
  CommentListQuery,
  CommentListResult,
  CommentSaveParams,
  CommentSysset,
} from './types'

// 评价列表（分页 + 筛选 + 顶部统计）
export function getCommentList(params: CommentListQuery) {
  return request.get<unknown, CommentListResult>('/admin/comment/list', { params })
}

// 审核评价（1通过 2驳回）
export function checkComment(id: number, status: number) {
  return request.post<unknown, null>('/admin/comment/check', { id, status })
}

// 商家回复（支持回复图片，多张逗号分隔）
export function replyComment(id: number, replyContent: string, replyPic: string) {
  return request.post<unknown, null>('/admin/comment/reply', {
    id,
    reply_content: replyContent,
    reply_content_pic: replyPic,
  })
}

// 删除评价（单条，走批量删除接口的单元素形式）
export function deleteComment(id: number) {
  return request.post<unknown, null>('/admin/comment/delete', { id })
}

// 批量设置审核状态（0未审核 1已审核，可互相切换）
export function batchSetCommentStatus(ids: number[], status: number) {
  return request.post<unknown, { count: number }>('/admin/comment/batchSet', { ids, status })
}

// 批量删除评价（物理删除）
export function batchDeleteComments(ids: number[]) {
  return request.post<unknown, { count: number }>('/admin/comment/batchDelete', { ids })
}

// 新增/编辑虚拟评价
export function saveComment(data: CommentSaveParams) {
  return request.post<unknown, { id: number }>('/admin/comment/save', data)
}

// 读取评价开关（comment 总开关 / comment_check 审核开关）
export function getCommentSysset() {
  return request.get<unknown, CommentSysset>('/admin/comment/sysset')
}

// 保存评价开关
export function saveCommentSysset(data: CommentSysset) {
  return request.post<unknown, null>('/admin/comment/sysset', data)
}
