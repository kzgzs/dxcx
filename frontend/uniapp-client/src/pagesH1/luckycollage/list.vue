<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onPullDownRefresh, onReachBottom } from "@dcloudio/uni-app";
import { fetchLuckyCollageProducts } from "../../api/collage";
import type { LuckyCollageProduct } from "../../api/collage";
import { goPage } from "../../utils/navigate";
import BizProductCard from "../components/BizProductCard.vue";

const keyword = ref("");
const list = ref<LuckyCollageProduct[]>([]);
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
    const data = await fetchLuckyCollageProducts({ page: page.value, pagesize: 10, keyword: keyword.value });
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

onPullDownRefresh(async () => {
  await load();
  uni.stopPullDownRefresh();
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

function goDetail(id: number) {
  goPage("/pagesH1/luckycollage/detail?id=" + id);
}

function goOrders() {
  goPage("/pagesH1/luckycollage/orderlist");
}

function goCollage() {
  goPage("/pagesH1/collage/list");
}
</script>

<template>
  <view class="page">
    <!-- 搜索栏 -->
    <view class="search-bar">
      <input
        v-model="keyword"
        class="search-input"
        placeholder="搜索幸运拼团商品"
        confirm-type="search"
        @confirm="doSearch"
      />
      <text class="search-btn" @click="doSearch">搜索</text>
      <text class="search-orders" @click="goOrders">我的订单</text>
    </view>

    <!-- 入口提示 -->
    <view class="entry-tip" @click="goCollage">
      <text class="entry-tip-main">满员即开奖，幸运儿免费拿</text>
      <text class="entry-tip-link">普通拼团 ></text>
    </view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无幸运拼团活动</text></view>

    <view v-for="p in list" :key="p.id" @click="goDetail(p.id)">
      <BizProductCard
        :pic="p.pic"
        :name="p.name"
        :sellpoint="p.sellpoint"
        :price="p.sell_price"
        :market-price="p.market_price"
        :sales="p.sales"
        :badge="p.teamnum + '人团·' + p.gua_num + '人中奖'"
      />
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
.search-bar {
  display: flex;
  align-items: center;
  background: #ffffff;
  padding: 18rpx 20rpx;
}
.search-input {
  flex: 1;
  height: 64rpx;
  background: #f5f5f7;
  border-radius: 32rpx;
  padding: 0 26rpx;
  font-size: 26rpx;
  color: #333333;
}
.search-btn {
  margin-left: 20rpx;
  font-size: 28rpx;
  color: #7b7de5;
  font-weight: 600;
}
.search-orders {
  margin-left: 24rpx;
  font-size: 26rpx;
  color: #555555;
}
.entry-tip {
  margin: 20rpx 20rpx 0;
  background: linear-gradient(90deg, rgba(255, 138, 61, 0.14), rgba(255, 138, 61, 0.04));
  border-radius: 12rpx;
  padding: 20rpx 24rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.entry-tip-main {
  font-size: 26rpx;
  color: #8a5a35;
}
.entry-tip-link {
  font-size: 24rpx;
  color: #7b7de5;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
</style>
