<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom, onShow } from "@dcloudio/uni-app";
import { fetchKanjiaOrders } from "../api/kanjia";
import type { KanjiaOrder } from "../api/types";
import { fmtTime, imgUrl, price2 } from "../../utils/format";

const tabs = [
  { label: "全部", value: "" },
  { label: "待支付", value: "0" },
  { label: "待发货", value: "1" },
  { label: "待收货", value: "2" },
  { label: "已收货", value: "3" },
  { label: "已关闭", value: "4" },
];

const active = ref("");
const list = ref<KanjiaOrder[]>([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);

async function load(reset = true) {
  if (loading.value) return;
  loading.value = true;
  if (reset) {
    page.value = 1;
    list.value = [];
  }
  try {
    const data = await fetchKanjiaOrders(active.value, page.value, 10);
    list.value = reset ? data.list || [] : list.value.concat(data.list || []);
    total.value = data.total || 0;
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

onLoad((options) => {
  const st = options && options.status;
  if (st === "0" || st === "1" || st === "2" || st === "3" || st === "4") {
    active.value = st;
  }
});

onShow(() => {
  load();
});

onReachBottom(() => {
  if (list.value.length < total.value) {
    page.value += 1;
    load(false);
  }
});

function switchTab(value: string) {
  if (active.value === value) return;
  active.value = value;
  load();
}
</script>

<template>
  <view class="page">
    <scroll-view class="tabs" scroll-x>
      <view
        v-for="t in tabs"
        :key="t.label"
        class="tab"
        :class="{ active: active === t.value }"
        @click="switchTab(t.value)"
      >
        <text>{{ t.label }}</text>
      </view>
    </scroll-view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无相关订单</text></view>

    <view v-for="o in list" :key="o.id" class="order-card">
      <view class="order-head">
        <text class="order-sn">{{ o.ordernum }}</text>
        <text class="order-status">{{ o.status_text }}</text>
      </view>

      <view class="goods-row">
        <image v-if="o.propic" class="goods-img" :src="imgUrl(o.propic)" mode="aspectFill" />
        <view v-else class="goods-img goods-img-ph"><text>图</text></view>
        <view class="goods-meta">
          <text class="goods-name">{{ o.proname }}</text>
          <view class="row-bottom">
            <text class="price">砍价后 ￥{{ price2(o.product_price) }}</text>
            <text class="num">x{{ o.num }}</text>
          </view>
        </view>
      </view>

      <view class="order-info">
        <text class="info-line">下单时间：{{ fmtTime(o.createtime) }}</text>
        <text v-if="Number(o.freight_price) > 0" class="info-line">运费：￥{{ price2(o.freight_price) }}</text>
        <text v-if="o.status >= 1 && o.hexiao_code" class="info-line">核销码：{{ o.hexiao_code }}</text>
      </view>

      <view class="order-foot">
        <text class="pay-amount">实付：￥{{ price2(o.totalprice) }}</text>
      </view>
    </view>

    <view v-if="loading" class="empty"><text>加载中...</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.tabs {
  display: flex;
  white-space: nowrap;
  background: #ffffff;
  padding: 0 10rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.tab {
  display: inline-block;
  text-align: center;
  padding: 26rpx 28rpx;
  font-size: 27rpx;
  color: #555555;
}
.tab.active {
  color: #7b7de5;
  font-weight: 600;
  border-bottom: 4rpx solid #7b7de5;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.order-card {
  margin: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.order-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 18rpx;
  border-bottom: 1rpx solid #f5f5f5;
}
.order-sn {
  font-size: 24rpx;
  color: #999999;
}
.order-status {
  font-size: 26rpx;
  color: #7b7de5;
  font-weight: 600;
}
.goods-row {
  display: flex;
  padding: 20rpx 0;
}
.goods-img {
  width: 130rpx;
  height: 130rpx;
  border-radius: 10rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.goods-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.goods-meta {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.goods-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
}
.row-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.price {
  color: #ff4d4f;
  font-size: 26rpx;
}
.num {
  font-size: 24rpx;
  color: #999999;
}
.order-info {
  padding-bottom: 12rpx;
}
.info-line {
  display: block;
  font-size: 22rpx;
  color: #999999;
  padding: 4rpx 0;
}
.order-foot {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  border-top: 1rpx solid #f5f5f5;
  padding-top: 18rpx;
}
.pay-amount {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
</style>
