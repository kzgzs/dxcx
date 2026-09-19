<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchSvMyupload, svDelete, svSetStatus } from "../api/shortvideo";
import type { SvVideo } from "../api/shortvideo";
import { imgUrl } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import C2Empty from "../components/c2-empty.vue";

const st = ref<number | string>("");
const list = ref<SvVideo[]>([]);
const counts = ref({ all: 0, c0: 0, c1: 0 });
const page = ref(1);
const loading = ref(false);
const hasMore = ref(true);

const TABS: Array<{ value: number | string; label: string }> = [
  { value: "", label: "全部" },
  { value: 0, label: "待审核" },
  { value: 1, label: "已上架" },
];

onLoad(() => {
  void load(true);
});

async function load(reset: boolean) {
  if (loading.value) return;
  loading.value = true;
  try {
    const p = reset ? 1 : page.value + 1;
    const res = await fetchSvMyupload({ st: st.value, page: p });
    counts.value = { all: res.countall || 0, c0: res.count0 || 0, c1: res.count1 || 0 };
    const rows = res.datalist || [];
    list.value = reset ? rows : list.value.concat(rows);
    page.value = p;
    hasMore.value = rows.length >= 10;
  } catch {
    /* request 已弹 toast */
  } finally {
    loading.value = false;
  }
}

function onTab(v: number | string) {
  if (st.value === v) return;
  st.value = v;
  void load(true);
}

function tabLabel(t: { value: number | string; label: string }): string {
  const n = t.value === "" ? counts.value.all : t.value === 0 ? counts.value.c0 : counts.value.c1;
  return t.label + " " + n;
}

async function toggleSt(item: SvVideo) {
  const target = Number(item.status) === 1 ? 0 : 1;
  try {
    await svSetStatus(item.id, target);
    item.status = target;
    uni.showToast({ title: target === 1 ? "已上架" : "已下架", icon: "none" });
  } catch {
    /* request 已弹 toast */
  }
}

function del(item: SvVideo) {
  uni.showModal({
    title: "提示",
    content: "确定删除该视频？",
    success: async (r) => {
      if (!r.confirm) return;
      try {
        await svDelete(item.id);
        void load(true);
      } catch {
        /* request 已弹 toast */
      }
    },
  });
}

function goDetail(item: SvVideo) {
  goPage("/pagesC2/shortvideo/detail?id=" + item.id);
}

onReachBottom(() => {
  if (hasMore.value && !loading.value) void load(false);
});
</script>

<template>
  <view class="page">
    <view class="tabs">
      <view v-for="t in TABS" :key="String(t.value)" class="tab" :class="{ on: st === t.value }" @click="onTab(t.value)">
        <text>{{ tabLabel(t) }}</text>
      </view>
    </view>

    <view v-for="item in list" :key="item.id" class="vid-card" @click="goDetail(item)">
      <image v-if="item.coverimg" class="vid-cover" :src="imgUrl(item.coverimg)" mode="aspectFill"></image>
      <view v-else class="vid-cover ph"><text>▶</text></view>
      <view class="vid-body">
        <text class="vid-name">{{ item.name || item.title || "未命名视频" }}</text>
        <view class="vid-meta">
          <text class="meta">▶ {{ item.view_num || 0 }}</text>
          <text class="meta">♥ {{ item.zan_num || 0 }}</text>
          <text class="vid-st" :class="Number(item.status) === 1 ? 'ok' : 'warn'">
            {{ Number(item.status) === 1 ? "已上架" : "待审核/下架" }}
          </text>
        </view>
        <view class="vid-acts">
          <text class="act" @click.stop="toggleSt(item)">{{ Number(item.status) === 1 ? "下架" : "上架" }}</text>
          <text class="act danger" @click.stop="del(item)">删除</text>
        </view>
      </view>
    </view>
    <view v-if="list.length" class="list-tip">
      <text>{{ loading ? "加载中..." : hasMore ? "上拉加载更多" : "没有更多了" }}</text>
    </view>
    <C2Empty v-if="!list.length && !loading" text="还没有上传过视频" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx;
  box-sizing: border-box;
}
.tabs {
  display: flex;
  margin-bottom: 20rpx;
}
.tab {
  flex: 1;
  text-align: center;
  padding: 16rpx 0;
  background: #ffffff;
  border-radius: 12rpx;
  margin-right: 16rpx;
  font-size: 26rpx;
  color: #666666;
}
.tab:last-child {
  margin-right: 0;
}
.tab.on {
  background: #7b7de5;
  color: #ffffff;
}
.vid-card {
  display: flex;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 20rpx;
  margin-bottom: 20rpx;
}
.vid-cover {
  width: 220rpx;
  height: 160rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
}
.vid-cover.ph {
  background: #ecebf7;
  color: #7b7de5;
  font-size: 48rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.vid-body {
  flex: 1;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.vid-name {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.vid-meta {
  margin-top: 12rpx;
  display: flex;
  align-items: center;
}
.meta {
  font-size: 22rpx;
  color: #b5b5b5;
  margin-right: 24rpx;
}
.vid-st {
  font-size: 22rpx;
  padding: 2rpx 14rpx;
  border-radius: 14rpx;
}
.vid-st.ok {
  background: #e8f7ee;
  color: #2e9e5b;
}
.vid-st.warn {
  background: #fff3e6;
  color: #e08a3c;
}
.vid-acts {
  margin-top: auto;
  display: flex;
  justify-content: flex-end;
}
.act {
  font-size: 24rpx;
  color: #7b7de5;
  margin-left: 32rpx;
}
.act.danger {
  color: #e64340;
}
.list-tip {
  text-align: center;
  font-size: 24rpx;
  color: #b5b5b5;
  padding: 20rpx 0;
}
</style>
