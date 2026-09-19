<script setup lang="ts">
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { orderPay, rechargePay } from "../../api/mall";
import { price2 } from "../../utils/format";
import { redirectTo, PAGES } from "../../utils/navigate";

/** 支付场景：order 商城订单（默认） / recharge 余额充值 */
const scene = ref<"order" | "recharge">("order");
const orderId = ref(0);
const amount = ref(0);
const payType = ref<"wechat" | "alipay">("wechat");
const paying = ref(false);
const paid = ref(false);

onLoad((options) => {
  orderId.value = Number(options && options.order_id) || 0;
  amount.value = Number(options && options.amount) || 0;
  if (options && options.scene === "recharge") {
    scene.value = "recharge";
  }
});

async function doPay() {
  if (paying.value || orderId.value <= 0) return;
  paying.value = true;
  try {
    const res =
      scene.value === "recharge"
        ? await rechargePay(orderId.value, payType.value)
        : await orderPay(orderId.value, payType.value);
    if (res.status === 1) {
      paid.value = true;
    } else {
      uni.showToast({ title: "支付未完成", icon: "none" });
    }
  } catch {
    // request 已弹 toast
  } finally {
    paying.value = false;
  }
}

function goDetail() {
  redirectTo(PAGES.orderDetail + "?id=" + orderId.value);
}

function goOrderList() {
  redirectTo(PAGES.orderList);
}

function goRechargeOrders() {
  redirectTo(PAGES.rechargeOrders);
}

function goRechargeIndex() {
  redirectTo(PAGES.recharge);
}
</script>

<template>
  <view class="page">
    <!-- 支付成功态 -->
    <view v-if="paid" class="result-card">
      <view class="ok-icon"><text>✓</text></view>
      <text class="result-title">{{ scene === "recharge" ? "充值成功" : "支付成功" }}</text>
      <text class="result-sub">{{ scene === "recharge" ? "充值" : "实付" }} ￥{{ price2(amount) }}</text>
      <view class="result-btns">
        <template v-if="scene === 'recharge'">
          <view class="r-btn plain" @click="goRechargeIndex"><text>继续充值</text></view>
          <view class="r-btn" @click="goRechargeOrders"><text>充值记录</text></view>
        </template>
        <template v-else>
          <view class="r-btn plain" @click="goOrderList"><text>订单列表</text></view>
          <view class="r-btn" @click="goDetail"><text>查看订单</text></view>
        </template>
      </view>
    </view>

    <!-- 收银台 -->
    <template v-else>
      <view class="amount-card">
        <text class="amount-label">{{ scene === "recharge" ? "充值金额" : "订单金额" }}</text>
        <text class="amount">￥{{ price2(amount) }}</text>
      </view>

      <view class="pay-list">
        <view class="pay-row" @click="payType = 'wechat'">
          <text class="pay-name">微信支付</text>
          <view class="radio" :class="{ checked: payType === 'wechat' }"></view>
        </view>
        <view class="pay-row" @click="payType = 'alipay'">
          <text class="pay-name">支付宝</text>
          <view class="radio" :class="{ checked: payType === 'alipay' }"></view>
        </view>
      </view>

      <view class="pay-btn" :class="{ disabled: paying }" @click="doPay">
        <text>{{ paying ? "支付中..." : "确认支付" }}</text>
      </view>
      <view class="pay-note"><text>测试环境 PAY_MOCK 模式：点击即支付成功入账</text></view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx;
  box-sizing: border-box;
}
.amount-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 60rpx 28rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.amount-label {
  font-size: 26rpx;
  color: #999999;
}
.amount {
  margin-top: 16rpx;
  font-size: 60rpx;
  font-weight: 700;
  color: #333333;
}
.pay-list {
  margin-top: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
}
.pay-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 32rpx 28rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.pay-row:last-child {
  border-bottom: none;
}
.pay-name {
  font-size: 28rpx;
  color: #333333;
}
.radio {
  width: 36rpx;
  height: 36rpx;
  border: 2rpx solid #cccccc;
  border-radius: 50%;
}
.radio.checked {
  border-color: #7b7de5;
  background: #7b7de5;
  box-shadow: inset 0 0 0 6rpx #ffffff;
}
.pay-btn {
  margin-top: 60rpx;
  padding: 24rpx 0;
  background: #7b7de5;
  color: #ffffff;
  font-size: 32rpx;
  text-align: center;
  border-radius: 48rpx;
}
.pay-btn.disabled {
  opacity: 0.6;
}
.pay-note {
  margin-top: 24rpx;
  text-align: center;
  color: #b5b5b5;
  font-size: 22rpx;
}
.result-card {
  margin-top: 120rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 80rpx 28rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.ok-icon {
  width: 100rpx;
  height: 100rpx;
  border-radius: 50%;
  background: #7b7de5;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  font-size: 56rpx;
}
.result-title {
  margin-top: 28rpx;
  font-size: 36rpx;
  font-weight: 600;
  color: #333333;
}
.result-sub {
  margin-top: 14rpx;
  font-size: 26rpx;
  color: #999999;
}
.result-btns {
  margin-top: 60rpx;
  display: flex;
  width: 100%;
}
.r-btn {
  flex: 1;
  text-align: center;
  padding: 20rpx 0;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 28rpx;
}
.r-btn.plain {
  background: #ffffff;
  color: #7b7de5;
  border: 1rpx solid #7b7de5;
  margin-right: 20rpx;
}
</style>
