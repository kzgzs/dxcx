<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchLuckyCollageProductDetail, luckyCollageOrderCreate } from "../../api/collage";
import type { LuckyCollageProductDetail } from "../../api/collage";
import { imgUrl, price2 } from "../../utils/format";
import { redirectTo } from "../../utils/navigate";
import { useSessionStore } from "../../stores/session";
import BizBuyBar from "../components/BizBuyBar.vue";

const session = useSessionStore();

const proid = ref(0);
const buytype = ref(2);
const teamid = ref(0);
const ggid = ref(0);
const num = ref(1);

const detail = ref<LuckyCollageProductDetail | null>(null);
const submitting = ref(false);

/* ---------- 收货信息 ---------- */
const linkman = ref("");
const tel = ref("");
const area = ref("");
const address = ref("");
const message = ref("");

const spec = computed(() => (detail.value && detail.value.options.find((o) => o.id === ggid.value)) || null);
const unitPrice = computed(() => (spec.value ? spec.value.sell_price : detail.value ? detail.value.sell_price : 0));
const productName = computed(() => (detail.value ? detail.value.name : ""));

/* ---------- 金额明细（与后端 apiCreateOrder 口径一致） ---------- */
const goodsAmount = computed(() => Math.round(unitPrice.value * num.value * 100) / 100);
const leaderCut = computed(() => {
  const p = detail.value;
  if (!p || buytype.value !== 2) return 0;
  return Math.min(Math.round(p.leadermoney * 100) / 100, goodsAmount.value);
});
const freight = 0;
const total = computed(() => Math.max(Math.round((goodsAmount.value - leaderCut.value + freight) * 100) / 100, 0));

const buytypeText = computed(() => (buytype.value === 2 ? "开团抽奖" : buytype.value === 3 ? "参与拼团" : "单独购买"));

onLoad((options) => {
  proid.value = Number(options && options.proid) || 0;
  buytype.value = Number(options && options.buytype) || 2;
  teamid.value = Number(options && options.teamid) || 0;
  ggid.value = Number(options && options.ggid) || 0;
  num.value = Math.max(1, Number(options && options.num) || 1);
  if (proid.value > 0) load();
});

async function load() {
  try {
    detail.value = await fetchLuckyCollageProductDetail(proid.value);
  } catch {
    // request 已弹 toast
  }
}

function toastTip(title: string) {
  uni.showToast({ title, icon: "none" });
}

