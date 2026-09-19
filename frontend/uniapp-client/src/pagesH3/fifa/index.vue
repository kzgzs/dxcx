<script setup lang="ts">
/**
 * Fifa 赛事竞猜首页
 * - fifa/index：我的积分/猜中场次 + 按日期分组的赛程
 * - 点击赛事进入竞猜详情
 */
import { ref } from "vue";
import { onLoad, onPullDownRefresh } from "@dcloudio/uni-app";
import { fetchFifaIndex } from "../api";
import type { FifaIndexData, FifaMatch } from "../api";
import { imgUrl } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const data = ref<FifaIndexData | null>(null);
const loadError = ref(false);
/** 当前选中的日期分组下标 */
const activeIdx = ref(0);

onLoad(() => {
  void loadIndex();
});

onPullDownRefresh(async () => {
  await loadIndex();
  uni.stopPullDownRefresh();
});

async function loadIndex() {
  loadError.value = false;
  try {
    data.value = await fetchFifaIndex();
  } catch {
    data.value = null;
    loadError.value = true;
  }
}

function pickDate(idx: number) {
  activeIdx.value = idx;
}

function goDetail(item: FifaMatch) {
  goPage("/pagesH3/fifa/detail?id=" + item.id);
}
</script>

<template>
  <view class="page">
    <!-- 我的竞猜数据 -->
    <view v-if="data" class="stat-card">
      <view class="stat-item">
        <text class="stat-num">{{ data.myscore }}</text>
        <text class="stat-label">我的积分</text>
      </view>
      <view class="stat-item">
        <text class="stat-num">{{ data.successnum }}</text>
        <text class="stat-label">猜中场次</text>
      </view>
      <view class="stat-item">
        <text class="stat-num">{{ data.winscore }}</text>
        <text class="stat-label">获得积分</text>
      </view>
    </view>

    <view v-if="loadError" class="card err-card">
      <text class="err-text">赛程加载失败，请下拉刷新重试</text>
    </view>

    <template v-else-if="data && data.datalist.length > 0">
      <!-- 日期切换 -->
      <scroll-view scroll-x class="date-bar">
        <view
          v-for="(group, idx) in data.datalist"
          :key="group.date"
          class="date-item"
          :class="{ active: idx === activeIdx }"
          @click="pickDate(idx)"
        >
          <text class="date-text">{{ group.date }}</text>
          <text class="week-text">{{ group.week }}</text>
        </view>
      </scroll-view>

      <!-- 当日赛程 -->
      <view
        v-for="item in data.datalist[activeIdx].data"
        :key="item.id"
        class="card match-card"
        @click="goDetail(item)"
      >
        <view class="match-top">
          <text class="stage">{{ item.matchStage }}</text>
          <text class="match-time">{{ item.startDate }} {{ item.startTime }}</text>
          <text class="match-status">{{ item.matchStatusText }}</text>
        </view>
        <view class="match-body">
          <view class="team">
            <image v-if="item.leftTeam_logo !== ''" class="team-logo" :src="imgUrl(item.leftTeam_logo)" mode="aspectFit" />
            <text class="team-name">{{ item.leftTeam_name }}</text>
          </view>
          <view class="vs">
            <text v-if="item.leftTeam_score !== ''" class="score">{{ item.leftTeam_score }} : {{ item.rightTeam_score }}</text>
            <text v-else class="vs-text">VS</text>
          </view>
          <view class="team">
            <image v-if="item.rightTeam_logo !== ''" class="team-logo" :src="imgUrl(item.rightTeam_logo)" mode="aspectFit" />
            <text class="team-name">{{ item.rightTeam_name }}</text>
          </view>
        </view>
        <view v-if="item.matchDesc !== ''" class="match-desc">
          <text>{{ item.matchDesc }}</text>
        </view>
      </view>
    </template>

    <view v-else-if="data" class="card err-card">
      <text class="err-text">暂无赛程安排</text>
    </view>

    <!-- 规则 -->
    <view v-if="data && data.guize !== ''" class="card">
      <text class="title sm">竞猜规则</text>
      <rich-text :nodes="data.guize" />
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 60rpx;
}
.stat-card {
  display: flex;
  margin: 24rpx;
  padding: 32rpx 0;
  background: linear-gradient(90deg, #9b9df0, #7b7de5);
  border-radius: 20rpx;
}
.stat-item {
  flex: 1;
  text-align: center;
}
.stat-num {
  display: block;
  font-size: 40rpx;
  font-weight: 700;
  color: #ffffff;
}
.stat-label {
  display: block;
  margin-top: 8rpx;
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.8);
}
.card {
  margin: 0 24rpx 20rpx;
  background: #ffffff;
  border-radius: 20rpx;
  padding: 24rpx;
}
.err-card {
  margin-top: 24rpx;
  text-align: center;
  padding: 80rpx 24rpx;
}
.err-text {
  font-size: 28rpx;
  color: #999999;
}
.date-bar {
  margin: 0 24rpx 20rpx;
  white-space: nowrap;
}
.date-item {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  padding: 16rpx 32rpx;
  margin-right: 16rpx;
  border-radius: 16rpx;
  background: #ffffff;
}
.date-item.active {
  background: #7b7de5;
}
.date-text {
  font-size: 26rpx;
  color: #333333;
  font-weight: 600;
}
.week-text {
  margin-top: 4rpx;
  font-size: 22rpx;
  color: #999999;
}
.date-item.active .date-text,
.date-item.active .week-text {
  color: #ffffff;
}
.match-card {
  padding: 28rpx 24rpx;
}
.match-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.stage {
  font-size: 24rpx;
  color: #7b7de5;
  font-weight: 600;
}
.match-time {
  font-size: 24rpx;
  color: #999999;
}
.match-status {
  font-size: 24rpx;
  color: #ff8a3d;
}
.match-body {
  display: flex;
  align-items: center;
  margin-top: 28rpx;
}
.team {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 0;
}
.team-logo {
  width: 88rpx;
  height: 88rpx;
}
.team-name {
  margin-top: 12rpx;
  font-size: 26rpx;
  color: #333333;
  text-align: center;
  max-width: 200rpx;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.vs {
  width: 180rpx;
  text-align: center;
  flex-shrink: 0;
}
.score {
  font-size: 40rpx;
  font-weight: 700;
  color: #333333;
}
.vs-text {
  font-size: 32rpx;
  font-weight: 700;
  color: #bbbbbb;
}
.match-desc {
  margin-top: 20rpx;
  text-align: center;
  font-size: 24rpx;
  color: #999999;
}
.title.sm {
  display: block;
  font-size: 30rpx;
  color: #333333;
  font-weight: 700;
  margin-bottom: 16rpx;
}
</style>
