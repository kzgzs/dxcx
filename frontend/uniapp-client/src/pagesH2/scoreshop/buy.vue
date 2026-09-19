<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchMyScoreBalance, fetchScoreshopDetail, scoreshopOrderCreate } from "../api/scoreshop";
import { mockPay } from "../api/common";
import type { ContactInfo, ScoreshopDetail } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";
import ContactForm from "../components/ContactForm.vue";

const proid = ref(0);
const ggid = ref(0);

const detail = ref<ScoreshopDetail | null>(null);
const loaded = ref(false);
const num = ref(1);
const myScore = ref<number | null>(null);

const contact = ref<ContactInfo>({ linkman: "", tel: "", area: "", address: "", message: "" });
const formRef = ref<InstanceType<typeof ContactForm> | null>(null);

const submitting = ref(false);
const done = ref(false);
const doneOrderNum = ref("");
const doneScore = ref(0);
const doneMoney = ref(0);

/** 选中规格行（0=商品默认价） */
const curGg = computed(() => {
  if (!detail.value || ggid.value <= 0) return null;
  return (detail.value.guige || []).find((g) => g.id === ggid.value) || null;
});

const unitScore = computed(() =>
  curGg.value ? curGg.value.score_price : detail.value ? detail.value.score_price : 0
);
const unitMoney = computed(() =>
  curGg.value ? curGg.value.money_price : detail.value ? detail.value.money_price : 0
);
const ggName = computed(() => (curGg.value ? curGg.value.name : "默认规格"));

/** 需扣积分合计 */
const totalScore = computed(() => unitScore.value * num.value);
/** 需付现金合计 */
const totalMoney = computed(() => unitMoney.value * num.value);

/** 剩余库存 */
const leftStock = computed(() => (curGg.value ? curGg.value.stock : detail.value ? detail.value.stock : 0));

/** 限购（buymax>0 生效） */
const buyLimit = computed(() => (detail.value && detail.value.buymax > 0 ? detail.value.buymax : 0));

/** 积分是否充足（余额未知时不拦截，交由后端校验） */
const scoreEnough = computed(() => myScore.value === null || myScore.value >= totalScore.value);

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
  ggid.value = Number(options && options.ggid) || 0;
  load();
  loadScore();
});

function minus() {
  if (num.value > 1) num.value -= 1;
}

function plus() {
  if (buyLimit.value > 0 && num.value >= buyLimit.value) {
    uni.showToast({ title: "限兑 " + buyLimit.value + " 件", icon: "none" });
    return;
  }
  if (num.value >= leftStock.value) {
    uni.showToast({ title: "库存不足", icon: "none" });
    return;
  }
  num.value += 1;
}

async function submit() {
  if (submitting.value || !detail.value) return;
  if (!scoreEnough.value) {
    uni.showToast({ title: "积分不足，无法兑换", icon: "none" });
    return;
  }
  const err = formRef.value ? formRef.value.validate() : "";
  if (err) {
    uni.showToast({ title: err, icon: "none" });
    return;
  }
  submitting.value = true;
  try {
    const res = await scoreshopOrderCreate({
      proid: proid.value,
      ggid: ggid.value,
      num: num.value,
      contact: contact.value,
    });
    doneScore.value = res.totalscore;
    doneMoney.value = res.totalmoney;
    doneOrderNum.value = res.ordernum;
    if (res.status === 1) {
      // 纯积分：同事务直扣即已付
      done.value = true;
    } else {
      // 积分+现金：PAY_MOCK 模拟支付现金部分
      await mockPay(res.ordernum);
      done.value = true;
    }
  } catch {
    // request 已弹 toast（积分不足/库存/限购等）
  } finally {
    submitting.value = false;
  }
}

function goOrders() {
  uni.redirectTo({ url: "/pagesH2/scoreshop/orderlist" });
}

function backToList() {
  uni.redirectTo({ url: "/pagesH2/scoreshop/list" });
}
</script>

