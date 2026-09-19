<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchMyScoreBalance, fetchScoreshopDetail } from "../api/scoreshop";
import type { ScoreshopDetail } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const proid = ref(0);
const detail = ref<ScoreshopDetail | null>(null);
const loaded = ref(false);
const selectedGgid = ref(0);
const myScore = ref<number | null>(null);

/** 当前生效价格：选中规格 > 商品默认 */
const curScore = computed(() => {
  if (!detail.value) return 0;
  const g = (detail.value.guige || []).find((x) => x.id === selectedGgid.value);
  return g ? g.score_price : detail.value.score_price;
});

const curMoney = computed(() => {
  if (!detail.value) return 0;
  const g = (detail.value.guige || []).find((x) => x.id === selectedGgid.value);
  return g ? g.money_price : detail.value.money_price;
});

const curStock = computed(() => {
  if (!detail.value) return 0;
  const g = (detail.value.guige || []).find((x) => x.id === selectedGgid.value);
  return g ? g.stock : detail.value.stock;
});

async function load() {
  if (proid.value <= 0) {
    loaded.value = true;
    return;
  }
  try {
    detail.value = await fetchScoreshopDetail(proid.value);
  } catch {
    // request 已弹 toast
  } finally {
    loaded.value = true;
  }
}

function loadScore() {
  fetchMyScoreBalance()
    .then((d) => {
      myScore.value = Number(d.score) || 0;
    })
    .catch(() => {
      // 未登录等场景静默
    });
}

onLoad((options) => {
  proid.value = Number(options && options.proid) || 0;
  load();
  loadScore();
});

function selectGg(id: number) {
  selectedGgid.value = selectedGgid.value === id ? 0 : id;
}

function goBuy() {
  if (!detail.value) return;
  if (curStock.value <= 0) {
    uni.showToast({ title: "该商品已兑完", icon: "none" });
    return;
  }
  if (myScore.value !== null && myScore.value < curScore.value) {
    uni.showToast({ title: "积分不足，无法兑换", icon: "none" });
    return;
  }
  goPage("/pagesH2/scoreshop/buy?proid=" + proid.value + "&ggid=" + selectedGgid.value);
}

function goOrders() {
  goPage("/pagesH2/scoreshop/orderlist");
}
</script>

<template>
  <view class="page">
    <view v-if="!loaded" class="empty"><text>加载中...</text></view>
    <view v-else-if="!detail" class="empty"><text>商品不存在或已下架</text></view>

    <template v-else>
      <!-- 商品主图 -->
      <image v-if="detail.pic" class="main-img" :src="imgUrl(detail.pic)" mode="aspectFill" />
      <view v-else class="main-img main-img-ph"><text>图</text></view>

      <!-- 价格与名称 -->
      <view class="info-card">
        <view class="price-line">
          <text class="score-price">{{ curScore }} 积分</text>
          <text v-if="Number(curMoney) > 0" class="money-price">+￥{{ price2(curMoney) }}</text>
          <text class="sell-price">售价 ￥{{ price2(detail.sell_price) }}</text>
        </view>
        <text class="pro-name">{{ detail.name }}</text>
        <view class="meta-line">
          <text class="meta-item">已兑 {{ detail.sales || 0 }}</text>
          <text class="meta-item">库存 {{ curStock }}</text>
          <text v-if="detail.buymax > 0" class="meta-item">限兑 {{ detail.buymax }} 件</text>
          <text v-if="myScore !== null" class="meta-item mine">我的积分 {{ myScore }}</text>
        </view>
      </view>

      <!-- 规格选择 -->
      <view v-if="detail.guige && detail.guige.length > 0" class="gg-card">
        <text class="gg-title">选择规格</text>
        <view class="gg-list">
          <view
            v-for="g in detail.guige"
            :key="g.id"
            class="gg-chip"
            :class="{ active: selectedGgid === g.id, off: Number(g.stock) <= 0 }"
            @click="selectGg(g.id)"
          >
            <text class="gg-name">{{ g.name }}</text>
            <text class="gg-price">{{ g.score_price }}积分<template v-if="Number(g.money_price) > 0">+￥{{ price2(g.money_price) }}</template></text>
          </view>
        </view>
      </view>

      <!-- 兑换说明 -->
      <view class="rule-card">
        <text class="rule-title">兑换说明</text>
        <text class="rule-line">· 纯积分商品：提交订单后立即扣减积分并视为已支付</text>
        <text class="rule-line">· 积分+现金商品：扣减积分后需支付现金部分（PAY_MOCK 自动入账）</text>
        <text class="rule-line">· 兑换成功后积分不予退还，请确认收货信息后再提交</text>
      </view>

      <!-- 底部操作栏 -->
      <view class="foot-bar">
        <view class="foot-order" @click="goOrders"><text>兑换订单</text></view>
        <view class="foot-btn" :class="{ off: curStock <= 0 }" @click="goBuy">
          <text>{{ curStock > 0 ? "立即兑换" : "已兑完" }}</text>
        </view>
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
.main-img {
  width: 100%;
  height: 560rpx;
  background: #f2f2f5;
}
.main-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 30rpx;
}
.info-card {
  background: #ffffff;
  padding: 24rpx;
}
.price-line {
  display: flex;
  align-items: baseline;
}
.score-price {
  color: #ff9f43;
  font-size: 44rpx;
  font-weight: 700;
}
.money-price {
  margin-left: 12rpx;
  color: #ff4d4f;
  font-size: 30rpx;
  font-weight: 600;
}
.sell-price {
  margin-left: 16rpx;
  color: #999999;
  font-size: 22rpx;
  text-decoration: line-through;
}
.pro-name {
  display: block;
  margin-top: 14rpx;
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.meta-line {
  margin-top: 14rpx;
  display: flex;
  flex-wrap: wrap;
}
.meta-item {
  margin-right: 24rpx;
  font-size: 22rpx;
  color: #999999;
}
.meta-item.mine {
  color: #ff9f43;
}
.gg-card {
  margin-top: 20rpx;
  background: #ffffff;
  padding: 24rpx;
}
.gg-title {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.gg-list {
  margin-top: 18rpx;
  display: flex;
  flex-wrap: wrap;
}
.gg-chip {
  margin: 0 20rpx 20rpx 0;
  padding: 14rpx 24rpx;
  border: 1rpx solid #e5e5ea;
  border-radius: 12rpx;
  background: #fafafa;
  display: flex;
  flex-direction: column;
}
.gg-chip.active {
  border-color: #ff9f43;
  background: #fff7ec;
}
.gg-chip.off {
  opacity: 0.5;
}
.gg-name {
  font-size: 25rpx;
  color: #333333;
}
.gg-price {
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #ff9f43;
}
.rule-card {
  margin-top: 20rpx;
  background: #ffffff;
  padding: 24rpx;
}
.rule-title {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.rule-line {
  display: block;
  margin-top: 12rpx;
  font-size: 23rpx;
  color: #888888;
  line-height: 1.6;
}
.foot-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  background: #ffffff;
  padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));
  display: flex;
  align-items: center;
}
.foot-order {
  margin-right: 24rpx;
  font-size: 24rpx;
  color: #666666;
}
.foot-btn {
  flex: 1;
  text-align: center;
  padding: 22rpx 0;
  background: #ff9f43;
  color: #ffffff;
  border-radius: 44rpx;
  font-size: 30rpx;
  font-weight: 600;
}
.foot-btn.off {
  background: #c5c5cf;
}
</style>
