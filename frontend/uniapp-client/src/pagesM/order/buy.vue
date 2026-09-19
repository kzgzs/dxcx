<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad, onShow } from "@dcloudio/uni-app";
import {
  fetchAddressList,
  fetchGoodsDetail,
  fetchMyCoupons,
  fetchMyScore,
  fetchPromotionList,
  orderCreate,
} from "../../api/mall";
import type { AddressItem, CouponRecord, GoodsDetail, PromotionItem } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";

const goodsId = ref(0);
const num = ref(1);
const goods = ref<GoodsDetail | null>(null);

/* ---------- 地址 ---------- */
const addresses = ref<AddressItem[]>([]);
const addressId = ref(0);
const showAddress = ref(false);
const currentAddress = computed(() => addresses.value.find((a) => a.id === addressId.value) || null);

/* ---------- 优惠券 ---------- */
const coupons = ref<CouponRecord[]>([]);
const couponId = ref(0); // 券模板 id（order/create 口径）
const showCoupon = ref(false);
const currentCoupon = computed(() => coupons.value.find((c) => c.coupon_id === couponId.value) || null);
/** 可用券：未使用 + 满足门槛（后端按促销后金额判断门槛） */
const usableCoupons = computed(() => {
  const base = afterPromo.value;
  return coupons.value.filter((c) => c.status === 0 && c.full_money <= base);
});

/* ---------- 积分 ---------- */
const score = ref(0);
const useScore = ref(false);

const submitting = ref(false);

const goodsAmount = computed(() => (goods.value ? goods.value.price * num.value : 0));

/* ---------- 满减促销（复刻后端 PromotionService::pickBest 择优） ---------- */
const promotions = ref<PromotionItem[]>([]);
const promotionCut = computed(() => {
  const amount = goodsAmount.value;
  const now = Math.floor(Date.now() / 1000);
  let best = 0;
  for (const p of promotions.value) {
    if (p.status !== 1 || p.start_time > now || p.end_time < now) continue;
    let cut = 0;
    if (p.type === 2) {
      // 满件折：件数达标后按 金额×(1-discount/10)
      if (p.full_num > 0 && num.value >= p.full_num) {
        cut = Math.round(amount * (1 - p.discount / 10) * 100) / 100;
      }
    } else if (p.type === 1 && amount >= p.full_money) {
      // 满额减
      cut = Math.round(p.cut_money * 100) / 100;
    }
    if (cut > best) best = cut;
  }
  return best;
});
const afterPromo = computed(() => Math.max(Math.round((goodsAmount.value - promotionCut.value) * 100) / 100, 0));

/** 优惠预估：后端口径为促销后金额上再算券（满减减 money、折扣乘 discount/10） */
const couponCut = computed(() => {
  const c = currentCoupon.value;
  if (!c) return 0;
  const base = afterPromo.value;
  if (c.type === 1) return Math.min(c.money, base);
  if (c.type === 2 && c.discount > 0 && c.discount < 10) {
    return Math.round(base * (1 - c.discount / 10) * 100) / 100;
  }
  return 0;
});
const estimatePay = computed(() => Math.max(Math.round((afterPromo.value - couponCut.value) * 100) / 100, 0.01));

onLoad((options) => {
  goodsId.value = Number(options && options.goods_id) || 0;
  num.value = Math.max(1, Number(options && options.num) || 1);
});

onShow(() => {
  if (goodsId.value > 0) {
    loadAll();
  }
});

async function loadAll() {
  try {
    const [g, addr, cp, sc, promo] = await Promise.all([
      fetchGoodsDetail(goodsId.value),
      fetchAddressList(),
      fetchMyCoupons(0),
      fetchMyScore().catch(() => ({ score: 0 })),
      fetchPromotionList().catch(() => ({ list: [] as PromotionItem[] })),
    ]);
    goods.value = g;
    addresses.value = addr || [];
    coupons.value = (cp && cp.list) || [];
    score.value = (sc && sc.score) || 0;
    promotions.value = (promo && promo.list) || [];
    // 默认地址：优先 is_default，其次第一个
    if (addressId.value === 0 && addresses.value.length > 0) {
      const def = addresses.value.find((a) => a.is_default === 1);
      addressId.value = (def || addresses.value[0]).id;
    }
    // 若当前券不可用了则清掉
    if (couponId.value > 0 && !usableCoupons.value.some((c) => c.coupon_id === couponId.value)) {
      couponId.value = 0;
    }
  } catch {
    // request 已弹 toast
  }
}

function pickAddress(a: AddressItem) {
  addressId.value = a.id;
  showAddress.value = false;
}

function goAddAddress() {
  showAddress.value = false;
  uni.navigateTo({ url: "/pagesM/address/edit" });
}

function goManageAddress() {
  showAddress.value = false;
  uni.navigateTo({ url: "/pagesM/address/list" });
}

function pickCoupon(c: CouponRecord | null) {
  couponId.value = c ? c.coupon_id : 0;
  showCoupon.value = false;
}

