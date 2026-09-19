<script setup lang="ts">
import { onLoad, onShow } from "@dcloudio/uni-app";
import { computed, ref } from "vue";
import { fetchRestaurantInfo, fetchRestaurantProductList } from "../../api/industry";
import type { RestaurantInfo, RestaurantProduct, RestaurantTable } from "../../api/types";
import { applyLaunchTableId, getStoredTableId } from "../../utils/auth";
import { imgUrl, price2 } from "../../utils/format";

const info = ref<RestaurantInfo | null>(null);
const products = ref<RestaurantProduct[]>([]);
const tableId = ref(0);

/** 购物车行（与下单页共享 storage 口径 dinein_cart） */
interface DineinCartRow {
  product_id: number;
  name: string;
  pic: string;
  price: number;
  num: number;
}
const cart = ref<DineinCartRow[]>([]);

function loadCart() {
  try {
    const saved = uni.getStorageSync("dinein_cart") as DineinCartRow[];
    cart.value = Array.isArray(saved) ? saved : [];
  } catch {
    cart.value = [];
  }
}
function saveCart() {
  uni.setStorageSync("dinein_cart", cart.value);
}

function load() {
  fetchRestaurantInfo()
    .then((res) => {
      info.value = res;
      validateStoredTable();
    })
    .catch(() => {
      info.value = null;
    });
  fetchRestaurantProductList()
    .then((res) => {
      products.value = res.list || [];
    })
    .catch(() => {
      products.value = [];
    });
}

/** 扫码带参自动选桌：校验 storage 中桌台是否存在/空闲 */
function validateStoredTable() {
  if (tableId.value <= 0 || !info.value) return;
  const t = info.value.areas.flatMap((a) => a.tables).find((x) => x.id === tableId.value);
  if (!t) {
    // 桌台不存在（可能是别的门店的参数），清掉避免卡死
    tableId.value = 0;
    uni.removeStorageSync("dinein_table_id");
    uni.removeStorageSync("dinein_table_name");
    return;
  }
  if (t.busy === 1) {
    tableId.value = 0;
    uni.removeStorageSync("dinein_table_id");
    uni.removeStorageSync("dinein_table_name");
    uni.showToast({ title: "扫码桌台使用中，请重新选桌", icon: "none" });
    return;
  }
  uni.setStorageSync("dinein_table_name", t.name);
  uni.showToast({ title: "已自动选桌：" + t.name, icon: "none" });
}

onLoad((options) => {
  // 页面直传 ?tableId=（H5 扫码链接/分享链）
  applyLaunchTableId((options || {}) as Record<string, unknown>);
  tableId.value = getStoredTableId();
});

onShow(() => {
  load();
  loadCart();
  tableId.value = getStoredTableId();
});

const cartCount = computed(() => cart.value.reduce((s, r) => s + r.num, 0));
const cartTotal = computed(() => cart.value.reduce((s, r) => s + r.num * r.price, 0));

function cartNum(productId: number) {
  const row = cart.value.find((r) => r.product_id === productId);
  return row ? row.num : 0;
}

function addProduct(p: RestaurantProduct) {
  const row = cart.value.find((r) => r.product_id === p.id);
  if (row) {
    row.num += 1;
  } else {
    cart.value.push({ product_id: p.id, name: p.name, pic: p.pic, price: p.price, num: 1 });
  }
  saveCart();
}

function minusProduct(p: RestaurantProduct) {
  const row = cart.value.find((r) => r.product_id === p.id);
  if (!row) return;
  row.num -= 1;
  if (row.num <= 0) {
    cart.value = cart.value.filter((r) => r.product_id !== p.id);
  }
  saveCart();
}

function pickTable(t: RestaurantTable) {
  if (t.busy === 1) {
    uni.showToast({ title: "该桌台使用中", icon: "none" });
    return;
  }
  tableId.value = t.id;
  uni.setStorageSync("dinein_table_id", t.id);
  uni.setStorageSync("dinein_table_name", t.name);
}

function goBuy() {
  if (tableId.value <= 0) {
    uni.showToast({ title: "请先选择桌台", icon: "none" });
    return;
  }
  if (cartCount.value <= 0) {
    uni.showToast({ title: "请先选择菜品", icon: "none" });
    return;
  }
  uni.navigateTo({ url: "/restaurant/order/buy?table_id=" + tableId.value });
}

function goOrders() {
  uni.navigateTo({ url: "/restaurant/order/list" });
}
</script>

