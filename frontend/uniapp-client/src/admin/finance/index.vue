<script setup lang="ts">
/**
 * 手机端后台-财务（底部 Tab 页，对照参考项目 admin/finance/index 的概览职责）
 * - GET /api/adminidx/finances 财务概览
 * - 后端并行开发中，返回结构未定：对象按键值对渲染、数组按行渲染，
 *   任何异常展示空态不白屏
 */
import { onShow } from "@dcloudio/uni-app";
import { ref } from "vue";
import AdminTabbar from "../components/admin-tabbar.vue";
import { fetchAdminFinances, guardAdmin } from "../api";

/** 概览条目 */
interface FinanceItem {
  label: string;
  value: string;
}

/** 常见字段的中文标签映射（未命中的键原样展示） */
const LABEL_MAP: Record<string, string> = {
  today_money: "今日收款",
  yesterday_money: "昨日收款",
  month_money: "本月收款",
  total_money: "累计收款",
  balance: "账户余额",
  money: "账户余额",
  withdraw_money: "已提现",
  wait_money: "待结算",
  today_order: "今日订单",
  total_order: "累计订单",
  refund_money: "退款金额",
  income: "商家收入",
};

const items = ref<FinanceItem[]>([]);
const loading = ref(false);
/** 首次加载失败 → 空态 */
const loadError = ref(false);

onShow(() => {
  if (!guardAdmin()) return;
  loadData();
});

/** 加载财务数据并归一化为条目列表 */
function loadData() {
  loading.value = true;
  loadError.value = false;
  fetchAdminFinances()
    .then((res) => {
      items.value = normalize(res);
    })
    .catch(() => {
      items.value = [];
      loadError.value = true;
    })
    .finally(() => {
      loading.value = false;
    });
}

/** 结构容错：对象 → 键值对；数组 → 行；其余 → 空 */
function normalize(res: unknown): FinanceItem[] {
  if (Array.isArray(res)) {
    return (res as Array<Record<string, unknown>>).map((row) => ({
      label: String(row.label ?? row.name ?? row.title ?? "项目"),
      value: String(row.value ?? row.money ?? row.amount ?? "--"),
    }));
  }
  if (res && typeof res === "object") {
    const obj = res as Record<string, unknown>;
    const out: FinanceItem[] = [];
    Object.keys(obj).forEach((key) => {
      const v = obj[key];
      // 仅展示基础类型，嵌套结构跳过
      if (v === null || v === undefined) return;
      if (typeof v === "object") return;
      out.push({ label: LABEL_MAP[key] || key, value: String(v) });
    });
    return out;
  }
  return [];
}
</script>

<template>
  <view class="page">
    <!-- 财务概览条目 -->
    <view v-if="items.length > 0" class="card">
      <view class="card-title"><text>财务概览</text></view>
      <view v-for="(item, idx) in items" :key="idx" class="f-row">
        <text class="f-label">{{ item.label }}</text>
        <text class="f-value">{{ item.value }}</text>
      </view>
    </view>

    <!-- 空态（接口未就绪/无数据均展示，不白屏） -->
    <view v-else-if="!loading" class="empty">
      <text class="empty-icon">💰</text>
      <text class="empty-text">{{ loadError ? "数据加载失败，请稍后重试" : "暂无财务数据" }}</text>
    </view>

    <!-- 底部占位 + 自绘 Tab -->
    <view class="tabbar-holder"></view>
    <admin-tabbar current="finance" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.card {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 8rpx 32rpx 16rpx;
}
.card-title {
  padding: 24rpx 0 8rpx;
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.f-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 26rpx 0;
  border-bottom: 1rpx solid #f2f2f2;
}
.f-row:last-child {
  border-bottom: none;
}
.f-label {
  font-size: 28rpx;
  color: #666666;
}
.f-value {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.empty {
  margin-top: 240rpx;
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
.tabbar-holder {
  height: 140rpx;
}
</style>
