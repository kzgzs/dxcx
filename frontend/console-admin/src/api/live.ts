import request from './request'

// ============ 小程序直播（批次7-6 任务214） ============

/** 直播间行 */
export interface LiveRoomRow {
  id: number
  roomId: number
  name: string
  coverImg: string
  shareImg: string
  feedsImg: string
  startTime: number
  endTime: number
  anchorName: string
  anchorWechat: string
  subAnchorWechat: string
  createrWechat: string
  isFeedsPublic: number
  type: number
  screenType: number
  closeLike: number
  closeGoods: number
  closeComment: number
  closeReplay: number
  closeShare: number
  closeKf: number
  qrcode_url: string
  live_status: string
  sort: number
  status: number
  createtime: number
  goods?: unknown[]
}

export interface LiveListQuery {
  page?: number
  pagesize?: number
  name?: string
}

// 直播间列表
export function liveList(params: LiveListQuery) {
  return request.get<unknown, { list: LiveRoomRow[]; total: number; nowtime: number }>('/admin/live/list', { params })
}

// 直播间详情
export function liveDetail(id: number) {
  return request.get<unknown, LiveRoomRow>('/admin/live/detail', { params: { id } })
}

/** 保存载荷（时间允许字符串，后端 strtotime 转换） */
export type LiveSavePayload = Partial<Omit<LiveRoomRow, 'startTime' | 'endTime'>> & {
  id?: number
  startTime?: string | number
  endTime?: string | number
}

// 新增/编辑直播间
export function liveSave(info: LiveSavePayload) {
  return request.post<unknown, { id: number }>('/admin/live/save', { info })
}

// 改显示状态
export function liveSetst(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/live/setst', { ids, st })
}

// 删除直播间
export function liveDel(ids: number[]) {
  return request.post<unknown, null>('/admin/live/del', { ids })
}

// 主播推流二维码（mock 返回占位图路径）
export function livePusherQr(id: number) {
  return request.post<unknown, { url: string; mock?: boolean }>('/admin/live/getpusherqr', { id })
}

// 从小程序中同步直播间
export function liveTongbu() {
  return request.post<unknown, null>('/admin/live/tongbu')
}

/** 直播商品库行（价格单位：元，后端 fen 转换后下发 price_yuan/price2_yuan） */
export interface LiveGoodsRow {
  id: number
  goodsId?: number
  goods_id?: number
  name: string
  coverImgUrl: string
  cover_img?: string
  url: string
  priceType: number
  price_type?: number
  price: number
  price2: number
  price_yuan: string
  price2_yuan: string
  audit_status?: number
  auditStatus?: number
}

/** 商品表单（价格单位：元） */
export interface LiveGoodsForm {
  id?: number
  goodsId?: number
  name: string
  coverImgUrl: string
  url: string
  priceType: number
  price: string | number
  price2?: string | number
}

// 商品库列表 st：1审核中 2审核通过 3审核驳回
export function liveGoodsList(params: { st: number; page?: number; pagesize?: number }) {
  return request.get<unknown, { list: LiveGoodsRow[]; total: number }>('/admin/live/goodslist', { params })
}

// 添加商品
export function liveGoodsAdd(info: LiveGoodsForm) {
  return request.post<unknown, null>('/admin/live/goodsadd', { info })
}

// 修改价格
export function liveGoodsUpd(goodsId: number, info: Pick<LiveGoodsForm, 'priceType' | 'price' | 'price2'>) {
  return request.post<unknown, null>('/admin/live/goodsupd', { info }, { params: { goodsId } })
}

// 删除商品
export function liveGoodsDel(goodsId: number) {
  return request.post<unknown, null>('/admin/live/goodsdel', { goodsId })
}

// 直播间添加商品
export function liveRoomAddgoods(id: number, ids: number[]) {
  return request.post<unknown, null>('/admin/live/roomaddgoods', { id, ids })
}
