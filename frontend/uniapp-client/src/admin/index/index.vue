<script setup lang="ts">
/**
 * 手机端后台首页（严格对照参考项目 canzhao/uniapp/admin/index/index.vue 结构）
 *
 * 对照要点：
 * - 顶部：店铺名 + > 箭头（参考页 head-view/user-info）
 * - 今日收款 / 今日订单 两块统计（参考页 today-data）
 * - 商城订单块：待付款/待发货/待收货/退款售后/已完成 五计数 + 全部订单入口（参考页 mall-orders）
 * - 快捷功能：核销记录/商品管理/消息通知/切换账号/修改密码（参考页 meun-view）
 * - 菜品管理组：添加菜品/菜品列表/菜品分类/添加分类/餐桌编辑/餐桌分类/点餐清台（参考页 menu-manage）
 * - 底部自绘 Tab：会员/咨询/财务/我的，reLaunch 切换（参考页 tabbar）
 *
 * 权限显隐本期简化：参考页按 auth_data.* 控制，本期按 isadmin 全显。
 * 接口异常（404/500）统计回退为 0 并照常渲染，不白屏。
 */
import { onLoad, onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import AdminTabbar from "../components/admin-tabbar.vue";
import {
  clearAdminSession,
  fetchAdminStats,
  getAdminInfo,
  guardAdmin,
  saveAdminSession,
  type AdminStats,
} from "../api";

/** 店铺名（参考页 set.name，本期取管理员信息兜底） */
const shopName = ref("我的店铺");
/** 今日统计（契约字段：today_money/today_order/pay/send/receive/refund/done） */
const stats = ref<AdminStats>({});

onLoad((options) => {
  // 免登直达（任务272）：B端后台手机框 iframe 嵌入时携带 mtoken（autologin 换发的手机端 token），
  // 写入 storage 键 adminidx_token 后照常渲染，后续 onShow 会拉取统计
  const mtoken = String((options && options.mtoken) || "");
  if (mtoken) {
    saveAdminSession(mtoken, null);
    loadStats();
  }
});

onShow(() => {
  // 未登录先跳登录页
  if (!guardAdmin()) return;
  const admin = getAdminInfo();
  shopName.value =
    String(admin.shopname || admin.name || admin.nickname || admin.username || "我的店铺");
  loadStats();
});

/** 拉取首页统计：失败静默回退 0（后端并行未到时空态不白屏） */
function loadStats() {
  fetchAdminStats()
    .then((res) => {
      stats.value = res || {};
    })
    .catch(() => {
      stats.value = {};
    });
}

/** 数字安全展示 */
function num(v: unknown): string {
  const n = Number(v);
  return Number.isFinite(n) ? String(n) : "0";
}

/* ================= 跳转（对照参考页 goto data-url） ================= */

/** 顶部店铺名 > ：进入 C 端店铺首页 */
function goShop() {
  uni.reLaunch({ url: "/pages/index/index" });
}

/** 全部订单 */
function goAllOrder() {
  uni.navigateTo({ url: "/admin/order/list" });
}

/** 商城订单五计数：st=0待付款 1待发货 2待收货 4退款售后 3已完成 */
function goOrder(st: number) {
  uni.navigateTo({ url: `/admin/order/list?st=${st}` });
}

/** 快捷功能入口 */
function goHexiao() {
  uni.navigateTo({ url: "/admin/hexiao/record" });
}
function goProduct() {
  uni.navigateTo({ url: "/admin/product/index" });
}
function goNotice() {
  uni.navigateTo({ url: "/admin/index/setnotice" });
}
/** 切换账号：清管理端会话后回登录页（对照参考页 data-url="login"） */
function switchAccount() {
  clearAdminSession();
  uni.reLaunch({ url: "/admin/index/login" });
}
function goSetpwd() {
  uni.navigateTo({ url: "/admin/index/setpwd" });
}

/** 菜品管理组（本期跳骨架页） */
function goRestaurant(path: string) {
  uni.navigateTo({ url: `/admin/restaurant/${path}` });
}
</script>

<template>
  <view class="page">
    <!-- 顶部：店铺名 + >（对照参考页 head-view） -->
    <view class="head-class">
      <view class="head-view" @click="goShop">
        <text class="shop-name">{{ shopName }}</text>
        <text class="shop-arrow">></text>
      </view>

      <!-- 今日收款 / 今日订单（对照参考页 today-data） -->
      <view class="today-data">
        <view class="option-view">
          <text class="title-text">今日收款</text>
          <view class="num-row">
            <text class="unit-money">￥</text>
            <text class="num-text">{{ num(stats.today_money) }}</text>
          </view>
        </view>
        <view class="option-view">
          <text class="title-text">今日订单</text>
          <view class="num-row">
            <text class="num-text">{{ num(stats.today_order) }}</text>
          </view>
        </view>
      </view>
    </view>

    <!-- 商城订单块（对照参考页 mall-orders：五计数 + 全部订单入口） -->
    <view class="card mall-orders">
      <view class="order-title">
        <view class="title-wrap">
          <view class="title-dot"></view>
          <text class="card-title-text">商城订单</text>
        </view>
        <view class="all-text" @click="goAllOrder">
          <text>全部订单</text>
          <text class="arrow">></text>
        </view>
      </view>
      <view class="order-list">
        <view class="option-order" @click="goOrder(0)">
          <text class="num-text">{{ num(stats.pay) }}</text>
          <text class="order-label">待付款</text>
        </view>
        <view class="option-order" @click="goOrder(1)">
          <text class="num-text">{{ num(stats.send) }}</text>
          <text class="order-label">待发货</text>
        </view>
        <view class="option-order" @click="goOrder(2)">
          <text class="num-text">{{ num(stats.receive) }}</text>
          <text class="order-label">待收货</text>
        </view>
        <view class="option-order" @click="goOrder(4)">
          <text class="num-text">{{ num(stats.refund) }}</text>
          <text class="order-label">退款/售后</text>
        </view>
        <view class="option-order" @click="goOrder(3)">
          <text class="num-text">{{ num(stats.done) }}</text>
          <text class="order-label">已完成</text>
        </view>
      </view>
    </view>

    <!-- 快捷功能（对照参考页 meun-view；权限显隐本期按 isadmin 全显） -->
    <view class="card">
      <view class="menu-grid">
        <view class="menu-option" @click="goHexiao">
          <view class="menu-icon-box bg1"><text class="menu-icon">🧾</text></view>
          <text class="menu-text">核销记录</text>
        </view>
        <view class="menu-option" @click="goProduct">
          <view class="menu-icon-box bg2"><text class="menu-icon">🛍️</text></view>
          <text class="menu-text">商品管理</text>
        </view>
        <view class="menu-option" @click="goNotice">
          <view class="menu-icon-box bg3"><text class="menu-icon">🔔</text></view>
          <text class="menu-text">消息通知</text>
        </view>
        <view class="menu-option" @click="switchAccount">
          <view class="menu-icon-box bg4"><text class="menu-icon">🔁</text></view>
          <text class="menu-text">切换账号</text>
        </view>
        <view class="menu-option" @click="goSetpwd">
          <view class="menu-icon-box bg5"><text class="menu-icon">🔑</text></view>
          <text class="menu-text">修改密码</text>
        </view>
      </view>
    </view>

    <!-- 菜品管理组（对照参考页 menu-manage，本期跳骨架页） -->
    <view class="card menu-manage">
      <view class="menu-title-bar">
        <view class="title-dot"></view>
        <text class="card-title-text">菜品管理</text>
      </view>
      <view class="menu-grid">
        <view class="menu-option" @click="goRestaurant('product/edit')">
          <view class="menu-icon-box bg2"><text class="menu-icon">➕</text></view>
          <text class="menu-text">添加菜品</text>
        </view>
        <view class="menu-option" @click="goRestaurant('product/index')">
          <view class="menu-icon-box bg1"><text class="menu-icon">🍽️</text></view>
          <text class="menu-text">菜品列表</text>
        </view>
        <view class="menu-option" @click="goRestaurant('category/index')">
          <view class="menu-icon-box bg3"><text class="menu-icon">🗂️</text></view>
          <text class="menu-text">菜品分类</text>
        </view>
        <view class="menu-option" @click="goRestaurant('category/edit')">
          <view class="menu-icon-box bg4"><text class="menu-icon">🏷️</text></view>
          <text class="menu-text">添加分类</text>
        </view>
        <view class="menu-option" @click="goRestaurant('table/index')">
          <view class="menu-icon-box bg5"><text class="menu-icon">🪑</text></view>
          <text class="menu-text">餐桌编辑</text>
        </view>
        <view class="menu-option" @click="goRestaurant('table/category')">
          <view class="menu-icon-box bg1"><text class="menu-icon">📋</text></view>
          <text class="menu-text">餐桌分类</text>
        </view>
        <view class="menu-option" @click="goRestaurant('table/waiter')">
          <view class="menu-icon-box bg2"><text class="menu-icon">🧹</text></view>
          <text class="menu-text">点餐清台</text>
        </view>
      </view>
    </view>

    <!-- 底部占位（自绘 Tab fixed 定位） -->
    <view class="tabbar-holder"></view>
    <!-- 底部自绘 Tab（对照参考页 tabbar：会员/咨询/财务/我的，reLaunch 切换） -->
    <admin-tabbar current="my" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
/* ===== 顶部（对照参考页 head-class，紫色渐变替代远端背景图） ===== */
.head-class {
  padding: 32rpx 30rpx 40rpx;
  background: linear-gradient(135deg, #7b7de5, #a3a5f0);
}
.head-view {
  display: flex;
  align-items: center;
}
.shop-name {
  font-size: 36rpx;
  font-weight: 600;
  color: #ffffff;
}
.shop-arrow {
  margin-left: 12rpx;
  font-size: 32rpx;
  color: rgba(255, 255, 255, 0.85);
}
/* 今日统计（对照参考页 today-data 两块） */
.today-data {
  margin-top: 32rpx;
  display: flex;
  background: rgba(255, 255, 255, 0.14);
  border-radius: 16rpx;
}
.option-view {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 28rpx 0;
}
.title-text {
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.9);
}
.num-row {
  margin-top: 12rpx;
  display: flex;
  align-items: baseline;
}
.unit-money {
  font-size: 26rpx;
  color: #ffffff;
}
.num-text {
  font-size: 40rpx;
  font-weight: 600;
  color: #ffffff;
}
/* ===== 卡片通用 ===== */
.card {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
}
.card-title-text {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.title-dot {
  width: 8rpx;
  height: 28rpx;
  border-radius: 4rpx;
  background: #7b7de5;
  margin-right: 12rpx;
}
.title-wrap,
.menu-title-bar {
  display: flex;
  align-items: center;
}
.arrow {
  color: #cccccc;
  margin-left: 6rpx;
}
/* ===== 商城订单块（对照参考页 mall-orders） ===== */
.order-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28rpx 32rpx 8rpx;
}
.all-text {
  display: flex;
  align-items: center;
  font-size: 24rpx;
  color: #999999;
}
.order-list {
  display: flex;
  padding: 20rpx 0 32rpx;
}
.option-order {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.option-order .num-text {
  font-size: 36rpx;
  font-weight: 600;
  color: #333333;
}
.order-label {
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #666666;
}
/* ===== 快捷功能 / 菜品管理（对照参考页 meun-view/menu-manage 网格） ===== */
.menu-grid {
  display: flex;
  flex-wrap: wrap;
  padding: 24rpx 0 8rpx;
}
.menu-option {
  width: 25%;
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 28rpx;
}
.menu-icon-box {
  width: 88rpx;
  height: 88rpx;
  border-radius: 24rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.menu-icon {
  font-size: 42rpx;
}
.bg1 {
  background: #eef0ff;
}
.bg2 {
  background: #fff3e8;
}
.bg3 {
  background: #e8f8f0;
}
.bg4 {
  background: #fdeeee;
}
.bg5 {
  background: #f0eafd;
}
.menu-text {
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #333333;
}
.menu-manage .menu-title-bar {
  padding: 28rpx 32rpx 0;
}
.tabbar-holder {
  height: 140rpx;
}
</style>
