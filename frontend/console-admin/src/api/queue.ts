import request from './request'

// ==================== 类型定义 ====================

/** 队列分类行 */
export interface QueueCategoryRow {
  id: number
  name: string
  code: string
  seat_min: number
  seat_max: number
  per_minute: number
  call_text: string
  sort: number
  status: number
  /** 当日排队中数量（列表接口附带） */
  waitnum?: number
}

/** 排队状态：0排队中 1已入座 2过号 3已取消 */
export type QueueStatus = 0 | 1 | 2 | 3

/** 排队记录行 */
export interface QueueRow {
  id: number
  cid: number
  mid: number
  date: string
  queue_no: string
  linkman: string
  tel: string
  renshu: string
  call_text: string
  status: QueueStatus
  create_time: number
  call_time: number
  expiry_time: number
  cname: string
  nickname: string
}

export interface QueueListResult {
  list: QueueRow[]
  total: number
  page: number
  pagesize: number
}

export interface QueueListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: string
  cid?: number
  date?: string
}

/** 排队设置（restaurant_queue_sysset） */
export interface QueueSysset {
  id: number
  screen_title: string
  screen_pic: string
  screen_desc: string
  start_hours: string
  end_hours: string
  status: number
}

/** 大屏轮询返回（匿名接口 /api/restaurantQueue/getqueuelist） */
export interface QueueScreenData {
  set: {
    screen_title: string
    screen_pic: string
    screen_desc: string
    status: number
  }
  lastqueue: {
    id: number
    queue_no: string
    call_text: string
    call_time?: number
  }
  waitlist: {
    id: number
    queue_no: string
    cid: number
    renshu: string
    create_time: number
  }[]
}

// ==================== B 端 - 队列分类 ====================

export function getQueueCategoryList() {
  return request.get<unknown, { list: QueueCategoryRow[] }>('/admin/restaurant/queue/category/list')
}

export function saveQueueCategory(data: Partial<QueueCategoryRow>) {
  return request.post<unknown, { id: number }>('/admin/restaurant/queue/category/save', data)
}

export function deleteQueueCategory(id: number) {
  return request.post<unknown, null>('/admin/restaurant/queue/category/del', { id })
}

// ==================== B 端 - 排队队列 ====================

export function getQueueList(params: QueueListQuery) {
  return request.get<unknown, QueueListResult>('/admin/restaurant/queue/list', { params })
}

/** 叫号：cid=叫分类最早一桌；id=重复叫指定号 */
export function callQueue(params: { cid?: number; id?: number }) {
  return request.post<unknown, { queue: QueueRow }>('/admin/restaurant/queue/jiaohao', params)
}

export function passQueue(id: number) {
  return request.post<unknown, null>('/admin/restaurant/queue/guohao', { id })
}

export function cancelQueue(id: number) {
  return request.post<unknown, null>('/admin/restaurant/queue/cancel', { id })
}

export function deleteQueue(id: number) {
  return request.post<unknown, null>('/admin/restaurant/queue/del', { id })
}

// ==================== B 端 - 排队设置 ====================

export function getQueueSet() {
  return request.get<unknown, QueueSysset>('/admin/restaurant/queue/set')
}

export function saveQueueSet(data: Partial<QueueSysset>) {
  return request.post<unknown, null>('/admin/restaurant/queue/set', data)
}

// ==================== 大屏轮询（匿名，aid 走 query） ====================

export function getQueueScreenData(aid: number) {
  return request.get<unknown, QueueScreenData>('/api/restaurantQueue/getqueuelist', {
    params: { aid },
    silentError: true,
  })
}

// ==================== 浏览器 TTS（语音降级方案） ====================

/** 使用 Web Speech API 播报叫号文字（不支持时静默降级为纯文字） */
export function speakCallText(text: string) {
  if (!text || typeof window === 'undefined' || !('speechSynthesis' in window)) return
  try {
    window.speechSynthesis.cancel()
    const utter = new SpeechSynthesisUtterance(text)
    utter.lang = 'zh-CN'
    utter.rate = 0.9
    window.speechSynthesis.speak(utter)
  } catch {
    // 播报失败不影响页面展示
  }
}
