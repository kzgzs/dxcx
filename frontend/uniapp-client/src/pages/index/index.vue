<script setup lang="ts">
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import CustomTabbar from "../../components/custom-tabbar.vue";
import DpRenderer from "../../components/dp/DpRenderer.vue";
import FollowBar from "../../components/follow-bar.vue";
import { fetchHome } from "../../api/mall";
import { fetchDesignerPage } from "../../api/designer";
import type { DpNode, TopbarParams } from "../../api/designer";
import type { HomeData } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { useThemeStore } from "../../stores/theme";
import { useSessionStore } from "../../stores/session";
import { LOGIN_PAGE } from "../../config";

/* ================= 渲染模式：designer 设计器 / default 默认兜底 / gate 门控提示 ================= */
const mode = ref<"loading" | "designer" | "default" | "gate">("loading");
const theme = useThemeStore();
const session = useSessionStore();
const gateType = ref<"" | "close" | "perm">("");

/** 设计器 content 组件数组 */
const dpContent = ref<DpNode[]>([]);
/** topbar 下发的页面背景色 */
const pageBg = ref("#F8F8F8");

/** 简易亮度判断（深色背景配白色导航字） */
function isDarkColor(hex: string): boolean {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
  if (!m) return false;
  const v = parseInt(m[1], 16);
  const r = (v >> 16) & 255;
  const g = (v >> 8) & 255;
  const b = v & 255;
  return r * 0.299 + g * 0.587 + b * 0.114 < 128;
}

/** 应用 topbar：title 设导航栏标题，bgcolor 设背景 */
function applyTopbar(pageinfo: DpNode[], sysName: string) {
  const topbar = (pageinfo || []).find((n) => n && n.temp === "topbar");
  const params = ((topbar && topbar.params) || {}) as TopbarParams;
  const title = (params.title || "").trim() || sysName || "";
  if (title) {
    uni.setNavigationBarTitle({ title });
  }
  const bgcolor = (params.bgcolor || "").trim();
  if (bgcolor) {
    pageBg.value = bgcolor;
    uni.setNavigationBarColor({
      frontColor: isDarkColor(bgcolor) ? "#ffffff" : "#000000",
      backgroundColor: bgcolor,
    });
  }
}

/* ================= 默认兜底布局数据（原首页） ================= */
const banners = ref<HomeData["banners"]>([]);
const promotions = ref<HomeData["promotions"]>([]);
const goods = ref<HomeData["goods"]>([]);
const loaded = ref(false);

/** 后端 banners 一期固定空数组 → 纯色占位 */
const placeholderBanners = [
  { color: "linear-gradient(135deg,#7b7de5,#a3a5f0)", title: "鼎极多端 · 品质好物" },
  { color: "linear-gradient(135deg,#f08fb0,#f6b8cd)", title: "行业应用 · 一站打通" },
];

async function loadDefault() {
  try {
    const data = await fetchHome();
    banners.value = data.banners || [];
    promotions.value = data.promotions || [];
    goods.value = data.goods || [];
  } catch {
    // request 已弹 toast
  } finally {
    loaded.value = true;
  }
}

/** 拉取设计页：有 content 走设计器渲染，空结构/失败走默认布局兜底 */
async function loadDesigner() {
  let sysName = "";
  try {
    await theme.load();
    sysName = ((theme.sysset && theme.sysset.name) || "").trim();
  } catch {
    // sysset 失败不阻塞首页
  }

  // 前端门控（任务308）：状态关停 / 访问条件不符 → 提示页
  if (theme.blocked) {
    gateType.value = theme.blocked;
    mode.value = "gate";
    return;
  }

  try {
    const page = await fetchDesignerPage();
    const content = Array.isArray(page.content) ? page.content : [];
    applyTopbar(page.pageinfo || [], sysName);
    if (content.length > 0) {
      dpContent.value = content;
      mode.value = "designer";
      return;
    }
  } catch {
    // request 已弹 toast，走兜底
  }
  mode.value = "default";
  void loadDefault();
}

