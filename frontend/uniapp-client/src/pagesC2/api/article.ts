/** 文章模块 C 端 API 封装（pagesC2 分包） */
import { get, post } from "../../utils/request";

/** 分类选项 */
export interface C2Option {
  value: number | string;
  label: string;
}

/** 文章列表行 */
export interface ArticleItem {
  id: number;
  name: string;
  subname?: string;
  pic?: string;
  cid?: number;
  sendtime?: number;
  sendtime_fmt?: string;
  readcount?: number;
  zan?: number;
}

/** 文章列表结果 */
export interface ArticleListResult {
  list: ArticleItem[];
  clist: C2Option[];
  title: string;
  listtype?: number | string;
}

/** 打赏配置组 */
export interface RewardGroup {
  name?: string;
  nums?: Array<string | number>;
  [key: string]: unknown;
}

/** 打赏配置 */
export interface RewardConfig {
  money_data?: RewardGroup[];
  score_data?: RewardGroup[];
}

/** 文章详情 */
export interface ArticleDetail {
  id: number;
  name: string;
  subname?: string;
  pic?: string;
  content?: string;
  sendtime?: number;
  sendtime_fmt?: string;
  readcount?: number;
  zan?: number;
  canpl?: number;
  canplrp?: number;
  pinglun_check?: number;
  fujian?: string;
  fujian_name?: string;
  showtj?: string;
  [key: string]: unknown;
}

/** 文章详情结果 */
export interface ArticleDetailResult {
  detail: ArticleDetail;
  plcount: number;
  iszan: number;
  reward: number;
  reward_data: RewardConfig;
  reward_num: number;
}

/** 回评行 */
export interface ArticleReply {
  nickname?: string;
  headimg?: string;
  content?: string;
  createtime?: number;
}

/** 评论行 */
export interface ArticlePl {
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
  replylist?: ArticleReply[];
}

/** 文章列表 GET /api/article/getartlist（匿名可用） */
export function fetchArticleList(params: { pagenum?: number; cid?: number | string; keyword?: string }) {
  return get<ArticleListResult>("/api/article/getartlist", { ...params });
}

/** 文章详情 GET /api/article/detail（匿名可用） */
export function fetchArticleDetail(id: number) {
  return get<ArticleDetailResult>("/api/article/detail", { id });
}

/** 评论列表 GET /api/article/getpllist */
export function fetchArticlePlList(sid: number, pagenum: number) {
  return get<{ list: ArticlePl[] }>("/api/article/getpllist", { sid, pagenum });
}

/** 文章点赞切换 POST /api/article/zan */
export function articleZan(id: number) {
  return post<{ type: number; zancount: number }>("/api/article/zan", { id });
}

/** 提交评论/回评 POST /api/article/subpinglun（type 0评论 1回评） */
export function articleSubPinglun(id: number, type: 0 | 1, content: string, hfid = 0) {
  return post<{ msg?: string }>("/api/article/subpinglun", { id, type, hfid, content });
}

/** 评论点赞切换 POST /api/article/pzan */
export function articlePzan(id: number) {
  return post<{ type?: number }>("/api/article/pzan", { id });
}

/** 打赏 POST /api/article/reward {id, type 1余额|2积分, num} */
export function articleReward(id: number, type: 1 | 2, num: number) {
  return post<{ msg?: string }>("/api/article/reward", { id, type, num });
}

/** 附件下载记录 POST /api/article/saveResource */
export function articleSaveResource(id: number) {
  return post<Record<string, unknown>>("/api/article/saveResource", { id });
}

/** 附件信息 GET /api/article/getFujian */
export function fetchArticleFujian(id: number) {
  return get<{ fujian?: string; name?: string; msg?: string }>("/api/article/getFujian", { id });
}
