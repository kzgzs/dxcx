<script setup lang="ts">
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { fetchProfile, fetchRechargeConfig, rechargeCreate } from "../../api/mall";
import type { RechargeConfig } from "../../api/types";
import { price2 } from "../../utils/format";
import { navigateTo, PAGES } from "../../utils/navigate";

const config = ref<RechargeConfig | null>(null);
const balance = ref<number | null>(null);
/** 选中档位金额；null 表示使用自定义输入 */
const selectedMoney = ref<number | null>(null);
const customMoney = ref("");
const submitting = ref(false);

onShow(async () => {
  await Promise.all([loadConfig(), loadBalance()]);
});

async function loadConfig() {
  try {
    config.value = await fetchRechargeConfig();
  } catch {
    config.value = null;
  }
}

async function loadBalance() {
  try {
    const p = await fetchProfile();
    balance.value = p.money;
  } catch {
    balance.value = null;
  }
}

/** 最终充值金额：选中档位优先，否则取自定义输入 */
function finalMoney(): number {
  if (selectedMoney.value !== null) return selectedMoney.value;
  const n = Number(customMoney.value);
  return isNaN(n) ? 0 : Math.round(n * 100) / 100;
}

function selectTier(money: number) {
  if (selectedMoney.value === money) {
    selectedMoney.value = null;
    return;
  }
  selectedMoney.value = money;
  customMoney.value = "";
}

function onCustomInput() {
  // 手动输入时清除档位选中
  selectedMoney.value = null;
}

async function submit() {
  if (submitting.value) return;
  if (!config.value || config.value.status !== 1) {
    uni.showToast({ title: "充值功能暂未开启", icon: "none" });
    return;
  }
  const money = finalMoney();
  if (money <= 0) {
    uni.showToast({ title: "请选择或输入充值金额", icon: "none" });
    return;
  }
  if (config.value.caninput !== 1 && selectedMoney.value === null) {
    uni.showToast({ title: "请选择充值档位金额", icon: "none" });
    return;
  }
  submitting.value = true;
  try {
    const res = await rechargeCreate(money);
    navigateTo(
      PAGES.pay +
        "?order_id=" + res.id +
        "&amount=" + res.money +
        "&scene=recharge"
    );
  } catch {
    // request 已弹 toast
  } finally {
    submitting.value = false;
  }
}

function goOrders() {
  navigateTo(PAGES.rechargeOrders);
}
</script>

