import { get, post } from "../../utils/request";
import type { DepositGroup, DepositOrder, DepositOrderDetail, DepositSysset } from "./types";

/** 全部存酒（按商家分组，仅寄存中） GET /api/restaurantDeposit/orderlist */
export function depositOrderList(page = 1, pagesize = 10) {
  return get<{ datalist: DepositGroup[] }>("/api/restaurantDeposit/orderlist", { page, pagesize });
}

/** 某商家的存酒记录 + 寄存设置 GET /api/restaurantDeposit/orderdetail */
export function depositOrderDetailByBid(bid: number, page = 1, pagesize = 10) {
  return get<{ datalist: DepositOrder[]; sysset: DepositSysset; total: number }>(
    "/api/restaurantDeposit/orderdetail",
    { bid, page, pagesize }
  );
}

/** 单笔存酒记录 + 流水 GET /api/restaurantDeposit/orderlog */
export function depositOrderLog(id: number) {
  return get<{ data: DepositOrderDetail }>("/api/restaurantDeposit/orderlog", { id });
}

/** 添加寄存申请 POST /api/restaurantDeposit/add（status=0 待审核） */
export function depositAdd(params: {
  bid?: number;
  name: string;
  num: number;
  linkman: string;
  tel: string;
  message?: string;
  pic?: string;
}) {
  return post<{ id: number }>("/api/restaurantDeposit/add", { ...params });
}

/** 取件：按数量部分取/全部取 POST /api/restaurantDeposit/takeout（幂等防超取） */
export function depositTakeout(orderid: number, numbers: number) {
  return post<{ left: number; taken: number }>("/api/restaurantDeposit/takeout", { orderid, numbers });
}
