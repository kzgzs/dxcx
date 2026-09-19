import request from './request'

// ============ 微信会员卡（批次7-6 任务214） ============

/** 会员卡列表行 */
export interface MembercardRow {
  id: number
  title: string
  brand_name: string
  logo_url: string
  card_id: string
  ret_url: string
  status: number
  status_text?: string
  quantity: number
  membercount?: number
  custom_field?: string[]
  createtime: number
  remark?: string
}

/** 开卡表单字段项 */
export interface MembercardFieldItem {
  name: string
  isshow: number
  required: number
}

/** 会员卡详情（大表单全量字段） */
export interface MembercardDetail {
  id: number
  card_id?: string
  ret_url?: string
  status?: number
  status_text?: string
  bg_type: number
  color: string
  background_pic_url: string
  logo_url: string
  brand_name: string
  code_type: string
  title: string
  custom_field: string[]
  center_title: string
  center_sub_title: string
  center_url: string
  custom_url_name: string
  custom_url_sub_title: string
  custom_url: string
  promotion_url_name: string
  promotion_url_sub_title: string
  promotion_url: string
  custom_cell1_name: string
  custom_cell1_tips: string
  custom_cell1_url: string
  notice: string
  description: string
  prerogative: string
  bonus_rules: string
  quantity: number
  get_limit: number
  service_phone: string
  date_info_type: string
  date_info_begin_time: string
  date_info_end_time: string
  date_info_fixed_term: number
  date_info_fixed_begin_term: number
  field_list: Record<string, MembercardFieldItem>
  custom_field_customize1_name: string
  custom_field_customize1_value: string
  custom_field_customize1_link: string
  custom_field_customize2_name: string
  custom_field_customize2_value: string
  custom_field_customize2_link: string
  remark?: string
}

/** 领取记录行 */
export interface MembercardRecordRow {
  id: number
  card_id: string
  card_code: string
  card_title?: string
  mid: number
  openid: string
  nickname: string
  headimg: string
  name: string
  mobile: string
  sex: string
  birthday: string
  email: string
  idcard: string
  education: string
  industry: string
  income: string
  habit: string
  location: string
  field1: string
  field2: string
  field3: string
  field4: string
  field5: string
  status: number
  createtime: number
}

export interface MembercardListQuery {
  page?: number
  pagesize?: number
  keyword?: string
}

export interface RecordListQuery {
  page?: number
  pagesize?: number
  card_id?: string
  card_code?: string
  name?: string
  mobile?: string
  starttime?: string
  endtime?: string
}

export interface SendScorePayload {
  id: number
  score: number
  remark: string
  logid: number
  pagenum: number
  pagelimit: number
}

export interface SendScoreResult {
  status: number
  logid: number
  sendcount: number
  successcount: number
  errorcount: number
  mock: boolean
}

// 会员卡列表
export function mcList(params: MembercardListQuery) {
  return request.get<unknown, { list: MembercardRow[]; total: number }>('/admin/membercard/list', { params })
}

// 会员卡详情（id=0 返回创建默认值）
export function mcDetail(id: number) {
  return request.get<unknown, MembercardDetail>('/admin/membercard/detail', { params: { id } })
}

// 保存会员卡（本地表）
export function mcSave(info: Partial<MembercardDetail>) {
  return request.post<unknown, { id: number }>('/admin/membercard/save', { info })
}

// 同步微信（card/create）
export function mcSync(id: number) {
  return request.post<unknown, { id: number; card_id: string; mock?: boolean; already?: boolean }>(
    '/admin/membercard/sync',
    { id }
  )
}

// 同步审核状态（card/get）
export function mcSyncStatus(id: number) {
  return request.post<unknown, { id: number; status: number; status_text: string; mock?: boolean }>(
    '/admin/membercard/syncstatus',
    { id }
  )
}

// 删除会员卡
export function mcDel(ids: number[]) {
  return request.post<unknown, null>('/admin/membercard/del', { ids })
}

// 设置备注
export function mcSetRemark(id: number, remark: string) {
  return request.post<unknown, null>('/admin/membercard/setremark', { id, remark })
}

// 领取记录列表
export function mcRecordList(params: RecordListQuery) {
  return request.get<unknown, { list: MembercardRecordRow[]; total: number }>('/admin/membercard/record', { params })
}

// 领取记录详情
export function mcRecordDetail(id: number) {
  return request.get<unknown, MembercardRecordRow>('/admin/membercard/recorddetail', { params: { id } })
}

// 删除领取记录
export function mcRecordDel(ids: number[]) {
  return request.post<unknown, null>('/admin/membercard/recorddel', { ids })
}

// 领取记录导出（blob 下载）
export async function mcRecordExport(params: RecordListQuery) {
  const res = await request.get('/admin/membercard/recordexport', {
    params,
    responseType: 'blob',
    timeout: 180000,
    transformResponse: [(data: unknown) => data],
  })
  const blob = res instanceof Blob ? res : new Blob([res as unknown as BlobPart])
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `会员卡领取记录_${new Date().getTime()}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// 送积分（分页幂等，logid 续传）
export function mcSendScore(payload: SendScorePayload) {
  return request.post<unknown, SendScoreResult>('/admin/membercard/sendscore', payload)
}
