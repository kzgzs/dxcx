<script setup lang="ts">
/**
 * dp-luckycollage 幸运拼团（P27-3 任务247）
 * params: style(1/2/3 宫格，list 横滑)/proshownum|limit/productfrom/cid/group/ids/sortby
 *         showname/showprice/showsales/title
 * 数据源: GET /api/designerComponent/fetch?temp=luckycollage（匿名白名单）
 * 接口无数据回退设计器静态 data，均无数据组件自动隐藏
 * 点击跳 /pagesH1/luckycollage/detail?id=
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
    const list = await fetchDesignerList("luckycollage", props.params);
    items.value = list.length > 0 ? list : staticItems.value;
  } catch {
    items.value = staticItems.value;
  } finally {
    loaded.value = true;
  }
}
void load();

function goDetail(item: DpFetchItem) {
  if (item.id > 0) dpNavigate("/pagesH1/luckycollage/detail?id=" + item.id);
}
function goMore() {
  dpNavigate("/pagesH1/luckycollage/list");
}
</script>

<template>
  <view v-if="items.length > 0 || !loaded" class="dp-luckycollage">
    <view v-if="title" class="dp-luckycollage-head">
      <text class="dp-luckycollage-title">{{ title }}</text>
      <text class="dp-luckycollage-more" @click="goMore">更多 ›</text>
    </view>
    <scroll-view v-if="layout === 'scroll'" scroll-x class="dp-luckycollage-scroll">
      <view class="dp-luckycollage-scroll-inner">
        <view v-for="item in items" :key="'ls' + item.id" class="dp-luckycollage-card dp-luckycollage-card-scroll" @click="goDetail(item)">
          <image v-if="item.pic" class="dp-luckycollage-img dp-luckycollage-img-scroll" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
          <view v-else class="dp-luckycollage-img dp-luckycollage-img-scroll dp-luckycollage-img-ph"><text>暂无图</text></view>
          <view class="dp-luckycollage-tag">幸运拼</view>
          <view class="dp-luckycollage-info">
            <text v-if="showName" class="dp-luckycollage-name">{{ item.name }}</text>
            <view class="dp-luckycollage-row2">
              <text v-if="showPrice" class="dp-luckycollage-price">￥{{ price2(item.price) }}</text>
              <text v-if="(item.teamnum || 0) > 0" class="dp-luckycollage-team">{{ item.teamnum }}人团</text>
            </view>
          </view>
        </view>
        <view v-if="items.length === 0" class="dp-luckycollage-loading"><text>加载中…</text></view>
      </view>
    </scroll-view>
    <view v-else class="dp-luckycollage-grid">
      <view
        v-for="item in items"
        :key="'lg' + item.id"
        class="dp-luckycollage-card"
        :style="columns > 1 ? { width: 'calc(' + 100 / columns + '% - 12rpx)' } : { width: '100%' }"
        @click="goDetail(item)"
      >
        <image v-if="item.pic" class="dp-luckycollage-img" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
        <view v-else class="dp-luckycollage-img dp-luckycollage-img-ph"><text>暂无图</text></view>
        <view class="dp-luckycollage-tag">幸运拼</view>
        <view class="dp-luckycollage-info">
          <text v-if="showName" class="dp-luckycollage-name">{{ item.name }}</text>
          <view class="dp-luckycollage-row2">
            <view class="dp-luckycollage-pricewrap">
              <text v-if="showPrice" class="dp-luckycollage-price">￥{{ price2(item.price) }}</text>
              <text v-if="showPrice && item.market_price > item.price" class="dp-luckycollage-market">￥{{ price2(item.market_price) }}</text>
            </view>
            <text v-if="showSales && item.sales > 0" class="dp-luckycollage-sales">已拼{{ item.sales }}件</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.dp-luckycollage-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4rpx 12rpx;
}
.dp-luckycollage-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.dp-luckycollage-more {
  font-size: 24rpx;
  color: #999999;
}
.dp-luckycollage-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.dp-luckycollage-card {
  position: relative;
  background: #ffffff;
  border-radius: 12rpx;
  overflow: hidden;
  box-sizing: border-box;
}
.dp-luckycollage-img {
  width: 100%;
  height: 300rpx;
  background: #f2f2f5;
}
.dp-luckycollage-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.dp-luckycollage-tag {
  position: absolute;
  left: 0;
  top: 0;
  padding: 4rpx 14rpx;
  background: linear-gradient(135deg, #722ed1, #b37feb);
  color: #ffffff;
  font-size: 20rpx;
  border-radius: 0 0 12rpx 0;
}
.dp-luckycollage-info {
  padding: 14rpx 16rpx 18rpx;
}
.dp-luckycollage-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
  line-height: 36rpx;
}
.dp-luckycollage-row2 {
  margin-top: 10rpx;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.dp-luckycollage-pricewrap {
  display: flex;
  align-items: baseline;
}
.dp-luckycollage-price {
  color: #ff5000;
  font-size: 30rpx;
  font-weight: 600;
}
.dp-luckycollage-market {
  margin-left: 10rpx;
  color: #b5b5bf;
  font-size: 22rpx;
  text-decoration: line-through;
}
.dp-luckycollage-team {
  color: #722ed1;
  font-size: 22rpx;
}
.dp-luckycollage-sales {
  color: #999999;
  font-size: 22rpx;
}
.dp-luckycollage-scroll {
  width: 100%;
  white-space: nowrap;
}
.dp-luckycollage-scroll-inner {
  display: inline-flex;
}
.dp-luckycollage-card-scroll {
  width: 280rpx;
  margin-right: 16rpx;
  display: inline-block;
}
.dp-luckycollage-img-scroll {
  height: 280rpx;
}
.dp-luckycollage-loading {
  display: inline-flex;
  align-items: center;
  padding: 40rpx;
  color: #999999;
  font-size: 24rpx;
}
</style>
