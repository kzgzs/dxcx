import request from './request'

// ============ 类型定义 ============

/** 组件统一结构：{id:'M'+时间戳, temp, params, data} */
export interface DesignerModule {
  id: string
  temp: string
  params: Record<string, any>
  data?: any[]
  content?: string
  [key: string]: any
}

/** 页面列表行 */
export interface DesignerPageRow {
  id: number
  name: string
  title: string
  ishome: number
  type: number
  create_time: string
  update_time: string
}

/** 页面详情（编辑器回显） */
export interface DesignerPageDetail {
  id: number
  name: string
  ishome: number
  pageinfo: DesignerModule[]
  content: DesignerModule[]
}

/** 模板库行（任务276：下发 pageinfo/content 供卡片真实预览渲染） */
export interface DesignerTemplateRow {
  id: number
  /** 租户ID：0平台模板（禁删），>0 租户自建模板 */
  aid: number
  name: string
  title: string
  type: number
  remark: string
  update_time: string
  pageinfo?: DesignerModule[]
  content?: DesignerModule[]
}

// ============ 页面接口 ============

// 页面列表（type 筛选：0 常规 / 2 外卖页 / 3 点餐页；name 标题搜索，任务275）
export function getDesignerPageList(params: { type?: number; ishome?: number | ''; page?: number; limit?: number; name?: string }) {
  return request.get<unknown, { count: number; list: DesignerPageRow[] }>('/admin/designer/page/list', { params })
}

// 页面详情（编辑器回显 pageinfo + content）
export function getDesignerPageDetail(id: number) {
  return request.get<unknown, DesignerPageDetail>('/admin/designer/page/detail', { params: { id } })
}

// 保存页面（保存即发布，无草稿；type 0常规/2外卖页/3点餐页，仅新建时生效）
export function saveDesignerPage(data: { id?: number; pageinfo: DesignerModule[]; content: DesignerModule[]; type?: number }) {
  return request.post<unknown, { id: number }>('/admin/designer/page/save', data)
}

// 删除页面
export function deleteDesignerPage(id: number) {
  return request.post<unknown, null>('/admin/designer/page/del', { id })
}

// 复制页面
export function copyDesignerPage(id: number) {
  return request.post<unknown, { id: number }>('/admin/designer/page/copy', { id })
}

// 设为首页（后端先把同租户同标记的其他页清零，保证唯一）
export function setDesignerPageHome(id: number, ishome = 1) {
  return request.post<unknown, null>('/admin/designer/page/sethome', { id, ishome })
}

// 取消首页
export function unsetDesignerPageHome(id: number) {
  return request.post<unknown, null>('/admin/designer/page/rehome', { id })
}

// ============ 模板接口 ============

// 模板库列表
export function getDesignerTemplateList() {
  return request.get<unknown, { list: DesignerTemplateRow[] }>('/admin/designer/template/list')
}

// 套用模板（复制模板 pageinfo/content 到新页，返回新页 id；type 0常规/2外卖页/3点餐页）
export function applyDesignerTemplate(id: number, type = 0) {
  return request.post<unknown, { id: number }>('/admin/designer/template/apply', { id, type })
}

// 存为模板（我的页面 → 当前租户模板，任务250）
export function saveDesignerTemplate(pageid: number, name: string) {
  return request.post<unknown, { id: number }>('/admin/designer/template/save', { pageid, name })
}

// 删除模板（仅本租户模板可删，平台模板后端拒绝，任务250）
export function deleteDesignerTemplate(id: number) {
  return request.post<unknown, null>('/admin/designer/template/del', { id })
}

// 同步模板（任务276：清空平台内置模板重插内置两套，保留租户自建模板）
export function syncDesignerTemplates() {
  return request.post<unknown, null>('/admin/designer/template/sync')
}

// ============ 选项卡子内容（designerpage_tab，tabid=tab组件id tabindexid=选项项id，任务250） ============

// 读取选项子内容（组件数组）
export function getDesignerTabContent(tabid: string, tabindexid: string) {
  return request.get<unknown, { tabid: string; tabindexid: string; content: DesignerModule[] }>('/admin/designer/tab/get', { params: { tabid, tabindexid } })
}

// 保存选项子内容（组件数组）
export function saveDesignerTabContent(tabid: string, tabindexid: string, content: DesignerModule[]) {
  return request.post<unknown, null>('/admin/designer/tab/save', { tabid, tabindexid, content })
}

// ============ 底部导航（designer_menu，微信 tabBar 结构，按 platform 分行） ============

/** tabBar 单项 {text,pagePath,iconPath,selectedIconPath,pagePathname} */
export interface NavTabItem {
  text: string
  pagePath: string
  iconPath: string
  selectedIconPath: string
  pagePathname: string
}

/** tabBar 整体结构（对齐参考系统 menudata 口径；midbutton 中间按钮突出 0关 1开，任务279） */
export interface NavMenudata {
  color: string
  selectedColor: string
  backgroundColor: string
  borderStyle: string
  position: string
  midbutton?: number
  list: NavTabItem[]
}

/** 底部导航读取返回 */
export interface NavMenuInfo {
  id: number
  platform: string
  tongbu: number
  indexurl: string
  menucount: number
  menudata: NavMenudata
}

export function getDesignerMenu(platform: string) {
  return request.get<unknown, NavMenuInfo>('/admin/designer/menu/get', { params: { platform } })
}

