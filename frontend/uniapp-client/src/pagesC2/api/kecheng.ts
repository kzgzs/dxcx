/** 课程模块 C 端 API 封装（pagesC2 分包） */
import { get, post } from "../../utils/request";

/** 课程列表行 */
export interface KcItem {
  id: number;
  name?: string;
  pic?: string;
  price?: number | string;
  market_price?: number | string;
  join_num?: number;
  kctype?: number;
  count?: number;
  [key: string]: unknown;
}

/** 课程详情（product） */
export interface KcProduct {
  id: number;
  name?: string;
  pic?: string;
  pics?: string[];
  price?: number | string;
  market_price?: number | string;
  join_num?: number;
  count?: number;
  ispay?: number;
  commission?: number | string;
  content?: string;
  kctype?: number;
  [key: string]: unknown;
}

/** 课程详情结果 */
export interface KcDetailResult {
  product: KcProduct;
  kechengset?: Record<string, unknown>;
  tjdatalist?: KcItem[];
}

/** 目录子章节 */
export interface KcMlChild {
  id: number;
  name?: string;
  kctype?: number;
  duration?: number | string;
  study_status?: number;
  jindu?: number;
  tiku_status?: number;
  record_status?: number;
  [key: string]: unknown;
}

/** 目录行（章） */
export interface KcMlRow {
  id: number;
  name?: string;
  children?: KcMlChild[];
  [key: string]: unknown;
}

/** 章节播放详情 */
export interface KcMlDetail {
  id: number;
  kcid?: number;
  name?: string;
  kctype?: number;
  content?: string;
  voice_url?: string;
  video_url?: string;
  /** 上次播放位置（秒） */
  startTime?: number;
  ispay?: number;
  isstudy?: number;
  isdt?: number;
  price?: number | string;
  orderlearn?: number;
  learnhg?: number;
  learnkey?: string;
  learnhg_text?: string;
  count?: number;
  kccount?: number;
  [key: string]: unknown;
}

/** 学习记录行（studylog） */
export interface KcStudyLog {
  id: number;
  kcid?: number;
  kccid?: number;
  jindu?: number;
  status?: number;
  [key: string]: unknown;
}

/** 章节详情结果 */
export interface KcMlDetailResult {
  detail: KcMlDetail;
  studylog?: KcStudyLog;
  chapters?: KcMlRow[];
  iskaoshi?: number;
  sysset?: Record<string, unknown>;
}

/** 创建订单结果 */
export interface KcOrderCreateResult {
  orderid: number;
  ordernum?: string;
  price?: number | string;
  payorderid?: number;
  out_trade_no?: string;
  /** 免费课直接完成 */
  free?: boolean;
}

/** 课程订单行 */
export interface KcOrderItem {
  id: number;
  kcid?: number;
  ordernum?: string;
  price?: number | string;
  status?: number;
  pic?: string;
  name?: string;
  count?: number;
  kccount?: number;
  isdt?: number;
  orderlearn?: number;
  learnhg?: number;
  createtime_text?: string;
  createtime?: number;
  [key: string]: unknown;
}

/** 题目 */
export interface TkQuestion {
  id: number;
  title?: string;
  /** 1选择 2填空 */
  type?: number;
  option?: string[];
  sorts?: string[];
  /** 正确答案（错题/回顾态） */
  answer?: string;
  right_options?: string;
  right_option_idx?: number | number[];
  jiexi?: string;
  rightcount?: number;
  [key: string]: unknown;
}

/** 抽题结果 */
export interface TkStartResult {
  /** 1=无可答题（已结束） */
  ended?: number;
  lefttime?: number;
  tkdata: TkQuestion;
  rid: number;
  nums?: number;
  hasnum?: number;
  dtid: number;
  set?: Record<string, unknown>;
}

/** 判分+下一题结果 */
export interface TkNextResult {
  /** 1=已答完，需调 tofinish */
  finished?: number;
  is_right?: number;
  tkdata?: TkQuestion;
  hasnum?: number;
  dtid?: number;
  answer?: string;
}

/** 交卷结果 */
export interface TkFinishResult {
  is_right?: number;
  rid: number;
  score?: number;
  ishg?: number;
}

/** 成绩单 */
export interface TkComplete {
  id: number;
  kcid?: number;
  mlid?: number;
  score?: number;
  ishg?: number;
  isend?: number;
  time?: number;
  endtime?: number;
  rightnum?: number;
  errornum?: number;
  longtime?: number | string;
}

/** 学习记录行 */
export interface TkRecord {
  id: number;
  title?: string;
  date?: string;
  score?: number;
  ishg?: number;
  rid?: number;
  [key: string]: unknown;
}

/** 讲师信息 */
export interface LecturerInfo {
  id?: number;
  pic?: string;
  headimg?: string;
  nickname?: string;
  realname?: string;
  tel?: string;
  shortdesc?: string;
  status?: number;
  [key: string]: unknown;
}

/** 选项字母表 */
export const SORTS = ["A", "B", "C", "D", "E", "F", "G"];

/** 课程列表 GET /api/kecheng/list（匿名） */
export function fetchKcList(params: { cid?: number; keyword?: string; pagenum?: number }) {
  return get<{ list: KcItem[] }>("/api/kecheng/list", { ...params });
}

/** 课程详情 GET /api/kecheng/detail（匿名） */
export function fetchKcDetail(id: number) {
  return get<KcDetailResult>("/api/kecheng/detail", { id });
}

