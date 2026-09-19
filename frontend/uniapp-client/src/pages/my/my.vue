<script setup lang="ts">
import { onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import CustomTabbar from "../../components/custom-tabbar.vue";
import DpRenderer from "../../components/dp/DpRenderer.vue";
import { fetchMyCoupons, fetchOrderList, fetchProfile } from "../../api/mall";
import type { DpNode, TopbarParams } from "../../api/designer";
import type { MemberProfile } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { request } from "../../utils/request";
import { useSessionStore } from "../../stores/session";

const session = useSessionStore();

/* ================= 渲染模式（P27-4 任务248）：designer ishome=2 装修页 / default 现有布局 =================
 * 双模式不闪烁：首帧 loading 不渲染任何内容，数据落定后一次性切模式 */
const mode = ref<"loading" | "designer" | "default">("loading");
const dpContent = ref<DpNode[]>([]);
const pageBg = ref("#F8F8F8");
/** 装修页只拉一次（静态配置，避免 tab 切换重复请求） */
let designerChecked = false;

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
function applyTopbar(pageinfo: DpNode[]) {
  const topbar = (pageinfo || []).find((n) => n && n.temp === "topbar");
  const params = ((topbar && topbar.params) || {}) as TopbarParams;
  const title = (params.title || "").trim();
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

/** 拉取会员中心装修页（ishome=2）：有 content 走设计器渲染，无则维持现有布局 */
async function checkDesigner() {
  if (designerChecked) return;
  designerChecked = true;
  try {
    const page = await request<{ pageinfo: DpNode[]; content: DpNode[] }>({
      url: "/api/designer/page",
      method: "GET",
      data: { ishome: 2 },
      silent: true,
    });
    const content = Array.isArray(page.content) ? page.content : [];
    if (content.length > 0) {
      applyTopbar(page.pageinfo || []);
      dpContent.value = content;
      mode.value = "designer";
      return;
    }
  } catch {
    // 接口异常静默走默认布局
  }
  mode.value = "default";
}

const profile = ref<MemberProfile | null>(null);
const couponCount = ref(0);
/** 订单五宫格角标：status 0待付款 1待发货 2待收货 3已完成（退款售后无计数接口，不展示角标） */
const orderBadges = ref<Record<number, number>>({});
const loading = ref(false);

async function loadData() {
  if (!session.isLoggedIn) {
    profile.value = null;
    return;
  }
  loading.value = true;
  try {
    const tasks: Promise<unknown>[] = [
      fetchProfile()
        .then((p) => {
          profile.value = p;
        })
        .catch(() => {
          profile.value = null;
        }),
      fetchMyCoupons(0)
        .then((r) => {
          couponCount.value = r.total || 0;
        })
        .catch(() => {
          couponCount.value = 0;
        }),
    ];
    // 各状态订单计数（pagesize=1 仅取 total）
    [0, 1, 2, 3].forEach((st) => {
      tasks.push(
        fetchOrderList(st, 1, 1)
          .then((r) => {
            orderBadges.value[st] = r.total || 0;
          })
          .catch(() => {
            orderBadges.value[st] = 0;
          })
      );
    });
    await Promise.all(tasks);
  } finally {
    loading.value = false;
  }
}

onShow(() => {
  void checkDesigner();
  loadData();
});

/* ================= 跳转（uni 不在模板作用域，统一抽函数） ================= */

function goLogin() {
  uni.navigateTo({ url: "/pages/login/login?redirect=/pages/my/my" });
}

function goProfile() {
  if (!session.isLoggedIn) {
    goLogin();
    return;
  }
  uni.navigateTo({ url: "/pagesM/member/profile" });
}

function goScoreLog() {
  uni.navigateTo({ url: "/pagesM/score/log" });
}

function goCouponMine() {
  uni.navigateTo({ url: "/pagesM/coupon/mine" });
}

function goCouponCenter() {
  uni.navigateTo({ url: "/pagesM/coupon/center" });
}

function goFavorite() {
  uni.showToast({ title: "收藏功能建设中，敬请期待", icon: "none" });
}

function goAddress() {
  uni.navigateTo({ url: "/pagesM/address/list" });
}

function goSetting() {
  uni.navigateTo({ url: "/pagesM/member/setting" });
}

function goOrderList(status: number | "") {
  const query = status === "" ? "" : `?status=${status}`;
  uni.navigateTo({ url: `/pagesM/order/list${query}` });
}

function logout() {
  uni.showModal({
    title: "提示",
    content: "确定退出登录吗？",
    success: (res) => {
      if (res.confirm) {
        session.clearSession();
        profile.value = null;
        couponCount.value = 0;
        orderBadges.value = {};
        uni.showToast({ title: "已退出登录", icon: "none" });
      }
    },
  });
}
</script>

<template>
  <view class="page" :style="{ background: pageBg }">
    <!-- 装修页渲染（ishome=2） -->
    <block v-if="mode === 'designer'">
      <dp-renderer :content="dpContent" />
    </block>

    <!-- 默认布局（无装修页时保持现状） -->
    <block v-if="mode === 'default'">
      <!-- 头部：头像 + 昵称 + ID + 等级 -->
    <view class="header" @click="goProfile">
      <template v-if="session.isLoggedIn">
        <image
          v-if="profile && profile.headimg"
          class="avatar"
          :src="imgUrl(profile.headimg)"
          mode="aspectFill"
        />
        <view v-else class="avatar avatar-text">
          <text>{{ (profile && profile.nickname) || (session.member.nickname || "会员").charAt(0) }}</text>
        </view>
        <view class="user-meta">
          <view class="name-row">
            <text class="nickname">{{ (profile && profile.nickname) || session.member.nickname || "会员" }}</text>
            <text class="level">{{ profile && profile.levelid > 0 ? `Lv.${profile.levelid}` : "普通会员" }}</text>
          </view>
          <text class="uid">ID: {{ profile ? profile.mid : session.member.id || "--" }}</text>
        </view>
        <text class="header-arrow">></text>
      </template>
      <template v-else>
        <view class="avatar avatar-text"><text>客</text></view>
        <view class="user-meta">
          <text class="nickname">未登录</text>
          <text class="uid">点击登录，享更多权益 ></text>
        </view>
      </template>
    </view>

    <!-- 未登录引导卡 -->
    <view v-if="!session.isLoggedIn" class="login-card">
      <text class="login-tip">登录后同步订单、优惠券、积分等权益</text>
      <view class="login-btn" @click="goLogin"><text>立即登录</text></view>
    </view>

    <!-- 资产四宫格 -->
    <view class="card asset-card">
      <view class="asset-item" @click="goLogin">
        <text class="asset-num">￥{{ price2(profile ? profile.money : 0) }}</text>
        <text class="asset-label">余额</text>
      </view>
      <view class="asset-item">
        <text class="asset-num">￥0.00</text>
        <text class="asset-label">佣金</text>
      </view>
      <view class="asset-item" @click="session.isLoggedIn && goScoreLog()">
        <text class="asset-num">{{ profile ? profile.score : 0 }}</text>
        <text class="asset-label">积分</text>
      </view>
      <view class="asset-item" @click="session.isLoggedIn && goCouponMine()">
        <text class="asset-num">{{ couponCount }}</text>
        <text class="asset-label">优惠券</text>
      </view>
    </view>

    <!-- 我的订单五宫格 -->
    <view class="card">
      <view class="card-title" @click="goOrderList('')">
        <text class="card-title-text">我的订单</text>
        <text class="card-title-more">查看全部 ></text>
      </view>
      <view class="order-grid">
        <view class="order-item" @click="goOrderList(0)">
          <view class="badge-wrap">
            <text class="order-icon">💰</text>
            <text v-if="(orderBadges[0] || 0) > 0" class="badge">{{ orderBadges[0] }}</text>
          </view>
          <text class="order-label">待付款</text>
        </view>
        <view class="order-item" @click="goOrderList(1)">
          <view class="badge-wrap">
            <text class="order-icon">📦</text>
            <text v-if="(orderBadges[1] || 0) > 0" class="badge">{{ orderBadges[1] }}</text>
          </view>
          <text class="order-label">待发货</text>
        </view>
        <view class="order-item" @click="goOrderList(2)">
          <view class="badge-wrap">
            <text class="order-icon">🚚</text>
            <text v-if="(orderBadges[2] || 0) > 0" class="badge">{{ orderBadges[2] }}</text>
          </view>
          <text class="order-label">待收货</text>
        </view>
        <view class="order-item" @click="goOrderList(3)">
          <view class="badge-wrap">
            <text class="order-icon">✅</text>
            <text v-if="(orderBadges[3] || 0) > 0" class="badge">{{ orderBadges[3] }}</text>
          </view>
          <text class="order-label">已完成</text>
        </view>
        <view class="order-item" @click="goOrderList('')">
          <view class="badge-wrap">
            <text class="order-icon">🔄</text>
          </view>
          <text class="order-label">退款售后</text>
        </view>
      </view>
    </view>

    <!-- 常用工具 -->
    <view class="card">
      <view class="card-title">
        <text class="card-title-text">常用工具</text>
      </view>
      <view class="menu-item" @click="goCouponMine">
        <text>🎟️ 我的优惠券</text>
        <text class="arrow">></text>
      </view>
      <view class="menu-item" @click="goCouponCenter">
        <text>🎁 领券中心</text>
        <text class="arrow">></text>
      </view>
      <view class="menu-item" @click="goFavorite">
        <text>⭐ 我的收藏</text>
        <text class="arrow">></text>
      </view>
      <view class="menu-item" @click="goScoreLog">
        <text>💎 积分明细</text>
        <text class="arrow">></text>
      </view>
      <view class="menu-item" @click="goAddress">
        <text>📍 收货地址</text>
        <text class="arrow">></text>
      </view>
      <view class="menu-item" @click="goSetting">
        <text>⚙️ 设置</text>
        <text class="arrow">></text>
      </view>
    </view>

    <!-- 退出登录 -->
    <view v-if="session.isLoggedIn" class="logout-btn" @click="logout">
      <text>退出登录</text>
    </view>
    </block>

    <custom-tabbar current="/pages/my/my" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 140rpx;
}
.header {
  display: flex;
  align-items: center;
  padding: 56rpx 32rpx 72rpx;
  background: linear-gradient(135deg, #7b7de5, #a3a5f0);
}
.avatar {
  width: 120rpx;
  height: 120rpx;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.25);
  flex-shrink: 0;
}
.avatar-text {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  font-size: 48rpx;
}
.user-meta {
  margin-left: 24rpx;
  flex: 1;
  display: flex;
  flex-direction: column;
}
.name-row {
  display: flex;
  align-items: center;
}
.nickname {
  color: #ffffff;
  font-size: 36rpx;
  font-weight: 600;
}
.level {
  margin-left: 16rpx;
  padding: 2rpx 16rpx;
  border-radius: 20rpx;
  background: rgba(255, 255, 255, 0.25);
  color: #ffffff;
  font-size: 22rpx;
}
.uid {
  margin-top: 12rpx;
  color: rgba(255, 255, 255, 0.85);
  font-size: 26rpx;
}
.header-arrow {
  color: rgba(255, 255, 255, 0.85);
  font-size: 32rpx;
}
.login-card {
  margin: -36rpx 24rpx 24rpx;
  padding: 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.04);
}
.login-tip {
  font-size: 26rpx;
  color: #666666;
}
.login-btn {
  padding: 14rpx 40rpx;
  border-radius: 32rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 28rpx;
}
.card {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
}
.asset-card {
  margin-top: -36rpx;
  display: flex;
  box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.04);
}
.asset-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 28rpx 0;
}
.asset-num {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.asset-label {
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #999999;
}
.card-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28rpx 32rpx 8rpx;
}
.card-title-text {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.card-title-more {
  font-size: 24rpx;
  color: #999999;
}
.order-grid {
  display: flex;
  padding: 16rpx 0 28rpx;
}
.order-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.badge-wrap {
  position: relative;
}
.order-icon {
  font-size: 44rpx;
}
.badge {
  position: absolute;
  top: -10rpx;
  right: -18rpx;
  min-width: 30rpx;
  height: 30rpx;
  line-height: 30rpx;
  padding: 0 8rpx;
  border-radius: 16rpx;
  background: #e64340;
  color: #ffffff;
  font-size: 20rpx;
  text-align: center;
  box-sizing: border-box;
}
.order-label {
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #666666;
}
.menu-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28rpx 32rpx;
  font-size: 28rpx;
  color: #333333;
  border-bottom: 1rpx solid #f2f2f2;
}
.menu-item:last-child {
  border-bottom: none;
}
.arrow {
  color: #cccccc;
}
.logout-btn {
  margin: 32rpx 24rpx;
  padding: 24rpx 0;
  background: #ffffff;
  border-radius: 16rpx;
  text-align: center;
  color: #e64340;
  font-size: 30rpx;
}
</style>
