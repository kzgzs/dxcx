<script setup lang="ts">
/**
 * 团购详情页
 * - 图集轮播（pics 逗号分隔，回退主图）
 * - 价格区（阶梯现价/划线原价/活动倒计时）
 * - 阶梯价表（已购人数命中档位高亮）
 * - 服务承诺 / 活动时间 / 最新评价（前3）/ 图文详情
 * - 底部栏：我的团购 + 立即团购
 */
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchTuangouProductDetail } from "../api";
import type { TuangouProductDetail } from "../api";
import { fmtTime, imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const proId = ref(0);
const detail = ref<TuangouProductDetail | null>(null);
const loaded = ref(false);
const now = ref(Math.floor(Date.now() / 1000));

setInterval(() => {
  now.value = Math.floor(Date.now() / 1000);
}, 1000);

onLoad((options) => {
  proId.value = Number(options?.id || 0);
  if (proId.value > 0) load();
});

async function load() {
  try {
    detail.value = await fetchTuangouProductDetail(proId.value);
  } catch {
    detail.value = null;
  } finally {
    loaded.value = true;
  }
}

const gallery = computed<string[]>(() => {
  if (!detail.value) return [];
  const pics = (detail.value.pics || "")
    .split(",")
    .map((s) => s.trim())
    .filter((s) => s !== "");
  if (pics.length > 0) return pics;
  return detail.value.pic ? [detail.value.pic] : [];
});

const fuwuList = computed<string[]>(() => {
  if (!detail.value) return [];
  return (detail.value.fuwupoint || "")
    .split(",")
    .map((s) => s.trim())
    .filter((s) => s !== "");
});

const leftText = computed(() => {
  if (!detail.value) return "";
  if (detail.value.endtime <= 0) return "长期有效";
  const left = detail.value.endtime - now.value;
  if (left <= 0) return "已结束";
  const d = Math.floor(left / 86400);
  const h = Math.floor((left % 86400) / 3600);
  const m = Math.floor((left % 3600) / 60);
  const s = left % 60;
  if (d > 0) return "距结束 " + d + " 天 " + h + " 小时";
  return "距结束 " + h + ":" + String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
});

function tierHit(num: number): boolean {
  if (!detail.value) return false;
  return detail.value.buynum >= num;
}

function goBuy() {
  if (!detail.value) return;
  goPage("/pagesH3/tuangou/buy?id=" + proId.value);
}

function goOrderList() {
  goPage("/pagesH3/tuangou/orderlist");
}

function scoreText(score: number): string {
  return "★".repeat(Math.max(0, Math.min(5, Math.round(score))));
}
</script>

<template>
  <view class="page">
    <template v-if="detail">
      <!-- 图集 -->
      <swiper
        v-if="gallery.length > 0"
        class="gallery"
        autoplay
        circular
        :interval="3500"
        indicator-dots
        indicator-active-color="#7b7de5"
      >
        <swiper-item v-for="(g, i) in gallery" :key="i">
          <image class="gallery-img" :src="imgUrl(g)" mode="aspectFill" />
        </swiper-item>
      </swiper>

      <!-- 价格区 -->
      <view class="price-card">
        <view class="price-line">
          <text class="now-price">￥{{ price2(detail.nowprice) }}</text>
          <text v-if="detail.market_price > detail.nowprice" class="market-price">
            ￥{{ price2(detail.market_price) }}
          </text>
          <text class="countdown">{{ leftText }}</text>
        </view>
        <text class="pro-name">{{ detail.name }}</text>
        <text v-if="detail.sellpoint" class="sell-point">{{ detail.sellpoint }}</text>
        <view class="stat-line">
          <text>已抢 {{ detail.buynum }} 件</text>
          <text v-if="detail.perlimit > 0">限购 {{ detail.perlimit }} 件/人</text>
          <text v-if="detail.stock > 0">库存 {{ detail.stock }}</text>
        </view>
      </view>

      <!-- 阶梯价 -->
      <view class="card">
        <view class="card-title"><text>阶梯团购价</text></view>
        <view v-for="t in detail.pricedata" :key="t.num" class="tier-row" :class="{ hit: tierHit(t.num) }">
          <text class="tier-num">{{ t.num }} 人起</text>
          <text class="tier-money">￥{{ price2(t.money) }}</text>
          <text v-if="tierHit(t.num)" class="tier-tag">已达</text>
        </view>
        <view v-if="detail.pricedata.length === 0" class="tier-empty"><text>一口价团购</text></view>
      </view>

      <!-- 服务承诺 -->
      <view v-if="fuwuList.length > 0" class="card">
        <view class="card-title"><text>服务承诺</text></view>
        <view v-for="(f, i) in fuwuList" :key="i" class="fuwu-row">
          <text class="fuwu-dot">✓</text>
          <text class="fuwu-text">{{ f }}</text>
        </view>
      </view>

      <!-- 活动时间 -->
      <view class="card">
        <view class="card-title"><text>活动时间</text></view>
        <text class="time-row">
          {{ detail.starttime > 0 ? fmtTime(detail.starttime) : "不限" }} ~
          {{ detail.endtime > 0 ? fmtTime(detail.endtime) : "长期有效" }}
        </text>
      </view>

      <!-- 最新评价 -->
      <view v-if="detail.comments.length > 0" class="card">
        <view class="card-title"><text>最新评价</text></view>
        <view v-for="c in detail.comments" :key="c.id" class="comment-item">
          <image v-if="c.headimg" class="comment-head" :src="imgUrl(c.headimg)" mode="aspectFill" />
          <view v-else class="comment-head comment-head-ph"><text>客</text></view>
          <view class="comment-body">
            <view class="comment-top">
              <text class="comment-name">{{ c.nickname || "匿名用户" }}</text>
              <text class="comment-score">{{ scoreText(c.score) }}</text>
            </view>
            <text class="comment-content">{{ c.content }}</text>
          </view>
        </view>
      </view>

      <!-- 图文详情 -->
      <view v-if="detail.detail" class="card">
        <view class="card-title"><text>图文详情</text></view>
        <rich-text :nodes="detail.detail" />
      </view>

      <!-- 底部占位 -->
      <view class="footer-holder"></view>

      <!-- 底部栏 -->
      <view class="footer">
        <view class="footer-link" @click="goOrderList"><text>我的团购</text></view>
        <view class="footer-btn" @click="goBuy"><text>立即团购</text></view>
      </view>
    </template>
    <view v-else-if="loaded" class="empty-tip"><text>商品不存在或已下架</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.gallery {
  width: 100%;
  height: 600rpx;
}
.gallery-img {
  width: 100%;
  height: 600rpx;
}
.price-card {
  background: #ffffff;
  padding: 28rpx 24rpx;
}
.price-line {
  display: flex;
  align-items: baseline;
}
.now-price {
  color: #ff4d4f;
  font-size: 44rpx;
  font-weight: 700;
}
.market-price {
  margin-left: 14rpx;
  font-size: 24rpx;
  color: #b5b5b5;
  text-decoration: line-through;
}
.countdown {
  margin-left: auto;
  font-size: 22rpx;
  color: #ff8a3d;
}
.pro-name {
  display: block;
  margin-top: 16rpx;
  font-size: 32rpx;
  color: #333333;
  font-weight: 600;
}
.sell-point {
  display: block;
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #999999;
}
.stat-line {
  margin-top: 14rpx;
  display: flex;
  font-size: 22rpx;
  color: #b5b5b5;
}
.stat-line text {
  margin-right: 28rpx;
}
.card {
  margin-top: 20rpx;
  background: #ffffff;
  padding: 28rpx 24rpx;
}
.card-title {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  margin-bottom: 20rpx;
}
.tier-row {
  display: flex;
  align-items: center;
  padding: 14rpx 0;
  border-bottom: 1rpx solid #f5f5f7;
  font-size: 26rpx;
  color: #666666;
}
.tier-row.hit {
  color: #7b7de5;
  font-weight: 600;
}
.tier-num {
  flex: 1;
}
.tier-money {
  margin-right: 20rpx;
}
.tier-tag {
  font-size: 20rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 16rpx;
  padding: 2rpx 14rpx;
}
.tier-empty {
  font-size: 24rpx;
  color: #999999;
}
.fuwu-row {
  display: flex;
  align-items: center;
  padding: 8rpx 0;
}
.fuwu-dot {
  color: #7b7de5;
  margin-right: 12rpx;
  font-size: 24rpx;
}
.fuwu-text {
  font-size: 26rpx;
  color: #666666;
}
.time-row {
  font-size: 26rpx;
  color: #666666;
}
.comment-item {
  display: flex;
  padding: 16rpx 0;
  border-bottom: 1rpx solid #f5f5f7;
}
.comment-head {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  background: #f2f2f5;
  flex-shrink: 0;
}
.comment-head-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.comment-body {
  flex: 1;
  margin-left: 16rpx;
}
.comment-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.comment-name {
  font-size: 24rpx;
  color: #666666;
}
.comment-score {
  color: #ffb400;
  font-size: 22rpx;
}
.comment-content {
  margin-top: 6rpx;
  font-size: 26rpx;
  color: #333333;
}
.footer-holder {
  height: 120rpx;
}
.footer {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  height: 110rpx;
  background: #ffffff;
  display: flex;
  align-items: center;
  padding: 0 24rpx;
  box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.footer-link {
  padding: 0 32rpx;
  font-size: 26rpx;
  color: #666666;
}
.footer-btn {
  flex: 1;
  height: 76rpx;
  border-radius: 38rpx;
  background: linear-gradient(90deg, #9b9df0, #7b7de5);
  color: #ffffff;
  font-size: 30rpx;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
}
.empty-tip {
  padding: 200rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
</style>
