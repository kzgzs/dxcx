import { get, post } from "../utils/request";
import type {
  CarItem,
  CarOrder,
  HotelDetail,
  HotelItem,
  HotelOrder,
  PageResult,
  PayResult,
  RestaurantInfo,
  RestaurantOrder,
  RestaurantProduct,
  RoomAvailableResult,
  YuyueOrder,
  YuyueProduct,
  YuyueSlot,
  YuyueWorker,
} from "./types";

/** 行业下单统一返回口径（id/order_sn/金额字段，券积分可选） */
export interface BizOrderCreateResult {
  id: number;
  order_sn: string;
  amount?: number;
  pay_amount: number;
  coupon_money?: number;
  score_money?: number;
  use_score?: number;
  coupon_id?: number;
  days?: number;
  nights?: number;
  deposit_total?: number;
  hexiao_code?: string;
  book_date?: string;
  book_time?: string;
  table_id?: number;
  goods?: unknown[];
}

/* ================= 网约车 ================= */

/** 车辆列表 GET /api/carhailing/list（category_id=0 全部） */
export function fetchCarList(categoryId = 0) {
  return get<{ list: CarItem[] }>("/api/carhailing/list", { category_id: categoryId });
}

/** 车辆详情 GET /api/carhailing/detail */
export function fetchCarDetail(id: number) {
  return get<CarItem>("/api/carhailing/detail", { id });
}

/**
 * 创建用车订单 POST /api/carhailing/order/create
 * 计价：<4h 拒绝；4~24h 半天价；>=24h ceil(时长/86400)×price_day
 */
export function carOrderCreate(params: { product_id: number; start_time: number; end_time: number }) {
  return post<BizOrderCreateResult>("/api/carhailing/order/create", params);
}

export function fetchCarOrderList(status: string | number, page: number, pagesize = 20) {
  const s = status === -1 || status === "" ? "" : String(status);
  return get<PageResult<CarOrder>>("/api/carhailing/order/list", { status: s, page, pagesize });
}

/** 支付（PAY_MOCK=1 时 mock 直接入账，订单转已支付） */
export function carOrderPay(orderId: number, payType: "wechat" | "alipay" = "wechat") {
  return post<PayResult>("/api/carhailing/order/pay", { order_id: orderId, pay_type: payType });
}

/** 取消（仅待支付） */
export function carOrderCancel(orderId: number) {
  return post<null>("/api/carhailing/order/cancel", { order_id: orderId });
}

/** 退款（仅已支付且用车未开始） */
export function carOrderRefund(orderId: number) {
  return post<PayResult>("/api/carhailing/order/refund", { order_id: orderId });
}

/* ================= 酒店 ================= */

/** 酒店列表 GET /api/hotel/list（star=0 全部） */
export function fetchHotelList(star = 0, keyword = "") {
  return get<{ list: HotelItem[] }>("/api/hotel/list", { star, keyword });
}

/** 酒店详情（含房型） GET /api/hotel/detail */
export function fetchHotelDetail(id: number) {
  return get<HotelDetail>("/api/hotel/detail", { id });
}

/** 房态查询 GET /api/hotel/room/available → {available,nights,amount,deposit} */
export function fetchRoomAvailable(roomId: number, checkin: string, checkout: string) {
  return get<RoomAvailableResult>("/api/hotel/room/available", { room_id: roomId, checkin, checkout });
}

/**
 * 创建预订订单 POST /api/hotel/order/create
 * 计价：amount = nights × price；pay_amount = 房费优惠后 + 押金（随单收）
 */
export function hotelOrderCreate(params: {
  room_id: number;
  checkin_date: string;
  checkout_date: string;
  name: string;
  phone: string;
}) {
  return post<BizOrderCreateResult>("/api/hotel/order/create", params);
}

export function fetchHotelOrderList(status: string | number, page: number, pagesize = 20) {
  const s = status === -1 || status === "" ? "" : String(status);
  return get<PageResult<HotelOrder>>("/api/hotel/order/list", { status: s, page, pagesize });
}

