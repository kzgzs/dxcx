<script setup lang="ts">
import { onLoad, onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchSlot, fetchWorkerList, fetchYuyueProductDetail, yuyueOrderCreate } from "../../api/industry";
import type { YuyueProduct, YuyueSlot, YuyueWorker } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const productId = ref(0);
const product = ref<YuyueProduct | null>(null);

// 人员：worker_id=0 表示不指定
const workers = ref<YuyueWorker[]>([]);
const workerId = ref(0);

function todayStr() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}
const bookDate = ref(todayStr());
const slots = ref<YuyueSlot[]>([]);
const slotsLoading = ref(false);
const bookTime = ref("");
const submitting = ref(false);

function loadSlots() {
  bookTime.value = "";
  slots.value = [];
  if (productId.value <= 0 || !bookDate.value) return;
  slotsLoading.value = true;
  fetchSlot(productId.value, workerId.value, bookDate.value)
    .then((res) => {
      slots.value = res.slots || [];
    })
    .catch(() => {
      slots.value = [];
    })
    .finally(() => {
      slotsLoading.value = false;
    });
}

onLoad((options) => {
  productId.value = Number((options && options.id) || 0);
  if (productId.value > 0) {
    fetchYuyueProductDetail(productId.value)
      .then((res) => {
        product.value = res;
      })
      .catch(() => {
        product.value = null;
      });
  }
  fetchWorkerList()
    .then((res) => {
      workers.value = res.list || [];
    })
    .catch(() => {
      workers.value = [];
    });
  // 进入时以默认日期（今天）主动拉取时段
  loadSlots();
});

/** 兼保险：若首次加载未拉到（如页面直链刷新时序），返回/重显时补拉 */
onShow(() => {
  if (productId.value > 0 && slots.value.length === 0 && !slotsLoading.value) {
    loadSlots();
  }
});

function pickWorker(id: number) {
  if (workerId.value === id) return;
  workerId.value = id;
  loadSlots();
}

function onDateChange(e: { detail: { value: string } }) {
  if (e.detail.value < todayStr()) {
    uni.showToast({ title: "预约日期不能早于今天", icon: "none" });
    return;
  }
  bookDate.value = e.detail.value;
  loadSlots();
}

function pickSlot(s: YuyueSlot) {
  if (s.available <= 0) {
    uni.showToast({ title: "该时段已约满", icon: "none" });
    return;
  }
  bookTime.value = s.time;
}

function submit() {
  if (submitting.value) return;
  if (!bookDate.value) {
    uni.showToast({ title: "请选择预约日期", icon: "none" });
    return;
  }
  if (bookDate.value < todayStr()) {
    uni.showToast({ title: "预约日期不能早于今天", icon: "none" });
    return;
  }
  if (!bookTime.value) {
    uni.showToast({ title: "请选择预约时段", icon: "none" });
    return;
  }
  submitting.value = true;
  const params: { product_id: number; worker_id?: number; book_date: string; book_time: string } = {
    product_id: productId.value,
    book_date: bookDate.value,
    book_time: bookTime.value,
  };
  if (workerId.value > 0) params.worker_id = workerId.value;
  yuyueOrderCreate(params)
    .then((res) => {
      uni.showToast({ title: "预约成功", icon: "success" });
      setTimeout(() => {
        uni.redirectTo({ url: "/yuyue/order/list?highlight=" + res.id });
      }, 600);
    })
    .catch((err: Error) => {
      uni.showToast({ title: err.message || "预约失败", icon: "none" });
    })
    .finally(() => {
      submitting.value = false;
    });
}
</script>

