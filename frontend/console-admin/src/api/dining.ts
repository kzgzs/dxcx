import request from './request'

// ==================== 类型定义 ====================

/** 餐桌状态：-1禁用 0空闲 1预定 2入座 3清台 */
export type DiningTableStatus = -1 | 0 | 1 | 2 | 3

export interface DiningTableRow {
  id: number
  cid: number
  category_name: string
  name: string
  pic: string
  seat: number
  canbook: number
  status: DiningTableStatus
  status_text: string
  qrcode: string
  sort: number
  orderid: number
  print_ids: number[]
  create_time: number
}

export interface DiningTableListResult {
  list: DiningTableRow[]
  total: number
  page: number
  pagesize: number
}

export interface DiningTableSaveParams {
  id?: number
  name?: string
  cid?: number
  pic?: string
  seat?: number
  canbook?: number
  sort?: number
  print_ids?: number[] | string
}

/** 批量建桌参数 */
export interface DiningTableCreateParams {
  makecount: number
  prefix: string
  start: number
  not_have_number: string
  cid: number
  seat: number
  canbook: number
  print_ids?: number[]
}

export interface DiningTableCategoryRow {
  id: number
  name: string
  pic: string
  service_fee: number
  limit_fee: number
  booking_fee: number
  seat: number
  status: number
  sort: number
  table_count: number
}

/** 菜品行 */
export interface DiningDishRow {
  id: number
  name: string
  procode: string
  sellpoint: string
  pic: string
  pics: string[]
  cid: number[]
  cid_names: string
  area_id: number
  area_name: string
  market_price: number
  sell_price: number
  cost_price: number
  pack_fee: number
  sales: number
  stock: number
  stock_daily: number
  limit_per: number
  limit_takeaway: number
  limit_start: number
  status: number
  status_text: string
  status_week: string[]
  start_time: string
  end_time: string
  start_hours: string
  end_hours: string
  sort: number
  product_type: number
  onsale: number
  create_time: number
  detail?: string
  guige?: DiningGuigeRow[]
}

export interface DiningGuigeRow {
  id?: number
  name: string
  pic: string
  market_price: number
  cost_price: number
  sell_price: number
  stock: number
  stock_daily: number
  procode: string
}

export interface DiningDishListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  cid?: number
  /** 1=在售 0=未在售 空=全部 */
  status?: string
}

export interface DiningDishListResult {
  list: DiningDishRow[]
  total: number
  page: number
  pagesize: number
}

export interface DiningDishOptionsResult {
  categories: { id: number; pid: number; name: string; status: number }[]
  areas: { id: number; name: string }[]
}

/** 菜品分类行 */
export interface DiningProcatRow {
  id: number
  pid: number
  name: string
  pic: string
  is_shop: number
  is_takeaway: number
  is_booking: number
  status: number
  sort: number
  product_count: number
}

/** 堂食设置 */
export interface DiningShopSet {
  banner: string
  banner_show: number
  business_info_show: number
  comment_show: number
  open_restaurant_detail_status: number
  tea_fee_status: number
  tea_fee_text: string
  tea_fee: number
  start_hours: string
  end_hours: string
  paytype: string
  paytypes: string[]
  pay_after: number
  clean_table_status: number
  status: number
  autoshdays: number
  diancan_text: string
  cross_day?: number
}

/** 打印机行 */
export interface DiningPrinterRow {
  id: number
  name: string
  title: string
  type: number
  type_text: string
  client_id: string
  client_secret: string
  secret_masked: string
  machine_code: string
  msign: string
  status: number
  autoprint: number
  voice: number
  print_ps: number
  tmpltype: number
  tmplcontent: string
  width: number
  height: number
}

// ==================== 餐桌管理 ====================

export function getDiningTableList(params: { page?: number; pagesize?: number; keyword?: string; cid?: number; status?: string }) {
  return request.get<unknown, DiningTableListResult>('/admin/dining/table/list', { params })
}

export function saveDiningTable(data: DiningTableSaveParams) {
  return request.post<unknown, null>('/admin/dining/table/save', data)
}

export function deleteDiningTable(id: number) {
  return request.post<unknown, null>('/admin/dining/table/del', { id })
}

export function setDiningTableStatus(id: number, st: DiningTableStatus) {
  return request.post<unknown, null>('/admin/dining/table/setst', { id, st })
}

export function createDiningTables(data: DiningTableCreateParams) {
  return request.post<unknown, { count: number }>('/admin/dining/table/create', data)
}

export function getDiningTableQrcode(id: number) {
  return request.get<unknown, { id: number; name: string; qrcode: string }>('/admin/dining/table/qrcode', { params: { id } })
}

// ==================== 餐桌分类 ====================

export function getDiningTableCategoryList(keyword?: string) {
  return request.get<unknown, { list: DiningTableCategoryRow[] }>('/admin/dining/tablecat/list', { params: { keyword } })
}

export function saveDiningTableCategory(data: Partial<DiningTableCategoryRow>) {
  return request.post<unknown, { id: number }>('/admin/dining/tablecat/save', data)
}

export function deleteDiningTableCategory(id: number) {
  return request.post<unknown, null>('/admin/dining/tablecat/del', { id })
}

// ==================== 菜品管理 ====================

export function getDiningDishList(params: DiningDishListQuery) {
  return request.get<unknown, DiningDishListResult>('/admin/dining/dish/list', { params })
}

export function getDiningDishDetail(id: number) {
  return request.get<unknown, DiningDishRow>('/admin/dining/dish/detail', { params: { id } })
}

export function getDiningDishOptions() {
  return request.get<unknown, DiningDishOptionsResult>('/admin/dining/dish/options')
}

export function saveDiningDish(data: Record<string, unknown>) {
  return request.post<unknown, { id: number }>('/admin/dining/dish/save', data)
}

export function deleteDiningDish(id: number) {
  return request.post<unknown, null>('/admin/dining/dish/del', { id })
}

export function setDiningDishStatus(id: number, st: number) {
  return request.post<unknown, null>('/admin/dining/dish/setst', { id, st })
}

export function setDiningDishStock(id: number, stock: number, stockDaily: number) {
  return request.post<unknown, null>('/admin/dining/dish/setstock', { id, stock, stock_daily: stockDaily })
}

// ==================== 菜品分类 ====================

export function getDiningProcatList(keyword?: string) {
  return request.get<unknown, { list: DiningProcatRow[] }>('/admin/dining/procat/list', { params: { keyword } })
}

export function saveDiningProcat(data: Partial<DiningProcatRow>) {
  return request.post<unknown, { id: number }>('/admin/dining/procat/save', data)
}

export function deleteDiningProcat(id: number) {
  return request.post<unknown, null>('/admin/dining/procat/del', { id })
}

// ==================== 堂食设置 ====================

export function getDiningShopSet() {
  return request.get<unknown, DiningShopSet>('/admin/dining/shopset')
}

export function saveDiningShopSet(data: Partial<DiningShopSet>) {
  return request.post<unknown, null>('/admin/dining/shopset', data)
}

// ==================== 打印机管理 ====================

export function getDiningPrinterList() {
  return request.get<unknown, { list: DiningPrinterRow[] }>('/admin/dining/printer/list')
}

export function saveDiningPrinter(data: Partial<DiningPrinterRow>) {
  return request.post<unknown, { id: number }>('/admin/dining/printer/save', data)
}

export function deleteDiningPrinter(id: number) {
  return request.post<unknown, null>('/admin/dining/printer/del', { id })
}

export function testDiningPrinter(id: number) {
  return request.post<unknown, null>('/admin/dining/printer/test', { id })
}
