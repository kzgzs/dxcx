<script setup lang="ts">
/**
 * 团购订单详情
 * - 状态头 + 商品信息 + 金额明细
 * - 核销码展示（到店单且已支付/发货/完成）
 * - 在线卡密列表（codes）
 * - 物流/联系人信息 + 时间轴
 * - 操作：核销码/确认收货/评价/申请退款/删除
 */
import { computed, ref } from "vue";
import { onLoad, onShow } from "@dcloudio/uni-app";
import {
  fetchTuangouOrderDetail,
  tuangouOrderCollect,
  tuangouOrderDelete,
} from "../api";
import type { TuangouOrderDetail } from "../api";
import { fmtTime, imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const orderId = ref(0);
const detail = ref<TuangouOrderDetail | null>(null);
const loaded = ref(false);

onLoad((options) => {
  orderId.value = Number(options?.id || 0);
  if (orderId.value > 0) load();
});

onShow(() => {
  if (loaded.value && orderId.value > 0) load();
});

async function load() {
  try {
    detail.value = await fetchTuangouOrderDetail(orderId.value);
  } catch {
    detail.value = null;
  } finally {
    loaded.value = true;
  }
}

/** 到店核销单且已支付可展示核销码 */
const showHexiao = computed(() => {
  if (!detail.value) return false;
  return detail.value.freight_type === 1 && [1, 2, 3].includes(detail.value.status);
});

const hasCodes = computed(() => !!detail.value && detail.value.codes && detail.value.codes.length > 0);

const statusBg = computed(() => {
  const s = detail.value?.status;
  if (s === 4) return "linear-gradient(90deg,#b5b5c5,#9a9aa8)";
  return "linear-gradient(90deg,#9b9df0,#7b7de5)";
});

function goHexiao() {
  goPage("/pagesH3/tuangou/hexiao?id=" + orderId.value);
}

function goRefund() {
  goPage("/pagesH3/tuangou/refund?id=" + orderId.value);
}

function goComment() {
  if (!detail.value) return;
  goPage(
    "/pagesH3/tuangou/comment?id=" + orderId.value + "&name=" + encodeURIComponent(detail.value.proname)
  );
}

async function collect() {
  try {
    await tuangouOrderCollect(orderId.value);
    uni.showToast({ title: "已确认收货", icon: "success" });
    load();
  } catch {
    // 已提示
  }
}

function removeOrder() {
  uni.showModal({
    title: "提示",
    content: "确定删除该订单吗？",
    success: async (res) => {
      if (!res.confirm) return;
      try {
        await tuangouOrderDelete(orderId.value);
        uni.showToast({ title: "删除成功", icon: "success" });
        setTimeout(() => {
          goPage("/pagesH3/tuangou/orderlist");
        }, 500);
      } catch {
        // 已提示
      }
    },
  });
}

function copyCode(code: string) {
  uni.setClipboardData({
    data: code,
    success: () => uni.showToast({ title: "已复制", icon: "success" }),
  });
}
</script>

<template>
  <view class="page">
    <template v-if="detail">
      <!-- 状态头 -->
      <view class="status-head" :style="{ background: statusBg }">
        <text class="status-text">{{ detail.refund_status > 0 ? detail.refund_text : detail.status_text }}</text>
        <text class="status-sub">{{ detail.freight_type_text || detail.freight_text }}</text>
      </view>

      <!-- 核销码卡片 -->
      <view v-if="showHexiao" class="card hexiao-card" @click="goHexiao">
        <view class="hexiao-left">
          <text class="hexiao-label">到店核销码</text>
          <text class="hexiao-code">{{ detail.hexiao_code }}</text>
        </view>
        <text class="hexiao-more">查看 ›</text>
      </view>

      <!-- 卡密列表 -->
      <view v-if="hasCodes" class="card">
        <view class="card-title"><text>卡密信息</text></view>
        <view v-for="(code, i) in detail.codes" :key="i" class="code-row" @click="copyCode(code)">
          <text class="code-text">{{ code }}</text>
          <text class="code-copy">复制</text>
        </view>
      </view>

      <!-- 商品 -->
      <view class="card">
        <view class="pro-row">
          <image v-if="detail.propic" class="pro-img" :src="imgUrl(detail.propic)" mode="aspectFill" />
          <view v-else class="pro-img pro-img-ph"><text>图</text></view>
          <view class="pro-meta">
            <text class="pro-name">{{ detail.proname }}</text>
            <text class="pro-sub">￥{{ price2(detail.sell_price) }} x{{ detail.num }}</text>
          </view>
        </view>
        <view class="amount-row">
          <text>商品金额</text>
          <text>￥{{ price2(detail.product_price) }}</text>
        </view>
        <view class="amount-row">
          <text>运费（{{ detail.freight_text || detail.freight_type_text }}）</text>
          <text>￥{{ price2(detail.freight_price) }}</text>
        </view>
        <view class="amount-row total">
          <text>实付合计</text>
          <text class="total-price">￥{{ price2(detail.totalprice) }}</text>
        </view>
        <view v-if="detail.refund_status === 2" class="amount-row refund">
          <text>已退款</text>
          <text>￥{{ price2(detail.refund_money) }}</text>
        </view>
      </view>

      <!-- 联系/收货信息 -->
      <view class="card">
        <view class="card-title"><text>{{ detail.freight_type === 0 ? "收货信息" : "联系信息" }}</text></view>
        <view v-if="detail.linkman" class="info-row">
          <text class="info-label">联系人</text>
          <text>{{ detail.linkman }}</text>
        </view>
        <view v-if="detail.tel" class="info-row">
          <text class="info-label">电话</text>
          <text>{{ detail.tel }}</text>
        </view>
        <view v-if="detail.address" class="info-row">
          <text class="info-label">地址</text>
          <text>{{ detail.address }}</text>
        </view>
        <view v-if="detail.message" class="info-row">
          <text class="info-label">留言</text>
          <text>{{ detail.message }}</text>
        </view>
        <view v-if="detail.express_no" class="info-row">
          <text class="info-label">快递</text>
          <text>{{ detail.express_com }} {{ detail.express_no }}</text>
        </view>
        <view v-if="!detail.linkman && !detail.tel && !detail.address && !detail.message && !detail.express_no">
          <text class="info-none">无</text>
        </view>
      </view>

      <!-- 时间轴 -->
      <view class="card">
        <view class="card-title"><text>订单信息</text></view>
        <view class="info-row"><text class="info-label">订单号</text><text>{{ detail.ordernum }}</text></view>
        <view class="info-row"><text class="info-label">下单时间</text><text>{{ fmtTime(detail.createtime) }}</text></view>
        <view v-if="detail.paytime > 0" class="info-row">
          <text class="info-label">支付时间</text>
          <text>{{ fmtTime(detail.paytime) }}</text>
        </view>
        <view v-if="detail.send_time > 0" class="info-row">
          <text class="info-label">发货时间</text>
          <text>{{ fmtTime(detail.send_time) }}</text>
        </view>
        <view v-if="detail.collect_time > 0" class="info-row">
          <text class="info-label">完成时间</text>
          <text>{{ fmtTime(detail.collect_time) }}</text>
        </view>
        <view v-if="detail.refund_reason" class="info-row">
          <text class="info-label">退款原因</text>
          <text>{{ detail.refund_reason }}</text>
        </view>
      </view>

      <view class="footer-holder"></view>

      <!-- 操作栏 -->
      <view class="footer">
        <view v-if="detail.status === 4" class="act-btn" @click="removeOrder"><text>删除订单</text></view>
        <view v-if="showHexiao" class="act-btn primary" @click="goHexiao"><text>核销码</text></view>
        <view v-if="detail.status === 2" class="act-btn primary" @click="collect"><text>确认收货</text></view>
        <view v-if="detail.status === 3 && detail.iscomment === 0" class="act-btn primary" @click="goComment">
          <text>评价</text>
        </view>
        <view v-if="detail.status === 1 && detail.refund_status === 0" class="act-btn" @click="goRefund">
          <text>申请退款</text>
        </view>
      </view>
    </template>
    <view v-else-if="loaded" class="empty-tip"><text>订单不存在</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.status-head {
  padding: 48rpx 32rpx;
  color: #ffffff;
}
.status-text {
  display: block;
  font-size: 38rpx;
  font-weight: 700;
}
.status-sub {
  display: block;
  margin-top: 10rpx;
  font-size: 24rpx;
  opacity: 0.85;
}
.card {
  margin: 20rpx 24rpx 0;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
}
.card-title {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  margin-bottom: 16rpx;
}
.hexiao-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(90deg, #fff7ef, #ffffff);
  border: 1rpx solid #ffd9b8;
}
.hexiao-label {
  display: block;
  font-size: 24rpx;
  color: #ff8a3d;
}
.hexiao-code {
  display: block;
  margin-top: 8rpx;
  font-size: 36rpx;
  font-weight: 700;
  color: #333333;
  letter-spacing: 4rpx;
}
.hexiao-more {
  font-size: 24rpx;
  color: #ff8a3d;
}
.code-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14rpx 0;
  border-bottom: 1rpx solid #f5f5f7;
}
.code-row:last-child {
  border-bottom: none;
}
.code-text {
  font-size: 28rpx;
  color: #333333;
  letter-spacing: 2rpx;
}
.code-copy {
  font-size: 22rpx;
  color: #7b7de5;
}
.pro-row {
  display: flex;
  padding-bottom: 16rpx;
  border-bottom: 1rpx solid #f5f5f7;
}
.pro-img {
  width: 120rpx;
  height: 120rpx;
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
.pro-sub {
  display: block;
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #999999;
}
.amount-row {
  display: flex;
  justify-content: space-between;
  padding: 10rpx 0;
  font-size: 26rpx;
  color: #666666;
}
.amount-row.total {
  border-top: 1rpx solid #f5f5f7;
  margin-top: 8rpx;
  padding-top: 16rpx;
  color: #333333;
  font-weight: 600;
}
.total-price {
  color: #ff4d4f;
  font-size: 30rpx;
}
.amount-row.refund {
  color: #ff8a3d;
}
.info-row {
  display: flex;
  padding: 8rpx 0;
  font-size: 26rpx;
  color: #333333;
}
.info-label {
  width: 160rpx;
  color: #999999;
  flex-shrink: 0;
}
.info-none {
  font-size: 24rpx;
  color: #c5c5cf;
}
.footer-holder {
  height: 140rpx;
}
.footer {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  background: #ffffff;
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding: 20rpx 24rpx;
  box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.act-btn {
  margin-left: 16rpx;
  padding: 14rpx 40rpx;
  border: 1rpx solid #d9d9e3;
  border-radius: 32rpx;
  font-size: 26rpx;
  color: #666666;
}
.act-btn.primary {
  border-color: #7b7de5;
  background: #7b7de5;
  color: #ffffff;
}
.empty-tip {
  padding: 200rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
</style>
