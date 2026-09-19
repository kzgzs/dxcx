<script setup lang="ts">
import { computed, ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import CustomTabbar from "../../components/custom-tabbar.vue";
import { cartDelete, cartSelectAll, cartUpdate, fetchCartList } from "../../api/mall";
import type { CartItem } from "../../api/types";
import { useSessionStore } from "../../stores/session";
import { imgUrl, price2 } from "../../utils/format";

const session = useSessionStore();

const list = ref<CartItem[]>([]);
const loaded = ref(false);

const selectedItems = computed(() => list.value.filter((x) => x.selected === 1));
const allSelected = computed(() => list.value.length > 0 && selectedItems.value.length === list.value.length);
const totalAmount = computed(() =>
  selectedItems.value.reduce((sum, x) => sum + x.price * x.num, 0)
);

async function load() {
  if (!session.isLoggedIn) {
    list.value = [];
    loaded.value = true;
    return;
  }
  try {
    const data = await fetchCartList();
    list.value = data.list || [];
  } catch {
    // request 已弹 toast
  } finally {
    loaded.value = true;
  }
}

onShow(() => {
  load();
});

function goLogin() {
  uni.navigateTo({ url: "/pages/login/login?redirect=/pages/cart/cart" });
}

async function toggleSelect(item: CartItem) {
  const next = item.selected === 1 ? 0 : 1;
  item.selected = next;
  try {
    await cartUpdate(item.id, { selected: next });
  } catch {
    item.selected = next === 1 ? 0 : 1;
  }
}

async function toggleSelectAll() {
  const next = allSelected.value ? 0 : 1;
  const backup = list.value.map((x) => x.selected);
  list.value.forEach((x) => (x.selected = next));
  try {
    await cartSelectAll(next);
  } catch {
    list.value.forEach((x, i) => (x.selected = backup[i]));
  }
}

function changeNum(item: CartItem, delta: number) {
  const next = item.num + delta;
  if (next < 1 || next > item.stock) return;
  const backup = item.num;
  item.num = next;
  cartUpdate(item.id, { num: next }).catch(() => {
    item.num = backup;
  });
}

/** 长按删除 */
function removeItem(item: CartItem) {
  uni.showModal({
    title: "提示",
    content: "确定删除该商品吗？",
    success: async (res) => {
      if (!res.confirm) return;
      try {
        await cartDelete([item.id]);
        list.value = list.value.filter((x) => x.id !== item.id);
      } catch {
        // request 已弹 toast
      }
    },
  });
}

/** 去结算：order/create 为单商品口径，需恰好选中 1 个商品 */
function checkout() {
  if (selectedItems.value.length === 0) {
    uni.showToast({ title: "请先选择商品", icon: "none" });
    return;
  }
  if (selectedItems.value.length > 1) {
    uni.showToast({ title: "当前仅支持单商品结算，请只勾选一件", icon: "none", duration: 2500 });
    return;
  }
  const item = selectedItems.value[0];
  uni.navigateTo({ url: "/pagesM/order/buy?goods_id=" + item.product_id + "&num=" + item.num });
}
</script>

<template>
  <view class="page">
    <!-- 未登录引导 -->
    <view v-if="!session.isLoggedIn" class="placeholder">
      <text>购物车需要登录后使用</text>
      <view class="login-btn" @click="goLogin"><text>去登录</text></view>
    </view>

    <template v-else>
      <view v-if="list.length === 0 && loaded" class="placeholder">
        <text>购物车还是空的，去逛逛吧</text>
      </view>

      <view v-else class="cart-list">
        <view
          v-for="item in list"
          :key="item.id"
          class="cart-row"
          @longpress="removeItem(item)"
        >
          <view class="checkbox" :class="{ checked: item.selected === 1 }" @click="toggleSelect(item)">
            <text v-if="item.selected === 1" class="check-mark">✓</text>
          </view>
          <image v-if="item.pic" class="goods-img" :src="imgUrl(item.pic)" mode="aspectFill" />
          <view v-else class="goods-img goods-img-ph"><text>图</text></view>
          <view class="goods-meta">
            <text class="goods-name">{{ item.name }}</text>
            <view class="row-bottom">
              <text class="price">￥{{ price2(item.price) }}</text>
              <view class="stepper">
                <text class="step-btn" @click="changeNum(item, -1)">-</text>
                <text class="step-num">{{ item.num }}</text>
                <text class="step-btn" @click="changeNum(item, 1)">+</text>
              </view>
            </view>
          </view>
        </view>
        <view class="tip"><text>长按商品可删除</text></view>
      </view>

      <view class="bottom-space"></view>

      <!-- 底栏：全选 + 合计 + 去结算 -->
      <view class="bottom-bar">
        <view class="checkbox" :class="{ checked: allSelected }" @click="toggleSelectAll">
          <text v-if="allSelected" class="check-mark">✓</text>
        </view>
        <text class="all-text">全选</text>
        <view class="total-wrap">
          <text class="total-label">合计：</text>
          <text class="total-price">￥{{ price2(totalAmount) }}</text>
        </view>
        <view class="checkout-btn" @click="checkout"><text>去结算</text></view>
      </view>
    </template>

    <custom-tabbar current="/pages/cart/cart" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 240rpx;
}
.placeholder {
  margin: 24rpx;
  padding: 120rpx 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.login-btn {
  margin-top: 32rpx;
  padding: 16rpx 64rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 28rpx;
}
.cart-list {
  padding: 20rpx;
}
.cart-row {
  display: flex;
  align-items: center;
  background: #ffffff;
  border-radius: 14rpx;
  padding: 20rpx;
  margin-bottom: 16rpx;
}
.checkbox {
  width: 40rpx;
  height: 40rpx;
  border: 2rpx solid #cccccc;
  border-radius: 50%;
  margin-right: 20rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.checkbox.checked {
  background: #7b7de5;
  border-color: #7b7de5;
}
.check-mark {
  color: #ffffff;
  font-size: 24rpx;
}
.goods-img {
  width: 150rpx;
  height: 150rpx;
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
  min-height: 150rpx;
}
.goods-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
}
.row-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.price {
  color: #7b7de5;
  font-size: 30rpx;
  font-weight: 600;
}
.stepper {
  display: flex;
  align-items: center;
  border: 1rpx solid #e5e5e5;
  border-radius: 8rpx;
  overflow: hidden;
}
.step-btn {
  width: 52rpx;
  height: 48rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f6f6f8;
  color: #555555;
  font-size: 28rpx;
}
.step-num {
  width: 68rpx;
  text-align: center;
  font-size: 24rpx;
  color: #333333;
}
.tip {
  text-align: center;
  color: #b5b5b5;
  font-size: 22rpx;
  padding: 12rpx 0;
}
.bottom-space {
  height: 120rpx;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 100rpx;
  display: flex;
  align-items: center;
  padding: 16rpx 24rpx;
  background: #ffffff;
  border-top: 1rpx solid #eeeeee;
}
.all-text {
  font-size: 26rpx;
  color: #555555;
}
.total-wrap {
  flex: 1;
  display: flex;
  align-items: baseline;
  justify-content: flex-end;
  margin-right: 20rpx;
}
.total-label {
  font-size: 26rpx;
  color: #555555;
}
.total-price {
  color: #7b7de5;
  font-size: 34rpx;
  font-weight: 700;
}
.checkout-btn {
  padding: 16rpx 48rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 28rpx;
}
</style>
