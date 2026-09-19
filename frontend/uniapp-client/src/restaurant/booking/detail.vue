<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";
import { bookingCancel, bookingDetail, bookingPay } from "../api/booking";
import type { BookingOrderDetail } from "../api/types";
import { fmtTime, imgUrl, price2 } from "../../utils/format";
import StatusTag from "../components/StatusTag.vue";

const orderId = ref(0);
const order = ref<BookingOrderDetail | null>(null);

function load() {
  if (orderId.value <= 0) return;
  bookingDetail(orderId.value)
    .then((res) => {
      order.value = res;
    })
    .catch(() => {
      order.value = null;
    });
}

onLoad((options) => {
  orderId.value = Number((options && options.id) || 0) || 0;
  load();
});

function callShop() {
  if (!order.value || !order.value.business || !order.value.business.tel) return;
  uni.makePhoneCall({ phoneNumber: order.value.business.tel, fail: () => undefined });
}

function doPay() {
  if (!order.value) return;
  bookingPay(order.value.id)
    .then(() => {
      uni.showToast({ title: "支付成功", icon: "success" });
      load();
    })
    .catch(() => {
      /* 后端 toast 已提示 */
    });
}

function doCancel() {
  if (!order.value) return;
  uni.showModal({
    title: "提示",
    content: order.value.totalprice > 0 ? "取消后定金将原路退回，确定取消吗？" : "确定取消该预定吗？",
    success: (r) => {
      if (!r.confirm) return;
      bookingCancel(orderId.value)
        .then(() => {
          uni.showToast({ title: "已取消", icon: "success" });
          load();
        })
        .catch(() => {
          /* 后端 toast 已提示 */
        });
    },
  });
}
</script>

<template>
  <view class="page">
    <view v-if="!order" class="empty"><text>订单加载中...</text></view>

    <template v-if="order">
      <!-- 商家信息 -->
      <view class="card biz-card" v-if="order.business">
        <image v-if="order.business.logo" class="biz-logo" :src="imgUrl(order.business.logo)" mode="aspectFill" />
        <view class="biz-info">
          <text class="biz-name">{{ order.business.name || "商家" }}</text>
          <text v-if="order.business.address" class="biz-sub">{{ order.business.address }}</text>
        </view>
        <view v-if="order.business.tel" class="biz-call" @click="callShop"><text>致电</text></view>
      </view>

      <!-- 预定信息 -->
      <view class="card">
        <view class="status-row">
          <StatusTag
            :text="order.status === 1 ? order.check_text : order.status_text"
            :tone="order.status === 0 ? 'warn' : order.status === 4 ? 'gray' : order.check_status === 1 ? 'success' : 'primary'"
          />
          <text class="order-sn">{{ order.ordernum }}</text>
        </view>
        <view class="detail-line"><text>桌台</text><text>{{ order.table_name || `#${order.table_id}` }}</text></view>
        <view class="detail-line"><text>用餐时间</text><text>{{ order.booking_time }}</text></view>
        <view class="detail-line"><text>用餐人数</text><text>{{ order.seat }} 人</text></view>
        <view class="detail-line" v-if="order.linkman"><text>联系人</text><text>{{ order.linkman }}</text></view>
        <view class="detail-line"><text>手机号</text><text>{{ order.tel }}</text></view>
        <view class="detail-line" v-if="order.message"><text>备注</text><text>{{ order.message }}</text></view>
        <view class="detail-line">
          <text>定金</text>
          <text>{{ order.totalprice > 0 ? "￥" + price2(order.totalprice) : "免费" }}</text>
        </view>
        <view class="detail-line" v-if="order.pay_time > 0"><text>支付时间</text><text>{{ fmtTime(order.pay_time) }}</text></view>
        <view class="detail-line"><text>下单时间</text><text>{{ fmtTime(order.createtime) }}</text></view>
        <view class="detail-line" v-if="order.refund_status > 0">
          <text>退款</text>
          <text>￥{{ price2(order.refund_money) }}（{{ order.refund_reason || "已退款" }}）</text>
        </view>
      </view>

      <!-- 操作 -->
      <view class="action-bar" v-if="order.status === 0 || order.status !== 4">
        <view v-if="order.status !== 4" class="btn plain" @click="doCancel"><text>取消预定</text></view>
        <view v-if="order.status === 0" class="btn primary" @click="doPay"><text>立即支付</text></view>
      </view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx 0 180rpx;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.card {
  margin: 0 24rpx 24rpx;
  padding: 28rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  flex-direction: column;
}
.biz-card {
  flex-direction: row;
  align-items: center;
}
.biz-logo {
  width: 88rpx;
  height: 88rpx;
  border-radius: 12rpx;
  margin-right: 20rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.biz-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.biz-name {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.biz-sub {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.biz-call {
  padding: 10rpx 28rpx;
  border: 1rpx solid #7b7de5;
  border-radius: 28rpx;
  color: #7b7de5;
  font-size: 24rpx;
  flex-shrink: 0;
}
.status-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16rpx;
}
.order-sn {
  font-size: 22rpx;
  color: #999999;
}
.detail-line {
  display: flex;
  justify-content: space-between;
  font-size: 26rpx;
  color: #333333;
  line-height: 48rpx;
}
.detail-line text:first-child {
  color: #999999;
  flex-shrink: 0;
  margin-right: 24rpx;
}
.detail-line text:last-child {
  text-align: right;
}
.action-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  justify-content: flex-end;
  padding: 20rpx 32rpx calc(20rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  box-shadow: 0 -4rpx 16rpx rgba(0, 0, 0, 0.04);
}
.btn {
  margin-left: 16rpx;
  padding: 16rpx 48rpx;
  border-radius: 36rpx;
  font-size: 28rpx;
}
.btn.plain {
  border: 1rpx solid #dddddd;
  color: #666666;
}
.btn.primary {
  background: #7b7de5;
  color: #ffffff;
}
</style>
