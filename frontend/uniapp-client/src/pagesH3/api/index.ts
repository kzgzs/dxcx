/**
 * pagesH3 分包（团购 + 互动小件）API 封装
 * 模块：tuangou 团购 / sign 签到 / choujiang 抽奖 / kouling 口令谜语 /
 *       hongbao 红包 / fifa 竞猜 / cuxiao 促销
 * 统一走 src/utils/request.ts（信封 {code,msg,data}，401 自动处理）
 */
import { get, post, uploadFile } from "../../utils/request";

/* ================= 通用 ================= */

/** 分页结果信封 */
export interface PageResult<T> {
  list: T[];
  total: number;
  page: number;
  pagesize: number;
}

/* ================= 团购 tuangou ================= */

/** 团购分类 */
export interface TuangouCategory {
  id: number;
  pid: number;
  name: string;
  pic: string;
}

/** 阶梯价档位 */
export interface TuangouPriceTier {
  num: number;
  money: number;
}

/** 团购商品（列表/详情公共段） */
export interface TuangouProduct {
  id: number;
  cid: number;
  name: string;
  pic: string;
  sellpoint: string;
  market_price: number;
  sell_price: number;
  nowprice: number;
  minprice: number;
  buynum: number;
  pricedata: TuangouPriceTier[];
  stock: number;
  sales: number;
  perlimit: number;
  starttime: number;
  endtime: number;
  sort: number;
  status: number;
  createtime: number;
}

/** 团购商品详情 */
export interface TuangouProductDetail extends TuangouProduct {
  detail: string;
  pics: string;
  fuwupoint: string;
  freighttype: number;
  contact_require: number;
  comments: TuangouComment[];
}

/** 团购评价 */
export interface TuangouComment {
  id: number;
  mid: number;
  proid: number;
  proname: string;
  propic: string;
  ordernum: string;
  nickname: string;
  headimg: string;
  score: number;
  content: string;
  content_pic: string;
  reply_content: string;
  reply_time: number;
  status: number;
  status_text: string;
  createtime: number;
}

/** 团购订单（status 0未支付 1已支付 2已发货 3已完成 4已关闭） */
export interface TuangouOrder {
  id: number;
  ordernum: string;
  proid: number;
  proname: string;
  propic: string;
  num: number;
  sell_price: number;
  totalprice: number;
  product_price: number;
  freight_price: number;
  freight_type: number;
  freight_text: string;
  freight_type_text: string;
  refund_money: number;
  refund_reason: string;
  status: number;
  status_text: string;
  refund_status: number;
  refund_text: string;
  buyer: { mid: number; nickname: string; headimg: string };
  linkman: string;
  tel: string;
  address: string;
  message: string;
  remark: string;
  express_com: string;
  express_no: string;
  hexiao_code: string;
  iscomment: number;
  paytype: string;
  paytime: number;
  send_time: number;
  collect_time: number;
  createtime: number;
}

/** 团购订单详情（含核销码与卡密） */
export interface TuangouOrderDetail extends TuangouOrder {
  codes: string[];
}

/** 团购设置 */
export interface TuangouSet {
  pics: string;
  autoshdays: number;
  comment: number;
  comment_check: number;
  showjd: number;
}

/** 下单返回 */
export interface TuangouCreateResult {
  orderid: number;
  ordernum: string;
  totalprice: number;
  status: number;
}

export function fetchTuangouSet() {
  return get<TuangouSet>("/api/tuangou/set");
}

export function fetchTuangouCategories() {
  return get<TuangouCategory[]>("/api/tuangou/category/list");
}

export function fetchTuangouProducts(params: { page?: number; pagesize?: number; cid?: number; keyword?: string }) {
  return get<PageResult<TuangouProduct>>("/api/tuangou/product/list", {
    page: params.page || 1,
    pagesize: params.pagesize || 10,
    cid: params.cid || 0,
    keyword: params.keyword || "",
  });
}

export function fetchTuangouProductDetail(id: number) {
  return get<TuangouProductDetail>("/api/tuangou/product/detail", { id });
}

/** 团购下单（PAY_MOCK 时后端即时支付入账，返回 status=1） */
export function tuangouOrderCreate(data: {
  proid: number;
  num: number;
  linkman?: string;
  tel?: string;
  area?: string;
  address?: string;
  message?: string;
  freight_type?: number;
  freight_price?: number;
  freight_text?: string;
}) {
  return post<TuangouCreateResult>("/api/tuangou/order/create", { ...data });
}

/** 我的团购订单（st ''=全部 0-4 / r 退款相关） */
export function fetchTuangouOrders(st: string, page: number, pagesize = 10) {
  return get<PageResult<TuangouOrder>>("/api/tuangou/order/list", { st, page, pagesize });
}

