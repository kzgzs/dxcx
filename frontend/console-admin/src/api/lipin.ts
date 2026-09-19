import request from './request'

// ===== 礼品卡管理整组（批次8-5 任务225），类型自包含 =====

// 礼品卡活动行（附加兑换码统计与分类名）
export interface LipinItem {
  id: number
  aid: number
  name: string
  cid: number
  type: number
  num_type: string
  starttime: string
  endtime: string
  money: string
  prodata: string
  prodata4: string
  num_type4: string
  createtime: number
  status: number
  score: string
  coupon_ids: string
  totalcount?: number
  usedcount?: number
  cname?: string
}

// 兑换码行
export interface LipinCodeItem {
  id: number
  aid: number
  hid: number
  cardno: string
  code: string
  qr: string
  status: number
  createtime: number
  saletime: number
  usetime: number
  mid: number
  headimg: string
  nickname: string
  remark: string
  sale_mdid: number
  hexiao_mdid: number
  hname?: string
}

// 礼品卡分类行（平铺含 pid，子级 name 带前缀）
export interface LipinCategoryItem {
  id: number
  aid: number
  bid: number
  pid: number
  name: string
  pic: string
  status: number
  sort: number
  createtime: number
}

// 礼品卡设置
export interface LipinSetData {
  status: number
  scanshow: number
  guize: string
}

export interface LipinListQuery {
  page?: number
  pagesize?: number
  name?: string
  status?: string
}

export interface LipinCodeListQuery {
  page?: number
  pagesize?: number
  hid?: number
  cardno?: string
  cardno2?: string
  code?: string
  mid?: number
  nickname?: string
  status?: string
  starttime?: string
  endtime?: string
}

// ===== 礼品卡活动 =====
export function getLipinList(params: LipinListQuery) {
  return request.get<unknown, { list: LipinItem[]; total: number }>('/admin/lipin/list', { params })
}

export function getLipinDetail(id: number) {
  return request.get<unknown, LipinItem>('/admin/lipin/detail', { params: { id } })
}

export function saveLipin(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/lipin/save', data)
}

export function deleteLipin(ids: number[]) {
  return request.post<unknown, null>('/admin/lipin/delete', { ids })
}

export function getLipinSet() {
  return request.get<unknown, LipinSetData>('/admin/lipin/set')
}

export function saveLipinSet(data: LipinSetData) {
  return request.post<unknown, null>('/admin/lipin/set', data)
}

// ===== 兑换码 =====
export function getLipinCodeList(params: LipinCodeListQuery) {
  return request.get<unknown, { list: LipinCodeItem[]; total: number }>('/admin/lipin/codelist', { params })
}

export function makeLipinCode(data: { hid: number; makecount: number; codelength: number; codetype: number; qrtype: number }) {
  return request.post<unknown, { created: number }>('/admin/lipin/makecode', data)
}

export function lipinSetst(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/lipin/setst', { ids, st })
}

export function lipinCodelistDel(ids: number[]) {
  return request.post<unknown, null>('/admin/lipin/codelistdel', { ids })
}

// 兑换码导出（blob 下载）
export async function lipinCodelistExcel(params: LipinCodeListQuery) {
  const res = await request.get('/admin/lipin/codelistexcel', {
    params,
    responseType: 'blob',
    timeout: 180000,
    transformResponse: [(data: unknown) => data],
  })
  const blob = res instanceof Blob ? res : new Blob([res as unknown as BlobPart])
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `礼品卡兑换码_${new Date().getTime()}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// 兑换码导入（multipart file）
export function importLipinCode(hid: number, file: File) {
  const fd = new FormData()
  fd.append('hid', String(hid))
  fd.append('file', file)
  return request.post<unknown, { inserted: number }>('/admin/lipin/importexcel', fd)
}

// ===== 礼品卡分类 =====
export function getLipinCategoryList() {
  return request.get<unknown, { list: LipinCategoryItem[]; total: number }>('/admin/lipinCategory/list')
}

export function getLipinCategoryParents(id = 0) {
  return request.get<unknown, { id: number; name: string }[]>('/admin/lipinCategory/parents', { params: { id } })
}

export function saveLipinCategory(data: { id?: number; pid: number; name: string; pic?: string; status?: number; sort?: number }) {
  return request.post<unknown, { id: number }>('/admin/lipinCategory/save', data)
}

export function deleteLipinCategory(id: number) {
  return request.post<unknown, null>('/admin/lipinCategory/delete', { id })
}
