<script setup lang="ts">
/**
 * 手机端后台-订单列表（对照参考项目 admin/order/shoporder 的状态 Tab 职责）
 * - GET /api/adminidx/orderlist?st=&page=（st='' 全部，0待付款 1待发货 2待收货 3已完成 4退款售后）
 * - 状态 Tab 切换 + 真实列表渲染 + 触底加载下一页
 * - 接口 404/500 显示空态不白屏；后端字段未定，按多候选字段容错取值
 */
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { ref } from "vue";
import { fetchAdminOrderList, fieldNum, fieldText, guardAdmin, normalizePage } from "../api";

/** 状态 Tab 配置（顺序对照参考页商城订单五态 + 全部） */
const statusTabs: Array<{ label: string; st: number | "" }> = [
  { label: "全部", st: "" },
  { label: "待付款", st: 0 },
  { label: "待发货", st: 1 },
  { label: "待收货", st: 2 },
  { label: "退款/售后", st: 4 },
  { label: "已完成", st: 3 },
];

/** 当前状态 / 分页 / 列表 / 状态位 */
const currentSt = ref<number | "">("");
const page = ref(1);
const list = ref<Record<string, unknown>[]>([]);
const total = ref(0);
const loading = ref(false);
/** 首次加载失败（404/500）→ 空态展示 */
const loadError = ref(false);
const finished = ref(false);

onLoad((options) => {
  if (!guardAdmin()) return;
  // 首页五计数跳转携带 st 参数
  const st = Number(options && options.st);
  if (options && options.st !== undefined && options.st !== "" && Number.isFinite(st)) {
    currentSt.value = st;
  }
  refresh();
});

/** 切换状态 Tab */
function switchTab(st: number | "") {
  if (currentSt.value === st) return;
  currentSt.value = st;
  refresh();
}

/** 重置并加载第一页 */
function refresh() {
  page.value = 1;
  list.value = [];
  total.value = 0;
  finished.value = false;
  loadError.value = false;
  void loadPage();
}

/** 加载当前页（失败静默：列表为空时展示空态） */
async function loadPage() {
  if (loading.value || finished.value) return;
  loading.value = true;
  try {
    const res = await fetchAdminOrderList(currentSt.value, page.value);
    const { list: rows, total: t } = normalizePage(res);
    list.value = page.value === 1 ? rows : list.value.concat(rows);
    total.value = t;
    // 无更多数据判定：本页空 或 已达 total
    if (rows.length === 0 || list.value.length >= t) finished.value = true;
    else page.value += 1;
  } catch {
    if (page.value === 1) loadError.value = true;
  } finally {
    loading.value = false;
  }
}

/** 触底加载下一页 */
onReachBottom(() => {
  void loadPage();
});

/** 行内字段容错取值（后端并行开发，字段名多候选） */
function orderNo(item: Record<string, unknown>): string {
  return fieldText(item, ["order_no", "ordersn", "order_sn", "no", "id"]);
}
function buyerName(item: Record<string, unknown>): string {
  return fieldText(item, ["nickname", "username", "name", "member_name"], "");
}
function money(item: Record<string, unknown>): string {
  return fieldText(item, ["money", "pay_money", "total_money", "price", "amount"], "0.00");
}
function statusText(item: Record<string, unknown>): string {
  const st = fieldNum(item, ["status", "st", "state"], -99);
  const custom = fieldText(item, ["status_text", "statustext"], "");
  if (custom) return custom;
  const map: Record<number, string> = { 0: "待付款", 1: "待发货", 2: "待收货", 3: "已完成", 4: "退款/售后" };
  return map[st] || "订单";
}
function createTime(item: Record<string, unknown>): string {
  return fieldText(item, ["createtime", "create_time", "addtime", "time"], "");
}
</script>

<template>
  <view class="page">
    <!-- 状态 Tab（横向滚动，对照参考页订单状态切换） -->
    <scroll-view class="tabs" scroll-x>
      <view
        v-for="tab in statusTabs"
        :key="String(tab.st)"
        class="tab"
        :class="{ on: currentSt === tab.st }"
        @click="switchTab(tab.st)"
      >
        {{ tab.label }}
      </view>
    </scroll-view>

    <!-- 列表 -->
    <view v-if="list.length > 0" class="list">
      <view v-for="(item, idx) in list" :key="idx" class="order-card">
        <view class="row-top">
          <text class="order-no">订单号：{{ orderNo(item) }}</text>
          <text class="order-status">{{ statusText(item) }}</text>
        </view>
        <view class="row-mid">
          <text v-if="buyerName(item)" class="buyer">{{ buyerName(item) }}</text>
          <text class="time">{{ createTime(item) }}</text>
        </view>
        <view class="row-bottom">
          <text class="money-label">金额</text>
          <text class="money">￥{{ money(item) }}</text>
        </view>
      </view>
      <!-- 加载状态提示 -->
      <view class="load-tip">
        <text v-if="loading">加载中...</text>
        <text v-else-if="finished">没有更多了</text>
      </view>
    </view>

    <!-- 空态（接口未就绪/无数据均展示，不白屏） -->
    <view v-else-if="!loading" class="empty">
      <text class="empty-icon">📭</text>
      <text class="empty-text">{{ loadError ? "数据加载失败，请稍后重试" : "暂无相关订单" }}</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.tabs {
  white-space: nowrap;
  background: #ffffff;
  padding: 0 12rpx;
  position: sticky;
  top: 0;
  z-index: 10;
}
.tab {
  display: inline-block;
  padding: 24rpx 26rpx;
  font-size: 28rpx;
  color: #666666;
  position: relative;
}
.tab.on {
  color: #7b7de5;
  font-weight: 600;
}
.tab.on::after {
  content: "";
  position: absolute;
  left: 50%;
  bottom: 6rpx;
  transform: translateX(-50%);
  width: 40rpx;
  height: 6rpx;
  border-radius: 3rpx;
  background: #7b7de5;
}
.list {
  padding: 20rpx 24rpx 0;
}
.order-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx 28rpx;
  margin-bottom: 20rpx;
}
.row-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.order-no {
  font-size: 26rpx;
  color: #333333;
  font-weight: 600;
}
.order-status {
  font-size: 24rpx;
  color: #7b7de5;
}
.row-mid {
  margin-top: 14rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.buyer {
  font-size: 24rpx;
  color: #666666;
}
.time {
  font-size: 22rpx;
  color: #999999;
}
.row-bottom {
  margin-top: 14rpx;
  display: flex;
  align-items: center;
  justify-content: flex-end;
}
.money-label {
  font-size: 24rpx;
  color: #999999;
  margin-right: 10rpx;
}
.money {
  font-size: 30rpx;
  font-weight: 600;
  color: #e64340;
}
.load-tip {
  text-align: center;
  font-size: 24rpx;
  color: #999999;
  padding: 20rpx 0 30rpx;
}
.empty {
  margin-top: 200rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16rpx;
}
.empty-icon {
  font-size: 72rpx;
}
.empty-text {
  font-size: 28rpx;
  color: #999999;
}
</style>
