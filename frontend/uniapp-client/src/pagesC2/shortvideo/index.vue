<script setup lang="ts">
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchSvIndex, svZan } from "../api/shortvideo";
import type { C2Option } from "../api/article";
import type { SvVideo } from "../api/shortvideo";
import { imgUrl } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const list = ref<SvVideo[]>([]);
const clist = ref<C2Option[]>([]);
const cid = ref(0);
const current = ref(0);
const page = ref(1);
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
    const res = await fetchSvIndex({ cid: cid.value || undefined, page: p });
    if (p === 1) clist.value = res.clist || [];
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

function onSwiperChange(e: { detail: { current: number } }) {
  current.value = e.detail.current;
  // 接近末尾预载下一页
  if (hasMore.value && e.detail.current >= list.value.length - 2) {
    void load(false);
  }
}

function onTab(v: number | string) {
  const n = Number(v);
  if (cid.value === n) return;
  cid.value = n;
  current.value = 0;
  void load(true);
}

async function onZan(item: SvVideo) {
  try {
    const res = await svZan(item.id);
    item.iszan = res.iszan;
    item.zan_num = res.zan_num;
  } catch {
    /* request 已弹 toast */
  }
}

function goDetail(item: SvVideo) {
  goPage("/pagesC2/shortvideo/detail?id=" + item.id + "&cid=" + cid.value);
}

function videoSrc(item: SvVideo): string {
  const raw = item.url || item.src || "";
  return imgUrl(raw);
}
</script>

<template>
  <view class="sv-page">
    <swiper
      class="sv-swiper"
      vertical
      :current="current"
      :circular="false"
      @change="onSwiperChange"
    >
      <swiper-item v-for="(item, i) in list" :key="item.id">
        <view class="sv-item" @click="goDetail(item)">
          <!-- 仅当前页渲染 video，其余用封面占位控性能 -->
          <video
            v-if="i === current"
            class="sv-video"
            :src="videoSrc(item)"
            :poster="item.coverimg ? imgUrl(item.coverimg) : ''"
            autoplay
            loop
            :controls="false"
            object-fit="cover"
            :show-center-play-btn="false"
          ></video>
          <image v-else-if="item.coverimg" class="sv-cover" :src="imgUrl(item.coverimg)" mode="aspectFill"></image>
          <view v-else class="sv-cover ph"><text>▶</text></view>

          <!-- 信息浮层 -->
          <view class="sv-info">
            <text class="sv-name">{{ item.name || item.title || "未命名视频" }}</text>
            <text v-if="item.content" class="sv-desc">{{ item.content }}</text>
            <view class="sv-stats">
              <text class="stat">▶ {{ item.view_num || 0 }}</text>
              <text class="stat">💬 {{ item.commentnum || 0 }}</text>
            </view>
          </view>

          <!-- 右侧操作列 -->
          <view class="sv-side">
            <view class="side-btn" :class="{ on: item.iszan === 1 }" @click.stop="onZan(item)">
              <text class="side-icon">♥</text>
              <text class="side-num">{{ item.zan_num || 0 }}</text>
            </view>
            <view class="side-btn" @click.stop="goDetail(item)">
              <text class="side-icon">💬</text>
              <text class="side-num">{{ item.commentnum || 0 }}</text>
            </view>
          </view>
        </view>
      </swiper-item>
    </swiper>

    <!-- 顶部分类 -->
    <scroll-view v-if="clist.length" class="sv-tabs" scroll-x>
      <view class="sv-tab" :class="{ on: cid === 0 }" @click="onTab(0)"><text>全部</text></view>
      <view
        v-for="c in clist"
        :key="c.value"
        class="sv-tab"
        :class="{ on: cid === Number(c.value) }"
        @click="onTab(c.value)"
      >
        <text>{{ c.label }}</text>
      </view>
    </scroll-view>

    <view class="sv-empty" v-if="!list.length && !loading"><text>暂无视频</text></view>
  </view>
</template>

<style scoped>
.sv-page {
  height: 100vh;
  background: #000000;
  position: relative;
}
.sv-swiper {
  width: 100%;
  height: 100%;
}
.sv-item {
  width: 100%;
  height: 100%;
  position: relative;
}
.sv-video {
  width: 100%;
  height: 100%;
}
.sv-cover {
  width: 100%;
  height: 100%;
}
.sv-cover.ph {
  background: #1a1a22;
  color: #555566;
  font-size: 80rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sv-info {
  position: absolute;
  left: 24rpx;
  right: 160rpx;
  bottom: calc(60rpx + env(safe-area-inset-bottom));
}
.sv-name {
  color: #ffffff;
  font-size: 32rpx;
  font-weight: 600;
}
.sv-desc {
  margin-top: 10rpx;
  color: rgba(255, 255, 255, 0.8);
  font-size: 26rpx;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
}
.sv-stats {
  margin-top: 12rpx;
}
.stat {
  color: rgba(255, 255, 255, 0.7);
  font-size: 22rpx;
  margin-right: 28rpx;
}
.sv-side {
  position: absolute;
  right: 24rpx;
  bottom: calc(120rpx + env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
}
.side-btn {
  margin-top: 32rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  color: #ffffff;
}
.side-icon {
  font-size: 56rpx;
}
.side-btn.on .side-icon {
  color: #ff4d6d;
}
.side-num {
  font-size: 22rpx;
  margin-top: 4rpx;
}
.sv-tabs {
  position: absolute;
  top: calc(20rpx + env(safe-area-inset-top));
  left: 0;
  right: 0;
  white-space: nowrap;
  padding: 0 24rpx;
}
.sv-tab {
  display: inline-block;
  padding: 8rpx 28rpx;
  margin-right: 16rpx;
  border-radius: 28rpx;
  background: rgba(255, 255, 255, 0.15);
  color: #ffffff;
  font-size: 26rpx;
}
.sv-tab.on {
  background: #7b7de5;
}
.sv-empty {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #888899;
  font-size: 28rpx;
}
</style>
