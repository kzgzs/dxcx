<script setup lang="ts">
/**
 * 团购核销码页
 * - 展示到店核销码（大字号 + 复制），附订单号与核销说明
 * - 接口：tuangou/order/hexiaoCode?id=
 */
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchTuangouHexiaoCode } from "../api";
import { goPage } from "../../utils/navigate";

const orderId = ref(0);
const ordernum = ref("");
const code = ref("");
const status = ref(-1);
const loaded = ref(false);
const errMsg = ref("");

onLoad((options) => {
  orderId.value = Number(options?.id || 0);
  if (orderId.value > 0) load();
});

async function load() {
  try {
    const data = await fetchTuangouHexiaoCode(orderId.value);
    ordernum.value = data.ordernum;
    code.value = data.hexiao_code;
    status.value = data.status;
  } catch {
    errMsg.value = "核销码获取失败";
  } finally {
    loaded.value = true;
  }
}

/** 核销码是否有效（已支付/已发货/已完成） */
const valid = computed(() => [1, 2, 3].includes(status.value) && code.value !== "");

function copyCode() {
  if (code.value === "") return;
  uni.setClipboardData({
    data: code.value,
    success: () => uni.showToast({ title: "已复制核销码", icon: "success" }),
  });
}

function goDetail() {
  goPage("/pagesH3/tuangou/orderdetail?id=" + orderId.value);
}
</script>

<template>
  <view class="page">
    <template v-if="loaded && valid">
      <view class="code-card">
        <text class="code-title">到店核销码</text>
        <text class="code-value">{{ code }}</text>
        <view class="code-actions">
          <view class="act-btn" @click="copyCode"><text>复制核销码</text></view>
        </view>
      </view>

      <view class="card">
        <view class="info-row"><text class="info-label">订单号</text><text>{{ ordernum }}</text></view>
        <view class="tips-title"><text>核销说明</text></view>
        <text class="tip">1. 到店后请向店员出示此核销码；</text>
        <text class="tip">2. 店员核销后订单即完成，核销码失效；</text>
        <text class="tip">3. 请勿将核销码泄露给他人。</text>
      </view>
    </template>

    <view v-else-if="loaded" class="invalid">
      <text class="invalid-text">{{ errMsg !== "" ? errMsg : "当前订单暂无可用核销码" }}</text>
      <view class="act-btn" @click="goDetail"><text>查看订单详情</text></view>
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
.code-card {
  background: #ffffff;
  border-radius: 24rpx;
  padding: 64rpx 32rpx 48rpx;
  text-align: center;
}
.code-title {
  display: block;
  font-size: 26rpx;
  color: #999999;
}
.code-value {
  display: block;
  margin-top: 32rpx;
  font-size: 60rpx;
  font-weight: 700;
  color: #333333;
  letter-spacing: 8rpx;
  word-break: break-all;
}
.code-actions {
  margin-top: 48rpx;
  display: flex;
  justify-content: center;
}
.act-btn {
  padding: 16rpx 56rpx;
  border-radius: 36rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 28rpx;
}
.card {
  margin-top: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
}
.info-row {
  display: flex;
  font-size: 26rpx;
  color: #333333;
}
.info-label {
  width: 160rpx;
  color: #999999;
}
.tips-title {
  margin-top: 28rpx;
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
.tip {
  display: block;
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #999999;
  line-height: 1.6;
}
.invalid {
  padding: 160rpx 0;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.invalid-text {
  font-size: 26rpx;
  color: #999999;
  margin-bottom: 40rpx;
}
</style>
