/**
 * dp 组件统一跳转工具（hrefurl 消费口径）
 * - tel:xxx           → 拨号
 * - copy::xxx         → 复制到剪贴板
 * - http(s)://        → H5 直接打开；其余端提示浏览器打开
 * - 带 pageid 的站内链接 → 自定义设计页，navigateTo 到 /pages/designer/custom（P27-4 任务248：
 *   B 端 chooseurl 下发 /pages/index/index?pageid=x 口径，此前命中 tabBar 走 switchTab 会丢 pageid，现改写指向 custom 页）
 * - /pages/xxx 站内路径 → tabBar 页 reLaunch（自定义 tabbar 无原生 tabBar，switchTab 必静默失败，任务298），其余 navigateTo（失败兜底 reLaunch）
 * - pagesM/xxx 相对路径 → 自动补首斜杠
 */

/** tabBar 页面（pages.json 主包四个 tab 页） */
const TAB_BAR_PAGES = [
  "/pages/index/index",
  "/pages/category/category",
  "/pages/cart/cart",
  "/pages/my/my",
];

/** 自定义设计页渲染入口（pageid 链接统一指向） */
const CUSTOM_PAGE = "/pages/designer/custom";

/** 路径去参数（tabBar 判断用） */
function stripQuery(url: string): string {
  const idx = url.indexOf("?");
  return idx >= 0 ? url.slice(0, idx) : url;
}

/** 提取链接中的 pageid 参数（自定义设计页标识，无则空串） */
function getPageId(url: string): string {
  const idx = url.indexOf("?");
  if (idx < 0) return "";
  for (const kv of url.slice(idx + 1).split("&")) {
    const eq = kv.indexOf("=");
    const key = eq >= 0 ? kv.slice(0, eq) : kv;
    if (key === "pageid") {
      const val = eq >= 0 ? kv.slice(eq + 1) : "";
      try {
        return val ? decodeURIComponent(val) : "";
      } catch {
        return val;
      }
    }
  }
  return "";
}

export function dpNavigate(url: unknown): void {
  const raw = typeof url === "string" ? url.trim() : "";
  if (!raw) return;

  // 拨号
  if (raw.indexOf("tel:") === 0) {
    uni.makePhoneCall({ phoneNumber: raw.slice(4), fail: () => undefined });
    return;
  }

  // 复制
  if (raw.indexOf("copy::") === 0) {
    uni.setClipboardData({
      data: raw.slice(6),
      success: () => uni.showToast({ title: "已复制", icon: "none" }),
    });
    return;
  }

  // 外部链接
  if (/^https?:\/\//.test(raw)) {
    // #ifdef H5
    window.location.href = raw;
    // #endif
    // #ifndef H5
    uni.showToast({ title: "请通过浏览器打开外部链接", icon: "none" });
    // #endif
    return;
  }

  // 站内路径（相对路径补首斜杠）
  const path = raw.startsWith("/") ? raw : "/" + raw;
  const bare = stripQuery(path);

  // 自定义设计页链接（带 pageid）：统一 navigateTo 到 custom 渲染页，
  // 修复旧逻辑中 /pages/index/index?pageid=x 命中 tabBar 走 switchTab 丢失 pageid 的缺陷
  const pageid = getPageId(path);
  if (pageid) {
    uni.navigateTo({
      url: CUSTOM_PAGE + "?pageid=" + encodeURIComponent(pageid),
      fail: () => console.warn("[dp] 自定义页跳转失败：", raw),
    });
    return;
  }

  if (TAB_BAR_PAGES.includes(bare)) {
    // 自定义 tabbar（pages.json 无原生 tabBar）：与 custom-tabbar.vue 同口径 reLaunch，
    // 修复旧版 switchTab 静默失败导致宫格导航点击无反应（任务298）
    uni.reLaunch({ url: path });
    return;
  }

  uni.navigateTo({
    url: path,
    fail: () => {
      // 目标为 tabBar 未登记页或分包路径异常时兜底
      uni.reLaunch({ url: path, fail: () => console.warn("[dp] 跳转失败：", raw) });
    },
  });
}