onLoad((options) => {
  // pageid 兼容（P27-4 任务248）：B 端自定义页链接口径为 /pages/index/index?pageid=x，
  // 站内 dp 跳转已由 navigate.ts 改写指向 custom 页；此处兜底 H5 直达 URL 等直接落到首页的场景，
  // 重定向到自定义页渲染，首页自身兜底逻辑保持不变
  const pageid = options && options.pageid ? String(options.pageid) : "";
  if (pageid) {
    uni.redirectTo({ url: "/pages/designer/custom?pageid=" + encodeURIComponent(pageid) });
    return;
  }
  void loadDesigner();
});

function goDetail(id: number) {
  uni.navigateTo({ url: "/pagesM/product/detail?id=" + id });
}

function goCategory() {
  uni.reLaunch({ url: "/pages/category/category" });
}

/** 行业应用入口 */
const industryEntries = [
  { icon: "🚗", label: "网约车", url: "/carhailing/index/index" },
  { icon: "🏨", label: "酒店", url: "/hotel/index/index" },
  { icon: "📅", label: "预约", url: "/yuyue/index/index" },
  { icon: "🍜", label: "餐饮", url: "/restaurant/index/index" },
];

function goIndustry(url: string) {
  uni.navigateTo({ url });
}

function goLogin() {
  uni.navigateTo({ url: LOGIN_PAGE + "?redirect=" + encodeURIComponent("/pages/index/index") });
}
</script>

