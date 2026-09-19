<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchSeckillDetail } from "../api/seckill";
import type { SeckillDetail, SeckillProdata } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import SeckillTimeTag from "../components/SeckillTimeTag.vue";

const proid = ref(0);
const dateParam = ref("");
const timeParam = ref(0);
const detail = ref<SeckillDetail | null>(null);
const loading = ref(false);

/** 选中的场次行 id 与规格 id */
const selectedProdataId = ref(0);
const selectedGgid = ref(0);

const selectedProdata = computed<SeckillProdata | null>(() => {
  if (!detail.value) return null;
  return detail.value.prodata.find((p) => p.id === selectedProdataId.value) || null;
});

/** 展示价：选中场次价 > 场次最低价 > 商品秒杀价 */
const showPrice = computed(() => {
  if (selectedProdata.value) return selectedProdata.value.seckill_price;
  if (detail.value && detail.value.prodata.length > 0) {
    return Math.min(...detail.value.prodata.map((p) => p.seckill_price));
  }
  return detail.value ? detail.value.sell_price : 0;
});

async function load() {
  if (proid.value <= 0) return;
  loading.value = true;
  try {
    detail.value = await fetchSeckillDetail(proid.value, dateParam.value, timeParam.value);
    // 默认选中第一场（优先 URL 带入的场次）
    const rows = detail.value.prodata || [];
    const hit = rows.find((p) => p.seckill_date === dateParam.value && p.seckill_time === timeParam.value);
    const first = hit || rows[0];
    selectedProdataId.value = first ? first.id : 0;
    selectedGgid.value = 0;
  } catch {
    // request 已弹 toast
  } finally {
    loading.value = false;
  }
}

onLoad((options) => {
  proid.value = Number(options && options.proid) || 0;
  dateParam.value = (options && options.date) || "";
  timeParam.value = Number(options && options.time) || 0;
  load();
});

function selectProdata(p: SeckillProdata) {
  selectedProdataId.value = p.id;
}

function selectGgid(ggid: number) {
  selectedGgid.value = ggid;
}

/** 剩余库存（场次维度） */
function leftStock(p: SeckillProdata): number {
  return Math.max(0, p.seckill_num - p.sales);
}

function goBuy() {
  const p = selectedProdata.value;
  if (!p) {
    uni.showToast({ title: "请先选择场次", icon: "none" });
    return;
  }
  const now = Math.floor(Date.now() / 1000);
  if (now < p.starttime) {
    uni.showToast({ title: "秒杀尚未开始，请稍后再来", icon: "none" });
    return;
  }
  if (now > p.endtime) {
    uni.showToast({ title: "本场秒杀已结束", icon: "none" });
    return;
  }
  goPage(
    "/pagesH2/seckill/buy?proid=" + proid.value + "&prodata_id=" + p.id + "&ggid=" + selectedGgid.value
  );
}

function goOrders() {
  goPage("/pagesH2/seckill/orderlist");
}
</script>

