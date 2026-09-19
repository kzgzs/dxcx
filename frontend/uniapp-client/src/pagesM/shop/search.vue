<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchCategoryList, fetchGoodsList } from "../../api/mall";
import type { CategoryItem, GoodsItem } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { navigateTo, PAGES } from "../../utils/navigate";

const keywordInput = ref("");
const keyword = ref("");
const categoryId = ref<number | "">("");
const categories = ref<CategoryItem[]>([]);
const list = ref<GoodsItem[]>([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const loaded = ref(false);

onLoad((options) => {
  keywordInput.value = decodeURIComponent((options && options.keyword) || "");
  const cid = Number(options && options.category_id) || 0;
  if (cid > 0) categoryId.value = cid;
  keyword.value = keywordInput.value.trim();
  loadCategories();
  load();
});

onReachBottom(() => {
  if (list.value.length < total.value) {
    page.value += 1;
    load(false);
  }
});

async function loadCategories() {
  try {
    const data = await fetchCategoryList();
    categories.value = data.list || [];
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
    const data = await fetchGoodsList({
      page: page.value,
      pagesize: 10,
      keyword: keyword.value,
      category_id: categoryId.value,
    });
    list.value = reset ? data.list || [] : list.value.concat(data.list || []);
    total.value = data.total || 0;
    loaded.value = true;
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

function doSearch() {
  keyword.value = keywordInput.value.trim();
  load();
}

function switchCategory(id: number | "") {
  if (categoryId.value === id) return;
  categoryId.value = id;
  load();
}

function goDetail(id: number) {
  navigateTo(PAGES.productDetail + "?id=" + id);
}
</script>

<template>
  <view class="page">
    <!-- 搜索栏 -->
    <view class="search-bar">
      <view class="search-input-wrap">
        <input
          v-model="keywordInput"
          class="search-input"
          type="text"
          confirm-type="search"
          placeholder="搜索商品名称"
          placeholder-class="ph"
          @confirm="doSearch"
        />
        <text v-if="keywordInput" class="clear" @click="keywordInput = ''">×</text>
      </view>
      <text class="search-btn" @click="doSearch">搜索</text>
    </view>

    <!-- 分类筛选 -->
    <scroll-view scroll-x class="cate-scroll" :show-scrollbar="false">
      <view class="cate-list">
        <view
          class="cate-chip"
          :class="{ active: categoryId === '' }"
          @click="switchCategory('')"
        >
          <text>全部</text>
        </view>
        <view
          v-for="c in categories"
          :key="c.id"
          class="cate-chip"
          :class="{ active: categoryId === c.id }"
          @click="switchCategory(c.id)"
        >
          <text>{{ c.name }}</text>
        </view>
      </view>
    </scroll-view>

    <!-- 结果统计 -->
    <view v-if="loaded" class="result-meta">
      <text>{{ keyword ? `"${keyword}" ` : "" }}共 {{ total }} 件商品</text>
    </view>

    <!-- 空态 -->
    <view v-if="loaded && list.length === 0 && !loading" class="empty">
      <text>未找到相关商品</text>
    </view>

    <!-- 商品列表 -->
    <view class="goods-list">
      <view v-for="g in list" :key="g.id" class="goods-card" @click="goDetail(g.id)">
        <image v-if="g.image" class="goods-img" :src="imgUrl(g.image)" mode="aspectFill" />
        <view v-else class="goods-img goods-img-ph"><text>图</text></view>
        <view class="goods-meta">
          <text class="goods-name">{{ g.name }}</text>
          <text class="goods-sub">已售 {{ g.sales || 0 }} 件</text>
          <view class="row-bottom">
            <view class="price-wrap">
              <text class="price">￥{{ price2(g.price) }}</text>
              <text v-if="g.market_price > g.price" class="market">￥{{ price2(g.market_price) }}</text>
            </view>
            <text v-if="g.stock <= 0" class="sold-out">已售罄</text>
          </view>
        </view>
      </view>
    </view>

    <view v-if="loading" class="empty"><text>加载中...</text></view>
    <view v-else-if="list.length > 0 && list.length >= total" class="empty">
      <text>没有更多了</text>
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
  background: #ffffff;
  padding: 16rpx 24rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.search-input-wrap {
  flex: 1;
  display: flex;
  align-items: center;
  background: #f2f2f5;
  border-radius: 32rpx;
  padding: 0 24rpx;
  height: 64rpx;
}
.search-input {
  flex: 1;
  font-size: 26rpx;
  color: #333333;
}
.ph {
  color: #b5b5b5;
}
.clear {
  color: #999999;
  font-size: 32rpx;
  padding: 0 8rpx;
}
.search-btn {
  margin-left: 20rpx;
  color: #7b7de5;
  font-size: 28rpx;
  font-weight: 600;
}
.cate-scroll {
  background: #ffffff;
  white-space: nowrap;
  border-bottom: 1rpx solid #f2f2f2;
}
.cate-list {
  display: inline-flex;
  padding: 16rpx 24rpx;
}
.cate-chip {
  margin-right: 16rpx;
  padding: 8rpx 28rpx;
  border-radius: 28rpx;
  background: #f2f2f5;
  color: #555555;
  font-size: 24rpx;
}
.cate-chip.active {
  background: #ecebfc;
  color: #7b7de5;
  font-weight: 600;
}
.result-meta {
  padding: 20rpx 24rpx 0;
  font-size: 24rpx;
  color: #999999;
}
.empty {
  padding: 100rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.goods-list {
  padding: 20rpx 20rpx 0;
}
.goods-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 20rpx;
  margin-bottom: 20rpx;
}
.goods-img {
  width: 180rpx;
  height: 180rpx;
  border-radius: 10rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.goods-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.goods-meta {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.goods-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 27rpx;
  color: #333333;
}
.goods-sub {
  font-size: 22rpx;
  color: #999999;
}
.row-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.price-wrap {
  display: flex;
  align-items: baseline;
}
.price {
  color: #e64340;
  font-size: 32rpx;
  font-weight: 700;
}
.market {
  margin-left: 12rpx;
  color: #b5b5b5;
  font-size: 22rpx;
  text-decoration: line-through;
}
.sold-out {
  font-size: 22rpx;
  color: #999999;
  border: 1rpx solid #dddddd;
  border-radius: 20rpx;
  padding: 2rpx 16rpx;
}
</style>
