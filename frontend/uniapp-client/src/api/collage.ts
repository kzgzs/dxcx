/**
 * 拼团 / 幸运拼团 C 端接口封装（任务236，pagesH1 分包消费）
 * - GET 走默认信封；POST 显式 urlencoded（后端 post() 不解析 JSON body）
 */
import { get, request } from "../utils/request";

/** 后端 POST 参数仅识别表单编码，显式声明避免 uni.request 默认 JSON */
const FORM_HEADER = { "Content-Type": "application/x-www-form-urlencoded" };

/* ================= 通用结构 ================= */

export interface BizPageResult<T> {
  list: T[];
  total: number;
  page: number;
  pagesize: number;
}

/** 团员/下单人信息（机器人带标记；幸运拼团附带中奖态） */
export interface BizBuyer {
  mid: number;
  nickname: string;
  headimg: string;
  isjiqiren: number;
  iszj?: number;
  iszj_text?: string;
}

/** 团信息（拼团/幸运拼团同构） */
export interface BizTeam {
  id: number;
  proid: number;
  product_name: string;
  teamnum: number;
  num: number;
  status: number;
  status_text: string;
  members: BizBuyer[];
  createtime: number;
  deadline: number;
}

/** 商品规格行 */
export interface BizSpec {
  id: number;
  name: string;
  sell_price: number;
  market_price: number;
  cost_price: number;
  stock: number;
}

/* ================= 拼团（collage/*） ================= */

export interface CollageProduct {
  id: number;
  name: string;
  pic: string;
  sellpoint: string;
  sell_price: number;
  market_price: number;
  stock: number;
  sales: number;
  teamnum: number;
  teamhour: number;
  leadermoney: number;
  buymax: number;
  status: number;
  ischecked: number;
  detail: string;
  createtime: number;
}

export interface CollageProductDetail extends CollageProduct {
  options: BizSpec[];
  teams: BizTeam[];
}

export interface CollageOrderTeamBrief {
  id: number;
  status: number;
  status_text: string;
  teamnum: number;
  num: number;
}

/** 拼团订单（formatOrder 口径） */
export interface CollageOrder {
  id: number;
  ordernum: string;
  buytype: number;
  buytype_text: string;
  teamid: number;
  team: CollageOrderTeamBrief | null;
  proname: string;
  propic: string;
  ggname: string;
  num: number;
  sell_price: number;
  totalprice: number;
  refund_money: number;
  status: number;
  status_text: string;
  refund_status: number;
  refund_text: string;
  buyer: BizBuyer;
  linkman: string;
  tel: string;
  address: string;
  express_com: string;
  express_no: string;
  paytype: string;
  paytime: number;
  send_time: number;
  createtime: number;
}

/** 下单返回（PAY_MOCK 下 status 直接为已支付） */
export interface BizCreateResult {
  orderid: number;
  ordernum: string;
  totalprice: number;
  status: number;
  teamid: number;
}

/** 下单入参 */
export interface BizOrderCreateParams {
  proid: number;
  buytype: number;
  teamid?: number;
  ggid?: number;
  num?: number;
  linkman?: string;
  tel?: string;
  area?: string;
  address?: string;
  message?: string;
}

/** 拼团商品列表 GET /api/collage/product/list */
export function fetchCollageProducts(params: { page?: number; pagesize?: number; keyword?: string }) {
  return get<BizPageResult<CollageProduct>>("/api/collage/product/list", {
    page: params.page || 1,
    pagesize: params.pagesize || 10,
    keyword: params.keyword || "",
  });
}

/** 拼团商品详情（含进行中团） GET /api/collage/product/detail */
export function fetchCollageProductDetail(id: number) {
  return get<CollageProductDetail>("/api/collage/product/detail", { id });
}

/** 某商品进行中团 GET /api/collage/team/list */
export function fetchCollageTeams(proid: number, limit = 10) {
  return get<BizTeam[]>("/api/collage/team/list", { proid, limit });
}

