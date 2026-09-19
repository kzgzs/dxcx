<script setup lang="ts">
/** 餐饮分包通用状态标签（排队/预定/寄存各页复用） */
defineProps<{
  text: string;
  /** primary 主题紫 / success 绿 / warn 橙 / gray 灰 */
  tone?: "primary" | "success" | "warn" | "gray";
}>();
</script>

<template>
  <text class="status-tag" :class="tone || 'primary'">{{ text }}</text>
</template>

<style scoped>
.status-tag {
  font-size: 22rpx;
  padding: 4rpx 16rpx;
  border-radius: 20rpx;
}
.status-tag.primary {
  color: #7b7de5;
  background: rgba(123, 125, 229, 0.1);
}
.status-tag.success {
  color: #2bb673;
  background: rgba(43, 182, 115, 0.1);
}
.status-tag.warn {
  color: #f0883a;
  background: rgba(240, 136, 58, 0.12);
}
.status-tag.gray {
  color: #999999;
  background: #f2f2f5;
}
</style>
