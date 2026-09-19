<script setup lang="ts">
/**
 * 口令游戏页
 * - 页面设置（title/bgpic/guize）来自 kouling/set
 * - 输入口令 → kouling/exchange 兑换奖励（券/积分/余额由后端发放）
 * - 提供猜谜语入口（输入谜语编号进入）
 */
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchKoulingSet, koulingExchange } from "../api";
import type { KoulingSet } from "../api";
import { imgUrl } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const set = ref<KoulingSet | null>(null);
const kouling = ref("");
const submitting = ref(false);
/** 结果提示 */
const resultText = ref("");
const resultOk = ref(false);
/** 谜语编号入口 */
const riddleId = ref("");

onLoad(() => {
  void loadSet();
});

async function loadSet() {
  try {
    set.value = await fetchKoulingSet();
  } catch {
    set.value = null;
  }
}

async function doExchange() {
  if (submitting.value) return;
  const input = kouling.value.trim();
  if (input === "") {
    uni.showToast({ title: "请输入口令", icon: "none" });
    return;
  }
  submitting.value = true;
  resultText.value = "";
  try {
    const res = await koulingExchange(input);
    resultOk.value = true;
    resultText.value = res.msg || "恭喜获得奖励";
    kouling.value = "";
  } catch (e) {
    resultOk.value = false;
    const err = e as { msg?: string };
    resultText.value = err.msg || "兑换失败";
  } finally {
    submitting.value = false;
  }
}

function goRiddle() {
  const id = Number(riddleId.value);
  if (!(id > 0)) {
    uni.showToast({ title: "请输入谜语编号", icon: "none" });
    return;
  }
  goPage("/pagesH3/kouling/riddle?id=" + id);
}
</script>

<template>
  <view class="page">
    <image v-if="set && set.bgpic !== ''" class="bgpic" :src="imgUrl(set.bgpic)" mode="aspectFill" />

    <view class="card">
      <text class="title">{{ set ? set.title : "口令游戏" }}</text>
      <text class="sub">输入正确口令即可兑换奖励</text>

      <input v-model="kouling" class="input" placeholder="请输入口令" confirm-type="done" @confirm="doExchange" />

      <view v-if="resultText !== ''" class="result" :class="{ ok: resultOk, fail: !resultOk }">
        <text>{{ resultText }}</text>
      </view>

      <view class="main-btn" :class="{ disabled: submitting }" @click="doExchange"><text>兑换奖励</text></view>
    </view>

    <!-- 猜谜语入口 -->
    <view class="card">
      <text class="title sm">猜谜语</text>
      <view class="riddle-row">
        <input
          v-model="riddleId"
          class="input flex"
          type="number"
          placeholder="输入谜语编号"
          confirm-type="done"
          @confirm="goRiddle"
        />
        <view class="sub-btn" @click="goRiddle"><text>去猜谜</text></view>
      </view>
    </view>

    <!-- 活动规则 -->
    <view v-if="set && set.guize !== ''" class="card">
      <text class="title sm">活动规则</text>
      <rich-text :nodes="set.guize" />
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 60rpx;
}
.bgpic {
  width: 100%;
  height: 320rpx;
}
.card {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 20rpx;
  padding: 40rpx 32rpx;
}
.title {
  display: block;
  font-size: 36rpx;
  color: #333333;
  font-weight: 700;
  text-align: center;
}
.title.sm {
  font-size: 30rpx;
  text-align: left;
  margin-bottom: 20rpx;
}
.sub {
  display: block;
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #999999;
  text-align: center;
}
.input {
  margin-top: 32rpx;
  height: 88rpx;
  background: #f5f5f7;
  border-radius: 44rpx;
  padding: 0 32rpx;
  font-size: 28rpx;
  color: #333333;
}
.input.flex {
  flex: 1;
  margin-top: 0;
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
  margin-top: 40rpx;
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
.riddle-row {
  display: flex;
  align-items: center;
}
.sub-btn {
  margin-left: 20rpx;
  padding: 20rpx 40rpx;
  border-radius: 40rpx;
  border: 1rpx solid #7b7de5;
  color: #7b7de5;
  font-size: 26rpx;
}
</style>
