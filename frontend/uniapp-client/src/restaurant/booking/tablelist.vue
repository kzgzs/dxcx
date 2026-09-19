<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";
import { bookingTableCategory, bookingTableList } from "../api/booking";
import type { BookableTable, BookingTableCategory } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";

const PICK_KEY = "booking_pick_table";

/** 选桌回传给预定页的时段（指定时段排除已被预定桌台） */
const time = ref("");
const cid = ref(0);
const cats = ref<BookingTableCategory[]>([]);
const showFee = ref(false);
const list = ref<BookableTable[]>([]);
const loading = ref(false);

function loadTables() {
  loading.value = true;
  bookingTableList(cid.value, time.value)
    .then((res) => {
      list.value = res.list || [];
    })
    .catch(() => {
      list.value = [];
    })
    .finally(() => {
      loading.value = false;
    });
}

onLoad((options) => {
  time.value = decodeURIComponent(String((options && options.time) || ""));
  bookingTableCategory()
    .then((res) => {
      cats.value = res.list || [];
      showFee.value = res.set.show_booking_fee === 1;
    })
    .catch(() => {
      cats.value = [];
    });
  loadTables();
});

function switchCat(id: number) {
  if (cid.value === id) return;
  cid.value = id;
  loadTables();
}

function pick(t: BookableTable) {
  uni.setStorageSync(PICK_KEY, t);
  uni.navigateBack({
    fail: () => uni.redirectTo({ url: "/restaurant/booking/add" }),
  });
}
</script>

<template>
  <view class="page">
    <view class="tabs">
      <view class="tab" :class="{ active: cid === 0 }" @click="switchCat(0)"><text>全部</text></view>
      <view
        v-for="c in cats"
        :key="c.id"
        class="tab"
        :class="{ active: cid === c.id }"
        @click="switchCat(c.id)"
      >
        <text>{{ c.name }}</text>
      </view>
    </view>

    <view v-if="time" class="time-bar"><text>当前时段：{{ time }}（已排除该时段被预定的桌台）</text></view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>该时段暂无可预定桌台</text></view>

    <view v-for="t in list" :key="t.id" class="table-card" @click="pick(t)">
      <image v-if="t.pic" class="table-img" :src="imgUrl(t.pic)" mode="aspectFill" />
      <view v-else class="table-img table-ph"><text>暂无图</text></view>
      <view class="table-info">
        <text class="table-name">{{ t.name }}</text>
        <text class="table-sub">{{ t.category_name || "桌台" }} · {{ t.seat }} 人桌</text>
        <text v-if="showFee" class="table-fee">{{ t.booking_fee > 0 ? "定金 ￥" + price2(t.booking_fee) : "免定金" }}</text>
      </view>
      <view class="pick-btn"><text>选择</text></view>
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
  flex-wrap: wrap;
  background: #ffffff;
  padding: 8rpx 16rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.tab {
  padding: 16rpx 28rpx;
  font-size: 26rpx;
  color: #666666;
  border-radius: 32rpx;
  margin: 8rpx;
  background: #f5f5f8;
}
.tab.active {
  color: #ffffff;
  background: #7b7de5;
  font-weight: 600;
}
.time-bar {
  margin: 24rpx 24rpx 0;
  padding: 16rpx 24rpx;
  background: rgba(123, 125, 229, 0.08);
  border-radius: 12rpx;
  font-size: 22rpx;
  color: #7b7de5;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.table-card {
  display: flex;
  align-items: center;
  margin: 24rpx;
  padding: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.table-img {
  width: 140rpx;
  height: 140rpx;
  border-radius: 12rpx;
  margin-right: 20rpx;
  flex-shrink: 0;
}
.table-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f2f2f5;
  color: #c5c5cf;
  font-size: 22rpx;
}
.table-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.table-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.table-sub {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
}
.table-fee {
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #f0883a;
  font-weight: 600;
}
.pick-btn {
  padding: 12rpx 36rpx;
  border-radius: 32rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 26rpx;
  flex-shrink: 0;
}
</style>
