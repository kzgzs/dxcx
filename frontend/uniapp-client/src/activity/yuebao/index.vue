<script setup lang="ts">
import { onLoad, onReachBottom, onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import {
  applyYuebaoWithdraw,
  fetchYuebaoInfo,
  fetchYuebaoLog,
  yuebaoTurnMoney,
} from "../../api/yuebao";
import type { YuebaoInfo, YuebaoMoneyLogItem } from "../../api/yuebao";
import { fmtTime, price2 } from "../../utils/format";

/** 流水类型文案：1收益增加 2提现减少 3转余额减少 4退款返回 */
const TYPE_TEXT: Record<number, string> = {
  1: "收益增加",
  2: "提现减少",
  3: "转余额减少",
  4: "退款返回",
};

const info = ref<YuebaoInfo | null>(null);
const list = ref<YuebaoMoneyLogItem[]>([]);
const page = ref(1);
const total = ref(0);
const pagesize = 15;
const loading = ref(false);
const finished = ref(false);

async function loadInfo() {
  try {
    info.value = await fetchYuebaoInfo();
  } catch {
    info.value = null;
  }
}

async function load(next = false) {
  if (loading.value || (next && finished.value)) return;
  loading.value = true;
  const target = next ? page.value + 1 : 1;
  try {
    const res = await fetchYuebaoLog(target, pagesize);
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

onShow(() => {
  loadInfo();
});

onReachBottom(() => {
  load(true);
});

function gotoWithdrawLog() {
  uni.navigateTo({ url: "/activity/yuebao/log" });
}

// ============ 转余额弹窗 ============
const turnVisible = ref(false);
const turnAmount = ref("");
const turnSubmitting = ref(false);

function openTurn() {
  if (info.value && Number(info.value.config.yuebao_turn_yue) !== 1) {
    uni.showToast({ title: "收益转余额功能未开启", icon: "none" });
    return;
  }
  turnAmount.value = "";
  turnVisible.value = true;
}

async function submitTurn() {
  const money = Number(turnAmount.value);
  if (!money || money <= 0) {
    uni.showToast({ title: "请输入转入金额", icon: "none" });
    return;
  }
  turnSubmitting.value = true;
  try {
    await yuebaoTurnMoney(money);
    uni.showToast({ title: "转入成功", icon: "success" });
    turnVisible.value = false;
    loadInfo();
    load();
  } catch {
    // 错误提示由 request 拦截器统一弹出
  } finally {
    turnSubmitting.value = false;
  }
}

// ============ 提现弹窗 ============
const withdrawVisible = ref(false);
const withdrawAmount = ref("");
const paytypes = ["微信钱包", "支付宝", "银行卡"];
const paytypeIndex = ref(0);
const withdrawSubmitting = ref(false);

function openWithdraw() {
  withdrawAmount.value = "";
  paytypeIndex.value = 0;
  withdrawVisible.value = true;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function onPaytypeChange(e: any) {
  paytypeIndex.value = Number(e.detail.value);
}

async function submitWithdraw() {
  const money = Number(withdrawAmount.value);
  if (!money || money <= 0) {
    uni.showToast({ title: "请输入提现金额", icon: "none" });
    return;
  }
  withdrawSubmitting.value = true;
  try {
    await applyYuebaoWithdraw(money, paytypes[paytypeIndex.value]);
    uni.showToast({ title: "提交成功，请等待审核打款", icon: "none" });
    withdrawVisible.value = false;
    loadInfo();
    load();
  } catch {
    // 错误提示由 request 拦截器统一弹出
  } finally {
    withdrawSubmitting.value = false;
  }
}
</script>

<template>
  <view class="page">
    <!-- 头部：余额与收益 -->
    <view class="head">
      <text class="head-label">余额宝金额（元）</text>
      <text class="head-num">{{ info ? price2(info.yuebao_money) : "--" }}</text>
      <view class="head-sub">
        <view class="head-sub-item">
          <text class="head-sub-label">今日收益</text>
          <text class="head-sub-value">{{ info ? price2(info.today_income) : "--" }}</text>
        </view>
        <view class="head-sub-item">
          <text class="head-sub-label">累计收益</text>
          <text class="head-sub-value">{{ info ? price2(info.total_income) : "--" }}</text>
        </view>
        <view class="head-sub-item">
          <text class="head-sub-label">日收益率</text>
          <text class="head-sub-value">{{ info ? info.rate : "--" }}%</text>
        </view>
      </view>
    </view>

    <!-- 操作按钮 -->
    <view class="actions">
      <view class="action-btn" @click="openTurn">转入余额</view>
      <view class="action-btn primary" @click="openWithdraw">提现</view>
      <view class="action-btn" @click="gotoWithdrawLog">提现记录</view>
    </view>

    <view v-if="info && info.config.yuebao_withdrawmin > 0" class="tips">
      <text>最低提现金额 ￥{{ price2(info.config.yuebao_withdrawmin) }}</text>
      <text v-if="info.config.yuebao_withdrawfee > 0">，手续费 {{ info.config.yuebao_withdrawfee }}%</text>
    </view>

    <!-- 收益明细 -->
    <view class="section-title">
      <text>收益明细</text>
    </view>

    <view v-if="!loading && list.length === 0" class="empty">
      <text>暂无收益记录</text>
    </view>

    <view class="log-list">
      <view v-for="item in list" :key="item.id" class="log-item">
        <view class="log-meta">
          <text class="log-title">{{ TYPE_TEXT[item.type] || "余额宝变动" }}</text>
          <text class="log-remark">{{ item.remark }}</text>
          <text class="log-time">{{ fmtTime(item.createtime) }}</text>
        </view>
        <text class="log-money" :class="item.money >= 0 ? 'plus' : 'minus'">
          {{ item.money >= 0 ? "+" : "" }}{{ price2(item.money) }}
        </text>
      </view>
    </view>

    <view v-if="list.length > 0" class="footer">
      <text v-if="finished">已加载全部 {{ total }} 条</text>
      <text v-else-if="loading">加载中...</text>
      <text v-else>上拉加载更多</text>
    </view>

    <!-- 转余额弹窗 -->
    <view v-if="turnVisible" class="mask" @click="turnVisible = false">
      <view class="dialog" @click.stop>
        <text class="dialog-title">余额宝转入余额</text>
        <text class="dialog-sub">可转入金额：￥{{ info ? price2(info.yuebao_money) : "0.00" }}</text>
        <input
          v-model="turnAmount"
          type="digit"
          class="dialog-input"
          placeholder="请输入转入金额"
        />
        <view class="dialog-btns">
          <view class="dialog-btn cancel" @click="turnVisible = false">取消</view>
          <view class="dialog-btn ok" :class="{ disabled: turnSubmitting }" @click="submitTurn">
            {{ turnSubmitting ? "提交中..." : "确认转入" }}
          </view>
        </view>
      </view>
    </view>

    <!-- 提现弹窗 -->
    <view v-if="withdrawVisible" class="mask" @click="withdrawVisible = false">
      <view class="dialog" @click.stop>
        <text class="dialog-title">余额宝提现</text>
        <text class="dialog-sub">可提现金额：￥{{ info ? price2(info.yuebao_money) : "0.00" }}</text>
        <input
          v-model="withdrawAmount"
          type="digit"
          class="dialog-input"
          placeholder="请输入提现金额"
        />
        <picker :range="paytypes" :value="paytypeIndex" @change="onPaytypeChange">
          <view class="dialog-input picker">
            <text>提现方式：{{ paytypes[paytypeIndex] }}</text>
          </view>
        </picker>
        <view v-if="info && info.config.yuebao_withdrawfee > 0" class="dialog-sub fee">
          手续费 {{ info.config.yuebao_withdrawfee }}%，实付金额以到账为准
        </view>
        <view class="dialog-btns">
          <view class="dialog-btn cancel" @click="withdrawVisible = false">取消</view>
          <view class="dialog-btn ok" :class="{ disabled: withdrawSubmitting }" @click="submitWithdraw">
            {{ withdrawSubmitting ? "提交中..." : "提交申请" }}
          </view>
        </view>
      </view>
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
  padding: 48rpx 0 36rpx;
  background: linear-gradient(135deg, #fd9a4a, #fdc746);
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
.head-sub {
  display: flex;
  margin-top: 28rpx;
  gap: 56rpx;
}
.head-sub-item {
  display: flex;
  flex-direction: column;
  align-items: center;
}
.head-sub-label {
  color: rgba(255, 255, 255, 0.8);
  font-size: 24rpx;
}
.head-sub-value {
  margin-top: 6rpx;
  color: #ffffff;
  font-size: 30rpx;
  font-weight: 600;
}
.actions {
  display: flex;
  margin: -36rpx 24rpx 0;
  background: #ffffff;
  border-radius: 16rpx;
  box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.06);
}
.action-btn {
  flex: 1;
  text-align: center;
  padding: 28rpx 0;
  font-size: 28rpx;
  color: #333333;
}
.action-btn.primary {
  color: #fd7d2f;
  font-weight: 600;
}
.tips {
  margin: 20rpx 32rpx 0;
  font-size: 24rpx;
  color: #999999;
}
.section-title {
  margin: 36rpx 32rpx 0;
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.empty {
  padding: 100rpx 0;
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
.log-money {
  font-size: 32rpx;
  font-weight: 600;
}
.log-money.plus {
  color: #fd7d2f;
}
.log-money.minus {
  color: #e64340;
}
.footer {
  padding: 24rpx 0;
  text-align: center;
  font-size: 24rpx;
  color: #b9b9c9;
}

/* 弹窗 */
.mask {
  position: fixed;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 99;
}
.dialog {
  width: 600rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 40rpx 32rpx;
  display: flex;
  flex-direction: column;
}
.dialog-title {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
  text-align: center;
}
.dialog-sub {
  margin-top: 16rpx;
  font-size: 26rpx;
  color: #999999;
  text-align: center;
}
.dialog-sub.fee {
  margin-top: 12rpx;
}
.dialog-input {
  margin-top: 24rpx;
  border: 1rpx solid #eeeeee;
  border-radius: 12rpx;
  padding: 20rpx 24rpx;
  font-size: 28rpx;
  color: #333333;
}
.dialog-input.picker {
  margin-top: 16rpx;
}
.dialog-btns {
  display: flex;
  gap: 24rpx;
  margin-top: 36rpx;
}
.dialog-btn {
  flex: 1;
  text-align: center;
  padding: 22rpx 0;
  border-radius: 40rpx;
  font-size: 28rpx;
}
.dialog-btn.cancel {
  background: #f2f2f2;
  color: #666666;
}
.dialog-btn.ok {
  background: linear-gradient(135deg, #fd9a4a, #fdc746);
  color: #ffffff;
  font-weight: 600;
}
.dialog-btn.disabled {
  opacity: 0.6;
}
</style>