<template>
  <view class="page">
    <!-- 兑换成功态 -->
    <view v-if="done" class="result-card">
      <view class="ok-icon"><text>✓</text></view>
      <text class="result-title">兑换成功</text>
      <text class="result-sub">消耗 {{ doneScore }} 积分<template v-if="Number(doneMoney) > 0"> + ￥{{ price2(doneMoney) }}</template></text>
      <text class="result-sn">订单号：{{ doneOrderNum }}</text>
      <view class="result-btns">
        <view class="r-btn plain" @click="backToList"><text>返回积分商城</text></view>
        <view class="r-btn" @click="goOrders"><text>查看兑换订单</text></view>
      </view>
    </view>

    <template v-else>
      <view v-if="!loaded" class="empty"><text>加载中...</text></view>
      <view v-else-if="!detail" class="empty"><text>商品不存在或已下架</text></view>

      <template v-else>
        <!-- 商品信息 -->
        <view class="pro-card">
          <image v-if="detail.pic" class="pro-img" :src="imgUrl(detail.pic)" mode="aspectFill" />
          <view v-else class="pro-img pro-img-ph"><text>图</text></view>
          <view class="pro-meta">
            <text class="pro-name">{{ detail.name || "积分商品" }}</text>
            <view class="price-line">
              <text class="score-price">{{ unitScore }} 积分</text>
              <text v-if="Number(unitMoney) > 0" class="money-price">+￥{{ price2(unitMoney) }}</text>
            </view>
            <text class="pro-spec">规格：{{ ggName }} · 库存 {{ leftStock }}</text>
          </view>
        </view>

        <!-- 积分余额 -->
        <view class="row-card">
          <text class="row-label">我的积分</text>
          <text class="row-value" :class="{ short: !scoreEnough }">
            {{ myScore === null ? "--" : myScore }}
          </text>
        </view>

        <!-- 兑换数量 -->
        <view class="row-card">
          <text class="row-label">兑换数量</text>
          <view class="stepper">
            <view class="step-btn" :class="{ off: num <= 1 }" @click="minus"><text>-</text></view>
            <text class="step-num">{{ num }}</text>
            <view class="step-btn" @click="plus"><text>+</text></view>
          </view>
        </view>

        <ContactForm ref="formRef" v-model="contact" />

        <!-- 合计：积分 + 现金 -->
        <view class="amount-bar">
          <text class="amount-label">合计</text>
          <view class="amount-box">
            <text class="amount-score">{{ totalScore }} 积分</text>
            <text v-if="Number(totalMoney) > 0" class="amount-money">+￥{{ price2(totalMoney) }}</text>
          </view>
        </view>
        <view v-if="!scoreEnough" class="warn-line"><text>当前积分不足，还差 {{ totalScore - (myScore || 0) }} 积分</text></view>

        <view class="submit-btn" :class="{ disabled: submitting || !scoreEnough }" @click="submit">
          <text>{{ submitting ? "提交中..." : "确认兑换" }}</text>
        </view>
        <view class="submit-note">
          <text>{{ Number(totalMoney) > 0 ? "扣积分后现金部分将自动模拟支付（PAY_MOCK）" : "纯积分兑换：提交后立即扣减积分并视为已支付" }}</text>
        </view>
      </template>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx;
  box-sizing: border-box;
}
.empty {
  padding: 120rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
.pro-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 24rpx;
}
.pro-img {
  width: 150rpx;
  height: 150rpx;
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
}
.pro-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 27rpx;
  font-weight: 600;
  color: #333333;
}
.price-line {
  margin-top: 12rpx;
  display: flex;
  align-items: baseline;
}
.score-price {
  color: #ff9f43;
  font-size: 32rpx;
  font-weight: 700;
}
.money-price {
  margin-left: 10rpx;
  color: #ff4d4f;
  font-size: 24rpx;
}
.pro-spec {
  display: block;
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #999999;
}
.row-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 24rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.row-label {
  font-size: 28rpx;
  color: #333333;
}
.row-value {
  font-size: 30rpx;
  font-weight: 600;
  color: #ff9f43;
}
.row-value.short {
  color: #ff4d4f;
}
.stepper {
  display: flex;
  align-items: center;
}
.step-btn {
  width: 56rpx;
  height: 56rpx;
  border-radius: 10rpx;
  background: #f2f2f5;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #333333;
  font-size: 32rpx;
}
.step-btn.off {
  color: #c5c5cf;
}
.step-num {
  width: 80rpx;
  text-align: center;
  font-size: 30rpx;
  color: #333333;
}
.amount-bar {
  margin-top: 4rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.amount-label {
  font-size: 28rpx;
  color: #333333;
}
.amount-box {
  display: flex;
  align-items: baseline;
}
.amount-score {
  font-size: 38rpx;
  font-weight: 700;
  color: #ff9f43;
}
.amount-money {
  margin-left: 10rpx;
  font-size: 32rpx;
  font-weight: 700;
  color: #ff4d4f;
}
.warn-line {
  margin-top: 14rpx;
  text-align: right;
  font-size: 22rpx;
  color: #ff4d4f;
}
.submit-btn {
  margin-top: 36rpx;
  padding: 24rpx 0;
  background: #ff9f43;
  color: #ffffff;
  text-align: center;
  border-radius: 48rpx;
  font-size: 32rpx;
}
.submit-btn.disabled {
  opacity: 0.6;
}
.submit-note {
  margin-top: 20rpx;
  text-align: center;
  color: #b5b5b5;
  font-size: 22rpx;
}
.result-card {
  margin-top: 100rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 80rpx 28rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.ok-icon {
  width: 100rpx;
  height: 100rpx;
  border-radius: 50%;
  background: #22c07a;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  font-size: 56rpx;
}
.result-title {
  margin-top: 28rpx;
  font-size: 36rpx;
  font-weight: 600;
  color: #333333;
}
.result-sub {
  margin-top: 14rpx;
  font-size: 26rpx;
  color: #ff9f43;
}
.result-sn {
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #999999;
}
.result-btns {
  margin-top: 60rpx;
  display: flex;
  width: 100%;
}
.r-btn {
  flex: 1;
  text-align: center;
  padding: 20rpx 0;
  background: #ff9f43;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 28rpx;
}
.r-btn.plain {
  background: #ffffff;
  color: #ff9f43;
  border: 1rpx solid #ff9f43;
  margin-right: 20rpx;
}
</style>