export function hotelOrderPay(orderId: number, payType: "wechat" | "alipay" = "wechat") {
  return post<PayResult>("/api/hotel/order/pay", { order_id: orderId, pay_type: payType });
}

export function hotelOrderCancel(orderId: number) {
  return post<null>("/api/hotel/order/cancel", { order_id: orderId });
}

export function hotelOrderRefund(orderId: number) {
  return post<PayResult>("/api/hotel/order/refund", { order_id: orderId });
}

/* ================= 预约 ================= */

/** 项目列表 GET /api/yuyue/product/list */
export function fetchYuyueProductList(categoryId = 0) {
  return get<{ list: YuyueProduct[] }>("/api/yuyue/product/list", { category_id: categoryId });
}

/** 项目详情 GET /api/yuyue/product/detail */
export function fetchYuyueProductDetail(id: number) {
  return get<YuyueProduct>("/api/yuyue/product/detail", { id });
}

/** 技师列表 GET /api/yuyue/worker/list */
export function fetchWorkerList() {
  return get<{ list: YuyueWorker[] }>("/api/yuyue/worker/list");
}

/** 时段可约 GET /api/yuyue/slot → {slots:[{time,available}]}（09:00-21:00 每小时一档） */
export function fetchSlot(productId: number, workerId: number, date: string) {
  return get<{ slots: YuyueSlot[] }>("/api/yuyue/slot", { product_id: productId, worker_id: workerId, date });
}

/**
 * 创建预约订单 POST /api/yuyue/order/create
 * book_date Y-m-d；book_time 须在 09:00~20:00 档位内且未过
 */
export function yuyueOrderCreate(params: {
  product_id: number;
  worker_id?: number;
  book_date: string;
  book_time: string;
}) {
  return post<BizOrderCreateResult>("/api/yuyue/order/create", params);
}

export function fetchYuyueOrderList(status: string | number, page: number, pagesize = 20) {
  const s = status === -1 || status === "" ? "" : String(status);
  return get<PageResult<YuyueOrder>>("/api/yuyue/order/list", { status: s, page, pagesize });
}

export function yuyueOrderPay(orderId: number, payType: "wechat" | "alipay" = "wechat") {
  return post<PayResult>("/api/yuyue/order/pay", { order_id: orderId, pay_type: payType });
}

/** 退款（仅已支付未核销且预约日期未到；后端无取消接口） */
export function yuyueOrderRefund(orderId: number) {
  return post<PayResult>("/api/yuyue/order/refund", { order_id: orderId });
}

/* ================= 餐饮堂食 ================= */

/** 店铺信息+区域桌台树 GET /api/restaurant/info */
export function fetchRestaurantInfo() {
  return get<RestaurantInfo>("/api/restaurant/info");
}

/** 菜品列表 GET /api/restaurant/product/list */
export function fetchRestaurantProductList(categoryId = 0) {
  return get<{ list: RestaurantProduct[] }>("/api/restaurant/product/list", { category_id: categoryId });
}

/**
 * 创建堂食订单 POST /api/restaurant/order/create
 * goods: [{product_id,num}] 多商品；amount = Σ price×num
 */
export function restaurantOrderCreate(params: {
  table_id: number;
  goods: { product_id: number; num: number }[];
}) {
  return post<BizOrderCreateResult>("/api/restaurant/order/create", params);
}

export function fetchRestaurantOrderList(status: string | number, page: number, pagesize = 20) {
  const s = status === -1 || status === "" ? "" : String(status);
  return get<PageResult<RestaurantOrder>>("/api/restaurant/order/list", { status: s, page, pagesize });
}

export function restaurantOrderPay(orderId: number, payType: "wechat" | "alipay" = "wechat") {
  return post<PayResult>("/api/restaurant/order/pay", { order_id: orderId, pay_type: payType });
}

export function restaurantOrderCancel(orderId: number) {
  return post<null>("/api/restaurant/order/cancel", { order_id: orderId });
}

/** 完成订单（契约：status 1已支付→2已完成，幂等；完成后桌台 busy 释放） */
export function restaurantOrderComplete(orderId: number) {
  return post<null>("/api/restaurant/order/complete", { order_id: orderId });
}
