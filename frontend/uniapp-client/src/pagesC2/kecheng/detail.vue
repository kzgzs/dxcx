<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchKcDetail } from "../api/kecheng";
import type { KcDetailResult } from "../api/kecheng";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const id = ref(0);
const data = ref<KcDetailResult | null>(null);

const product = computed(() => data.value?.product || null);
const ispay = computed(() => Number(product.value?.ispay ?? 0) === 1);
const isFree = computed(() => Number(product.value?.price ?? 0) <= 0);

onLoad((options) => {
  id.value = Number(options && options.id) || 0;
  if (id.value > 0) void load();
});

async function load() {
  try {
    data.value = await fetchKcDetail(id.value);
  } catch {
    /* request 已弹 toast */
  }
}

function goStudy() {
  goPage("/pagesC2/kecheng/mldetail?kcid=" + id.value);
}

function goBuy() {
  goPage("/pagesC2/kecheng/buy?id=" + id.value);
}

function goRecommend(rid: number) {
  goPage("/pagesC2/kecheng/detail?id=" + rid);
}
</script>

<template>
  <view v-if="product" class="page">
    <swiper v-if="product.pics && product.pics.length" class="banner-swiper" indicator-dots autoplay circular>
      <swiper-item v-for="(p, i) in product.pics" :key="i">
        <image class="banner" :src="imgUrl(p)" mode="aspectFill"></image>
      </swiper-item>
    </swiper>
    <image v-else-if="product.pic" class="banner" :src="imgUrl(product.pic)" mode="aspectFill"></image>

    <view class="info-card">
      <view class="price-row">
        <text class="price">￥{{ price2(product.price) }}</text>
        <text v-if="Number(product.market_price) > 0" class="market">￥{{ price2(product.market_price) }}</text>
        <text v-if="isFree" class="free-tag">免费课</text>
        <text v-if="ispay" class="paid-tag">已购</text>
      </view>
      <text class="kc-name">{{ product.name }}</text>
      <view class="meta-row">
        <text class="meta">{{ product.count || 0 }} 章节</text>
        <text class="meta">{{ product.join_num || 0 }} 人已报名</text>
        <text v-if="Number(product.commission) > 0" class="meta commission">
          推广赚 ￥{{ price2(product.commission) }}
        </text>
      </view>
    </view>

    <view class="section">
      <text class="section-title">课程介绍</text>
      <view class="kc-content">
        <rich-text :nodes="product.content || '暂无介绍'"></rich-text>
      </view>
    </view>

    <view v-if="data && data.tjdatalist && data.tjdatalist.length" class="section">
      <text class="section-title">推荐课程</text>
      <view v-for="t in data.tjdatalist" :key="t.id" class="tj-item" @click="goRecommend(t.id)">
        <image v-if="t.pic" class="tj-pic" :src="imgUrl(t.pic)" mode="aspectFill"></image>
        <view class="tj-body">
          <text class="tj-name">{{ t.name }}</text>
          <text class="tj-price">￥{{ price2(t.price) }}</text>
        </view>
      </view>
    </view>

    <!-- 底部购买栏 -->
    <view class="foot-bar">
      <view v-if="ispay || isFree" class="foot-btn" @click="goStudy"><text>开始学习</text></view>
      <view v-else class="foot-btn" @click="goBuy"><text>立即购买 ￥{{ price2(product.price) }}</text></view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 160rpx;
}
.banner-swiper {
  width: 100%;
  height: 420rpx;
}
.banner {
  width: 100%;
  height: 420rpx;
}
.info-card {
  background: #ffffff;
  padding: 28rpx;
}
.price-row {
  display: flex;
  align-items: baseline;
}
.price {
  font-size: 44rpx;
  color: #e64340;
  font-weight: 700;
}
.market {
  margin-left: 16rpx;
  font-size: 24rpx;
  color: #b5b5b5;
  text-decoration: line-through;
}
.free-tag {
  margin-left: 16rpx;
  font-size: 22rpx;
  color: #2e9e5b;
  background: #e8f7ee;
  padding: 2rpx 14rpx;
  border-radius: 14rpx;
}
.paid-tag {
  margin-left: 16rpx;
  font-size: 22rpx;
  color: #7b7de5;
  background: #ecebf7;
  padding: 2rpx 14rpx;
  border-radius: 14rpx;
}
.kc-name {
  margin-top: 16rpx;
  font-size: 34rpx;
  font-weight: 700;
  color: #222222;
  line-height: 1.4;
}
.meta-row {
  margin-top: 16rpx;
  display: flex;
  flex-wrap: wrap;
}
.meta {
  font-size: 24rpx;
  color: #999999;
  margin-right: 28rpx;
}
.meta.commission {
  color: #e08a3c;
}
.section {
  background: #ffffff;
  margin-top: 20rpx;
  padding: 28rpx;
}
.section-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.kc-content {
  margin-top: 20rpx;
  font-size: 28rpx;
  color: #444444;
  line-height: 1.7;
  word-break: break-all;
}
.tj-item {
  display: flex;
  margin-top: 24rpx;
}
.tj-pic {
  width: 180rpx;
  height: 120rpx;
  border-radius: 12rpx;
}
.tj-body {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
}
.tj-name {
  font-size: 28rpx;
  color: #333333;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
}
.tj-price {
  margin-top: auto;
  font-size: 28rpx;
  color: #e64340;
}
.foot-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  background: #ffffff;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.foot-btn {
  padding: 22rpx 0;
  background: #7b7de5;
  color: #ffffff;
  text-align: center;
  border-radius: 44rpx;
  font-size: 30rpx;
}
</style>
