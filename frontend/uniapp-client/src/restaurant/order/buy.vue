<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { computed, ref } from "vue";
import { restaurantOrderCreate } from "../../api/industry";
import { imgUrl, price2 } from "../../utils/format";

interface DineinCartRow {
  product_id: number;
  name: string;
  pic: string;
  price: number;
  num: number;
}

const cart = ref<DineinCartRow[]>([]);
const tableId = ref(0);
const tableName = ref("");
const submitting = ref(false);

const total = computed(() => cart.value.reduce((s, r) => s + r.num * r.price, 0));
const count = computed(() => cart.value.reduce((s, r) => s + r.num, 0));

onLoad((options) => {
  tableId.value = Number((options && options.table_id) || uni.getStorageSync("dinein_table_id") || 0);
  tableName.value = String(uni.getStorageSync("dinein_table_name") || "");
  try {
    const saved = uni.getStorageSync("dinein_cart") as DineinCartRow[];
    cart.value = Array.isArray(saved) ? saved.filter((r) => r.num > 0) : [];
  } catch {
    cart.value = [];
  }
});

function plus(r: DineinCartRow) {
  r.num += 1;
}
function minus(r: DineinCartRow) {
  r.num -= 1;
  if (r.num <= 0) {
    cart.value = cart.value.filter((x) => x.product_id !== r.product_id);
  }
}

function submit() {
  if (submitting.value) return;
  if (tableId.value <= 0) {
    uni.showToast({ title: "请先选择桌台", icon: "none" });
    return;
  }
  if (cart.value.length === 0) {
    uni.showToast({ title: "请先选择菜品", icon: "none" });
    return;
  }
  submitting.value = true;
  restaurantOrderCreate({
    table_id: tableId.value,
    goods: cart.value.map((r) => ({ product_id: r.product_id, num: r.num })),
  })
    .then((res) => {
      uni.removeStorageSync("dinein_cart");
      uni.showToast({ title: "下单成功", icon: "success" });
      setTimeout(() => {
        uni.redirectTo({ url: "/restaurant/order/list?highlight=" + res.id });
      }, 600);
    })
    .catch((err: Error) => {
      uni.showToast({ title: err.message || "下单失败", icon: "none" });
    })
    .finally(() => {
      submitting.value = false;
    });
}
</script>

<template>
  <view class="page">
    <view class="card">
      <text class="card-title">桌台</text>
      <text class="table-name">{{ tableName || `桌台 #${tableId}` }}</text>
    </view>

    <view class="card">
      <text class="card-title">已点菜品</text>
      <view v-if="cart.length === 0" class="empty"><text>购物车为空，请返回门店页选择菜品</text></view>
      <view v-for="r in cart" :key="r.product_id" class="dish-row">
        <image v-if="r.pic" class="dish-img" :src="imgUrl(r.pic)" mode="aspectFill" />
        <view v-else class="dish-img dish-ph"><text>暂无图</text></view>
        <view class="dish-info">
          <text class="dish-name">{{ r.name }}</text>
          <text class="dish-price">￥{{ price2(r.price) }}</text>
        </view>
        <view class="stepper">
          <view class="step-btn minus" @click="minus(r)"><text>-</text></view>
          <text class="step-num">{{ r.num }}</text>
          <view class="step-btn plus" @click="plus(r)"><text>+</text></view>
        </view>
      </view>
    </view>

    <view class="bottom-bar">
      <view class="preview">
        <text class="total">￥{{ price2(total) }}</text>
        <text class="hint">共 {{ count }} 件</text>
      </view>
      <view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
        <text>{{ submitting ? "提交中..." : "提交订单" }}</text>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 160rpx;
}
.card {
  margin: 24rpx;
  padding: 28rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  flex-direction: column;
}
.card-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  margin-bottom: 16rpx;
}
.table-name {
  font-size: 28rpx;
  color: #7b7de5;
}
.empty {
  padding: 40rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.dish-row {
  display: flex;
  align-items: center;
  padding: 20rpx 0;
  border-bottom: 1rpx solid #f2f2f2;
}
.dish-row:last-child {
  border-bottom: none;
}
.dish-img {
  width: 100rpx;
  height: 100rpx;
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
  font-size: 20rpx;
}
.dish-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.dish-name {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
.dish-price {
  margin-top: 8rpx;
  color: #7b7de5;
  font-size: 26rpx;
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
.bottom-bar {
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
.preview {
  flex: 1;
  display: flex;
  align-items: baseline;
}
.total {
  color: #7b7de5;
  font-size: 40rpx;
  font-weight: 600;
}
.hint {
  margin-left: 12rpx;
  font-size: 22rpx;
  color: #999999;
}
.submit-btn {
  padding: 22rpx 64rpx;
  border-radius: 44rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 30rpx;
}
.submit-btn.disabled {
  opacity: 0.6;
}
</style>
