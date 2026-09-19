/** 论坛模块 C 端 API 封装（pagesC2 分包） */
import { get, post } from "../../utils/request";
import type { C2Option } from "./article";

/** 挂载商品摘要 */
export interface LtGoods {
  pronum?: number;
  proid?: number;
  proname?: string;
  propic?: string;
  proprice?: number | string;
}

/** 帖子行（decoratePost 装饰后） */
export interface LtPost {
  id: number;
  mid?: number;
  cid?: number;
  nickname?: string;
  headimg?: string;
  content?: string;
  pics?: string[];
  video?: string;
  createtime?: number;
  showtime?: string;
  status?: number;
  zan?: number;
  top?: number;
  plcount?: number;
  iszan?: number;
  /** 挂载商品 */
  pronum?: number;
  proid?: number;
  proname?: string;
  propic?: string;
  proprice?: number | string;
  [key: string]: unknown;
}

/** 论坛设置 */
export interface LtSysset {
  title?: string;
  banner?: string;
  top_price?: number | string;
  top_day?: number;
  sendcheck?: number;
  [key: string]: unknown;
}

/** 首页结果 */
export interface LtIndexResult {
  datalist: LtPost[];
  sysset?: LtSysset;
  title?: string;
  clist?: C2Option[];
}

/** 版块列表结果 */
export interface LtListResult {
  cid: number;
  title?: string;
  banner?: string;
  datalist: LtPost[];
  sysset?: LtSysset;
  categoryTabs?: C2Option[];
}

/** 回帖行 */
export interface LtReply {
  id?: number;
  nickname?: string;
  headimg?: string;
  content?: string;
  createtime?: number;
  createtime_fmt?: string;
}

/** 评论行 */
export interface LtComment {
  id: number;
  sid?: number;
  mid?: number;
  nickname?: string;
  headimg?: string;
  content?: string;
  createtime?: number;
  createtime_fmt?: string;
  zan?: number;
  iszan?: number;
  replylist?: LtReply[];
}

/** 帖子详情结果 */
export interface LtDetailResult {
  mid: number;
  detail: LtPost;
  datalist: LtComment[];
  plcount: number;
  iszan: number;
  pinglunstatus: number;
}

/** 置顶套餐选项 */
export interface LtTopOption {
  index?: number;
  name?: string;
  hour?: number;
  price?: number | string;
  [key: string]: unknown;
}

/** 版块节点（树） */
export interface LtCategory {
  id: number;
  name?: string;
  children?: LtCategory[];
  top_options?: LtTopOption[];
  [key: string]: unknown;
}

/** 发帖表单 */
export interface LtFatieForm {
  clist: LtCategory[];
  sendcheck: number;
  can_send: number;
  send_msg?: string;
}

/** 发帖结果 */
export interface LtFatieResult {
  id: number;
  status: number;
  msg?: string;
  need_pay?: number;
  orderid?: number;
  ordernum?: string;
  payorderid?: number;
}

/** 我的发帖记录结果 */
export interface LtFatielogResult {
  list: LtPost[];
  total: number;
  page: number;
  pagesize: number;
}

/** 首页 GET /api/luntan/index（page≤1 附 sysset/clist） */
export function fetchLtIndex(page: number) {
  return get<LtIndexResult>("/api/luntan/index", { page });
}

/** 版块列表 GET /api/luntan/ltlist */
export function fetchLtList(params: { cid?: number; keyword?: string; page?: number }) {
  return get<LtListResult>("/api/luntan/ltlist", { ...params });
}

/** 帖子详情 GET /api/luntan/detail */
export function fetchLtDetail(id: number, page: number) {
  return get<LtDetailResult>("/api/luntan/detail", { id, page });
}

/** 发帖表单 GET /api/luntan/fatieForm */
export function fetchFatieForm() {
  return get<LtFatieForm>("/api/luntan/fatieForm");
}

/** 发帖 POST /api/luntan/fatie */
export function submitFatie(data: {
  cid: number;
  cid2?: number;
  content: string;
  pics?: string;
  video?: string;
  selected_goods?: string;
  top_index?: number;
}) {
  return post<LtFatieResult>("/api/luntan/fatie", { ...data });
}

/** 付费置顶支付 POST /api/luntan/payTop（PAY_MOCK 直接入账） */
export function payLtTop(orderid: number, paytype: string = "wechat") {
  return post<{ mock?: boolean; status?: number }>("/api/luntan/payTop", { orderid, paytype });
}

/** 我的发帖记录 GET /api/luntan/fatielog */
export function fetchFatielog(page: number) {
  return get<LtFatielogResult>("/api/luntan/fatielog", { page });
}

/** 帖子点赞切换 POST /api/luntan/zan */
export function ltZan(id: number) {
  return post<{ type: number; zancount: number }>("/api/luntan/zan", { id });
}

/** 提交评论/回帖 POST /api/luntan/subpinglun（type 0评论 1回帖） */
export function ltSubPinglun(id: number, type: 0 | 1, content: string, hfid = 0) {
  return post<{ msg?: string }>("/api/luntan/subpinglun", { id, type, hfid, content });
}

/** 评论点赞切换 POST /api/luntan/pzan */
export function ltPzan(id: number) {
  return post<{ type?: number }>("/api/luntan/pzan", { id });
}

/** 删除帖子 POST /api/luntan/deltie */
export function ltDelTie(id: number) {
  return post<{ msg?: string }>("/api/luntan/deltie", { id });
}

/** 删除评论 POST /api/luntan/delpinglun */
export function ltDelPinglun(id: number) {
  return post<{ msg?: string }>("/api/luntan/delpinglun", { id });
}

/** 删除回帖 POST /api/luntan/delplreply */
export function ltDelPlReply(id: number) {
  return post<{ msg?: string }>("/api/luntan/delplreply", { id });
}
