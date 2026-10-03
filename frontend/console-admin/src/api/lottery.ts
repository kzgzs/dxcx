import request from './request'

// ============ 类型（本模块私有，避免改动共享 types.ts） ============

// 奖品类型：1实物奖品 2红包 3积分 4优惠券
export const PRIZE_TYPE_TEXT: Record<number, string> = {
  1: '实物奖品',
  2: '红包',
  3: '积分',
  4: '优惠券',
}

// 奖项行（j0 不中奖 + j1-j9 真实奖项；gl 为手工录入中奖概率%）
export interface LotteryPrize {
  index: number
  mc: string
  pic: string
  tp: number // 1实物 2红包 3积分 4优惠券
  sl: number // 奖品数量（库存）
  yj: number // 已出奖数
  gl: number // 中奖概率 %
}

// 兑奖信息表单项（formcontent JSON 反序列化）
export interface LotteryFormField {
  type: string // 单行输入/多行输入/单项选择/多项选择/普通选择/时间选择/日期选择/省市区选择
  name: string // 字段名称
  content: string // 字段内容（提示语/选项）
  required: boolean // 是否必填
}

export interface LotteryItem {
  id: number
  type: string // dzp 大转盘 / ggk 刮刮卡
  name: string
  banner: string
  toppic: string
  bgpic: string
  poppic: string
  popnote: string
  bgcolor: string
  starttime: number
  endtime: number
  phase: string // notstart / ongoing / ended
  status: number
  totalprizes: number // 总奖品数（真实奖项库存合计）
  cjnum: number // 已抽奖次数
  zjnum: number // 已中奖次数
  pertotal: number
  perday: number
  shareaddnum: number
  createtime: number
  // 详情独有
  guize?: string
  sharedaytimes?: number
  sharetimes?: number
  joincond?: string
  joinscope?: number
  use_type?: number
  usescore?: number
  scene_id?: string
  formcontent?: string
  sharetitle?: string
  sharelink?: string
  sharepic?: string
  sharedesc?: string
  prizes?: LotteryPrize[]
}

export interface LotteryRecordItem {
  id: number
  hid: number
  name: string
  mid: number
  nickname: string
  headimg: string
  jx: number // 奖项 0未中奖
  jxtp: number // 1实物 2红包 3积分 4优惠券
  jxmc: string
  linkman: string
  tel: string
  createtime: number
  status: number // 0未领取 1已领取
  code: string
  remark: string
}

export interface PageResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

// 保存入参奖项行（不含推导字段）
export interface LotteryPrizeSaveRow {
  mc: string
  pic: string
  tp: number
  sl: number
  gl: number
}

export interface LotterySaveParams {
  id?: number
  type: string
  name: string
  toppic: string
  bgpic: string
  poppic: string
  popnote: string
  bgcolor: string
  starttime: string
  endtime: string
  guize: string
  pertotal: number
  perday: number
  shareaddnum: number
  sharedaytimes: number
  sharetimes: number
  joincond: string
  joinscope: number
  use_type: number
  usescore: number
  scene_id: string
  formcontent: string
  sharetitle: string
  sharelink: string
  sharepic: string
  sharedesc: string
  status: number
  prizes: LotteryPrizeSaveRow[]
}

// ============ 活动管理 ============

export function getLotteryList(params: {
  page?: number
  pagesize?: number
  type?: string
  status?: number | ''
  phase?: string
  name?: string
}) {
  return request.get<unknown, PageResult<LotteryItem>>('/admin/choujiang/list', { params })
}

export function getLotteryDetail(id: number) {
  return request.get<unknown, LotteryItem>('/admin/choujiang/detail', { params: { id } })
}

export function saveLottery(data: LotterySaveParams) {
  return request.post<unknown, { id: number }>('/admin/choujiang/save', data)
}

export function deleteLottery(id: number) {
  return request.post<unknown, null>('/admin/choujiang/delete', { id })
}

export function setLotteryStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/choujiang/status', { id, status })
}

// ============ 中奖记录 ============

export interface LotteryRecordFilter {
  page?: number
  pagesize?: number
  hid?: number | ''
  mid?: number | ''
  nickname?: string
  jxmc?: string
  duijiang?: string
  status?: number | ''
  start_date?: string
  end_date?: string
}

export function getLotteryRecords(params: LotteryRecordFilter) {
  return request.get<unknown, PageResult<LotteryRecordItem>>('/admin/choujiang/records', {
    params,
  })
}

export function verifyLotteryRecord(id: number) {
  return request.post<unknown, { code: string }>('/admin/choujiang/recordSetst', { id })
}

export function deleteLotteryRecords(ids: number[]) {
  return request.post<unknown, null>('/admin/choujiang/recordDelete', { ids })
}

// 中奖记录导出（按筛选条件；返回 xlsx Blob）
export function exportLotteryRecords(params: LotteryRecordFilter) {
  return request.get('/admin/choujiang/export', {
    params,
    responseType: 'blob',
    timeout: 180000,
  }) as Promise<Blob>
}
