import request from './request'
import type { DashboardData } from './types'

// 控制台统计数据
export function getDashboard() {
  return request.get<unknown, DashboardData>('/console/dashboard')
}
