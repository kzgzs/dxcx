<script setup lang="ts">
import { onReachBottom, onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { carOrderCancel, carOrderPay, carOrderRefund, fetchCarOrderList } from "../../api/industry";
import type { CarOrder } from "../../api/types";
import { dpNavigate } from "../../components/dp/navigate";
import { fmtTime, imgUrl, price2 } from "../../utils/format";

const tabs = [
  { label: "全部", status: -1 },
  { label: "待支付", status: 0 },
  { label: "已支付", status: 1 },
  { label: "使用中", status: 2 },
  { label: "已完成", status: 3 },
];
const tabIdx = ref(0);
const list = ref<CarOrder[]>([]);
const page = ref(1);
const finished = ref(false);
const loading = ref(false);

function load(reset = false) {
  if (loading.value) return;
  if (reset) {
    page.value = 1;
    finished.value = false;
    list.value = [];
  }
  if (finished.value && !reset) return;
  loading.value = true;
  fetchCarOrderList(tabs[tabIdx.value].status, page.value)
    .then((res) => {
      list.value = list.value.concat(res.list || []);
      if (list.value.length >= res.total || (res.list || []).length === 0) {
        finished.value = true;
      } else {
        page.value += 1;
      }
    })
    .catch(() => {
      finished.value = true;
    })
    .finally(() => {
      loading.value = false;
    });
}

onShow(() => {
  load(true);
});

function switchTab(i: number) {
  if (tabIdx.value === i) return;
  tabIdx.value = i;
  load(true);
}

function goDetail(o: CarOrder) {
  dpNavigate("/carhailing/order/detail?id=" + o.id);
}

function doPay(o: CarOrder) {
  carOrderPay(o.id)
    .then(() => {
      uni.showToast({ title: "支付成功", icon: "success" });
      load(true);
    })
    .catch((err: Error) => uni.showToast({ title: err.message || "支付失败", icon: "none" }));
}

function doCancel(o: CarOrder) {
  uni.showModal({
    title: "提示",
    content: "确定取消该订单吗？",
    success: (r) => {
      if (!r.confirm) return;
      carOrderCancel(o.id)
        .then(() => {
          uni.showToast({ title: "已取消", icon: "success" });
          load(true);
        })
        .catch((err: Error) => uni.showToast({ title: err.message || "取消失败", icon: "none" }));
    },
  });
}

function doRefund(o: CarOrder) {
  uni.showModal({
    title: "提示",
    content: "确定申请退款吗？",
    success: (r) => {
      if (!r.confirm) return;
      carOrderRefund(o.id)
        .then(() => {
          uni.showToast({ title: "退款成功", icon: "success" });
          load(true);
        })
        .catch((err: Error) => uni.showToast({ title: err.message || "退款失败", icon: "none" }));
    },
  });
}

onReachBottom(() => {
  load(false);
});
</script>

<template>
  <view class="page">
    <view class="tabs">
      <view
        v-for="(t, i) in tabs"
        :key="t.status"
        class="tab"
        :class="{ active: i === tabIdx }"
        @click="switchTab(i)"
      >
        <text>{{ t.label }}</text>
      </view>
    </view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无订单</text></view>

    <view v-for="o in list" :key="o.id" class="order-card">
      <view class="order-head">
        <text class="order-sn">订单号：{{ o.order_sn }}</text>
        <text class="order-status">{{ o.status_text }}</text>
      </view>
      <view class="order-body">
        <image v-if="o.pic" class="goods-img" :src="imgUrl(o.pic)" mode="aspectFill" />
        <view class="goods-info">
          <text class="goods-name">{{ o.product_name }}</text>
          <text class="goods-meta">{{ fmtTime(o.start_time) }} ~ {{ fmtTime(o.end_time) }}</text>
          <text class="goods-meta">租期 {{ o.days === 0.5 ? "半天" : o.days + " 天" }}</text>
        </view>
      </view>
      <view class="order-detail">
        <view class="detail-line"><text>订单金额</text><text>￥{{ price2(o.amount) }}</text></view>
        <view v-if="o.coupon_money > 0" class="detail-line"><text>优惠券抵扣</text><text>-￥{{ price2(o.coupon_money) }}</text></view>
        <view v-if="o.score_money > 0" class="detail-line"><text>积分抵扣（{{ o.use_score }}分）</text><text>-￥{{ price2(o.score_money) }}</text></view>
        <view class="detail-line total"><text>实付</text><text>￥{{ price2(o.pay_amount) }}</text></view>
        <view class="detail-line"><text>下单时间</text><text>{{ fmtTime(o.create_time) }}</text></view>
        <view v-if="o.pay_time > 0" class="detail-line"><text>支付时间</text><text>{{ fmtTime(o.pay_time) }}</text></view>
      </view>
      <view class="order-actions">
        <view class="btn plain" @click="goDetail(o)"><text>查看详情</text></view>
        <view v-if="o.status === 0" class="btn plain" @click="doCancel(o)"><text>取消订单</text></view>
        <view v-if="o.status === 0" class="btn primary" @click="doPay(o)"><text>立即支付</text></view>
        <view v-if="o.status === 1" class="btn plain" @click="doRefund(o)"><text>申请退款</text></view>
      </view>
    </view>

    <view v-if="list.length > 0" class="list-end">
      <text>{{ finished ? "没有更多了" : "加载中..." }}</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.tabs {
  display: flex;
  background: #ffffff;
  padding: 0 8rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.tab {
  flex: 1;
  text-align: center;
  padding: 24rpx 0;
  font-size: 26rpx;
  color: #666666;
}
.tab.active {
  color: #7b7de5;
  font-weight: 600;
  border-bottom: 4rpx solid #7b7de5;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.order-card {
  margin: 24rpx;
  padding: 24rpx 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.order-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 20rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.order-sn {
  font-size: 24rpx;
  color: #999999;
}
.order-status {
  font-size: 26rpx;
  color: #7b7de5;
  font-weight: 600;
}
.order-body {
  display: flex;
  padding: 24rpx 0;
}
.goods-img {
  width: 140rpx;
  height: 140rpx;
  border-radius: 12rpx;
  margin-right: 20rpx;
  background: #f2f2f5;
}
.goods-info {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.goods-name {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
.goods-meta {
  margin-top: 10rpx;
  font-size: 24rpx;
  color: #999999;
}
.order-detail {
  border-top: 1rpx solid #f2f2f2;
  padding-top: 16rpx;
}
.detail-line {
  display: flex;
  justify-content: space-between;
  font-size: 24rpx;
  color: #666666;
  line-height: 42rpx;
}
.detail-line.total {
  color: #333333;
  font-weight: 600;
}
.order-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 20rpx;
}
.btn {
  margin-left: 16rpx;
  padding: 12rpx 36rpx;
  border-radius: 32rpx;
  font-size: 26rpx;
}
.btn.plain {
  border: 1rpx solid #dddddd;
  color: #666666;
}
.btn.primary {
  background: #7b7de5;
  color: #ffffff;
}
.list-end {
  padding: 24rpx 0 48rpx;
  text-align: center;
  color: #bbbbbb;
  font-size: 24rpx;
}
</style>
