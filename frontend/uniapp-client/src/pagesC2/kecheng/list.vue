<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchKcList } from "../api/kecheng";
import type { KcItem } from "../api/kecheng";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import C2Empty from "../components/c2-empty.vue";

const list = ref<KcItem[]>([]);
const keyword = ref("");
const pagenum = ref(1);
const loading = ref(false);
const hasMore = ref(true);

onLoad(() => {
  void load(true);
});

async function load(reset: boolean) {
  if (loading.value) return;
  loading.value = true;
  try {
    const p = reset ? 1 : pagenum.value + 1;
    const res = await fetchKcList({ keyword: keyword.value.trim() || undefined, pagenum: p });
    const rows = res.list || [];
    list.value = reset ? rows : list.value.concat(rows);
    pagenum.value = p;
    hasMore.value = rows.length >= 10;
  } catch {
    /* request 已弹 toast */
  } finally {
    loading.value = false;
  }
}

function onSearch() {
  void load(true);
}

function goDetail(id: number) {
  goPage("/pagesC2/kecheng/detail?id=" + id);
}

function goOrderList() {
  goPage("/pagesC2/kecheng/orderlist");
}

function goLecturer() {
  goPage("/pagesC2/kecheng/lecturer/list");
}

onReachBottom(() => {
  if (hasMore.value && !loading.value) void load(false);
});
</script>

<template>
  <view class="page">
    <view class="search-bar">
      <input
        v-model="keyword"
        class="search-input"
        placeholder="搜索课程"
        confirm-type="search"
        @confirm="onSearch"
      />
      <view class="search-btn" @click="onSearch"><text>搜索</text></view>
    </view>

    <view class="quick-row">
      <text class="quick" @click="goOrderList">📋 我的课程订单</text>
      <text class="quick" @click="goLecturer">👨‍🏫 讲师列表</text>
    </view>

    <view class="kc-list">
      <view v-for="item in list" :key="item.id" class="kc-card" @click="goDetail(item.id)">
        <image v-if="item.pic" class="kc-pic" :src="imgUrl(item.pic)" mode="aspectFill"></image>
        <view v-else class="kc-pic ph"><text>课</text></view>
        <view class="kc-body">
          <text class="kc-name">{{ item.name }}</text>
          <view class="kc-meta">
            <text class="meta">{{ item.count || 0 }} 章节</text>
            <text class="meta">{{ item.join_num || 0 }} 人在学</text>
          </view>
          <view class="kc-price-row">
            <text class="kc-price">￥{{ price2(item.price) }}</text>
            <text v-if="Number(item.market_price) > 0" class="kc-market">￥{{ price2(item.market_price) }}</text>
            <text v-if="Number(item.price) <= 0" class="kc-free">免费</text>
          </view>
        </view>
      </view>
      <view v-if="list.length" class="list-tip">
        <text>{{ loading ? "加载中..." : hasMore ? "上拉加载更多" : "没有更多了" }}</text>
      </view>
      <C2Empty v-if="!list.length && !loading" text="暂无课程" />
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
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
  background: #f2f2f6;
  border-radius: 32rpx;
  padding: 0 28rpx;
  font-size: 26rpx;
}
.search-btn {
  margin-left: 20rpx;
  padding: 14rpx 32rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 32rpx;
  font-size: 26rpx;
}
.quick-row {
  display: flex;
  background: #ffffff;
  padding: 0 24rpx 20rpx;
}
.quick {
  font-size: 24rpx;
  color: #7b7de5;
  margin-right: 40rpx;
}
.kc-list {
  padding: 24rpx;
}
.kc-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
}
.kc-pic {
  width: 220rpx;
  height: 160rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
}
.kc-pic.ph {
  background: #ecebf7;
  color: #7b7de5;
  font-size: 48rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.kc-body {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.kc-name {
  font-size: 30rpx;
  color: #333333;
  font-weight: 600;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
}
.kc-meta {
  margin-top: 12rpx;
}
.meta {
  font-size: 22rpx;
  color: #b5b5b5;
  margin-right: 24rpx;
}
.kc-price-row {
  margin-top: auto;
  display: flex;
  align-items: baseline;
}
.kc-price {
  font-size: 34rpx;
  color: #e64340;
  font-weight: 700;
}
.kc-market {
  margin-left: 16rpx;
  font-size: 22rpx;
  color: #b5b5b5;
  text-decoration: line-through;
}
.kc-free {
  margin-left: 16rpx;
  font-size: 22rpx;
  color: #2e9e5b;
  background: #e8f7ee;
  padding: 2rpx 14rpx;
  border-radius: 14rpx;
}
.list-tip {
  text-align: center;
  font-size: 24rpx;
  color: #b5b5b5;
  padding: 20rpx 0;
}
</style>
