<script setup lang="ts">
import { onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { depositOrderList } from "../api/deposit";
import type { DepositGroup } from "../api/types";
import { fmtTime, imgUrl } from "../../utils/format";
import StatusTag from "../components/StatusTag.vue";

const groups = ref<DepositGroup[]>([]);
const loaded = ref(false);

function load() {
  depositOrderList(1, 50)
    .then((res) => {
      groups.value = res.datalist || [];
    })
    .catch(() => {
      groups.value = [];
    })
    .finally(() => {
      loaded.value = true;
    });
}

onShow(() => {
  load();
});

function toneOf(status: number): "primary" | "success" | "warn" | "gray" {
  if (status === 1) return "success";
  if (status === 0) return "warn";
  return "gray";
}

function goDetail(id: number) {
  uni.navigateTo({ url: "/restaurant/deposit/orderdetail?id=" + id });
}

function goAdd() {
  uni.navigateTo({ url: "/restaurant/deposit/add" });
}
</script>

<template>
  <view class="page">
    <view class="head">
      <text class="head-title">我的寄存记录（寄存中）</text>
      <view class="add-link" @click="goAdd"><text>去寄存</text></view>
    </view>

    <view v-if="loaded && groups.length === 0" class="empty"><text>暂无寄存中的物品</text></view>

    <view v-for="g in groups" :key="g.bid" class="group-card">
      <view class="group-head">
        <image v-if="g.binfo.logo" class="group-logo" :src="imgUrl(g.binfo.logo)" mode="aspectFill" />
        <text class="group-name">{{ g.binfo.name || "商家" }}</text>
        <text class="group-count">{{ g.prolist.length }} 笔</text>
      </view>
      <view v-for="o in g.prolist" :key="o.id" class="item" @click="goDetail(o.id)">
        <view class="item-info">
          <text class="item-name">{{ o.name }}</text>
          <text class="item-sub">{{ fmtTime(o.createtime) }} 存入 · {{ o.position_name || "未分配位置" }}</text>
        </view>
        <view class="item-right">
          <text class="item-num">×{{ o.num }}</text>
          <StatusTag :text="o.status_text" :tone="toneOf(o.status)" />
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  padding: 24rpx 32rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.head-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.add-link {
  padding: 10rpx 32rpx;
  border-radius: 32rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 26rpx;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.group-card {
  margin: 24rpx;
  padding: 24rpx 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.group-head {
  display: flex;
  align-items: center;
  padding-bottom: 16rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.group-logo {
  width: 56rpx;
  height: 56rpx;
  border-radius: 8rpx;
  margin-right: 16rpx;
  background: #f2f2f5;
  flex-shrink: 0;
}
.group-name {
  flex: 1;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.group-count {
  font-size: 22rpx;
  color: #999999;
  flex-shrink: 0;
}
.item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20rpx 0;
  border-bottom: 1rpx solid #f8f8f8;
}
.item:last-child {
  border-bottom: none;
}
.item-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.item-name {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
.item-sub {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
}
.item-right {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}
.item-num {
  margin-right: 16rpx;
  font-size: 26rpx;
  color: #333333;
  font-weight: 600;
}
</style>
