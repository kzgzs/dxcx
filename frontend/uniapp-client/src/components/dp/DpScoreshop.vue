<script setup lang="ts">
/**
 * dp-scoreshop 积分兑换（P27-3 任务247）
 * params: style(1/2/3 宫格，list 横滑)/proshownum|limit/productfrom/cid/group/ids/sortby
 *         showname/showprice/showsales/title
 * 数据源: GET /api/designerComponent/fetch?temp=scoreshop（匿名白名单）
 * 展示口径：积分价 score_price（有现金补差 money_price 时附加显示）
 * 接口无数据回退设计器静态 data，均无数据组件自动隐藏
 * 点击跳 /pagesH2/scoreshop/detail?proid=
 */
import { computed, ref } from "vue";
import { num, str, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";
import { imgUrl, price2 } from "../../utils/format";
import { fetchDesignerList, normalizeFetchItem, type DpFetchItem } from "./dpFetch";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const layout = computed(() => {
  const s = str(props.params.style, "2");
  if (s === "list" || s === "line") return "scroll";
  const c = num(s, 2);
  return [1, 2, 3].includes(c) ? String(c) : "2";
});
const columns = computed(() => num(layout.value, 2));
const showName = computed(() => str(props.params.showname, "1") !== "0");
const showPrice = computed(() => str(props.params.showprice, "1") !== "0");
const showSales = computed(() => str(props.params.showsales, "1") !== "0");
const title = computed(() => str(props.params.title));

const staticItems = computed<DpFetchItem[]>(() =>
  (Array.isArray(props.data) ? props.data : [])
    .map(normalizeFetchItem)
    .filter((it): it is DpFetchItem => it !== null)
);

const items = ref<DpFetchItem[]>([]);
const loaded = ref(false);

async function load() {
  try {
    const list = await fetchDesignerList("scoreshop", props.params);
    items.value = list.length > 0 ? list : staticItems.value;
  } catch {
    items.value = staticItems.value;
  } finally {
    loaded.value = true;
  }
}
void load();

/** 积分价文案（无积分价时退化为金额价） */
function scoreText(item: DpFetchItem): string {
  const score = item.score_price ?? 0;
  if (score > 0) return score + "积分";
  return "￥" + price2(item.price);
}

function goDetail(item: DpFetchItem) {
  if (item.id > 0) dpNavigate("/pagesH2/scoreshop/detail?proid=" + item.id);
}
function goMore() {
  dpNavigate("/pagesH2/scoreshop/list");
}
</script>

<template>
  <view v-if="items.length > 0 || !loaded" class="dp-scoreshop">
    <view v-if="title" class="dp-scoreshop-head">
      <text class="dp-scoreshop-title">{{ title }}</text>
      <text class="dp-scoreshop-more" @click="goMore">更多 ›</text>
    </view>
    <scroll-view v-if="layout === 'scroll'" scroll-x class="dp-scoreshop-scroll">
      <view class="dp-scoreshop-scroll-inner">
        <view v-for="item in items" :key="'rs' + item.id" class="dp-scoreshop-card dp-scoreshop-card-scroll" @click="goDetail(item)">
          <image v-if="item.pic" class="dp-scoreshop-img dp-scoreshop-img-scroll" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
          <view v-else class="dp-scoreshop-img dp-scoreshop-img-scroll dp-scoreshop-img-ph"><text>暂无图</text></view>
          <view class="dp-scoreshop-tag">积分兑</view>
          <view class="dp-scoreshop-info">
            <text v-if="showName" class="dp-scoreshop-name">{{ item.name }}</text>
            <view class="dp-scoreshop-row2">
              <text v-if="showPrice" class="dp-scoreshop-price">{{ scoreText(item) }}</text>
              <text v-if="showPrice && (item.money_price || 0) > 0" class="dp-scoreshop-money">+￥{{ price2(item.money_price) }}</text>
            </view>
          </view>
        </view>
        <view v-if="items.length === 0" class="dp-scoreshop-loading"><text>加载中…</text></view>
      </view>
    </scroll-view>
    <view v-else class="dp-scoreshop-grid">
      <view
        v-for="item in items"
        :key="'rg' + item.id"
        class="dp-scoreshop-card"
        :style="columns > 1 ? { width: 'calc(' + 100 / columns + '% - 12rpx)' } : { width: '100%' }"
        @click="goDetail(item)"
      >
        <image v-if="item.pic" class="dp-scoreshop-img" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
        <view v-else class="dp-scoreshop-img dp-scoreshop-img-ph"><text>暂无图</text></view>
        <view class="dp-scoreshop-tag">积分兑</view>
        <view class="dp-scoreshop-info">
          <text v-if="showName" class="dp-scoreshop-name">{{ item.name }}</text>
          <view class="dp-scoreshop-row2">
            <view class="dp-scoreshop-pricewrap">
              <text v-if="showPrice" class="dp-scoreshop-price">{{ scoreText(item) }}</text>
              <text v-if="showPrice && (item.money_price || 0) > 0" class="dp-scoreshop-money">+￥{{ price2(item.money_price) }}</text>
            </view>
            <text v-if="showSales && item.sales > 0" class="dp-scoreshop-sales">已兑{{ item.sales }}</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.dp-scoreshop-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4rpx 12rpx;
}
.dp-scoreshop-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.dp-scoreshop-more {
  font-size: 24rpx;
  color: #999999;
}
.dp-scoreshop-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.dp-scoreshop-card {
  position: relative;
  background: #ffffff;
  border-radius: 12rpx;
  overflow: hidden;
  box-sizing: border-box;
}
.dp-scoreshop-img {
  width: 100%;
  height: 300rpx;
  background: #f2f2f5;
}
.dp-scoreshop-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.dp-scoreshop-tag {
  position: absolute;
  left: 0;
  top: 0;
  padding: 4rpx 14rpx;
  background: linear-gradient(135deg, #faad14, #ffd666);
  color: #ffffff;
  font-size: 20rpx;
  border-radius: 0 0 12rpx 0;
}
.dp-scoreshop-info {
  padding: 14rpx 16rpx 18rpx;
}
.dp-scoreshop-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
  line-height: 36rpx;
}
.dp-scoreshop-row2 {
  margin-top: 10rpx;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.dp-scoreshop-pricewrap {
  display: flex;
  align-items: baseline;
}
.dp-scoreshop-price {
  color: #faad14;
  font-size: 30rpx;
  font-weight: 600;
}
.dp-scoreshop-money {
  margin-left: 8rpx;
  color: #ff5000;
  font-size: 22rpx;
}
.dp-scoreshop-sales {
  color: #999999;
  font-size: 22rpx;
}
.dp-scoreshop-scroll {
  width: 100%;
  white-space: nowrap;
}
.dp-scoreshop-scroll-inner {
  display: inline-flex;
}
.dp-scoreshop-card-scroll {
  width: 280rpx;
  margin-right: 16rpx;
  display: inline-block;
}
.dp-scoreshop-img-scroll {
  height: 280rpx;
}
.dp-scoreshop-loading {
  display: inline-flex;
  align-items: center;
  padding: 40rpx;
  color: #999999;
  font-size: 24rpx;
}
</style>
