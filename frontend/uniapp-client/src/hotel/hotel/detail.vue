<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchHotelDetail, fetchRoomAvailable } from "../../api/industry";
import type { HotelDetail, RoomAvailableResult } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const hotel = ref<HotelDetail | null>(null);
const hotelId = ref(0);

/** 默认入住今天、离店明天 */
function fmtDate(d: Date) {
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}
const now = new Date();
const tomorrow = new Date(now.getTime() + 86400000);
const checkin = ref(fmtDate(now));
const checkout = ref(fmtDate(tomorrow));

/** 房型房态 map：room_id → 结果 */
const availMap = ref<Record<number, RoomAvailableResult>>({});

function loadAvailable() {
  if (!hotel.value) return;
  availMap.value = {};
  hotel.value.rooms.forEach((r) => {
    fetchRoomAvailable(r.id, checkin.value, checkout.value)
      .then((res) => {
        availMap.value[r.id] = res;
      })
      .catch(() => {
        // 查询失败不展示房态
      });
  });
}

onLoad((options) => {
  hotelId.value = Number((options && options.id) || 0);
  if (hotelId.value > 0) {
    fetchHotelDetail(hotelId.value)
      .then((res) => {
        hotel.value = res;
        loadAvailable();
      })
      .catch(() => {
        hotel.value = null;
      });
  }
});

function onCheckinChange(e: { detail: { value: string } }) {
  checkin.value = e.detail.value;
  if (checkout.value <= checkin.value) {
    const d = new Date(`${checkin.value} 00:00:00`.replace(/-/g, "/"));
    checkout.value = fmtDate(new Date(d.getTime() + 86400000));
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

function goBook(roomId: number) {
  uni.navigateTo({
    url: `/hotel/hotel/book?hotel_id=${hotelId.value}&room_id=${roomId}&checkin=${checkin.value}&checkout=${checkout.value}`,
  });
}
</script>

<template>
  <view class="page">
    <template v-if="hotel">
      <image v-if="hotel.pic" class="cover" :src="imgUrl(hotel.pic)" mode="aspectFill" />
      <view class="info-card">
        <view class="name-row">
          <text class="hotel-name">{{ hotel.name }}</text>
          <text v-if="hotel.star > 0" class="star-tag">{{ hotel.star }}星</text>
        </view>
        <text class="addr">{{ hotel.address || `${hotel.province || ""}${hotel.city || ""}${hotel.district || ""}` }}</text>
        <text v-if="hotel.phone" class="phone">电话：{{ hotel.phone }}</text>
        <text v-if="hotel.desc" class="desc">{{ hotel.desc }}</text>
      </view>

      <view class="date-card">
        <view class="date-item">
          <text class="date-label">入住</text>
          <picker mode="date" :value="checkin" @change="onCheckinChange">
            <text class="date-value">{{ checkin }}</text>
          </picker>
        </view>
        <view class="date-item">
          <text class="date-label">离店</text>
          <picker mode="date" :value="checkout" @change="onCheckoutChange">
            <text class="date-value">{{ checkout }}</text>
          </picker>
        </view>
      </view>

      <view class="room-title"><text>房型列表</text></view>
      <view v-if="hotel.rooms.length === 0" class="empty"><text>暂无房型</text></view>
      <view v-for="r in hotel.rooms" :key="r.id" class="room-card">
        <image v-if="r.pic" class="room-img" :src="imgUrl(r.pic)" mode="aspectFill" />
        <view v-else class="room-img room-ph"><text>暂无图</text></view>
        <view class="room-info">
          <text class="room-name">{{ r.name }}</text>
          <text class="room-meta">{{ r.bed_type }}<template v-if="r.area > 0"> · {{ r.area }}㎡</template></text>
          <text class="room-meta">押金 ￥{{ price2(r.deposit) }}/间</text>
          <view class="room-bottom">
            <view class="price-box">
              <text class="price">￥{{ price2(r.price) }}</text>
              <text class="per">/晚</text>
            </view>
            <view v-if="availMap[r.id]" class="avail">
              <text :class="availMap[r.id].available > 0 ? 'ok' : 'no'">
                {{ availMap[r.id].available > 0 ? `可订 ${availMap[r.id].available} 间` : "已满" }}
              </text>
            </view>
          </view>
        </view>
        <view class="book-btn" @click="goBook(r.id)"><text>预订</text></view>
      </view>
    </template>
    <view v-else class="empty"><text>加载中或酒店已下架</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 48rpx;
}
.cover {
  width: 100%;
  height: 360rpx;
}
.info-card {
  margin: 24rpx;
  padding: 28rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
  flex-direction: column;
}
.name-row {
  display: flex;
  align-items: center;
}
.hotel-name {
  font-size: 34rpx;
  font-weight: 600;
  color: #333333;
}
.star-tag {
  margin-left: 12rpx;
  padding: 2rpx 12rpx;
  border-radius: 8rpx;
  background: rgba(123, 125, 229, 0.1);
  color: #7b7de5;
  font-size: 20rpx;
}
.addr {
  margin-top: 14rpx;
  font-size: 24rpx;
  color: #999999;
}
.phone {
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #999999;
}
.desc {
  margin-top: 12rpx;
  font-size: 26rpx;
  color: #666666;
  line-height: 40rpx;
}
.date-card {
  margin: 0 24rpx 24rpx;
  padding: 24rpx 32rpx;
  background: #ffffff;
  border-radius: 16rpx;
  display: flex;
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
.room-title {
  padding: 0 32rpx 16rpx;
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.room-card {
  display: flex;
  margin: 0 24rpx 24rpx;
  padding: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.room-img {
  width: 160rpx;
  height: 160rpx;
  border-radius: 12rpx;
  margin-right: 20rpx;
  flex-shrink: 0;
}
.room-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f2f2f5;
  color: #c5c5cf;
  font-size: 22rpx;
}
.room-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.room-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.room-meta {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
}
.room-bottom {
  margin-top: auto;
  display: flex;
  align-items: baseline;
}
.price-box {
  display: flex;
  align-items: baseline;
}
.price {
  color: #7b7de5;
  font-size: 32rpx;
  font-weight: 600;
}
.per {
  font-size: 22rpx;
  color: #999999;
  margin-left: 4rpx;
}
.avail {
  margin-left: 20rpx;
  font-size: 22rpx;
}
.avail .ok {
  color: #09bb07;
}
.avail .no {
  color: #e64340;
}
.book-btn {
  align-self: center;
  padding: 14rpx 32rpx;
  border-radius: 32rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 26rpx;
  flex-shrink: 0;
}
</style>
