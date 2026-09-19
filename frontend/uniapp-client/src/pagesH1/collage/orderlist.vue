<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onPullDownRefresh, onReachBottom, onShow } from "@dcloudio/uni-app";
import { fetchCollageOrders } from "../../api/collage";
import type { CollageOrder } from "../../api/collage";
import { imgUrl, price2, fmtTime } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const tabs = [
  { label: "全部", value: "" },
  { label: "待支付", value: "0" },
  { label: "进行中", value: "1" },
  { label: "已发货", value: "2" },
  { label: "已收货", value: "3" },
  { label: "已关闭", value: "4" },
];

const active = ref("");
const list = ref<CollageOrder[]>([]);
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
    const data = await fetchCollageOrders(active.value, page.value, 10);
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

onPullDownRefresh(async () => {
  await load();
  uni.stopPullDownRefresh();
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

function goTeam(order: CollageOrder) {
  if (order.teamid > 0) {
    goPage("/pagesH1/collage/team?teamid=" + order.teamid);
  }
}

function goProduct(order: CollageOrder) {
  // 已关闭/无商品上下文不跳
  if (order.status === 4) return;
  goPage("/pagesH1/collage/list");
}
</script>

<template>
  <view class="page">
    <!-- 状态 tabs -->
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

      <view class="goods-row" @click="goProduct(o)">
        <image v-if="o.propic" class="goods-img" :src="imgUrl(o.propic)" mode="aspectFill" />
        <view v-else class="goods-img goods-img-ph"><text>图</text></view>
        <view class="goods-meta">
          <text class="goods-name">{{ o.proname }}</text>
          <text v-if="o.ggname" class="goods-spec">{{ o.ggname }}</text>
          <view class="row-bottom">
            <text class="price">￥{{ price2(o.sell_price) }}</text>
            <text class="num">x{{ o.num }}</text>
          </view>
        </view>
      </view>

      <view class="order-tags">
        <text class="tag">{{ o.buytype_text }}</text>
        <text v-if="o.team" class="tag team" @click="goTeam(o)">
          {{ o.team.status_text }}（{{ o.team.num }}/{{ o.team.teamnum }}）
        </text>
        <text v-if="o.refund_text" class="tag refund">{{ o.refund_text }}</text>
      </view>

      <view class="order-foot">
        <view class="foot-left">
          <text class="pay-amount">实付：￥{{ price2(o.totalprice) }}</text>
          <text class="order-time">{{ fmtTime(o.createtime) }}</text>
        </view>
        <view class="btns">
          <text v-if="o.teamid > 0" class="act-btn" @click="goTeam(o)">查看团</text>
        </view>
      </view>
    </view>

    <view v-if="loading" class="empty"><text>加载中...</text></view>
    <view v-else-if="list.length > 0 && list.length >= total" class="empty thin"><text>没有更多了</text></view>
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
.empty.thin {
  padding: 30rpx 0;
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
}
.goods-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
}
.goods-spec {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #999999;
}
.row-bottom {
  margin-top: auto;
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
.order-tags {
  display: flex;
  flex-wrap: wrap;
  padding-bottom: 18rpx;
  border-bottom: 1rpx solid #f5f5f5;
}
.tag {
  margin-right: 16rpx;
  font-size: 20rpx;
  color: #7b7de5;
  background: rgba(123, 125, 229, 0.1);
  border-radius: 8rpx;
  padding: 6rpx 14rpx;
}
.tag.team {
  color: #ff6034;
  background: #fff1ec;
}
.tag.refund {
  color: #999999;
  background: #f5f5f7;
}
.order-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 18rpx;
}
.foot-left {
  display: flex;
  flex-direction: column;
}
.pay-amount {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
.order-time {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #b5b5b5;
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
</style>
