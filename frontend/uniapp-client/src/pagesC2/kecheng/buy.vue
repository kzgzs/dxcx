<script setup lang="ts">
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchKcDetail, kcOrderCreate, kcOrderPay } from "../api/kecheng";
import type { KcProduct } from "../api/kecheng";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const kcid = ref(0);
const product = ref<KcProduct | null>(null);
const payType = ref<"wechat" | "alipay">("wechat");
const paying = ref(false);
const paid = ref(false);

onLoad((options) => {
  kcid.value = Number(options && options.id) || 0;
  if (kcid.value > 0) void load();
});

async function load() {
  try {
    const res = await fetchKcDetail(kcid.value);
    product.value = res.product;
  } catch {
    /* request 已弹 toast */
  }
}

async function doPay() {
  if (paying.value || kcid.value <= 0) return;
  paying.value = true;
  try {
    const order = await kcOrderCreate(kcid.value);
    if (order.free) {
      // 免费课自动成单，直接可学
      paid.value = true;
      return;
    }
    const res = await kcOrderPay(order.orderid, payType.value);
    if (res.status === 1 || res.mock) {
      paid.value = true;
    } else {
      uni.showToast({ title: "支付未完成", icon: "none" });
    }
  } catch {
    /* request 已弹 toast */
  } finally {
    paying.value = false;
  }
}

function goStudy() {
  goPage("/pagesC2/kecheng/mldetail?kcid=" + kcid.value);
}

function goOrderList() {
  goPage("/pagesC2/kecheng/orderlist");
}
</script>

<template>
  <view class="page">
    <!-- 支付成功态 -->
    <view v-if="paid" class="result-card">
      <view class="ok-icon"><text>✓</text></view>
      <text class="result-title">报名成功</text>
      <text class="result-sub">课程已解锁，可以开始学习了</text>
      <view class="result-btns">
        <view class="r-btn plain" @click="goOrderList"><text>课程订单</text></view>
        <view class="r-btn" @click="goStudy"><text>开始学习</text></view>
      </view>
    </view>

    <!-- 确认下单 -->
    <template v-else>
      <view v-if="product" class="pro-card">
        <image v-if="product.pic" class="pro-pic" :src="imgUrl(product.pic)" mode="aspectFill"></image>
        <view class="pro-body">
          <text class="pro-name">{{ product.name }}</text>
          <view class="pro-price-row">
            <text class="pro-price">￥{{ price2(product.price) }}</text>
            <text v-if="Number(product.market_price) > 0" class="pro-market">￥{{ price2(product.market_price) }}</text>
          </view>
        </view>
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
        <text>{{ paying ? "支付中..." : "确认支付 ￥" + price2(product ? product.price : 0) }}</text>
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
.pro-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.pro-pic {
  width: 200rpx;
  height: 150rpx;
  border-radius: 12rpx;
}
.pro-body {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
}
.pro-name {
  font-size: 30rpx;
  color: #333333;
  font-weight: 600;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
}
.pro-price-row {
  margin-top: auto;
  display: flex;
  align-items: baseline;
}
.pro-price {
  font-size: 36rpx;
  color: #e64340;
  font-weight: 700;
}
.pro-market {
  margin-left: 16rpx;
  font-size: 22rpx;
  color: #b5b5b5;
  text-decoration: line-through;
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
