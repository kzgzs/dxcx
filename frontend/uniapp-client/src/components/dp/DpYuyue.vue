<script setup lang="ts">
/**
 * dp-yuyue 预约服务（P27-3 任务247）
 * params: style(1/2/3 宫格，list 横滑)/proshownum|limit/productfrom/cid/group/ids/sortby
 *         showname/showprice/showsales/title
 * 数据源: GET /api/designerComponent/fetch?temp=yuyue（匿名白名单）
 * 接口无数据回退设计器静态 data，均无数据组件自动隐藏
 * 点击跳 /yuyue/item/detail?id=
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
    const list = await fetchDesignerList("yuyue", props.params);
    items.value = list.length > 0 ? list : staticItems.value;
  } catch {
    items.value = staticItems.value;
  } finally {
    loaded.value = true;
  }
}
void load();

function goDetail(item: DpFetchItem) {
  if (item.id > 0) dpNavigate("/yuyue/item/detail?id=" + item.id);
}
function goMore() {
  dpNavigate("/yuyue/index/index");
}
</script>

<template>
  <view v-if="items.length > 0 || !loaded" class="dp-yuyue">
    <view v-if="title" class="dp-yuyue-head">
      <text class="dp-yuyue-title">{{ title }}</text>
      <text class="dp-yuyue-more" @click="goMore">更多 ›</text>
    </view>
    <scroll-view v-if="layout === 'scroll'" scroll-x class="dp-yuyue-scroll">
      <view class="dp-yuyue-scroll-inner">
        <view v-for="item in items" :key="'ys' + item.id" class="dp-yuyue-card dp-yuyue-card-scroll" @click="goDetail(item)">
          <image v-if="item.pic" class="dp-yuyue-img dp-yuyue-img-scroll" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
          <view v-else class="dp-yuyue-img dp-yuyue-img-scroll dp-yuyue-img-ph"><text>暂无图</text></view>
          <view class="dp-yuyue-tag">可预约</view>
          <view class="dp-yuyue-info">
            <text v-if="showName" class="dp-yuyue-name">{{ item.name }}</text>
            <view class="dp-yuyue-row2">
              <text v-if="showPrice" class="dp-yuyue-price">￥{{ price2(item.price) }}</text>
            </view>
          </view>
        </view>
        <view v-if="items.length === 0" class="dp-yuyue-loading"><text>加载中…</text></view>
      </view>
    </scroll-view>
    <view v-else class="dp-yuyue-grid">
      <view
        v-for="item in items"
        :key="'yg' + item.id"
        class="dp-yuyue-card"
        :style="columns > 1 ? { width: 'calc(' + 100 / columns + '% - 12rpx)' } : { width: '100%' }"
        @click="goDetail(item)"
      >
        <image v-if="item.pic" class="dp-yuyue-img" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
        <view v-else class="dp-yuyue-img dp-yuyue-img-ph"><text>暂无图</text></view>
        <view class="dp-yuyue-tag">可预约</view>
        <view class="dp-yuyue-info">
          <text v-if="showName" class="dp-yuyue-name">{{ item.name }}</text>
          <view class="dp-yuyue-row2">
            <text v-if="showPrice" class="dp-yuyue-price">￥{{ price2(item.price) }}</text>
            <text v-if="showSales && item.sales > 0" class="dp-yuyue-sales">已约{{ item.sales }}次</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.dp-yuyue-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4rpx 12rpx;
}
.dp-yuyue-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.dp-yuyue-more {
  font-size: 24rpx;
  color: #999999;
}
.dp-yuyue-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.dp-yuyue-card {
  position: relative;
  background: #ffffff;
  border-radius: 12rpx;
  overflow: hidden;
  box-sizing: border-box;
}
.dp-yuyue-img {
  width: 100%;
  height: 300rpx;
  background: #f2f2f5;
}
.dp-yuyue-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.dp-yuyue-tag {
  position: absolute;
  left: 0;
  top: 0;
  padding: 4rpx 14rpx;
  background: linear-gradient(135deg, #1890ff, #69c0ff);
  color: #ffffff;
  font-size: 20rpx;
  border-radius: 0 0 12rpx 0;
}
.dp-yuyue-info {
  padding: 14rpx 16rpx 18rpx;
}
.dp-yuyue-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  color: #333333;
  line-height: 36rpx;
}
.dp-yuyue-row2 {
  margin-top: 10rpx;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.dp-yuyue-price {
  color: #1890ff;
  font-size: 30rpx;
  font-weight: 600;
}
.dp-yuyue-sales {
  color: #999999;
  font-size: 22rpx;
}
.dp-yuyue-scroll {
  width: 100%;
  white-space: nowrap;
}
.dp-yuyue-scroll-inner {
  display: inline-flex;
}
.dp-yuyue-card-scroll {
  width: 280rpx;
  margin-right: 16rpx;
  display: inline-block;
}
.dp-yuyue-img-scroll {
  height: 280rpx;
}
.dp-yuyue-loading {
  display: inline-flex;
  align-items: center;
  padding: 40rpx;
  color: #999999;
  font-size: 24rpx;
}
</style>
