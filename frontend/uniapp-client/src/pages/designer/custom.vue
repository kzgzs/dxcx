<script setup lang="ts">
/**
 * 自定义设计页通用渲染页（P27-4 任务248；任务305 页面信息设置四联动）
 * - 路由：/pages/designer/custom?pageid=x（B 端 chooseurl 下发 /pages/index/index?pageid=x
 *   口径由 navigate.ts 改写指向本页，首页 index.vue 亦做 pageid 兼容重定向）
 * - 消费：GET /api/designer/page?id=pageid（aid 隔离），DpRenderer 渲染 content
 * - 四联动（任务305）：
 *   1. 强制登录：access.needlogin_ok=false → 跳登录页回跳本页
 *   2. 查看权限：access.perm_ok=false → 遮罩「无查看权限」
 *   3. 付费查看：access.payview=1 且未购买 → 遮罩+「支付 X 元查看」走统一支付单（order_type=pageview）
 *   4. 开屏广告：showgg=1 且 gglist 首项有图 → 进页全屏广告，点击跳链接，右上×关闭
 * - topbar 样式：title 设导航标题；导航背景/文字色按 navbg/navtext 口径；页面背景纯色/渐变
 * - 兜底：pageid 非法 / 页面不存在 / 已删除 → 提示并返回首页
 */
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import DpRenderer from "../../components/dp/DpRenderer.vue";
import CustomTabbar from "../../components/custom-tabbar.vue";
import { request } from "../../utils/request";
import {
  pageBuy,
  pagePay,
  type DesignerPage,
  type DpNode,
  type PageAccess,
  type TopbarParams,
} from "../../api/designer";
import { LOGIN_PAGE } from "../../config";
import { useSessionStore } from "../../stores/session";
import { useThemeStore } from "../../stores/theme";
import { dpNavigate } from "../../components/dp/navigate";

const session = useSessionStore();
const theme = useThemeStore();

const mode = ref<"loading" | "ready" | "gate">("loading");
/** 系统门控类型（任务308：close=系统关闭 / perm=访问条件不符） */
const gateType = ref<"close" | "perm">("close");
/** 设计器 content 组件数组 */
const dpContent = ref<DpNode[]>([]);
/** topbar 下发的页面背景（纯色 hex 或渐变 linear-gradient） */
const pageBg = ref("#F8F8F8");
/** 页面级访问判定（后端下发） */
const access = ref<PageAccess>({});
/** 无查看权限遮罩 */
const permBlocked = ref(false);
/** 付费查看遮罩 */
const payBlocked = ref(false);
const paying = ref(false);
/** 开屏广告（showgg=1 且首项有图） */
const ggShow = ref(false);
const ggImage = ref("");
const ggUrl = ref("");
/** 当前页面 id（支付成功后重新拉取用） */
const curPageId = ref(0);

/** 应用 topbar：标题/导航背景色/导航文字色/页面背景（任务309：跟随系统口径同 B端） */
function applyTopbar(pageinfo: DpNode[]) {
  const topbar = (pageinfo || []).find((n) => n && n.temp === "topbar");
  const params = ((topbar && topbar.params) || {}) as TopbarParams;
  const title = (params.title || "").trim();
  if (title) {
    uni.setNavigationBarTitle({ title });
  }

  // 导航背景色（任务309）：页面自定义优先；跟随系统=系统 navbgmode?navbgcolor:主色调
  const s = theme.sysset;
  const pageNavBg =
    Number(params.navbgmode || 0) === 1
      ? String(params.navbgcolor || "").trim()
      : "";
  const sysCustom = String(s?.navbgcolor || "").trim();
  const navBg =
    pageNavBg ||
    (Number(s?.navbgmode || 0) === 1 && sysCustom
      ? sysCustom
      : String(s?.color1 || "").trim()) ||
    "#F7F7F7";
  // 导航文字色（任务309）：页面 1白/2黑；跟随系统=系统 navtextcolor（1白/0黑）
  const navTextMode = Number(params.navtextmode || 0);
  const frontColor =
    navTextMode === 1
      ? "#ffffff"
      : navTextMode === 2
        ? "#000000"
        : Number(s?.navtextcolor || 0) === 1
          ? "#ffffff"
          : "#000000";
  uni.setNavigationBarColor({ frontColor, backgroundColor: navBg });

  // 页面主区域背景：bgmode 0纯色/1渐变（bgdir 0上下/1左右，任务307）
  const c1 = String(params.bgcolor || "").trim();
  if (c1) {
    pageBg.value =
      Number(params.bgmode || 0) === 1
        ? `linear-gradient(${Number(params.bgdir || 0) === 1 ? "90deg" : "180deg"}, ${c1}, ${String(params.bgcolor2 || "#FFFFFF").trim() || "#FFFFFF"})`
        : c1;
  }
  return params;
}

