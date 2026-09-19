<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchKanjiaProducts } from "../api/kanjia";
import type { KanjiaProduct } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const keyword = ref("");
const list = ref<KanjiaProduct[]>([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);

async function load(reset = true) {
  if (loading.value) return;
  loading.value = true;
  if (reset) {
    page.value = 1;
    list.value = [];
  }
  try {
    const data = await fetchKanjiaProducts({ page: page.value, pagesize: 10, keyword: keyword.value });
    list.value = reset ? data.list || [] : list.value.concat(data.list || []);
    total.value = data.total || 0;
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

onLoad(() => {
  load();
});

onReachBottom(() => {
  if (list.value.length < total.value) {
    page.value += 1;
    load(false);
  }
});

function doSearch() {
  load();
}

function onKeywordInput(e: unknown) {
  keyword.value = (e as { detail: { value: string } }).detail.value;
}

function goDetail(p: KanjiaProduct) {
  goPage("/pagesH2/kanjia/detail?proid=" + p.id);
}

function goMyJoin() {
  goPage("/pagesH2/kanjia/helplist");
}

function goOrders() {
  goPage("/pagesH2/kanjia/orderlist");
}
</script>

<template>
  <view class="page">
    <!-- 搜索 + 入口 -->
    <view class="topbar">
      <view class="search">
        <input
          class="search-input"
          :value="keyword"
          placeholder="搜索砍价商品"
          placeholder-class="search-ph"
          confirm-type="search"
          @input="onKeywordInput"
          @confirm="doSearch"
        />
        <text class="search-btn" @click="doSearch">搜索</text>
      </view>
      <view class="entries">
        <text class="entry" @click="goMyJoin">我的砍价</text>
        <text class="entry" @click="goOrders">砍价订单</text>
      </view>
    </view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无砍价商品</text></view>

    <view v-for="p in list" :key="p.id" class="pro-card" @click="goDetail(p)">
      <image v-if="p.pic" class="pro-img" :src="imgUrl(p.pic)" mode="aspectFill" />
      <view v-else class="pro-img pro-img-ph"><text>图</text></view>
      <view class="pro-meta">
        <text class="pro-name">{{ p.name }}</text>
        <view class="price-row">
          <text class="min-price">底价 ￥{{ price2(p.min_price) }}</text>
          <text class="sell-price">￥{{ price2(p.sell_price) }}</text>
        </view>
        <view class="info-row">
          <text class="info">{{ p.helpnum }}人可砍 · {{ p.stock > 0 ? "库存" + p.stock : "已抢光" }}</text>
          <text v-if="(p.ongoing_join_num || 0) > 0" class="joining">{{ p.ongoing_join_num }}人砍价中</text>
        </view>
        <view class="btn-row">
          <text class="cut-btn">免费开砍</text>
        </view>
      </view>
    </view>

    <view v-if="loading" class="empty"><text>加载中...</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.topbar {
  background: #ffffff;
  padding: 20rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.search {
  display: flex;
  align-items: center;
  background: #f5f5f7;
  border-radius: 36rpx;
  padding: 10rpx 12rpx 10rpx 28rpx;
}
.search-input {
  flex: 1;
  font-size: 26rpx;
  color: #333333;
}
.search-ph {
  color: #bbbbbb;
}
.search-btn {
  padding: 8rpx 26rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 30rpx;
  font-size: 24rpx;
}
.entries {
  display: flex;
  justify-content: flex-end;
  margin-top: 16rpx;
}
.entry {
  margin-left: 28rpx;
  font-size: 24rpx;
  color: #7b7de5;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.pro-card {
  display: flex;
  margin: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.pro-img {
  width: 190rpx;
  height: 190rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.pro-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 26rpx;
}
.pro-meta {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.pro-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 27rpx;
  color: #333333;
  font-weight: 600;
}
.price-row {
  display: flex;
  align-items: baseline;
}
.min-price {
  color: #ff4d4f;
  font-size: 30rpx;
  font-weight: 700;
}
.sell-price {
  margin-left: 16rpx;
  color: #999999;
  font-size: 22rpx;
  text-decoration: line-through;
}
.info-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.info {
  font-size: 22rpx;
  color: #999999;
}
.joining {
  font-size: 22rpx;
  color: #ff9f43;
}
.btn-row {
  display: flex;
  justify-content: flex-end;
}
.cut-btn {
  padding: 10rpx 34rpx;
  background: #ff4d4f;
  color: #ffffff;
  border-radius: 30rpx;
  font-size: 24rpx;
}
</style>
