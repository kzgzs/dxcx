<script setup lang="ts">
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { addressDelete, fetchAddressList } from "../../api/mall";
import type { AddressItem } from "../../api/types";

const list = ref<AddressItem[]>([]);

async function load() {
  try {
    list.value = (await fetchAddressList()) || [];
  } catch {
    // request 已弹 toast
  }
}

onShow(() => {
  load();
});

function goEdit(id?: number) {
  uni.navigateTo({ url: "/pagesM/address/edit" + (id ? "?id=" + id : "") });
}

function remove(item: AddressItem) {
  uni.showModal({
    title: "提示",
    content: "确定删除该地址吗？",
    success: async (res) => {
      if (!res.confirm) return;
      try {
        await addressDelete(item.id);
        list.value = list.value.filter((x) => x.id !== item.id);
      } catch {
        // request 已弹 toast
      }
    },
  });
}
</script>

<template>
  <view class="page">
    <view v-if="list.length === 0" class="empty"><text>还没有收货地址</text></view>

    <view v-for="a in list" :key="a.id" class="addr-card">
      <view class="addr-main" @click="goEdit(a.id)">
        <view class="addr-line">
          <text class="addr-name">{{ a.name }}</text>
          <text class="addr-phone">{{ a.phone }}</text>
          <text v-if="a.is_default === 1" class="addr-default-tag">默认</text>
        </view>
        <text class="addr-detail">{{ a.province }}{{ a.city }}{{ a.district }} {{ a.detail }}</text>
      </view>
      <view class="addr-actions">
        <text class="act" @click="goEdit(a.id)">编辑</text>
        <text class="act danger" @click="remove(a)">删除</text>
      </view>
    </view>

    <view class="bottom-space"></view>
    <view class="bottom-bar">
      <view class="add-btn" @click="goEdit()"><text>+ 新增收货地址</text></view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx;
  box-sizing: border-box;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.addr-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
  margin-bottom: 20rpx;
}
.addr-line {
  display: flex;
  align-items: center;
}
.addr-name {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  margin-right: 20rpx;
}
.addr-phone {
  font-size: 26rpx;
  color: #777777;
}
.addr-default-tag {
  margin-left: 16rpx;
  font-size: 20rpx;
  color: #7b7de5;
  border: 1rpx solid #7b7de5;
  border-radius: 6rpx;
  padding: 2rpx 10rpx;
}
.addr-detail {
  display: block;
  margin-top: 12rpx;
  font-size: 26rpx;
  color: #777777;
  line-height: 38rpx;
}
.addr-actions {
  margin-top: 20rpx;
  padding-top: 20rpx;
  border-top: 1rpx solid #f5f5f5;
  display: flex;
  justify-content: flex-end;
}
.act {
  margin-left: 32rpx;
  font-size: 26rpx;
  color: #555555;
}
.act.danger {
  color: #e64340;
}
.bottom-space {
  height: 140rpx;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  border-top: 1rpx solid #eeeeee;
}
.add-btn {
  padding: 22rpx 0;
  background: #7b7de5;
  color: #ffffff;
  text-align: center;
  border-radius: 44rpx;
  font-size: 30rpx;
}
</style>
