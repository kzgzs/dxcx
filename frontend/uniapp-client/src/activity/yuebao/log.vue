<script setup lang="ts">
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchYuebaoWithdrawLog } from "../../api/yuebao";
import type { YuebaoWithdrawLogItem } from "../../api/yuebao";
import { fmtTime, price2 } from "../../utils/format";

const list = ref<YuebaoWithdrawLogItem[]>([]);
const page = ref(1);
const total = ref(0);
const pagesize = 15;
const loading = ref(false);
const finished = ref(false);

async function load(next = false) {
  if (loading.value || (next && finished.value)) return;
  loading.value = true;
  const target = next ? page.value + 1 : 1;
  try {
    const res = await fetchYuebaoWithdrawLog(target, pagesize);
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
  load();
});

onReachBottom(() => {
  load(true);
});

/** 状态样式：0审核中 1已审核 2已驳回 3已打款 */
function statusClass(status: number): string {
  if (status === 3) return "success";
  if (status === 2) return "danger";
  if (status === 1) return "primary";
  return "warning";
}
</script>

<template>
  <view class="page">
    <view v-if="!loading && list.length === 0" class="empty">
      <text>暂无提现记录</text>
    </view>

    <view class="log-list">
      <view v-for="item in list" :key="item.id" class="log-item">
        <view class="log-meta">
          <view class="log-line">
            <text class="log-title">{{ item.paytype || "提现" }}</text>
            <text class="log-status" :class="statusClass(item.status)">{{ item.status_text }}</text>
          </view>
          <text v-if="item.status === 2 && item.reason" class="log-remark">驳回原因：{{ item.reason }}</text>
          <text class="log-time">{{ fmtTime(item.createtime) }}</text>
        </view>
        <text class="log-money">￥{{ price2(item.txmoney) }}</text>
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
  padding: 24rpx 0 40rpx;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  font-size: 28rpx;
  color: #999999;
}
.log-list {
  margin: 0 24rpx;
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
.log-line {
  display: flex;
  align-items: center;
  gap: 16rpx;
}
.log-title {
  font-size: 28rpx;
  color: #333333;
}
.log-status {
  font-size: 22rpx;
  padding: 2rpx 14rpx;
  border-radius: 20rpx;
}
.log-status.warning {
  color: #e6a23c;
  background: #fdf6ec;
}
.log-status.primary {
  color: #409eff;
  background: #ecf5ff;
}
.log-status.danger {
  color: #f56c6c;
  background: #fef0f0;
}
.log-status.success {
  color: #67c23a;
  background: #f0f9eb;
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
.log-money {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.footer {
  padding: 24rpx 0;
  text-align: center;
  font-size: 24rpx;
  color: #b9b9c9;
}
</style>
