<script setup lang="ts">
/**
 * 团购列表页
 * - 顶部轮播（tuangou/set.pics 逗号分隔）
 * - 分类 tab + 关键词搜索
 * - 商品卡片：阶梯价现价/划线原价/已购进度/倒计时
 */
import { computed, ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import {
  fetchTuangouCategories,
  fetchTuangouProducts,
  fetchTuangouSet,
} from "../api";
import type { TuangouCategory, TuangouProduct, TuangouSet } from "../api";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import H3Empty from "../components/h3-empty.vue";

const banners = ref<string[]>([]);
const categories = ref<TuangouCategory[]>([]);
const cid = ref(0);
const keywordInput = ref("");
const keyword = ref("");
const list = ref<TuangouProduct[]>([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const loaded = ref(false);
const now = ref(Math.floor(Date.now() / 1000));

/** 每秒刷新一次倒计时显示 */
setInterval(() => {
  now.value = Math.floor(Date.now() / 1000);
}, 1000);

onLoad(() => {
  loadSet();
  loadCategories();
  load(true);
});

onReachBottom(() => {
  if (list.value.length < total.value) {
    page.value += 1;
    load(false);
  }
});

async function loadSet() {
  try {
    const data: TuangouSet = await fetchTuangouSet();
    banners.value = (data.pics || "")
      .split(",")
      .map((s) => s.trim())
      .filter((s) => s !== "");
  } catch {
    banners.value = [];
  }
}

async function loadCategories() {
  try {
    const data = await fetchTuangouCategories();
    categories.value = data || [];
  } catch {
    categories.value = [];
  }
}

async function load(reset = true) {
  if (loading.value) return;
  loading.value = true;
  if (reset) {
    page.value = 1;
    list.value = [];
  }
  try {
    const data = await fetchTuangouProducts({
      page: page.value,
      pagesize: 10,
      cid: cid.value,
      keyword: keyword.value,
    });
    list.value = reset ? data.list || [] : list.value.concat(data.list || []);
    total.value = data.total || 0;
    loaded.value = true;
  } catch {
    loaded.value = true;
  } finally {
    loading.value = false;
  }
}

function pickCategory(id: number) {
  if (cid.value === id) return;
  cid.value = id;
  load(true);
}

function doSearch() {
  keyword.value = keywordInput.value.trim();
  load(true);
}

function goDetail(id: number) {
  goPage("/pagesH3/tuangou/detail?id=" + id);
}

function goOrderList() {
  goPage("/pagesH3/tuangou/orderlist");
}

/** 活动剩余文案 */
function leftText(p: TuangouProduct): string {
  if (p.endtime <= 0) return "长期有效";
  const left = p.endtime - now.value;
  if (left <= 0) return "已结束";
  const d = Math.floor(left / 86400);
  const h = Math.floor((left % 86400) / 3600);
  const m = Math.floor((left % 3600) / 60);
  if (d > 0) return "剩 " + d + " 天 " + h + " 小时";
  return "剩 " + h + " 小时 " + m + " 分";
}

/** 阶梯价下一档目标人数（无下一档返回 0） */
function nextTier(p: TuangouProduct): number {
  for (const t of p.pricedata) {
    if (t.num > p.buynum) return t.num;
  }
  return 0;
}

function progressPercent(p: TuangouProduct): number {
  const next = nextTier(p);
  if (next <= 0) return 100;
  return Math.min(100, Math.floor((p.buynum / next) * 100));
}

const showProgressTip = (p: TuangouProduct): string => {
  const next = nextTier(p);
  if (next <= 0) return "已达最低团购价";
  const nextPrice = p.pricedata.find((t) => t.num === next);
  return "再增 " + (next - p.buynum) + " 人降至 ￥" + price2(nextPrice ? nextPrice.money : 0);
};

const hasMore = computed(() => list.value.length < total.value);
</script>

<template>
  <view class="page">
    <!-- 轮播 -->
    <swiper
      v-if="banners.length > 0"
      class="banner"
      autoplay
      circular
      :interval="3500"
      indicator-dots
      indicator-active-color="#7b7de5"
    >
      <swiper-item v-for="(b, i) in banners" :key="i">
        <image class="banner-img" :src="imgUrl(b)" mode="aspectFill" />
      </swiper-item>
    </swiper>

    <!-- 搜索 + 我的订单入口 -->
    <view class="search-bar">
      <input
        v-model="keywordInput"
        class="search-input"
        placeholder="搜索团购商品"
        confirm-type="search"
        @confirm="doSearch"
      />
      <view class="search-btn" @click="doSearch"><text>搜索</text></view>
      <view class="my-order" @click="goOrderList"><text>我的订单</text></view>
    </view>

    <!-- 分类 tab -->
    <scroll-view class="cat-bar" scroll-x>
      <view
        class="cat-item"
        :class="{ active: cid === 0 }"
        @click="pickCategory(0)"
      >
        <text>全部</text>
      </view>
      <view
        v-for="c in categories"
        :key="c.id"
        class="cat-item"
        :class="{ active: cid === c.id }"
        @click="pickCategory(c.id)"
      >
        <text>{{ c.name }}</text>
      </view>
    </scroll-view>

    <!-- 商品列表 -->
    <view v-if="list.length > 0" class="pro-list">
      <view v-for="p in list" :key="p.id" class="pro-card" @click="goDetail(p.id)">
        <image v-if="p.pic" class="pro-img" :src="imgUrl(p.pic)" mode="aspectFill" />
        <view v-else class="pro-img pro-img-ph"><text>图</text></view>
        <view class="pro-meta">
          <text class="pro-name">{{ p.name }}</text>
          <text v-if="p.sellpoint" class="pro-point">{{ p.sellpoint }}</text>
          <view class="progress-row">
            <view class="progress-bar">
              <view class="progress-inner" :style="{ width: progressPercent(p) + '%' }"></view>
            </view>
            <text class="progress-tip">{{ showProgressTip(p) }}</text>
          </view>
          <view class="bottom-row">
            <view class="price-wrap">
              <text class="price">￥{{ price2(p.nowprice) }}</text>
              <text v-if="p.market_price > p.nowprice" class="market">￥{{ price2(p.market_price) }}</text>
            </view>
            <text class="left-text">{{ leftText(p) }}</text>
          </view>
          <text class="sales">已抢 {{ p.buynum }} 件</text>
        </view>
      </view>
    </view>
    <h3-empty v-else-if="loaded" text="暂无团购商品" />

    <view v-if="loaded && list.length > 0" class="load-more">
      <text>{{ loading ? "加载中..." : hasMore ? "上拉加载更多" : "没有更多了" }}</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.banner {
  width: 100%;
  height: 300rpx;
}
.banner-img {
  width: 100%;
  height: 300rpx;
}
.search-bar {
  display: flex;
  align-items: center;
  padding: 20rpx 24rpx;
  background: #ffffff;
}
.search-input {
  flex: 1;
  height: 64rpx;
  background: #f5f5f7;
  border-radius: 32rpx;
  padding: 0 28rpx;
  font-size: 26rpx;
  color: #333333;
}
.search-btn {
  margin-left: 16rpx;
  padding: 12rpx 28rpx;
  background: #7b7de5;
  border-radius: 32rpx;
  color: #ffffff;
  font-size: 26rpx;
}
.my-order {
  margin-left: 16rpx;
  padding: 12rpx 0;
  color: #7b7de5;
  font-size: 26rpx;
}
.cat-bar {
  white-space: nowrap;
  background: #ffffff;
  padding: 10rpx 0 20rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.cat-item {
  display: inline-block;
  padding: 10rpx 32rpx;
  font-size: 26rpx;
  color: #666666;
}
.cat-item.active {
  color: #7b7de5;
  font-weight: 600;
}
.pro-list {
  padding: 20rpx 24rpx 0;
}
.pro-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
}
.pro-img {
  width: 200rpx;
  height: 200rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.pro-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.pro-meta {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.pro-name {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 1;
  overflow: hidden;
}
.pro-point {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 1;
  overflow: hidden;
}
.progress-row {
  margin-top: 14rpx;
  display: flex;
  align-items: center;
}
.progress-bar {
  width: 160rpx;
  height: 12rpx;
  border-radius: 6rpx;
  background: #f0f0f4;
  overflow: hidden;
}
.progress-inner {
  height: 100%;
  border-radius: 6rpx;
  background: linear-gradient(90deg, #9b9df0, #7b7de5);
}
.progress-tip {
  margin-left: 12rpx;
  font-size: 20rpx;
  color: #7b7de5;
}
.bottom-row {
  margin-top: 14rpx;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.price-wrap {
  display: flex;
  align-items: baseline;
}
.price {
  color: #ff4d4f;
  font-size: 34rpx;
  font-weight: 700;
}
.market {
  margin-left: 10rpx;
  font-size: 22rpx;
  color: #b5b5b5;
  text-decoration: line-through;
}
.left-text {
  font-size: 22rpx;
  color: #ff8a3d;
}
.sales {
  margin-top: 6rpx;
  font-size: 20rpx;
  color: #b5b5b5;
}
.load-more {
  text-align: center;
  padding: 20rpx 0 40rpx;
  font-size: 22rpx;
  color: #b5b5b5;
}
</style>