export function saveDesignerMenu(info: { platform: string; tongbu: number; indexurl: string; menudata: NavMenudata }) {
  return request.post<unknown, null>('/admin/designer/menu/save', { info })
}

// ============ 内页导航（designer_menu2，多条 CRUD + 复制 + 启停） ============

/** 内页导航单项 {text,iconPath,pagePath,color}（color 单菜单文字颜色，任务280） */
export interface Menu2Item {
  text: string
  iconPath: string
  pagePath: string
  color?: string
}

export interface Menu2Row {
  id: number
  indexurlname: string
  indexurl: string
  platform: string
  backgroundColor: string
  menucount: number
  status: number
  create_time?: string
  createtime?: number
  menudata?: Menu2Item[]
}

export function getMenu2List(params: { page?: number; limit?: number; indexurlname?: string; indexurl?: string; platform?: string; status?: number | '' }) {
  return request.get<unknown, { count: number; list: Menu2Row[] }>('/admin/designer/menu2/list', { params })
}

export function getMenu2Detail(id: number) {
  return request.get<unknown, Menu2Row>('/admin/designer/menu2/detail', { params: { id } })
}

export function saveMenu2(info: Partial<Menu2Row> & { menudata: Menu2Item[] }) {
  return request.post<unknown, { id: number }>('/admin/designer/menu2/save', { info })
}

export function setstMenu2(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/designer/menu2/setst', { ids, st })
}

export function delMenu2(ids: number[]) {
  return request.post<unknown, null>('/admin/designer/menu2/del', { ids })
}

export function copyMenu2(id: number) {
  return request.post<unknown, { id: number }>('/admin/designer/menu2/copy', { id })
}

// ============ 商品详情导航（designer_shopdetail，三固定槽位：1客服/2购物车/3收藏） ============

/** 详情页底部栏槽位项 */
export interface ShopdetailSlot {
  text: string
  pagePath: string
  iconPath: string
  selectedIconPath: string
  pagePathname: string
  isShow: number
  menuType: number
  useSystem?: number
  selectedtext?: string
}

export interface ShopdetailMenudata {
  color: string
  selectedColor: string
  backgroundColor: string
  borderStyle: string
  position: string
  list: ShopdetailSlot[]
}

export interface ShopdetailInfo {
  id: number
  menudata: ShopdetailMenudata
  navigationBarBackgroundColor: string
  navigationBarTextStyle: string
  /** 公共底部商品描述富文本（任务281） */
  description: string
  /** 公共底部描述补充图 */
  description_img: string
  /** 分享海报：1需要登录 0无需登录 */
  share_login: number
}

export function getShopdetail() {
  return request.get<unknown, ShopdetailInfo>('/admin/designer/shopdetail/get')
}

export function saveShopdetail(info: {
  menudata: ShopdetailMenudata
  navigationBarBackgroundColor: string
  navigationBarTextStyle: string
  description: string
  description_img: string
  share_login: number
}) {
  return request.post<unknown, null>('/admin/designer/shopdetail/save', { info })
}

// ============ 登录页装修（designer_login，对照参考口径：页面样式一/二 + 主题字段） ============

/** 登录页主题字段（背景/标题/按钮/验证码/协议/注册与其他登录方式） */
export interface LoginTheme {
  logo: string
  bgtype: number
  bgcolor: string
  bgimg: string
  cardcolor: string
  titletype: string
  title: string
  titlecolor: string
  subhead: string
  subheadcolor: string
  /** 1跟随主题色 / 2自定义（自定义时用 btncolor） */
  btntype: number
  btncolor: string
  btnwordcolor: string
  codecolor: string
  regtipcolor: string
  regpwdbtncolor: string
  /** 注册账号文字颜色（预览用） */
  regbtncolor: string
  /** 微信授权登录图标（空走默认） */
  wxicon: string
  /** 微信授权登录文案（默认 授权登录） */
  wxtext: string
  xytipword: string
  xytipcolor: string
  xycolor: string
}

export interface LoginDesignInfo {
  id: number
  /** 页面样式：1样式一 / 2样式二 */
  type: number
  updatetime: number
  data: LoginTheme
  /** 系统主题色（admin_set.color1，btntype=1 预览跟随用） */
  themecolor?: string
}

export function getLoginDesign() {
  return request.get<unknown, LoginDesignInfo>('/admin/designer/login/get')
}

export function saveLoginDesign(info: { id?: number; type?: number; data: LoginTheme }) {
  return request.post<unknown, { id: number }>('/admin/designer/login/save', { info })
}

// ============ 分享设置（designer_share，CRUD + 启停） ============

export interface ShareRow {
  id: number
  indexurlname: string
  indexurl: string
  title: string
  desc: string
  pic: string
  url: string
  platform: string
  is_rootpath: number
  status: number
  create_time?: string
  createtime?: number
}

export function getShareList(params: { page?: number; limit?: number; indexurlname?: string; indexurl?: string; platform?: string; status?: number | '' }) {
  return request.get<unknown, { count: number; list: ShareRow[] }>('/admin/designer/share/list', { params })
}

export function getShareDetail(id: number) {
  return request.get<unknown, ShareRow>('/admin/designer/share/detail', { params: { id } })
}

export function saveShare(info: Partial<ShareRow>) {
  return request.post<unknown, { id: number }>('/admin/designer/share/save', { info })
}

export function setstShare(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/designer/share/setst', { ids, st })
}

export function delShare(ids: number[]) {
  return request.post<unknown, null>('/admin/designer/share/del', { ids })
}