<template>
  <view class="page">
    <view class="head">
      <text class="shop-name">{{ info ? info.shop.name : "加载中..." }}</text>
      <view class="orders-link" @click="goOrders"><text>我的订单</text></view>
    </view>

    <template v-if="info && info.areas.length > 0">
      <view class="section-title"><text>选择桌台</text></view>
      <view v-for="area in info.areas" :key="area.id" class="area-block">
        <text class="area-name">{{ area.name }}</text>
        <view class="table-grid">
          <view
            v-for="t in area.tables"
            :key="t.id"
            class="table-item"
            :class="{ active: tableId === t.id, busy: t.busy === 1 }"
            @click="pickTable(t)"
          >
            <text class="table-name">{{ t.name }}</text>
            <text class="table-sub">{{ t.busy === 1 ? "使用中" : `${t.persons} 人桌` }}</text>
          </view>
        </view>
      </view>
    </template>

    <view class="section-title"><text>菜品列表</text></view>
    <view v-if="products.length === 0" class="empty"><text>暂无菜品</text></view>
    <view v-for="p in products" :key="p.id" class="dish-card">
      <image v-if="p.pic" class="dish-img" :src="imgUrl(p.pic)" mode="aspectFill" />
      <view v-else class="dish-img dish-ph"><text>暂无图</text></view>
      <view class="dish-info">
        <text class="dish-name">{{ p.name }}</text>
        <text v-if="p.desc" class="dish-desc">{{ p.desc }}</text>
        <text class="dish-price">￥{{ price2(p.price) }}</text>
      </view>
      <view class="stepper">
        <view v-if="cartNum(p.id) > 0" class="step-btn minus" @click="minusProduct(p)"><text>-</text></view>
        <text v-if="cartNum(p.id) > 0" class="step-num">{{ cartNum(p.id) }}</text>
        <view class="step-btn plus" @click="addProduct(p)"><text>+</text></view>
      </view>
    </view>

    <view class="cart-bar" v-if="cartCount > 0 || tableId > 0">
      <view class="cart-info">
        <text class="cart-total">￥{{ price2(cartTotal) }}</text>
        <text class="cart-hint">已选 {{ cartCount }} 件</text>
      </view>
      <view class="buy-btn" @click="goBuy"><text>去下单</text></view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 160rpx;
}
.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  padding: 24rpx 32rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.shop-name {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.orders-link {
  font-size: 26rpx;
  color: #7b7de5;
}
.section-title {
  padding: 28rpx 32rpx 16rpx;
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.area-block {
  margin: 0 24rpx 24rpx;
  padding: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.area-name {
  font-size: 26rpx;
  color: #7b7de5;
  font-weight: 600;
}
.table-grid {
  display: flex;
  flex-wrap: wrap;
  margin-top: 16rpx;
}
.table-item {
  width: 31%;
  margin: 0 3.5% 16rpx 0;
  padding: 16rpx 0;
  border: 1rpx solid #e5e5ea;
  border-radius: 12rpx;
  text-align: center;
  display: flex;
  flex-direction: column;
}
.table-item:nth-child(3n) {
  margin-right: 0;
}
.table-item.active {
  border-color: #7b7de5;
  background: rgba(123, 125, 229, 0.06);
}
.table-item.busy {
  background: #fafafa;
  border-color: #eeeeee;
}
.table-name {
  font-size: 26rpx;
  color: #333333;
}
.table-item.active .table-name {
  color: #7b7de5;
  font-weight: 600;
}
.table-item.busy .table-name {
  color: #bbbbbb;
}
.table-sub {
  margin-top: 4rpx;
  font-size: 20rpx;
  color: #999999;
}
.empty {
  padding: 80rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.dish-card {
  display: flex;
  align-items: center;
  margin: 0 24rpx 24rpx;
  padding: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.dish-img {
  width: 140rpx;
  height: 140rpx;
  border-radius: 12rpx;
  margin-right: 20rpx;
  flex-shrink: 0;
}
.dish-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f2f2f5;
  color: #c5c5cf;
  font-size: 22rpx;
}
.dish-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.dish-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.dish-desc {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.dish-price {
  margin-top: 10rpx;
  color: #7b7de5;
  font-size: 30rpx;
  font-weight: 600;
}
.stepper {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}
.step-btn {
  width: 48rpx;
  height: 48rpx;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32rpx;
}
.step-btn.plus {
  background: #7b7de5;
  color: #ffffff;
}
.step-btn.minus {
  border: 1rpx solid #7b7de5;
  color: #7b7de5;
}
.step-num {
  margin: 0 16rpx;
  font-size: 28rpx;
  color: #333333;
  min-width: 36rpx;
  text-align: center;
}
.cart-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  padding: 20rpx 32rpx calc(20rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  box-shadow: 0 -4rpx 16rpx rgba(0, 0, 0, 0.04);
}
.cart-info {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.cart-total {
  color: #7b7de5;
  font-size: 36rpx;
  font-weight: 600;
}
.cart-hint {
  font-size: 22rpx;
  color: #999999;
}
.buy-btn {
  padding: 20rpx 64rpx;
  border-radius: 44rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 30rpx;
}
</style>
