<script setup lang="ts">
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchKanjiaJoins, kanjiaOrderCreate } from "../api/kanjia";
import { mockPay } from "../api/common";
import type { ContactInfo, KanjiaJoin } from "../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import ContactForm from "../components/ContactForm.vue";

const joinid = ref(0);
const join = ref<KanjiaJoin | null>(null);
const loaded = ref(false);

const contact = ref<ContactInfo>({ linkman: "", tel: "", area: "", address: "", message: "" });
const formRef = ref<InstanceType<typeof ContactForm> | null>(null);

const submitting = ref(false);
const done = ref(false);
const doneOrderNum = ref("");
const doneAmount = ref(0);

async function load() {
  if (joinid.value <= 0) {
    loaded.value = true;
    return;
  }
  try {
    // 后端未提供单条发起记录接口：从我的发起列表中定位
    const data = await fetchKanjiaJoins(1, 100);
    join.value = (data.list || []).find((j) => j.id === joinid.value) || null;
  } catch {
    // request 已弹 toast
  } finally {
    loaded.value = true;
  }
}

onLoad((options) => {
  joinid.value = Number(options && options.joinid) || 0;
  load();
});

async function submit() {
  if (submitting.value || !join.value) return;
  const err = formRef.value ? formRef.value.validate() : "";
  if (err) {
    uni.showToast({ title: err, icon: "none" });
    return;
  }
  submitting.value = true;
  try {
    const res = await kanjiaOrderCreate(joinid.value, contact.value);
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
    // request 已弹 toast（砍到底价后才能购买等）
  } finally {
    submitting.value = false;
  }
}

function goOrders() {
  uni.redirectTo({ url: "/pagesH2/kanjia/orderlist" });
}

function backToList() {
  uni.redirectTo({ url: "/pagesH2/kanjia/helplist" });
}
</script>

<template>
  <view class="page">
    <!-- 下单成功态 -->
    <view v-if="done" class="result-card">
      <view class="ok-icon"><text>✓</text></view>
      <text class="result-title">下单成功</text>
      <text class="result-sub">实付 ￥{{ price2(doneAmount) }}（PAY_MOCK 已入账）</text>
      <text class="result-sn">订单号：{{ doneOrderNum }}</text>
      <view class="result-btns">
        <view class="r-btn plain" @click="backToList"><text>返回砍价记录</text></view>
        <view class="r-btn" @click="goOrders"><text>查看砍价订单</text></view>
      </view>
    </view>

    <template v-else>
      <view v-if="!loaded" class="empty"><text>加载中...</text></view>
      <view v-else-if="!join" class="empty"><text>未找到对应的砍价记录</text></view>

      <template v-else>
        <!-- 砍价商品信息 -->
        <view class="pro-card">
          <image v-if="join.propic" class="pro-img" :src="imgUrl(join.propic)" mode="aspectFill" />
          <view v-else class="pro-img pro-img-ph"><text>图</text></view>
          <view class="pro-meta">
            <text class="pro-name">{{ join.proname || "砍价商品" }}</text>
            <view class="price-line">
              <text class="now-price">砍价后 ￥{{ price2(join.now_price) }}</text>
              <text v-if="join.sell_price !== undefined" class="sell-price">原价 ￥{{ price2(join.sell_price) }}</text>
            </view>
            <text class="pro-tip">
              {{ join.status === 1 ? "已砍到底价，可直接下单" : "未砍到底价时仅开启直接购买的商品可下单" }}
            </text>
          </view>
        </view>

        <ContactForm ref="formRef" v-model="contact" />

        <view class="amount-bar">
          <text class="amount-label">合计</text>
          <text class="amount">￥{{ price2(join.now_price) }}</text>
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
.pro-tip {
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #ff9f43;
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
