<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { computed, ref } from "vue";
import { carOrderCreate, fetchCarDetail } from "../../api/industry";
import type { CarItem } from "../../api/types";
import { price2 } from "../../utils/format";

const car = ref<CarItem | null>(null);
const carId = ref(0);

// 取车日期 / 时间
function todayStr() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}
const startDate = ref(todayStr());
const startTime = ref("09:00");

// 租期档位（小时）
const durations = [
  { label: "半天（12小时）", hours: 12 },
  { label: "1天（24小时）", hours: 24 },
  { label: "2天", hours: 48 },
  { label: "3天", hours: 72 },
  { label: "5天", hours: 120 },
  { label: "7天", hours: 168 },
];
const durIndex = ref(0);

const submitting = ref(false);

/** 取车时间戳（秒） */
const startTs = computed(() => {
  const dt = new Date(`${startDate.value} ${startTime.value}:00`);
  return Math.floor(dt.getTime() / 1000);
});
const endTs = computed(() => startTs.value + durations[durIndex.value].hours * 3600);

/** 前端计价预览：与后端 CarhailingService 同规则 */
const preview = computed(() => {
  if (!car.value) return null;
  const hours = durations[durIndex.value].hours;
  if (hours < 4) return { error: "租车时长少于 4 小时不可下单", amount: 0, days: 0 };
  if (hours <= 24) return { error: "", amount: car.value.price_half, days: 0.5 };
  const days = Math.ceil(hours / 24);
  return { error: "", amount: days * car.value.price_day, days };
});

const endText = computed(() => {
  const d = new Date(endTs.value * 1000);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
});

onLoad((options) => {
  carId.value = Number((options && options.id) || 0);
  if (carId.value > 0) {
    fetchCarDetail(carId.value)
      .then((res) => {
        car.value = res;
      })
      .catch(() => {
        car.value = null;
      });
  }
});

function onDateChange(e: { detail: { value: string } }) {
  startDate.value = e.detail.value;
}
function onTimeChange(e: { detail: { value: string } }) {
  startTime.value = e.detail.value;
}
function pickDuration(i: number) {
  durIndex.value = i;
}

function submit() {
  if (!car.value || submitting.value) return;
  if (preview.value && preview.value.error) {
    uni.showToast({ title: preview.value.error, icon: "none" });
    return;
  }
  if (startTs.value < Math.floor(Date.now() / 1000)) {
    uni.showToast({ title: "取车时间不能早于当前", icon: "none" });
    return;
  }
  submitting.value = true;
  carOrderCreate({
    product_id: carId.value,
    start_time: startTs.value,
    end_time: endTs.value,
  })
    .then((res) => {
      uni.showToast({ title: "下单成功", icon: "success" });
      setTimeout(() => {
        uni.redirectTo({ url: "/carhailing/order/list?highlight=" + res.id });
      }, 600);
    })
    .catch((err: Error) => {
      uni.showToast({ title: err.message || "下单失败", icon: "none" });
    })
    .finally(() => {
      submitting.value = false;
    });
}
</script>

<template>
  <view class="page">
    <view v-if="car" class="car-brief">
      <text class="car-name">{{ car.name }}</text>
      <text class="car-price">日租 ￥{{ price2(car.price_day) }}/天 · 半天 ￥{{ price2(car.price_half) }}</text>
    </view>

    <view class="form-card">
      <view class="form-row">
        <text class="form-label">取车日期</text>
        <picker mode="date" :value="startDate" @change="onDateChange">
          <text class="form-value">{{ startDate }}</text>
        </picker>
      </view>
      <view class="form-row">
        <text class="form-label">取车时间</text>
        <picker mode="time" :value="startTime" @change="onTimeChange">
          <text class="form-value">{{ startTime }}</text>
        </picker>
      </view>
      <view class="form-row col">
        <text class="form-label">租期时长</text>
        <view class="dur-grid">
          <view
            v-for="(d, i) in durations"
            :key="d.hours"
            class="dur-item"
            :class="{ active: i === durIndex }"
            @click="pickDuration(i)"
          >
            <text>{{ d.label }}</text>
          </view>
        </view>
      </view>
      <view class="form-row">
        <text class="form-label">还车时间</text>
        <text class="form-value">{{ endText }}</text>
      </view>
    </view>

    <view class="rule-card">
      <text class="card-title">计价规则</text>
      <text class="rule-line">· 租车时长少于 4 小时不可下单</text>
      <text class="rule-line">· 4 小时 ~ 24 小时按半天价计费</text>
      <text class="rule-line">· 24 小时及以上按天计费（不足一天按一天）</text>
    </view>

    <view class="bottom-bar">
      <view class="preview">
        <text v-if="preview && preview.error" class="err">{{ preview.error }}</text>
        <template v-else-if="preview">
          <text class="total">￥{{ price2(preview.amount) }}</text>
          <text class="hint">{{ preview.days === 0.5 ? "按半天价" : `共 ${preview.days} 天` }}</text>
        </template>
      </view>
      <view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
        <text>{{ submitting ? "提交中..." : "提交订单" }}</text>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 160rpx;
}
.car-brief {
  margin: 24rpx;
  padding: 28rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  flex-direction: column;
}
.car-name {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.car-price {
  margin-top: 10rpx;
  font-size: 24rpx;
  color: #7b7de5;
}
.form-card,
.rule-card {
  margin: 0 24rpx 24rpx;
  padding: 8rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.form-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28rpx 0;
  border-bottom: 1rpx solid #f2f2f2;
}
.form-row:last-child {
  border-bottom: none;
}
.form-row.col {
  flex-direction: column;
  align-items: flex-start;
}
.form-label {
  font-size: 28rpx;
  color: #333333;
}
.form-value {
  font-size: 28rpx;
  color: #666666;
}
.dur-grid {
  display: flex;
  flex-wrap: wrap;
  margin-top: 20rpx;
}
.dur-item {
  width: 31%;
  margin: 0 3.5% 16rpx 0;
  padding: 16rpx 0;
  border: 1rpx solid #e5e5ea;
  border-radius: 12rpx;
  text-align: center;
  font-size: 24rpx;
  color: #666666;
}
.dur-item:nth-child(3n) {
  margin-right: 0;
}
.dur-item.active {
  border-color: #7b7de5;
  color: #7b7de5;
  background: rgba(123, 125, 229, 0.06);
}
.card-title {
  display: block;
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  padding: 24rpx 0 12rpx;
}
.rule-line {
  display: block;
  font-size: 26rpx;
  color: #666666;
  line-height: 44rpx;
}
.rule-card {
  padding: 8rpx 32rpx 24rpx;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  padding: 20rpx 32rpx calc(20rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  box-shadow: 0 -4rpx 16rpx rgba(0, 0, 0, 0.04);
}
.preview {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.total {
  color: #7b7de5;
  font-size: 40rpx;
  font-weight: 600;
}
.hint {
  font-size: 22rpx;
  color: #999999;
}
.err {
  color: #e64340;
  font-size: 24rpx;
}
.submit-btn {
  padding: 22rpx 64rpx;
  border-radius: 44rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 30rpx;
}
.submit-btn.disabled {
  opacity: 0.6;
}
</style>
