<script setup lang="ts">
import { onReachBottom, onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { bookingOrderList, bookingPay } from "../api/booking";
import type { BookingOrder } from "../api/types";
import { fmtTime, price2 } from "../../utils/format";
import StatusTag from "../components/StatusTag.vue";

const tabs = [
  { label: "全部", st: "" },
  { label: "待支付", st: "0" },
  { label: "待审核", st: "1" },
  { label: "已完成", st: "3" },
  { label: "已关闭", st: "4" },
];
const tabIdx = ref(0);
const list = ref<BookingOrder[]>([]);
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
  bookingOrderList(tabs[tabIdx.value].st, page.value)
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

function goDetail(o: BookingOrder) {
  uni.navigateTo({ url: "/restaurant/booking/detail?id=" + o.id });
}

function doPay(o: BookingOrder) {
  bookingPay(o.id)
    .then(() => {
      uni.showToast({ title: "支付成功", icon: "success" });
      load(true);
    })
    .catch(() => {
      /* 后端 toast 已提示 */
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
        :key="t.st"
        class="tab"
        :class="{ active: i === tabIdx }"
        @click="switchTab(i)"
      >
        <text>{{ t.label }}</text>
      </view>
    </view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无预定订单</text></view>

    <view v-for="o in list" :key="o.id" class="order-card" @click="goDetail(o)">
      <view class="order-head">
        <text class="table-name">{{ o.table_name || `桌台#${o.table_id}` }}</text>
        <StatusTag
          :text="o.status === 1 ? o.check_text : o.status_text"
          :tone="o.status === 0 ? 'warn' : o.status === 4 ? 'gray' : o.check_status === 1 ? 'success' : 'primary'"
        />
      </view>
      <view class="order-line"><text class="label">用餐时间</text><text>{{ o.booking_time }}</text></view>
      <view class="order-line"><text class="label">用餐人数</text><text>{{ o.seat }} 人</text></view>
      <view class="order-line">
        <text class="label">定金</text>
        <text>{{ o.totalprice > 0 ? "￥" + price2(o.totalprice) : "免费" }}</text>
      </view>
      <view class="order-line"><text class="label">下单时间</text><text>{{ fmtTime(o.createtime) }}</text></view>
      <view class="order-actions" v-if="o.status === 0">
        <view class="btn primary" @click.stop="doPay(o)"><text>立即支付</text></view>
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
  align-items: center;
  justify-content: space-between;
  padding-bottom: 16rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.table-name {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.order-line {
  display: flex;
  justify-content: space-between;
  padding: 10rpx 0 0;
  font-size: 26rpx;
  color: #333333;
}
.order-line .label {
  color: #999999;
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