export function fetchTuangouOrderDetail(id: number) {
  return get<TuangouOrderDetail>("/api/tuangou/order/detail", { id });
}

export function fetchTuangouHexiaoCode(id: number) {
  return get<{ ordernum: string; hexiao_code: string; status: number }>("/api/tuangou/order/hexiaoCode", { id });
}

export function tuangouOrderRefund(id: number, reason: string) {
  return post<null>("/api/tuangou/order/refund", { id, reason });
}

export function tuangouOrderCollect(id: number) {
  return post<null>("/api/tuangou/order/collect", { id });
}

export function tuangouOrderDelete(id: number) {
  return post<null>("/api/tuangou/order/delete", { id });
}

/** 提交评价（score 1-5；content_pic 逗号分隔图片地址） */
export function tuangouCommentCreate(data: { orderid: number; score: number; content: string; content_pic: string }) {
  return post<{ id: number }>("/api/tuangou/comment/create", { ...data });
}

/* ================= 签到 sign ================= */

/** 连签奖励规则档 */
export interface SignRule {
  days: number;
  score: number;
  coupon_id: number;
  coupon_name: string;
}

export interface SignSet {
  score: number;
  lxqdset: SignRule[];
  lxzsset: SignRule[];
  name: string;
  status: number;
  display: number;
  guize: string;
  style: number;
  bgpic: string;
}

/** 签到页聚合数据 */
export interface SignPageData {
  signset: SignSet;
  userinfo: {
    id?: number;
    nickname?: string;
    headimg?: string;
    score?: number;
    signtimes?: number;
    signtimeslx?: number;
    signtime?: number;
    signdate?: string;
  };
  hassign: number;
  today: string;
  selectedDate: { date: string; info: string }[];
  list?: {
    id: number;
    nickname: string;
    headimg: string;
    signtimes: number;
    signtimeslx: number;
    signtime: number;
  }[];
}

/** 签到动作返回 */
export interface SignResult {
  scoreadd: number;
  signtimes: number;
  signtimeslx: number;
  lxqd_coupon_id: number;
  lxzs_coupon_id: number;
}

export function fetchSignPage() {
  return get<SignPageData>("/api/sign/page");
}

export function signSignin() {
  return post<SignResult>("/api/sign/signin");
}

export function fetchSignRecords(page: number, pagesize = 20) {
  return get<PageResult<Record<string, unknown>>>("/api/sign/records", { page, pagesize });
}

/* ================= 抽奖 choujiang ================= */

/** 奖项（j0 不中奖槽位 + j1-j9） */
export interface LotteryPrize {
  index: number;
  mc: string;
  pic: string;
  tp: number;
  sl: number;
  yj: number;
  gl: number;
}

/** 抽奖活动详情 */
export interface LotteryDetail {
  id: number;
  type: string;
  name: string;
  banner: string;
  starttime: number;
  endtime: number;
  phase: string;
  status: number;
  cjnum: number;
  zjnum: number;
  pertotal: number;
  perday: number;
  shareaddnum: number;
  createtime: number;
  guize: string;
  sharedaytimes: number;
  sharetimes: number;
  sharetitle: string;
  sharelink: string;
  sharepic: string;
  sharedesc: string;
  prizes: LotteryPrize[];
}

/** 抽奖中奖记录 */
export interface LotteryRecord {
  id: number;
  hid: number;
  name: string;
  mid: number;
  nickname: string;
  headimg: string;
  jx: number;
  jxtp: number;
  jxmc: string;
  linkman: string;
  tel: string;
  createtime: number;
  status: number;
  code: string;
  remark: string;
}

/** 抽奖动作返回（jx 0 未中奖，1-9 奖项槽位） */
export interface LotteryDrawResult {
  jx: number;
  jxmc: string;
  pic: string;
  jxtp: number;
}

export function fetchLotteryDetail(hid: number) {
  return get<LotteryDetail>("/api/choujiang/detail", { hid });
}

/** 剩余次数（-1 表示不限） */
export function fetchLotteryLeftTimes(hid: number) {
  return get<{ pertotal_left: number; perday_left: number }>("/api/choujiang/leftTimes", { hid });
}

export function lotteryDraw(hid: number, nickname: string, headimg: string) {
  return post<LotteryDrawResult>("/api/choujiang/draw", { hid, nickname, headimg });
}

export function lotteryShare(hid: number) {
  return post<{ added: number; extratimes: number }>("/api/choujiang/share", { hid });
}

export function fetchLotteryRecords(hid: number, page: number, pagesize = 20) {
  return get<PageResult<LotteryRecord>>("/api/choujiang/records", { hid, page, pagesize });
}