<template>
  <view class="page">
    <!-- 余额卡 -->
    <view class="balance-card">
      <view class="balance-info">
        <text class="balance-label">账户余额（元）</text>
        <text class="balance-value">{{ balance === null ? "--" : price2(balance) }}</text>
      </view>
      <view class="orders-entry" @click="goOrders">
        <text>充值记录</text>
        <text class="arrow">></text>
      </view>
    </view>

    <!-- 未启用 -->
    <view v-if="config && config.status !== 1" class="disabled-tip">
      <text>充值功能暂未开启，敬请期待</text>
    </view>

    <template v-else-if="config && config.status === 1">
      <!-- 档位 -->
      <view class="section-title"><text>充值档位</text></view>
      <view v-if="config.givedata.length > 0" class="tier-grid">
        <view
          v-for="t in config.givedata"
          :key="t.money"
          class="tier-card"
          :class="{ active: selectedMoney === t.money }"
          @click="selectTier(t.money)"
        >
          <text class="tier-money">￥{{ price2(t.money) }}</text>
          <text v-if="t.give > 0 || t.give_score > 0" class="tier-give">
            送{{ t.give > 0 ? "￥" + price2(t.give) : "" }}{{ t.give > 0 && t.give_score > 0 ? " + " : "" }}{{ t.give_score > 0 ? t.give_score + "积分" : "" }}
          </text>
        </view>
      </view>
      <view v-else class="no-tier"><text>暂无充值档位</text></view>

      <!-- 自定义金额 -->
      <view v-if="config.caninput === 1" class="custom-wrap">
        <text class="custom-label">自定义金额</text>
        <view class="custom-input-wrap">
          <text class="yuan">￥</text>
          <input
            v-model="customMoney"
            class="custom-input"
            type="digit"
            placeholder="输入充值金额"
            placeholder-class="ph"
            @input="onCustomInput"
          />
        </view>
      </view>

      <!-- 说明 -->
      <view v-if="config.shuoming" class="shuoming">
        <text>{{ config.shuoming }}</text>
      </view>

      <view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
        <text>{{ submitting ? "提交中..." : finalMoney() > 0 ? "充值 ￥" + price2(finalMoney()) : "立即充值" }}</text>
      </view>
      <view class="note"><text>充值成功后余额即时到账；满足档位金额按最高档发放赠送</text></view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx;
  box-sizing: border-box;
}
.balance-card {
  background: linear-gradient(135deg, #7b7de5, #9a8cf0);
  border-radius: 16rpx;
  padding: 40rpx 32rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.balance-info {
  display: flex;
  flex-direction: column;
}
.balance-label {
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.85);
}
.balance-value {
  margin-top: 12rpx;
  font-size: 56rpx;
  font-weight: 700;
  color: #ffffff;
}
.orders-entry {
  display: flex;
  align-items: center;
  font-size: 24rpx;
  color: #ffffff;
  background: rgba(255, 255, 255, 0.18);
  border-radius: 28rpx;
  padding: 10rpx 24rpx;
}
.arrow {
  margin-left: 8rpx;
}
.disabled-tip {
  margin-top: 60rpx;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.section-title {
  margin: 32rpx 0 20rpx;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.tier-grid {
  display: flex;
  flex-wrap: wrap;
}
.tier-card {
  width: calc(33.333% - 16rpx);
  margin: 0 24rpx 20rpx 0;
  background: #ffffff;
  border: 2rpx solid #eeeeee;
  border-radius: 12rpx;
  padding: 28rpx 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  box-sizing: border-box;
}
.tier-card:nth-child(3n) {
  margin-right: 0;
}
.tier-card.active {
  border-color: #7b7de5;
  background: #f4f4fd;
}
.tier-money {
  font-size: 32rpx;
  font-weight: 700;
  color: #333333;
}
.tier-card.active .tier-money {
  color: #7b7de5;
}
.tier-give {
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #e6a23c;
  text-align: center;
  padding: 0 8rpx;
}
.no-tier {
  background: #ffffff;
  border-radius: 12rpx;
  padding: 40rpx;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.custom-wrap {
  background: #ffffff;
  border-radius: 12rpx;
  padding: 28rpx 24rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.custom-label {
  font-size: 28rpx;
  color: #333333;
}
.custom-input-wrap {
  flex: 1;
  margin-left: 32rpx;
  display: flex;
  align-items: center;
  justify-content: flex-end;
}
.yuan {
  font-size: 28rpx;
  color: #333333;
  margin-right: 8rpx;
}
.custom-input {
  width: 240rpx;
  text-align: right;
  font-size: 28rpx;
  color: #333333;
}
.ph {
  color: #b5b5b5;
}
.shuoming {
  margin-top: 24rpx;
  padding: 20rpx 24rpx;
  background: #fff8ec;
  border-radius: 12rpx;
  font-size: 24rpx;
  color: #b0885a;
  line-height: 1.6;
}
.submit-btn {
  margin-top: 48rpx;
  padding: 24rpx 0;
  background: #7b7de5;
  color: #ffffff;
  font-size: 32rpx;
  text-align: center;
  border-radius: 48rpx;
}
.submit-btn.disabled {
  opacity: 0.6;
}
.note {
  margin-top: 24rpx;
  text-align: center;
  color: #b5b5b5;
  font-size: 22rpx;
}
</style>
