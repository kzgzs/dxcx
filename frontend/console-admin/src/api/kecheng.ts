import request from './request'

/* ================= 类型 ================= */

// 课程分类
export interface KechengCategoryItem {
  id: number
  pid: number
  name: string
  pic: string
  status: number
  sort: number
  createtime: number
  count?: number
}

// 课程行
export interface KechengCourseItem {
  id: number
  name: string
  pic: string
  pics: string
  cid: string
  kctype: number
  price: number
  market_price: number
  detail: string
  detail_text: string
  detail_pics: string
  sort: number
  status: number
  gettj: string
  gettjurl: string
  gettjtip: string
  showtj: string
  sxdate: number
  isdt: number
  dtnum: number
  hgscore: number
  join_num: number
  commissionset: number
  commissiondata1: Record<string, { commission1?: number | string; commission2?: number | string; commission3?: number | string }>
  commissiondata2: Record<string, { commission1?: number | string; commission2?: number | string; commission3?: number | string }>
  commissiondata3: Record<string, { commission1?: number | string; commission2?: number | string; commission3?: number | string }>
  lvprice: number
  lvprice_data: Record<string, { money_price?: number | string }>
  orderlearn: number
  learnhg: number
  lecturerid: number
  fenhongset: number
  createtime: number
  chapter_count?: number
}

// 章节行
export interface KechengChapterItem {
  id: number
  kcid: number
  name: string
  pic: string
  kctype: number
  detail: string
  voice_url: string
  video_url: string
  video_duration: string
  ismianfei: number
  isjinzhi: number
  jumpurl: string
  parent_id: number
  give_score: number
  sort: number
  status: number
  createtime: number
  kcname?: string
  zjtype?: string
}

// 订单行
export interface KechengOrderItem {
  id: number
  mid: number
  ordernum: string
  title: string
  kcid: number
  pic: string
  totalprice: number
  price: number
  status: number
  paytype: string
  paytime: number
  platform: string
  platform_text: string
  createtime: number
  member_tel: string
  member_nickname: string
  member_headimg: string
  createtime_text: string
  paytime_text: string
  parent1commission: number
  parent2commission: number
  parent3commission: number
  iscommission: number
}

// 订单详情
export interface KechengOrderDetail {
  order: KechengOrderItem & Record<string, any>
  member: { id: number; nickname: string; headimg: string; tel: string; realname?: string }
}

// 学习记录行
export interface KechengStudylogItem {
  id: number
  mid: number
  kcid: number
  mlid: number
  title: string
  kecheng_name: string
  status: number
  jindu: string
  currentTime: number
  platform: string
  platform_text: string
  createtime: number
  member_tel: string
  member_nickname: string
  member_headimg: string
  createtime_text: string
}

// 课程设置
export interface KechengSyssetData {
  details_rec: number
  showcommission: number
  show_lvupsavemoney: number
  show_join_num: number
  show_buyed_kecheng_price: number
  upgrade_text: string
  ios_canbuy: number
  send_score_num: number
  give_score_num: number
  lecturer_open: number
}

// 题库行
export interface KechengTikuItem {
  id: number
  kcid: number
  mlid: number
  title: string
  type: number
  score: number
  status: number
  sort: number
  jiexi: string
  give_score: number
  createtime: number
  kcname: string
  mlname: string
  tname: string
  options: string[]
  right_option: number[] | string
  right_option_text: string
}


export interface KechengListResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

// 会员等级（复用 member/level/list）
export interface MemberLevelOption {
  id: number
  name: string
  can_agent?: number
}

/* ================= 课程分类 ================= */

export function getKechengCategoryList() {
  return request.get<unknown, { list: KechengCategoryItem[] }>('/admin/kechengCategory/list')
}

export function saveKechengCategory(data: Partial<KechengCategoryItem>) {
  return request.post<unknown, { id: number }>('/admin/kechengCategory/save', data)
}

export function deleteKechengCategory(id: number) {
  return request.post<unknown, null>('/admin/kechengCategory/delete', { id })
}

/* ================= 课程 ================= */

export function getKechengCourseList(params: {
  page?: number
  pagesize?: number
  keyword?: string
  status?: string | number
  cid?: number
}) {
  return request.get<unknown, KechengListResult<KechengCourseItem>>('/admin/kechengList/list', {
    params,
  })
}

export function getKechengCourseDetail(id: number) {
  return request.get<unknown, KechengCourseItem>('/admin/kechengList/detail', { params: { id } })
}

export function saveKechengCourse(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/kechengList/save', data)
}

export function deleteKechengCourse(id: number) {
  return request.post<unknown, null>('/admin/kechengList/delete', { id })
}

export function setKechengCourseStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/kechengList/status', { id, status })
}

/* ================= 章节 ================= */

