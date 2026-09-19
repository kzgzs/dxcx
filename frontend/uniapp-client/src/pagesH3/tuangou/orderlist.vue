<script setup lang="ts">
/**
 * 团购订单列表
 * - tabs：全部/待支付/已支付/已发货/已完成/退款（st ''/0/1/2/3/r）
 * - 操作：核销码（到店单）/确认收货/评价/申请退款/删除
 */
import { ref } from "vue";
import { onLoad, onReachBottom, onShow } from "@dcloudio/uni-app";
import { fetchTuangouOrders, tuangouOrderCollect, tuangouOrderDelete } from "../api";
import type { TuangouOrder } from "../api";
import { fmtTime, imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import H3Empty from "../components/h3-empty.vue";

const tabs = [
  { st: "", name: "全部" },
  { st: "0", name: "待支付" },
  { st: "1", name: "已支付" },
  { st: "2", name: "已发货" },
  { st: "3", name: "已完成" },
  { st: "r", name: "退款" },
];

const st = ref("");
const list = ref<TuangouOrder[]>([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const loaded = ref(false);

onLoad(() => {
  load(true);
});

onShow(() => {
  if (loaded.value) load(true);
});

onReachBottom(() => {
  if (list.value.length < total.value) {
    page.value += 1;
    load(false);
  }
});

async function load(reset = true) {
  if (loading.value) return;
  loading.value = true;
  if (reset) {
    page.value = 1;
    list.value = [];
  }
  try {
    const data = await fetchTuangouOrders(st.value, page.value, 10);
    list.value = reset ? data.list || [] : list.value.concat(data.list || []);
    total.value = data.total || 0;
    loaded.value = true;
  } catch {
    loaded.value = true;
  } finally {
    loading.value = false;
  }
}

function pickTab(value: string) {
  if (st.value === value) return;
  st.value = value;
  load(true);
}

function goDetail(id: number) {
  goPage("/pagesH3/tuangou/orderdetail?id=" + id);
}

function goHexiao(o: TuangouOrder) {
  goPage("/pagesH3/tuangou/hexiao?id=" + o.id);
}

function goRefund(o: TuangouOrder) {
  goPage("/pagesH3/tuangou/refund?id=" + o.id);
}

function goComment(o: TuangouOrder) {
  goPage("/pagesH3/tuangou/comment?id=" + o.id + "&name=" + encodeURIComponent(o.proname));
}

async function collect(o: TuangouOrder) {
  try {
    await tuangouOrderCollect(o.id);
    uni.showToast({ title: "已确认收货", icon: "success" });
    load(true);
  } catch {
    // 已提示
  }
}

function removeOrder(o: TuangouOrder) {
  uni.showModal({
    title: "提示",
    content: "确定删除该订单吗？",
    success: async (res) => {
      if (!res.confirm) return;
      try {
        await tuangouOrderDelete(o.id);
        uni.showToast({ title: "删除成功", icon: "success" });
        load(true);
      } catch {
        // 已提示
      }
    },
  });
}

/** 到店核销单且已支付可展示核销码 */
function showHexiao(o: TuangouOrder): boolean {
  return o.freight_type === 1 && (o.status === 1 || o.status === 2 || o.status === 3);
}
</script>

<template>
  <view class="page">
    <!-- tabs -->
    <scroll-view class="tab-bar" scroll-x>
      <view
        v-for="t in tabs"
        :key="t.st"
        class="tab-item"
        :class="{ active: st === t.st }"
        @click="pickTab(t.st)"
      >
        <text>{{ t.name }}</text>
      </view>
    </scroll-view>

    <!-- 订单列表 -->
    <view v-if="list.length > 0" class="order-list">
      <view v-for="o in list" :key="o.id" class="order-card" @click="goDetail(o.id)">
        <view class="order-top">
          <text class="order-num">{{ o.ordernum }}</text>
          <text class="order-status" :class="{ warn: o.refund_status > 0 }">
            {{ o.refund_status > 0 ? o.refund_text : o.status_text }}
          </text>
        </view>
        <view class="order-body">
          <image v-if="o.propic" class="order-img" :src="imgUrl(o.propic)" mode="aspectFill" />
          <view v-else class="order-img order-img-ph"><text>图</text></view>
          <view class="order-meta">
            <text class="order-name">{{ o.proname }}</text>
            <text class="order-sub">x{{ o.num }} · {{ o.freight_text || o.freight_type_text }}</text>
            <text class="order-time">{{ fmtTime(o.createtime) }}</text>
          </view>
          <view class="order-price-wrap">
            <text class="order-price">￥{{ price2(o.totalprice) }}</text>
          </view>
        </view>
        <view class="order-actions" @click.stop>
          <view v-if="o.status === 4 && o.refund_status === 0" class="act-btn" @click="removeOrder(o)">
            <text>删除</text>
          </view>
          <view v-if="showHexiao(o)" class="act-btn primary" @click="goHexiao(o)"><text>核销码</text></view>
          <view v-if="o.status === 2" class="act-btn primary" @click="collect(o)"><text>确认收货</text></view>
          <view v-if="o.status === 3 && o.iscomment === 0" class="act-btn primary" @click="goComment(o)">
            <text>评价</text>
          </view>
          <view v-if="o.status === 1 && o.refund_status === 0" class="act-btn" @click="goRefund(o)">
            <text>申请退款</text>
          </view>
        </view>
      </view>
    </view>
    <h3-empty v-else-if="loaded" text="暂无相关订单" />

    <view v-if="loaded && list.length > 0" class="load-more">
      <text>{{ loading ? "加载中..." : list.length < total ? "上拉加载更多" : "没有更多了" }}</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.tab-bar {
  white-space: nowrap;
  background: #ffffff;
  padding: 20rpx 0;
  position: sticky;
  top: 0;
  z-index: 5;
}
.tab-item {
  display: inline-block;
  padding: 8rpx 32rpx;
  font-size: 26rpx;
  color: #666666;
}
.tab-item.active {
  color: #7b7de5;
  font-weight: 600;
}
.order-list {
  padding: 20rpx 24rpx 0;
}
.order-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
}
.order-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 16rpx;
  border-bottom: 1rpx solid #f5f5f7;
}
.order-num {
  font-size: 22rpx;
  color: #b5b5b5;
}
.order-status {
  font-size: 26rpx;
  color: #7b7de5;
  font-weight: 600;
}
.order-status.warn {
  color: #ff8a3d;
}
.order-body {
  display: flex;
  padding: 20rpx 0;
}
.order-img {
  width: 140rpx;
  height: 140rpx;
  border-radius: 12rpx;
  background: #f2f2f5;
  flex-shrink: 0;
}
.order-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.order-meta {
  flex: 1;
  margin-left: 20rpx;
  min-width: 0;
}
.order-name {
  display: block;
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.order-sub {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #999999;
}
.order-time {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #c5c5cf;
}
.order-price-wrap {
  display: flex;
  align-items: flex-end;
}
.order-price {
  color: #333333;
  font-size: 30rpx;
  font-weight: 700;
}
.order-actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
}
.act-btn {
  margin-left: 16rpx;
  padding: 10rpx 28rpx;
  border: 1rpx solid #d9d9e3;
  border-radius: 28rpx;
  font-size: 24rpx;
  color: #666666;
}
.act-btn.primary {
  border-color: #7b7de5;
  color: #7b7de5;
}
.load-more {
  text-align: center;
  padding: 20rpx 0 40rpx;
  font-size: 22rpx;
  color: #b5b5b5;
}
</style>
