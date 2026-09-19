<script setup lang="ts">
import { onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchMyCoupons } from "../../api/mall";
import type { CouponRecord } from "../../api/types";
import { fmtTime, price2 } from "../../utils/format";

/** tab 对应后端 status：0未使用 1已使用 2已过期 */
const tabs = [
  { label: "未使用", status: 0 },
  { label: "已使用", status: 1 },
  { label: "已过期", status: 2 },
];

const active = ref(0);
const list = ref<CouponRecord[]>([]);
const loading = ref(false);

async function load() {
  loading.value = true;
  try {
    const res = await fetchMyCoupons(tabs[active.value].status);
    list.value = res.list || [];
  } catch {
    list.value = [];
  } finally {
    loading.value = false;
  }
}

onShow(() => {
  load();
});

function switchTab(index: number) {
  if (active.value === index) return;
  active.value = index;
  load();
}

function goCenter() {
  uni.navigateTo({ url: "/pagesM/coupon/center" });
}

/** 满减券显示面值，折扣券显示折扣 */
function faceText(item: CouponRecord) {
  return item.type === 2 ? `${item.discount}折` : `￥${price2(item.money)}`;
}

function condText(item: CouponRecord) {
  return item.full_money > 0 ? `满￥${price2(item.full_money)}可用` : "无门槛";
}
</script>

<template>
  <view class="page">
    <view class="tabs">
      <view
        v-for="(tab, index) in tabs"
        :key="tab.status"
        class="tab"
        :class="{ active: active === index }"
        @click="switchTab(index)"
      >
        <text>{{ tab.label }}</text>
      </view>
    </view>

    <view v-if="!loading && list.length === 0" class="empty">
      <text class="empty-text">暂无优惠券</text>
      <view class="empty-btn" @click="goCenter"><text>去领券中心看看</text></view>
    </view>

    <view v-for="item in list" :key="item.id" class="coupon" :class="{ disabled: item.status !== 0 }">
      <view class="coupon-left">
        <text class="face">{{ faceText(item) }}</text>
        <text class="cond">{{ condText(item) }}</text>
      </view>
      <view class="coupon-sep"></view>
      <view class="coupon-right">
        <text class="coupon-name">{{ item.name }}</text>
        <text class="coupon-time">有效期 {{ fmtTime(item.start_time) }} ~ {{ fmtTime(item.end_time) }}</text>
        <text v-if="item.status === 1" class="coupon-status">已使用</text>
        <text v-else-if="item.status === 2" class="coupon-status">已过期</text>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx;
  box-sizing: border-box;
}
.tabs {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  margin-bottom: 24rpx;
  overflow: hidden;
}
.tab {
  flex: 1;
  text-align: center;
  padding: 24rpx 0;
  font-size: 28rpx;
  color: #666666;
  position: relative;
}
.tab.active {
  color: #7b7de5;
  font-weight: 600;
}
.tab.active::after {
  content: "";
  position: absolute;
  left: 50%;
  bottom: 0;
  transform: translateX(-50%);
  width: 48rpx;
  height: 6rpx;
  border-radius: 3rpx;
  background: #7b7de5;
}
.empty {
  padding: 120rpx 0;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.empty-text {
  font-size: 28rpx;
  color: #999999;
}
.empty-btn {
  margin-top: 32rpx;
  padding: 16rpx 40rpx;
  border-radius: 32rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 26rpx;
}
.coupon {
  display: flex;
  align-items: stretch;
  background: #ffffff;
  border-radius: 16rpx;
  margin-bottom: 24rpx;
  overflow: hidden;
}
.coupon.disabled {
  opacity: 0.6;
}
.coupon-left {
  width: 220rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 28rpx 0;
  background: linear-gradient(135deg, #7b7de5, #a3a5f0);
}
.coupon.disabled .coupon-left {
  background: #b9b9c9;
}
.face {
  color: #ffffff;
  font-size: 40rpx;
  font-weight: 600;
}
.cond {
  margin-top: 8rpx;
  color: rgba(255, 255, 255, 0.9);
  font-size: 22rpx;
}
.coupon-sep {
  width: 0;
  border-left: 2rpx dashed #e8e8f0;
}
.coupon-right {
  flex: 1;
  padding: 24rpx 28rpx;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.coupon-name {
  font-size: 28rpx;
  color: #333333;
  font-weight: 500;
}
.coupon-time {
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #999999;
}
.coupon-status {
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #b9b9c9;
}
</style>
