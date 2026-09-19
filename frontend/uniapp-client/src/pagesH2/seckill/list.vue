<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchSeckillList } from "../api/seckill";
import type { SeckillSessionRow } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import SeckillTimeTag from "../components/SeckillTimeTag.vue";

const list = ref<SeckillSessionRow[]>([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);

/** 场次整点配置（后端 seckill_sysset.timeset） */
const timeset = ref<number[]>([]);
/** 当前场次过滤：-1 全部，其余为整点 */
const activeTime = ref(-1);

const tabs = computed(() => {
  const base = [{ label: "全部", time: -1 }];
  return base.concat(timeset.value.map((t) => ({ label: t + "点场", time: t })));
});

async function load(reset = true) {
  if (loading.value) return;
  loading.value = true;
  if (reset) {
    page.value = 1;
    list.value = [];
  }
  try {
    const data = await fetchSeckillList({
      seckill_time: activeTime.value >= 0 ? activeTime.value : 0,
      page: page.value,
      pagesize: 20,
    });
    list.value = reset ? data.list || [] : list.value.concat(data.list || []);
    total.value = data.total || 0;
    // 场次配置以首次返回为准（切换场次时保持一致）
    if (timeset.value.length === 0 && Array.isArray(data.timeset)) {
      timeset.value = data.timeset.map((t) => Number(t));
    }
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

onLoad(() => {
  load();
});

onReachBottom(() => {
  if (list.value.length < total.value) {
    page.value += 1;
    load(false);
  }
});

function switchTab(time: number) {
  if (activeTime.value === time) return;
  activeTime.value = time;
  load();
}

function goDetail(row: SeckillSessionRow) {
  goPage(
    "/pagesH2/seckill/detail?proid=" +
      row.proid +
      "&date=" +
      encodeURIComponent(row.seckill_date) +
      "&time=" +
      row.seckill_time
  );
}

function goOrders() {
  goPage("/pagesH2/seckill/orderlist");
}
</script>

<template>
  <view class="page">
    <view class="topbar">
      <scroll-view class="tabs" scroll-x>
        <view
          v-for="t in tabs"
          :key="t.label"
          class="tab"
          :class="{ active: activeTime === t.time }"
          @click="switchTab(t.time)"
        >
          <text>{{ t.label }}</text>
        </view>
      </scroll-view>
      <text class="orders-entry" @click="goOrders">秒杀订单</text>
    </view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无秒杀场次商品</text></view>

    <view v-for="(row, idx) in list" :key="row.proid + '-' + row.seckill_date + '-' + row.seckill_time + '-' + idx" class="pro-card" @click="goDetail(row)">
      <image v-if="row.pic" class="pro-img" :src="imgUrl(row.pic)" mode="aspectFill" />
      <view v-else class="pro-img pro-img-ph"><text>图</text></view>
      <view class="pro-meta">
        <text class="pro-name">{{ row.name }}</text>
        <view class="price-row">
          <text class="sk-price">秒杀价 ￥{{ price2(row.seckill_price) }}</text>
          <text class="sk-date">{{ row.seckill_date }} {{ row.seckill_time }}点场</text>
        </view>
        <view class="progress-row">
          <view class="progress-bar">
            <view class="progress-inner" :style="{ width: row.salepercent + '%' }"></view>
          </view>
          <text class="progress-text">已抢{{ row.salepercent }}%</text>
        </view>
        <view class="foot-row">
          <SeckillTimeTag :starttime="row.starttime" :endtime="row.endtime" />
          <text class="go-btn">去抢购</text>
        </view>
      </view>
    </view>

    <view v-if="loading" class="empty"><text>加载中...</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.topbar {
  display: flex;
  align-items: center;
  background: #ffffff;
  padding: 10rpx 20rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.tabs {
  flex: 1;
  white-space: nowrap;
}
.tab {
  display: inline-block;
  text-align: center;
  padding: 22rpx 26rpx;
  font-size: 26rpx;
  color: #555555;
}
.tab.active {
  color: #ff4d4f;
  font-weight: 600;
  border-bottom: 4rpx solid #ff4d4f;
}
.orders-entry {
  flex-shrink: 0;
  font-size: 24rpx;
  color: #7b7de5;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.pro-card {
  display: flex;
  margin: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.pro-img {
  width: 190rpx;
  height: 190rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.pro-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 26rpx;
}
.pro-meta {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.pro-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 1;
  overflow: hidden;
  font-size: 27rpx;
  font-weight: 600;
  color: #333333;
}
.price-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.sk-price {
  color: #ff4d4f;
  font-size: 30rpx;
  font-weight: 700;
}
.sk-date {
  font-size: 20rpx;
  color: #b5b5b5;
}
.progress-row {
  display: flex;
  align-items: center;
}
.progress-bar {
  flex: 1;
  height: 12rpx;
  border-radius: 6rpx;
  background: #fde3e3;
  overflow: hidden;
}
.progress-inner {
  height: 100%;
  border-radius: 6rpx;
  background: linear-gradient(90deg, #ff9f43, #ff4d4f);
}
.progress-text {
  margin-left: 14rpx;
  font-size: 20rpx;
  color: #ff4d4f;
}
.foot-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.go-btn {
  padding: 8rpx 30rpx;
  background: #ff4d4f;
  color: #ffffff;
  border-radius: 28rpx;
  font-size: 24rpx;
}
</style>
