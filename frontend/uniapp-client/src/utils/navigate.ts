/**
 * 统一跳转工具：页面路径集中维护，业务页不直接拼 uni.navigateTo url
 */

/** pagesM 分包内页面路径（与 pages.json 注册一一对应） */
export const PAGES = {
  /** 商品详情 ?id= */
  productDetail: "/pagesM/product/detail",
  /** 搜索结果 ?keyword=&category_id= */
  shopSearch: "/pagesM/shop/search",
  /** 收银台 ?order_id=&amount=&scene=order|recharge */
  pay: "/pagesM/pay/pay",
  /** 订单列表 ?status= */
  orderList: "/pagesM/order/list",
  /** 订单详情 ?id= */
  orderDetail: "/pagesM/order/detail",
  /** 退款单列表 */
  refundList: "/pagesM/refund/list",
  /** 申请退款 ?order_id= */
  refundApply: "/pagesM/refund/apply",
  /** 充值页 */
  recharge: "/pagesM/recharge/index",
  /** 充值订单列表 */
  rechargeOrders: "/pagesM/recharge/orders",
  /** 个人资料 */
  memberProfile: "/pagesM/member/profile",
} as const;

/** 保留当前页，跳转（页面栈最多 10 层） */
export function navigateTo(url: string): void {
  uni.navigateTo({ url });
}

/** 关闭当前页，跳转 */
export function redirectTo(url: string): void {
  uni.redirectTo({ url });
}

/** 关闭所有页面，打开 */
export function reLaunch(url: string): void {
  uni.reLaunch({ url });
}

/** 返回上 N 层 */
export function navigateBack(delta = 1): void {
  uni.navigateBack({ delta });
}

/* ================= pagesH2 营销分包通用跳转 ================= */

/** 主包 tabBar 页面（与 pages.json pages 区域一致） */
const TAB_BAR_PAGES = [
  "/pages/index/index",
  "/pages/category/category",
  "/pages/cart/cart",
  "/pages/my/my",
];

/** 路径去参数 */
function stripQuery(url: string): string {
  const idx = url.indexOf("?");
  return idx >= 0 ? url.slice(0, idx) : url;
}

/**
 * 站内页面智能跳转（pagesH2 分包用）
 * - 站内路径自动补首斜杠
 * - tabBar 页走 switchTab
 * - navigateTo 失败兜底 reLaunch
 */
export function goPage(url: string): void {
  const raw = (url || "").trim();
  if (!raw) return;

  const path = raw.startsWith("/") ? raw : "/" + raw;
  const bare = stripQuery(path);

  if (TAB_BAR_PAGES.includes(bare)) {
    uni.switchTab({ url: bare });
    return;
  }

  uni.navigateTo({
    url: path,
    fail: () => {
      uni.reLaunch({ url: path, fail: () => console.warn("[navigate] 跳转失败：", raw) });
    },
  });
}

/** 返回上一页（无历史时兜底回首页 tab） */
export function goBack(): void {
  const pages = getCurrentPages();
  if (pages.length > 1) {
    uni.navigateBack();
    return;
  }
  uni.switchTab({ url: "/pages/index/index" });
}
