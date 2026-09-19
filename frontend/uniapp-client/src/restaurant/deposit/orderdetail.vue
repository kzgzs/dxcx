<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";
import { depositOrderLog, depositTakeout } from "../api/deposit";
import type { DepositOrderDetail } from "../api/types";
import { fmtTime, imgUrl } from "../../utils/format";
import StatusTag from "../components/StatusTag.vue";

const orderId = ref(0);
const detail = ref<DepositOrderDetail | null>(null);
const takeNum = ref("");
const taking = ref(false);

function toneOf(status: number): "primary" | "success" | "warn" | "gray" {
  if (status === 1) return "success";
  if (status === 0) return "warn";
  return "gray";
}

function load() {
  if (orderId.value <= 0) return;
  depositOrderLog(orderId.value)
    .then((res) => {
      detail.value = res.data;
    })
    .catch(() => {
      detail.value = null;
    });
}

onLoad((options) => {
  orderId.value = Number((options && options.id) || 0) || 0;
  load();
});

/** 取件：按数量部分取/全部取（登录身份归属校验，后端幂等防超取） */
function doTake() {
  if (!detail.value || taking.value) return;
  const n = Number(takeNum.value);
  if (!n || n <= 0) {
    uni.showToast({ title: "请填写取出数量", icon: "none" });
    return;
  }
  if (n > detail.value.num) {
    uni.showToast({ title: "取出数量不能超过剩余数量", icon: "none" });
    return;
  }
  uni.showModal({
    title: "提示",
    content: `确定取出 ${n} 件吗？`,
    success: (r) => {
      if (!r.confirm) return;
      taking.value = true;
      depositTakeout(orderId.value, n)
        .then((res) => {
          uni.showToast({ title: res.left === 0 ? "已全部取出" : "取出成功", icon: "success" });
          takeNum.value = "";
          load();
        })
        .catch(() => {
          /* 后端 toast 已提示 */
        })
        .finally(() => {
          taking.value = false;
        });
    },
  });
}
</script>

<template>
  <view class="page">
    <view v-if="!detail" class="empty"><text>记录加载中...</text></view>

    <template v-if="detail">
      <!-- 物品信息 -->
      <view class="card">
        <view class="status-row">
          <text class="item-name">{{ detail.name }}</text>
          <StatusTag :text="detail.status_text" :tone="toneOf(detail.status)" />
        </view>
        <view class="detail-line"><text>数量</text><text>{{ detail.num }}</text></view>
        <view class="detail-line"><text>商家</text><text>{{ detail.binfo.name || "商家" }}</text></view>
        <view class="detail-line" v-if="detail.position_name"><text>存放位置</text><text>{{ detail.position_name }}</text></view>
        <view class="detail-line" v-if="detail.number_name"><text>存单编号</text><text>{{ detail.number_name }}</text></view>
        <view class="detail-line"><text>联系人</text><text>{{ detail.linkman }} {{ detail.tel }}</text></view>
        <view class="detail-line" v-if="detail.message"><text>留言</text><text>{{ detail.message }}</text></view>
        <view class="detail-line"><text>存入时间</text><text>{{ fmtTime(detail.createtime) }}</text></view>
        <view class="detail-line" v-if="detail.takeout_time > 0"><text>取件时间</text><text>{{ fmtTime(detail.takeout_time) }}</text></view>
        <image v-if="detail.pic" class="item-pic" :src="imgUrl(detail.pic)" mode="aspectFill" />
      </view>

      <!-- 取件操作（寄存中可取） -->
      <view class="card" v-if="detail.status === 1">
        <text class="card-title">取件</text>
        <view class="take-row">
          <input
            class="take-input"
            v-model="takeNum"
            type="number"
            :placeholder="`取出数量（剩余 ${detail.num}）`"
            :maxlength="5"
          />
          <view class="take-btn" :class="{ disabled: taking }" @click="doTake">
            <text>{{ taking ? "取出中..." : "确认取出" }}</text>
          </view>
        </view>
        <text class="take-tip">取件凭登录身份核验，支持部分取出与全部取出</text>
      </view>

      <!-- 存取流水 -->
      <view class="card">
        <text class="card-title">存取记录</text>
        <view v-if="detail.log.length === 0" class="log-empty"><text>暂无流水记录</text></view>
        <view v-for="l in detail.log" :key="l.id" class="log-item">
          <view class="log-dot" :class="l.type === 0 ? 'in' : 'out'"></view>
          <view class="log-info">
            <text class="log-title">{{ l.type_text }} {{ l.num }} 件{{ l.remark ? " · " + l.remark : "" }}</text>
            <text class="log-time">{{ fmtTime(l.createtime) }}</text>
          </view>
        </view>
      </view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx 0 60rpx;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.card {
  margin: 0 24rpx 24rpx;
  padding: 28rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  flex-direction: column;
}
.card-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  margin-bottom: 20rpx;
}
.status-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16rpx;
}
.item-name {
  font-size: 34rpx;
  font-weight: 700;
  color: #333333;
}
.detail-line {
  display: flex;
  justify-content: space-between;
  font-size: 26rpx;
  color: #333333;
  line-height: 48rpx;
}
.detail-line text:first-child {
  color: #999999;
  flex-shrink: 0;
  margin-right: 24rpx;
}
.detail-line text:last-child {
  text-align: right;
}
.item-pic {
  margin-top: 20rpx;
  width: 100%;
  height: 320rpx;
  border-radius: 12rpx;
  background: #f2f2f5;
}
.take-row {
  display: flex;
  align-items: center;
}
.take-input {
  flex: 1;
  padding: 16rpx 24rpx;
  border: 1rpx solid #e5e5ea;
  border-radius: 12rpx;
  font-size: 28rpx;
  color: #333333;
}
.take-btn {
  margin-left: 20rpx;
  padding: 16rpx 44rpx;
  border-radius: 36rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 28rpx;
  flex-shrink: 0;
}
.take-btn.disabled {
  opacity: 0.6;
}
.take-tip {
  margin-top: 16rpx;
  font-size: 22rpx;
  color: #999999;
}
.log-empty {
  padding: 30rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.log-item {
  display: flex;
  align-items: flex-start;
  padding: 16rpx 0;
}
.log-dot {
  width: 16rpx;
  height: 16rpx;
  border-radius: 50%;
  margin: 14rpx 20rpx 0 0;
  flex-shrink: 0;
}
.log-dot.in {
  background: #2bb673;
}
.log-dot.out {
  background: #f0883a;
}
.log-info {
  display: flex;
  flex-direction: column;
}
.log-title {
  font-size: 26rpx;
  color: #333333;
}
.log-time {
  margin-top: 4rpx;
  font-size: 22rpx;
  color: #999999;
}
</style>
