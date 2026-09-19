<script setup lang="ts">
import { imgUrl, price2 } from "../../utils/format";

/**
 * 拼团/幸运拼团商品卡片（列表通用件）
 * - 图 + 名称/卖点 + 拼团价/原价 + 角标（x人团 / x人中奖）
 */
defineProps<{
  pic: string;
  name: string;
  sellpoint?: string;
  price: number;
  marketPrice?: number;
  sales?: number;
  /** 左上角标签文案（如「2人团」「3人团·1人中奖」） */
  badge?: string;
}>();
</script>

<template>
  <view class="biz-card">
    <view class="biz-img-wrap">
      <image v-if="pic" class="biz-img" :src="imgUrl(pic)" mode="aspectFill" />
      <view v-else class="biz-img biz-img-ph"><text>图</text></view>
      <view v-if="badge" class="biz-badge"><text>{{ badge }}</text></view>
    </view>
    <view class="biz-meta">
      <text class="biz-name">{{ name }}</text>
      <text v-if="sellpoint" class="biz-sellpoint">{{ sellpoint }}</text>
      <view class="biz-bottom">
        <view class="biz-price-row">
          <text class="biz-price">￥{{ price2(price) }}</text>
          <text v-if="marketPrice && marketPrice > price" class="biz-market">￥{{ price2(marketPrice) }}</text>
        </view>
        <text v-if="sales !== undefined" class="biz-sales">已拼{{ sales }}件</text>
      </view>
    </view>
  </view>
</template>

<style scoped>
.biz-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin: 20rpx 20rpx 0;
}
.biz-img-wrap {
  position: relative;
  width: 190rpx;
  height: 190rpx;
  flex-shrink: 0;
}
.biz-img {
  width: 190rpx;
  height: 190rpx;
  border-radius: 12rpx;
  background: #f2f2f5;
}
.biz-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 26rpx;
}
.biz-badge {
  position: absolute;
  left: 0;
  top: 0;
  background: rgba(123, 125, 229, 0.92);
  color: #ffffff;
  font-size: 20rpx;
  padding: 4rpx 14rpx;
  border-radius: 12rpx 0 12rpx 0;
}
.biz-meta {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
}
.biz-name {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  line-height: 40rpx;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
}
.biz-sellpoint {
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #999999;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 1;
  overflow: hidden;
}
.biz-bottom {
  margin-top: auto;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
}
.biz-price-row {
  display: flex;
  align-items: baseline;
}
.biz-price {
  color: #7b7de5;
  font-size: 34rpx;
  font-weight: 700;
}
.biz-market {
  margin-left: 12rpx;
  color: #b5b5b5;
  font-size: 22rpx;
  text-decoration: line-through;
}
.biz-sales {
  font-size: 22rpx;
  color: #999999;
}
</style>
