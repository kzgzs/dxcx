<script setup lang="ts">
/**
 * 领红包页
 * - hongbao/detail 展示红包信息（金额区间/祝福语/剩余情况）
 * - hongbao/receive 领取，st 状态机：1成功 0未开始 2已结束 3已抢完 4已抢过 5需抽奖码 10发放失败
 * - needcode=1 时需先输入抽奖码（cjcode）
 */
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchHongbaoDetail, hongbaoReceive } from "../api";
import type { HongbaoDetail } from "../api";
import { imgUrl, price2 } from "../../utils/format";

const id = ref(0);
const detail = ref<HongbaoDetail | null>(null);
const loadError = ref(false);
const receiving = ref(false);
/** 抽奖码输入（needcode=1 时展示） */
const cjcode = ref("");
/** 领取结果 */
const done = ref(false);
const doneSt = ref(0);
const doneMoney = ref(0);
const doneMsg = ref("");

onLoad((options) => {
  id.value = Number(options?.id || 0);
  void loadDetail();
});

async function loadDetail() {
  loadError.value = false;
  try {
    detail.value = await fetchHongbaoDetail(id.value);
  } catch {
    detail.value = null;
    loadError.value = true;
  }
}

async function doReceive() {
  if (receiving.value || !detail.value) return;
  if (detail.value.needcode === 1 && cjcode.value.trim() === "") {
    uni.showToast({ title: "请输入抽奖码", icon: "none" });
    return;
  }
  receiving.value = true;
  try {
    const res = await hongbaoReceive(id.value, cjcode.value.trim());
    done.value = true;
    doneSt.value = res.st;
    doneMoney.value = res.money;
    doneMsg.value = res.msg || stText(res.st);
    if (res.st === 1) {
      void loadDetail();
    }
  } catch (e) {
    const err = e as { msg?: string };
    uni.showToast({ title: err.msg || "领取失败", icon: "none" });
  } finally {
    receiving.value = false;
  }
}

function stText(st: number): string {
  switch (st) {
    case 1:
      return "领取成功";
    case 0:
      return "活动未开始";
    case 2:
      return "活动已结束";
    case 3:
      return "红包已被抢完";
    case 4:
      return "您已领取过该红包";
    case 5:
      return "请输入抽奖码";
    case 10:
      return "红包发放失败，请稍后再试";
    default:
      return "领取失败";
  }
}

function again() {
  done.value = false;
  doneMsg.value = "";
}

/** 剩余可领金额（估算展示） */
function remainMoney(): string {
  if (!detail.value) return "0.00";
  const remain = detail.value.totalmoney - detail.value.givenmoney;
  return price2(remain > 0 ? remain : 0);
}
</script>

