import request from './request'

// ============ 微信模板/订阅消息配置（批次7-3 任务211） ============

export type TmplInfo = Record<string, string>

export interface MyTmplRow {
  source: 'mp_tmplset' | 'mp_tmplset_new'
  source_label: string
  field: string
  label: string
  template_id: string
}

export interface WxSubscribeTitleRow {
  tid: number
  title: string
}

/* ---------------- 公众号模板消息设置（mp_tmplset） ---------------- */

// 读取模板消息设置
export function getMpTmplset() {
  return request.get<unknown, { info: TmplInfo }>('/admin/mptmpl/tmplset')
}

// 保存模板消息设置
export function saveMpTmplset(info: TmplInfo) {
  return request.post<unknown, null>('/admin/mptmpl/tmplset', { info })
}

// 添加行业模板（api_add_template，返回模板ID）
export function addMpTemplate(templateNo: string) {
  return request.post<unknown, { template_id: string }>('/admin/mptmpl/getTmplId', {
    template_no: templateNo,
  })
}

/* ---------------- 类目模板消息（mp_tmplset_new） ---------------- */

// 读取类目模板消息设置
export function getMpTmplsetNew() {
  return request.get<unknown, { info: TmplInfo }>('/admin/mptmpl/tmplsetNew')
}

// 保存类目模板消息设置
export function saveMpTmplsetNew(info: TmplInfo) {
  return request.post<unknown, null>('/admin/mptmpl/tmplsetNew', { info })
}

// 添加类目模板（附关键词，自动行业设置，返回模板ID）
export function addMpTemplateNew(templateNo: string) {
  return request.post<unknown, { template_id: string }>('/admin/mptmpl/getTmplIdNew', {
    template_no: templateNo,
  })
}

/* ---------------- 已添加的模板（两表聚合） ---------------- */

// 聚合列表
export function getMyTmplList() {
  return request.get<unknown, { list: MyTmplRow[]; count: number }>('/admin/mptmpl/mytmpl')
}

// 删除（清空对应配置字段）
export function delMyTmpl(source: MyTmplRow['source'], field: string) {
  return request.post<unknown, null>('/admin/mptmpl/mytmplDel', { source, field })
}

/* ---------------- 小程序订阅消息设置（wx_tmplset） ---------------- */

// 读取订阅消息设置（主字段组 + _new 订阅消息字段组）
export function getWxTmplset() {
  return request.get<unknown, { info: TmplInfo }>('/admin/wxtmpl/tmplset')
}

// 保存订阅消息设置（支持字段组独立保存：info 仅传该组字段）
export function saveWxTmplset(info: TmplInfo) {
  return request.post<unknown, null>('/admin/wxtmpl/tmplset', { info })
}

// 获取模板标题（小程序类目列表）
export function getWxSubscribeTitle() {
  return request.get<unknown, { list: WxSubscribeTitleRow[] }>('/admin/wxtmpl/getTitle')
}

// 组合模板并添加至账号（tid+kidList，返回 priTmplId）
export function addWxTemplate(templateNo: string, keywords: string) {
  return request.post<unknown, { template_id: string }>('/admin/wxtmpl/getTmplId', {
    template_no: templateNo,
    keywords,
  })
}
