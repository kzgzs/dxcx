import request from './request'
import type {
  AdminDashboardData,
  SalesStatQuery,
  GoodsSalesQuery,
  GoodsSalesResult,
  SalesStatResult,
  TrendResult,
} from './types'

// 租户首页面板汇总（订单状态/今日收款/会员/退款）
export function getAdminDashboard() {
  return request.get<unknown, AdminDashboardData>('/admin/dashboard')
}

// 近 N 天趋势（订单金额/订单数/新增会员）
export function getTrend(days = 30) {
  return request.get<unknown, TrendResult>('/admin/stat/trend', {
    params: { days },
  })
}

// 销售统计（年→月→日 三级钻取）
export function getSalesStat(params: SalesStatQuery) {
  return request.get<unknown, SalesStatResult>('/admin/stat/sales', { params })
}

// 商品销售统计（按商品 / 按规格：筛选、服务端排序、分页、汇总）
export function getGoodsSales(params: GoodsSalesQuery) {
  return request.get<unknown, GoodsSalesResult>('/admin/stat/goods_sales', { params })
}
