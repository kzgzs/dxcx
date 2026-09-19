import { post } from "../../utils/request";
import type { MockPayResult } from "./types";

/**
 * PAY_MOCK 模拟第三方回调（仅后端 PAY_MOCK=1 生效）
 * 下单返回 status=0（未支付）时用它模拟支付成功入账
 */
export function mockPay(orderSn: string) {
  return post<MockPayResult>("/api/pay/notify/mock", { order_sn: orderSn });
}

/** 订单状态文案（0未付 1已付 2已发货 3已收货 4已关闭，三域通用） */
export function orderStatusText(status: number): string {
  switch (status) {
    case 1:
      return "已支付";
    case 2:
      return "已发货";
    case 3:
      return "已收货";
    case 4:
      return "已关闭";
    default:
      return "待支付";
  }
}
