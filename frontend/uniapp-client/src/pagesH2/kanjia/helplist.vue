<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onShow } from "@dcloudio/uni-app";
import { fetchKanjiaHelpList, fetchKanjiaJoins, kanjiaHelpCut } from "../api/kanjia";
import type { KanjiaHelpRow, KanjiaJoin } from "../api/types";
import { fmtTime, imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import Countdown from "../components/Countdown.vue";

const list = ref<KanjiaJoin[]>([]);
const loading = ref(false);
const loaded = ref(false);

/** 当前展开帮砍明细的 joinid */
const expandedId = ref(0);
const helpRows = ref<KanjiaHelpRow[]>([]);
const helpLoading = ref(false);

/** 帮砍动作防连点 */
const cuttingId = ref(0);

async function load() {
  if (loading.value) return;
  loading.value = true;
  try {
    const data = await fetchKanjiaJoins(1, 50);
    list.value = data.list || [];
    loaded.value = true;
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

onLoad((options) => {
  const joinid = Number(options && options.joinid) || 0;
  if (joinid > 0) {
    expandedId.value = joinid;
    loadHelp(joinid);
  }
});

onShow(() => {
  load();
});

async function loadHelp(joinid: number) {
  helpLoading.value = true;
  try {
    helpRows.value = await fetchKanjiaHelpList(joinid);
  } catch {
    helpRows.value = [];
  } finally {
    helpLoading.value = false;
  }
}

function toggleHelp(join: KanjiaJoin) {
  if (expandedId.value === join.id) {
    expandedId.value = 0;
    helpRows.value = [];
    return;
  }
  expandedId.value = join.id;
  loadHelp(join.id);
}

/** 帮砍一刀（同一会员同一发起仅可帮砍一次，后端校验） */
async function doHelpCut(join: KanjiaJoin) {
  if (cuttingId.value > 0) return;
  cuttingId.value = join.id;
  try {
    const res = await kanjiaHelpCut(join.id);
    uni.showToast({ title: "帮砍成功，砍掉 ￥" + price2(res.cut_price), icon: "none" });
    // 本地先行落状态，再整体刷新
    join.now_price = res.now_price;
    join.helpnum = join.helpnum + 1;
    join.status = res.status;
    if (expandedId.value === join.id) {
      loadHelp(join.id);
    }
    load();
  } catch {
    // request 已弹 toast（您已经砍过了/砍价已结束等）
  } finally {
    cuttingId.value = 0;
  }
}

function goBuy(join: KanjiaJoin) {
  goPage("/pagesH2/kanjia/buy?joinid=" + join.id);
}

function goOrders() {
  goPage("/pagesH2/kanjia/orderlist");
}
</script>

<template>
  <view class="page">
    <view class="head-bar">
      <text class="head-title">我的砍价记录</text>
      <text class="head-entry" @click="goOrders">砍价订单</text>
    </view>

    <view v-if="loaded && list.length === 0" class="empty"><text>暂无砍价记录，去砍价商品列表看看吧</text></view>

    <view v-for="j in list" :key="j.id" class="join-card">
      <view class="join-head">
        <image v-if="j.propic" class="join-img" :src="imgUrl(j.propic)" mode="aspectFill" />
        <view v-else class="join-img join-img-ph"><text>图</text></view>
        <view class="join-meta">
          <text class="join-name">{{ j.proname || "砍价商品" }}</text>
          <view class="join-price">
            <text class="now">当前 ￥{{ price2(j.now_price) }}</text>
            <text v-if="j.min_price !== undefined" class="min">底价 ￥{{ price2(j.min_price) }}</text>
          </view>
        </view>
        <text class="join-status" :class="'st' + j.status">{{ j.status_text }}</text>
      </view>

      <!-- 进度条 -->
      <view class="progress-bar">
        <view class="progress-inner" :style="{ width: (j.cut_percent || 0) + '%' }"></view>
      </view>
      <view class="join-sub">
        <text class="sub-text">已砍 {{ j.helpnum }}/{{ j.target_helpnum || "-" }} 刀（{{ j.cut_percent || 0 }}%）</text>
        <text v-if="j.status === 0 && j.deadtime" class="sub-count">
          剩 <Countdown :deadline="j.deadtime" />
        </text>
        <text v-if="j.status === 0" class="sub-time">{{ fmtTime(j.createtime) }} 发起</text>
      </view>

      <!-- 操作按钮 -->
      <view class="join-btns">
        <view v-if="j.status === 0" class="j-btn cut" @click="doHelpCut(j)">
          <text>{{ cuttingId === j.id ? "帮砍中..." : "帮砍一刀" }}</text>
        </view>
        <view class="j-btn plain" @click="toggleHelp(j)">
          <text>{{ expandedId === j.id ? "收起帮砍明细" : "帮砍明细" }}</text>
        </view>
        <view v-if="j.status === 1 && j.isbuy === 0" class="j-btn buy" @click="goBuy(j)">
          <text>去下单</text>
        </view>
        <view v-if="j.status === 1 && j.isbuy === 1" class="j-btn plain" @click="goOrders">
          <text>已下单</text>
        </view>
      </view>

      <!-- 帮砍明细 -->
      <view v-if="expandedId === j.id" class="help-list">
        <view v-if="helpLoading" class="help-empty"><text>加载中...</text></view>
        <view v-else-if="helpRows.length === 0" class="help-empty"><text>还没有好友帮砍，快去邀请吧</text></view>
        <view v-for="h in helpRows" :key="h.id" class="help-row">
          <image v-if="h.headimg" class="help-avatar" :src="imgUrl(h.headimg)" mode="aspectFill" />
          <view v-else class="help-avatar help-avatar-ph"><text>友</text></view>
          <view class="help-meta">
            <text class="help-name">{{ h.nickname || "好友" }}</text>
            <text class="help-time">{{ fmtTime(h.createtime) }}</text>
          </view>
          <text class="help-cut">砍掉 ￥{{ price2(h.cut_price) }}</text>
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
.head-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  padding: 26rpx 24rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.head-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.head-entry {
  font-size: 24rpx;
  color: #7b7de5;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.join-card {
  margin: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.join-head {
  display: flex;
  align-items: center;
}
.join-img {
  width: 110rpx;
  height: 110rpx;
  border-radius: 10rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.join-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.join-meta {
  flex: 1;
  margin-left: 20rpx;
}
.join-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 1;
  overflow: hidden;
  font-size: 27rpx;
  font-weight: 600;
  color: #333333;
}
.join-price {
  margin-top: 10rpx;
  display: flex;
  align-items: baseline;
}
.now {
  color: #ff4d4f;
  font-size: 28rpx;
  font-weight: 700;
}
.min {
  margin-left: 16rpx;
  color: #999999;
  font-size: 22rpx;
}
.join-status {
  font-size: 24rpx;
  font-weight: 600;
}
.join-status.st0 {
  color: #ff9f43;
}
.join-status.st1 {
  color: #22c07a;
}
.join-status.st2 {
  color: #999999;
}
.progress-bar {
  margin-top: 20rpx;
  height: 14rpx;
  border-radius: 7rpx;
  background: #f2f2f5;
  overflow: hidden;
}
.progress-inner {
  height: 100%;
  border-radius: 7rpx;
  background: linear-gradient(90deg, #ff9f43, #ff4d4f);
}
.join-sub {
  margin-top: 14rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.sub-text {
  font-size: 22rpx;
  color: #666666;
}
.sub-count {
  font-size: 22rpx;
  color: #ff4d4f;
}
.sub-time {
  font-size: 22rpx;
  color: #b5b5b5;
}
.join-btns {
  margin-top: 20rpx;
  display: flex;
  flex-wrap: wrap;
}
.j-btn {
  margin-right: 16rpx;
  margin-top: 8rpx;
  padding: 12rpx 32rpx;
  border-radius: 32rpx;
  font-size: 24rpx;
  background: #7b7de5;
  color: #ffffff;
}
.j-btn.cut {
  background: #ff4d4f;
}
.j-btn.buy {
  background: #22c07a;
}
.j-btn.plain {
  background: #ffffff;
  color: #555555;
  border: 1rpx solid #cccccc;
}
.help-list {
  margin-top: 20rpx;
  border-top: 1rpx solid #f5f5f5;
  padding-top: 8rpx;
}
.help-empty {
  padding: 30rpx 0;
  text-align: center;
  color: #999999;
  font-size: 24rpx;
}
.help-row {
  display: flex;
  align-items: center;
  padding: 16rpx 0;
}
.help-avatar {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  flex-shrink: 0;
  background: #f2f2f5;
}
.help-avatar-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 22rpx;
}
.help-meta {
  flex: 1;
  margin-left: 16rpx;
}
.help-name {
  font-size: 25rpx;
  color: #333333;
}
.help-time {
  margin-top: 4rpx;
  font-size: 20rpx;
  color: #b5b5b5;
  display: block;
}
.help-cut {
  color: #ff4d4f;
  font-size: 25rpx;
  font-weight: 600;
}
</style>
