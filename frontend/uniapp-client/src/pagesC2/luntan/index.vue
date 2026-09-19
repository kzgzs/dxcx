<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchLtIndex } from "../api/luntan";
import type { C2Option } from "../api/article";
import type { LtPost, LtSysset } from "../api/luntan";
import { imgUrl } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import C2Empty from "../components/c2-empty.vue";
import C2Post from "../components/c2-post.vue";

const page = ref(1);
const datalist = ref<LtPost[]>([]);
const clist = ref<C2Option[]>([]);
const sysset = ref<LtSysset | null>(null);
const title = ref("社区论坛");
const loading = ref(false);
const hasMore = ref(true);

onLoad(() => {
  void load(true);
});

async function load(reset: boolean) {
  if (loading.value) return;
  loading.value = true;
  try {
    const p = reset ? 1 : page.value + 1;
    const res = await fetchLtIndex(p);
    if (p === 1) {
      clist.value = res.clist || [];
      sysset.value = res.sysset || null;
      if (res.title) title.value = res.title;
      uni.setNavigationBarTitle({ title: title.value });
    }
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

function goList(cid: number | string) {
  goPage("/pagesC2/luntan/list?cid=" + cid);
}

function goDetail(id: number) {
  goPage("/pagesC2/luntan/detail?id=" + id);
}

function goFatie() {
  goPage("/pagesC2/luntan/fatie");
}

function goMyFatie() {
  goPage("/pagesC2/luntan/myfatie");
}

onReachBottom(() => {
  if (hasMore.value && !loading.value) void load(false);
});
</script>

<template>
  <view class="page">
    <image v-if="sysset && sysset.banner" class="banner" :src="imgUrl(sysset.banner)" mode="aspectFill"></image>

    <view class="toolbar">
      <scroll-view class="tabs" scroll-x>
        <view v-for="c in clist" :key="c.value" class="tab" @click="goList(c.value)">
          <text>{{ c.label }}</text>
        </view>
      </scroll-view>
      <view class="tb-btns">
        <text class="tb-btn" @click="goMyFatie">我的发帖</text>
        <text class="tb-btn primary" @click="goFatie">＋ 发帖</text>
      </view>
    </view>

    <view class="post-list">
      <C2Post v-for="p in datalist" :key="p.id" :post="p" @tap="goDetail" />
      <view v-if="datalist.length" class="list-tip">
        <text>{{ loading ? "加载中..." : hasMore ? "上拉加载更多" : "没有更多了" }}</text>
      </view>
      <C2Empty v-if="!datalist.length && !loading" text="还没有帖子，快来发第一帖" />
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.banner {
  width: 100%;
  height: 260rpx;
}
.toolbar {
  background: #ffffff;
  padding: 16rpx 24rpx;
  display: flex;
  align-items: center;
}
.tabs {
  flex: 1;
  white-space: nowrap;
}
.tab {
  display: inline-block;
  padding: 10rpx 26rpx;
  margin-right: 16rpx;
  font-size: 26rpx;
  color: #666666;
  background: #f2f2f6;
  border-radius: 28rpx;
}
.tb-btns {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}
.tb-btn {
  font-size: 24rpx;
  color: #666666;
  margin-left: 20rpx;
}
.tb-btn.primary {
  padding: 10rpx 28rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 28rpx;
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
</style>
