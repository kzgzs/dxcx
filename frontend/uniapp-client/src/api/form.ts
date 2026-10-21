import { get, post } from "../utils/request";
import type { PayResult } from "./types";

// ===== C端自定义表单（租户设计表单 → 设计器组件调用 → 终端用户填写提交） =====

/** 表单项定义（form.content JSON 数组元素，口径同租户后台 FormIndex.vue） */
export interface FormFieldDef {
  key: string;
  val1: string;
  val2: unknown;
  val3: number;
  val4: number;
  val8: number;
  val9: number;
  val10: number;
  val11: number;
  val15: string;
}

/** 表单详情（GET /api/form/detail） */
export interface FormDetailResult {
  id: number;
  name: string;
  starttime: string;
  endtime: string;
  payset: number;
  price: string;
  priceedit: number;
  maxlimit: number;
  perlimit: number;
  content: FormFieldDef[];
  mycount: number;
}

/** 提交结果（status=1 提交成功；status=2 需支付，返回 orderid/money） */
export interface FormSubmitResult {
  status: number;
  msg: string;
  orderid: number;
  money?: number;
}

export interface FormMyDataItem {
  id: number;
  formid: number;
  title: string;
  status: number;
  createtime: number;
  paystatus: number;
  money: string;
  ordernum: string;
  paytime: number;
  reason: string;
  isrefund: number;
}

/** 表单详情（匿名） GET /api/form/detail */
export function fetchFormDetail(id: number) {
  return get<FormDetailResult>("/api/form/detail", { id });
}

/** 提交表单（登录） POST /api/form/submit */
export function formSubmit(params: {
  formid: number;
  formdata: Record<string, unknown>;
  price?: number;
  fromurl?: string;
  platform?: string;
}) {
  return post<FormSubmitResult>("/api/form/submit", params);
}

/** 发起表单支付（登录） POST /api/form/pay */
export function formPay(orderId: number, payType: "wechat" | "alipay") {
  return post<PayResult>("/api/form/pay", { order_id: orderId, pay_type: payType });
}

/** 我的表单提交记录（登录） GET /api/form/mydata */
export function fetchFormMyData(page = 1, pagesize = 10) {
  return get<{ list: FormMyDataItem[]; total: number }>("/api/form/mydata", { page, pagesize });
}
