<script setup lang="ts">
import { onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchHotelList } from "../../api/industry";
import type { HotelItem } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const stars = [
  { label: "全部", star: 0 },
  { label: "三星", star: 3 },
  { label: "四星", star: 4 },
  { label: "五星", star: 5 },
];
const starIdx = ref(0);
const list = ref<HotelItem[]>([]);
const loading = ref(false);

function load() {
  loading.value = true;
  fetchHotelList(stars[starIdx.value].star)
    .then((res) => {
      list.value = res.list || [];
    })
    .catch(() => {
      list.value = [];
    })
    .finally(() => {
      loading.value = false;
    });
}

onShow(() => {
  load();
});

function switchStar(i: number) {
  if (starIdx.value === i) return;
  starIdx.value = i;
  load();
}

function goDetail(id: number) {
  uni.navigateTo({ url: "/hotel/hotel/detail?id=" + id });
}

function goOrders() {
  uni.navigateTo({ url: "/hotel/order/list" });
}
</script>

<template>
  <view class="page">
    <view class="head">
      <view class="stars">
        <view
          v-for="(s, i) in stars"
          :key="s.star"
          class="star-item"
          :class="{ active: i === starIdx }"
          @click="switchStar(i)"
        >
          <text>{{ s.label }}</text>
        </view>
      </view>
      <view class="orders-link" @click="goOrders"><text>我的订单</text></view>
    </view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无酒店</text></view>

    <view v-for="h in list" :key="h.id" class="hotel-card" @click="goDetail(h.id)">
      <image v-if="h.pic" class="hotel-img" :src="imgUrl(h.pic)" mode="aspectFill" />
      <view v-else class="hotel-img hotel-ph"><text>暂无图</text></view>
      <view class="hotel-info">
        <view class="name-row">
          <text class="hotel-name">{{ h.name }}</text>
          <text v-if="h.star > 0" class="star-tag">{{ h.star }}星</text>
        </view>
        <text class="hotel-addr">{{ h.address || `${h.province || ""}${h.city || ""}${h.district || ""}` }}</text>
        <view class="price-row">
          <text class="price">￥{{ price2(h.min_price) }}</text>
          <text class="price-label">起/晚</text>
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.head {
  display: flex;
  align-items: center;
  background: #ffffff;
  padding: 0 24rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.stars {
  flex: 1;
  display: flex;
}
.star-item {
  padding: 24rpx 20rpx;
  font-size: 26rpx;
  color: #666666;
}
.star-item.active {
  color: #7b7de5;
  font-weight: 600;
  border-bottom: 4rpx solid #7b7de5;
}
.orders-link {
  font-size: 26rpx;
  color: #7b7de5;
  padding: 24rpx 0;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.hotel-card {
  display: flex;
  margin: 24rpx;
  padding: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.hotel-img {
  width: 180rpx;
  height: 180rpx;
  border-radius: 12rpx;
  margin-right: 24rpx;
  flex-shrink: 0;
}
.hotel-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f2f2f5;
  color: #c5c5cf;
  font-size: 22rpx;
}
.hotel-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.name-row {
  display: flex;
  align-items: center;
}
.hotel-name {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.star-tag {
  margin-left: 12rpx;
  padding: 2rpx 12rpx;
  border-radius: 8rpx;
  background: rgba(123, 125, 229, 0.1);
  color: #7b7de5;
  font-size: 20rpx;
  flex-shrink: 0;
}
.hotel-addr {
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #999999;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.price-row {
  margin-top: auto;
  display: flex;
  align-items: baseline;
}
.price {
  color: #7b7de5;
  font-size: 34rpx;
  font-weight: 600;
}
.price-label {
  margin-left: 8rpx;
  font-size: 22rpx;
  color: #999999;
}
</style>
