<script setup lang="ts">
import { ref } from "vue";
import { onShow, onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchOrderList, orderCancel, orderConfirm } from "../../api/mall";
import type { OrderItem } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const tabs = [
  { label: "全部", value: "" },
  { label: "待支付", value: "0" },
  { label: "待发货", value: "1" },
  { label: "待收货", value: "2" },
  { label: "已完成", value: "3" },
];

const active = ref("");
const list = ref<OrderItem[]>([]);
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
    const data = await fetchOrderList(active.value, page.value, 10);
    list.value = reset ? data.list || [] : list.value.concat(data.list || []);
    total.value = data.total || 0;
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

onLoad((options) => {
  // 支持 ?status=0/1/2/3 定位到对应 tab（会员中心五宫格跳转）
  const st = options && options.status;
  if (st === "0" || st === "1" || st === "2" || st === "3") {
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

function goDetail(id: number) {
  uni.navigateTo({ url: "/pagesM/order/detail?id=" + id });
}

function goPay(order: OrderItem) {
  uni.navigateTo({ url: "/pagesM/pay/pay?order_id=" + order.id + "&amount=" + order.pay_amount });
}

function doCancel(order: OrderItem) {
  uni.showModal({
    title: "提示",
    content: "确定取消该订单吗？",
    success: async (res) => {
      if (!res.confirm) return;
      try {
        await orderCancel(order.id);
        uni.showToast({ title: "已取消", icon: "success" });
        load();
      } catch {
        // request 已弹 toast
      }
    },
  });
}

function doConfirm(order: OrderItem) {
  uni.showModal({
    title: "提示",
    content: "确认已收到货吗？",
    success: async (res) => {
      if (!res.confirm) return;
      try {
        await orderConfirm(order.id);
        uni.showToast({ title: "已确认收货", icon: "success" });
        load();
      } catch {
        // request 已弹 toast
      }
    },
  });
}

function goRefund(order: OrderItem) {
  uni.navigateTo({ url: "/pagesM/refund/apply?order_id=" + order.id });
}
</script>

<template>
  <view class="page">
    <!-- 状态 tabs -->
    <view class="tabs">
      <view
        v-for="t in tabs"
        :key="t.label"
        class="tab"
        :class="{ active: active === t.value }"
        @click="switchTab(t.value)"
      >
        <text>{{ t.label }}</text>
      </view>
    </view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无相关订单</text></view>

    <view v-for="o in list" :key="o.id" class="order-card" @click="goDetail(o.id)">
      <view class="order-head">
        <text class="order-sn">{{ o.order_sn }}</text>
        <text class="order-status">{{ o.status_text }}</text>
      </view>

      <view v-for="g in o.goods" :key="g.goods_id" class="goods-row">
        <image v-if="g.pic" class="goods-img" :src="imgUrl(g.pic)" mode="aspectFill" />
        <view v-else class="goods-img goods-img-ph"><text>图</text></view>
        <view class="goods-meta">
          <text class="goods-name">{{ g.name }}</text>
          <view class="row-bottom">
            <text class="price">￥{{ price2(g.price) }}</text>
            <text class="num">x{{ g.num }}</text>
          </view>
        </view>
      </view>

      <view class="order-foot">
        <text class="pay-amount">实付：￥{{ price2(o.pay_amount) }}</text>
        <view class="btns">
          <text v-if="o.status === 0" class="act-btn plain" @click.stop="doCancel(o)">取消</text>
          <text v-if="o.status === 0" class="act-btn" @click.stop="goPay(o)">去支付</text>
          <text v-if="o.status === 2" class="act-btn" @click.stop="doConfirm(o)">确认收货</text>
          <text v-if="o.status === 1 || o.status === 2" class="act-btn plain" @click.stop="goRefund(o)">退款</text>
        </view>
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
  background: #ffffff;
  padding: 0 10rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.tab {
  flex: 1;
  text-align: center;
  padding: 26rpx 0;
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
  color: #333333;
  font-size: 26rpx;
}
.num {
  font-size: 24rpx;
  color: #999999;
}
.order-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1rpx solid #f5f5f5;
  padding-top: 18rpx;
}
.pay-amount {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
.btns {
  display: flex;
}
.act-btn {
  margin-left: 16rpx;
  padding: 10rpx 30rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 30rpx;
  font-size: 24rpx;
}
.act-btn.plain {
  background: #ffffff;
  color: #555555;
  border: 1rpx solid #cccccc;
}
</style>
