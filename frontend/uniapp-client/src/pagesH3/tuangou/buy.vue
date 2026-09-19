<script setup lang="ts">
/**
 * 团购下单页
 * - 商品摘要 + 数量步进（限购提示）
 * - 配送方式：freighttype 1 到店核销 / 0 快递（联系人+地址）/ 3 自动发货 / 4 在线卡密（联系人+手机号）
 * - 提交 order/create，PAY_MOCK 下后端即时入账 status=1，直接跳订单详情
 */
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchTuangouProductDetail, tuangouOrderCreate } from "../api";
import type { TuangouProductDetail } from "../api";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const proId = ref(0);
const detail = ref<TuangouProductDetail | null>(null);
const loaded = ref(false);
const num = ref(1);
const linkman = ref("");
const tel = ref("");
const address = ref("");
const message = ref("");
const submitting = ref(false);

onLoad((options) => {
  proId.value = Number(options?.id || 0);
  if (proId.value > 0) load();
});

async function load() {
  try {
    detail.value = await fetchTuangouProductDetail(proId.value);
  } catch {
    detail.value = null;
  } finally {
    loaded.value = true;
  }
}

/** 0 快递 1 到店核销 3 自动发货 4 在线卡密 */
const freightType = computed(() => {
  if (!detail.value) return 0;
  const ft = Number(detail.value.freighttype);
  return ft === 1 || ft === 3 || ft === 4 ? ft : 0;
});

const freightLabel = computed(() => {
  switch (freightType.value) {
    case 1:
      return "到店核销（付款后凭核销码到店使用）";
    case 3:
      return "自动发货（付款后自动发放）";
    case 4:
      return "在线卡密（付款后查看卡密）";
    default:
      return "快递发货";
  }
});

/** 是否需要收货地址（快递单） */
const needAddress = computed(() => freightType.value === 0);
/** 是否强制联系人（虚拟商品 + contact_require=1） */
const needContact = computed(
  () => Number(detail.value?.contact_require || 0) === 1 && (freightType.value === 3 || freightType.value === 4)
);

const totalPrice = computed(() => {
  if (!detail.value) return 0;
  return detail.value.nowprice * num.value;
});

function minus() {
  if (num.value > 1) num.value -= 1;
}

function plus() {
  const limit = Number(detail.value?.perlimit || 0);
  if (limit > 0 && num.value >= limit) {
    uni.showToast({ title: "每人限购 " + limit + " 件", icon: "none" });
    return;
  }
  const stock = Number(detail.value?.stock || 0);
  if (stock > 0 && num.value >= stock) {
    uni.showToast({ title: "库存不足", icon: "none" });
    return;
  }
  num.value += 1;
}

