import { get, post } from "../utils/request";

/* ================= 设计器页面（任务164 后端口径） ================= */

/** 设计器组件节点：{id,temp,params,data} */
export interface DpNode {
  id: string;
  temp: string;
  /** 组件配置（设计器保存的原始键值，类型宽松） */
  params: Record<string, unknown>;
  /** 组件数据（banner/notice/menu 等静态数据，product/coupon 可为空数组） */
  data: unknown;
  /** 富文本等附加内容（richtext temp 使用，任务242 起由 DpRichText 消费） */
  content?: string;
}

/** topbar 节点参数（pageinfo 首项；任务305 页面信息设置全量字段） */
export interface TopbarParams {
  title?: string;
  /** 背景：bgmode 0纯色/1渐变；bgcolor 纯色/渐变色1；bgcolor2 渐变色2；bgdir 0从上到下/1从左到右（任务307） */
  bgmode?: string | number;
  bgcolor?: string;
  bgcolor2?: string;
  bgdir?: string | number;
  /** 导航背景：navbgmode 0跟随/1自定义；navbgcolor 自定义色 */
  navbgmode?: string | number;
  navbgcolor?: string;
  /** 导航文字：navtextmode 0跟随/1白色/2黑色 */
  navtextmode?: string | number;
  needlogin?: string | number;
  /** 查看权限：{all:true}|{member:true}|{dealer:true} 复选 */
  viewperm?: Record<string, unknown>;
  /** 付费查看：payview 0否/1是；payviewprice 金额（元） */
  payview?: string | number;
  payviewprice?: string | number;
  /** 开屏广告：showgg 0关闭/1图片；gglist 首项 {image,url} */
  showgg?: string | number;
  gglist?: Array<{ image?: string; url?: string }>;
  /** 任务306：开屏广告展示人群复选 {all/followed/unfollowed/member/dealer} */
  ggcrowd?: Record<string, unknown>;
  /** 任务306：展示方式 0首次/1每次 */
  ggmode?: string | number;
  [key: string]: unknown;
}

/** 页面级访问判定（任务305，随 /api/designer/page 下发） */
export interface PageAccess {
  /** 强制登录通过（false=需登录未登录） */
  needlogin_ok?: boolean;
  /** 查看权限通过 */
  perm_ok?: boolean;
  /** 付费查看开关 0/1 */
  payview?: number;
  /** 付费金额（元） */
  price?: string;
  /** 当前会员是否已购买 */
  paid?: boolean;
  /** 会员等级 ID（任务306：C 端广告人群判定用） */
  levelid?: number;
  /** 开屏广告展示人群（任务306） */
  ggcrowd?: Record<string, unknown>;
  /** 展示方式 0首次/1每次（任务306） */
  ggmode?: string;
}

/** GET /api/designer/page 返回结构（无首页时为空结构） */
export interface DesignerPage {
  id: number;
  name: string;
  ishome: number;
  /** 首项固定为 topbar 节点 */
  pageinfo: DpNode[];
  content: DpNode[];
  /** 页面级访问判定（任务305） */
  access?: PageAccess;
}

/** 取设计页：不传 id 时后端按 ishome 口径取标记页（1 首页，2 会员中心页，P27-4 任务248）；无命中返回空结构 */
export function fetchDesignerPage(id?: number, ishome?: number) {
  const params: Record<string, unknown> = {};
  if (id) params.id = id;
  if (ishome && ishome !== 1) params.ishome = ishome;
  return get<DesignerPage>("/api/designer/page", params);
}

/** 取模板（任务278：B端模板卡二维码 pages/index/main?tid=xx 预览）；结构与 fetchDesignerPage 对齐，模板不存在后端报错 */
export function fetchDesignerTemplate(tid: number) {
  return get<DesignerPage>("/api/designer/template", { tid });
}

/** GET /api/designer/tab/get 返回结构（任务332：选项卡子内容，无配置时 content 为空数组） */
export interface DesignerTabContent {
  tabid: string;
  tabindexid: string;
  /** 子内容组件数组（口径同页面 content 节点） */
  content: DpNode[];
}

/** 取选项卡子内容（任务332）：tabid=tab组件id，tabindexid=选项项id */
export function fetchDesignerTabContent(tabid: string | number, tabindexid: string | number) {
  return get<DesignerTabContent>("/api/designer/tab/get", { tabid, tabindexid });
}

/** 付费查看下单 POST /api/designer/pagebuy（任务305；已购买返回 paid=1） */
export function pageBuy(pageId: number) {
  return post<{ paid: number; orderid: number; price?: number }>("/api/designer/pagebuy", { page_id: pageId });
}

