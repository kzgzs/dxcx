<script setup lang="ts">
/** 分包通用空状态 */
withDefaults(defineProps<{ text?: string }>(), { text: "暂无数据" });
</script>

<template>
  <view class="c2-empty">
    <view class="c2-empty-icon"><text>∅</text></view>
    <text class="c2-empty-text">{{ text }}</text>
  </view>
</template>

<style scoped>
.c2-empty {
  padding: 120rpx 0;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.c2-empty-icon {
  width: 96rpx;
  height: 96rpx;
  border-radius: 50%;
  background: #f0f0f4;
  color: #9a9bb0;
  font-size: 44rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.c2-empty-text {
  margin-top: 20rpx;
  font-size: 26rpx;
  color: #999999;
}
</style>
