import request from './request'

// ===== 文章管理整组（批次5-A 任务194），类型自包含 =====

// 文章行
export interface ArticleItem {
  id: number
  aid: number
  bid: number
  cid: number
  name: string
  subname: string
  pic: string
  author: string
  content: string
  readcount: number
  zan: number
  sort: number
  status: number
  reason: string
  showname: number
  showsubname: number
  showreadcount: number
  showsendtime: number
  showauthor: number
  canpl: number
  canplrp: number
  pinglun_check: number
  showtj: string
  pcid: number
  fujian: string
  mid: number
  sendtime: number
  createtime: number
  cname?: string
  bname?: string
}

// 分类行（平铺含 pid）
export interface ArticleCategoryItem {
  id: number
  aid?: number
  bid?: number
  pid: number
  name: string
  pic: string
  sort: number
  status?: number
}

// 评论行
export interface ArticlePinglunItem {
  id: number
  sid: number
  mid: number
  nickname: string
  headimg: string
  content: string
  status: number
  zan: number
  score: number
  createtime: number
  title?: string
  replycount?: number
}

// 回评行
export interface ArticlePlreplyItem {
  id: number
  sid: number
  pid: number
  mid: number
  nickname: string
  headimg: string
  content: string
  status: number
  zan: number
  createtime: number
  plcontent?: string
  title?: string
}

// 文章设置
export interface ArticleSetData {
  listtype: number
  title_size: number
  share_score: number
  share_score_max_perday: number
  reward_status: number
  money_data: string
  score_data: string
}

export interface ArticleListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: string
  cid?: number
  bid?: string
  start_time?: number
  end_time?: number
}

export interface ArticleSaveParams {
  id?: number
  cid: number
  name: string
  subname?: string
  pic?: string
  author?: string
  content?: string
  sort?: number
  readcount?: number
  createtime?: number
  status?: number
  showname?: number
  showsubname?: number
  showreadcount?: number
  showsendtime?: number
  showauthor?: number
  canpl?: number
  canplrp?: number
  pinglun_check?: number
  showtj?: string[] | string
  pcid?: number
  fujian?: string
  mid?: number
}

export interface ArticlePageResult {
  list: ArticleItem[]
  total: number
  page: number
  pagesize: number
}

// ===== B端 - 文章 =====
export function getArticleList(params: ArticleListQuery) {
  return request.get<unknown, ArticlePageResult>('/admin/article/list', { params })
}

export function getArticleDetail(id: number) {
  return request.get<unknown, ArticleItem>('/admin/article/detail', { params: { id } })
}

export function saveArticle(data: ArticleSaveParams) {
  return request.post<unknown, { id: number }>('/admin/article/save', data)
}

export function deleteArticle(ids: number[]) {
  return request.post<unknown, null>('/admin/article/delete', { ids })
}

export function setArticleSt(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/article/setst', { ids, st })
}

export function setArticleCheckst(id: number, st: number, reason = '') {
  return request.post<unknown, null>('/admin/article/setcheckst', { id, st, reason })
}

export function copyArticle(id: number) {
  return request.post<unknown, { id: number }>('/admin/article/copy', { id })
}

// ===== B端 - 分类 =====
export function getArticleCategoryList() {
  return request.get<unknown, { list: ArticleCategoryItem[] }>('/admin/articlecategory/list')
}

export function saveArticleCategory(data: { id?: number; pid: number; name: string; sort?: number; pic?: string; status?: number }) {
  return request.post<unknown, { id: number }>('/admin/articlecategory/save', data)
}

export function deleteArticleCategory(ids: number[]) {
  return request.post<unknown, null>('/admin/articlecategory/delete', { ids })
}

// ===== B端 - 评论 =====
export function getArticlePinglunList(params: ArticleListQuery) {
  return request.get<unknown, { list: ArticlePinglunItem[]; total: number }>('/admin/articlepinglun/list', { params })
}

export function setArticlePinglunSt(ids: number[], st: number, score = 0) {
  return request.post<unknown, null>('/admin/articlepinglun/setst', { ids, st, score })
}

export function deleteArticlePinglun(ids: number[]) {
  return request.post<unknown, null>('/admin/articlepinglun/delete', { ids })
}

export function replyArticlePinglun(pid: number, content: string) {
  return request.post<unknown, { id: number }>('/admin/articlepinglun/reply', { pid, content })
}

// ===== B端 - 回评 =====
export function getArticlePlreplyList(params: ArticleListQuery) {
  return request.get<unknown, { list: ArticlePlreplyItem[]; total: number }>('/admin/articleplreply/list', { params })
}

export function setArticlePlreplySt(ids: number[], st: number) {
  return request.post<unknown, null>('/admin/articleplreply/setst', { ids, st })
}

export function deleteArticlePlreply(ids: number[]) {
  return request.post<unknown, null>('/admin/articleplreply/delete', { ids })
}

// ===== B端 - 设置 =====
export function getArticleSet() {
  return request.get<unknown, ArticleSetData>('/admin/articleset/get')
}

export function saveArticleSet(data: ArticleSetData) {
  return request.post<unknown, null>('/admin/articleset/save', data)
}
