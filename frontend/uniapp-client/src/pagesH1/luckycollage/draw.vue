<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchLuckyDrawResult } from "../../api/collage";
import type { LuckyDrawResult } from "../../api/collage";
import { imgUrl, price2, fmtTime } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import BizCountdown from "../components/BizCountdown.vue";

const orderId = ref(0);
const result = ref<LuckyDrawResult | null>(null);
const loading = ref(false);

/** 本人中奖态：0待开奖 1中奖 2未中奖 */
const myIszj = computed(() => (result.value ? result.value.order.iszj : 0));

const winners = computed(() => (result.value ? result.value.members.filter((m) => m.iszj === 1) : []));

onLoad((options) => {
  orderId.value = Number(options && options.orderid) || 0;
  if (orderId.value > 0) load();
});

async function load() {
  if (loading.value) return;
  loading.value = true;
  try {
    result.value = await fetchLuckyDrawResult(orderId.value);
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

function goTeam() {
  const r = result.value;
  if (r && r.order.teamid > 0) {
    goPage("/pagesH1/luckycollage/team?teamid=" + r.order.teamid);
  }
}

function goOrders() {
  goPage("/pagesH1/luckycollage/orderlist");
}

function onFinish() {
  // 倒计时归零后重拉（定时任务开奖可能有延迟）
  load();
}
</script>

<template>
  <view class="page">
    <template v-if="result">
      <!-- 开奖结果横幅 -->
      <view class="banner" :class="{ win: myIszj === 1, lose: myIszj === 2 }">
        <template v-if="myIszj === 1">
          <text class="banner-icon">🎉</text>
          <text class="banner-title">恭喜您中奖啦！</text>
          <text class="banner-sub">商品将按订单信息为您发货</text>
        </template>
        <template v-else-if="myIszj === 2">
          <text class="banner-icon">☁️</text>
          <text class="banner-title">很遗憾，本次未中奖</text>
          <text class="banner-sub">已按规则为您返还，感谢参与</text>
        </template>
        <template v-else>
          <text class="banner-icon">⏳</text>
          <text class="banner-title">等待开奖</text>
          <text class="banner-sub">团满员后自动开奖，请稍后再来查看</text>
        </template>
      </view>

      <!-- 订单信息 -->
      <view class="order-card">
        <view class="goods-row">
          <image v-if="result.order.propic" class="goods-img" :src="imgUrl(result.order.propic)" mode="aspectFill" />
          <view v-else class="goods-img goods-img-ph"><text>图</text></view>
          <view class="goods-meta">
            <text class="goods-name">{{ result.order.proname }}</text>
            <text v-if="result.order.ggname" class="goods-spec">{{ result.order.ggname }}</text>
            <view class="row-bottom">
              <text class="price">￥{{ price2(result.order.totalprice) }}</text>
              <text class="num">x{{ result.order.num }}</text>
            </view>
          </view>
        </view>
        <view class="info-row"><text class="info-label">订单号</text><text class="info-value">{{ result.order.ordernum }}</text></view>
        <view class="info-row"><text class="info-label">下单时间</text><text class="info-value">{{ fmtTime(result.order.createtime) }}</text></view>
        <view class="info-row">
          <text class="info-label">订单状态</text>
          <text class="info-value">{{ result.order.status_text }}（{{ result.order.iszj_text }}）</text>
        </view>
        <view v-if="result.order.refund_text" class="info-row">
          <text class="info-label">返还/退款</text>
          <text class="info-value">{{ result.order.refund_text }}{{ result.order.refund_money > 0 ? " ￥" + price2(result.order.refund_money) : "" }}</text>
        </view>
      </view>

      <!-- 团信息与倒计时 -->
      <view v-if="result.team" class="team-card">
        <view class="team-head">
          <text class="card-title">团状态：{{ result.team.status_text }}</text>
          <BizCountdown v-if="result.team.status === 1" :deadline="result.team.deadline" prefix="距开奖截止" @finish="onFinish" />
        </view>
        <text class="team-sub">{{ result.team.num }}/{{ result.team.teamnum }} 人 · 开团时间 {{ fmtTime(result.team.createtime) }}</text>
        <view class="team-link" @click="goTeam"><text>查看团详情 ></text></view>
      </view>

      <!-- 团成员中奖情况 -->
      <view v-if="result.members.length > 0" class="members-card">
        <text class="card-title">团成员开奖情况</text>
        <view v-for="(m, i) in result.members" :key="i" class="member-row">
          <view class="member-avatar">
            <image v-if="m.headimg" class="member-img" :src="imgUrl(m.headimg)" mode="aspectFill" />
            <text v-else class="member-ph">{{ (m.nickname || "拼").slice(0, 1) }}</text>
          </view>
          <text class="member-name">{{ m.nickname || "拼团用户" }}</text>
          <text class="member-result" :class="{ win: m.iszj === 1, lose: m.iszj === 2 }">{{ m.iszj_text }}</text>
        </view>
        <view v-if="winners.length > 0" class="winner-summary">
          <text>本团共 {{ winners.length }} 人中奖</text>
        </view>
      </view>

      <!-- 操作按钮 -->
      <view class="actions">
        <view class="act-btn plain" @click="load"><text>{{ loading ? "刷新中..." : "刷新结果" }}</text></view>
        <view class="act-btn" @click="goOrders"><text>返回订单列表</text></view>
      </view>
    </template>
    <view v-else class="empty"><text>加载中...</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx;
  box-sizing: border-box;
}
.banner {
  border-radius: 16rpx;
  padding: 50rpx 28rpx;
  background: #ffffff;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.banner.win {
  background: linear-gradient(135deg, #fff1ec, #ffe0d4);
}
.banner.lose {
  background: #f5f5f7;
}
.banner-icon {
  font-size: 64rpx;
}
.banner-title {
  margin-top: 16rpx;
  font-size: 36rpx;
  font-weight: 700;
  color: #333333;
}
.banner-sub {
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #777777;
}
.order-card {
  margin-top: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.goods-row {
  display: flex;
  padding-bottom: 20rpx;
  border-bottom: 1rpx solid #f5f5f5;
}
.goods-img {
  width: 130rpx;
  height: 130rpx;
  border-radius: 10rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.goods-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.goods-meta {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
}
.goods-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
}
.goods-spec {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
}
.row-bottom {
  margin-top: auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.price {
  color: #333333;
  font-size: 26rpx;
  font-weight: 600;
}
.num {
  font-size: 24rpx;
  color: #999999;
}
.info-row {
  display: flex;
  justify-content: space-between;
  padding: 12rpx 0;
}
.info-label {
  font-size: 24rpx;
  color: #999999;
}
.info-value {
  font-size: 24rpx;
  color: #333333;
}
.team-card {
  margin-top: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.team-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.card-title {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.team-sub {
  display: block;
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #999999;
}
.team-link {
  margin-top: 16rpx;
  font-size: 24rpx;
  color: #7b7de5;
}
.members-card {
  margin-top: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.member-row {
  display: flex;
  align-items: center;
  padding: 18rpx 0;
  border-bottom: 1rpx solid #f5f5f5;
}
.member-row:last-of-type {
  border-bottom: none;
}
.member-avatar {
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
  background: #f2f2f5;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.member-img {
  width: 100%;
  height: 100%;
}
.member-ph {
  font-size: 24rpx;
  color: #999999;
}
.member-name {
  flex: 1;
  margin-left: 20rpx;
  font-size: 26rpx;
  color: #333333;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.member-result {
  font-size: 24rpx;
  color: #999999;
}
.member-result.win {
  color: #ff3b30;
  font-weight: 600;
}
.member-result.lose {
  color: #999999;
}
.winner-summary {
  margin-top: 16rpx;
  text-align: center;
  font-size: 22rpx;
  color: #ff6034;
}
.actions {
  margin-top: 40rpx;
  display: flex;
}
.act-btn {
  flex: 1;
  padding: 22rpx 0;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 44rpx;
  text-align: center;
  font-size: 28rpx;
}
.act-btn.plain {
  background: #ffffff;
  color: #7b7de5;
  border: 1rpx solid #7b7de5;
  margin-right: 20rpx;
}
.empty {
  padding: 200rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
</style>