<template>
  <view class="page">
    <view v-if="loadError" class="card err-card">
      <text class="err-text">红包不存在或已下线</text>
    </view>

    <template v-else-if="detail">
      <!-- 红包封面 -->
      <view class="hb-card">
        <image v-if="detail.sharepic !== ''" class="hb-bg" :src="imgUrl(detail.sharepic)" mode="aspectFill" />
        <view class="hb-body">
          <text class="hb-name">{{ detail.name }}</text>
          <text class="hb-wish">{{ detail.wishing }}</text>
          <view class="hb-money-row">
            <text class="hb-label">单个红包</text>
            <text class="hb-money">￥{{ price2(detail.minmoney) }} ~ ￥{{ price2(detail.maxmoney) }}</text>
          </view>
          <text class="hb-send">{{ detail.sendname }} 发放</text>
        </view>
      </view>

      <!-- 领取区 -->
      <view v-if="!done" class="card">
        <view v-if="detail.needcode === 1" class="code-block">
          <text class="code-label">该红包需抽奖码领取</text>
          <input v-model="cjcode" class="input" placeholder="请输入抽奖码" confirm-type="done" @confirm="doReceive" />
        </view>
        <view class="receive-btn" :class="{ disabled: receiving }" @click="doReceive">
          <text>{{ receiving ? "领取中..." : "立即领取" }}</text>
        </view>
        <view class="stat-row">
          <text class="stat">已领 {{ detail.pernum }} 个 · 剩余金额约 ￥{{ remainMoney() }}</text>
        </view>
      </view>

      <!-- 领取结果 -->
      <view v-else class="card result-card">
        <view v-if="doneSt === 1" class="result-ok">
          <text class="ok-title">恭喜发财，大吉大利</text>
          <view class="ok-money-row">
            <text class="ok-symbol">￥</text>
            <text class="ok-money">{{ price2(doneMoney) }}</text>
          </view>
          <text class="ok-tip">红包金额已发放（具体以到账为准）</text>
        </view>
        <view v-else class="result-fail">
          <text class="fail-text">{{ doneMsg }}</text>
        </view>
        <view class="again-btn" @click="again"><text>返回</text></view>
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
  padding: 40rpx 32rpx;
}
.err-card {
  text-align: center;
  padding: 80rpx 32rpx;
}
.err-text {
  font-size: 28rpx;
  color: #999999;
}
.hb-card {
  position: relative;
  margin: 24rpx;
  border-radius: 24rpx;
  overflow: hidden;
  background: linear-gradient(160deg, #ff6b52, #e64340);
}
.hb-bg {
  position: absolute;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  opacity: 0.25;
}
.hb-body {
  position: relative;
  padding: 48rpx 32rpx;
  text-align: center;
}
.hb-name {
  display: block;
  font-size: 36rpx;
  font-weight: 700;
  color: #ffffff;
}
.hb-wish {
  display: block;
  margin-top: 16rpx;
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.85);
}
.hb-money-row {
  margin-top: 32rpx;
}
.hb-label {
  display: block;
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.7);
}
.hb-money {
  display: block;
  margin-top: 8rpx;
  font-size: 40rpx;
  font-weight: 700;
  color: #ffe8a3;
}
.hb-send {
  display: block;
  margin-top: 24rpx;
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.7);
}
.code-block {
  margin-bottom: 24rpx;
}
.code-label {
  display: block;
  font-size: 26rpx;
  color: #666666;
  margin-bottom: 16rpx;
}
.input {
  height: 88rpx;
  background: #f5f5f7;
  border-radius: 44rpx;
  padding: 0 32rpx;
  font-size: 28rpx;
  color: #333333;
}
.receive-btn {
  height: 92rpx;
  border-radius: 46rpx;
  background: linear-gradient(90deg, #ff8a5c, #e64340);
  color: #ffffff;
  font-size: 32rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.receive-btn.disabled {
  opacity: 0.6;
}
.stat-row {
  margin-top: 20rpx;
  text-align: center;
}
.stat {
  font-size: 24rpx;
  color: #999999;
}
.result-card {
  text-align: center;
}
.result-ok {
  padding: 20rpx 0;
}
.ok-title {
  display: block;
  font-size: 30rpx;
  color: #666666;
}
.ok-money-row {
  display: flex;
  align-items: baseline;
  justify-content: center;
  margin-top: 24rpx;
}
.ok-symbol {
  font-size: 36rpx;
  color: #e64340;
  font-weight: 700;
}
.ok-money {
  font-size: 72rpx;
  color: #e64340;
  font-weight: 700;
}
.ok-tip {
  display: block;
  margin-top: 16rpx;
  font-size: 24rpx;
  color: #999999;
}
.result-fail {
  padding: 20rpx 0;
}
.fail-text {
  font-size: 30rpx;
  color: #666666;
}
.again-btn {
  margin: 36rpx auto 0;
  width: 240rpx;
  height: 76rpx;
  border-radius: 38rpx;
  border: 1rpx solid #e64340;
  color: #e64340;
  font-size: 28rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