/** 团详情 GET /api/collage/team/detail */
export function fetchCollageTeamDetail(teamid: number) {
  return get<BizTeam>("/api/collage/team/detail", { teamid });
}

/** 拼团下单 POST /api/collage/order/create */
export function collageOrderCreate(params: BizOrderCreateParams) {
  return request<BizCreateResult>({
    url: "/api/collage/order/create",
    method: "POST",
    data: { ...params, platform: "h5" },
    header: FORM_HEADER,
  });
}

/** 我的拼团订单 GET /api/collage/order/list */
export function fetchCollageOrders(status: string, page: number, pagesize = 10) {
  return get<BizPageResult<CollageOrder>>("/api/collage/order/list", { status, page, pagesize });
}

/* ================= 幸运拼团（luckyCollage/*） ================= */

export interface LuckyCollageProduct {
  id: number;
  name: string;
  pic: string;
  pics: string;
  sellpoint: string;
  sell_price: number;
  market_price: number;
  stock: number;
  sales: number;
  teamnum: number;
  teamhour: number;
  leadermoney: number;
  buymax: number;
  status: number;
  ischecked: number;
  detail: string;
  gua_num: number;
  fy_type: number;
  fy_money: number;
  fy_money_val: number;
  tklx: number;
  zjjl_type: number;
  createtime: number;
}

export interface LuckyCollageProductDetail extends LuckyCollageProduct {
  options: BizSpec[];
  teams: BizTeam[];
}

/** 幸运拼团订单（formatOrder 口径，含中奖态） */
export interface LuckyCollageOrder extends Omit<CollageOrder, "team"> {
  iszj: number;
  iszj_text: string;
  money: number;
  isjiqiren: number;
}

/** 开奖结果 GET /api/luckyCollage/order/drawResult */
export interface LuckyDrawResult {
  order: LuckyCollageOrder;
  team: BizTeam | null;
  members: BizBuyer[];
}

/** 幸运拼团商品列表 GET /api/luckyCollage/product/list */
export function fetchLuckyCollageProducts(params: { page?: number; pagesize?: number; keyword?: string }) {
  return get<BizPageResult<LuckyCollageProduct>>("/api/luckyCollage/product/list", {
    page: params.page || 1,
    pagesize: params.pagesize || 10,
    keyword: params.keyword || "",
  });
}

/** 幸运拼团商品详情（含进行中团） GET /api/luckyCollage/product/detail */
export function fetchLuckyCollageProductDetail(id: number) {
  return get<LuckyCollageProductDetail>("/api/luckyCollage/product/detail", { id });
}

/** 某商品进行中团 GET /api/luckyCollage/team/list */
export function fetchLuckyCollageTeams(proid: number, limit = 10) {
  return get<BizTeam[]>("/api/luckyCollage/team/list", { proid, limit });
}

/** 团详情 GET /api/luckyCollage/team/detail */
export function fetchLuckyCollageTeamDetail(teamid: number) {
  return get<BizTeam>("/api/luckyCollage/team/detail", { teamid });
}

/** 幸运拼团下单 POST /api/luckyCollage/order/create */
export function luckyCollageOrderCreate(params: BizOrderCreateParams) {
  return request<BizCreateResult>({
    url: "/api/luckyCollage/order/create",
    method: "POST",
    data: { ...params, platform: "h5" },
    header: FORM_HEADER,
  });
}

/** 我的幸运拼团订单 GET /api/luckyCollage/order/list */
export function fetchLuckyCollageOrders(status: string, page: number, pagesize = 10) {
  return get<BizPageResult<LuckyCollageOrder>>("/api/luckyCollage/order/list", { status, page, pagesize });
}

/** 开奖结果 GET /api/luckyCollage/order/drawResult */
export function fetchLuckyDrawResult(orderid: number) {
  return get<LuckyDrawResult>("/api/luckyCollage/order/drawResult", { orderid });
}
