<script setup lang="ts">
/**
 * 手机端后台-我的（底部 Tab 页，对照参考项目 admin/index/index 的账号区职责）
 * - 账号信息（storage adminidx_admin 读取，字段多候选容错）
 * - 切换账号：清管理端会话回登录页
 * - 修改密码入口 → index/setpwd
 */
import { onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import AdminTabbar from "../components/admin-tabbar.vue";
import { clearAdminSession, getAdminInfo, guardAdmin, type AdminInfo } from "../api";

/** 管理员信息 */
const admin = ref<AdminInfo>({});
/** 展示名（nickname/username 兜底） */
const displayName = ref("管理员");
/** 是否为超级管理员（权限显隐本期简化为按 isadmin 全显，此处仅展示） */
const isAdmin = ref(false);

onShow(() => {
  if (!guardAdmin()) return;
  admin.value = getAdminInfo();
  displayName.value = String(
    admin.value.nickname || admin.value.username || admin.value.name || "管理员"
  );
  isAdmin.value = Number(admin.value.isadmin) > 0;
});

/** 切换账号（对照参考页 data-url="login"：回登录页） */
function switchAccount() {
  uni.showModal({
    title: "提示",
    content: "确定切换账号吗？",
    success: (res) => {
      if (res.confirm) {
        clearAdminSession();
        uni.reLaunch({ url: "/admin/index/login" });
      }
    },
  });
}

/** 修改密码入口 */
function goSetpwd() {
  uni.navigateTo({ url: "/admin/index/setpwd" });
}

/** 回管理首页 */
function goHome() {
  uni.reLaunch({ url: "/admin/index/index" });
}
</script>

<template>
  <view class="page">
    <!-- 账号信息头部 -->
    <view class="header" @click="goHome">
      <view class="avatar"><text>{{ displayName.charAt(0) }}</text></view>
      <view class="user-meta">
        <view class="name-row">
          <text class="nickname">{{ displayName }}</text>
          <text v-if="isAdmin" class="role">管理员</text>
        </view>
        <text class="uid">{{ admin.username ? `账号：${admin.username}` : "手机端后台" }}</text>
      </view>
      <text class="header-arrow">></text>
    </view>

    <!-- 功能菜单 -->
    <view class="card">
      <view class="menu-item" @click="goSetpwd">
        <text class="menu-label">🔑 修改密码</text>
        <text class="arrow">></text>
      </view>
      <view class="menu-item" @click="switchAccount">
        <text class="menu-label">🔁 切换账号</text>
        <text class="arrow">></text>
      </view>
    </view>

    <!-- 底部占位 + 自绘 Tab -->
    <view class="tabbar-holder"></view>
    <admin-tabbar current="my" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.header {
  display: flex;
  align-items: center;
  padding: 56rpx 32rpx;
  background: linear-gradient(135deg, #7b7de5, #a3a5f0);
}
.avatar {
  width: 120rpx;
  height: 120rpx;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  font-size: 48rpx;
  flex-shrink: 0;
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
.role {
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
.card {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
}
.menu-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 30rpx 32rpx;
  font-size: 28rpx;
  color: #333333;
  border-bottom: 1rpx solid #f2f2f2;
}
.menu-item:last-child {
  border-bottom: none;
}
.menu-label {
  font-size: 28rpx;
  color: #333333;
}
.arrow {
  color: #cccccc;
}
.tabbar-holder {
  height: 140rpx;
}
</style>
