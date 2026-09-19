<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom, onShow } from "@dcloudio/uni-app";
import { fetchFatielog } from "../api/luntan";
import type { LtPost } from "../api/luntan";
import { goPage } from "../../utils/navigate";
import C2Empty from "../components/c2-empty.vue";
import C2Post from "../components/c2-post.vue";

const page = ref(1);
const total = ref(0);
const datalist = ref<LtPost[]>([]);
const loading = ref(false);

async function load(reset: boolean) {
  if (loading.value) return;
  loading.value = true;
  try {
    const p = reset ? 1 : page.value + 1;
    const res = await fetchFatielog(p);
    const rows = res.list || [];
    datalist.value = reset ? rows : datalist.value.concat(rows);
    total.value = res.total || 0;
    page.value = p;
  } catch {
    /* request 已弹 toast */
  } finally {
    loading.value = false;
  }
}

onLoad(() => {
  void load(true);
});

onShow(() => {
  if (datalist.value.length) void load(true);
});

function goDetail(id: number) {
  goPage("/pagesC2/luntan/detail?id=" + id);
}

function goFatie() {
  goPage("/pagesC2/luntan/fatie");
}

onReachBottom(() => {
  if (datalist.value.length < total.value && !loading.value) void load(false);
});
</script>

<template>
  <view class="page">
    <view class="post-list">
      <C2Post v-for="p in datalist" :key="p.id" :post="p" @tap="goDetail" />
      <view v-if="datalist.length" class="list-tip">
        <text>{{ loading ? "加载中..." : datalist.length >= total ? "没有更多了" : "上拉加载更多" }}</text>
      </view>
      <C2Empty v-if="!datalist.length && !loading" text="你还没有发布过帖子" />
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
