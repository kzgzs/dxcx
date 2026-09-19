<script setup lang="ts">
import { onLoad, onShow } from "@dcloudio/uni-app";
import { computed, ref } from "vue";
import { carOrderCancel, carOrderPay, carOrderRefund, fetchCarOrderList } from "../../api/industry";
import type { CarOrder, PayResult } from "../../api/types";
import { dpNavigate } from "../../components/dp/navigate";
import { fmtTime, imgUrl, price2 } from "../../utils/format";

const orderId = ref(0);
const order = ref<CarOrder | null>(null);
const notFound = ref(false);
const busy = ref(false);

/** 收银台弹层（逻辑对齐 pagesM/pay/pay.vue：支付方式单选 + mock 入账口径） */
const paySheet = ref(false);
const payType = ref<"wechat" | "alipay">("wechat");
const paying = ref(false);

onLoad((options) => {
  orderId.value = Number(options && options.id) || 0;
});

onShow(() => {
  if (orderId.value > 0) load();
});

/** 后端无独立 order/detail 接口：列表已含全部详情字段，按 id 翻页定位（pagesize 上限 100） */
async function load() {
  try {
    for (let p = 1; p <= 5; p++) {
      const res = await fetchCarOrderList("", p, 100);
      const items = res.list || [];
      const hit = items.find((o) => o.id === orderId.value);
      if (hit) {
        order.value = hit;
        notFound.value = false;
        return;
      }
      if (items.length < 100 || p * 100 >= res.total) break;
    }
    notFound.value = true;
  } catch {
    // request 已弹 toast
  }
}

/** 状态色带：已完成绿 / 已关闭灰 / 其余紫 */
const statusTone = computed(() => {
  const s = order.value ? order.value.status : -1;
  if (s === 3) return "tone-success";
  if (s === 4 || s === 5) return "tone-closed";
  return "tone-active";
});

/** 状态流转步骤：待支付→已支付→使用中→已完成（0~3 正向状态展示，4/5 关闭态不展示） */
const flowSteps = ["待支付", "已支付", "使用中", "已完成"];
const flowIdx = computed(() => {
  const s = order.value ? order.value.status : -1;
  return s >= 0 && s <= 3 ? s : -1;
});

/** 租期展示（0.5=半天） */
const daysText = computed(() => {
  if (!order.value) return "";
  return order.value.days === 0.5 ? "半天" : order.value.days + " 天";
});

function openPay() {
  if (!order.value || order.value.status !== 0) return;
  paySheet.value = true;
}

function closePay() {
  if (paying.value) return;
  paySheet.value = false;
}

async function confirmPay() {
  if (!order.value || paying.value) return;
  paying.value = true;
  try {
    const res: PayResult = await carOrderPay(order.value.id, payType.value);
    if (res.status === 1) {
      paySheet.value = false;
      uni.showToast({ title: "支付成功", icon: "success" });
      load();
    } else {
      uni.showToast({ title: "支付未完成", icon: "none" });
    }
  } catch {
    // request 已弹 toast
  } finally {
    paying.value = false;
  }
}

/** 取消（仅待支付） */
function doCancel() {
  if (!order.value || busy.value) return;
  const id = order.value.id;
  uni.showModal({
    title: "提示",
    content: "确定取消该订单吗？",
    success: async (res) => {
      if (!res.confirm) return;
      busy.value = true;
      try {
        await carOrderCancel(id);
        uni.showToast({ title: "已取消", icon: "success" });
        load();
      } catch {
        // request 已弹 toast
      } finally {
        busy.value = false;
      }
    },
  });
}

/** 退款（仅已支付且用车未开始） */
function doRefund() {
  if (!order.value || busy.value) return;
  const id = order.value.id;
  uni.showModal({
    title: "提示",
    content: "确定申请退款吗？",
    success: async (res) => {
      if (!res.confirm) return;
      busy.value = true;
      try {
        await carOrderRefund(id);
        uni.showToast({ title: "退款成功", icon: "success" });
        load();
      } catch {
        // request 已弹 toast
      } finally {
        busy.value = false;
      }
    },
  });
}

