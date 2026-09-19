<script setup lang="ts">
/**
 * 猜谜语页
 * - kouling/riddle 拉取谜面（后端不下发谜底）
 * - 输入答案 → kouling/riddleSubmit 校验，正确则发放奖励（msg 为后端文案）
 */
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchRiddleDetail, riddleSubmit } from "../api";
import type { RiddleDetail } from "../api";
import { fmtTime } from "../../utils/format";

const id = ref(0);
const detail = ref<RiddleDetail | null>(null);
const answer = ref("");
const submitting = ref(false);
const resultText = ref("");
const resultOk = ref(false);
const loadError = ref(false);

onLoad((options) => {
  id.value = Number(options?.id || 0);
  void loadDetail();
});

async function loadDetail() {
  loadError.value = false;
  try {
    detail.value = await fetchRiddleDetail(id.value);
  } catch {
    detail.value = null;
    loadError.value = true;
  }
}

async function doSubmit() {
  if (submitting.value) return;
  const input = answer.value.trim();
  if (input === "") {
    uni.showToast({ title: "请输入谜底", icon: "none" });
    return;
  }
  submitting.value = true;
  resultText.value = "";
  try {
    const res = await riddleSubmit(id.value, input);
    resultOk.value = true;
    resultText.value = res.msg || "回答正确，恭喜获得奖励";
    answer.value = "";
  } catch (e) {
    resultOk.value = false;
    const err = e as { msg?: string };
    resultText.value = err.msg || "回答错误，再想想";
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <view class="page">
    <view v-if="loadError" class="card err-card">
      <text class="err-text">谜语不存在或已下线</text>
    </view>

    <template v-else-if="detail">
      <!-- 谜面 -->
      <view class="card">
        <view class="head-row">
          <text class="rid-no">谜语 No.{{ detail.id }}</text>
          <view v-if="detail.status === 1" class="rid-tag on"><text>进行中</text></view>
          <view v-else class="rid-tag off"><text>已结束</text></view>
        </view>
        <text class="rid-title">{{ detail.title }}</text>
        <text v-if="detail.alias !== ''" class="rid-alias">类别：{{ detail.alias }}</text>
        <view class="rid-content">
          <rich-text :nodes="detail.content" />
        </view>
        <view class="meta-row">
          <text class="meta">活动时间：{{ fmtTime(detail.starttime) }} ~ {{ fmtTime(detail.endtime) }}</text>
        </view>
        <view v-if="typeof detail.num === 'number'" class="meta-row">
          <text class="meta">参与 {{ detail.num }} 人 · 答对 {{ detail.zjnum || 0 }} 人</text>
        </view>
      </view>

      <!-- 作答 -->
      <view class="card">
        <text class="title sm">写下你的谜底</text>
        <input v-model="answer" class="input" placeholder="请输入谜底" confirm-type="done" @confirm="doSubmit" />

        <view v-if="resultText !== ''" class="result" :class="{ ok: resultOk, fail: !resultOk }">
          <text>{{ resultText }}</text>
        </view>

        <view class="main-btn" :class="{ disabled: submitting || detail.status !== 1 }" @click="doSubmit">
          <text>{{ detail.status === 1 ? "提交答案" : "活动已结束" }}</text>
        </view>
      </view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 60rpx;
}
.card {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 20rpx;
  padding: 36rpx 32rpx;
}
.err-card {
  text-align: center;
  padding: 80rpx 32rpx;
}
.err-text {
  font-size: 28rpx;
  color: #999999;
}
.head-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.rid-no {
  font-size: 24rpx;
  color: #7b7de5;
}
.rid-tag {
  padding: 6rpx 20rpx;
  border-radius: 24rpx;
  font-size: 22rpx;
}
.rid-tag.on {
  background: #f0faf0;
  color: #52c41a;
}
.rid-tag.off {
  background: #f5f5f7;
  color: #999999;
}
.rid-title {
  display: block;
  margin-top: 24rpx;
  font-size: 36rpx;
  font-weight: 700;
  color: #333333;
}
.rid-alias {
  display: block;
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #999999;
}
.rid-content {
  margin-top: 24rpx;
  padding: 24rpx;
  background: #f9f9ff;
  border-radius: 12rpx;
  font-size: 30rpx;
  color: #444444;
  line-height: 1.7;
}
.meta-row {
  margin-top: 16rpx;
}
.meta {
  font-size: 24rpx;
  color: #999999;
}
.title.sm {
  display: block;
  font-size: 30rpx;
  color: #333333;
  font-weight: 700;
  margin-bottom: 20rpx;
}
.input {
  height: 88rpx;
  background: #f5f5f7;
  border-radius: 44rpx;
  padding: 0 32rpx;
  font-size: 28rpx;
  color: #333333;
}
.result {
  margin-top: 24rpx;
  padding: 16rpx 24rpx;
  border-radius: 12rpx;
  font-size: 26rpx;
  text-align: center;
}
.result.ok {
  background: #f0faf0;
  color: #52c41a;
}
.result.fail {
  background: #fff2f0;
  color: #ff4d4f;
}
.main-btn {
  margin-top: 36rpx;
  height: 88rpx;
  border-radius: 44rpx;
  background: linear-gradient(90deg, #9b9df0, #7b7de5);
  color: #ffffff;
  font-size: 30rpx;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
}
.main-btn.disabled {
  opacity: 0.6;
}
</style>
