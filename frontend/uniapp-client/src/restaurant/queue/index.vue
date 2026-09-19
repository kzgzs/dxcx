<script setup lang="ts">
import { onHide, onShow, onUnload } from "@dcloudio/uni-app";
import { ref } from "vue";
import { queueCancel, queueHome, queueQuhao } from "../api/queue";
import type { JustQueueRow, MyQueueRow, QueueCategory } from "../api/types";
import StatusTag from "../components/StatusTag.vue";

const notice = ref("");
const clist = ref<QueueCategory[]>([]);
const myqueue = ref<MyQueueRow | null>(null);
const myjustqueue = ref<JustQueueRow | null>(null);
const loaded = ref(false);

/** 取号表单 */
const pickCid = ref(0);
const linkman = ref("");
const tel = ref("");
const renshu = ref("");
const submitting = ref(false);

let timer: ReturnType<typeof setInterval> | null = null;

function load(silent = false) {
  queueHome()
    .then((res) => {
      notice.value = res.notice || "";
      clist.value = res.clist || [];
      myqueue.value = res.myqueue;
      myjustqueue.value = res.myjustqueue;
      loaded.value = true;
      // 已取号且当前选中的分类失效时清空选择
      if (pickCid.value > 0 && !clist.value.some((c) => c.id === pickCid.value)) {
        pickCid.value = 0;
      }
    })
    .catch(() => {
      if (!silent) loaded.value = true;
    });
}

function startTimer() {
  stopTimer();
  timer = setInterval(() => load(true), 10000);
}
function stopTimer() {
  if (timer) {
    clearInterval(timer);
    timer = null;
  }
}

onShow(() => {
  load();
  startTimer();
});
onHide(() => {
  stopTimer();
});
onUnload(() => {
  stopTimer();
});

function pickCategory(c: QueueCategory) {
  pickCid.value = pickCid.value === c.id ? 0 : c.id;
}

function seatRange(c: QueueCategory): string {
  if (c.seat_min > 0 && c.seat_max > 0) return `适用 ${c.seat_min}-${c.seat_max} 人`;
  return "不限人数";
}

function submit() {
  if (submitting.value) return;
  if (pickCid.value <= 0) {
    uni.showToast({ title: "请先选择桌型分类", icon: "none" });
    return;
  }
  if (!linkman.value.trim()) {
    uni.showToast({ title: "请填写联系人", icon: "none" });
    return;
  }
  if (tel.value.trim() && !/^1[3-9]\d{9}$/.test(tel.value.trim())) {
    uni.showToast({ title: "手机号格式不正确", icon: "none" });
    return;
  }
  submitting.value = true;
  queueQuhao({
    cid: pickCid.value,
    linkman: linkman.value.trim(),
    tel: tel.value.trim(),
    renshu: renshu.value.trim(),
  })
    .then((res) => {
      uni.showToast({ title: "取号成功：" + res.queue_no, icon: "success" });
      linkman.value = "";
      tel.value = "";
      renshu.value = "";
      pickCid.value = 0;
      load(true);
    })
    .catch(() => {
      /* 后端 toast 已提示 */
    })
    .finally(() => {
      submitting.value = false;
    });
}

function doCancel() {
  if (!myqueue.value) return;
  const q = myqueue.value;
  uni.showModal({
    title: "提示",
    content: "确定取消排队吗？",
    success: (r) => {
      if (!r.confirm) return;
      queueCancel(q.id)
        .then(() => {
          uni.showToast({ title: "已取消", icon: "success" });
          load(true);
        })
        .catch(() => {
          /* 后端 toast 已提示 */
        });
    },
  });
}

function goRecord() {
  uni.navigateTo({ url: "/restaurant/queue/record" });
}
</script>

