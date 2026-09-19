<script setup lang="ts">
import { onLoad, onReachBottom, onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchRestaurantOrderList, restaurantOrderCancel, restaurantOrderComplete, restaurantOrderPay } from "../../api/industry";
import type { RestaurantOrder } from "../../api/types";
import { dpNavigate } from "../../components/dp/navigate";
import { fmtTime, imgUrl, price2 } from "../../utils/format";

const tabs = [
  { label: "全部", status: -1 },
  { label: "待支付", status: 0 },
  { label: "已支付", status: 1 },
  { label: "已完成", status: 2 },
];
const tabIdx = ref(0);
const list = ref<RestaurantOrder[]>([]);
const page = ref(1);
const finished = ref(false);
const loading = ref(false);
/** 下单成功后跳转过来的新订单 id，高亮展示 */
const highlightId = ref(0);

onLoad((options) => {
  highlightId.value = Number((options && options.highlight) || 0) || 0;
});

function load(reset = false) {
  if (loading.value) return;
  if (reset) {
    page.value = 1;
    finished.value = false;
    list.value = [];
  }
  if (finished.value && !reset) return;
  loading.value = true;
  fetchRestaurantOrderList(tabs[tabIdx.value].status, page.value)
    .then((res) => {
      list.value = list.value.concat(res.list || []);
      if (list.value.length >= res.total || (res.list || []).length === 0) {
        finished.value = true;
      } else {
        page.value += 1;
      }
    })
    .catch(() => {
      finished.value = true;
    })
    .finally(() => {
      loading.value = false;
    });
}

onShow(() => {
  load(true);
});

function switchTab(i: number) {
  if (tabIdx.value === i) return;
  tabIdx.value = i;
  load(true);
}

function goDetail(o: RestaurantOrder) {
  dpNavigate("/restaurant/order/detail?id=" + o.id);
}

function doPay(o: RestaurantOrder) {
  restaurantOrderPay(o.id)
    .then(() => {
      uni.showToast({ title: "支付成功", icon: "success" });
      load(true);
    })
    .catch((err: Error) => uni.showToast({ title: err.message || "支付失败", icon: "none" }));
}

function doCancel(o: RestaurantOrder) {
  uni.showModal({
    title: "提示",
    content: "确定取消该订单吗？",
    success: (r) => {
      if (!r.confirm) return;
      restaurantOrderCancel(o.id)
        .then(() => {
          uni.showToast({ title: "已取消", icon: "success" });
          load(true);
        })
        .catch((err: Error) => uni.showToast({ title: err.message || "取消失败", icon: "none" }));
    },
  });
}

/** 完成订单：已支付→已完成，桌台随之释放（接口幂等） */
function doComplete(o: RestaurantOrder) {
  restaurantOrderComplete(o.id)
    .then(() => {
      uni.showToast({ title: "已完成", icon: "success" });
      load(true);
    })
    .catch((err: Error) => uni.showToast({ title: err.message || "操作失败", icon: "none" }));
}

onReachBottom(() => {
  load(false);
});
</script>

