<script setup lang="ts">
import { ref } from "vue";
import { onShow, onReachBottom } from "@dcloudio/uni-app";
import { fetchRechargeOrders } from "../../api/mall";
import type { RechargeOrderItem } from "../../api/types";
import { price2 } from "../../utils/format";
import { navigateTo, PAGES } from "../../utils/navigate";

const list = ref<RechargeOrderItem[]>([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const loaded = ref(false);

onShow(() => {
  load();
});

onReachBottom(() => {
  if (list.value.length < total.value) {
    page.value += 1;
    load(false);
  }
});

async function load(reset = true) {
  if (loading.value) return;
  loading.value = true;
  if (reset) {
    page.value = 1;
    list.value = [];
  }
  try {
    const data = await fetchRechargeOrders(page.value, 10);
    list.value = reset ? data.list || [] : list.value.concat(data.list || []);
    total.value = data.total || 0;
    loaded.value = true;
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

function payTypeName(payType: string): string {
  if (payType === "wechat") return "微信支付";
  if (payType === "alipay") return "支付宝";
  return payType || "--";
}

function goPay(o: RechargeOrderItem) {
  navigateTo(
    PAGES.pay + "?order_id=" + o.id + "&amount=" + o.money + "&scene=recharge"
  );
}
</script>

<template>
  <view class="page">
    <view v-if="loaded && list.length === 0 && !loading" class="empty">
      <text>暂无充值记录</text>
    </view>

    <view v-for="o in list" :key="o.id" class="order-card">
      <view class="card-head">
        <text class="order-sn">{{ o.order_sn }}</text>
        <text class="status" :class="o.status === 1 ? 'ok' : 'pending'">
          {{ o.status === 1 ? "已支付" : "待支付" }}
        </text>
      </view>

      <view class="amount-row">
        <text class="amount">￥{{ price2(o.money) }}</text>
        <text v-if="o.give_money > 0 || o.give_score > 0" class="give">
          赠送{{ o.give_money > 0 ? "￥" + price2(o.give_money) : "" }}{{ o.give_money > 0 && o.give_score > 0 ? " + " : "" }}{{ o.give_score > 0 ? o.give_score + "积分" : "" }}
        </text>
      </view>

      <view class="row">
        <text class="label">支付方式</text>
        <text class="value">{{ o.status === 1 ? payTypeName(o.pay_type) : "--" }}</text>
      </view>
      <view class="row">
        <text class="label">创建时间</text>
        <text class="value">{{ o.create_time || "--" }}</text>
      </view>
      <view v-if="o.pay_time" class="row">
        <text class="label">支付时间</text>
        <text class="value">{{ o.pay_time }}</text>
      </view>

      <view v-if="o.status === 0" class="card-foot">
        <text class="act-btn" @click="goPay(o)">去支付</text>
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
  padding: 20rpx;
  box-sizing: border-box;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.order-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 18rpx;
  border-bottom: 1rpx solid #f5f5f5;
}
.order-sn {
  font-size: 24rpx;
  color: #999999;
}
.status {
  font-size: 26rpx;
  font-weight: 600;
}
.status.ok {
  color: #67c23a;
}
.status.pending {
  color: #e6a23c;
}
.amount-row {
  display: flex;
  align-items: baseline;
  padding: 20rpx 0 8rpx;
}
.amount {
  font-size: 40rpx;
  font-weight: 700;
  color: #333333;
}
.give {
  margin-left: 20rpx;
  font-size: 22rpx;
  color: #e6a23c;
}
.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8rpx 0;
}
.label {
  font-size: 26rpx;
  color: #999999;
}
.value {
  font-size: 26rpx;
  color: #333333;
}
.card-foot {
  display: flex;
  justify-content: flex-end;
  border-top: 1rpx solid #f5f5f5;
  padding-top: 18rpx;
  margin-top: 10rpx;
}
.act-btn {
  padding: 10rpx 40rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 30rpx;
  font-size: 24rpx;
}
</style>
