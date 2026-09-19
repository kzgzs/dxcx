<script setup lang="ts">
import { price2 } from "../../utils/format";

/**
 * 下单页底部提交栏（金额明细在页面内展示，本组件承载合计+提交）
 */
defineProps<{
  /** 合计金额（元） */
  total: number;
  /** 提交中（按钮禁用态） */
  submitting?: boolean;
  /** 按钮文案 */
  buttonText?: string;
}>();

defineEmits<{ (e: "submit"): void }>();
</script>

<template>
  <view class="buy-bar">
    <view class="buy-bar-total">
      <text class="buy-bar-label">合计：</text>
      <text class="buy-bar-price">￥{{ price2(total) }}</text>
    </view>
    <view class="buy-bar-btn" :class="{ disabled: submitting }" @click="$emit('submit')">
      <text>{{ submitting ? "提交中..." : buttonText || "提交订单" }}</text>
    </view>
  </view>
</template>

<style scoped>
.buy-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  border-top: 1rpx solid #eeeeee;
  z-index: 50;
}
.buy-bar-total {
  flex: 1;
  display: flex;
  align-items: baseline;
}
.buy-bar-label {
  font-size: 26rpx;
  color: #555555;
}
.buy-bar-price {
  color: #7b7de5;
  font-size: 38rpx;
  font-weight: 700;
}
.buy-bar-btn {
  padding: 18rpx 64rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 30rpx;
}
.buy-bar-btn.disabled {
  opacity: 0.6;
}
</style>
