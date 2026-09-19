<script setup lang="ts">
import { onLoad, onShow } from "@dcloudio/uni-app";
import { computed, ref } from "vue";
import { bookingAddInfo, bookingCreate, bookingPay } from "../api/booking";
import type { BookableTable, BookingSet, BookingSlot } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";

/** 桌台选择结果 storage 键（tablelist 页选桌回传） */
const PICK_KEY = "booking_pick_table";

const set = ref<BookingSet | null>(null);
const loadFailed = ref(false);

const picked = ref<BookableTable | null>(null);
const time = ref("");
const renshu = ref("");
const linkman = ref("");
const tel = ref("");
const message = ref("");
const submitting = ref(false);

/** 时段按日期分组展示 */
const slotGroups = computed(() => {
  const arr = set.value ? set.value.timeArr : [];
  const groups: { date: string; day_text: string; slots: BookingSlot[] }[] = [];
  for (const s of arr) {
    let g = groups.find((x) => x.date === s.date);
    if (!g) {
      g = { date: s.date, day_text: s.day_text, slots: [] };
      groups.push(g);
    }
    g.slots.push(s);
  }
  return groups;
});

function loadSet() {
  bookingAddInfo()
    .then((res) => {
      set.value = res;
      loadFailed.value = false;
    })
    .catch(() => {
      set.value = null;
      loadFailed.value = true;
    });
}

function loadPicked() {
  try {
    const saved = uni.getStorageSync(PICK_KEY) as BookableTable | null;
    picked.value = saved && saved.id ? saved : null;
  } catch {
    picked.value = null;
  }
}

onLoad(() => {
  loadSet();
  loadPicked();
});
onShow(() => {
  loadPicked();
});

function pickSlot(s: BookingSlot) {
  time.value = s.value;
}

function goPickTable() {
  uni.navigateTo({ url: "/restaurant/booking/tablelist?time=" + encodeURIComponent(time.value) });
}

function clearPicked() {
  picked.value = null;
  uni.removeStorageSync(PICK_KEY);
}

function submit() {
  if (submitting.value) return;
  if (!set.value || set.value.status !== 1) {
    uni.showToast({ title: "当前未开启预定", icon: "none" });
    return;
  }
  if (!time.value) {
    uni.showToast({ title: "请选择预约时段", icon: "none" });
    return;
  }
  if (!picked.value) {
    uni.showToast({ title: "请先选择桌台", icon: "none" });
    return;
  }
  const seat = Number(renshu.value);
  if (!seat || seat <= 0) {
    uni.showToast({ title: "请填写用餐人数", icon: "none" });
    return;
  }
  if (!/^1[3-9]\d{9}$/.test(tel.value.trim())) {
    uni.showToast({ title: "请检查手机号格式", icon: "none" });
    return;
  }
  submitting.value = true;
  bookingCreate({
    tableId: picked.value.id,
    time: time.value,
    renshu: seat,
    tel: tel.value.trim(),
    linkman: linkman.value.trim(),
    message: message.value.trim(),
  })
    .then((res) => {
      uni.removeStorageSync(PICK_KEY);
      if (res.need_pay === 1) {
        // 定金支付（PAY_MOCK=1 时 mock 直接入账）；失败可到订单列表继续支付
        bookingPay(res.order_id)
          .then(() => {
            uni.showToast({ title: "预定并支付成功", icon: "success" });
          })
          .catch(() => {
            uni.showToast({ title: "订单已创建，请前往订单完成支付", icon: "none" });
          })
          .finally(() => {
            setTimeout(() => {
              uni.redirectTo({ url: "/restaurant/booking/orderlist" });
            }, 800);
          });
      } else {
        uni.showToast({ title: "预定成功", icon: "success" });
        setTimeout(() => {
          uni.redirectTo({ url: "/restaurant/booking/orderlist" });
        }, 800);
      }
    })
    .catch(() => {
      /* 后端 toast 已提示 */
    })
    .finally(() => {
      submitting.value = false;
    });
}
</script>

