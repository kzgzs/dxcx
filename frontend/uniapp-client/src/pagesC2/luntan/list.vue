<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchLtList } from "../api/luntan";
import type { C2Option } from "../api/article";
import type { LtPost } from "../api/luntan";
import { imgUrl } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import C2Empty from "../components/c2-empty.vue";
import C2Post from "../components/c2-post.vue";

const cid = ref(0);
const keyword = ref("");
const page = ref(1);
const tabs = ref<C2Option[]>([]);
const banner = ref("");
const datalist = ref<LtPost[]>([]);
const loading = ref(false);
const hasMore = ref(true);

onLoad((options) => {
  cid.value = Number(options && options.cid) || 0;
  if (options && options.keyword) keyword.value = decodeURIComponent(options.keyword);
  void load(true);
});

async function load(reset: boolean) {
  if (loading.value) return;
  loading.value = true;
  try {
    const p = reset ? 1 : page.value + 1;
    const res = await fetchLtList({ cid: cid.value, keyword: keyword.value.trim() || undefined, page: p });
    tabs.value = res.categoryTabs || [];
    banner.value = res.banner || "";
    if (res.title) uni.setNavigationBarTitle({ title: res.title });
    const rows = res.datalist || [];
    datalist.value = reset ? rows : datalist.value.concat(rows);
    page.value = p;
    hasMore.value = rows.length >= 10;
  } catch {
    /* request 已弹 toast */
  } finally {
    loading.value = false;
  }
}

function onTab(v: number | string) {
  const n = Number(v);
  if (cid.value === n) return;
  cid.value = n;
  void load(true);
}

function onSearch() {
  void load(true);
}

function goDetail(id: number) {
  goPage("/pagesC2/luntan/detail?id=" + id);
}

function goFatie() {
  goPage("/pagesC2/luntan/fatie?cid=" + cid.value);
}

onReachBottom(() => {
  if (hasMore.value && !loading.value) void load(false);
});
</script>

<template>
  <view class="page">
    <image v-if="banner" class="banner" :src="imgUrl(banner)" mode="aspectFill"></image>

    <view class="search-bar">
      <input
        v-model="keyword"
        class="search-input"
        placeholder="搜索帖子"
        confirm-type="search"
        @confirm="onSearch"
      />
      <view class="search-btn" @click="onSearch"><text>搜索</text></view>
    </view>

    <scroll-view v-if="tabs.length" class="tabs" scroll-x>
      <view v-for="t in tabs" :key="t.value" class="tab" :class="{ on: cid === Number(t.value) }" @click="onTab(t.value)">
        <text>{{ t.label }}</text>
      </view>
    </scroll-view>

    <view class="post-list">
      <C2Post v-for="p in datalist" :key="p.id" :post="p" @tap="goDetail" />
      <view v-if="datalist.length" class="list-tip">
        <text>{{ loading ? "加载中..." : hasMore ? "上拉加载更多" : "没有更多了" }}</text>
      </view>
      <C2Empty v-if="!datalist.length && !loading" text="该版块还没有帖子" />
    </view>

    <view class="fab" @click="goFatie"><text>发帖</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 120rpx;
}
.banner {
  width: 100%;
  height: 240rpx;
}
.search-bar {
  display: flex;
  align-items: center;
  padding: 20rpx 24rpx;
  background: #ffffff;
}
.search-input {
  flex: 1;
  height: 64rpx;
  background: #f2f2f6;
  border-radius: 32rpx;
  padding: 0 28rpx;
  font-size: 26rpx;
}
.search-btn {
  margin-left: 20rpx;
  padding: 14rpx 32rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 32rpx;
  font-size: 26rpx;
}
.tabs {
  white-space: nowrap;
  background: #ffffff;
  padding: 0 24rpx 16rpx;
}
.tab {
  display: inline-block;
  padding: 10rpx 28rpx;
  margin-right: 16rpx;
  font-size: 26rpx;
  color: #666666;
  background: #f2f2f6;
  border-radius: 28rpx;
}
.tab.on {
  background: #7b7de5;
  color: #ffffff;
}
.post-list {
  padding: 24rpx;
}
.list-tip {
  text-align: center;
  font-size: 24rpx;
  color: #b5b5b5;
  padding: 20rpx 0;
}
.fab {
  position: fixed;
  right: 32rpx;
  bottom: calc(60rpx + env(safe-area-inset-bottom));
  padding: 20rpx 44rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 48rpx;
  font-size: 28rpx;
  box-shadow: 0 8rpx 24rpx rgba(123, 125, 229, 0.4);
}
</style>
