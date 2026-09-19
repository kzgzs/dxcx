<script setup lang="ts">
import { ref } from "vue";
import { useSessionStore } from "../../stores/session";

const session = useSessionStore();

/** 当前缓存占用（KB，近似） */
const cacheSize = ref("0.0");

function calcCache() {
  try {
    const info = uni.getStorageInfoSync();
    cacheSize.value = (info.currentSize || 0).toFixed(1);
  } catch {
    cacheSize.value = "0.0";
  }
}

calcCache();

function clearCache() {
  uni.showModal({
    title: "提示",
    content: "清除缓存将退出登录，是否继续？",
    success: (res) => {
      if (!res.confirm) return;
      try {
        uni.clearStorageSync();
      } catch {
        // ignore
      }
      session.clearSession();
      cacheSize.value = "0.0";
      uni.showToast({ title: "缓存已清除", icon: "success" });
    },
  });
}

function about() {
  uni.showModal({
    title: "关于我们",
    content: "鼎极多端（自研重写版）\n版本 1.0.0\n基于 uni-app + ThinkPHP 8",
    showCancel: false,
    confirmText: "知道了",
  });
}

function logout() {
  uni.showModal({
    title: "提示",
    content: "确定退出登录吗？",
    success: (res) => {
      if (res.confirm) {
        session.clearSession();
        uni.showToast({ title: "已退出登录", icon: "none" });
        setTimeout(() => {
          uni.reLaunch({ url: "/pages/my/my" });
        }, 800);
      }
    },
  });
}
</script>

<template>
  <view class="page">
    <view class="group">
      <view class="row" @click="clearCache">
        <text class="row-label">清除缓存</text>
        <view class="row-value">
          <text class="row-text">{{ cacheSize }} KB</text>
          <text class="arrow">></text>
        </view>
      </view>
      <view class="row" @click="about">
        <text class="row-label">关于我们</text>
        <view class="row-value">
          <text class="row-text">v1.0.0</text>
          <text class="arrow">></text>
        </view>
      </view>
    </view>

    <view v-if="session.isLoggedIn" class="logout-btn" @click="logout">
      <text>退出登录</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx;
  box-sizing: border-box;
}
.group {
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
}
.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28rpx 32rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.row:last-child {
  border-bottom: none;
}
.row-label {
  font-size: 28rpx;
  color: #333333;
}
.row-value {
  display: flex;
  align-items: center;
}
.row-text {
  font-size: 26rpx;
  color: #999999;
}
.arrow {
  margin-left: 12rpx;
  color: #cccccc;
}
.logout-btn {
  margin-top: 48rpx;
  padding: 24rpx 0;
  background: #ffffff;
  border-radius: 16rpx;
  text-align: center;
  color: #e64340;
  font-size: 30rpx;
}
</style>
