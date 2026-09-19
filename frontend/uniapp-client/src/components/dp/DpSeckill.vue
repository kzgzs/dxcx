<script setup lang="ts">
/**
 * dp-seckill 秒杀商品（P27-3 任务247）
 * params: style(1/2/3 宫格，list 横滑)/proshownum|limit/productfrom/cid/group/ids/sortby
 *         showname/showprice/showsales/title
 * 数据源: GET /api/designerComponent/fetch?temp=seckill（匿名白名单）
 * 接口无数据回退设计器静态 data，均无数据组件自动隐藏
 * 点击跳 /pagesH2/seckill/detail?proid=
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
    const list = await fetchDesignerList("seckill", props.params);
    items.value = list.length > 0 ? list : staticItems.value;
  } catch {
    items.value = staticItems.value;
  } finally {
    loaded.value = true;
  }
}
void load();

function goDetail(item: DpFetchItem) {
  if (item.id > 0) dpNavigate("/pagesH2/seckill/detail?proid=" + item.id);
}
function goMore() {
  dpNavigate("/pagesH2/seckill/list");
}
</script>

<template>
  <view v-if="items.length > 0 || !loaded" class="dp-seckill">
    <view v-if="title" class="dp-seckill-head">
      <text class="dp-seckill-title">{{ title }}</text>
      <text class="dp-seckill-more" @click="goMore">更多 ›</text>
    </view>
    <scroll-view v-if="layout === 'scroll'" scroll-x class="dp-seckill-scroll">
      <view class="dp-seckill-scroll-inner">
        <view v-for="item in items" :key="'ss' + item.id" class="dp-seckill-card dp-seckill-card-scroll" @click="goDetail(item)">
          <image v-if="item.pic" class="dp-seckill-img dp-seckill-img-scroll" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
          <view v-else class="dp-seckill-img dp-seckill-img-scroll dp-seckill-img-ph"><text>暂无图</text></view>
          <view class="dp-seckill-tag">秒杀</view>
          <view class="dp-seckill-info">
            <text v-if="showName" class="dp-seckill-name">{{ item.name }}</text>
            <view class="dp-seckill-row2">
              <text v-if="showPrice" class="dp-seckill-price">￥{{ price2(item.price) }}</text>
              <text v-if="showPrice && item.market_price > item.price" class="dp-seckill-market">￥{{ price2(item.market_price) }}</text>
            </view>
          </view>
        </view>
        <view v-if="items.length === 0" class="dp-seckill-loading"><text>加载中…</text></view>
      </view>
    </scroll-view>
    <view v-else class="dp-seckill-grid">
      <view
        v-for="item in items"
        :key="'sg' + item.id"
        class="dp-seckill-card"
        :style="columns > 1 ? { width: 'calc(' + 100 / columns + '% - 12rpx)' } : { width: '100%' }"
        @click="goDetail(item)"
      >
        <image v-if="item.pic" class="dp-seckill-img" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
        <view v-else class="dp-seckill-img dp-seckill-img-ph"><text>暂无图</text></view>
        <view class="dp-seckill-tag">秒杀</view>
        <view class="dp-seckill-info">
          <text v-if="showName" class="dp-seckill-name">{{ item.name }}</text>
          <view class="dp-seckill-row2">
            <view class="dp-seckill-pricewrap">
              <text v-if="showPrice" class="dp-seckill-price">￥{{ price2(item.price) }}</text>
              <text v-if="showPrice && item.market_price > item.price" class="dp-seckill-market">￥{{ price2(item.market_price) }}</text>
            </view>
            <text v-if="showSales && item.sales > 0" class="dp-seckill-sales">已抢{{ item.sales }}件</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.dp-seckill-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4rpx 12rpx;
}
.dp-seckill-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.dp-seckill-more {
  font-size: 24rpx;
  color: #999999;
}
.dp-seckill-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.dp-seckill-card {
  position: relative;
  background: #ffffff;
  border-radius: 12rpx;
  overflow: hidden;
  box-sizing: border-box;
}
.dp-seckill-img {
  width: 100%;
  height: 300rpx;
  background: #f2f2f5;
}
.dp-seckill-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.dp-seckill-tag {
  position: absolute;
  left: 0;
  top: 0;
  padding: 4rpx 14rpx;
  background: linear-gradient(135deg, #f5222d, #fa541c);
  color: #ffffff;
  font-size: 20rpx;
  border-radius: 0 0 12rpx 0;
}
.dp-seckill-info {
  padding: 14rpx 16rpx 18rpx;
}
.dp-seckill-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
  line-height: 36rpx;
}
.dp-seckill-row2 {
  margin-top: 10rpx;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.dp-seckill-pricewrap {
  display: flex;
  align-items: baseline;
}
.dp-seckill-price {
  color: #f5222d;
  font-size: 30rpx;
  font-weight: 600;
}
.dp-seckill-market {
  margin-left: 10rpx;
  color: #b5b5bf;
  font-size: 22rpx;
  text-decoration: line-through;
}
.dp-seckill-sales {
  color: #999999;
  font-size: 22rpx;
}
.dp-seckill-scroll {
  width: 100%;
  white-space: nowrap;
}
.dp-seckill-scroll-inner {
  display: inline-flex;
}
.dp-seckill-card-scroll {
  width: 280rpx;
  margin-right: 16rpx;
  display: inline-block;
}
.dp-seckill-img-scroll {
  height: 280rpx;
}
.dp-seckill-loading {
  display: inline-flex;
  align-items: center;
  padding: 40rpx;
  color: #999999;
  font-size: 24rpx;
}
</style>