<template>
  <view class="page">
    <view v-if="product" class="brief">
      <text class="p-name">{{ product.name }}</text>
      <text class="p-price">￥{{ price2(product.price) }}</text>
    </view>

    <view class="card">
      <text class="card-title">选择服务人员</text>
      <view class="worker-grid">
        <view class="worker-item" :class="{ active: workerId === 0 }" @click="pickWorker(0)">
          <view class="avatar avatar-ph"><text>随机</text></view>
          <text class="worker-name">不指定</text>
        </view>
        <view
          v-for="w in workers"
          :key="w.id"
          class="worker-item"
          :class="{ active: workerId === w.id }"
          @click="pickWorker(w.id)"
        >
          <image v-if="w.pic" class="avatar" :src="imgUrl(w.pic)" mode="aspectFill" />
          <view v-else class="avatar avatar-ph"><text>{{ w.name.slice(0, 1) }}</text></view>
          <text class="worker-name">{{ w.name }}</text>
        </view>
      </view>
    </view>

    <view class="card">
      <text class="card-title">选择日期与时段</text>
      <view class="date-row">
        <text class="date-label">预约日期</text>
        <picker mode="date" :value="bookDate" :start="todayStr()" @change="onDateChange">
          <text class="date-value">{{ bookDate }}</text>
        </picker>
      </view>
      <view v-if="slotsLoading" class="slot-empty"><text>加载中...</text></view>
      <view v-else-if="slots.length === 0" class="slot-empty"><text>暂无可约时段</text></view>
      <view v-else class="slot-grid">
        <view
          v-for="s in slots"
          :key="s.time"
          class="slot-item"
          :class="{ active: bookTime === s.time, disabled: s.available <= 0 }"
          @click="pickSlot(s)"
        >
          <text>{{ s.time }}</text>
          <text class="slot-sub">{{ s.available > 0 ? "可约" : "已满" }}</text>
        </view>
      </view>
    </view>

    <view class="bottom-bar">
      <view class="preview">
        <text v-if="product" class="total">￥{{ price2(product.price) }}</text>
        <text class="hint">{{ bookDate }} {{ bookTime || "未选时段" }}</text>
      </view>
      <view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
        <text>{{ submitting ? "提交中..." : "提交预约" }}</text>
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
.brief {
  margin: 24rpx;
  padding: 28rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.p-name {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.p-price {
  color: #7b7de5;
  font-size: 34rpx;
  font-weight: 600;
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
.worker-grid {
  display: flex;
  flex-wrap: wrap;
}
.worker-item {
  width: 25%;
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 24rpx;
}
.avatar {
  width: 96rpx;
  height: 96rpx;
  border-radius: 50%;
  border: 3rpx solid transparent;
}
.avatar-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f2f2f5;
  color: #7b7de5;
  font-size: 24rpx;
}
.worker-item.active .avatar {
  border-color: #7b7de5;
}
.worker-name {
  margin-top: 10rpx;
  font-size: 24rpx;
  color: #666666;
}
.worker-item.active .worker-name {
  color: #7b7de5;
  font-weight: 600;
}
.date-row {
  display: flex;
  align-items: center;
  margin-bottom: 24rpx;
}
.date-label {
  font-size: 26rpx;
  color: #999999;
  margin-right: 20rpx;
}
.date-value {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
.slot-empty {
  padding: 40rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.slot-grid {
  display: flex;
  flex-wrap: wrap;
}
.slot-item {
  width: 22%;
  margin: 0 4% 16rpx 0;
  padding: 14rpx 0;
  border: 1rpx solid #e5e5ea;
  border-radius: 12rpx;
  text-align: center;
  font-size: 26rpx;
  color: #333333;
  display: flex;
  flex-direction: column;
}
.slot-item:nth-child(4n) {
  margin-right: 0;
}
.slot-item.active {
  border-color: #7b7de5;
  color: #7b7de5;
  background: rgba(123, 125, 229, 0.06);
}
.slot-item.disabled {
  color: #cccccc;
  background: #fafafa;
}
.slot-sub {
  font-size: 20rpx;
  color: #999999;
  margin-top: 4rpx;
}
.slot-item.active .slot-sub {
  color: #7b7de5;
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
