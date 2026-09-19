<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchCollageTeamDetail } from "../../api/collage";
import type { BizTeam } from "../../api/collage";
import { imgUrl, fmtTime } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import BizCountdown from "../components/BizCountdown.vue";

const teamId = ref(0);
const team = ref<BizTeam | null>(null);

/** 可参团：进行中 + 未满员（超时由倒计时提示，提交时后端二次校验） */
const joinable = computed(() => {
  const t = team.value;
  if (!t) return false;
  return t.status === 1 && t.num < t.teamnum && t.deadline > Math.floor(Date.now() / 1000);
});

const emptySlots = computed(() => {
  const t = team.value;
  return t ? Math.max(0, t.teamnum - t.members.length) : 0;
});

onLoad((options) => {
  teamId.value = Number(options && options.teamid) || 0;
  if (teamId.value > 0) load();
});

async function load() {
  try {
    team.value = await fetchCollageTeamDetail(teamId.value);
  } catch {
    // request 已弹 toast
  }
}

function goJoin() {
  const t = team.value;
  if (!t) return;
  goPage("/pagesH1/collage/buy?proid=" + t.proid + "&buytype=3&teamid=" + t.id);
}

function goDetail() {
  const t = team.value;
  if (!t) return;
  goPage("/pagesH1/collage/detail?id=" + t.proid);
}

function onTeamFinish() {
  load();
}
</script>

<template>
  <view class="page">
    <template v-if="team">
      <!-- 团状态卡 -->
      <view class="status-card">
        <text class="status-text">{{ team.status_text }}</text>
        <text class="status-sub">
          {{ team.num }}/{{ team.teamnum }} 人 · 开团时间 {{ fmtTime(team.createtime) }}
        </text>
        <BizCountdown v-if="team.status === 1" :deadline="team.deadline" prefix="距成团截止" @finish="onTeamFinish" />
        <view class="product-link" @click="goDetail">
          <text>查看商品：{{ team.product_name }} ></text>
        </view>
      </view>

      <!-- 成员席位 -->
      <view class="members-card">
        <text class="card-title">团员席位</text>
        <view class="seats">
          <view v-for="(m, i) in team.members" :key="i" class="seat">
            <view class="seat-avatar">
              <image v-if="m.headimg" class="seat-img" :src="imgUrl(m.headimg)" mode="aspectFill" />
              <text v-else class="seat-ph">{{ (m.nickname || "拼").slice(0, 1) }}</text>
            </view>
            <text class="seat-name">{{ m.nickname || "拼团用户" }}</text>
            <text v-if="i === 0" class="seat-tag">团长</text>
          </view>
          <view v-for="n in emptySlots" :key="'e' + n" class="seat">
            <view class="seat-avatar empty-seat"><text class="seat-ph">虚位以待</text></view>
          </view>
        </view>
      </view>

      <view class="bottom-space"></view>

      <!-- 底部按钮 -->
      <view v-if="joinable" class="bottom-bar">
        <view class="join-btn" @click="goJoin"><text>一键参团</text></view>
      </view>
      <view v-else class="bottom-bar">
        <view class="join-btn disabled" @click="goDetail"><text>该团已结束，去看看商品</text></view>
      </view>
    </template>
    <view v-else class="empty"><text>加载中...</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.status-card {
  margin: 20rpx;
  padding: 40rpx 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.status-text {
  font-size: 36rpx;
  font-weight: 700;
  color: #7b7de5;
}
.status-sub {
  margin-top: 14rpx;
  font-size: 24rpx;
  color: #999999;
}
.product-link {
  margin-top: 20rpx;
  font-size: 24rpx;
  color: #555555;
  background: #f5f5f7;
  border-radius: 24rpx;
  padding: 10rpx 26rpx;
}
.members-card {
  margin: 0 20rpx;
  padding: 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.card-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.seats {
  margin-top: 30rpx;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
}
.seat {
  width: 25%;
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 30rpx;
  position: relative;
}
.seat-avatar {
  width: 96rpx;
  height: 96rpx;
  border-radius: 50%;
  background: #f2f2f5;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
.empty-seat {
  border: 2rpx dashed #d5d5de;
  background: #fafafa;
}
.seat-img {
  width: 100%;
  height: 100%;
}
.seat-ph {
  font-size: 20rpx;
  color: #999999;
}
.seat-name {
  margin-top: 12rpx;
  font-size: 22rpx;
  color: #555555;
  max-width: 140rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.seat-tag {
  position: absolute;
  top: -12rpx;
  right: 12rpx;
  background: #ff6034;
  color: #ffffff;
  font-size: 18rpx;
  border-radius: 12rpx;
  padding: 2rpx 10rpx;
}
.bottom-space {
  height: 160rpx;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  border-top: 1rpx solid #eeeeee;
}
.join-btn {
  padding: 22rpx 0;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 44rpx;
  text-align: center;
  font-size: 30rpx;
}
.join-btn.disabled {
  background: #b5b5c5;
}
.empty {
  padding: 200rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
</style>
