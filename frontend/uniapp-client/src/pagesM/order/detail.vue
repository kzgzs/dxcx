<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onShow } from "@dcloudio/uni-app";
import { fetchOrderDetail, orderCancel, orderConfirm } from "../../api/mall";
import type { OrderItem } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const orderId = ref(0);
const order = ref<OrderItem | null>(null);

onLoad((options) => {
  orderId.value = Number(options && options.id) || 0;
});

onShow(() => {
  if (orderId.value > 0) load();
});

async function load() {
  try {
    order.value = await fetchOrderDetail(orderId.value);
  } catch {
    // request 已弹 toast
  }
}

function goPay() {
  if (!order.value) return;
  uni.navigateTo({ url: "/pagesM/pay/pay?order_id=" + order.value.id + "&amount=" + order.value.pay_amount });
}

function doCancel() {
  if (!order.value) return;
  const id = order.value.id;
  uni.showModal({
    title: "提示",
    content: "确定取消该订单吗？",
    success: async (res) => {
      if (!res.confirm) return;
      try {
        await orderCancel(id);
        uni.showToast({ title: "已取消", icon: "success" });
        load();
      } catch {
        // request 已弹 toast
      }
    },
  });
}

function doConfirm() {
  if (!order.value) return;
  const id = order.value.id;
  uni.showModal({
    title: "提示",
    content: "确认已收到货吗？",
    success: async (res) => {
      if (!res.confirm) return;
      try {
        await orderConfirm(id);
        uni.showToast({ title: "已确认收货", icon: "success" });
        load();
      } catch {
        // request 已弹 toast
      }
    },
  });
}

function goRefund() {
  uni.navigateTo({ url: "/pagesM/refund/apply?order_id=" + orderId.value });
}
</script>

<template>
  <view v-if="order" class="page">
    <!-- 状态头 -->
    <view class="status-card">
      <text class="status-text">{{ order.status_text }}</text>
      <text class="status-sub">订单号：{{ order.order_sn }}</text>
    </view>

    <!-- 地址快照 -->
    <view class="card">
      <view class="addr-line">
        <text class="addr-name">{{ order.address.name }}</text>
        <text class="addr-phone">{{ order.address.phone }}</text>
      </view>
      <text class="addr-detail">{{ order.address.detail }}</text>
    </view>

    <!-- 商品 -->
    <view class="card">
      <view v-for="g in order.goods" :key="g.goods_id" class="goods-row">
        <image v-if="g.pic" class="goods-img" :src="imgUrl(g.pic)" mode="aspectFill" />
        <view v-else class="goods-img goods-img-ph"><text>图</text></view>
        <view class="goods-meta">
          <text class="goods-name">{{ g.name }}</text>
          <view class="row-bottom">
            <text class="price">￥{{ price2(g.price) }}</text>
            <text class="num">x{{ g.num }}</text>
          </view>
        </view>
      </view>
    </view>

    <!-- 金额明细 -->
    <view class="card">
      <view class="sum-row"><text>商品金额</text><text>￥{{ price2(order.pay_amount + order.coupon_money + order.promotion_money + order.score_money) }}</text></view>
      <view v-if="order.promotion_money > 0" class="sum-row"><text>满减促销</text><text>-￥{{ price2(order.promotion_money) }}</text></view>
      <view v-if="order.coupon_money > 0" class="sum-row"><text>优惠券</text><text>-￥{{ price2(order.coupon_money) }}</text></view>
      <view v-if="order.use_score > 0" class="sum-row"><text>积分抵扣（{{ order.use_score }} 积分）</text><text>-￥{{ price2(order.score_money) }}</text></view>
      <view class="sum-row"><text>运费</text><text>￥{{ price2(order.freight) }}</text></view>
      <view class="sum-row total"><text>实付</text><text>￥{{ price2(order.pay_amount) }}</text></view>
    </view>

    <!-- 时间 -->
    <view class="card">
      <view class="sum-row"><text>下单时间</text><text>{{ order.create_time }}</text></view>
      <view v-if="order.pay_time" class="sum-row"><text>支付时间</text><text>{{ order.pay_time }}</text></view>
      <view v-if="order.ship_time" class="sum-row"><text>发货时间</text><text>{{ order.ship_time }}</text></view>
      <view v-if="order.status === 0 && order.pay_expire_time" class="sum-row"><text>支付截止</text><text>{{ order.pay_expire_time }}</text></view>
    </view>

    <view class="bottom-space"></view>

    <!-- 操作栏 -->
    <view class="bottom-bar">
      <text v-if="order.status === 0" class="act-btn plain" @click="doCancel">取消订单</text>
      <text v-if="order.status === 1 || order.status === 2" class="act-btn plain" @click="goRefund">申请退款</text>
      <text v-if="order.status === 2" class="act-btn" @click="doConfirm">确认收货</text>
      <text v-if="order.status === 0" class="act-btn" @click="goPay">去支付</text>
    </view>
  </view>
  <view v-else class="empty"><text>加载中...</text></view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx;
  box-sizing: border-box;
}
.status-card {
  background: linear-gradient(135deg, #7b7de5, #a3a5f0);
  border-radius: 16rpx;
  padding: 40rpx 28rpx;
  display: flex;
  flex-direction: column;
}
.status-text {
  color: #ffffff;
  font-size: 38rpx;
  font-weight: 700;
}
.status-sub {
  margin-top: 14rpx;
  color: rgba(255, 255, 255, 0.85);
  font-size: 24rpx;
}
.card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
  margin-top: 20rpx;
}
.addr-line {
  display: flex;
  align-items: center;
}
.addr-name {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  margin-right: 20rpx;
}
.addr-phone {
  font-size: 26rpx;
  color: #777777;
}
.addr-detail {
  display: block;
  margin-top: 12rpx;
  font-size: 26rpx;
  color: #777777;
  line-height: 38rpx;
}
.goods-row {
  display: flex;
  padding: 8rpx 0;
}
.goods-img {
  width: 130rpx;
  height: 130rpx;
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
  font-size: 26rpx;
  color: #333333;
}
.row-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.price {
  color: #333333;
  font-size: 26rpx;
}
.num {
  font-size: 24rpx;
  color: #999999;
}
.sum-row {
  display: flex;
  justify-content: space-between;
  font-size: 26rpx;
  color: #555555;
  padding: 10rpx 0;
}
.sum-row.total {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  border-top: 1rpx solid #f2f2f2;
  margin-top: 12rpx;
  padding-top: 22rpx;
}
.bottom-space {
  height: 140rpx;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  border-top: 1rpx solid #eeeeee;
}
.act-btn {
  margin-left: 16rpx;
  padding: 14rpx 40rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 36rpx;
  font-size: 26rpx;
}
.act-btn.plain {
  background: #ffffff;
  color: #555555;
  border: 1rpx solid #cccccc;
}
.empty {
  padding: 200rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
</style>