<template>
  <view class="page">
    <view v-if="loadFailed" class="empty"><text>预定信息加载失败，请返回重试</text></view>
    <view v-else-if="set && set.status !== 1" class="empty"><text>商家暂未开启预定</text></view>

    <template v-if="set && set.status === 1">
      <!-- 选择时段 -->
      <view class="card">
        <text class="card-title">选择日期与时段</text>
        <view v-if="slotGroups.length === 0" class="slot-empty"><text>暂无可约时段</text></view>
        <view v-for="g in slotGroups" :key="g.date" class="day-block">
          <text class="day-label">{{ g.day_text }}（{{ g.date }}）</text>
          <view class="slot-grid">
            <view
              v-for="s in g.slots"
              :key="s.value"
              class="slot-item"
              :class="{ active: time === s.value }"
              @click="pickSlot(s)"
            >
              <text>{{ s.start.slice(11) }}{{ s.end !== s.start.slice(11) ? "-" + s.end : "" }}</text>
            </view>
          </view>
        </view>
      </view>

      <!-- 已选桌台 -->
      <view class="card">
        <text class="card-title">选择桌台</text>
        <view v-if="picked" class="picked-row">
          <image v-if="picked.pic" class="picked-img" :src="imgUrl(picked.pic)" mode="aspectFill" />
          <view class="picked-info">
            <text class="picked-name">{{ picked.name }}</text>
            <text class="picked-sub">
              {{ picked.category_name || "桌台" }} · {{ picked.seat }} 人桌
              <template v-if="picked.booking_fee > 0"> · 定金￥{{ price2(picked.booking_fee) }}</template>
            </text>
          </view>
          <view class="picked-re" @click="clearPicked"><text>重选</text></view>
        </view>
        <view v-else class="pick-btn" @click="goPickTable"><text>去选择桌台</text></view>
        <text class="fee-tip" v-if="set.pay === 1">
          本店预定需支付定金{{ set.pay_money > 0 ? "￥" + price2(set.pay_money) : "" }}，取消自动原路退回
        </text>
        <text class="fee-tip" v-else>本店预定免费，到店用餐即可</text>
      </view>

      <!-- 用餐信息 -->
      <view class="card">
        <text class="card-title">用餐信息</text>
        <view class="form-line">
          <text class="form-label">用餐人数</text>
          <input class="form-input" v-model="renshu" type="number" placeholder="请填写人数" maxlength="3" />
        </view>
        <view class="form-line">
          <text class="form-label">联系人</text>
          <input class="form-input" v-model="linkman" placeholder="选填" maxlength="20" />
        </view>
        <view class="form-line">
          <text class="form-label">手机号</text>
          <input class="form-input" v-model="tel" type="number" placeholder="请填写手机号" maxlength="11" />
        </view>
        <view class="form-line">
          <text class="form-label">备注</text>
          <input class="form-input" v-model="message" placeholder="选填，如宝宝椅等需求" maxlength="100" />
        </view>
      </view>

      <view class="submit-bar">
        <view class="preview">
          <text class="total">{{ picked && picked.booking_fee > 0 ? "定金 ￥" + price2(picked.booking_fee) : "免费预定" }}</text>
          <text class="hint">{{ time || "未选时段" }}</text>
        </view>
        <view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
          <text>{{ submitting ? "提交中..." : "提交预定" }}</text>
        </view>
      </view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx 0 180rpx;
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
.slot-empty {
  padding: 40rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.day-block {
  margin-bottom: 20rpx;
}
.day-label {
  font-size: 26rpx;
  color: #7b7de5;
  font-weight: 600;
}
.slot-grid {
  display: flex;
  flex-wrap: wrap;
  margin-top: 16rpx;
}
.slot-item {
  min-width: 22%;
  margin: 0 4% 16rpx 0;
  padding: 14rpx 10rpx;
  border: 1rpx solid #e5e5ea;
  border-radius: 12rpx;
  text-align: center;
  font-size: 26rpx;
  color: #333333;
}
.slot-item.active {
  border-color: #7b7de5;
  color: #7b7de5;
  background: rgba(123, 125, 229, 0.06);
}
.picked-row {
  display: flex;
  align-items: center;
}
.picked-img {
  width: 96rpx;
  height: 96rpx;
  border-radius: 12rpx;
  margin-right: 20rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.picked-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.picked-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.picked-sub {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
}
.picked-re {
  padding: 10rpx 28rpx;
  border: 1rpx solid #dddddd;
  border-radius: 28rpx;
  font-size: 24rpx;
  color: #666666;
  flex-shrink: 0;
}
.pick-btn {
  padding: 24rpx 0;
  border: 2rpx dashed #c5c6f0;
  border-radius: 12rpx;
  text-align: center;
  color: #7b7de5;
  font-size: 28rpx;
}
.fee-tip {
  margin-top: 16rpx;
  font-size: 22rpx;
  color: #999999;
}
.form-line {
  display: flex;
  align-items: center;
  padding: 20rpx 0;
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
  font-size: 32rpx;
  font-weight: 600;
}
.hint {
  font-size: 22rpx;
  color: #999999;
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
