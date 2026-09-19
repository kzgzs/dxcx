<script setup lang="ts">
/**
 * 团购申请退款页
 * - 展示订单摘要（商品/实付金额）
 * - 退款原因输入（必填）→ tuangou/order/refund 提交后返回订单详情
 */
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchTuangouOrderDetail, tuangouOrderRefund } from "../api";
import type { TuangouOrderDetail } from "../api";
import { imgUrl, price2 } from "../../utils/format";
import { navigateBack } from "../../utils/navigate";

const orderId = ref(0);
const detail = ref<TuangouOrderDetail | null>(null);
const reason = ref("");
const submitting = ref(false);

const quickReasons = ["不想要了", "拍错了", "商品缺货", "其他原因"];

onLoad((options) => {
  orderId.value = Number(options?.id || 0);
  if (orderId.value > 0) load();
});

async function load() {
  try {
    detail.value = await fetchTuangouOrderDetail(orderId.value);
  } catch {
    detail.value = null;
  }
}

function pickReason(r: string) {
  reason.value = r;
}

async function submit() {
  if (submitting.value) return;
  if (reason.value.trim() === "") {
    uni.showToast({ title: "请填写退款原因", icon: "none" });
    return;
  }
  submitting.value = true;
  try {
    await tuangouOrderRefund(orderId.value, reason.value.trim());
    uni.showToast({ title: "退款申请已提交", icon: "success" });
    setTimeout(() => {
      navigateBack();
    }, 800);
  } catch {
    // request 层已提示
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <view class="page">
    <!-- 订单摘要 -->
    <view v-if="detail" class="card pro-card">
      <image v-if="detail.propic" class="pro-img" :src="imgUrl(detail.propic)" mode="aspectFill" />
      <view v-else class="pro-img pro-img-ph"><text>图</text></view>
      <view class="pro-meta">
        <text class="pro-name">{{ detail.proname }}</text>
        <text class="pro-sub">x{{ detail.num }}</text>
      </view>
      <view class="pro-price">
        <text class="price-label">实付</text>
        <text class="price">￥{{ price2(detail.totalprice) }}</text>
      </view>
    </view>

    <!-- 退款原因 -->
    <view class="card">
      <view class="card-title"><text>退款原因</text></view>
      <view class="quick-row">
        <view
          v-for="r in quickReasons"
          :key="r"
          class="quick-item"
          :class="{ active: reason === r }"
          @click="pickReason(r)"
        >
          <text>{{ r }}</text>
        </view>
      </view>
      <textarea v-model="reason" class="form-textarea" placeholder="请说明退款原因（必填）" maxlength="200" />
    </view>

    <view class="tip-box">
      <text class="tip">提交后商家将尽快审核，退款金额将按原支付渠道退回。</text>
    </view>

    <view class="footer-holder"></view>
    <view class="footer">
      <view class="footer-btn" :class="{ disabled: submitting }" @click="submit"><text>提交退款申请</text></view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx 24rpx 0;
  box-sizing: border-box;
}
.card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
  margin-bottom: 20rpx;
}
.pro-card {
  display: flex;
  align-items: center;
}
.pro-img {
  width: 120rpx;
  height: 120rpx;
  border-radius: 12rpx;
  background: #f2f2f5;
  flex-shrink: 0;
}
.pro-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.pro-meta {
  flex: 1;
  margin-left: 20rpx;
  min-width: 0;
}
.pro-name {
  display: block;
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.pro-sub {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #999999;
}
.pro-price {
  text-align: right;
}
.price-label {
  display: block;
  font-size: 22rpx;
  color: #999999;
}
.price {
  color: #ff4d4f;
  font-size: 30rpx;
  font-weight: 700;
}
.card-title {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  margin-bottom: 20rpx;
}
.quick-row {
  display: flex;
  flex-wrap: wrap;
}
.quick-item {
  margin: 0 16rpx 16rpx 0;
  padding: 10rpx 28rpx;
  border: 1rpx solid #d9d9e3;
  border-radius: 28rpx;
  font-size: 24rpx;
  color: #666666;
}
.quick-item.active {
  border-color: #7b7de5;
  color: #7b7de5;
  background: #f3f3fd;
}
.form-textarea {
  width: 100%;
  height: 180rpx;
  background: #f7f7fa;
  border-radius: 12rpx;
  padding: 16rpx;
  font-size: 26rpx;
  color: #333333;
  box-sizing: border-box;
}
.tip-box {
  padding: 0 8rpx;
}
.tip {
  font-size: 22rpx;
  color: #b5b5b5;
}
.footer-holder {
  height: 140rpx;
}
.footer {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  background: #ffffff;
  padding: 16rpx 24rpx;
  box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.footer-btn {
  height: 80rpx;
  border-radius: 40rpx;
  background: linear-gradient(90deg, #9b9df0, #7b7de5);
  color: #ffffff;
  font-size: 30rpx;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
}
.footer-btn.disabled {
  opacity: 0.6;
}
</style>
