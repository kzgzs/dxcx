<script setup lang="ts">
/**
 * pagesH3 通用空态组件
 * text 提示文案；compact 紧凑模式（半高）
 */
defineProps<{
  text?: string;
  compact?: boolean;
}>();
</script>

<template>
  <view class="h3-empty" :class="{ compact: compact }">
    <view class="h3-empty-icon"><text>○</text></view>
    <text class="h3-empty-text">{{ text || "暂无数据" }}</text>
  </view>
</template>

<style scoped>
.h3-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 120rpx 0;
}
.h3-empty.compact {
  padding: 60rpx 0;
}
.h3-empty-icon {
  width: 96rpx;
  height: 96rpx;
  border-radius: 50%;
  background: #f2f2f5;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 44rpx;
}
.h3-empty-text {
  margin-top: 20rpx;
  font-size: 26rpx;
  color: #999999;
}
</style>
