<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad, onShow } from "@dcloudio/uni-app";
import { fetchKanjiaDetail } from "../api/kanjia";
import type { KanjiaProductDetail } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import Countdown from "../components/Countdown.vue";

const proid = ref(0);
const detail = ref<KanjiaProductDetail | null>(null);
const loading = ref(false);

/** 砍价进度：(原价-现价)/(原价-底价) */
const cutPercent = computed(() => {
  const j = detail.value && detail.value.myjoin;
  if (!j || !detail.value) return 0;
  const sell = Number(detail.value.sell_price) || 0;
  const min = Number(detail.value.min_price) || 0;
  const now = Number(j.now_price) || 0;
  if (sell <= min) return now <= min ? 100 : 0;
  const p = Math.floor(((sell - now) / (sell - min)) * 100);
  return Math.max(0, Math.min(100, p));
});

/** 目标帮砍人数（商品配置 helpnum） */
const targetNum = computed(() => (detail.value ? detail.value.helpnum : 0));

async function load() {
  if (proid.value <= 0) return;
  loading.value = true;
  try {
    detail.value = await fetchKanjiaDetail(proid.value);
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

onLoad((options) => {
  proid.value = Number(options && options.proid) || 0;
});

onShow(() => {
  load();
});

function goStart() {
  goPage("/pagesH2/kanjia/join?proid=" + proid.value);
}

function goHelpList() {
  const j = detail.value && detail.value.myjoin;
  if (j) goPage("/pagesH2/kanjia/helplist?joinid=" + j.id);
  else goPage("/pagesH2/kanjia/helplist");
}

function goBuy() {
  const j = detail.value && detail.value.myjoin;
  if (j) goPage("/pagesH2/kanjia/buy?joinid=" + j.id);
}
</script>

<template>
  <view class="page">
    <view v-if="loading && !detail" class="empty"><text>加载中...</text></view>

    <template v-else-if="detail">
      <!-- 商品主图 -->
      <image v-if="detail.pic" class="banner" :src="imgUrl(detail.pic)" mode="aspectFill" />
      <view v-else class="banner banner-ph"><text>图</text></view>

      <!-- 价格区 -->
      <view class="price-card">
        <view class="price-line">
          <text class="label">底价</text>
          <text class="min-price">￥{{ price2(detail.min_price) }}</text>
          <text class="sell-price">原价 ￥{{ price2(detail.sell_price) }}</text>
        </view>
        <text class="pro-name">{{ detail.name }}</text>
        <view class="meta-line">
          <text class="meta">{{ detail.helpnum }}人帮砍可到底价</text>
          <text class="meta">时限 {{ detail.limitHour }} 小时</text>
          <text class="meta">{{ detail.stock > 0 ? "库存 " + detail.stock : "已抢光" }}</text>
        </view>
        <view class="meta-line">
          <text class="tag">{{ detail.helpgive_text }}</text>
          <text class="tag">{{ detail.directbuy_text }}</text>
        </view>
      </view>

      <!-- 砍价进度（本人有进行中/已完成发起记录） -->
      <view v-if="detail.myjoin" class="progress-card">
        <view class="progress-head">
          <text class="progress-title">{{ detail.myjoin.status === 1 ? "已砍到底价" : "砍价进行中" }}</text>
          <text v-if="detail.myjoin.status === 0 && detail.myjoin.deadtime" class="countdown-wrap">
            剩余 <Countdown :deadline="detail.myjoin.deadtime" />
          </text>
        </view>
        <view class="progress-bar">
          <view class="progress-inner" :style="{ width: cutPercent + '%' }"></view>
        </view>
        <view class="progress-info">
          <text class="now-price">当前 ￥{{ price2(detail.myjoin.now_price) }}</text>
          <text class="help-count">已砍 {{ detail.myjoin.helpnum }}/{{ targetNum }} 刀（{{ cutPercent }}%）</text>
        </view>

        <view class="progress-btns">
          <view v-if="detail.myjoin.status === 0" class="p-btn" @click="goHelpList">
            <text>邀请好友帮砍</text>
          </view>
          <view
            v-if="detail.myjoin.status === 1 && detail.myjoin.isbuy === 0"
            class="p-btn buy"
            @click="goBuy"
          >
            <text>砍到底价，去下单</text>
          </view>
          <view
            v-if="detail.directbuy === 1 && detail.myjoin.status === 0"
            class="p-btn plain"
            @click="goBuy"
          >
            <text>不砍了，直接购买</text>
          </view>
        </view>
      </view>

      <!-- 商品详情 -->
      <view v-if="detail.detail" class="detail-card">
        <view class="detail-title"><text>商品详情</text></view>
        <rich-text :nodes="detail.detail"></rich-text>
      </view>

      <!-- 底部操作栏 -->
      <view class="bottom-bar">
        <view class="bar-btn plain" @click="goHelpList"><text>我的砍价</text></view>
        <view v-if="!detail.myjoin" class="bar-btn" @click="goStart"><text>发起砍价</text></view>
        <view
          v-else-if="detail.myjoin.status === 1 && detail.myjoin.isbuy === 0"
          class="bar-btn"
          @click="goBuy"
        >
          <text>去下单</text>
        </view>
        <view v-else class="bar-btn" @click="goHelpList"><text>查看砍价进度</text></view>
      </view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 140rpx;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.banner {
  width: 100%;
  height: 560rpx;
  background: #f2f2f5;
}
.banner-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 30rpx;
}
.price-card {
  margin: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
}
.price-line {
  display: flex;
  align-items: baseline;
}
.label {
  font-size: 24rpx;
  color: #ff4d4f;
  margin-right: 8rpx;
}
.min-price {
  font-size: 44rpx;
  font-weight: 700;
  color: #ff4d4f;
}
.sell-price {
  margin-left: 20rpx;
  font-size: 24rpx;
  color: #999999;
  text-decoration: line-through;
}
.pro-name {
  margin-top: 16rpx;
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.meta-line {
  margin-top: 16rpx;
  display: flex;
  flex-wrap: wrap;
}
.meta {
  margin-right: 24rpx;
  font-size: 22rpx;
  color: #999999;
}
.tag {
  margin-right: 16rpx;
  padding: 4rpx 14rpx;
  background: #fff3f3;
  color: #ff4d4f;
  border-radius: 8rpx;
  font-size: 22rpx;
}
.progress-card {
  margin: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
}
.progress-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.progress-title {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.countdown-wrap {
  font-size: 22rpx;
  color: #ff4d4f;
}
.progress-bar {
  margin-top: 22rpx;
  height: 16rpx;
  border-radius: 8rpx;
  background: #f2f2f5;
  overflow: hidden;
}
.progress-inner {
  height: 100%;
  border-radius: 8rpx;
  background: linear-gradient(90deg, #ff9f43, #ff4d4f);
}
.progress-info {
  margin-top: 18rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.now-price {
  font-size: 28rpx;
  font-weight: 700;
  color: #ff4d4f;
}
.help-count {
  font-size: 22rpx;
  color: #999999;
}
.progress-btns {
  margin-top: 24rpx;
  display: flex;
  flex-direction: column;
}
.p-btn {
  margin-top: 16rpx;
  padding: 20rpx 0;
  text-align: center;
  background: #ff4d4f;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 28rpx;
}
.p-btn.buy {
  background: #7b7de5;
}
.p-btn.plain {
  background: #ffffff;
  color: #ff4d4f;
  border: 1rpx solid #ff4d4f;
}
.detail-card {
  margin: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.detail-title {
  padding-bottom: 16rpx;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
  border-bottom: 1rpx solid #f5f5f5;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  background: #ffffff;
  display: flex;
  padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));
  box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.bar-btn {
  flex: 1;
  text-align: center;
  padding: 20rpx 0;
  background: #ff4d4f;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 28rpx;
}
.bar-btn.plain {
  background: #ffffff;
  color: #555555;
  border: 1rpx solid #cccccc;
  margin-right: 20rpx;
  flex: 0 0 220rpx;
}
</style>