<template>
  <view class="page">
    <view class="tabs">
      <view
        v-for="(t, i) in tabs"
        :key="t.status"
        class="tab"
        :class="{ active: i === tabIdx }"
        @click="switchTab(i)"
      >
        <text>{{ t.label }}</text>
      </view>
    </view>

    <view v-if="list.length === 0 && !loading" class="empty"><text>暂无订单</text></view>

    <view v-for="o in list" :key="o.id" class="order-card" :class="{ highlight: o.id === highlightId }">
      <view class="order-head">
        <text class="order-sn">订单号：{{ o.order_sn }}</text>
        <text class="order-status">{{ o.status_text }}</text>
      </view>
      <view class="order-body">
        <text class="table-name">桌台：{{ o.table_name || `#${o.table_id}` }}</text>
        <view v-for="g in o.goods" :key="g.product_id" class="goods-line">
          <view class="goods-left">
            <image v-if="g.pic" class="goods-img" :src="imgUrl(g.pic)" mode="aspectFill" />
            <text class="goods-name">{{ g.name }}</text>
          </view>
          <text class="goods-num">×{{ g.num }} ￥{{ price2(g.price * g.num) }}</text>
        </view>
      </view>
      <view class="order-detail">
        <view class="detail-line"><text>订单金额</text><text>￥{{ price2(o.amount) }}</text></view>
        <view v-if="o.coupon_money > 0" class="detail-line"><text>优惠券抵扣</text><text>-￥{{ price2(o.coupon_money) }}</text></view>
        <view v-if="o.score_money > 0" class="detail-line"><text>积分抵扣</text><text>-￥{{ price2(o.score_money) }}</text></view>
        <view class="detail-line total"><text>实付</text><text>￥{{ price2(o.pay_amount) }}</text></view>
        <view class="detail-line"><text>下单时间</text><text>{{ fmtTime(o.create_time) }}</text></view>
        <view v-if="o.pay_time > 0" class="detail-line"><text>支付时间</text><text>{{ fmtTime(o.pay_time) }}</text></view>
      </view>
      <view class="order-actions">
        <view class="btn plain" @click="goDetail(o)"><text>查看详情</text></view>
        <view v-if="o.status === 0" class="btn plain" @click="doCancel(o)"><text>取消订单</text></view>
        <view v-if="o.status === 0" class="btn primary" @click="doPay(o)"><text>立即支付</text></view>
        <view v-if="o.status === 1" class="btn primary" @click="doComplete(o)"><text>完成订单</text></view>
      </view>
    </view>

    <view v-if="list.length > 0" class="list-end">
      <text>{{ finished ? "没有更多了" : "加载中..." }}</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.tabs {
  display: flex;
  background: #ffffff;
  padding: 0 8rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.tab {
  flex: 1;
  text-align: center;
  padding: 24rpx 0;
  font-size: 26rpx;
  color: #666666;
}
.tab.active {
  color: #7b7de5;
  font-weight: 600;
  border-bottom: 4rpx solid #7b7de5;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.order-card {
  margin: 24rpx;
  padding: 24rpx 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.order-card.highlight {
  border: 2rpx solid #7b7de5;
}
.order-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 20rpx;
  border-bottom: 1rpx solid #f2f2f2;
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
.order-body {
  padding: 20rpx 0;
}
.table-name {
  display: block;
  font-size: 26rpx;
  color: #333333;
  font-weight: 600;
  margin-bottom: 12rpx;
}
.goods-line {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8rpx 0;
}
.goods-left {
  display: flex;
  align-items: center;
  min-width: 0;
}
.goods-img {
  width: 56rpx;
  height: 56rpx;
  border-radius: 8rpx;
  margin-right: 12rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.goods-name {
  font-size: 26rpx;
  color: #666666;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.goods-num {
  font-size: 24rpx;
  color: #999999;
  flex-shrink: 0;
}
.order-detail {
  border-top: 1rpx solid #f2f2f2;
  padding-top: 16rpx;
}
.detail-line {
  display: flex;
  justify-content: space-between;
  font-size: 24rpx;
  color: #666666;
  line-height: 42rpx;
}
.detail-line.total {
  color: #333333;
  font-weight: 600;
}
.order-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 20rpx;
}
.btn {
  margin-left: 16rpx;
  padding: 12rpx 36rpx;
  border-radius: 32rpx;
  font-size: 26rpx;
}
.btn.plain {
  border: 1rpx solid #dddddd;
  color: #666666;
}
.btn.primary {
  background: #7b7de5;
  color: #ffffff;
}
.list-end {
  padding: 24rpx 0 48rpx;
  text-align: center;
  color: #bbbbbb;
  font-size: 24rpx;
}
</style>
