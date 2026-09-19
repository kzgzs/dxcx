<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchHotelDetail, fetchRoomAvailable, hotelOrderCreate } from "../../api/industry";
import type { HotelRoom, RoomAvailableResult } from "../../api/types";
import { price2 } from "../../utils/format";

const hotelId = ref(0);
const roomId = ref(0);
const hotelName = ref("");
const room = ref<HotelRoom | null>(null);
const checkin = ref("");
const checkout = ref("");
const avail = ref<RoomAvailableResult | null>(null);
const name = ref("");
const phone = ref("");
const submitting = ref(false);

function todayStr() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}

function loadAvailable() {
  if (roomId.value <= 0 || !checkin.value || !checkout.value) return;
  fetchRoomAvailable(roomId.value, checkin.value, checkout.value)
    .then((res) => {
      avail.value = res;
    })
    .catch(() => {
      avail.value = null;
    });
}

onLoad((options) => {
  const o = options || {};
  hotelId.value = Number(o.hotel_id || 0);
  roomId.value = Number(o.room_id || 0);
  checkin.value = o.checkin || todayStr();
  checkout.value = o.checkout || "";
  if (hotelId.value > 0) {
    fetchHotelDetail(hotelId.value)
      .then((res) => {
        hotelName.value = res.name;
        room.value = res.rooms.find((r) => r.id === roomId.value) || null;
      })
      .catch(() => {
        room.value = null;
      });
  }
  loadAvailable();
});

function onCheckinChange(e: { detail: { value: string } }) {
  checkin.value = e.detail.value;
  if (checkout.value && checkout.value <= checkin.value) {
    uni.showToast({ title: "离店日期需晚于入住日期", icon: "none" });
    return;
  }
  loadAvailable();
}
function onCheckoutChange(e: { detail: { value: string } }) {
  if (e.detail.value <= checkin.value) {
    uni.showToast({ title: "离店日期需晚于入住日期", icon: "none" });
    return;
  }
  checkout.value = e.detail.value;
  loadAvailable();
}

function submit() {
  if (submitting.value) return;
  if (!checkin.value || !checkout.value) {
    uni.showToast({ title: "请选择入住与离店日期", icon: "none" });
    return;
  }
  if (checkout.value <= checkin.value) {
    uni.showToast({ title: "离店日期需晚于入住日期", icon: "none" });
    return;
  }
  if (checkin.value < todayStr()) {
    uni.showToast({ title: "入住日期不能早于今天", icon: "none" });
    return;
  }
  if (avail.value && avail.value.available <= 0) {
    uni.showToast({ title: "该日期段房间已满", icon: "none" });
    return;
  }
  if (!name.value.trim()) {
    uni.showToast({ title: "请输入入住人姓名", icon: "none" });
    return;
  }
  if (!/^1\d{10}$/.test(phone.value.trim())) {
    uni.showToast({ title: "请输入正确的手机号", icon: "none" });
    return;
  }
  submitting.value = true;
  hotelOrderCreate({
    room_id: roomId.value,
    checkin_date: checkin.value,
    checkout_date: checkout.value,
    name: name.value.trim(),
    phone: phone.value.trim(),
  })
    .then((res) => {
      uni.showToast({ title: "预订成功", icon: "success" });
      setTimeout(() => {
        uni.redirectTo({ url: "/hotel/order/list?highlight=" + res.id });
      }, 600);
    })
    .catch((err: Error) => {
      uni.showToast({ title: err.message || "预订失败", icon: "none" });
    })
    .finally(() => {
      submitting.value = false;
    });
}
</script>

<template>
  <view class="page">
    <view class="card">
      <text class="hotel-name">{{ hotelName }}</text>
      <text class="room-name">{{ room ? room.name : "房型加载中..." }}</text>
      <view class="date-row">
        <view class="date-item">
          <text class="date-label">入住</text>
          <picker mode="date" :value="checkin" @change="onCheckinChange">
            <text class="date-value">{{ checkin }}</text>
          </picker>
        </view>
        <view class="date-item">
          <text class="date-label">离店</text>
          <picker mode="date" :value="checkout" @change="onCheckoutChange">
            <text class="date-value">{{ checkout || "请选择" }}</text>
          </picker>
        </view>
      </view>
      <view v-if="avail" class="avail-row">
        <text v-if="avail.available > 0" class="ok">可订 {{ avail.available }} 间 · 共 {{ avail.nights }} 晚</text>
        <text v-else class="no">该日期段已满</text>
      </view>
    </view>

    <view class="card">
      <view class="form-row">
        <text class="form-label">入住人</text>
        <input class="form-input" v-model="name" placeholder="请输入入住人姓名" />
      </view>
      <view class="form-row">
        <text class="form-label">手机号</text>
        <input class="form-input" v-model="phone" type="number" maxlength="11" placeholder="请输入手机号" />
      </view>
    </view>

    <view class="card">
      <text class="card-title">费用明细</text>
      <template v-if="room">
        <view class="fee-line"><text>房费</text><text>￥{{ price2(room.price) }}/晚</text></view>
        <view v-if="avail" class="fee-line"><text>共 {{ avail.nights }} 晚</text><text>￥{{ price2(avail.amount) }}</text></view>
        <view class="fee-line"><text>押金（随单收取，退房返还）</text><text>￥{{ price2(room.deposit) }}</text></view>
        <view v-if="avail" class="fee-line total">
          <text>应付合计</text>
          <text>￥{{ price2(avail.amount + avail.deposit) }}</text>
        </view>
      </template>
    </view>

    <view class="bottom-bar">
      <view class="preview">
        <text v-if="avail" class="total">￥{{ price2(avail.amount + avail.deposit) }}</text>
        <text class="hint">含押金</text>
      </view>
      <view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
        <text>{{ submitting ? "提交中..." : "提交预订" }}</text>
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
.card {
  margin: 24rpx;
  padding: 28rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  flex-direction: column;
}
.hotel-name {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.room-name {
  margin-top: 8rpx;
  font-size: 26rpx;
  color: #7b7de5;
}
.date-row {
  display: flex;
  margin-top: 24rpx;
}
.date-item {
  flex: 1;
  display: flex;
  align-items: center;
}
.date-label {
  font-size: 26rpx;
  color: #999999;
  margin-right: 16rpx;
}
.date-value {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
.avail-row {
  margin-top: 16rpx;
  font-size: 24rpx;
}
.avail-row .ok {
  color: #09bb07;
}
.avail-row .no {
  color: #e64340;
}
.form-row {
  display: flex;
  align-items: center;
  padding: 20rpx 0;
  border-bottom: 1rpx solid #f2f2f2;
}
.form-row:last-child {
  border-bottom: none;
}
.form-label {
  width: 140rpx;
  font-size: 28rpx;
  color: #333333;
}
.form-input {
  flex: 1;
  font-size: 28rpx;
  color: #333333;
}
.card-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  margin-bottom: 16rpx;
}
.fee-line {
  display: flex;
  justify-content: space-between;
  font-size: 26rpx;
  color: #666666;
  line-height: 48rpx;
}
.fee-line.total {
  color: #333333;
  font-weight: 600;
  border-top: 1rpx solid #f2f2f2;
  margin-top: 12rpx;
  padding-top: 12rpx;
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
  align-items: baseline;
}
.preview .total {
  color: #7b7de5;
  font-size: 40rpx;
  font-weight: 600;
}
.hint {
  margin-left: 12rpx;
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
