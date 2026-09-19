<script setup lang="ts">
import { onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchCarList } from "../../api/industry";
import type { CarItem } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const list = ref<CarItem[]>([]);
const loading = ref(false);

async function load() {
  loading.value = true;
  try {
    const res = await fetchCarList();
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

function goDetail(id: number) {
  uni.navigateTo({ url: "/carhailing/car/detail?id=" + id });
}

function goOrders() {
  uni.navigateTo({ url: "/carhailing/order/list" });
}
</script>

<template>
  <view class="page">
    <view class="head">
      <text class="head-title">车辆租赁</text>
      <text class="head-link" @click="goOrders">我的用车订单 ></text>
    </view>

    <view v-if="!loading && list.length === 0" class="empty">
      <text>暂无上架车辆</text>
    </view>

    <view v-for="car in list" :key="car.id" class="car-card" @click="goDetail(car.id)">
      <image v-if="car.pic" class="car-img" :src="imgUrl(car.pic)" mode="aspectFill" />
      <view v-else class="car-img car-img-ph"><text>暂无图</text></view>
      <view class="car-info">
        <text class="car-name">{{ car.name }}</text>
        <view class="price-row">
          <text class="price">￥{{ price2(car.price_day) }}<text class="unit">/天</text></text>
          <text class="half-price">半天 ￥{{ price2(car.price_half) }}</text>
        </view>
        <text class="car-meta">{{ car.car_num > 0 ? `车辆 ${car.car_num} 台` : "车辆不限" }} · 最短租 4 小时</text>
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
  color: #999999;
  font-size: 28rpx;
}
.car-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
  margin-bottom: 24rpx;
}
.car-img {
  width: 240rpx;
  height: 200rpx;
  background: #f2f2f5;
  flex-shrink: 0;
}
.car-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.car-info {
  flex: 1;
  padding: 20rpx 24rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.car-name {
  font-size: 30rpx;
  font-weight: 500;
  color: #333333;
}
.price-row {
  display: flex;
  align-items: baseline;
}
.price {
  color: #7b7de5;
  font-size: 34rpx;
  font-weight: 600;
}
.unit {
  font-size: 22rpx;
  font-weight: 400;
}
.half-price {
  margin-left: 16rpx;
  font-size: 22rpx;
  color: #999999;
}
.car-meta {
  font-size: 22rpx;
  color: #b9b9c9;
}
</style>
