<script setup lang="ts">
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchMyScore, fetchScoreLog } from "../../api/mall";
import type { ScoreLogItem } from "../../api/types";
import { fmtTime } from "../../utils/format";

/** type 文案映射（Score.php 注释口径：1支付赠送 2下单抵扣 3取消返还 4后台调整） */
const TYPE_TEXT: Record<number, string> = {
  1: "支付赠送",
  2: "下单抵扣",
  3: "取消返还",
  4: "后台调整",
};

const balance = ref(0);
const list = ref<ScoreLogItem[]>([]);
const page = ref(1);
const total = ref(0);
const pagesize = 15;
const loading = ref(false);
const finished = ref(false);

async function loadBalance() {
  try {
    const res = await fetchMyScore();
    balance.value = res.score || 0;
  } catch {
    balance.value = 0;
  }
}

async function load(next = false) {
  if (loading.value || (next && finished.value)) return;
  loading.value = true;
  const target = next ? page.value + 1 : 1;
  try {
    const res = await fetchScoreLog(target, pagesize);
    list.value = target === 1 ? res.list || [] : list.value.concat(res.list || []);
    total.value = res.total || 0;
    page.value = target;
    finished.value = list.value.length >= (res.total || 0);
  } catch {
    if (target === 1) list.value = [];
  } finally {
    loading.value = false;
  }
}

onLoad(() => {
  loadBalance();
  load();
});

onReachBottom(() => {
  load(true);
});

function typeText(item: ScoreLogItem) {
  return TYPE_TEXT[item.type] || "积分变动";
}
</script>

<template>
  <view class="page">
    <view class="head">
      <text class="head-label">当前积分</text>
      <text class="head-num">{{ balance }}</text>
    </view>

    <view v-if="!loading && list.length === 0" class="empty">
      <text>暂无积分记录</text>
    </view>

    <view class="log-list">
      <view v-for="item in list" :key="item.id" class="log-item">
        <view class="log-meta">
          <text class="log-title">{{ typeText(item) }}</text>
          <text class="log-remark">{{ item.remark }}</text>
          <text class="log-time">{{ fmtTime(item.create_time) }}</text>
        </view>
        <text class="log-score" :class="item.score >= 0 ? 'plus' : 'minus'">
          {{ item.score >= 0 ? "+" : "" }}{{ item.score }}
        </text>
      </view>
    </view>

    <view v-if="list.length > 0" class="footer">
      <text v-if="finished">已加载全部 {{ total }} 条</text>
      <text v-else-if="loading">加载中...</text>
      <text v-else>上拉加载更多</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.head {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 48rpx 0;
  background: linear-gradient(135deg, #7b7de5, #a3a5f0);
}
.head-label {
  color: rgba(255, 255, 255, 0.85);
  font-size: 26rpx;
}
.head-num {
  margin-top: 12rpx;
  color: #ffffff;
  font-size: 56rpx;
  font-weight: 600;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  font-size: 28rpx;
  color: #999999;
}
.log-list {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
}
.log-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 26rpx 32rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.log-item:last-child {
  border-bottom: none;
}
.log-meta {
  display: flex;
  flex-direction: column;
  flex: 1;
  margin-right: 24rpx;
}
.log-title {
  font-size: 28rpx;
  color: #333333;
}
.log-remark {
  margin-top: 6rpx;
  font-size: 24rpx;
  color: #999999;
}
.log-time {
  margin-top: 4rpx;
  font-size: 22rpx;
  color: #b9b9c9;
}
.log-score {
  font-size: 32rpx;
  font-weight: 600;
}
.log-score.plus {
  color: #7b7de5;
}
.log-score.minus {
  color: #e64340;
}
.footer {
  padding: 24rpx 0;
  text-align: center;
  font-size: 24rpx;
  color: #b9b9c9;
}
</style>
