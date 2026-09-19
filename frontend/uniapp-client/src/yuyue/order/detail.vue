<script setup lang="ts">
import { onLoad, onShow } from "@dcloudio/uni-app";
import { computed, ref } from "vue";
import { fetchYuyueOrderList, yuyueOrderPay, yuyueOrderRefund } from "../../api/industry";
import type { PayResult, YuyueOrder } from "../../api/types";
import { fmtTime, price2 } from "../../utils/format";

const orderId = ref(0);
const order = ref<YuyueOrder | null>(null);
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
      const res = await fetchYuyueOrderList("", p, 100);
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

/** 状态色带：进行中紫 / 已完成绿 / 已关闭灰 */
const statusTone = computed(() => {
  const s = order.value ? order.value.status : -1;
  if (s === 3) return "tone-success";
  if (s === 4 || s === 5) return "tone-closed";
  return "tone-active";
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
    const res: PayResult = await yuyueOrderPay(order.value.id, payType.value);
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

/** 退款（仅已支付未核销且预约日期未到；后端无取消/改期接口，改期需退款后重新预约） */
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
        await yuyueOrderRefund(id);
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
</script>

<template>
  <view v-if="order" class="page">
    <!-- 状态卡 -->
    <view class="status-card" :class="statusTone">
      <text class="status-text">{{ order.status_text }}</text>
      <text class="status-sub">订单号：{{ order.order_sn }}</text>
    </view>

    <!-- 预约信息 -->
    <view class="card">
      <view class="card-title"><text>预约信息</text></view>
      <text class="subject-name">{{ order.product_name }}</text>
      <view class="info-line"><text class="info-label">预约时间</text><text class="info-value">{{ order.book_date }} {{ order.book_time }}</text></view>
      <view class="info-line"><text class="info-label">技师</text><text class="info-value">{{ order.worker_name || "不指定" }}</text></view>
    </view>

    <!-- 核销码（已支付待核销） -->
    <view v-if="order.status === 1 && order.hexiao_code" class="card hexiao-card">
      <text class="hexiao-label">到店出示核销码</text>
      <text class="hexiao-code">{{ order.hexiao_code }}</text>
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
    </view>

    <view class="bottom-space"></view>

    <!-- 操作区 -->
    <view class="bottom-bar">
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
.subject-name {
  display: block;
  padding: 12rpx 0;
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.info-line {
  display: flex;
  justify-content: space-between;
  padding: 8rpx 0;
  font-size: 26rpx;
}
.info-label {
  color: #999999;
}
.info-value {
  color: #333333;
}
.hexiao-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  background: #ffffff;
}
.hexiao-label {
  font-size: 24rpx;
  color: #999999;
}
.hexiao-code {
  margin-top: 12rpx;
  font-size: 48rpx;
  font-weight: 700;
  letter-spacing: 6rpx;
  color: #7b7de5;
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
