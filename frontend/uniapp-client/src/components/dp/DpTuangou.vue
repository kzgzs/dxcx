<script setup lang="ts">
/**
 * dp-tuangou 团购商品（P27-3 任务247）
 * params: style(1/2/3 宫格，list 横滑)/proshownum|limit/productfrom/cid/group/ids/sortby
 *         showname/showprice/showsales/title
 * 数据源: GET /api/designerComponent/fetch?temp=tuangou（匿名白名单）
 * 接口无数据回退设计器静态 data，均无数据组件自动隐藏
 * 点击跳 /pagesH3/tuangou/detail?id=
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
    const list = await fetchDesignerList("tuangou", props.params);
    items.value = list.length > 0 ? list : staticItems.value;
  } catch {
    items.value = staticItems.value;
  } finally {
    loaded.value = true;
  }
}
void load();

function goDetail(item: DpFetchItem) {
  if (item.id > 0) dpNavigate("/pagesH3/tuangou/detail?id=" + item.id);
}
function goMore() {
  dpNavigate("/pagesH3/tuangou/list");
}
</script>

<template>
  <view v-if="items.length > 0 || !loaded" class="dp-tuangou">
    <view v-if="title" class="dp-tuangou-head">
      <text class="dp-tuangou-title">{{ title }}</text>
      <text class="dp-tuangou-more" @click="goMore">更多 ›</text>
    </view>
    <scroll-view v-if="layout === 'scroll'" scroll-x class="dp-tuangou-scroll">
      <view class="dp-tuangou-scroll-inner">
        <view v-for="item in items" :key="'ts' + item.id" class="dp-tuangou-card dp-tuangou-card-scroll" @click="goDetail(item)">
          <image v-if="item.pic" class="dp-tuangou-img dp-tuangou-img-scroll" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
          <view v-else class="dp-tuangou-img dp-tuangou-img-scroll dp-tuangou-img-ph"><text>暂无图</text></view>
          <view class="dp-tuangou-tag">限时团购</view>
          <view class="dp-tuangou-info">
            <text v-if="showName" class="dp-tuangou-name">{{ item.name }}</text>
            <view class="dp-tuangou-row2">
              <text v-if="showPrice" class="dp-tuangou-price">￥{{ price2(item.price) }}</text>
              <text v-if="showPrice && item.market_price > item.price" class="dp-tuangou-market">￥{{ price2(item.market_price) }}</text>
            </view>
          </view>
        </view>
        <view v-if="items.length === 0" class="dp-tuangou-loading"><text>加载中…</text></view>
      </view>
    </scroll-view>
    <view v-else class="dp-tuangou-grid">
      <view
        v-for="item in items"
        :key="'tg' + item.id"
        class="dp-tuangou-card"
        :style="columns > 1 ? { width: 'calc(' + 100 / columns + '% - 12rpx)' } : { width: '100%' }"
        @click="goDetail(item)"
      >
        <image v-if="item.pic" class="dp-tuangou-img" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
        <view v-else class="dp-tuangou-img dp-tuangou-img-ph"><text>暂无图</text></view>
        <view class="dp-tuangou-tag">限时团购</view>
        <view class="dp-tuangou-info">
          <text v-if="showName" class="dp-tuangou-name">{{ item.name }}</text>
          <view class="dp-tuangou-row2">
            <view class="dp-tuangou-pricewrap">
              <text v-if="showPrice" class="dp-tuangou-price">￥{{ price2(item.price) }}</text>
              <text v-if="showPrice && item.market_price > item.price" class="dp-tuangou-market">￥{{ price2(item.market_price) }}</text>
            </view>
            <text v-if="showSales && item.sales > 0" class="dp-tuangou-sales">已售{{ item.sales }}</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.dp-tuangou-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4rpx 12rpx;
}
.dp-tuangou-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.dp-tuangou-more {
  font-size: 24rpx;
  color: #999999;
}
.dp-tuangou-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.dp-tuangou-card {
  position: relative;
  background: #ffffff;
  border-radius: 12rpx;
  overflow: hidden;
  box-sizing: border-box;
}
.dp-tuangou-img {
  width: 100%;
  height: 300rpx;
  background: #f2f2f5;
}
.dp-tuangou-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.dp-tuangou-tag {
  position: absolute;
  left: 0;
  top: 0;
  padding: 4rpx 14rpx;
  background: linear-gradient(135deg, #13c2c2, #36cfc9);
  color: #ffffff;
  font-size: 20rpx;
  border-radius: 0 0 12rpx 0;
}
.dp-tuangou-info {
  padding: 14rpx 16rpx 18rpx;
}
.dp-tuangou-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
  line-height: 36rpx;
}
.dp-tuangou-row2 {
  margin-top: 10rpx;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.dp-tuangou-pricewrap {
  display: flex;
  align-items: baseline;
}
.dp-tuangou-price {
  color: #13c2c2;
  font-size: 30rpx;
  font-weight: 600;
}
.dp-tuangou-market {
  margin-left: 10rpx;
  color: #b5b5bf;
  font-size: 22rpx;
  text-decoration: line-through;
}
.dp-tuangou-sales {
  color: #999999;
  font-size: 22rpx;
}
.dp-tuangou-scroll {
  width: 100%;
  white-space: nowrap;
}
.dp-tuangou-scroll-inner {
  display: inline-flex;
}
.dp-tuangou-card-scroll {
  width: 280rpx;
  margin-right: 16rpx;
  display: inline-block;
}
.dp-tuangou-img-scroll {
  height: 280rpx;
}
.dp-tuangou-loading {
  display: inline-flex;
  align-items: center;
  padding: 40rpx;
  color: #999999;
  font-size: 24rpx;
}
</style>
