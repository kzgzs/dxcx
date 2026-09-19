<script setup lang="ts">
import { onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { couponReceive, fetchCouponCenterList } from "../../api/mall";
import type { CouponTemplate } from "../../api/types";
import { fmtTime, price2 } from "../../utils/format";

const list = ref<CouponTemplate[]>([]);
const loading = ref(false);
/** 领取中的券 id 集合（防重复点击） */
const receiving = ref<Set<number>>(new Set());
/** 已领取（本次会话内领取后本地置灰；达限领的券后端直接不下发） */
const received = ref<Set<number>>(new Set());

async function load() {
  loading.value = true;
  try {
    const res = await fetchCouponCenterList();
    list.value = res.list || [];
    received.value = new Set();
  } catch {
    list.value = [];
  } finally {
    loading.value = false;
  }
}

onShow(() => {
  load();
});

async function receive(item: CouponTemplate) {
  if (receiving.value.has(item.id) || received.value.has(item.id)) return;
  receiving.value.add(item.id);
  try {
    await couponReceive(item.id);
    received.value.add(item.id);
    uni.showToast({ title: "领取成功", icon: "success" });
  } catch {
    // request 封装已弹 toast（限领/领完等），这里刷新列表保持口径一致
    load();
  } finally {
    receiving.value.delete(item.id);
  }
}

function goMine() {
  uni.navigateTo({ url: "/pagesM/coupon/mine" });
}

function faceText(item: CouponTemplate) {
  return item.type === 2 ? `${item.discount}折` : `￥${price2(item.money)}`;
}

function condText(item: CouponTemplate) {
  return item.full_money > 0 ? `满￥${price2(item.full_money)}可用` : "无门槛";
}
</script>

<template>
  <view class="page">
    <view class="head">
      <text class="head-title">领券中心</text>
      <text class="head-link" @click="goMine">我的优惠券 ></text>
    </view>

    <view v-if="!loading && list.length === 0" class="empty">
      <text class="empty-text">暂无可领取的优惠券</text>
    </view>

    <view v-for="item in list" :key="item.id" class="coupon">
      <view class="coupon-left">
        <text class="face">{{ faceText(item) }}</text>
        <text class="cond">{{ condText(item) }}</text>
      </view>
      <view class="coupon-right">
        <text class="coupon-name">{{ item.name }}</text>
        <text class="coupon-time">有效期 {{ fmtTime(item.start_time) }} ~ {{ fmtTime(item.end_time) }}</text>
        <text class="coupon-stock">剩余 {{ Math.max(item.total - item.received, 0) }} / {{ item.total }} 张</text>
        <view
          class="receive-btn"
          :class="{ disabled: received.has(item.id) || receiving.has(item.id) }"
          @click="receive(item)"
        >
          <text>{{ received.has(item.id) ? "已领取" : receiving.has(item.id) ? "领取中..." : "立即领取" }}</text>
        </view>
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
.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8rpx 8rpx 24rpx;
}
.head-title {
  font-size: 34rpx;
  font-weight: 600;
  color: #333333;
}
.head-link {
  font-size: 26rpx;
  color: #7b7de5;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
}
.empty-text {
  font-size: 28rpx;
  color: #999999;
}
.coupon {
  display: flex;
  align-items: stretch;
  background: #ffffff;
  border-radius: 16rpx;
  margin-bottom: 24rpx;
  overflow: hidden;
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
.coupon-right {
  flex: 1;
  padding: 24rpx 28rpx;
  display: flex;
  flex-direction: column;
}
.coupon-name {
  font-size: 28rpx;
  color: #333333;
  font-weight: 500;
}
.coupon-time {
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #999999;
}
.coupon-stock {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #b9b9c9;
}
.receive-btn {
  align-self: flex-end;
  margin-top: 8rpx;
  padding: 10rpx 32rpx;
  border-radius: 28rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 24rpx;
}
.receive-btn.disabled {
  background: #d9d9e6;
  color: #ffffff;
}
</style>
