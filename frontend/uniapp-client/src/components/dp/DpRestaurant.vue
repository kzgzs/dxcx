<script setup lang="ts">
/**
 * dp-restaurant 餐饮菜品（P27-3 任务247）
 * params: style(1/2/3 宫格，list 横滑)/proshownum|limit/productfrom/cid/group/ids/sortby
 *         showname/showprice/showsales/title
 * 数据源: GET /api/designerComponent/fetch?temp=restaurant（匿名白名单）
 * 接口无数据回退设计器静态 data，均无数据组件自动隐藏
 * 点击跳餐饮堂食页 /restaurant/index/index（无菜品独立详情页，点餐在堂食页完成）
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
    const list = await fetchDesignerList("restaurant", props.params);
    items.value = list.length > 0 ? list : staticItems.value;
  } catch {
    items.value = staticItems.value;
  } finally {
    loaded.value = true;
  }
}
void load();

function goOrder() {
  dpNavigate("/restaurant/index/index");
}
</script>

<template>
  <view v-if="items.length > 0 || !loaded" class="dp-restaurant">
    <view v-if="title" class="dp-restaurant-head">
      <text class="dp-restaurant-title">{{ title }}</text>
      <text class="dp-restaurant-more" @click="goOrder">去点餐 ›</text>
    </view>
    <scroll-view v-if="layout === 'scroll'" scroll-x class="dp-restaurant-scroll">
      <view class="dp-restaurant-scroll-inner">
        <view v-for="item in items" :key="'fs' + item.id" class="dp-restaurant-card dp-restaurant-card-scroll" @click="goOrder()">
          <image v-if="item.pic" class="dp-restaurant-img dp-restaurant-img-scroll" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
          <view v-else class="dp-restaurant-img dp-restaurant-img-scroll dp-restaurant-img-ph"><text>暂无图</text></view>
          <view class="dp-restaurant-info">
            <text v-if="showName" class="dp-restaurant-name">{{ item.name }}</text>
            <view class="dp-restaurant-row2">
              <text v-if="showPrice" class="dp-restaurant-price">￥{{ price2(item.price) }}</text>
            </view>
          </view>
        </view>
        <view v-if="items.length === 0" class="dp-restaurant-loading"><text>加载中…</text></view>
      </view>
    </scroll-view>
    <view v-else class="dp-restaurant-grid">
      <view
        v-for="item in items"
        :key="'fg' + item.id"
        class="dp-restaurant-card"
        :style="columns > 1 ? { width: 'calc(' + 100 / columns + '% - 12rpx)' } : { width: '100%' }"
        @click="goOrder()"
      >
        <image v-if="item.pic" class="dp-restaurant-img" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
        <view v-else class="dp-restaurant-img dp-restaurant-img-ph"><text>暂无图</text></view>
        <view class="dp-restaurant-info">
          <text v-if="showName" class="dp-restaurant-name">{{ item.name }}</text>
          <view class="dp-restaurant-row2">
            <text v-if="showPrice" class="dp-restaurant-price">￥{{ price2(item.price) }}</text>
            <text v-if="showSales && item.sales > 0" class="dp-restaurant-sales">月售{{ item.sales }}</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.dp-restaurant-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4rpx 12rpx;
}
.dp-restaurant-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.dp-restaurant-more {
  font-size: 24rpx;
  color: #999999;
}
.dp-restaurant-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.dp-restaurant-card {
  position: relative;
  background: #ffffff;
  border-radius: 12rpx;
  overflow: hidden;
  box-sizing: border-box;
}
.dp-restaurant-img {
  width: 100%;
  height: 300rpx;
  background: #f2f2f5;
}
.dp-restaurant-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.dp-restaurant-info {
  padding: 14rpx 16rpx 18rpx;
}
.dp-restaurant-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
  line-height: 36rpx;
}
.dp-restaurant-row2 {
  margin-top: 10rpx;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.dp-restaurant-price {
  color: #ff5000;
  font-size: 30rpx;
  font-weight: 600;
}
.dp-restaurant-sales {
  color: #999999;
  font-size: 22rpx;
}
.dp-restaurant-scroll {
  width: 100%;
  white-space: nowrap;
}
.dp-restaurant-scroll-inner {
  display: inline-flex;
}
.dp-restaurant-card-scroll {
  width: 280rpx;
  margin-right: 16rpx;
  display: inline-block;
}
.dp-restaurant-img-scroll {
  height: 280rpx;
}
.dp-restaurant-loading {
  display: inline-flex;
  align-items: center;
  padding: 40rpx;
  color: #999999;
  font-size: 24rpx;
}
</style>