<template>
  <view class="page">
    <view v-if="loading && !detail" class="empty"><text>加载中...</text></view>

    <template v-else-if="detail">
      <image v-if="detail.pic" class="banner" :src="imgUrl(detail.pic)" mode="aspectFill" />
      <view v-else class="banner banner-ph"><text>图</text></view>

      <view class="price-card">
        <view class="price-line">
          <text class="sk-price">￥{{ price2(showPrice) }}</text>
          <text v-if="detail.market_price > 0" class="market-price">￥{{ price2(detail.market_price) }}</text>
        </view>
        <text class="pro-name">{{ detail.name }}</text>
        <view class="meta-line">
          <text v-if="detail.perlimit > 0" class="meta">每人限购 {{ detail.perlimit }} 件</text>
          <text class="meta">已售 {{ detail.sales }}</text>
          <text v-if="detail.givescore > 0" class="meta">下单送 {{ detail.givescore }} 积分</text>
        </view>
      </view>

      <!-- 场次选择 -->
      <view v-if="detail.prodata.length > 0" class="session-card">
        <view class="card-title"><text>选择场次</text></view>
        <view v-for="p in detail.prodata" :key="p.id" class="session-row" :class="{ active: selectedProdataId === p.id }" @click="selectProdata(p)">
          <view class="session-meta">
            <text class="session-date">{{ p.seckill_date }} {{ p.seckill_time }}点场</text>
            <text class="session-price">秒杀价 ￥{{ price2(p.seckill_price) }} · 剩余 {{ leftStock(p) }} 件</text>
          </view>
          <SeckillTimeTag :starttime="p.starttime" :endtime="p.endtime" />
        </view>
      </view>

      <!-- 规格选择 -->
      <view v-if="detail.guige.length > 0" class="guige-card">
        <view class="card-title"><text>选择规格</text></view>
        <view class="guige-list">
          <view
            class="guige-chip"
            :class="{ active: selectedGgid === 0 }"
            @click="selectGgid(0)"
          >
            <text>默认规格</text>
          </view>
          <view
            v-for="g in detail.guige"
            :key="g.id"
            class="guige-chip"
            :class="{ active: selectedGgid === g.id }"
            @click="selectGgid(g.id)"
          >
            <text>{{ g.name }}</text>
          </view>
        </view>
      </view>

      <!-- 商品详情 -->
      <view v-if="detail.detail" class="detail-card">
        <view class="card-title"><text>商品详情</text></view>
        <rich-text :nodes="detail.detail"></rich-text>
      </view>

      <view class="bottom-bar">
        <view class="bar-btn plain" @click="goOrders"><text>秒杀订单</text></view>
        <view class="bar-btn" @click="goBuy"><text>立即抢购</text></view>
      </view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 140rpx;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.banner {
  width: 100%;
  height: 560rpx;
  background: #f2f2f5;
}
.banner-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 30rpx;
}
.price-card {
  margin: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
}
.price-line {
  display: flex;
  align-items: baseline;
}
.sk-price {
  font-size: 44rpx;
  font-weight: 700;
  color: #ff4d4f;
}
.market-price {
  margin-left: 20rpx;
  font-size: 24rpx;
  color: #999999;
  text-decoration: line-through;
}
.pro-name {
  margin-top: 16rpx;
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.meta-line {
  margin-top: 16rpx;
  display: flex;
  flex-wrap: wrap;
}
.meta {
  margin-right: 24rpx;
  font-size: 22rpx;
  color: #999999;
}
.session-card,
.guige-card,
.detail-card {
  margin: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.card-title {
  padding-bottom: 16rpx;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.session-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20rpx;
  border: 2rpx solid #f0f0f0;
  border-radius: 12rpx;
  margin-top: 16rpx;
}
.session-row.active {
  border-color: #ff4d4f;
  background: #fff7f7;
}
.session-meta {
  display: flex;
  flex-direction: column;
}
.session-date {
  font-size: 26rpx;
  color: #333333;
  font-weight: 600;
}
.session-price {
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #999999;
}
.guige-list {
  display: flex;
  flex-wrap: wrap;
}
.guige-chip {
  margin: 8rpx 16rpx 8rpx 0;
  padding: 10rpx 28rpx;
  border: 2rpx solid #e5e5e5;
  border-radius: 30rpx;
  font-size: 24rpx;
  color: #555555;
}
.guige-chip.active {
  border-color: #ff4d4f;
  color: #ff4d4f;
  background: #fff7f7;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  background: #ffffff;
  display: flex;
  padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));
  box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.bar-btn {
  flex: 1;
  text-align: center;
  padding: 20rpx 0;
  background: #ff4d4f;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 28rpx;
}
.bar-btn.plain {
  background: #ffffff;
  color: #555555;
  border: 1rpx solid #cccccc;
  margin-right: 20rpx;
  flex: 0 0 220rpx;
}
</style>