/** 领奖（生成/返回核销码） */
export function lotteryClaim(recordId: number) {
  return post<{ code: string }>("/api/choujiang/claim", { record_id: recordId });
}

/* ================= 口令/谜语 kouling ================= */

export interface KoulingSet {
  title: string;
  bgpic: string;
  guize: string;
  status: number;
}

/** 谜语详情（不下发谜底 name） */
export interface RiddleDetail {
  id: number;
  title: string;
  alias: string;
  content: string;
  starttime: number;
  endtime: number;
  status: number;
  num?: number;
  zjnum?: number;
}

export function fetchKoulingSet() {
  return get<KoulingSet>("/api/kouling/set");
}

export function koulingExchange(kouling: string) {
  return post<{ msg: string }>("/api/kouling/exchange", { kouling });
}

export function fetchRiddleDetail(id: number) {
  return get<RiddleDetail>("/api/kouling/riddle", { id });
}

export function riddleSubmit(id: number, answer: string) {
  return post<{ msg: string }>("/api/kouling/riddleSubmit", { id, kouling: answer });
}

/* ================= 红包 hongbao ================= */

export interface HongbaoDetail {
  id: number;
  name: string;
  sendname: string;
  wishing: string;
  minmoney: number;
  maxmoney: number;
  totalmoney: number;
  givenmoney: number;
  pernum: number;
  starttime: number;
  endtime: number;
  status: number;
  sharepic: string;
  needcode: number;
}

/** 领取返回：st 1成功 0未开始 2已结束 3抢完 4已抢过 5需抽奖码 10发放失败 */
export interface HongbaoReceiveResult {
  st: number;
  msg: string;
  money: number;
}

export function fetchHongbaoDetail(id: number) {
  return get<HongbaoDetail>("/api/hongbao/detail", { id });
}

export function hongbaoReceive(id: number, cjcode: string) {
  return post<HongbaoReceiveResult>("/api/hongbao/receive", { id, cjcode });
}

/* ================= Fifa 竞猜 ================= */

/** 赛事行（后端原始口径，字段保留驼峰） */
export interface FifaMatch {
  id: number;
  matchId: string;
  startDate: string;
  startTime: string;
  matchStage: string;
  matchDesc: string;
  matchStatus: string;
  matchStatusText: string;
  leftTeam_name: string;
  leftTeam_logo: string;
  leftTeam_score: string;
  rightTeam_name: string;
  rightTeam_logo: string;
  rightTeam_score: string;
  startTimestamp: number;
}

export interface FifaDateGroup {
  date: string;
  week: string;
  data: FifaMatch[];
}

export interface FifaIndexData {
  datalist: FifaDateGroup[];
  myscore: number;
  successnum: number;
  winscore: number;
  guize: string;
}

/** 我的竞猜记录（字段宽松） */
export interface FifaMyRecord {
  id?: number;
  guess1?: string;
  guess2?: string;
  guess1st?: number;
  guess2st?: number;
  givescore1?: number;
  givescore2?: number;
  [key: string]: unknown;
}

export interface FifaSetInfo {
  status?: number;
  starthour?: number;
  givescore1?: number;
  givescore2?: number;
  guize?: string;
  guess1set: string[];
  guess2set: string[];
  [key: string]: unknown;
}

export interface FifaDetailData {
  detail: FifaMatch;
  myrecord: FifaMyRecord;
  sset: FifaSetInfo;
  /** 0 可竞猜 1 窗口未开 2 已结束 */
  guessStatus: number;
}

export function fetchFifaIndex() {
  return get<FifaIndexData>("/api/fifa/index");
}

export function fetchFifaDetail(id: number) {
  return get<FifaDetailData>("/api/fifa/detail", { id });
}

/** 提交竞猜（guesstype 1猜胜负 2猜比分） */
export function fifaSubguess(hid: number, guesstype: number, guess: string) {
  return post<{ msg: string }>("/api/fifa/subguess", { hid, guesstype, guess });
}

/* ================= 促销 cuxiao ================= */

/** 促销活动（type 0仅显示 1满减 2满赠 3换购） */
export interface CuxiaoItem {
  id: number;
  name: string;
  type: number;
  tip: string;
  gettj: string;
  fwtype: number;
  categoryids: string;
  productids: string;
  minprice: number;
  minnum: number;
  money: number;
  proid: number;
  ggid: number;
  proname: string;
  starttime: string;
  endtime: string;
  sort: number;
  status: number;
  create_time: number;
}

export function fetchCuxiaoList(proid = 0) {
  return get<{ list: CuxiaoItem[] }>("/api/cuxiao/list", { proid });
}

/* ================= 通用：图片上传（评价用） ================= */

export function uploadImage(filePath: string) {
  return uploadFile<{ url: string }>("/api/upload", filePath);
}