function goCarDetail() {
  if (!order.value) return;
  dpNavigate("/carhailing/car/detail?id=" + order.value.product_id);
}
</script>

<template>
  <view v-if="order" class="page">
    <!-- 状态卡 -->
    <view class="status-card" :class="statusTone">
      <text class="status-text">{{ order.status_text }}</text>
      <text class="status-sub">订单号：{{ order.order_sn }}</text>
    </view>

    <!-- 状态流转 -->
    <view v-if="flowIdx >= 0" class="card">
      <view class="flow">
        <view v-for="(step, i) in flowSteps" :key="step" class="flow-step">
          <view class="flow-dot" :class="{ on: i <= flowIdx }"></view>
          <text class="flow-label" :class="{ on: i <= flowIdx }">{{ step }}</text>
          <view v-if="i < flowSteps.length - 1" class="flow-line" :class="{ on: i < flowIdx }"></view>
        </view>
      </view>
    </view>

    <!-- 行程信息 -->
    <view class="card" @click="goCarDetail">
      <view class="card-title"><text>行程信息</text></view>
      <view class="trip-row">
        <image v-if="order.pic" class="trip-img" :src="imgUrl(order.pic)" mode="aspectFill" />
        <view class="trip-meta">
          <text class="subject-name">{{ order.product_name }}</text>
          <text class="trip-line">{{ fmtTime(order.start_time) }} ~ {{ fmtTime(order.end_time) }}</text>
          <text class="trip-line">租期 {{ daysText }}</text>
        </view>
      </view>
    </view>

    <!-- 金额明细 -->
    <view class="card">
      <view class="card-title"><text>金额明细</text></view>
      <view class="sum-row"><text>订单金额</text><text>￥{{ price2(order.amount) }}</text></view>
      <view v-if="order.coupon_money > 0" class="sum-row"><text>优惠券抵扣</text><text>-￥{{ price2(order.coupon_money) }}</text></view>
      <view v-if="order.score_money > 0" class="sum-row"><text>积分抵扣（{{ order.use_score }}分）</text><text>-￥{{ price2(order.score_money) }}</text></view>
      <view class="sum-row total"><text>实付</text><text>￥{{ price2(order.pay_amount) }}</text></view>
    </view>

    <!-- 时间记录 -->
    <view class="card">
      <view class="card-title"><text>时间记录</text></view>
      <view class="sum-row"><text>下单时间</text><text>{{ fmtTime(order.create_time) }}</text></view>
      <view v-if="order.pay_time > 0" class="sum-row"><text>支付时间</text><text>{{ fmtTime(order.pay_time) }}</text></view>
      <view class="sum-row"><text>用车开始</text><text>{{ fmtTime(order.start_time) }}</text></view>
      <view class="sum-row"><text>用车结束</text><text>{{ fmtTime(order.end_time) }}</text></view>
    </view>

    <view class="bottom-space"></view>

    <!-- 操作区 -->
    <view class="bottom-bar">
      <text v-if="order.status === 0" class="act-btn plain" @click="doCancel">取消订单</text>
      <text v-if="order.status === 1" class="act-btn plain" @click="doRefund">申请退款</text>
      <text v-if="order.status === 0" class="act-btn" @click="openPay">去支付</text>
    </view>

    <!-- 收银台弹层 -->
    <view v-if="paySheet" class="sheet-mask" @click="closePay">
      <view class="sheet" @click.stop>
        <view class="sheet-head">
          <text class="sheet-title">收银台</text>
          <text class="sheet-close" @click="closePay">×</text>
        </view>
        <text class="sheet-amount">￥{{ price2(order.pay_amount) }}</text>
        <view class="pay-row" @click="payType = 'wechat'">
          <text class="pay-name">微信支付</text>
          <view class="radio" :class="{ checked: payType === 'wechat' }"></view>
        </view>
        <view class="pay-row" @click="payType = 'alipay'">
          <text class="pay-name">支付宝</text>
          <view class="radio" :class="{ checked: payType === 'alipay' }"></view>
        </view>
        <view class="pay-btn" :class="{ disabled: paying }" @click="confirmPay">
          <text>{{ paying ? "支付中..." : "确认支付" }}</text>
        </view>
      </view>
    </view>
  </view>
  <view v-else class="empty"><text>{{ notFound ? "订单不存在或已删除" : "加载中..." }}</text></view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx;
  box-sizing: border-box;
}
.status-card {
  border-radius: 16rpx;
  padding: 40rpx 28rpx;
  display: flex;
  flex-direction: column;
}
.tone-active {
  background: linear-gradient(135deg, #7b7de5, #a3a5f0);
}
.tone-success {
  background: linear-gradient(135deg, #4caf7d, #7ed0a6);
}
.tone-closed {
  background: linear-gradient(135deg, #8a8f99, #b3b8c2);
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
.card-title {
  padding-bottom: 16rpx;
  border-bottom: 1rpx solid #f2f2f2;
  margin-bottom: 8rpx;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.flow {
  display: flex;
  align-items: flex-start;
}
.flow-step {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
}
.flow-dot {
  width: 20rpx;
  height: 20rpx;
  border-radius: 50%;
  background: #e2e2ea;
}
.flow-dot.on {
  background: #7b7de5;
}
.flow-label {
  margin-top: 12rpx;
  font-size: 22rpx;
  color: #999999;
  white-space: nowrap;
}
.flow-label.on {
  color: #7b7de5;
  font-weight: 600;
}
.flow-line {
  position: absolute;
  top: 9rpx;
  left: calc(50% + 14rpx);
  width: calc(100% - 28rpx);
  height: 3rpx;
  background: #e2e2ea;
}
.flow-line.on {
  background: #7b7de5;
}
.trip-row {
  display: flex;
  padding: 12rpx 0;
}
.trip-img {
  width: 150rpx;
  height: 110rpx;
  border-radius: 10rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.trip-meta {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
}
.subject-name {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.trip-line {
  margin-top: 8rpx;
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
.sheet-mask {
  position: fixed;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 99;
  display: flex;
  align-items: flex-end;
}
.sheet {
  width: 100%;
  background: #ffffff;
  border-radius: 24rpx 24rpx 0 0;
  padding: 32rpx 28rpx;
  padding-bottom: calc(32rpx + env(safe-area-inset-bottom));
  box-sizing: border-box;
}
.sheet-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.sheet-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.sheet-close {
  font-size: 40rpx;
  color: #999999;
  line-height: 40rpx;
  padding: 0 8rpx;
}
.sheet-amount {
  display: block;
  text-align: center;
  padding: 32rpx 0;
  font-size: 56rpx;
  font-weight: 700;
  color: #333333;
}
.pay-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28rpx 8rpx;
  border-top: 1rpx solid #f2f2f2;
}
.pay-name {
  font-size: 28rpx;
  color: #333333;
}
.radio {
  width: 36rpx;
  height: 36rpx;
  border: 2rpx solid #cccccc;
  border-radius: 50%;
}
.radio.checked {
  border-color: #7b7de5;
  background: #7b7de5;
  box-shadow: inset 0 0 0 6rpx #ffffff;
}
.pay-btn {
  margin-top: 32rpx;
  padding: 24rpx 0;
  background: #7b7de5;
  color: #ffffff;
  font-size: 32rpx;
  text-align: center;
  border-radius: 48rpx;
}
.pay-btn.disabled {
  opacity: 0.6;
}
.empty {
  padding: 200rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
</style>
