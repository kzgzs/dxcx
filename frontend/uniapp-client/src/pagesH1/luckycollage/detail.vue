<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad, onShow } from "@dcloudio/uni-app";
import { fetchLuckyCollageProductDetail } from "../../api/collage";
import type { LuckyCollageProductDetail, BizSpec } from "../../api/collage";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import BizCountdown from "../components/BizCountdown.vue";

const productId = ref(0);
const detail = ref<LuckyCollageProductDetail | null>(null);

/* ---------- 规格选择 ---------- */
const specId = ref(0);
const num = ref(1);
const showSpec = ref(false);
/** 弹窗确认后要执行的动作：1单买 2开团 */
const pendingBuytype = ref(0);

const selectedSpec = computed<BizSpec | null>(
  () => (detail.value && detail.value.options.find((o) => o.id === specId.value)) || null
);

/** 轮播图：pics 逗号分隔优先，缺省回退单图 pic */
const gallery = computed<string[]>(() => {
  const p = detail.value;
  if (!p) return [];
  if (p.pics) {
    const arr = p.pics
      .split(",")
      .map((s) => s.trim())
      .filter((s) => s !== "");
    if (arr.length > 0) return arr;
  }
  return p.pic ? [p.pic] : [];
});

const showPrice = computed(() => (selectedSpec.value ? selectedSpec.value.sell_price : detail.value ? detail.value.sell_price : 0));
const showMarket = computed(() =>
  selectedSpec.value ? selectedSpec.value.market_price : detail.value ? detail.value.market_price : 0
);

/** 未中奖返还文案 */
const fyText = computed(() => {
  const p = detail.value;
  if (!p) return "";
  if (p.fy_type === 2) return "未中奖返红包￥" + price2(p.fy_money_val);
  if (p.fy_type === 3) return "未中奖返" + p.fy_money_val + "%红包";
  return "未中奖无返还";
});

onLoad((options) => {
  productId.value = Number(options && options.id) || 0;
});

onShow(() => {
  if (productId.value > 0) load();
});

async function load() {
  try {
    detail.value = await fetchLuckyCollageProductDetail(productId.value);
    const first = detail.value.options.find((o) => o.stock > 0);
    if (specId.value === 0 && first) specId.value = first.id;
  } catch {
    // request 已弹 toast
  }
}

function step(delta: number) {
  const max = selectedSpec.value ? selectedSpec.value.stock : detail.value ? detail.value.stock : 99;
  const next = num.value + delta;
  if (next < 1 || next > Math.max(1, max)) return;
  num.value = next;
}

function pickSpec(s: BizSpec) {
  if (s.stock <= 0) return;
  specId.value = s.id;
}

function buy(buytype: number) {
  if (!detail.value) return;
  if (detail.value.options.length > 0 && specId.value === 0) {
    pendingBuytype.value = buytype;
    showSpec.value = true;
    return;
  }
  goBuy(buytype);
}

function confirmSpec() {
  showSpec.value = false;
  if (pendingBuytype.value > 0) {
    goBuy(pendingBuytype.value);
    pendingBuytype.value = 0;
  }
}

function goBuy(buytype: number) {
  let url = "/pagesH1/luckycollage/buy?proid=" + productId.value + "&buytype=" + buytype + "&num=" + num.value;
  if (specId.value > 0) url += "&ggid=" + specId.value;
  goPage(url);
}

function goTeam(teamid: number) {
  goPage("/pagesH1/luckycollage/team?teamid=" + teamid);
}

function onTeamFinish() {
  load();
}
</script>

