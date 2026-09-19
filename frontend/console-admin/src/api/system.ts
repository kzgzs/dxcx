import request from './request'

// ============ 超管控制台：系统设置 / 版本 / 缓存 / 支付记录（原有导出） ============

// 系统版本信息（GET /console/system/version）
export interface SystemVersion {
  version: string
  php: string
  think: string
  upload_max_filesize: string
}

// 读取某组全局配置（group=wxpay|sxpay|system|base|remote）
export function getSystemConfig(group: string) {
  return request.get<unknown, Record<string, string>>('/console/system/config', {
    params: { group },
  })
}

// 保存某组全局配置
export function saveSystemConfig(group: string, data: Record<string, unknown>) {
  return request.post<unknown, null>('/console/system/config', { group, data })
}

// 当前系统版本号
export function getSystemVersion() {
  return request.get<unknown, SystemVersion>('/console/system/version')
}

// 查看敏感配置原文（密钥类字段）
export function revealSecret(group: string, field: string) {
  return request.get<unknown, { value: string }>('/console/system/revealSecret', {
    params: { group, field },
  })
}

// 微信支付证书上传（multipart 字段 file，返回站点相对路径）
export function uploadWxpayCert(file: File) {
  const fd = new FormData()
  fd.append('file', file)
  return request.post<unknown, { url: string }>('/console/system/uploadCert', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

// 清理缓存页勾选项 key
export type ClearItemKey = 'global' | 'console_system' | 'tenant_system' | 'tenant_shop' | 'tenant_merchant'

// 按勾选项清理缓存（返回每项清理明细）
export function clearCacheByItems(items: ClearItemKey[]) {
  return request.post<unknown, { details: { key: string; name: string; message: string }[] }>(
    '/console/system/clearCacheByItems',
    { items },
  )
}

// 配置分组下拉（测试获取缓存数据 - 设置分类）
export interface CacheGroupOption {
  group: string
  name: string
}

export function getCacheGroups() {
  return request.get<unknown, { groups: CacheGroupOption[] }>('/console/system/cacheGroups')
}

// 某分组下的参数列表（设置参数联动）
export function getCacheKeys(group: string) {
  return request.get<unknown, { keys: string[] }>('/console/system/cacheKeys', {
    params: { group },
  })
}

// 测试获取缓存数据结果
export interface CacheQueryResult {
  group: string
  key: string
  cache_hit: boolean
  cache_value: string | null
  db_value: string | null
  value: string
}

// 查询某配置参数的缓存值与库中值
export function queryCacheValue(group: string, key: string) {
  return request.get<unknown, CacheQueryResult>('/console/system/cacheQuery', {
    params: { group, key },
  })
}

// 微信支付记录（pay_transaction 流水，只读）
export interface WxpayLogItem {
  id: number
  aid: number
  tenant: string
  ordernum: string
  transaction_num: string
  paynum: string
  title: string
  money: string
  score: number | string
  type: string
  status: number
  paytypeid: number
  paytype: string
  paytype_text: string
  platform: string
  createtime: number
  paytime: number
  refund_money: string
  refund_time: number
}

export function getWxpayLogList(params: {
  page: number
  pagesize: number
  ordernum?: string
  transaction_num?: string
  status?: number
  paytypeid?: number
  start_date?: string
  end_date?: string
}) {
  return request.get<unknown, { list: WxpayLogItem[]; total: number }>('/console/wxpaylog/list', { params })
}

export function getWxpayLogDetail(id: number) {
  return request.get<unknown, WxpayLogItem>('/console/wxpaylog/detail', { params: { id } })
}

// ============ 管理员列表（admin_user） ============

export interface AdminUserItem {
  id: number
  un: string
  truename: string
  mobile: string
  groupid: number
  groupname: string
  bids: string
  status: number
  remark: string
  isadmin: number
  is_main: number
  createtime: number
  logintime: number
  addname: string
  mid: number
  nickname: string
  headimg: string
}

export interface AdminUserSaveParams {
  id?: number
  un: string
  pwd?: string
  truename?: string
  mobile?: string
  groupid?: number
  bids?: number[]
  status?: number
  remark?: string
  mid?: number
}

// 管理员账号列表
export function getAdminUserList(params: { page: number; pagesize: number; keyword?: string }) {
  return request.get<unknown, { list: AdminUserItem[]; total: number }>('/admin/adminUser/list', { params })
}

// 新增/编辑管理员账号
export function saveAdminUser(data: AdminUserSaveParams) {
  return request.post<unknown, { id?: number }>('/admin/adminUser/save', data)
}

// 删除管理员账号
export function deleteAdminUser(id: number) {
  return request.post<unknown, null>('/admin/adminUser/delete', { id })
}

// 解绑管理员关联会员（置 mid=0）
export function unbindAdminUserMember(id: number) {
  return request.post<unknown, null>('/admin/adminUser/jiebang', { id })
}

// 管理员绑定会员（校验会员存在）
export function bindAdminUserMember(id: number, mid: number) {
  return request.post<unknown, null>('/admin/adminUser/bindmid', { id, mid })
}

// 手机端管理登录地址（H5 管理助手入口）
export function getAdminUserLoginUrl() {
  return request.get<unknown, { url: string; path: string }>('/admin/adminUser/loginurl')
}

// ============ 角色权限（admin_user_group） ============

export interface UserGroupItem {
  id: number
  name: string
  sort: number
  status: number
  remark: string
  createtime: number
  auth_mode: 'all' | 'keys'
  auth_count: number
  member_count: number
}

export interface UserGroupDetail {
  id: number
  name: string
  sort: number
  status: number
  remark: string
  auth_mode: 'all' | 'keys'
  auth_keys: string[]
}

// 权限树节点（{name, path(=菜单key), child?}，hide=按钮级权限点）
export interface AuthTreeNode {
  name: string
  path: string
  hide?: boolean
  child?: AuthTreeNode[]
}

export interface UserGroupSaveParams {
  id?: number
  name: string
  sort?: number
  status?: number
  remark?: string
  auth_mode: 'all' | 'keys'
  auth_keys?: string[]
}

// 权限组列表
export function getUserGroupList(params: { page: number; pagesize: number; name?: string }) {
  return request.get<unknown, { list: UserGroupItem[]; total: number }>('/admin/userGroup/list', { params })
}

// 权限组详情（含勾选 key 集合）
export function getUserGroupDetail(id: number) {
  return request.get<unknown, UserGroupDetail>('/admin/userGroup/detail', { params: { id } })
}

// 新增/编辑权限组
export function saveUserGroup(data: UserGroupSaveParams) {
  return request.post<unknown, { id?: number }>('/admin/userGroup/save', data)
}

// 删除权限组
export function deleteUserGroup(id: number) {
  return request.post<unknown, null>('/admin/userGroup/delete', { id })
}

// 权限树（租户客户端菜单全量）
export function getUserGroupMenuTree() {
  return request.get<unknown, AuthTreeNode[]>('/admin/userGroup/menuTree')
}

// ============ 短信设置（admin_set_sms + smslog） ============

// 短信配置（白名单字段动态结构，模板字段 tmpl_xxx / tmpl_xxx_st）
export type SmsConfig = Record<string, string | number>

export interface SmsLogItem {
  id: number
  PhoneNumbers: string
  SignName: string
  TemplateCode: string
  TemplateContent: string
  status: number
  err_msg: string
  createtime: number
}

// 读取短信配置
export function getSmsSet() {
  return request.get<unknown, SmsConfig>('/admin/sms/set')
}

// 保存短信配置
export function saveSmsSet(info: SmsConfig) {
  return request.post<unknown, null>('/admin/sms/saveSet', { info })
}

// 发送记录列表
export function getSmsSendlog(params: {
  page: number
  pagesize: number
  status?: number | ''
  phone?: string
  start?: string
  end?: string
}) {
  return request.get<unknown, { list: SmsLogItem[]; total: number }>('/admin/sms/sendlog', { params })
}

// 测试发送
export function testSms(mobile: string, tmplKey: string) {
  return request.post<unknown, null>('/admin/sms/test', { mobile, tmpl_key: tmplKey })
}

// ============ 店铺评价（business_comment） ============

export interface BusinessCommentItem {
  id: number
  bid: number
  bname: string
  mid: number
  nickname: string
  headimg: string
  score: number
  content: string
  content_pic: string
  reply_content: string
  reply_time: number
  status: number
  createtime: number
}

export interface BusinessCommentListResult {
  list: BusinessCommentItem[]
  total: number
  business_options: { id: number; name: string }[]
}

// 评价列表（含商户下拉）
export function getBusinessCommentList(params: {
  page: number
  pagesize: number
  bid?: number | string
  content?: string
  status?: number | ''
  start?: string
  end?: string
  field?: string
  order?: string
}) {
  return request.get<unknown, BusinessCommentListResult>('/admin/businessComment/list', { params })
}

// 评价详情
export function getBusinessCommentDetail(id: number) {
  return request.get<unknown, BusinessCommentItem & { content_pic: string[] }>(
    '/admin/businessComment/detail',
    { params: { id } },
  )
}

// 回复评价
export function replyBusinessComment(id: number, content: string) {
  return request.post<unknown, null>('/admin/businessComment/reply', { id, content })
}

// 审核（批量改状态并回算商户均分）
export function setBusinessCommentSt(ids: number[], status: number) {
  return request.post<unknown, null>('/admin/businessComment/setst', { ids, status })
}

// 删除评价（批量）
export function deleteBusinessComment(ids: number[]) {
  return request.post<unknown, null>('/admin/businessComment/delete', { ids })
}

// ============ 操作日志（plog） ============

export interface PlogItem {
  id: number
  uid: number
  un: string
  remark: string
  ip: string
  createtime: number
}

export interface PlogListResult {
  list: PlogItem[]
  total: number
  user_options: { id: number; un: string }[]
  can_filter: number
}

// 日志列表（主账号可看全租户并按成员过滤，子账号仅看本人）
export function getPlogList(params: {
  page: number
  pagesize: number
  uid?: number | ''
  remark?: string
  start?: string
  end?: string
}) {
  return request.get<unknown, PlogListResult>('/admin/plog/list', { params })
}

// 删除日志（批量）
export function deletePlog(ids: number[]) {
  return request.post<unknown, null>('/admin/plog/delete', { ids })
}

// 导出日志 CSV（blob 下载，带 token）
export function exportPlog(params: { uid?: number | ''; remark?: string; start?: string; end?: string }) {
  return request.get('/admin/plog/export', {
    params,
    responseType: 'blob',
    timeout: 120000,
  }) as Promise<Blob>
}

// ============ 快捷菜单（shortcut_menu） ============

export interface ShortcutMenuItem {
  name: string
  key: string
}

// 读取当前用户快捷菜单（后端已按权限过滤）
export function getShortcutMenu() {
  return request.get<unknown, { menus: ShortcutMenuItem[] }>('/admin/shortcutMenu/index')
}

// 保存当前用户快捷菜单
export function saveShortcutMenu(menus: ShortcutMenuItem[]) {
  return request.post<unknown, null>('/admin/shortcutMenu/save', { menus })
}
