import request from './request'

/* ================= 类型（P24-7 批次8 任务227：活动日历配置） ================= */

export interface HuodongCalendarSet {
  status: number
  title: string
  bgimg: string
  notice: string
  desc: string
}

/* ================= 接口 ================= */

export function getHuodongCalendarSet() {
  return request.get<unknown, HuodongCalendarSet>('/admin/huodongCalendar/get')
}

export function saveHuodongCalendarSet(data: Record<string, unknown>) {
  return request.post<unknown, null>('/admin/huodongCalendar/save', data)
}
