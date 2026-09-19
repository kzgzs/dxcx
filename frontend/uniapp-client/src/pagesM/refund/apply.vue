<script setup lang="ts">
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { orderRefund } from "../../api/mall";

const orderId = ref(0);
const reason = ref("");
const submitting = ref(false);

onLoad((options) => {
  orderId.value = Number(options && options.order_id) || 0;
});

async function submit() {
  if (submitting.value) return;
  if (orderId.value <= 0) {
    uni.showToast({ title: "缺少订单信息", icon: "none" });
    return;
  }
  if (!reason.value.trim()) {
    uni.showToast({ title: "请填写退款原因", icon: "none" });
    return;
  }
  submitting.value = true;
  try {
    await orderRefund(orderId.value, reason.value.trim());
    uni.showToast({ title: "退款申请已提交", icon: "success" });
    setTimeout(() => {
      uni.navigateBack({ delta: 1 });
    }, 600);
  } catch {
    // request 已弹 toast
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <view class="page">
    <view class="card">
      <text class="label">退款原因</text>
      <textarea
        v-model="reason"
        class="reason-input"
        placeholder="请简要说明退款原因"
        placeholder-class="ph"
        maxlength="200"
      />
      <text class="counter">{{ reason.length }}/200</text>
    </view>
    <view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
      <text>{{ submitting ? "提交中..." : "提交申请" }}</text>
    </view>
    <view class="note"><text>仅待发货/已发货订单可申请退款；已有进行中退款单时不可重复申请</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx;
  box-sizing: border-box;
}
.card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
}
.label {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.reason-input {
  margin-top: 20rpx;
  width: 100%;
  height: 240rpx;
  font-size: 26rpx;
  color: #333333;
  background: #f8f8fa;
  border-radius: 12rpx;
  padding: 20rpx;
  box-sizing: border-box;
}
.ph {
  color: #b5b5b5;
}
.counter {
  display: block;
  margin-top: 12rpx;
  text-align: right;
  font-size: 22rpx;
  color: #b5b5b5;
}
.submit-btn {
  margin-top: 48rpx;
  padding: 24rpx 0;
  background: #7b7de5;
  color: #ffffff;
  font-size: 32rpx;
  text-align: center;
  border-radius: 48rpx;
}
.submit-btn.disabled {
  opacity: 0.6;
}
.note {
  margin-top: 24rpx;
  text-align: center;
  color: #b5b5b5;
  font-size: 22rpx;
}
</style>
