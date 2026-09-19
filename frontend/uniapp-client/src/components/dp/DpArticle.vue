<script setup lang="ts">
/**
 * dp-article 文章列表（P27-3 任务247）
 * params: proshownum|limit/productfrom/cid/group/ids/sortby/title
 * 数据源: GET /api/designerComponent/fetch?temp=article（匿名白名单）
 * 接口无数据回退设计器静态 data，均无数据组件自动隐藏
 * 点击跳 /pagesC2/article/detail?id=，更多跳 /pagesC2/article/list
 */
import { computed, ref } from "vue";
import { str, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";
import { imgUrl, fmtTime } from "../../utils/format";
import { fetchDesignerList, normalizeFetchItem, type DpFetchItem } from "./dpFetch";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const title = computed(() => str(props.params.title));

/** 设计器静态数据兜底 */
const staticItems = computed<DpFetchItem[]>(() =>
  (Array.isArray(props.data) ? props.data : [])
    .map(normalizeFetchItem)
    .filter((it): it is DpFetchItem => it !== null)
);

const items = ref<DpFetchItem[]>([]);
const loaded = ref(false);

async function load() {
  try {
    const list = await fetchDesignerList("article", props.params);
    items.value = list.length > 0 ? list : staticItems.value;
  } catch {
    items.value = staticItems.value;
  } finally {
    loaded.value = true;
  }
}
void load();

/** 底部信息行文案（阅读量 + 发布时间） */
function metaText(item: DpFetchItem): string {
  const parts: string[] = [];
  if ((item.readcount || 0) > 0) parts.push(item.readcount + "次阅读");
  const t = fmtTime(item.createtime);
  if (t) parts.push(t);
  return parts.join(" · ");
}

function goDetail(item: DpFetchItem) {
  if (item.id > 0) dpNavigate("/pagesC2/article/detail?id=" + item.id);
}
function goMore() {
  dpNavigate("/pagesC2/article/list");
}
</script>

<template>
  <view v-if="items.length > 0 || !loaded" class="dp-article">
    <view v-if="title" class="dp-article-head">
      <text class="dp-article-title">{{ title }}</text>
      <text class="dp-article-more" @click="goMore">更多 ›</text>
    </view>
    <view class="dp-article-list">
      <view v-for="item in items" :key="'a' + item.id" class="dp-article-item" @click="goDetail(item)">
        <image v-if="item.pic" class="dp-article-pic" :src="imgUrl(item.pic)" mode="aspectFill" lazy-load />
        <view v-else class="dp-article-pic dp-article-pic-ph"><text>暂无图</text></view>
        <view class="dp-article-body">
          <text class="dp-article-name">{{ item.name }}</text>
          <text v-if="item.subname" class="dp-article-sub">{{ item.subname }}</text>
          <text v-if="metaText(item)" class="dp-article-meta">{{ metaText(item) }}</text>
        </view>
      </view>
      <view v-if="items.length === 0" class="dp-article-loading"><text>加载中…</text></view>
    </view>
  </view>
</template>

<style scoped>
.dp-article-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4rpx 12rpx;
}
.dp-article-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.dp-article-more {
  font-size: 24rpx;
  color: #999999;
}
.dp-article-list {
  background: #ffffff;
  border-radius: 12rpx;
  overflow: hidden;
}
.dp-article-item {
  display: flex;
  padding: 20rpx;
  border-bottom: 1rpx solid #f2f2f5;
}
.dp-article-item:last-child {
  border-bottom: none;
}
.dp-article-pic {
  flex-shrink: 0;
  width: 180rpx;
  height: 120rpx;
  border-radius: 8rpx;
  background: #f2f2f5;
}
.dp-article-pic-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 22rpx;
}
.dp-article-body {
  flex: 1;
  min-width: 0;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.dp-article-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 27rpx;
  color: #333333;
  line-height: 38rpx;
}
.dp-article-sub {
  margin-top: 6rpx;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 1;
  overflow: hidden;
  font-size: 22rpx;
  color: #999999;
}
.dp-article-meta {
  margin-top: 6rpx;
  font-size: 20rpx;
  color: #b5b5bf;
}
.dp-article-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40rpx;
  color: #999999;
  font-size: 24rpx;
}
</style>