<template>
  <view class="page">
    <template v-if="detail">
      <!-- 轮播 -->
      <swiper v-if="gallery.length > 0" class="gallery" indicator-dots autoplay circular>
        <swiper-item v-for="(pic, i) in gallery" :key="i">
          <image class="gallery-img" :src="imgUrl(pic)" mode="aspectFill" />
        </swiper-item>
      </swiper>
      <view v-else class="gallery gallery-ph"><text>暂无图片</text></view>

      <!-- 价格区 -->
      <view class="price-card">
        <view class="price-row">
          <text class="price">￥{{ price2(showPrice) }}</text>
          <text v-if="showMarket > showPrice" class="market-price">￥{{ price2(showMarket) }}</text>
        </view>
        <text class="goods-name">{{ detail.name }}</text>
        <text v-if="detail.sellpoint" class="sellpoint">{{ detail.sellpoint }}</text>
        <view class="meta-row">
          <text class="meta">{{ detail.teamnum }}人团 · {{ detail.gua_num }}人中奖</text>
          <text class="meta">{{ detail.teamhour }}小时满员开奖</text>
          <text class="meta">{{ fyText }}</text>
        </view>
      </view>

      <!-- 玩法规则 -->
      <view class="rule-card">
        <text class="card-title">玩法说明</text>
        <view class="rule-step"><text class="rule-no">1</text><text class="rule-text">开团/参团并支付，等待团满员</text></view>
        <view class="rule-step"><text class="rule-no">2</text><text class="rule-text">满员即开奖，{{ detail.gua_num }} 个中奖名额随机产生</text></view>
        <view class="rule-step"><text class="rule-no">3</text><text class="rule-text">中奖者获得商品发货；未中奖者按规则返还</text></view>
      </view>

      <!-- 规格选择 -->
      <view v-if="detail.options.length > 0" class="option-row" @click="(pendingBuytype = 0), (showSpec = true)">
        <text class="option-label">规格</text>
        <text class="option-value" :class="{ active: selectedSpec }">
          {{ selectedSpec ? selectedSpec.name + " ￥" + price2(selectedSpec.sell_price) : "请选择规格" }}
        </text>
        <text class="option-arrow">></text>
      </view>

      <!-- 进行中的团 -->
      <view class="teams-card">
        <text class="card-title">正在拼团（可直接参与）</text>
        <view v-if="detail.teams.length === 0" class="teams-empty"><text>暂无进行中的团，去开一个吧</text></view>
        <view v-for="t in detail.teams" :key="t.id" class="team-row" @click="goTeam(t.id)">
          <view class="team-members">
            <view v-for="(m, mi) in t.members" :key="mi" class="member-dot">
              <image v-if="m.headimg" class="member-img" :src="imgUrl(m.headimg)" mode="aspectFill" />
              <text v-else class="member-ph">{{ (m.nickname || "拼").slice(0, 1) }}</text>
            </view>
            <view v-for="n in Math.max(0, t.teamnum - t.num)" :key="'e' + n" class="member-dot empty-dot">
              <text class="member-ph">?</text>
            </view>
          </view>
          <view class="team-meta">
            <text class="team-need">{{ t.num }}/{{ t.teamnum }} 人 · 满员开奖</text>
            <BizCountdown :deadline="t.deadline" @finish="onTeamFinish" />
          </view>
          <view class="team-join"><text>参团</text></view>
        </view>
      </view>

      <!-- 详情描述 -->
      <view class="detail-card">
        <text class="card-title">商品详情</text>
        <rich-text v-if="detail.detail" class="detail-html" :nodes="detail.detail"></rich-text>
        <text v-else class="detail-empty">暂无详情描述</text>
      </view>

      <view class="bottom-space"></view>

      <!-- 底部操作栏 -->
      <view class="bottom-bar">
        <view class="bar-btn single-btn" @click="buy(1)">
          <text class="bar-btn-price">￥{{ price2(showPrice) }}</text>
          <text>单独购买</text>
        </view>
        <view class="bar-btn team-btn" @click="buy(2)">
          <text class="bar-btn-price">￥{{ price2(showPrice) }}</text>
          <text>开团抽奖</text>
        </view>
      </view>

      <!-- 规格弹窗 -->
      <view v-if="showSpec" class="mask" @click="showSpec = false">
        <view class="popup" @click.stop>
          <view class="popup-title"><text>选择规格与数量</text></view>
          <scroll-view class="popup-body" scroll-y>
            <view
              v-for="s in detail.options"
              :key="s.id"
              class="spec-pick"
              :class="{ picked: s.id === specId, soldout: s.stock <= 0 }"
              @click="pickSpec(s)"
            >
              <text class="spec-name">{{ s.name }}</text>
              <text class="spec-price">￥{{ price2(s.sell_price) }}</text>
              <text class="spec-stock">{{ s.stock <= 0 ? "已售罄" : "库存" + s.stock }}</text>
            </view>
            <view class="num-row">
              <text class="num-label">数量</text>
              <view class="stepper">
                <text class="step-btn" @click="step(-1)">-</text>
                <text class="step-num">{{ num }}</text>
                <text class="step-btn" @click="step(1)">+</text>
              </view>
            </view>
          </scroll-view>
          <view class="popup-confirm" @click="confirmSpec"><text>确定</text></view>
        </view>
      </view>
    </template>
    <view v-else class="empty"><text>加载中...</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.gallery {
  height: 750rpx;
  background: #ffffff;
}
.gallery-img {
  width: 100%;
  height: 100%;
}
.gallery-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 28rpx;
}
.price-card {
  margin: 20rpx;
  padding: 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.price-row {
  display: flex;
  align-items: baseline;
}
.price {
  color: #7b7de5;
  font-size: 44rpx;
  font-weight: 700;
}
.market-price {
  margin-left: 16rpx;
  color: #b5b5b5;
  font-size: 26rpx;
  text-decoration: line-through;
}
.goods-name {
  display: block;
  margin-top: 14rpx;
  font-size: 32rpx;
  color: #333333;
  font-weight: 600;
  line-height: 46rpx;
}
.sellpoint {
  display: block;
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #999999;
}
.meta-row {
  margin-top: 16rpx;
  display: flex;
  flex-wrap: wrap;
}
.meta {
  margin-right: 24rpx;
  margin-bottom: 8rpx;
  font-size: 22rpx;
  color: #ff6034;
  background: #fff1ec;
  border-radius: 8rpx;
  padding: 6rpx 14rpx;
}
.rule-card {
  margin: 0 20rpx;
  padding: 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.card-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.rule-step {
  margin-top: 20rpx;
  display: flex;
  align-items: center;
}
.rule-no {
  width: 40rpx;
  height: 40rpx;
  border-radius: 50%;
  background: rgba(123, 125, 229, 0.14);
  color: #7b7de5;
  font-size: 22rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.rule-text {
  margin-left: 16rpx;
  font-size: 24rpx;
  color: #555555;
}
.option-row {
  display: flex;
  align-items: center;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
  margin: 20rpx;
  position: relative;
}
.option-label {
  font-size: 28rpx;
  color: #333333;
  margin-right: 24rpx;
}
.option-value {
  flex: 1;
  font-size: 26rpx;
  color: #999999;
}
.option-value.active {
  color: #333333;
}
.option-arrow {
  color: #cccccc;
  font-size: 28rpx;
}
.teams-card {
  margin: 0 20rpx;
  padding: 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.teams-empty {
  padding: 30rpx 0;
  color: #999999;
  font-size: 24rpx;
}
.team-row {
  display: flex;
  align-items: center;
  padding: 24rpx 0;
  border-bottom: 1rpx solid #f5f5f5;
}
.team-row:last-child {
  border-bottom: none;
}
.team-members {
  display: flex;
}
.member-dot {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  background: #f2f2f5;
  margin-right: -12rpx;
  border: 2rpx solid #ffffff;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
.member-img {
  width: 100%;
  height: 100%;
}
.member-ph {
  font-size: 22rpx;
  color: #999999;
}
.empty-dot {
  background: #fafafa;
  border: 2rpx dashed #d5d5de;
}
.team-meta {
  flex: 1;
  margin-left: 28rpx;
  display: flex;
  flex-direction: column;
}
.team-need {
  font-size: 26rpx;
  color: #333333;
  margin-bottom: 6rpx;
}
.team-join {
  padding: 12rpx 32rpx;
  background: #ff6034;
  color: #ffffff;
  border-radius: 30rpx;
  font-size: 24rpx;
}
.detail-card {
  margin: 20rpx;
  padding: 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.detail-html {
  margin-top: 20rpx;
  font-size: 26rpx;
  color: #555555;
  line-height: 40rpx;
  word-break: break-all;
}
.detail-empty {
  display: block;
  margin-top: 20rpx;
  color: #999999;
  font-size: 26rpx;
}
.bottom-space {
  height: 160rpx;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  border-top: 1rpx solid #eeeeee;
}
.bar-btn {
  flex: 1;
  height: 88rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  font-size: 26rpx;
  border-radius: 44rpx;
}
.bar-btn-price {
  font-size: 24rpx;
  opacity: 0.85;
}
.single-btn {
  background: #fff1ec;
  color: #ff6034;
  margin-right: 16rpx;
}
.team-btn {
  background: #7b7de5;
  color: #ffffff;
}
.empty {
  padding: 200rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.mask {
  position: fixed;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 200;
  display: flex;
  align-items: flex-end;
}
.popup {
  width: 100%;
  background: #ffffff;
  border-radius: 24rpx 24rpx 0 0;
  padding-bottom: env(safe-area-inset-bottom);
}
.popup-title {
  padding: 30rpx;
  text-align: center;
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  border-bottom: 1rpx solid #f2f2f2;
}
.popup-body {
  max-height: 60vh;
  overflow-y: auto;
  padding: 20rpx 24rpx;
  box-sizing: border-box;
}
.spec-pick {
  display: flex;
  align-items: center;
  padding: 24rpx 16rpx;
  border-bottom: 1rpx solid #f5f5f5;
}
.spec-pick.picked {
  background: rgba(123, 125, 229, 0.08);
  border-radius: 12rpx;
}
.spec-pick.soldout {
  opacity: 0.5;
}
.spec-name {
  flex: 1;
  font-size: 28rpx;
  color: #333333;
}
.spec-price {
  font-size: 26rpx;
  color: #7b7de5;
  margin-right: 20rpx;
}
.spec-stock {
  font-size: 22rpx;
  color: #999999;
}
.num-row {
  margin-top: 24rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16rpx;
}
.num-label {
  font-size: 26rpx;
  color: #555555;
}
.stepper {
  display: flex;
  align-items: center;
  border: 1rpx solid #e5e5e5;
  border-radius: 8rpx;
  overflow: hidden;
}
.step-btn {
  width: 60rpx;
  height: 56rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f6f6f8;
  color: #555555;
  font-size: 30rpx;
}
.step-num {
  width: 80rpx;
  text-align: center;
  font-size: 26rpx;
  color: #333333;
}
.popup-confirm {
  margin: 24rpx;
  padding: 22rpx 0;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 44rpx;
  text-align: center;
  font-size: 30rpx;
}
</style>
