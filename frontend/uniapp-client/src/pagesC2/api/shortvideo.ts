/** 短视频模块 C 端 API 封装（pagesC2 分包） */
import { get, post } from "../../utils/request";
import type { C2Option } from "./article";

/** 视频行（formatApiVideo 装饰后） */
export interface SvVideo {
  id: number;
  name?: string;
  title?: string;
  src?: string;
  url?: string;
  coverimg?: string;
  pics?: string;
  content?: string;
  cid?: number;
  view_num?: number;
  zan_num?: number;
  share_num?: number;
  commentnum?: number;
  iszan?: number;
  status?: number;
  createtime?: number;
  /** 附加业务信息 */
  binfo?: Record<string, unknown>;
  proid?: number;
  proname?: string;
  [key: string]: unknown;
}

/** 首页结果 */
export interface SvIndexResult {
  datalist: SvVideo[];
  clist?: C2Option[];
  sysset?: Record<string, unknown>;
}

/** 详情结果（videoList 当前视频置顶循环） */
export interface SvDetailResult {
  videoList: SvVideo[];
  binfo?: Record<string, unknown>;
  list_type?: number | string;
}

/** 评论行 */
export interface SvComment {
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
  replylist?: SvComment[];
}

/** 上传表单 GET /api/shortvideo/uploadvideo */
export interface SvUploadForm {
  sysset?: Record<string, unknown>;
  clist: C2Option[];
}

/** 我的上传列表结果 */
export interface SvMyuploadResult {
  countall: number;
  count0: number;
  count1: number;
  datalist: SvVideo[];
  st: number | string;
}

/** 竖滑列表 GET /api/shortvideo/index（page=1 附 clist） */
export function fetchSvIndex(params: { bid?: number; cid?: number; keyword?: string; page: number }) {
  return get<SvIndexResult>("/api/shortvideo/index", { ...params });
}

/** 视频详情 GET /api/shortvideo/detail */
export function fetchSvDetail(id: number, cid = 0) {
  return get<SvDetailResult>("/api/shortvideo/detail", { id, cid });
}

/** 播放量+1 POST /api/shortvideo/updateviewnum */
export function svUpdateViewnum(id: number) {
  return post<Record<string, unknown>>("/api/shortvideo/updateviewnum", { id });
}

/** 评论列表 GET /api/shortvideo/getcommentlist */
export function fetchSvComments(id: number, page: number) {
  return get<{ data: SvComment[] }>("/api/shortvideo/getcommentlist", { id, page });
}

/** 视频点赞切换 POST /api/shortvideo/zan */
export function svZan(id: number) {
  return post<{ iszan: number; zan_num: number }>("/api/shortvideo/zan", { id });
}

/** 提交评论 POST /api/shortvideo/subpinglun */
export function svSubPinglun(id: number, content: string, hfid = 0) {
  return post<{ msg?: string; commentnum?: number }>("/api/shortvideo/subpinglun", { id, hfid, content });
}

/** 评论点赞切换 POST /api/shortvideo/pzan */
export function svPzan(id: number) {
  return post<{ type?: number }>("/api/shortvideo/pzan", { id });
}

/** 分享数+1 POST /api/shortvideo/addsharenum */
export function svAddSharenum(id: number) {
  return post<Record<string, unknown>>("/api/shortvideo/addsharenum", { id });
}

/** 挂载商品列表 GET /api/shortvideo/getprolist */
export function fetchSvProList(id: number) {
  return get<{ data?: Array<{ proid: number; proname?: string; propic?: string; proprice?: number | string }> }>(
    "/api/shortvideo/getprolist",
    { id },
  );
}

/** 上传表单 GET /api/shortvideo/uploadvideo */
export function fetchSvUploadForm(bid = 0) {
  return get<SvUploadForm>("/api/shortvideo/uploadvideo", { bid });
}

/** 上传视频 POST /api/shortvideo/uploadvideo（字段 title/video/pics/cid/content） */
export function submitSvUpload(data: { title: string; video: string; pics?: string; cid?: number; content?: string }) {
  return post<{ msg?: string; id?: number }>("/api/shortvideo/uploadvideo", { ...data });
}

/** 我的上传 GET /api/shortvideo/myupload */
export function fetchSvMyupload(params: { st?: number | string; keyword?: string; page?: number }) {
  return get<SvMyuploadResult>("/api/shortvideo/myupload", { ...params });
}

/** 上架/下架切换 POST /api/shortvideo/myuploadsetst */
export function svSetStatus(id: number, status: number) {
  return post<{ msg?: string }>("/api/shortvideo/myuploadsetst", { id, status });
}

/** 删除视频 POST /api/shortvideo/myuploaddel */
export function svDelete(id: number) {
  return post<{ msg?: string }>("/api/shortvideo/myuploaddel", { id });
}

/** 播放完成上报 POST /api/shortvideo/playEnd */
export function svPlayEnd(id: number) {
  return post<Record<string, unknown>>("/api/shortvideo/playEnd", { id });
}