<template>
  <view class="page">
    <view class="head">
      <text class="head-title">排队叫号</text>
      <view class="record-link" @click="goRecord"><text>取号记录</text></view>
    </view>

    <view class="notice-bar">
      <text class="notice-icon">叫号</text>
      <text class="notice-text">{{ notice || "当前没有叫号的顾客" }}</text>
    </view>

    <!-- 刚被叫到号 -->
    <view v-if="myjustqueue" class="my-card called">
      <view class="my-line">
        <text class="my-no">{{ myjustqueue.queue_no }}</text>
        <StatusTag text="到号啦" tone="success" />
      </view>
      <text class="my-sub">{{ myjustqueue.cname }} · 请前往就餐</text>
    </view>

    <!-- 我的排队（前方等待数） -->
    <view v-if="myqueue" class="my-card">
      <view class="my-line">
        <text class="my-no">{{ myqueue.queue_no }}</text>
        <StatusTag text="排队中" tone="primary" />
      </view>
      <view class="wait-line">
        <view class="wait-item">
          <text class="wait-num">{{ myqueue.beforenum }}</text>
          <text class="wait-label">前方等待(桌)</text>
        </view>
        <view class="wait-item">
          <text class="wait-num">{{ myqueue.need_minute }}</text>
          <text class="wait-label">预计等待(分钟)</text>
        </view>
      </view>
      <text class="my-sub">{{ myqueue.cname }} · 请留意叫号播报</text>
      <view class="cancel-btn" @click="doCancel"><text>取消排队</text></view>
    </view>

    <!-- 取号：选桌型 -->
    <template v-if="!myqueue">
      <view class="section-title"><text>选择桌型取号</text></view>
      <view v-if="loaded && clist.length === 0" class="empty"><text>暂无可取号的桌型</text></view>
      <view
        v-for="c in clist"
        :key="c.id"
        class="cat-card"
        :class="{ active: pickCid === c.id }"
        @click="pickCategory(c)"
      >
        <view class="cat-left">
          <text class="cat-name">{{ c.name }}（{{ c.code }}）</text>
          <text class="cat-sub">{{ seatRange(c) }}</text>
        </view>
        <view class="cat-right">
          <text class="cat-wait">前方 {{ c.waitnum }} 桌</text>
          <text class="cat-sub">{{ c.waitnum > 0 ? `约等 ${c.need_minute} 分钟` : "无需等待" }}</text>
        </view>
      </view>

      <view class="form-card">
        <view class="form-line">
          <text class="form-label">联系人</text>
          <input class="form-input" v-model="linkman" placeholder="请填写联系人" maxlength="20" />
        </view>
        <view class="form-line">
          <text class="form-label">手机号</text>
          <input class="form-input" v-model="tel" type="number" placeholder="选填，便于到号通知" maxlength="11" />
        </view>
        <view class="form-line">
          <text class="form-label">用餐人数</text>
          <input class="form-input" v-model="renshu" type="number" placeholder="选填" maxlength="3" />
        </view>
      </view>

      <view class="submit-bar">
        <view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
          <text>{{ submitting ? "取号中..." : "立即取号" }}</text>
        </view>
      </view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 180rpx;
}
.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  padding: 24rpx 32rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.head-title {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.record-link {
  font-size: 26rpx;
  color: #7b7de5;
}
.notice-bar {
  display: flex;
  align-items: center;
  margin: 24rpx;
  padding: 20rpx 24rpx;
  background: rgba(123, 125, 229, 0.08);
  border-radius: 12rpx;
}
.notice-icon {
  margin-right: 12rpx;
  padding: 2rpx 12rpx;
  border-radius: 8rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 22rpx;
  flex-shrink: 0;
}
.notice-text {
  flex: 1;
  font-size: 26rpx;
  color: #7b7de5;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.my-card {
  margin: 0 24rpx 24rpx;
  padding: 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  flex-direction: column;
}
.my-card.called {
  border: 2rpx solid #2bb673;
}
.my-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.my-no {
  font-size: 56rpx;
  font-weight: 700;
  color: #7b7de5;
}
.wait-line {
  display: flex;
  margin: 24rpx 0 12rpx;
}
.wait-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.wait-num {
  font-size: 40rpx;
  font-weight: 600;
  color: #333333;
}
.wait-label {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
}
.my-sub {
  font-size: 24rpx;
  color: #999999;
}
.cancel-btn {
  margin-top: 24rpx;
  align-self: flex-end;
  padding: 12rpx 36rpx;
  border: 1rpx solid #dddddd;
  border-radius: 32rpx;
  font-size: 26rpx;
  color: #666666;
}
.section-title {
  padding: 8rpx 32rpx 16rpx;
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.empty {
  padding: 80rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.cat-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 0 24rpx 20rpx;
  padding: 24rpx 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
  border: 2rpx solid transparent;
}
.cat-card.active {
  border-color: #7b7de5;
  background: rgba(123, 125, 229, 0.06);
}
.cat-left {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.cat-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.cat-card.active .cat-name {
  color: #7b7de5;
}
.cat-sub {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
}
.cat-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  flex-shrink: 0;
}
.cat-wait {
  font-size: 26rpx;
  color: #f0883a;
  font-weight: 600;
}
.form-card {
  margin: 0 24rpx 24rpx;
  padding: 8rpx 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.form-line {
  display: flex;
  align-items: center;
  padding: 24rpx 0;
  border-bottom: 1rpx solid #f2f2f2;
}
.form-line:last-child {
  border-bottom: none;
}
.form-label {
  width: 160rpx;
  font-size: 26rpx;
  color: #666666;
  flex-shrink: 0;
}
.form-input {
  flex: 1;
  font-size: 28rpx;
  color: #333333;
}
.submit-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 20rpx 32rpx calc(20rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  box-shadow: 0 -4rpx 16rpx rgba(0, 0, 0, 0.04);
}
.submit-btn {
  padding: 22rpx 0;
  border-radius: 44rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 30rpx;
  text-align: center;
}
.submit-btn.disabled {
  opacity: 0.6;
}
</style>