/** 付费查看支付 POST /api/designer/pagepay（PAY_MOCK=1 时直接入账放行） */
export function pagePay(orderid: number, paytype: string = "wechat") {
  return post<{ mock?: boolean; status?: number }>("/api/designer/pagepay", { orderid, paytype });
}

/* ================= 系统配置下发 ================= */

/** 商品详情底部栏槽位（任务281：designer_shopdetail menudata.list 单项） */
export interface ShopdetailSlot {
  text: string;
  pagePath: string;
  iconPath: string;
  selectedIconPath: string;
  pagePathname?: string;
  isShow: number;
  /** 1客服 2购物车 3收藏 4其它 */
  menuType: number;
  /** 客服槽位：1跟随系统客服链接 0自定义 */
  useSystem?: number;
  selectedtext?: string;
}

/** 商品详情底部配置（DesignerService::fetchShopdetail 口径，无配置时后端返回空对象） */
export interface ShopdetailConfig {
  list?: ShopdetailSlot[];
  /** 公共底部商品描述富文本 */
  description?: string;
  /** 公共底部描述补充图 */
  description_img?: string;
  /** 分享海报：1需要登录 0无需登录 */
  share_login?: number;
}

/** 系统前端门控（任务308：随 /api/index/sysset 下发） */
export interface SysGate {
  /** 状态 0关停 1开启 */
  status: number;
  close_tip: string;
  logged: boolean;
  levelid: number;
  perm_ok: boolean;
}

/** 最近购买记录（订单播报，任务308） */
export interface RecentOrder {
  nickname: string;
  headimg: string;
  title: string;
  createtime: number;
}

/** GET /api/index/sysset 返回结构（DesignerService::sysset 口径） */
export interface IndexSysset {
  name: string;
  logo: string;
  desc: string;
  tel: string;
  kfurl: string;
  wxkf: number;
  wxkfurl: string;
  color1: string;
  color2: string;
  status: number;
  close_tip: string;
  province: string;
  city: string;
  district: string;
  address: string;
  longitude: string;
  latitude: string;
  moneypay: number;
  recharge: number;
  score2money: string;
  invoice: number;
  /** 任务308：公众号关注组件 0关 1开 */
  official_account_status?: number;
  /** 任务308：关注提示 CSV 1首页 2商品详情页 */
  guanzhu_tip?: string;
  /** 任务308：订单播报 CSV 1首页 2商品详情页 */
  order_broadcast?: string;
  /** 任务308：导航背景 0跟随主色调 1自定义 */
  navbgmode?: number;
  navbgcolor?: string;
  /** 任务308：导航文字 0黑 1白 */
  navtextcolor?: number;
  /** 任务308：前端访问条件 CSV */
  visitperm?: string;
  /** 任务308：前端门控 */
  gate?: SysGate;
  shop: Record<string, unknown>;
  platform: Record<string, unknown>;
  nav: unknown[];
  /** 商品详情底部配置（任务281）；无配置时为空对象 */
  shopdetail?: ShopdetailConfig;
}

export function fetchIndexSysset() {
  return get<IndexSysset>("/api/index/sysset");
}

/** 最近购买记录 GET /api/index/recentOrders（订单播报滚动条数据源，任务308） */
export function fetchRecentOrders() {
  return get<{ list: RecentOrder[] }>("/api/index/recentOrders");
}

/* ================= 登录页装修（任务171：designer_login 19 主题字段） ================= */

/** GET /api/designer/login 返回结构（B 端登录页装修保存的 19 字段；未配置时后端返回空对象） */
export interface DesignerLoginData {
  logo?: string;
  /** 1 纯色背景 / 2 图片背景 */
  bgtype?: number;
  bgcolor?: string;
  bgimg?: string;
  cardcolor?: string;
  /** center 居中 / left 居左 */
  titletype?: string;
  title?: string;
  titlecolor?: string;
  subhead?: string;
  subheadcolor?: string;
  /** 1 圆角胶囊 / 2 直角矩形 */
  btntype?: number;
  btncolor?: string;
  btnwordcolor?: string;
  codecolor?: string;
  regtipcolor?: string;
  regpwdbtncolor?: string;
  xytipword?: string;
  xytipcolor?: string;
  xycolor?: string;
}

/** 取登录页装修：未配置返回空对象，登录页走默认样式 */
export function fetchDesignerLogin() {
  return get<DesignerLoginData>("/api/designer/login");
}
