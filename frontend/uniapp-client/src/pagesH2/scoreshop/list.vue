<script setup lang="ts">
import { ref } from "vue";
import { onReachBottom, onShow } from "@dcloudio/uni-app";
import { fetchMyScoreBalance, fetchScoreshopProducts } from "../api/scoreshop";
import type { ScoreshopProduct } from "../api/types";
import { imgUrl } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const list = ref<ScoreshopProduct[]>([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const myScore = ref<number | null>(null);

async function load(reset = true) {
  if (loading.value) return;
  loading.value = true;
  if (reset) {
    page.value = 1;
    list.value = [];
  }
  try {
    const data = await fetchScoreshopProducts({ page: page.value, pagesize: 10 });
    list.value = reset ? data.list || [] : list.value.concat(data.list || []);
    total.value = data.total || 0;
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

onShow(() => {
  load();
});

onReachBottom(() => {
  if (list.value.length < total.value) {
    page.value += 1;
    load(false);
  }
});

function goDetail(id: number) {
  goPage("/pagesH2/scoreshop/detail?proid=" + id);
}

function goOrders() {
  goPage("/pagesH2/scoreshop/orderlist");
}

function loadScore() {
  fetchMyScoreBalance()
    .then((d) => {
      myScore.value = Number(d.score) || 0;
    })
    .catch(() => {
      // 未登录等场景静默
    });
}
loadScore();
</script>

<template>
  <view class="page">
    <!-- 头部：我的积分 + 订单入口 -->
    <view class="head-bar">
      <view class="head-left">
        <text class="head-title">积分商城</text>
        <text v-if="myScore !== null" class="head-score">我的积分：{{ myScore }}</text>
      </view>
      <view class="head-order" @click="goOrders"><text>兑换订单</text></view>
    </view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无可兑换商品</text></view>

    <!-- 两列商品宫格 -->
    <view class="grid">
      <view v-for="p in list" :key="p.id" class="grid-item" @click="goDetail(p.id)">
        <image v-if="p.pic" class="pro-img" :src="imgUrl(p.pic)" mode="aspectFill" />
        <view v-else class="pro-img pro-img-ph"><text>图</text></view>
        <view class="item-body">
          <text class="pro-name">{{ p.name }}</text>
          <view class="price-line">
            <text class="score-price">{{ p.score_price }} 积分</text>
            <text v-if="Number(p.money_price) > 0" class="money-price">+￥{{ p.money_price }}</text>
          </view>
          <view class="item-foot">
            <text class="sales">已兑 {{ p.sales || 0 }}</text>
            <text v-if="Number(p.stock) <= 0" class="soldout">已兑完</text>
          </view>
        </view>
      </view>
    </view>

    <view v-if="loading" class="empty"><text>加载中...</text></view>
    <view v-else-if="list.length > 0 && list.length >= total" class="empty"><text>没有更多了</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.head-bar {
  background: linear-gradient(135deg, #ff9f43, #ff7f50);
  padding: 30rpx 24rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.head-left {
  display: flex;
  flex-direction: column;
}
.head-title {
  font-size: 34rpx;
  font-weight: 700;
  color: #ffffff;
}
.head-score {
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #ffffff;
  opacity: 0.9;
}
.head-order {
  padding: 12rpx 26rpx;
  border: 1rpx solid #ffffff;
  border-radius: 32rpx;
  color: #ffffff;
  font-size: 24rpx;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.grid {
  display: flex;
  flex-wrap: wrap;
  padding: 20rpx 12rpx 0;
}
.grid-item {
  width: calc(50% - 16rpx);
  margin: 8rpx;
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
  box-sizing: border-box;
}
.pro-img {
  width: 100%;
  height: 330rpx;
  background: #f2f2f5;
}
.pro-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 28rpx;
}
.item-body {
  padding: 16rpx 18rpx 20rpx;
}
.pro-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
  min-height: 72rpx;
}
.price-line {
  margin-top: 12rpx;
  display: flex;
  align-items: baseline;
}
.score-price {
  color: #ff9f43;
  font-size: 30rpx;
  font-weight: 700;
}
.money-price {
  margin-left: 10rpx;
  color: #ff4d4f;
  font-size: 24rpx;
}
.item-foot {
  margin-top: 10rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.sales {
  font-size: 22rpx;
  color: #999999;
}
.soldout {
  font-size: 22rpx;
  color: #ff4d4f;
}
</style>