/** 课程目录 GET /api/kecheng/mulu（登录） */
export function fetchKcMulu(params: { id: number; keyword?: string; pagenum?: number }) {
  return get<{ list: KcMlRow[] }>("/api/kecheng/mulu", { ...params });
}

/** 章节播放详情 GET /api/kecheng/mldetail（登录；id 缺省取首个未学完章节） */
export function fetchKcMlDetail(kcid: number, id = 0) {
  return get<KcMlDetailResult>("/api/kecheng/mldetail", { kcid, id });
}

/** 创建课程订单 POST /api/kecheng/order/create */
export function kcOrderCreate(kcid: number) {
  return post<KcOrderCreateResult>("/api/kecheng/order/create", { kcid });
}

/** 订单支付 POST /api/kecheng/order/pay（PAY_MOCK 直接入账） */
export function kcOrderPay(orderid: number, paytype: string = "wechat") {
  return post<{ mock?: boolean; status?: number }>("/api/kecheng/order/pay", { orderid, paytype });
}

/** 我的课程订单 GET /api/kecheng/order/list */
export function fetchKcOrderList(params: { keyword?: string; pagenum?: number }) {
  return get<{ list: KcOrderItem[] }>("/api/kecheng/order/list", { ...params });
}

/** 学习进度上报 POST /api/kecheng/study/add（播满 status=1） */
export function kcStudyAdd(logid: number, currentTime: number, playJd = "") {
  return post<{ jindu?: number; status?: number }>("/api/kecheng/study/add", { logid, currentTime, playJd });
}

/** 下一章 GET /api/kecheng/study/nextsection */
export function fetchKcNextSection(id: number, kcid: number) {
  return get<{ id: number }>("/api/kecheng/study/nextsection", { id, kcid });
}

/** 领取学分 POST /api/kecheng/givescore */
export function kcGiveScore(kccid: number) {
  return post<{ msg?: string }>("/api/kecheng/givescore", { kccid });
}

/** 抽题开考 POST /api/kecheng/exam/getTiku */
export function examGetTiku(kcid: number, mlid = 0) {
  return post<TkStartResult>("/api/kecheng/exam/getTiku", { kcid, mlid });
}

/** 上一题 POST /api/kecheng/exam/prevquestion */
export function examPrevQuestion(dtid: number) {
  return post<{ answer?: string; tkdata?: TkQuestion; hasnum?: number; dtid?: number }>(
    "/api/kecheng/exam/prevquestion",
    { dtid },
  );
}

/** 判分并取下一题 POST /api/kecheng/exam/nextquestion */
export function examNextQuestion(dtid: number, rightOption: number | number[] | string) {
  return post<TkNextResult>("/api/kecheng/exam/nextquestion", { dtid, right_option: rightOption });
}

/** 交卷 POST /api/kecheng/exam/tofinish */
export function examToFinish(dtid: number, rightOption: number | number[] | string) {
  return post<TkFinishResult>("/api/kecheng/exam/tofinish", { dtid, right_option: rightOption });
}

/** 成绩单 GET /api/kecheng/exam/complete */
export function fetchExamComplete(rid: number) {
  return get<TkComplete>("/api/kecheng/exam/complete", { rid });
}

/** 学习记录 GET /api/kecheng/exam/recordlog */
export function fetchExamRecordLog(params: { kcid: number; mlid?: number; pagenum?: number }) {
  return get<{ datalist: TkRecord[] }>("/api/kecheng/exam/recordlog", { ...params });
}

/** 错题回顾 GET /api/kecheng/exam/error（直接返回题目对象，含 logid/isup/isdown/nums） */
export function fetchExamError(params: { rid?: number; op?: number | string; logid?: number }) {
  return get<TkQuestion>("/api/kecheng/exam/error", { ...params });
}

/** 错题翻题 GET /api/kecheng/exam/geterrotiku（同 error 口径） */
export function fetchExamErrorTiku(params: { rid?: number; op?: number | string; logid?: number }) {
  return get<TkQuestion>("/api/kecheng/exam/geterrotiku", { ...params });
}

/** 讲师申请信息 GET /api/kecheng/lecturer/applyInfo */
export function fetchLecturerApplyInfo() {
  return get<{ cansub: number; info?: LecturerInfo }>("/api/kecheng/lecturer/applyInfo");
}

/** 讲师申请/修改 POST /api/kecheng/lecturer/apply（opttype 0申请 1修改） */
export function submitLecturerApply(
  info: { pic: string; nickname: string; realname: string; tel: string; shortdesc: string },
  opttype: 0 | 1,
) {
  return post<{ msg?: string }>("/api/kecheng/lecturer/apply", { info, opttype });
}

/** 讲师中心 GET /api/kecheng/lecturer/center */
export function fetchLecturerCenter(params: { st?: number; pagenum?: number }) {
  return get<{ data: KcItem[]; lecturer: LecturerInfo; canedit: number }>(
    "/api/kecheng/lecturer/center",
    { ...params },
  );
}

/** 讲师编辑课程 POST /api/kecheng/lecturer/editkecheng */
export function lecturerEditKecheng(info: Record<string, unknown>) {
  return post<{ msg?: string }>("/api/kecheng/lecturer/editkecheng", { info });
}

/** 讲师列表 GET /api/kecheng/lecturer/list */
export function fetchLecturerList(pagenum: number) {
  return get<{ data: LecturerInfo[] }>("/api/kecheng/lecturer/list", { pagenum });
}
