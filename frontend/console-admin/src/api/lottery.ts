import request from './request'

// ============ 类型（本模块私有，避免改动共享 types.ts） ============

// 奖项行（j0 不中奖 + j1-j9 真实奖项；gl 由数量推导）
export interface LotteryPrize {
  index: number
  mc: string
  pic: string
  tp: number // 1实物 2红包
  sl: number // 奖品数量
  yj: number // 已出奖数
  gl: number // 中奖概率 %（数量/总数 推导）
}

export interface LotteryItem {
  id: number
  type: string // dzp 大转盘 / ggk 刮刮卡
  name: string
  banner: string
  starttime: number
  endtime: number
  phase: string // notstart / ongoing / ended
  status: number
  cjnum: number // 累计抽奖次数
  zjnum: number // 累计中奖数
  pertotal: number
  perday: number
  shareaddnum: number
  createtime: number
  // 详情独有
  guize?: string
  sharedaytimes?: number
  sharetimes?: number
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
  jxtp: number // 1实物 2红包
  jxmc: string
  linkman: string
  tel: string
  createtime: number
  status: number // 0未领奖 1已领
  code: string // 核销码
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
}

export interface LotterySaveParams {
  id?: number
  type: string
  name: string
  banner: string
  starttime: string
  endtime: string
  guize: string
  pertotal: number
  perday: number
  shareaddnum: number
  sharedaytimes: number
  sharetimes: number
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

export function getLotteryRecords(params: {
  page?: number
  pagesize?: number
  hid?: number | ''
  nickname?: string
  jxmc?: string
  status?: number | ''
  start_date?: string
  end_date?: string
}) {
  return request.get<unknown, PageResult<LotteryRecordItem>>('/admin/choujiang/records', {
    params,
  })
}

export function verifyLotteryRecord(id: number) {
  return request.post<unknown, { code: string }>('/admin/choujiang/recordSetst', { id })
}