async function submit() {
  if (!detail.value || submitting.value) return;
  if (needAddress.value || needContact.value) {
    if (linkman.value.trim() === "") {
      uni.showToast({ title: "请填写联系人", icon: "none" });
      return;
    }
    if (tel.value.trim() === "") {
      uni.showToast({ title: "请填写联系电话", icon: "none" });
      return;
    }
  }
  if (needAddress.value && address.value.trim() === "") {
    uni.showToast({ title: "请填写收货地址", icon: "none" });
    return;
  }
  submitting.value = true;
  try {
    const res = await tuangouOrderCreate({
      proid: proId.value,
      num: num.value,
      linkman: linkman.value.trim(),
      tel: tel.value.trim(),
      address: address.value.trim(),
      message: message.value.trim(),
      freight_type: freightType.value,
      freight_price: 0,
    });
    uni.showToast({ title: res.status === 1 ? "支付成功" : "下单成功", icon: "success" });
    setTimeout(() => {
      goPage("/pagesH3/tuangou/orderdetail?id=" + res.orderid);
    }, 600);
  } catch {
    // request 层已弹错误提示
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <view class="page">
    <template v-if="detail">
      <!-- 商品摘要 -->
      <view class="pro-card">
        <image v-if="detail.pic" class="pro-img" :src="imgUrl(detail.pic)" mode="aspectFill" />
        <view v-else class="pro-img pro-img-ph"><text>图</text></view>
        <view class="pro-meta">
          <text class="pro-name">{{ detail.name }}</text>
          <view class="price-row">
            <text class="price">￥{{ price2(detail.nowprice) }}</text>
            <text v-if="detail.market_price > detail.nowprice" class="market">
              ￥{{ price2(detail.market_price) }}
            </text>
          </view>
          <text class="freight-label">{{ freightLabel }}</text>
        </view>
      </view>

      <!-- 数量 -->
      <view class="card row-card">
        <text class="row-label">购买数量</text>
        <view class="stepper">
          <view class="step-btn" :class="{ disabled: num <= 1 }" @click="minus"><text>-</text></view>
          <text class="step-num">{{ num }}</text>
          <view class="step-btn" @click="plus"><text>+</text></view>
        </view>
      </view>
      <view v-if="detail.perlimit > 0" class="limit-tip"><text>每人限购 {{ detail.perlimit }} 件</text></view>

      <!-- 联系信息 -->
      <view v-if="needAddress || needContact" class="card">
        <view class="form-row">
          <text class="row-label">联系人</text>
          <input v-model="linkman" class="form-input" placeholder="请输入联系人姓名" />
        </view>
        <view class="form-row">
          <text class="row-label">联系电话</text>
          <input v-model="tel" class="form-input" type="number" placeholder="请输入手机号" />
        </view>
        <view v-if="needAddress" class="form-row">
          <text class="row-label">收货地址</text>
          <input v-model="address" class="form-input" placeholder="请输入详细收货地址" />
        </view>
      </view>

      <!-- 留言 -->
      <view class="card">
        <text class="row-label">买家留言</text>
        <textarea v-model="message" class="form-textarea" placeholder="选填，给商家留言" maxlength="200" />
      </view>

      <view class="footer-holder"></view>

      <!-- 底部提交栏 -->
      <view class="footer">
        <view class="footer-total">
          <text class="footer-label">合计：</text>
          <text class="footer-price">￥{{ price2(totalPrice) }}</text>
        </view>
        <view class="footer-btn" :class="{ disabled: submitting }" @click="submit">
          <text>提交订单</text>
        </view>
      </view>
    </template>
    <view v-else-if="loaded" class="empty-tip"><text>商品不存在或已下架</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx 24rpx 0;
  box-sizing: border-box;
}
.card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
  margin-bottom: 20rpx;
}
.pro-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
}
.pro-img {
  width: 180rpx;
  height: 180rpx;
  border-radius: 12rpx;
  background: #f2f2f5;
  flex-shrink: 0;
}
.pro-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.pro-meta {
  flex: 1;
  margin-left: 20rpx;
  min-width: 0;
}
.pro-name {
  display: block;
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
.price-row {
  margin-top: 14rpx;
  display: flex;
  align-items: baseline;
}
.price {
  color: #ff4d4f;
  font-size: 36rpx;
  font-weight: 700;
}
.market {
  margin-left: 12rpx;
  font-size: 22rpx;
  color: #b5b5b5;
  text-decoration: line-through;
}
.freight-label {
  display: block;
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #7b7de5;
}
.row-card {
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
  border-radius: 12rpx;
  background: #f2f2f5;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #333333;
  font-size: 32rpx;
}
.step-btn.disabled {
  color: #c5c5cf;
}
.step-num {
  width: 80rpx;
  text-align: center;
  font-size: 30rpx;
  color: #333333;
}
.limit-tip {
  margin: -8rpx 8rpx 20rpx;
  font-size: 22rpx;
  color: #ff8a3d;
}
.form-row {
  display: flex;
  align-items: center;
  padding: 16rpx 0;
  border-bottom: 1rpx solid #f5f5f7;
}
.form-row:last-child {
  border-bottom: none;
}
.form-input {
  flex: 1;
  margin-left: 24rpx;
  font-size: 26rpx;
  color: #333333;
  text-align: right;
}
.form-textarea {
  width: 100%;
  height: 140rpx;
  margin-top: 16rpx;
  background: #f7f7fa;
  border-radius: 12rpx;
  padding: 16rpx;
  font-size: 26rpx;
  color: #333333;
  box-sizing: border-box;
}
.footer-holder {
  height: 140rpx;
}
.footer {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  height: 110rpx;
  background: #ffffff;
  display: flex;
  align-items: center;
  padding: 0 24rpx;
  box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.footer-total {
  flex: 1;
  display: flex;
  align-items: baseline;
}
.footer-label {
  font-size: 26rpx;
  color: #666666;
}
.footer-price {
  color: #ff4d4f;
  font-size: 38rpx;
  font-weight: 700;
}
.footer-btn {
  height: 76rpx;
  padding: 0 64rpx;
  border-radius: 38rpx;
  background: linear-gradient(90deg, #9b9df0, #7b7de5);
  color: #ffffff;
  font-size: 30rpx;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
}
.footer-btn.disabled {
  opacity: 0.6;
}
.empty-tip {
  padding: 200rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
</style>
