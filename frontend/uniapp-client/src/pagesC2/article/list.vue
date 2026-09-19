<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchArticleList, type ArticleItem, type C2Option } from "../api/article";
import { imgUrl } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import C2Empty from "../components/c2-empty.vue";

const PAGE_SIZE = 20;
const cid = ref<number | string>("");
const keyword = ref("");
const clist = ref<C2Option[]>([]);
const list = ref<ArticleItem[]>([]);
const pagenum = ref(1);
const loading = ref(false);
const hasMore = ref(true);

onLoad((options) => {
  if (options && options.cid) cid.value = options.cid;
  if (options && options.keyword) keyword.value = decodeURIComponent(options.keyword);
  void loadList(true);
});

async function loadList(reset: boolean) {
  if (loading.value) return;
  loading.value = true;
  try {
    const page = reset ? 1 : pagenum.value + 1;
    const res = await fetchArticleList({
      pagenum: page,
      cid: cid.value === "" ? undefined : Number(cid.value),
      keyword: keyword.value.trim() || undefined,
    });
    clist.value = res.clist || [];
    const rows = res.list || [];
    list.value = reset ? rows : list.value.concat(rows);
    pagenum.value = page;
    hasMore.value = rows.length >= PAGE_SIZE;
  } catch {
    /* request 已弹 toast */
  } finally {
    loading.value = false;
  }
}

function onTab(v: number | string) {
  if (cid.value === v) return;
  cid.value = v;
  void loadList(true);
}

function onSearch() {
  void loadList(true);
}

function goDetail(id: number) {
  goPage("/pagesC2/article/detail?id=" + id);
}

onReachBottom(() => {
  if (hasMore.value && !loading.value) void loadList(false);
});
</script>

<template>
  <view class="page">
    <view class="search-bar">
      <input
        v-model="keyword"
        class="search-input"
        placeholder="搜索文章标题"
        confirm-type="search"
        @confirm="onSearch"
      />
      <view class="search-btn" @click="onSearch"><text>搜索</text></view>
    </view>

    <scroll-view v-if="clist.length" class="tabs" scroll-x>
      <view class="tab" :class="{ on: cid === '' }" @click="onTab('')"><text>全部</text></view>
      <view v-for="c in clist" :key="c.value" class="tab" :class="{ on: cid === c.value }" @click="onTab(c.value)">
        <text>{{ c.label }}</text>
      </view>
    </scroll-view>

    <view v-if="list.length" class="art-list">
      <view v-for="item in list" :key="item.id" class="art-item" @click="goDetail(item.id)">
        <image v-if="item.pic" class="art-pic" :src="imgUrl(item.pic)" mode="aspectFill"></image>
        <view class="art-body">
          <text class="art-name">{{ item.name }}</text>
          <text v-if="item.subname" class="art-sub">{{ item.subname }}</text>
          <view class="art-meta">
            <text class="art-time">{{ item.sendtime_fmt }}</text>
            <text class="art-read">{{ item.readcount || 0 }} 阅读</text>
          </view>
        </view>
      </view>
      <view class="list-tip">
        <text>{{ loading ? "加载中..." : hasMore ? "上拉加载更多" : "没有更多了" }}</text>
      </view>
    </view>
    <C2Empty v-else-if="!loading" text="暂无文章" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
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
.art-list {
  padding: 24rpx;
}
.art-item {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
}
.art-pic {
  width: 180rpx;
  height: 130rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
}
.art-body {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.art-name {
  font-size: 30rpx;
  color: #333333;
  font-weight: 600;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
}
.art-sub {
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #999999;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.art-meta {
  margin-top: auto;
  display: flex;
  justify-content: space-between;
  font-size: 22rpx;
  color: #b5b5b5;
}
.list-tip {
  text-align: center;
  font-size: 24rpx;
  color: #b5b5b5;
  padding: 20rpx 0;
}
</style>