export function getKechengChapterList(params: {
  kcid?: number
  name?: string
  status?: string
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, KechengListResult<KechengChapterItem>>('/admin/kechengChapter/list', {
    params,
  })
}

export function getKechengChapterDetail(id: number) {
  return request.get<unknown, KechengChapterItem>('/admin/kechengChapter/detail', { params: { id } })
}

export function saveKechengChapter(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/kechengChapter/save', data)
}

export function deleteKechengChapter(ids: number[] | number) {
  return request.post<unknown, null>('/admin/kechengChapter/delete', {
    ids: Array.isArray(ids) ? ids : [ids],
  })
}

export function setKechengChapterStatus(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/kechengChapter/status', { ids, status })
}

/* ================= 答题记录 ================= */

// 答题记录行（整卷）
export interface KechengRecordItem {
  id: number
  mid: number
  kcid: number
  mlid: number
  time: number
  endtime: number
  score: number
  ishg: number
  status: number
  kcname: string
  nickname: string
  headimg: string
  time_text: string
  endtime_text: string
}

// 答题明细行（逐题）
export interface KechengRecordLogItem {
  id: number
  tmid: number
  title: string
  ttype: number
  answer: string
  status: number
  score: number
  time: number
  time_text: string
}

export function getKechengRecordList(params: {
  kcid?: number
  mid?: number
  page?: number
  pagesize?: number
}) {
  return request.get<unknown, KechengListResult<KechengRecordItem>>('/admin/kechengRecord/list', {
    params,
  })
}

export function getKechengRecordLogs(params: { rid: number; page?: number; pagesize?: number }) {
  return request.get<unknown, KechengListResult<KechengRecordLogItem>>('/admin/kechengRecord/logs', {
    params,
  })
}

export function deleteKechengRecord(ids: number[]) {
  return request.post<unknown, null>('/admin/kechengRecord/delete', { ids })
}

/* ================= 订单/学习记录 ================= */

export function getKechengOrderList(params: {
  page?: number
  pagesize?: number
  ordernum?: string
  ctime?: string
  status?: string | number
}) {
  return request.get<unknown, KechengListResult<KechengOrderItem>>('/admin/kechengOrder/list', {
    params,
  })
}

export function getKechengOrderDetail(id: number) {
  return request.get<unknown, KechengOrderDetail>('/admin/kechengOrder/detail', { params: { id } })
}

export function closeKechengOrder(id: number) {
  return request.post<unknown, null>('/admin/kechengOrder/close', { id })
}

export function deleteKechengOrder(id: number) {
  return request.post<unknown, null>('/admin/kechengOrder/delete', { id })
}

export function getKechengStudylogList(params: { page?: number; pagesize?: number }) {
  return request.get<unknown, KechengListResult<KechengStudylogItem>>('/admin/kechengStudylog/list', {
    params,
  })
}

export function deleteKechengStudylog(id: number) {
  return request.post<unknown, null>('/admin/kechengStudylog/delete', { id })
}

/* ================= 课程设置 ================= */

export function getKechengSysset() {
  return request.get<unknown, KechengSyssetData>('/admin/kechengSet/config')
}

export function saveKechengSysset(data: Partial<KechengSyssetData>) {
  return request.post<unknown, null>('/admin/kechengSet/save', data)
}

/* ================= 题库管理（批次5-D2） ================= */

export function getKechengTikuList(params: {
  page?: number
  pagesize?: number
  kcid?: number
  mlid?: number
  keyword?: string
  type?: string | number
  status?: string | number
}) {
  return request.get<unknown, KechengListResult<KechengTikuItem>>('/admin/kechengTiku/list', {
    params,
  })
}

export function saveKechengTiku(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/kechengTiku/save', data)
}

export function deleteKechengTiku(ids: number[]) {
  return request.post<unknown, null>('/admin/kechengTiku/delete', { ids })
}

export function setKechengTikuStatus(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/kechengTiku/status', { ids, status })
}

export function getKechengTikuChapters(kcid: number) {
  return request.get<unknown, { list: { id: number; name: string }[] }>(
    '/admin/kechengTiku/chapters',
    { params: { kcid } },
  )
}

export function importKechengTiku(formData: FormData) {
  return request.post<
    unknown,
    { total: number; success: number; fail: number; errors: string[] }
  >('/admin/kechengTiku/import', formData, { timeout: 180000 })
}

export function exportKechengTiku(params: { kcid?: number; mlid?: number }) {
  return request.get('/admin/kechengTiku/export', {
    params,
    responseType: 'blob',
    timeout: 180000,
  }) as Promise<Blob>
}


/* ================= 会员等级（分销配置/会员价用） ================= */

export function getMemberLevelOptions() {
  return request.get<unknown, { list: MemberLevelOption[] } | MemberLevelOption[]>(
    '/admin/member/level/list',
  )
}
