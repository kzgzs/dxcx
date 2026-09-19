import { get, post } from "../../utils/request";
import type {
  ActivityOrderResult,
  ContactInfo,
  PageResult,
  SeckillDetail,
  SeckillListResult,
  SeckillOrder,
} from "./types";

/** 秒杀场次商品列表 GET /api/seckill/product/list（date/seckill_time 过滤场次） */
export function fetchSeckillList(params: {
  date?: string;
  seckill_time?: number;
  page?: number;
  pagesize?: number;
}) {
  return get<SeckillListResult>("/api/seckill/product/list", {
    date: params.date || "",
    seckill_time: params.seckill_time || 0,
    page: params.page || 1,
    pagesize: params.pagesize || 20,
  });
}

/** 秒杀商品详情（规格+场次行） GET /api/seckill/product/detail */
export function fetchSeckillDetail(proid: number, date = "", seckillTime = 0) {
  return get<SeckillDetail>("/api/seckill/product/detail", {
    proid,
    date,
    seckill_time: seckillTime,
  });
}

/** 秒杀下单 POST /api/seckill/order/create */
export function seckillOrderCreate(params: {
  proid: number;
  prodata_id: number;
  ggid: number;
  num: number;
  contact: ContactInfo;
}) {
  return post<ActivityOrderResult>("/api/seckill/order/create", {
    proid: params.proid,
    prodata_id: params.prodata_id,
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

/** 我的秒杀订单 GET /api/seckill/order/list（status ''=全部） */
export function fetchSeckillOrders(status: string | number, page: number, pagesize = 10) {
  return get<PageResult<SeckillOrder>>("/api/seckill/order/list", { status, page, pagesize });
}
