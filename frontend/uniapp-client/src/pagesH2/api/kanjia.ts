import { get, post } from "../../utils/request";
import type {
  ActivityOrderResult,
  ContactInfo,
  KanjiaHelpCutResult,
  KanjiaHelpRow,
  KanjiaJoin,
  KanjiaOrder,
  KanjiaProduct,
  KanjiaProductDetail,
  KanjiaStartResult,
  PageResult,
} from "./types";

/** 砍价商品列表（上架） GET /api/kanjia/product/list */
export function fetchKanjiaProducts(params: { page?: number; pagesize?: number; keyword?: string }) {
  return get<PageResult<KanjiaProduct>>("/api/kanjia/product/list", {
    page: params.page || 1,
    pagesize: params.pagesize || 10,
    keyword: params.keyword || "",
  });
}

/** 砍价商品详情（含本人进行中发起记录） GET /api/kanjia/product/detail */
export function fetchKanjiaDetail(proid: number) {
  return get<KanjiaProductDetail>("/api/kanjia/product/detail", { proid });
}

/** 发起砍价 POST /api/kanjia/join/start */
export function kanjiaStartJoin(proid: number) {
  return post<KanjiaStartResult>("/api/kanjia/join/start", { proid });
}

/** 帮砍一刀 POST /api/kanjia/join/help */
export function kanjiaHelpCut(joinid: number) {
  return post<KanjiaHelpCutResult>("/api/kanjia/join/help", { joinid });
}

/** 我的砍价发起记录 GET /api/kanjia/join/list */
export function fetchKanjiaJoins(page: number, pagesize = 20) {
  return get<PageResult<KanjiaJoin>>("/api/kanjia/join/list", { page, pagesize });
}

/** 某次砍价的帮砍明细 GET /api/kanjia/join/helpList */
export function fetchKanjiaHelpList(joinid: number) {
  return get<KanjiaHelpRow[]>("/api/kanjia/join/helpList", { joinid });
}

/** 砍到底价下单 POST /api/kanjia/order/create */
export function kanjiaOrderCreate(joinid: number, contact: ContactInfo) {
  return post<ActivityOrderResult>("/api/kanjia/order/create", {
    joinid,
    linkman: contact.linkman,
    tel: contact.tel,
    area: contact.area,
    address: contact.address,
    message: contact.message,
    platform: "h5",
  });
}

/** 我的砍价订单 GET /api/kanjia/order/list（status ''=全部） */
export function fetchKanjiaOrders(status: string | number, page: number, pagesize = 10) {
  return get<PageResult<KanjiaOrder>>("/api/kanjia/order/list", { status, page, pagesize });
}