/** 开屏广告展示人群判定（任务306；无关注体系时以登录态近似关注态：未登录=未关注） */
function ggCrowdOk(crowd?: Record<string, unknown>): boolean {
  const c = crowd || {};
  if (Object.keys(c).length === 0 || c.all) return true;
  const logged = session.isLoggedIn;
  const levelid = Number(access.value.levelid || 0);
  if (c.followed && logged) return true;
  if (c.unfollowed && !logged) return true;
  if (c.member && logged) return true;
  if (c.dealer && logged && levelid >= 5) return true;
  return false;
}

/** 应用开屏广告：showgg=1 + 首项有图 + 人群命中 + 展示方式（首次=storage 页级去重） */
function applySplash(params: TopbarParams) {
  if (Number(params.showgg || 0) !== 1) return;
  const first = (params.gglist || [])[0] || {};
  const img = String(first.image || "").trim();
  if (!img) return;
  if (!ggCrowdOk(access.value.ggcrowd)) return;
  const mode = String(access.value.ggmode ?? params.ggmode ?? "0");
  if (mode !== "1") {
    const key = "dp_gg_shown_" + curPageId.value;
    if (uni.getStorageSync(key)) return;
    uni.setStorageSync(key, 1);
  }
  ggImage.value = img;
  ggUrl.value = String(first.url || "").trim();
  ggShow.value = true;
}

/** 广告点击：有链接跳链接，无链接仅关闭 */
function onGgTap() {
  ggShow.value = false;
  if (ggUrl.value) {
    dpNavigate(ggUrl.value);
  }
}

/** 门控页去登录（访问条件不符且未登录时） */
function goLogin() {
  uni.navigateTo({ url: LOGIN_PAGE });
}

/** 页面不存在/已删除：友好提示并返回首页 */
function failBack() {
  uni.showToast({ title: "页面不存在或已删除", icon: "none", duration: 1500 });
  setTimeout(() => {
    uni.reLaunch({ url: "/pages/index/index" });
  }, 1200);
}

async function load(pageid: number) {
  // 系统门控（任务308）：状态关闭 / 访问条件不符 → 提示页拦截
  try {
    await theme.load();
  } catch {
    // 配置拉取失败不阻断页面
  }
  if (theme.blocked) {
    gateType.value = theme.blocked;
    mode.value = "gate";
    return;
  }

  try {
    const page = await request<DesignerPage>({
      url: "/api/designer/page",
      method: "GET",
      data: { id: pageid },
      silent: true,
    });
    const params = applyTopbar(page.pageinfo || []);
    const acc = page.access || {};
    access.value = acc;

    // 1. 强制登录：未登录跳登录页，登录后回跳本页
    if (acc.needlogin_ok === false) {
      const back = "/pages/designer/custom?pageid=" + pageid;
      uni.reLaunch({ url: LOGIN_PAGE + "?redirect=" + encodeURIComponent(back) });
      return;
    }

    // 2. 查看权限不符：遮罩拦截
    if (acc.perm_ok === false) {
      permBlocked.value = true;
      mode.value = "ready";
      return;
    }

    // 3. 付费查看且未购买：遮罩+支付按钮拦截
    if (Number(acc.payview || 0) === 1 && !acc.paid) {
      payBlocked.value = true;
      mode.value = "ready";
      return;
    }

    dpContent.value = Array.isArray(page.content) ? page.content : [];
    mode.value = "ready";

    // 4. 开屏广告（放行后展示）
    applySplash(params);
  } catch {
    // code!==0（页面不存在）或网络异常：静默兜底（silent 未弹 toast）
    failBack();
  }
}

