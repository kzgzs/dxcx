<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { computed, ref } from "vue";
import { fetchCarDetail } from "../../api/industry";
import type { CarItem } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const car = ref<CarItem | null>(null);
const carId = ref(0);

/** 轮播图：pics 逗号分隔多图，无则用 pic */
const images = computed<string[]>(() => {
  if (!car.value) return [];
  if (car.value.pics) {
    return car.value.pics.split(",").map((s) => s.trim()).filter(Boolean);
  }
  return car.value.pic ? [car.value.pic] : [];
});

onLoad((options) => {
  carId.value = Number((options && options.id) || 0);
  if (carId.value > 0) {
    fetchCarDetail(carId.value)
      .then((res) => {
        car.value = res;
      })
      .catch(() => {
        car.value = null;
      });
  }
});

function goBook() {
  uni.navigateTo({ url: "/carhailing/car/book?id=" + carId.value });
}
</script>

<template>
  <view class="page">
    <template v-if="car">
      <swiper v-if="images.length > 0" class="gallery" indicator-dots circular>
        <swiper-item v-for="(img, i) in images" :key="i">
          <image class="gallery-img" :src="imgUrl(img)" mode="aspectFill" />
        </swiper-item>
      </swiper>
      <view v-else class="gallery gallery-ph"><text>暂无图</text></view>

      <view class="info-card">
        <text class="car-name">{{ car.name }}</text>
        <view class="price-row">
          <view class="price-item">
            <text class="price">￥{{ price2(car.price_day) }}</text>
            <text class="price-label">日租价/天</text>
          </view>
          <view class="price-item">
            <text class="price">￥{{ price2(car.price_half) }}</text>
            <text class="price-label">半天价（4~24h）</text>
          </view>
        </view>
        <text class="meta">{{ car.car_num > 0 ? `可租车辆 ${car.car_num} 台` : "车辆数量不限" }}</text>
      </view>

      <view class="rule-card">
        <text class="card-title">计价规则</text>
        <text class="rule-line">· 租车时长少于 4 小时不可下单</text>
        <text class="rule-line">· 4 小时 ~ 24 小时按半天价计费</text>
        <text class="rule-line">· 24 小时及以上按天计费（不足一天按一天）</text>
      </view>

      <view v-if="car.desc" class="desc-card">
        <text class="card-title">车辆介绍</text>
        <text class="desc-text">{{ car.desc }}</text>
      </view>
    </template>
    <view v-else class="empty"><text>加载中或车辆已下架</text></view>

    <view class="bottom-bar">
      <view class="book-btn" @click="goBook"><text>立即预约用车</text></view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 140rpx;
}
.gallery {
  height: 420rpx;
}
.gallery-img {
  width: 100%;
  height: 100%;
}
.gallery-ph {
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
}
.car-name {
  font-size: 34rpx;
  font-weight: 600;
  color: #333333;
}
.price-row {
  display: flex;
  margin-top: 24rpx;
}
.price-item {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.price {
  color: #7b7de5;
  font-size: 38rpx;
  font-weight: 600;
}
.price-label {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
}
.meta {
  display: block;
  margin-top: 20rpx;
  font-size: 24rpx;
  color: #666666;
}
.rule-card,
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
.rule-line {
  font-size: 26rpx;
  color: #666666;
  line-height: 44rpx;
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
