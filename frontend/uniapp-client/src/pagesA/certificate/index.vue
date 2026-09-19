<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";

/**
 * 「证书列表」功能骨架页（任务265按链接目录原路径 /pagesA/certificate/index 补齐）。
 * 后端接口尚未接入：onLoad 解析路径参数并预留请求位，主体为通用列表骨架+空态。
 */
// 路径携带参数（id/bid/type/cid 等按需取用）
const params = ref<Record<string, string>>({});
// 列表数据与加载状态（预留请求位）
const list = ref<unknown[]>([]);
const loading = ref(false);

onLoad((options) => {
  // 解析路径参数，供后续接入接口使用
  params.value = { ...(options || {}) };
  // TODO: 在此按 params 调用对应接口加载数据
  loading.value = false;
});
</script>

<template>
  <view class="page">
    <!-- 功能头部：标题取链接名称 -->
    <view class="head">
      <text class="title">证书列表</text>
      <text class="sub">数据加载中，敬请期待</text>
    </view>
    <!-- 通用列表骨架 -->
    <view v-if="loading" class="skeleton">
      <view v-for="i in 3" :key="i" class="sk-row"></view>
    </view>
    <!-- 空态提示 -->
    <view v-else-if="list.length === 0" class="empty">
      <text class="empty-icon">🗒</text>
      <text class="empty-text">暂无数据</text>
    </view>
    <!-- 数据行预留渲染位 -->
    <view v-else class="list">
      <view v-for="(item, idx) in list" :key="idx" class="row"></view>
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
  background: #fff;
  padding: 32rpx 30rpx;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}
.title {
  font-size: 34rpx;
  font-weight: 600;
  color: #333;
}
.sub {
  font-size: 24rpx;
  color: #999;
}
.skeleton {
  margin-top: 20rpx;
  padding: 0 24rpx;
}
.sk-row {
  height: 120rpx;
  border-radius: 16rpx;
  background: #eee;
  margin-bottom: 20rpx;
}
.empty {
  margin-top: 160rpx;
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
  color: #999;
}
.list {
  margin-top: 20rpx;
  padding: 0 24rpx;
}
.row {
  height: 120rpx;
  border-radius: 16rpx;
  background: #fff;
  margin-bottom: 20rpx;
}
</style>