/** 支付查看：下单（pagebuy）→ 支付（pagepay，mock 直接入账）→ 重新拉取放行 */
async function onPayView() {
  if (paying.value) return;
  if (!session.isLoggedIn) {
    const back = "/pages/designer/custom?pageid=" + curPageId.value;
    uni.reLaunch({ url: LOGIN_PAGE + "?redirect=" + encodeURIComponent(back) });
    return;
  }
  paying.value = true;
  try {
    const buy = await pageBuy(curPageId.value);
    if (Number(buy.paid) === 1) {
      payBlocked.value = false;
      await load(curPageId.value);
      return;
    }
    await pagePay(Number(buy.orderid), "wechat");
    uni.showToast({ title: "支付成功", icon: "success" });
    payBlocked.value = false;
    await load(curPageId.value);
  } catch {
    // 错误提示由 request 拦截器统一弹出
  } finally {
    paying.value = false;
  }
}

onLoad((options) => {
  const pageid = parseInt((options && options.pageid) || "", 10);
  if (!pageid || pageid <= 0) {
    failBack();
    return;
  }
  curPageId.value = pageid;
  void load(pageid);
});
</script>

<template>
  <view class="page" :style="{ background: pageBg }">
    <!-- 系统门控拦截（任务308） -->
    <view v-if="mode === 'gate'" class="gate">
      <text class="gate-title">{{ gateType === 'close' ? '系统维护中' : '无访问权限' }}</text>
      <text v-if="gateType === 'close'" class="gate-tip">{{ (theme.gate && theme.gate.close_tip) || '系统已关闭，请稍后再试' }}</text>
      <text v-else class="gate-tip">当前账号不满足访问条件</text>
      <view v-if="theme.gate && !theme.gate.logged" class="gate-btn" @click="goLogin">去登录</view>
    </view>

    <block v-if="mode === 'ready'">
      <dp-renderer :content="dpContent" />

      <!-- 底部导航（任务318）：设计页预览一律渲染；本页不在配置清单内不高亮，点击按 custom-tabbar 既有 reLaunch 口径 -->
      <custom-tabbar current="/pages/designer/custom" />

      <!-- 查看权限遮罩（任务305） -->
      <view v-if="permBlocked" class="dp-mask">
        <view class="dp-mask-text">无查看权限</view>
      </view>

      <!-- 付费查看遮罩（任务305） -->
      <view v-if="payBlocked" class="dp-mask">
        <view class="dp-mask-text">该页面需付费后查看</view>
        <button class="dp-pay-btn" :loading="paying" @click="onPayView">
          支付 {{ access.price || "" }} 元查看
        </button>
      </view>

      <!-- 开屏广告全屏层（任务305） -->
      <view v-if="ggShow" class="dp-gg" @click="onGgTap">
        <image class="dp-gg-img" :src="ggImage" mode="aspectFill" />
        <view class="dp-gg-close" @click.stop="ggShow = false">×</view>
      </view>
    </block>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  /* 任务318：预留底部导航高度，避免内容被固定 tabbar 遮挡 */
  padding-bottom: 140rpx;
}

/* 系统门控拦截页（任务308） */
.gate {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: #ffffff;
}

.gate-title {
  font-size: 34rpx;
  font-weight: 600;
  color: #333333;
}

.gate-tip {
  margin-top: 20rpx;
  font-size: 26rpx;
  color: #999999;
  padding: 0 60rpx;
  text-align: center;
}

.gate-btn {
  margin-top: 48rpx;
  width: 280rpx;
  height: 80rpx;
  line-height: 80rpx;
  text-align: center;
  border-radius: 40rpx;
  background: var(--tp-primary, #fd4a46);
  color: #ffffff;
  font-size: 28rpx;
}

/* 拦截遮罩（无权限/付费） */
.dp-mask {
  position: fixed;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  z-index: 90;
  background: rgba(255, 255, 255, 0.96);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.dp-mask-text {
  font-size: 30rpx;
  color: #666666;
  margin-bottom: 40rpx;
}

.dp-pay-btn {
  width: 400rpx;
  height: 88rpx;
  line-height: 88rpx;
  text-align: center;
  border-radius: 44rpx;
  background: #3db51e;
  color: #ffffff;
  font-size: 30rpx;
}

/* 开屏广告全屏层 */
.dp-gg {
  position: fixed;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  z-index: 99;
  background: #000000;
}

.dp-gg-img {
  width: 100%;
  height: 100%;
}

.dp-gg-close {
  position: absolute;
  right: 24rpx;
  top: calc(env(safe-area-inset-top) + 24rpx);
  width: 64rpx;
  height: 64rpx;
  line-height: 60rpx;
  text-align: center;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.5);
  color: #ffffff;
  font-size: 40rpx;
}
</style>