function couponLabel(c: CouponRecord): string {
  return c.type === 2 ? (c.discount > 0 ? c.discount + "折券" : "折扣券") : "满" + price2(c.full_money) + "减" + price2(c.money);
}

function onScoreChange(e: Event) {
  useScore.value = (e as unknown as { detail: { value: boolean } }).detail.value;
}

async function submit() {
  if (submitting.value) return;
  if (!currentAddress.value) {
    uni.showToast({ title: "请先选择收货地址", icon: "none" });
    return;
  }
  submitting.value = true;
  try {
    const result = await orderCreate({
      goods_id: goodsId.value,
      num: num.value,
      address_id: addressId.value,
      coupon_id: couponId.value,
      use_score: useScore.value ? score.value : 0,
    });
    uni.redirectTo({
      url: "/pagesM/pay/pay?order_id=" + result.id + "&amount=" + result.pay_amount,
    });
  } catch {
    // request 已弹 toast
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <view class="page">
    <!-- 地址卡片 -->
    <view class="address-card" @click="showAddress = true">
      <template v-if="currentAddress">
        <view class="addr-line">
          <text class="addr-name">{{ currentAddress.name }}</text>
          <text class="addr-phone">{{ currentAddress.phone }}</text>
        </view>
        <text class="addr-detail">
          {{ currentAddress.province }}{{ currentAddress.city }}{{ currentAddress.district }} {{ currentAddress.detail }}
        </text>
      </template>
      <template v-else>
        <text class="addr-empty">暂无收货地址，点击添加 ></text>
      </template>
      <text class="addr-arrow">></text>
    </view>

    <!-- 商品清单 -->
    <view v-if="goods" class="goods-card">
      <image v-if="goods.image" class="goods-img" :src="imgUrl(goods.image)" mode="aspectFill" />
      <view v-else class="goods-img goods-img-ph"><text>图</text></view>
      <view class="goods-meta">
        <text class="goods-name">{{ goods.name }}</text>
        <view class="row-bottom">
          <text class="price">￥{{ price2(goods.price) }}</text>
          <text class="num">x{{ num }}</text>
        </view>
      </view>
    </view>

    <!-- 优惠券 -->
    <view class="option-row" @click="showCoupon = true">
      <text class="option-label">优惠券</text>
      <text v-if="currentCoupon" class="option-value active">-￥{{ price2(couponCut) }}（{{ currentCoupon.name }}）</text>
      <text v-else-if="usableCoupons.length > 0" class="option-value active">{{ usableCoupons.length }} 张可用</text>
      <text v-else class="option-value">暂无可用</text>
      <text class="addr-arrow">></text>
    </view>

    <!-- 积分抵扣 -->
    <view class="option-row">
      <text class="option-label">积分抵扣（余额 {{ score }}）</text>
      <switch :checked="useScore" color="#7b7de5" style="transform: scale(0.8)" @change="onScoreChange" />
    </view>

    <!-- 金额预览 -->
    <view class="summary-card">
      <view class="sum-row"><text>商品金额</text><text>￥{{ price2(goodsAmount) }}</text></view>
      <view v-if="promotionCut > 0" class="sum-row"><text>满减促销（系统择优）</text><text>-￥{{ price2(promotionCut) }}</text></view>
      <view class="sum-row"><text>优惠券抵扣</text><text>-￥{{ price2(couponCut) }}</text></view>
      <view v-if="useScore" class="sum-row"><text>积分抵扣</text><text>以核算为准</text></view>
      <view class="sum-row total"><text>预估应付</text><text>￥{{ price2(estimatePay) }}</text></view>
      <view class="sum-note"><text>最终金额以下单结果为准</text></view>
    </view>

    <view class="bottom-space"></view>

    <!-- 提交栏 -->
    <view class="bottom-bar">
      <view class="total-wrap">
        <text class="total-label">合计：</text>
        <text class="total-price">￥{{ price2(estimatePay) }}</text>
      </view>
      <view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
        <text>{{ submitting ? "提交中..." : "提交订单" }}</text>
      </view>
    </view>

    <!-- 地址选择弹窗 -->
    <view v-if="showAddress" class="mask" @click="showAddress = false">
      <view class="popup" @click.stop>
        <view class="popup-title"><text>选择收货地址</text></view>
        <scroll-view class="popup-body" scroll-y>
          <view v-if="addresses.length === 0" class="popup-empty"><text>还没有收货地址</text></view>
          <view
            v-for="a in addresses"
            :key="a.id"
            class="addr-pick"
            :class="{ picked: a.id === addressId }"
            @click="pickAddress(a)"
          >
            <view class="addr-line">
              <text class="addr-name">{{ a.name }}</text>
              <text class="addr-phone">{{ a.phone }}</text>
              <text v-if="a.is_default === 1" class="addr-default-tag">默认</text>
            </view>
            <text class="addr-detail">{{ a.province }}{{ a.city }}{{ a.district }} {{ a.detail }}</text>
          </view>
        </scroll-view>
        <view class="popup-actions">
          <view class="popup-btn plain" @click="goManageAddress"><text>管理地址</text></view>
          <view class="popup-btn" @click="goAddAddress"><text>新增地址</text></view>
        </view>
      </view>
    </view>

    <!-- 优惠券选择弹窗 -->
    <view v-if="showCoupon" class="mask" @click="showCoupon = false">
      <view class="popup" @click.stop>
        <view class="popup-title"><text>选择优惠券</text></view>
        <scroll-view class="popup-body" scroll-y>
          <view class="addr-pick" :class="{ picked: couponId === 0 }" @click="pickCoupon(null)">
            <text class="coupon-name">不使用优惠券</text>
          </view>
          <view v-if="usableCoupons.length === 0" class="popup-empty"><text>暂无可用优惠券</text></view>
          <view
            v-for="c in usableCoupons"
            :key="c.id"
            class="addr-pick"
            :class="{ picked: c.coupon_id === couponId }"
            @click="pickCoupon(c)"
          >
            <view class="addr-line">
              <text class="coupon-name">{{ c.name }}</text>
              <text class="coupon-tag">{{ couponLabel(c) }}</text>
            </view>
          </view>
        </scroll-view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx;
  box-sizing: border-box;
}
.address-card {
  position: relative;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 60rpx 28rpx 28rpx;
}
.addr-line {
  display: flex;
  align-items: center;
}
.addr-name {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  margin-right: 20rpx;
}
.addr-phone {
  font-size: 26rpx;
  color: #777777;
}
.addr-default-tag {
  margin-left: 16rpx;
  font-size: 20rpx;
  color: #7b7de5;
  border: 1rpx solid #7b7de5;
  border-radius: 6rpx;
  padding: 2rpx 10rpx;
}
.addr-detail {
  display: block;
  margin-top: 12rpx;
  font-size: 26rpx;
  color: #777777;
  line-height: 38rpx;
}
.addr-empty {
  font-size: 28rpx;
  color: #999999;
}
.addr-arrow {
  position: absolute;
  right: 28rpx;
  top: 50%;
  transform: translateY(-50%);
  color: #cccccc;
  font-size: 28rpx;
}
.goods-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-top: 20rpx;
}
.goods-img {
  width: 150rpx;
  height: 150rpx;
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
  color: #7b7de5;
  font-size: 30rpx;
  font-weight: 600;
}
.num {
  font-size: 26rpx;
  color: #999999;
}
.option-row {
  display: flex;
  align-items: center;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
  margin-top: 20rpx;
  position: relative;
}
.option-label {
  flex: 1;
  font-size: 28rpx;
  color: #333333;
}
.option-value {
  font-size: 26rpx;
  color: #999999;
  margin-right: 20rpx;
}
.option-value.active {
  color: #7b7de5;
}
.summary-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
  margin-top: 20rpx;
}
.sum-row {
  display: flex;
  justify-content: space-between;
  font-size: 26rpx;
  color: #555555;
  padding: 10rpx 0;
}
.sum-row.total {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
  border-top: 1rpx solid #f2f2f2;
  margin-top: 12rpx;
  padding-top: 22rpx;
}
.sum-note {
  margin-top: 12rpx;
  font-size: 22rpx;
  color: #b5b5b5;
}
.bottom-space {
  height: 140rpx;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  border-top: 1rpx solid #eeeeee;
}
.total-wrap {
  flex: 1;
  display: flex;
  align-items: baseline;
}
.total-label {
  font-size: 26rpx;
  color: #555555;
}
.total-price {
  color: #7b7de5;
  font-size: 36rpx;
  font-weight: 700;
}
.submit-btn {
  padding: 18rpx 64rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 30rpx;
}
.submit-btn.disabled {
  opacity: 0.6;
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
  height: 640rpx;
  max-height: 60vh;
  overflow-y: auto;
  padding: 10rpx 24rpx;
  box-sizing: border-box;
}
.popup-empty {
  padding: 60rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.addr-pick {
  padding: 24rpx 16rpx;
  border-bottom: 1rpx solid #f5f5f5;
}
.addr-pick.picked {
  background: rgba(123, 125, 229, 0.08);
  border-radius: 12rpx;
}
.addr-pick .addr-detail {
  margin-top: 8rpx;
}
.coupon-name {
  font-size: 28rpx;
  color: #333333;
}
.coupon-tag {
  margin-left: 16rpx;
  font-size: 22rpx;
  color: #ff6034;
}
.popup-actions {
  display: flex;
  padding: 20rpx 24rpx;
}
.popup-btn {
  flex: 1;
  text-align: center;
  padding: 20rpx 0;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 28rpx;
}
.popup-btn.plain {
  background: #ffffff;
  color: #7b7de5;
  border: 1rpx solid #7b7de5;
  margin-right: 20rpx;
}
</style>
