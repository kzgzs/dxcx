/**
 * pagesH2 营销分包（砍价/秒杀/积分商城）接口类型定义
 * 与后端 BargainService / SeckillService / ScoreshopService C 端口径对齐
 */

/* ================= 通用 ================= */

/** 分页信封 */
export interface PageResult<T> {
  list: T[];
  total: number;
  page: number;
  pagesize: number;
}

/** 下单结果（砍价/秒杀） */
export interface ActivityOrderResult {
  orderid: number;
  ordernum: string;
  totalprice: number;
  status: number;
}

/** 积分商城下单结果 */
export interface ScoreshopOrderResult {
  orderid: number;
  ordernum: string;
  totalscore: number;
  totalmoney: number;
  status: number;
}

/** PAY_MOCK 模拟回调结果 */
export interface MockPayResult {
  mock: boolean;
  status?: number;
}

/** 收货信息（三域下单共用） */
export interface ContactInfo {
  linkman: string;
  tel: string;
  area: string;
  address: string;
  message: string;
}

/* ================= 砍价 ================= */

/** 砍价商品（列表/详情） */
export interface KanjiaProduct {
  id: number;
  name: string;
  pic: string;
  pics?: string;
  detail?: string;
  sell_price: number;
  min_price: number;
  helpnum: number;
  limitHour: number;
  stock: number;
  sales?: number;
  saleing?: number;
  directbuy: number;
  status: number;
  status_text?: string;
  directbuy_text?: string;
  helpgive_text?: string;
  helpgive_type?: number;
  helpgive_percent?: number;
  helpgive_ff?: number;
  ongoing_join_num?: number;
}

/** 砍价发起记录（join 行） */
export interface KanjiaJoin {
  id: number;
  mid: number;
  proid: number;
  now_price: number;
  helpnum: number;
  createtime: number;
  status: number;
  status_text?: string;
  endtime: number;
  isbuy: number;
  nickname?: string;
  headimg?: string;
  proname?: string;
  propic?: string;
  sell_price?: number;
  min_price?: number;
  target_helpnum?: number;
  limitHour?: number;
  deadtime?: number;
  leftsecond?: number;
  cut_percent?: number;
}

/** 砍价商品详情（附本人进行中发起记录） */
export interface KanjiaProductDetail extends KanjiaProduct {
  helpgive_text: string;
  myjoin: KanjiaJoin | null;
}

/** 发起砍价结果 */
export interface KanjiaStartResult {
  joinid: number;
  now_price: number;
}

/** 帮砍结果 */
export interface KanjiaHelpCutResult {
  cut_price: number;
  now_price: number;
  status: number;
  givescore: number;
  givemoney: number;
}

/** 帮砍明细行 */
export interface KanjiaHelpRow {
  id: number;
  mid: number;
  helpmid: number;
  proid: number;
  joinid: number;
  cut_price: number;
  after_price: number;
  createtime: number;
  nickname?: string;
  headimg?: string;
}

/** 砍价订单 */
export interface KanjiaOrder {
  id: number;
  ordernum: string;
  title?: string;
  proid: number;
  proname: string;
  propic: string;
  num: number;
  totalprice: number;
  product_price: number;
  freight_price: number;
  sell_price?: number;
  createtime: number;
  status: number;
  status_text?: string;
  linkman?: string;
  tel?: string;
  area?: string;
  address?: string;
  message?: string;
  paytime?: number;
  hexiao_code?: string;
  joinid?: number;
  refund_status?: number;
}

/* ================= 秒杀 ================= */

/** 秒杀场次聚合行（列表） */
export interface SeckillSessionRow {
  proid: number;
  name: string;
  pic: string;
  prostatus: number;
  seckill_date: string;
  seckill_time: number;
  starttime: number;
  endtime: number;
  seckill_price: number;
  seckill_num: number;
  sales: number;
  salepercent: number;
  buymax: number;
  ids: number[];
}

/** 秒杀列表响应（附场次配置） */
export interface SeckillListResult {
  list: SeckillSessionRow[];
  total: number;
  page: number;
  pagesize: number;
  timeset: number[];
  duration: number;
}

/** 秒杀规格行 */
export interface SeckillGuige {
  id: number;
  name: string;
  sell_price?: number;
  stock: number;
  sales?: number;
}

/** 秒杀场次明细行（详情） */
export interface SeckillProdata {
  id: number;
  seckill_date: string;
  seckill_time: number;
  starttime: number;
  endtime: number;
  seckill_price: number;
  seckill_num: number;
  sales: number;
  buymax: number;
}

/** 秒杀商品详情 */
export interface SeckillDetail {
  id: number;
  name: string;
  pic: string;
  pics: string;
  detail: string;
  market_price: number;
  sell_price: number;
  stock: number;
  sales: number;
  perlimit: number;
  givescore: number;
  guige: SeckillGuige[];
  prodata: SeckillProdata[];
}

/** 秒杀订单 */
export interface SeckillOrder {
  id: number;
  ordernum: string;
  title?: string;
  proid: number;
  proname: string;
  propic: string;
  ggid: number;
  ggname?: string;
  num: number;
  sell_price: number;
  totalprice: number;
  product_price: number;
  freight_price: number;
  createtime: number;
  status: number;
  linkman?: string;
  tel?: string;
  area?: string;
  address?: string;
  message?: string;
  paytime?: number;
  hexiao_code?: string;
}

/* ================= 积分商城 ================= */

/** 积分商城规格行 */
export interface ScoreshopGuige {
  id: number;
  name: string;
  score_price: number;
  money_price: number;
  stock: number;
  sales?: number;
}

/** 积分商城商品（列表行） */
export interface ScoreshopProduct {
  id: number;
  cid: number;
  cname?: string;
  name: string;
  pic: string;
  score_price: number;
  money_price: number;
  sell_price: number;
  stock: number;
  sales: number;
  buymax: number;
  status: number;
  sort?: number;
  createtime?: number;
  guige?: ScoreshopGuige[];
}

/** 积分商城商品详情 */
export interface ScoreshopDetail {
  id: number;
  cid: number;
  name: string;
  pic: string;
  pics: string;
  detail: string;
  sell_price: number;
  score_price: number;
  money_price: number;
  stock: number;
  sales: number;
  buymax: number;
  everyday_buymax: number;
  guige: ScoreshopGuige[];
}

/** 积分订单商品行 */
export interface ScoreshopOrderGoods {
  id: number;
  orderid: number;
  proid: number;
  name: string;
  pic: string;
  ggid: number;
  ggname?: string;
  num: number;
  score_price: number;
  money_price: number;
  totalscore: number;
  totalmoney: number;
  status: number;
}

/** 积分商城订单 */
export interface ScoreshopOrder {
  id: number;
  ordernum: string;
  title?: string;
  totalscore: number;
  totalmoney: number;
  totalprice: number;
  totalnum: number;
  freight_price: number;
  createtime: number;
  status: number;
  linkman?: string;
  tel?: string;
  area?: string;
  address?: string;
  message?: string;
  paytime?: number;
  paytype?: string;
  hexiao_code?: string;
  goods: ScoreshopOrderGoods[];
}
