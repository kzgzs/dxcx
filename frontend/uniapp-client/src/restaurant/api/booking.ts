import { get, post } from "../../utils/request";
import type { PayResult, PageResult } from "../../api/types";
import type {
  BookableTable,
  BookingCreateResult,
  BookingOrder,
  BookingOrderDetail,
  BookingSet,
  BookingTableCategory,
} from "./types";

/** 预定设置 + 可约时段 GET /api/restaurant/booking/add（匿名可查） */
export function bookingAddInfo() {
  return get<BookingSet>("/api/restaurant/booking/add");
}

/** 创建预定单 POST /api/restaurant/booking/add（同会员同桌同时段未支付单复用更新） */
export function bookingCreate(params: {
  tableId: number;
  time: string;
  renshu: number;
  tel: string;
  linkman: string;
  message: string;
}) {
  return post<BookingCreateResult>("/api/restaurant/booking/add", { ...params });
}

/** 桌台分类列表 GET /api/restaurant/booking/tableCategory */
export function bookingTableCategory() {
  return get<{ list: BookingTableCategory[]; set: { show_booking_fee: number; status: number } }>(
    "/api/restaurant/booking/tableCategory"
  );
}

/** 可预定桌台列表 GET /api/restaurant/booking/tableList（指定时段排除已被预定桌台） */
export function bookingTableList(cid = 0, time = "") {
  return get<{ list: BookableTable[] }>("/api/restaurant/booking/tableList", { cid, time });
}

/** 我的预定订单 GET /api/restaurant/booking/orderlist（st ''全部 0待支付 1待审核 3已完成 4已关闭） */
export function bookingOrderList(st: string, page: number, pagesize = 10) {
  return get<PageResult<BookingOrder>>("/api/restaurant/booking/orderlist", { st, page, pagesize });
}

/** 预定订单详情 GET /api/restaurant/booking/detail */
export function bookingDetail(id: number) {
  return get<BookingOrderDetail>("/api/restaurant/booking/detail", { id });
}

/** 取消预定 POST /api/restaurant/booking/del（已支付自动原路退款后关闭） */
export function bookingCancel(id: number) {
  return post<null>("/api/restaurant/booking/del", { id });
}

/** 支付预定定金 POST /api/restaurant/booking/pay（PAY_MOCK=1 时 mock 直接入账） */
export function bookingPay(id: number, paytype: "wechat" | "alipay" = "wechat") {
  return post<PayResult>("/api/restaurant/booking/pay", { id, paytype });
}
