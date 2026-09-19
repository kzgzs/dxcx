<script setup lang="ts">
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchKanjiaDetail, kanjiaStartJoin } from "../api/kanjia";
import type { KanjiaProductDetail } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const proid = ref(0);
const detail = ref<KanjiaProductDetail | null>(null);
const starting = ref(false);

async function load() {
  if (proid.value <= 0) return;
  try {
    detail.value = await fetchKanjiaDetail(proid.value);
  } catch {
    // request 已弹 toast
  }
}

onLoad((options) => {
  proid.value = Number(options && options.proid) || 0;
  load();
});

async function doStart() {
  if (starting.value || proid.value <= 0) return;
  starting.value = true;
  try {
    const res = await kanjiaStartJoin(proid.value);
    uni.showToast({ title: "发起砍价成功", icon: "success" });
    setTimeout(() => {
      // 用 redirectTo 收起发起页，直达砍价进度页
      uni.redirectTo({ url: "/pagesH2/kanjia/helplist?joinid=" + res.joinid });
    }, 600);
  } catch {
    // request 已弹 toast（如已有进行中记录等）
  } finally {
    starting.value = false;
  }
}
</script>

<template>
  <view class="page">
    <view v-if="detail" class="pro-card">
      <image v-if="detail.pic" class="pro-img" :src="imgUrl(detail.pic)" mode="aspectFill" />
      <view v-else class="pro-img pro-img-ph"><text>图</text></view>
      <view class="pro-meta">
        <text class="pro-name">{{ detail.name }}</text>
        <view class="price-line">
          <text class="min-price">底价 ￥{{ price2(detail.min_price) }}</text>
          <text class="sell-price">原价 ￥{{ price2(detail.sell_price) }}</text>
        </view>
      </view>
    </view>

    <view v-if="detail" class="rule-card">
      <view class="rule-title"><text>砍价规则</text></view>
      <view class="rule-item"><text>1. 发起砍价后当前价为商品原价，邀请好友帮砍可逐步砍至底价；</text></view>
      <view class="rule-item">
        <text>2. 最多邀请 {{ detail.helpnum }} 位好友帮砍，须在 {{ detail.limitHour }} 小时内砍到底价；</text>
      </view>
      <view class="rule-item"><text>3. 砍到底价后按底价下单，超时未砍到底价则砍价失败；</text></view>
      <view class="rule-item"><text>4. {{ detail.helpgive_text }}。</text></view>
    </view>

    <view class="foot">
      <view class="start-btn" :class="{ disabled: starting }" @click="doStart">
        <text>{{ starting ? "发起中..." : "确认发起砍价" }}</text>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx;
  box-sizing: border-box;
}
.pro-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.pro-img {
  width: 170rpx;
  height: 170rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
  background: #f2f2f5;
}
.pro-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 26rpx;
}
.pro-meta {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.pro-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.price-line {
  display: flex;
  align-items: baseline;
}
.min-price {
  color: #ff4d4f;
  font-size: 30rpx;
  font-weight: 700;
}
.sell-price {
  margin-left: 16rpx;
  color: #999999;
  font-size: 22rpx;
  text-decoration: line-through;
}
.rule-card {
  margin-top: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
}
.rule-title {
  padding-bottom: 16rpx;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.rule-item {
  padding: 8rpx 0;
  font-size: 24rpx;
  color: #666666;
  line-height: 1.6;
}
.foot {
  margin-top: 60rpx;
}
.start-btn {
  padding: 24rpx 0;
  background: #ff4d4f;
  color: #ffffff;
  text-align: center;
  border-radius: 48rpx;
  font-size: 32rpx;
}
.start-btn.disabled {
  opacity: 0.6;
}
</style>
