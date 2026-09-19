<script setup lang="ts">
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import CustomTabbar from "../../components/custom-tabbar.vue";
import { fetchCategoryList, fetchGoodsList } from "../../api/mall";
import type { CategoryItem, GoodsItem } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const keyword = ref("");
const categories = ref<CategoryItem[]>([]);
/** 0 = 全部 */
const activeCate = ref(0);
const goods = ref<GoodsItem[]>([]);
const loading = ref(false);
const inited = ref(false);

async function loadCategories() {
  try {
    const data = await fetchCategoryList();
    categories.value = data.list || [];
  } catch {
    // request 已弹 toast
  }
}

async function loadGoods() {
  loading.value = true;
  try {
    const data = await fetchGoodsList({
      page: 1,
      pagesize: 50,
      keyword: keyword.value.trim(),
      category_id: activeCate.value > 0 ? activeCate.value : "",
    });
    goods.value = data.list || [];
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

function selectCate(id: number) {
  if (activeCate.value === id) return;
  activeCate.value = id;
  loadGoods();
}

function onSearch() {
  loadGoods();
}

function goDetail(id: number) {
  uni.navigateTo({ url: "/pagesM/product/detail?id=" + id });
}

function clearSearch() {
  keyword.value = "";
  loadGoods();
}

onShow(() => {
  if (!inited.value) {
    inited.value = true;
    loadCategories();
    loadGoods();
  }
});
</script>

<template>
  <view class="page">
    <!-- 顶部搜索条 -->
    <view class="search-bar">
      <view class="search-box">
        <text class="search-icon">Q</text>
        <input
          v-model="keyword"
          class="search-input"
          placeholder="搜索商品"
          placeholder-class="search-ph"
          confirm-type="search"
          @confirm="onSearch"
        />
        <text v-if="keyword" class="search-clear" @click="clearSearch">x</text>
      </view>
    </view>

    <view class="body">
      <!-- 左侧分类锚点 -->
      <scroll-view class="cate-list" scroll-y>
        <view
          class="cate-item"
          :class="{ active: activeCate === 0 }"
          @click="selectCate(0)"
        >
          <text>全部</text>
        </view>
        <view
          v-for="c in categories"
          :key="c.id"
          class="cate-item"
          :class="{ active: activeCate === c.id }"
          @click="selectCate(c.id)"
        >
          <text>{{ c.name }}</text>
        </view>
      </scroll-view>

      <!-- 右侧商品流 -->
      <scroll-view class="goods-list" scroll-y>
        <view v-if="loading" class="empty"><text>加载中...</text></view>
        <view v-else-if="goods.length === 0" class="empty"><text>暂无商品</text></view>
        <view v-for="g in goods" :key="g.id" class="goods-row" @click="goDetail(g.id)">
          <image v-if="g.image" class="goods-img" :src="imgUrl(g.image)" mode="aspectFill" />
          <view v-else class="goods-img goods-img-ph"><text>暂无图</text></view>
          <view class="goods-meta">
            <text class="goods-name">{{ g.name }}</text>
            <view class="price-row">
              <text class="price">￥{{ price2(g.price) }}</text>
              <text v-if="g.market_price > g.price" class="market-price">￥{{ price2(g.market_price) }}</text>
            </view>
          </view>
        </view>
      </scroll-view>
    </view>

    <custom-tabbar current="/pages/category/category" />
  </view>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: #f8f8f8;
}
.search-bar {
  padding: 16rpx 24rpx;
  background: #ffffff;
}
.search-box {
  display: flex;
  align-items: center;
  height: 68rpx;
  padding: 0 24rpx;
  background: #f2f2f5;
  border-radius: 34rpx;
}
.search-icon {
  color: #999999;
  font-size: 28rpx;
  margin-right: 12rpx;
}
.search-input {
  flex: 1;
  font-size: 26rpx;
  color: #333333;
}
.search-ph {
  color: #b5b5b5;
}
.search-clear {
  color: #999999;
  font-size: 28rpx;
  padding: 0 8rpx;
}
.body {
  flex: 1;
  display: flex;
  overflow: hidden;
  padding-bottom: 100rpx;
}
.cate-list {
  width: 190rpx;
  background: #f2f2f5;
  height: 100%;
}
.cate-item {
  padding: 30rpx 20rpx;
  font-size: 26rpx;
  color: #555555;
  text-align: center;
  background: #f2f2f5;
}
.cate-item.active {
  background: #ffffff;
  color: #7b7de5;
  font-weight: 600;
  border-left: 6rpx solid #7b7de5;
}
.goods-list {
  flex: 1;
  height: 100%;
  padding: 16rpx;
  box-sizing: border-box;
}
.empty {
  padding: 100rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.goods-row {
  display: flex;
  background: #ffffff;
  border-radius: 14rpx;
  overflow: hidden;
  margin-bottom: 16rpx;
}
.goods-img {
  width: 180rpx;
  height: 180rpx;
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
  padding: 16rpx 20rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.goods-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
}
.price-row {
  display: flex;
  align-items: baseline;
}
.price {
  color: #7b7de5;
  font-size: 30rpx;
  font-weight: 600;
}
.market-price {
  margin-left: 12rpx;
  color: #b5b5b5;
  font-size: 22rpx;
  text-decoration: line-through;
}
</style>
