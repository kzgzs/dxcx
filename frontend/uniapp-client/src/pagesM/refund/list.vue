<script setup lang="ts">
import { ref } from "vue";
import { onShow, onReachBottom } from "@dcloudio/uni-app";
import { fetchRefundList } from "../../api/mall";
import type { RefundItem } from "../../api/types";
import { price2 } from "../../utils/format";

const list = ref<RefundItem[]>([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const loaded = ref(false);

onShow(() => {
  load();
});

onReachBottom(() => {
  if (list.value.length < total.value) {
    page.value += 1;
    load(false);
  }
});

async function load(reset = true) {
  if (loading.value) return;
  loading.value = true;
  if (reset) {
    page.value = 1;
    list.value = [];
  }
  try {
    const data = await fetchRefundList(page.value, 10);
    list.value = reset ? data.list || [] : list.value.concat(data.list || []);
    total.value = data.total || 0;
    loaded.value = true;
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

/** status 0申请中 1已退款 2已驳回 */
function statusClass(status: number): string {
  if (status === 1) return "ok";
  if (status === 2) return "reject";
  return "pending";
}
</script>

<template>
  <view class="page">
    <view v-if="loaded && list.length === 0 && !loading" class="empty">
      <text>暂无退款记录</text>
    </view>

    <view v-for="r in list" :key="r.id" class="refund-card">
      <view class="card-head">
        <text class="refund-sn">退款单号：{{ r.refund_sn }}</text>
        <text class="status" :class="statusClass(r.status)">{{ r.status_text }}</text>
      </view>

      <view class="row">
        <text class="label">订单编号</text>
        <text class="value">{{ r.ordernum || "--" }}</text>
      </view>
      <view class="row">
        <text class="label">退款金额</text>
        <text class="value amount">￥{{ price2(r.refund_amount) }}</text>
      </view>
      <view class="row">
        <text class="label">订单金额</text>
        <text class="value">￥{{ price2(r.pay_amount) }}</text>
      </view>
      <view class="row">
        <text class="label">退款原因</text>
        <text class="value">{{ r.reason || "--" }}</text>
      </view>
      <view class="row">
        <text class="label">申请时间</text>
        <text class="value">{{ r.apply_time || "--" }}</text>
      </view>
      <view v-if="r.audit_time" class="row">
        <text class="label">处理时间</text>
        <text class="value">{{ r.audit_time }}</text>
      </view>
    </view>

    <view v-if="loading" class="empty"><text>加载中...</text></view>
    <view v-else-if="list.length > 0 && list.length >= total" class="empty">
      <text>没有更多了</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx;
  box-sizing: border-box;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.refund-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 18rpx;
  border-bottom: 1rpx solid #f5f5f5;
  margin-bottom: 12rpx;
}
.refund-sn {
  font-size: 24rpx;
  color: #999999;
}
.status {
  font-size: 26rpx;
  font-weight: 600;
}
.status.pending {
  color: #e6a23c;
}
.status.ok {
  color: #67c23a;
}
.status.reject {
  color: #e64340;
}
.row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 10rpx 0;
}
.label {
  font-size: 26rpx;
  color: #999999;
  flex-shrink: 0;
}
.value {
  font-size: 26rpx;
  color: #333333;
  text-align: right;
  margin-left: 24rpx;
  word-break: break-all;
}
.value.amount {
  color: #e64340;
  font-weight: 600;
}
</style>
