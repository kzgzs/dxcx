<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchKcOrderList } from "../api/kecheng";
import type { KcOrderItem } from "../api/kecheng";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import C2Empty from "../components/c2-empty.vue";

const list = ref<KcOrderItem[]>([]);
const keyword = ref("");
const pagenum = ref(1);
const loading = ref(false);
const hasMore = ref(true);

onLoad(() => {
  void load(true);
});

async function load(reset: boolean) {
  if (loading.value) return;
  loading.value = true;
  try {
    const p = reset ? 1 : pagenum.value + 1;
    const res = await fetchKcOrderList({ keyword: keyword.value.trim() || undefined, pagenum: p });
    const rows = res.list || [];
    list.value = reset ? rows : list.value.concat(rows);
    pagenum.value = p;
    hasMore.value = rows.length >= 10;
  } catch {
    /* request 已弹 toast */
  } finally {
    loading.value = false;
  }
}

function onSearch() {
  void load(true);
}

function statusText(item: KcOrderItem): string {
  const st = Number(item.status);
  if (st === 1) return "已支付";
  if (st === 0) return "待支付";
  return "状态" + st;
}

function learnText(item: KcOrderItem): string {
  const total = Number(item.kccount || 0);
  const done = Number(item.count || 0);
  if (total <= 0) return "";
  const hg = Number(item.learnhg) === 1 ? "（已合格）" : "";
  return "已学 " + done + "/" + total + hg;
}

function onTap(item: KcOrderItem) {
  if (Number(item.status) === 1 && item.kcid) {
    goPage("/pagesC2/kecheng/mldetail?kcid=" + item.kcid);
  } else if (item.kcid) {
    goPage("/pagesC2/kecheng/buy?id=" + item.kcid);
  }
}

onReachBottom(() => {
  if (hasMore.value && !loading.value) void load(false);
});
</script>

<template>
  <view class="page">
    <view class="search-bar">
      <input
        v-model="keyword"
        class="search-input"
        placeholder="搜索课程订单"
        confirm-type="search"
        @confirm="onSearch"
      />
      <view class="search-btn" @click="onSearch"><text>搜索</text></view>
    </view>

    <view class="order-list">
      <view v-for="item in list" :key="item.id" class="order-card" @click="onTap(item)">
        <image v-if="item.pic" class="o-pic" :src="imgUrl(item.pic)" mode="aspectFill"></image>
        <view v-else class="o-pic ph"><text>课</text></view>
        <view class="o-body">
          <text class="o-name">{{ item.name || "课程订单" }}</text>
          <text class="o-num">单号 {{ item.ordernum }} · {{ item.createtime_text }}</text>
          <view class="o-foot">
            <text class="o-price">￥{{ price2(item.price) }}</text>
            <text class="o-status" :class="Number(item.status) === 1 ? 'ok' : 'warn'">{{ statusText(item) }}</text>
            <text v-if="Number(item.status) === 1" class="o-learn">{{ learnText(item) }}</text>
          </view>
        </view>
      </view>
      <view v-if="list.length" class="list-tip">
        <text>{{ loading ? "加载中..." : hasMore ? "上拉加载更多" : "没有更多了" }}</text>
      </view>
      <C2Empty v-if="!list.length && !loading" text="暂无课程订单" />
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.search-bar {
  display: flex;
  align-items: center;
  padding: 20rpx 24rpx;
  background: #ffffff;
}
.search-input {
  flex: 1;
  height: 64rpx;
  background: #f2f2f6;
  border-radius: 32rpx;
  padding: 0 28rpx;
  font-size: 26rpx;
}
.search-btn {
  margin-left: 20rpx;
  padding: 14rpx 32rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 32rpx;
  font-size: 26rpx;
}
.order-list {
  padding: 24rpx;
}
.order-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
}
.o-pic {
  width: 200rpx;
  height: 150rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
}
.o-pic.ph {
  background: #ecebf7;
  color: #7b7de5;
  font-size: 48rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.o-body {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.o-name {
  font-size: 30rpx;
  color: #333333;
  font-weight: 600;
}
.o-num {
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #b5b5b5;
}
.o-foot {
  margin-top: auto;
  display: flex;
  align-items: center;
}
.o-price {
  font-size: 30rpx;
  color: #e64340;
  font-weight: 700;
}
.o-status {
  margin-left: 20rpx;
  font-size: 22rpx;
  padding: 2rpx 14rpx;
  border-radius: 14rpx;
}
.o-status.ok {
  background: #e8f7ee;
  color: #2e9e5b;
}
.o-status.warn {
  background: #fff3e6;
  color: #e08a3c;
}
.o-learn {
  margin-left: auto;
  font-size: 22rpx;
  color: #7b7de5;
}
.list-tip {
  text-align: center;
  font-size: 24rpx;
  color: #b5b5b5;
  padding: 20rpx 0;
}
</style>
