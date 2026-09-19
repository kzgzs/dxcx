<script setup lang="ts">
/**
 * 手机端后台通用骨架页组件
 * 用于本期尚未实装的二级功能（核销记录、菜品管理七页、消息通知等）：
 * 统一骨架占位 + 空态，保证任何情况下不白屏
 */
defineProps<{
  /** 页面标题（功能名） */
  title: string;
  /** 副标题提示 */
  sub?: string;
}>();
</script>

<template>
  <view class="page">
    <!-- 功能头部 -->
    <view class="head">
      <text class="title">{{ title }}</text>
      <text class="sub">{{ sub || "功能建设中，敬请期待" }}</text>
    </view>
    <!-- 骨架占位 -->
    <view class="skeleton">
      <view v-for="i in 3" :key="i" class="sk-row"></view>
    </view>
    <!-- 空态提示 -->
    <view class="empty">
      <text class="empty-icon">🗒</text>
      <text class="empty-text">暂无数据</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.head {
  background: #ffffff;
  padding: 32rpx 30rpx;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}
.title {
  font-size: 34rpx;
  font-weight: 600;
  color: #333333;
}
.sub {
  font-size: 24rpx;
  color: #999999;
}
.skeleton {
  margin-top: 20rpx;
  padding: 0 24rpx;
}
.sk-row {
  height: 120rpx;
  border-radius: 16rpx;
  background: #eeeeee;
  margin-bottom: 20rpx;
}
.empty {
  margin-top: 100rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16rpx;
}
.empty-icon {
  font-size: 72rpx;
}
.empty-text {
  font-size: 28rpx;
  color: #999999;
}
</style>