async function submit() {
  if (submitting.value || !detail.value) return;
  if (!session.isLoggedIn) {
    toastTip("请先登录");
    return;
  }
  if (!linkman.value.trim()) {
    toastTip("请填写联系人");
    return;
  }
  if (!/^1\d{10}$/.test(tel.value.trim())) {
    toastTip("请填写正确的手机号");
    return;
  }
  if (!address.value.trim()) {
    toastTip("请填写收货地址");
    return;
  }
  submitting.value = true;
  try {
    const result = await luckyCollageOrderCreate({
      proid: proid.value,
      buytype: buytype.value,
      teamid: teamid.value,
      ggid: ggid.value,
      num: num.value,
      linkman: linkman.value.trim(),
      tel: tel.value.trim(),
      area: area.value.trim(),
      address: address.value.trim(),
      message: message.value.trim(),
    });
    // PAY_MOCK 下后端即时入账：status=1 已支付（满员还会即时开奖）
    uni.showToast({
      title: result.status === 1 ? "支付成功，等待开奖" : "下单成功，等待支付",
      icon: "none",
    });
    setTimeout(() => {
      redirectTo("/pagesH1/luckycollage/orderlist");
    }, 800);
  } catch {
    // request 已弹 toast
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <view class="page">
    <template v-if="detail">
      <!-- 商品与购买方式 -->
      <view class="goods-card">
        <image v-if="detail.pic" class="goods-img" :src="imgUrl(detail.pic)" mode="aspectFill" />
        <view v-else class="goods-img goods-img-ph"><text>图</text></view>
        <view class="goods-meta">
          <text class="goods-name">{{ productName }}</text>
          <text v-if="spec" class="goods-spec">规格：{{ spec.name }}</text>
          <view class="goods-price-row">
            <text class="goods-price">￥{{ price2(unitPrice) }}</text>
            <text class="goods-num">x{{ num }}</text>
          </view>
        </view>
      </view>

      <view class="mode-row">
        <text class="mode-label">购买方式</text>
        <text class="mode-value">{{ buytypeText }}</text>
      </view>

      <!-- 开奖提示 -->
      <view class="draw-tip">
        <text>{{ detail.teamnum }}人满员即开奖，{{ detail.gua_num }} 个中奖名额；中奖发货，未中奖按规则返还。</text>
      </view>

      <!-- 收货信息 -->
      <view class="form-card">
        <view class="form-row">
          <text class="form-label">联系人</text>
          <input v-model="linkman" class="form-input" placeholder="请输入联系人姓名" />
        </view>
        <view class="form-row">
          <text class="form-label">手机号</text>
          <input v-model="tel" class="form-input" type="number" maxlength="11" placeholder="请输入手机号" />
        </view>
        <view class="form-row">
          <text class="form-label">所在地区</text>
          <input v-model="area" class="form-input" placeholder="省市区（选填）" />
        </view>
        <view class="form-row">
          <text class="form-label">详细地址</text>
          <input v-model="address" class="form-input" placeholder="街道、门牌号等" />
        </view>
        <view class="form-row">
          <text class="form-label">买家留言</text>
          <input v-model="message" class="form-input" placeholder="选填，给商家留言" />
        </view>
      </view>

      <!-- 金额明细 -->
      <view class="summary-card">
        <view class="sum-row"><text>商品金额（{{ num }} 件）</text><text>￥{{ price2(goodsAmount) }}</text></view>
        <view v-if="leaderCut > 0" class="sum-row"><text>团长优惠</text><text>-￥{{ price2(leaderCut) }}</text></view>
        <view class="sum-row"><text>运费</text><text>{{ freight > 0 ? "￥" + price2(freight) : "包邮" }}</text></view>
        <view class="sum-row total"><text>合计</text><text>￥{{ price2(total) }}</text></view>
        <view class="sum-note"><text>测试环境 PAY_MOCK：提交即支付成功入账</text></view>
      </view>

      <view class="bottom-space"></view>

      <BizBuyBar :total="total" :submitting="submitting" :button-text="buytypeText" @submit="submit" />
    </template>
    <view v-else class="empty"><text>加载中...</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx;
  box-sizing: border-box;
}
.goods-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
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
.goods-spec {
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #999999;
}
.goods-price-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.goods-price {
  color: #7b7de5;
  font-size: 30rpx;
  font-weight: 600;
}
.goods-num {
  font-size: 24rpx;
  color: #999999;
}
.mode-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
  margin-top: 20rpx;
}
.mode-label {
  font-size: 28rpx;
  color: #333333;
}
.mode-value {
  font-size: 26rpx;
  color: #7b7de5;
  font-weight: 600;
}
.draw-tip {
  margin-top: 20rpx;
  background: #fff1ec;
  border-radius: 12rpx;
  padding: 20rpx 24rpx;
  font-size: 22rpx;
  color: #ff6034;
  line-height: 34rpx;
}
.form-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 8rpx 28rpx;
  margin-top: 20rpx;
}
.form-row {
  display: flex;
  align-items: center;
  padding: 24rpx 0;
  border-bottom: 1rpx solid #f5f5f5;
}
.form-row:last-child {
  border-bottom: none;
}
.form-label {
  width: 150rpx;
  font-size: 26rpx;
  color: #555555;
  flex-shrink: 0;
}
.form-input {
  flex: 1;
  font-size: 26rpx;
  color: #333333;
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
.empty {
  padding: 200rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
</style>
