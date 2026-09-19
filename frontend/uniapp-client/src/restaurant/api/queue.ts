import { get, post } from "../../utils/request";
import type { QueueHomeResult, QueueRecordRow } from "./types";

/** 排队首页：分类+等待数 / 最近叫号 / 我的排队 GET /api/restaurantQueue/index */
export function queueHome() {
  return get<QueueHomeResult>("/api/restaurantQueue/index");
}

/** 取号 POST /api/restaurantQueue/quhao（同会员同日仅一单） */
export function queueQuhao(params: { cid: number; linkman: string; tel: string; renshu: string }) {
  return post<{ id: number; queue_no: string }>("/api/restaurantQueue/quhao", { ...params });
}

/** 取消排队（仅本人排队中） POST /api/restaurantQueue/cancel */
export function queueCancel(id: number) {
  return post<null>("/api/restaurantQueue/cancel", { id });
}

/** 我的取号记录 GET /api/restaurantQueue/record（status 0排队中 1已入座 2过号 3已取消） */
export function queueRecord(page: number, pagesize = 20) {
  return get<{ list: QueueRecordRow[]; total: number; page: number; pagesize: number }>(
    "/api/restaurantQueue/record",
    { page, pagesize }
  );
}
