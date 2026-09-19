<script setup lang="ts">
import { onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchYuyueProductList } from "../../api/industry";
import type { YuyueProduct } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const list = ref<YuyueProduct[]>([]);
const loading = ref(false);

function load() {
  loading.value = true;
  fetchYuyueProductList()
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

function goDetail(id: number) {
  uni.navigateTo({ url: "/yuyue/item/detail?id=" + id });
}

function goOrders() {
  uni.navigateTo({ url: "/yuyue/order/list" });
}
</script>

<template>
  <view class="page">
    <view class="head">
      <text class="head-title">预约项目</text>
      <view class="orders-link" @click="goOrders"><text>我的预约</text></view>
    </view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无预约项目</text></view>

    <view v-for="p in list" :key="p.id" class="item-card" @click="goDetail(p.id)">
      <image v-if="p.pic" class="item-img" :src="imgUrl(p.pic)" mode="aspectFill" />
      <view v-else class="item-img item-ph"><text>暂无图</text></view>
      <view class="item-info">
        <text class="item-name">{{ p.name }}</text>
        <text v-if="p.duration > 0" class="item-meta">时长约 {{ p.duration }} 分钟</text>
        <view class="price-row">
          <text class="price">￥{{ price2(p.price) }}</text>
          <text class="book-hint">去预约</text>
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
  justify-content: space-between;
  background: #ffffff;
  padding: 24rpx 32rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.head-title {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.orders-link {
  font-size: 26rpx;
  color: #7b7de5;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.item-card {
  display: flex;
  margin: 24rpx;
  padding: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.item-img {
  width: 180rpx;
  height: 180rpx;
  border-radius: 12rpx;
  margin-right: 24rpx;
  flex-shrink: 0;
}
.item-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f2f2f5;
  color: #c5c5cf;
  font-size: 22rpx;
}
.item-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.item-name {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.item-meta {
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #999999;
}
.price-row {
  margin-top: auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.price {
  color: #7b7de5;
  font-size: 34rpx;
  font-weight: 600;
}
.book-hint {
  padding: 8rpx 28rpx;
  border-radius: 28rpx;
  background: rgba(123, 125, 229, 0.1);
  color: #7b7de5;
  font-size: 24rpx;
}
</style>
