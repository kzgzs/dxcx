import { get, post } from "../../utils/request";
import type {
  ContactInfo,
  PageResult,
  ScoreshopDetail,
  ScoreshopOrder,
  ScoreshopOrderResult,
  ScoreshopProduct,
} from "./types";

/** 积分商城商品列表（上架） GET /api/scoreshop/product/list */
export function fetchScoreshopProducts(params: {
  page?: number;
  pagesize?: number;
  keyword?: string;
  cid?: number;
}) {
  return get<PageResult<ScoreshopProduct>>("/api/scoreshop/product/list", {
    page: params.page || 1,
    pagesize: params.pagesize || 10,
    keyword: params.keyword || "",
    cid: params.cid || 0,
  });
}

/** 积分商城商品详情（含规格） GET /api/scoreshop/product/detail */
export function fetchScoreshopDetail(proid: number) {
  return get<ScoreshopDetail>("/api/scoreshop/product/detail", { proid });
}

/** 积分兑换下单 POST /api/scoreshop/order/create（纯积分同事务直扣即已付） */
export function scoreshopOrderCreate(params: {
  proid: number;
  ggid: number;
  num: number;
  contact: ContactInfo;
}) {
  return post<ScoreshopOrderResult>("/api/scoreshop/order/create", {
    proid: params.proid,
    ggid: params.ggid,
    num: params.num,
    linkman: params.contact.linkman,
    tel: params.contact.tel,
    area: params.contact.area,
    address: params.contact.address,
    message: params.contact.message,
    platform: "h5",
  });
}

/** 我的积分商城订单 GET /api/scoreshop/order/list（status ''=全部） */
export function fetchScoreshopOrders(status: string | number, page: number, pagesize = 10) {
  return get<PageResult<ScoreshopOrder>>("/api/scoreshop/order/list", { status, page, pagesize });
}

/** 我的积分余额 GET /api/member/score（兑换页展示可用积分） */
export function fetchMyScoreBalance() {
  return get<{ score: number }>("/api/member/score");
}
