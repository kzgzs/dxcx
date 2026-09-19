<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchSeckillDetail, seckillOrderCreate } from "../api/seckill";
import { mockPay } from "../api/common";
import type { ContactInfo, SeckillDetail, SeckillProdata } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";
import ContactForm from "../components/ContactForm.vue";

const proid = ref(0);
const prodataId = ref(0);
const ggid = ref(0);

const detail = ref<SeckillDetail | null>(null);
const loaded = ref(false);
const num = ref(1);

const contact = ref<ContactInfo>({ linkman: "", tel: "", area: "", address: "", message: "" });
const formRef = ref<InstanceType<typeof ContactForm> | null>(null);

const submitting = ref(false);
const done = ref(false);
const doneOrderNum = ref("");
const doneAmount = ref(0);

/** 选中的场次行 */
const session = computed<SeckillProdata | null>(() => {
  if (!detail.value) return null;
  return (detail.value.prodata || []).find((p) => p.id === prodataId.value) || null;
});

/** 选中规格名 */
const ggname = computed(() => {
  if (!detail.value || ggid.value <= 0) return "默认规格";
  const g = (detail.value.guige || []).find((x) => x.id === ggid.value);
  return g ? g.name : "默认规格";
});

/** 单价（优先场次价） */
const unitPrice = computed(() => {
  if (session.value) return session.value.seckill_price;
  return detail.value ? detail.value.sell_price : 0;
});

const totalPrice = computed(() => unitPrice.value * num.value);

/** 剩余可购库存 */
const leftStock = computed(() => {
  if (!session.value) return detail.value ? detail.value.stock : 0;
  return Math.max(0, session.value.seckill_num - session.value.sales);
});

/** 单笔限购（buymax>0 生效） */
const buyLimit = computed(() => {
  const limit = session.value ? session.value.buymax : 0;
  return limit > 0 ? limit : 0;
});

async function load() {
  if (proid.value <= 0) {
    loaded.value = true;
    return;
  }
  try {
    detail.value = await fetchSeckillDetail(proid.value);
  } catch {
    // request 已弹 toast
  } finally {
    loaded.value = true;
  }
}

onLoad((options) => {
  proid.value = Number(options && options.proid) || 0;
  prodataId.value = Number(options && options.prodata_id) || 0;
  ggid.value = Number(options && options.ggid) || 0;
  load();
});

function minus() {
  if (num.value > 1) num.value -= 1;
}

function plus() {
  if (buyLimit.value > 0 && num.value >= buyLimit.value) {
    uni.showToast({ title: "本场限购 " + buyLimit.value + " 件", icon: "none" });
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
  if (!session.value) {
    uni.showToast({ title: "请选择秒杀场次", icon: "none" });
    return;
  }
  const now = Math.floor(Date.now() / 1000);
  if (now < session.value.starttime) {
    uni.showToast({ title: "本场秒杀尚未开始", icon: "none" });
    return;
  }
  if (now > session.value.endtime) {
    uni.showToast({ title: "本场秒杀已结束", icon: "none" });
    return;
  }
  const err = formRef.value ? formRef.value.validate() : "";
  if (err) {
    uni.showToast({ title: err, icon: "none" });
    return;
  }
  submitting.value = true;
  try {
    const res = await seckillOrderCreate({
      proid: proid.value,
      prodata_id: prodataId.value,
      ggid: ggid.value,
      num: num.value,
      contact: contact.value,
    });
    doneAmount.value = res.totalprice;
    doneOrderNum.value = res.ordernum;
    if (res.status === 1) {
      done.value = true;
    } else {
      // PAY_MOCK 模拟支付入账
      await mockPay(res.ordernum);
      done.value = true;
    }
  } catch {
    // request 已弹 toast（库存/限购/场次校验等）
  } finally {
    submitting.value = false;
  }
}

function goOrders() {
  uni.redirectTo({ url: "/pagesH2/seckill/orderlist" });
}

function backToList() {
  uni.redirectTo({ url: "/pagesH2/seckill/list" });
}
</script>

<template>
  <view class="page">
    <!-- 下单成功态 -->
    <view v-if="done" class="result-card">
      <view class="ok-icon"><text>✓</text></view>
      <text class="result-title">抢购成功</text>
      <text class="result-sub">实付 ￥{{ price2(doneAmount) }}（PAY_MOCK 已入账）</text>
      <text class="result-sn">订单号：{{ doneOrderNum }}</text>
      <view class="result-btns">
        <view class="r-btn plain" @click="backToList"><text>返回秒杀列表</text></view>
        <view class="r-btn" @click="goOrders"><text>查看秒杀订单</text></view>
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
            <text class="pro-name">{{ detail.name || "秒杀商品" }}</text>
            <view class="price-line">
              <text class="now-price">秒杀价 ￥{{ price2(unitPrice) }}</text>
              <text class="sell-price">原价 ￥{{ price2(detail.market_price || detail.sell_price) }}</text>
            </view>
            <text class="pro-spec">规格：{{ ggname }}</text>
            <text v-if="session" class="pro-tip">剩余 {{ leftStock }} 件<template v-if="buyLimit > 0"> · 限购 {{ buyLimit }} 件</template></text>
          </view>
        </view>

        <!-- 购买数量 -->
        <view class="row-card">
          <text class="row-label">购买数量</text>
          <view class="stepper">
            <view class="step-btn" :class="{ off: num <= 1 }" @click="minus"><text>-</text></view>
            <text class="step-num">{{ num }}</text>
            <view class="step-btn" @click="plus"><text>+</text></view>
          </view>
        </view>

        <ContactForm ref="formRef" v-model="contact" />

        <view class="amount-bar">
          <text class="amount-label">合计</text>
          <text class="amount">￥{{ price2(totalPrice) }}</text>
        </view>

        <view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
          <text>{{ submitting ? "提交中..." : "提交订单" }}</text>
        </view>
        <view class="submit-note"><text>PAY_MOCK 环境：提交后自动模拟支付成功</text></view>
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
.now-price {
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
.pro-spec {
  display: block;
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #666666;
}
.pro-tip {
  display: block;
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #ff9f43;
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
  margin-top: 24rpx;
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
.amount {
  font-size: 40rpx;
  font-weight: 700;
  color: #ff4d4f;
}
.submit-btn {
  margin-top: 40rpx;
  padding: 24rpx 0;
  background: #ff4d4f;
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
  color: #ff4d4f;
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
  background: #7b7de5;
  color: #ffffff;
  border-radius: 40rpx;
  font-size: 28rpx;
}
.r-btn.plain {
  background: #ffffff;
  color: #7b7de5;
  border: 1rpx solid #7b7de5;
  margin-right: 20rpx;
}
</style>
