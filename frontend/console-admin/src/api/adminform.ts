import request from './request'

// ===== 自定义表单整组（批次8-5 任务225）：form + form_order 双表，类型自包含 =====

// 表单定义行（附加未处理记录数）
export interface FormItem {
  id: number
  aid: number
  bid: number
  name: string
  starttime: string
  endtime: string
  content: string
  payset: string
  price: string
  priceedit: string
  maxlimit: string
  perlimit: string
  sort: number
  createtime: number
  commissionset: string
  commission1: string
  commission2: string
  commission3: string
  commissiondata1: string
  commissiondata2: string
  fanwei: string
  fanwei_lng: string
  fanwei_lat: string
  fanwei_range: string
  form_query: string
  form_query_type: string
  form_query_bgcolor: string
  form_query_txtcolor: string
  submit_tourl: string
  st0count?: number
  // detail 附加：默认分类下具有分销权限的会员等级
  aglevellist?: { id: number; name: string; can_agent: number }[]
}

// 提交记录行（form0~form50 动态字段）
export interface FormOrderItem {
  id: number
  aid: number
  bid: number
  formid: number
  title: string
  mid: number
  status: number
  createtime: number
  isudel: number
  paystatus: number
  paynum: string
  money: string
  ordernum: string
  paytime: number
  platform: string
  reason: string
  isrefund: number
  is_send_commission: number
  nickname?: string
  headimg?: string
  [key: string]: unknown
}

export interface FormListQuery {
  page?: number
  pagesize?: number
  name?: string
  bid?: string
}

export interface FormRecordQuery {
  page?: number
  pagesize?: number
  formid?: number
  status?: string
  keyword?: string
  starttime?: string
  endtime?: string
  bid?: string
}

export function getFormList(params: FormListQuery) {
  return request.get<unknown, { list: FormItem[]; total: number; business_options?: { id: number; name: string }[] }>('/admin/form/list', { params })
}

export function getFormDetail(id: number) {
  return request.get<unknown, FormItem>('/admin/form/detail', { params: { id } })
}

export function saveForm(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/form/save', data)
}

export function deleteForm(ids: number[]) {
  return request.post<unknown, null>('/admin/form/delete', { ids })
}

export function copyForm(id: number) {
  return request.post<unknown, { id: number }>('/admin/form/copy', { id })
}

export function getFormRecord(params: FormRecordQuery) {
  return request.get<unknown, { list: FormOrderItem[]; total: number }>('/admin/form/record', { params })
}

export function getFormRecordDetail(id: number) {
  return request.get<unknown, FormOrderItem & { formdef: FormItem | null }>('/admin/form/recorddetail', { params: { id } })
}

export function formRecordSetst(ids: number[], st: number, reason = '', istuikuan = 0) {
  return request.post<unknown, null>('/admin/form/recordsetst', { ids, st, reason, istuikuan })
}

export function formRecordDel(ids: number[]) {
  return request.post<unknown, null>('/admin/form/recorddel', { ids })
}

// 表单数据新增/编辑
export function saveFormRecord(data: Record<string, unknown>) {
  return request.post<unknown, null>('/admin/form/recordsave', data)
}

// 表单数据导出（xlsx 下载）
export function exportFormRecord(params: Record<string, unknown>) {
  return request.get<unknown, Blob>('/admin/form/recordexcel', {
    params,
    responseType: 'blob',
  })
}
