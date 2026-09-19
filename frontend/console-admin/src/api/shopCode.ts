import request from './request'
import type {
  CodeImportResult,
  CodeListQuery,
  CodeListResult,
  CodeMakeParams,
} from './types'

// 卡密列表
export function getCodeList(params: CodeListQuery) {
  return request.get<unknown, CodeListResult>('/admin/shopCode/list', { params })
}

// 批量生成卡密
export function makeCode(data: CodeMakeParams) {
  return request.post<unknown, { created: number }>('/admin/shopCode/make', data)
}

// Excel 导入卡密（multipart 字段 file + proid，首列为卡密内容）
export function importCode(proid: number, file: File) {
  const formData = new FormData()
  formData.append('proid', String(proid))
  formData.append('file', file)
  return request.post<unknown, CodeImportResult>('/admin/shopCode/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

// 卡密导出（blob 下载，带 token）
export async function exportCode(proid: number) {
  const res = await request.get('/admin/shopCode/export', {
    params: proid > 0 ? { proid } : {},
    responseType: 'blob',
    // blob 响应不走 {code,msg} 解析
    transformResponse: [(data: unknown) => data],
  })
  const blob = res instanceof Blob ? res : new Blob([res as unknown as BlobPart])
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `商品卡密_${new Date().getTime()}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// 批量删除卡密（仅未售出）
export function deleteCode(ids: number[]) {
  return request.post<unknown, { deleted: number }>('/admin/shopCode/delete', { ids })
}