<template>
  <view class="page" :style="{ background: pageBg }">
    <!-- 公众号关注引导条（任务308：仅公众号 H5） -->
    <follow-bar :page-key="1" />

    <!-- 设计器渲染 -->
    <block v-if="mode === 'designer'">
      <dp-renderer :content="dpContent" />
    </block>

    <!-- 门控提示页（任务308：关停/访问条件） -->
    <block v-else-if="mode === 'gate'">
      <view class="gate">
        <text class="gate-title">{{ gateType === "close" ? "系统维护中" : "无访问权限" }}</text>
        <text class="gate-tip">{{
          gateType === "close"
            ? (theme.gate && theme.gate.close_tip) || "系统维护中，请稍后再来"
            : "当前账号不满足系统访问条件"
        }}</text>
        <view v-if="gateType === 'perm' && !session.isLoggedIn" class="gate-btn" @click="goLogin">去登录</view>
      </view>
    </block>

    <!-- 默认布局兜底 -->
    <block v-else-if="mode === 'default'">
      <!-- banner 轮播（无图用纯色占位） -->
      <swiper v-if="banners.length === 0" class="banner" indicator-dots autoplay circular :interval="3500">
        <swiper-item v-for="(b, i) in placeholderBanners" :key="'p' + i">
          <view class="banner-slide" :style="{ background: b.color }">
            <text class="banner-title">{{ b.title }}</text>
          </view>
        </swiper-item>
      </swiper>
      <swiper v-else class="banner" indicator-dots autoplay circular :interval="3500">
        <swiper-item v-for="(b, i) in banners" :key="'b' + i">
          <image v-if="b.image" class="banner-img" :src="imgUrl(b.image)" mode="aspectFill" />
          <view v-else class="banner-slide" :style="{ background: placeholderBanners[i % 2].color }">
            <text class="banner-title">{{ b.title || "活动 Banner" }}</text>
          </view>
        </swiper-item>
      </swiper>

      <!-- 促销条 -->
      <view v-if="promotions.length > 0" class="promo-bar">
        <text class="promo-tag">满减</text>
        <swiper class="promo-swiper" vertical autoplay circular :interval="2500" :display-multiple-items="Math.min(promotions.length, 2)">
          <swiper-item v-for="p in promotions" :key="'pr' + (p.id || 0)">
            <text class="promo-text">{{ p.name || "满减促销进行中" }}</text>
          </swiper-item>
        </swiper>
      </view>

      <!-- 行业应用入口 -->
      <view class="industry-card">
        <view
          v-for="e in industryEntries"
          :key="e.url"
          class="industry-item"
          @click="goIndustry(e.url)"
        >
          <text class="industry-icon">{{ e.icon }}</text>
          <text class="industry-label">{{ e.label }}</text>
        </view>
      </view>

      <!-- 商品网格 -->
      <view class="goods-section">
        <view class="section-head">
          <text class="section-title">为你推荐</text>
          <text class="section-more" @click="goCategory">全部分类 ></text>
        </view>
        <view v-if="goods.length === 0 && loaded" class="empty">
          <text>暂无上架商品</text>
        </view>
        <view class="goods-grid">
          <view v-for="g in goods" :key="g.id" class="goods-card" @click="goDetail(g.id)">
            <image v-if="g.image" class="goods-img" :src="imgUrl(g.image)" mode="aspectFill" />
            <view v-else class="goods-img goods-img-ph">
              <text>暂无图</text>
            </view>
            <view class="goods-info">
              <text class="goods-name">{{ g.name }}</text>
              <view class="price-row">
                <text class="price">￥{{ price2(g.price) }}</text>
                <text v-if="g.market_price > g.price" class="market-price">￥{{ price2(g.market_price) }}</text>
              </view>
            </view>
          </view>
        </view>
      </view>
    </block>

    <custom-tabbar current="/pages/index/index" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 140rpx;
}
.banner {
  height: 300rpx;
}
.banner-slide {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
}
.banner-title {
  color: #ffffff;
  font-size: 36rpx;
  font-weight: 600;
  letter-spacing: 2rpx;
}
.banner-img {
  width: 100%;
  height: 100%;
}
.promo-bar {
  display: flex;
  align-items: center;
  margin: 20rpx 24rpx 0;
  padding: 14rpx 20rpx;
  background: #fff7f0;
  border-radius: 12rpx;
}
.promo-tag {
  flex-shrink: 0;
  padding: 4rpx 14rpx;
  background: #ff6034;
  color: #ffffff;
  font-size: 22rpx;
  border-radius: 8rpx;
  margin-right: 16rpx;
}
.promo-swiper {
  flex: 1;
  height: 64rpx;
}
.promo-text {
  font-size: 26rpx;
  color: #ff6034;
  line-height: 32rpx;
}
.industry-card {
  display: flex;
  margin: 20rpx 24rpx 0;
  padding: 24rpx 0;
  background: #ffffff;
  border-radius: 16rpx;
}
.industry-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.industry-icon {
  width: 88rpx;
  height: 88rpx;
  line-height: 88rpx;
  text-align: center;
  border-radius: 50%;
  background: #f0f0fb;
  font-size: 40rpx;
}
.industry-label {
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #333333;
}
.goods-section {
  margin: 24rpx;
}
.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20rpx;
}
.section-title {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.section-more {
  font-size: 24rpx;
  color: #7b7de5;
}
.empty {
  padding: 80rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.goods-grid {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
}
.goods-card {
  width: 48.5%;
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
  margin-bottom: 20rpx;
}
.goods-img {
  width: 100%;
  height: 330rpx;
  background: #f2f2f5;
}
.goods-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 26rpx;
}
.goods-info {
  padding: 16rpx 20rpx 22rpx;
}
.goods-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
  line-height: 38rpx;
  height: 76rpx;
}
.price-row {
  margin-top: 10rpx;
  display: flex;
  align-items: baseline;
}
.price {
  color: #7b7de5;
  font-size: 32rpx;
  font-weight: 600;
}
.market-price {
  margin-left: 12rpx;
  color: #b5b5b5;
  font-size: 22rpx;
  text-decoration: line-through;
}
.gate {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 200rpx 60rpx 0;
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
  text-align: center;
}
.gate-btn {
  margin-top: 48rpx;
  padding: 16rpx 64rpx;
  border-radius: 999rpx;
  background: var(--tp-primary, #fd4a46);
  color: #ffffff;
  font-size: 28rpx;
}
</style>
