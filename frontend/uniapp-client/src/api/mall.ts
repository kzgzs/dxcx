import { get, post, uploadFile } from "../utils/request";
import type {
  AddressItem,
  CartItem,
  CategoryItem,
  CouponRecord,
  CouponTemplate,
  EditProfileResult,
  GoodsDetail,
  GoodsItem,
  HomeData,
  MemberProfile,
  OrderCreateResult,
  OrderItem,
  PageResult,
  PayResult,
  PromotionItem,
  RechargeConfig,
  RechargeCreateResult,
  RechargeOrderItem,
  RefundItem,
  ScoreLogItem,
} from "./types";

/* ================= 首页 / 商品 ================= */

/** 首页聚合 GET /api/index/home */
export function fetchHome() {
  return get<HomeData>("/api/index/home");
}

/** 商品分类 GET /api/goods/category/list */
export function fetchCategoryList() {
  return get<{ list: CategoryItem[] }>("/api/goods/category/list");
}

/** 商品列表 GET /api/goods/list */
export function fetchGoodsList(params: {
  page?: number;
  pagesize?: number;
  keyword?: string;
  category_id?: number | "";
}) {
  return get<PageResult<GoodsItem>>("/api/goods/list", {
    page: params.page || 1,
    pagesize: params.pagesize || 20,
    keyword: params.keyword || "",
    category_id: params.category_id || "",
  });
}

/** 商品详情 GET /api/goods/detail */
export function fetchGoodsDetail(id: number) {
  return get<GoodsDetail>("/api/goods/detail", { id });
}

/* ================= 购物车 ================= */

export function fetchCartList() {
  return get<{ list: CartItem[] }>("/api/cart/list");
}

export function cartAdd(productId: number, num: number) {
  return post<{ id: number }>("/api/cart/add", { product_id: productId, num });
}

export function cartUpdate(id: number, patch: { num?: number; selected?: number }) {
  return post<null>("/api/cart/update", { id, ...patch });
}

export function cartDelete(ids: number[]) {
  return post<null>("/api/cart/delete", { ids });
}

export function cartSelectAll(selected: number) {
  return post<null>("/api/cart/selectAll", { selected });
}

/* ================= 收货地址 ================= */

export function fetchAddressList() {
  return get<AddressItem[]>("/api/address/list");
}

export function addressAdd(data: {
  name: string;
  phone: string;
  province: string;
  city: string;
  district: string;
  detail: string;
  is_default: number;
}) {
  return post<{ id: number }>("/api/address/add", data);
}

export function addressEdit(
  id: number,
  data: {
    name: string;
    phone: string;
    province: string;
    city: string;
    district: string;
    detail: string;
    is_default: number;
  }
) {
  return post<null>("/api/address/edit", { id, ...data });
}

export function addressDelete(id: number) {
  return post<null>("/api/address/delete", { id });
}

/* ================= 优惠券 / 积分 ================= */

/** 我的优惠券 GET /api/coupon/mine（status: ''全部 0未使用 1已使用 2已过期） */
export function fetchMyCoupons(status: string | number = 0) {
  return get<PageResult<CouponRecord>>("/api/coupon/mine", { status, page: 1, pagesize: 100 });
}

/** 我的积分余额 GET /api/member/score */
export function fetchMyScore() {
  return get<{ score: number }>("/api/member/score");
}

/** 生效中的满减促销活动 GET /api/promotion/list（下单时后端自动择优） */
export function fetchPromotionList() {
  return get<{ list: PromotionItem[] }>("/api/promotion/list");
}

/* ================= 会员域 ================= */

/** 会员资料 GET /api/member/profile */
export function fetchProfile() {
  return get<MemberProfile>("/api/member/profile");
}

/** 可领取券列表 GET /api/coupon/list（后端已过滤未开始/已结束/无余量/已限领） */
export function fetchCouponCenterList() {
  return get<{ list: CouponTemplate[] }>("/api/coupon/list");
}

/** 领取优惠券 POST /api/coupon/receive（coupon_id 传券模板 id） */
export function couponReceive(couponId: number) {
  return post<{ id: number }>("/api/coupon/receive", { coupon_id: couponId });
}

/** 积分明细 GET /api/score/log（type: 1支付赠送 2下单抵扣 3取消返还 4后台调整） */
export function fetchScoreLog(page: number, pagesize = 15) {
  return get<PageResult<ScoreLogItem>>("/api/score/log", { page, pagesize });
}

/** 图片上传 POST /api/upload（multipart 字段 file，返回相对路径 url） */
export function uploadImage(filePath: string) {
  return uploadFile<{ url: string }>("/api/upload", filePath);
}

/* ================= 订单 ================= */

/** 创建订单 POST /api/order/create（coupon_id 传券模板 id，use_score 传抵扣积分数） */
export function orderCreate(params: {
  goods_id: number;
  num: number;
  address_id: number;
  coupon_id?: number;
  use_score?: number;
}) {
  return post<OrderCreateResult>("/api/order/create", params);
}

/** 订单列表 GET /api/order/list（status ''=全部） */
export function fetchOrderList(status: string | number, page: number, pagesize = 10) {
  return get<PageResult<OrderItem>>("/api/order/list", { status, page, pagesize });
}

export function fetchOrderDetail(id: number) {
  return get<OrderItem>("/api/order/detail", { id });
}

export function orderCancel(id: number) {
  return post<null>("/api/order/cancel", { id });
}

export function orderConfirm(id: number) {
  return post<null>("/api/order/confirm", { id });
}

/** 发起支付 POST /api/order/pay（PAY_MOCK=1 时返回 {mock:true,status:1} 直接入账） */
export function orderPay(orderId: number, payType: "wechat" | "alipay") {
  return post<PayResult>("/api/order/pay", { order_id: orderId, pay_type: payType });
}

/** 申请退款 POST /api/order/refund */
export function orderRefund(orderId: number, reason: string) {
  return post<null>("/api/order/refund", { order_id: orderId, reason });
}

/* ================= 退款单 ================= */

/** 我的退款单列表 GET /api/refund/list */
export function fetchRefundList(page: number, pagesize = 10) {
  return get<PageResult<RefundItem>>("/api/refund/list", { page, pagesize });
}

/* ================= 充值 ================= */

/** 充值赠送配置 GET /api/recharge/giveset（status 1启用，caninput 1允许自定义金额） */
export function fetchRechargeConfig() {
  return get<RechargeConfig>("/api/recharge/giveset");
}

/** 创建充值单 POST /api/recharge/create */
export function rechargeCreate(money: number) {
  return post<RechargeCreateResult>("/api/recharge/create", { money });
}

/** 充值支付 POST /api/recharge/pay（PAY_MOCK=1 时返回 {mock:true,status:1} 直接入账） */
export function rechargePay(orderId: number, payType: "wechat" | "alipay") {
  return post<PayResult>("/api/recharge/pay", { order_id: orderId, pay_type: payType });
}

/** 我的充值记录 GET /api/recharge/orders */
export function fetchRechargeOrders(page: number, pagesize = 10) {
  return get<PageResult<RechargeOrderItem>>("/api/recharge/orders", { page, pagesize });
}

/* ================= 会员资料编辑 ================= */

/**
 * 保存资料 POST /api/member/editProfile
 * 支持字段：nickname / headimg / realname / sex / birthday；
 * 四项（realname+手机号+sex+birthday）齐全时后端触发完善资料送分（只送一次）
 */
export function memberEditProfile(data: {
  nickname?: string;
  headimg?: string;
  realname?: string;
  sex?: string;
  birthday?: string;
}) {
  return post<EditProfileResult>("/api/member/editProfile", data);
}
