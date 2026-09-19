<script setup lang="ts">
import { onLoad, onReachBottom, onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { queueRecord } from "../api/queue";
import type { QueueRecordRow } from "../api/types";
import { fmtTime } from "../../utils/format";
import StatusTag from "../components/StatusTag.vue";

const list = ref<QueueRecordRow[]>([]);
const page = ref(1);
const finished = ref(false);
const loading = ref(false);

/** 状态机：0排队中 → 1已入座 / 2过号 / 3用户取消 */
const statusMap: Record<number, { text: string; tone: "primary" | "success" | "warn" | "gray" }> = {
  0: { text: "排队中", tone: "primary" },
  1: { text: "已入座", tone: "success" },
  2: { text: "已过号", tone: "warn" },
  3: { text: "已取消", tone: "gray" },
};

function statusOf(s: number) {
  return statusMap[s] || { text: "未知", tone: "gray" as const };
}

function load(reset = false) {
  if (loading.value) return;
  if (reset) {
    page.value = 1;
    finished.value = false;
    list.value = [];
  }
  if (finished.value && !reset) return;
  loading.value = true;
  queueRecord(page.value)
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

onLoad(() => {
  load(true);
});
onShow(() => {
  // 从取号页返回时刷新
  if (list.value.length > 0) load(true);
});
onReachBottom(() => {
  load(false);
});
</script>

<template>
  <view class="page">
    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无取号记录</text></view>

    <view v-for="r in list" :key="r.id" class="rec-card">
      <view class="rec-head">
        <text class="rec-no">{{ r.queue_no }}</text>
        <StatusTag :text="statusOf(r.status).text" :tone="statusOf(r.status).tone" />
      </view>
      <view class="rec-line"><text class="rec-label">桌型分类</text><text>{{ r.cname || "-" }}</text></view>
      <view class="rec-line"><text class="rec-label">排队日期</text><text>{{ r.date }}</text></view>
      <view v-if="r.renshu" class="rec-line"><text class="rec-label">用餐人数</text><text>{{ r.renshu }} 人</text></view>
      <view class="rec-line"><text class="rec-label">取号时间</text><text>{{ fmtTime(r.create_time) }}</text></view>
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
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.rec-card {
  margin: 24rpx;
  padding: 24rpx 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.rec-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 16rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.rec-no {
  font-size: 40rpx;
  font-weight: 700;
  color: #7b7de5;
}
.rec-line {
  display: flex;
  justify-content: space-between;
  padding: 10rpx 0 0;
  font-size: 26rpx;
  color: #333333;
}
.rec-label {
  color: #999999;
}
.list-end {
  padding: 24rpx 0 48rpx;
  text-align: center;
  color: #bbbbbb;
  font-size: 24rpx;
}
</style>
