<script setup lang="ts">
/**
 * dp-kecheng 课程（P27-3 任务247）
 * params: style(1/2/3 宫格，list 横滑)/proshownum|limit/productfrom/cid/group/ids/sortby
 *         showname/showprice/showsales/title
 * 数据源: GET /api/designerComponent/fetch?temp=kecheng（匿名白名单）
 * 接口无数据回退设计器静态 data，均无数据组件自动隐藏
 * 点击跳 /pagesC2/kecheng/detail?id=
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
    const list = await fetchDesignerList("kecheng", props.params);
    items.value = list.length > 0 ? list : staticItems.value;
  } catch {
    items.value = staticItems.value;
  } finally {
    loaded.value = true;
  }
}
void load();

/** 价格文案：0 元显示"免费" */
function priceText(item: DpFetchItem): string {
  return item.price > 0 ? "￥" + price2(item.price) : "免费";
}

function goDetail(item: DpFetchItem) {
  if (item.id > 0) dpNavigate("/pagesC2/kecheng/detail?id=" + item.id);
}
function goMore() {
  dpNavigate("/pagesC2/kecheng/list");
}
</script>

<template>
  <view v-if="items.length > 0 || !loaded" class="dp-kecheng">
    <view v-if="title" class="dp-kecheng-head">
      <text class="dp-kecheng-title">{{ title }}</text>
      <text class="dp-kecheng-more" @click="goMore">更多 ›</text>
    </view>
    <scroll-view v-if="layout === 'scroll'" scroll-x class="dp-kecheng-scroll">
      <view class="dp-kecheng-scroll-inner">
        <view v-for="item in items" :key="'es' + item.id" class="dp-kecheng-card dp-kecheng-card-scroll" @click="goDetail(item)">
          <image v-if="item.pic" class="dp-kecheng-img dp-kecheng-img-scroll" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
          <view v-else class="dp-kecheng-img dp-kecheng-img-scroll dp-kecheng-img-ph"><text>暂无图</text></view>
          <view class="dp-kecheng-info">
            <text v-if="showName" class="dp-kecheng-name">{{ item.name }}</text>
            <view class="dp-kecheng-row2">
              <text v-if="showPrice" class="dp-kecheng-price">{{ priceText(item) }}</text>
            </view>
          </view>
        </view>
        <view v-if="items.length === 0" class="dp-kecheng-loading"><text>加载中…</text></view>
      </view>
    </scroll-view>
    <view v-else class="dp-kecheng-grid">
      <view
        v-for="item in items"
        :key="'eg' + item.id"
        class="dp-kecheng-card"
        :style="columns > 1 ? { width: 'calc(' + 100 / columns + '% - 12rpx)' } : { width: '100%' }"
        @click="goDetail(item)"
      >
        <image v-if="item.pic" class="dp-kecheng-img" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
        <view v-else class="dp-kecheng-img dp-kecheng-img-ph"><text>暂无图</text></view>
        <view class="dp-kecheng-info">
          <text v-if="showName" class="dp-kecheng-name">{{ item.name }}</text>
          <view class="dp-kecheng-row2">
            <text v-if="showPrice" class="dp-kecheng-price">{{ priceText(item) }}</text>
            <text v-if="showSales && (item.join_num || 0) > 0" class="dp-kecheng-join">{{ item.join_num }}人学习</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.dp-kecheng-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4rpx 12rpx;
}
.dp-kecheng-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.dp-kecheng-more {
  font-size: 24rpx;
  color: #999999;
}
.dp-kecheng-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.dp-kecheng-card {
  position: relative;
  background: #ffffff;
  border-radius: 12rpx;
  overflow: hidden;
  box-sizing: border-box;
}
.dp-kecheng-img {
  width: 100%;
  height: 300rpx;
  background: #f2f2f5;
}
.dp-kecheng-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.dp-kecheng-info {
  padding: 14rpx 16rpx 18rpx;
}
.dp-kecheng-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
  line-height: 36rpx;
}
.dp-kecheng-row2 {
  margin-top: 10rpx;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.dp-kecheng-price {
  color: #ff5000;
  font-size: 30rpx;
  font-weight: 600;
}
.dp-kecheng-join {
  color: #999999;
  font-size: 22rpx;
}
.dp-kecheng-scroll {
  width: 100%;
  white-space: nowrap;
}
.dp-kecheng-scroll-inner {
  display: inline-flex;
}
.dp-kecheng-card-scroll {
  width: 280rpx;
  margin-right: 16rpx;
  display: inline-block;
}
.dp-kecheng-img-scroll {
  height: 280rpx;
}
.dp-kecheng-loading {
  display: inline-flex;
  align-items: center;
  padding: 40rpx;
  color: #999999;
  font-size: 24rpx;
}
</style>
