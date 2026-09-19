<script setup lang="ts">
/**
 * 手机端后台-商品管理（对照参考项目 admin/product/index 的列表+上下架职责）
 * - GET /api/adminidx/productlist?page= 列表
 * - POST /api/adminidx/productstatus {id,status} 上下架开关
 * - 触底加载；接口失败空态不白屏；开关失败自动回滚
 */
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { ref } from "vue";
import {
  fetchAdminProductList,
  fieldNum,
  fieldText,
  guardAdmin,
  normalizePage,
  setAdminProductStatus,
} from "../api";

/** 商品行（字段容错，后端并行开发） */
interface ProductRow {
  raw: Record<string, unknown>;
  id: number;
  name: string;
  price: string;
  /** 上架状态：1 上架 0 下架 */
  status: number;
}

const page = ref(1);
const list = ref<ProductRow[]>([]);
const total = ref(0);
const loading = ref(false);
const loadError = ref(false);
const finished = ref(false);

onLoad(() => {
  if (!guardAdmin()) return;
  refresh();
});

/** 重置加载第一页 */
function refresh() {
  page.value = 1;
  list.value = [];
  total.value = 0;
  finished.value = false;
  loadError.value = false;
  void loadPage();
}

/** 归一化后端行数据 */
function toRow(item: Record<string, unknown>): ProductRow {
  return {
    raw: item,
    id: fieldNum(item, ["id", "pid", "product_id"], 0),
    name: fieldText(item, ["name", "title", "product_name"], "未命名商品"),
    price: fieldText(item, ["price", "money", "sale_price"], "0.00"),
    // status 多候选：1 上架 / 0 下架（兼容 ison/shelf）
    status: fieldNum(item, ["status", "ison", "is_on", "shelf"], 0) > 0 ? 1 : 0,
  };
}

/** 加载当前页 */
async function loadPage() {
  if (loading.value || finished.value) return;
  loading.value = true;
  try {
    const res = await fetchAdminProductList(page.value);
    const { list: rows, total: t } = normalizePage(res);
    const mapped = rows.map(toRow);
    list.value = page.value === 1 ? mapped : list.value.concat(mapped);
    total.value = t;
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

/** 上下架开关变更：先乐观更新，失败回滚并提示 */
function handleSwitch(row: ProductRow, e: unknown) {
  onSwitchChange(row, e as { detail: { value: boolean } });
}

function onSwitchChange(row: ProductRow, e: { detail: { value: boolean } }) {
  const next = e.detail.value ? 1 : 0;
  const prev = row.status;
  if (next === prev) return;
  row.status = next;
  setAdminProductStatus(row.id, next)
    .then(() => {
      uni.showToast({ title: next === 1 ? "已上架" : "已下架", icon: "none" });
    })
    .catch(() => {
      // 回滚开关状态
      row.status = prev;
      uni.showToast({ title: "操作失败，请重试", icon: "none" });
    });
}
</script>

<template>
  <view class="page">
    <!-- 列表 -->
    <view v-if="list.length > 0" class="list">
      <view v-for="row in list" :key="row.id" class="product-card">
        <view class="p-meta">
          <text class="p-name">{{ row.name }}</text>
          <text class="p-price">￥{{ row.price }}</text>
        </view>
        <view class="p-row">
          <text class="p-status" :class="{ on: row.status === 1 }">
            {{ row.status === 1 ? "销售中" : "已下架" }}
          </text>
          <!-- 上下架开关 -->
          <switch
            :checked="row.status === 1"
            color="#7b7de5"
            @change="handleSwitch(row, $event)"
          />
        </view>
      </view>
      <view class="load-tip">
        <text v-if="loading">加载中...</text>
        <text v-else-if="finished">没有更多了</text>
      </view>
    </view>

    <!-- 空态（接口未就绪/无数据均展示，不白屏） -->
    <view v-else-if="!loading" class="empty">
      <text class="empty-icon">🛍️</text>
      <text class="empty-text">{{ loadError ? "数据加载失败，请稍后重试" : "暂无商品" }}</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx 24rpx 40rpx;
}
.product-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
  margin-bottom: 20rpx;
}
.p-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.p-name {
  flex: 1;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
  margin-right: 20rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.p-price {
  font-size: 28rpx;
  color: #e64340;
  font-weight: 600;
}
.p-row {
  margin-top: 16rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.p-status {
  font-size: 24rpx;
  color: #999999;
}
.p-status.on {
  color: #07c160;
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
