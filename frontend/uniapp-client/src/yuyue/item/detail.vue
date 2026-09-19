<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchYuyueProductDetail } from "../../api/industry";
import type { YuyueProduct } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const product = ref<YuyueProduct | null>(null);
const productId = ref(0);

onLoad((options) => {
  productId.value = Number((options && options.id) || 0);
  if (productId.value > 0) {
    fetchYuyueProductDetail(productId.value)
      .then((res) => {
        product.value = res;
      })
      .catch(() => {
        product.value = null;
      });
  }
});

function goBook() {
  uni.navigateTo({ url: "/yuyue/item/book?id=" + productId.value });
}
</script>

<template>
  <view class="page">
    <template v-if="product">
      <image v-if="product.pic" class="cover" :src="imgUrl(product.pic)" mode="aspectFill" />
      <view v-else class="cover cover-ph"><text>暂无图</text></view>

      <view class="info-card">
        <text class="p-name">{{ product.name }}</text>
        <view class="price-row">
          <text class="price">￥{{ price2(product.price) }}</text>
          <text v-if="product.duration > 0" class="duration">时长约 {{ product.duration }} 分钟</text>
        </view>
      </view>

      <view v-if="product.desc" class="desc-card">
        <text class="card-title">项目介绍</text>
        <text class="desc-text">{{ product.desc }}</text>
      </view>
    </template>
    <view v-else class="empty"><text>加载中或项目已下架</text></view>

    <view class="bottom-bar">
      <view class="book-btn" @click="goBook"><text>选择人员与时段</text></view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 140rpx;
}
.cover {
  width: 100%;
  height: 420rpx;
}
.cover-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f2f2f5;
  color: #c5c5cf;
  font-size: 26rpx;
}
.info-card {
  margin: 24rpx;
  padding: 28rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  flex-direction: column;
}
.p-name {
  font-size: 34rpx;
  font-weight: 600;
  color: #333333;
}
.price-row {
  margin-top: 20rpx;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.price {
  color: #7b7de5;
  font-size: 40rpx;
  font-weight: 600;
}
.duration {
  font-size: 24rpx;
  color: #999999;
}
.desc-card {
  margin: 0 24rpx 24rpx;
  padding: 28rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  flex-direction: column;
}
.card-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  margin-bottom: 16rpx;
}
.desc-text {
  font-size: 26rpx;
  color: #666666;
  line-height: 44rpx;
  white-space: pre-wrap;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 20rpx 32rpx calc(20rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  box-shadow: 0 -4rpx 16rpx rgba(0, 0, 0, 0.04);
}
.book-btn {
  padding: 22rpx 0;
  border-radius: 44rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 30rpx;
  text-align: center;
}
</style>
