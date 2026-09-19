<script setup lang="ts">
interface TabItem {
  path: string;
  text: string;
  icon: string;
}

const props = defineProps<{ current: string }>();

/** 简单 SVG path 图标（24x24 描边） */
const tabs: TabItem[] = [
  {
    path: "/pages/index/index",
    text: "首页",
    icon: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
  },
  {
    path: "/pages/category/category",
    text: "分类",
    icon: '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/>',
  },
  {
    path: "/pages/cart/cart",
    text: "购物车",
    icon: '<circle cx="9" cy="20" r="1.6"/><circle cx="18" cy="20" r="1.6"/><path d="M2.5 3.5h2.5l2.4 12h11.2l2-8.5H6"/>',
  },
  {
    path: "/pages/my/my",
    text: "我的",
    icon: '<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5c1.2-3.6 4-5.5 7.5-5.5s6.3 1.9 7.5 5.5"/>',
  },
];

function switchTo(path: string) {
  if (path === props.current) return;
  uni.reLaunch({ url: path });
}
</script>

<template>
  <view class="tabbar">
    <view
      v-for="tab in tabs"
      :key="tab.path"
      class="tab-item"
      :class="{ active: tab.path === current }"
      @click="switchTo(tab.path)"
    >
      <!-- #ifdef H5 -->
      <svg
        class="tab-icon"
        viewBox="0 0 24 24"
        fill="none"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        v-html="tab.icon"
      ></svg>
      <!-- #endif -->
      <!-- #ifndef H5 -->
      <view class="tab-icon-fallback">
        <text>{{ tab.text.charAt(0) }}</text>
      </view>
      <!-- #endif -->
      <text class="tab-text">{{ tab.text }}</text>
    </view>
  </view>
</template>

<style scoped>
.tabbar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 99;
  display: flex;
  height: 100rpx;
  background: #ffffff;
  border-top: 1rpx solid #eeeeee;
  padding-bottom: env(safe-area-inset-bottom);
}
.tab-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #999999;
}
.tab-item.active {
  color: #7b7de5;
}
.tab-icon {
  width: 44rpx;
  height: 44rpx;
  stroke: currentColor;
}
.tab-icon-fallback {
  width: 44rpx;
  height: 44rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2rpx solid currentColor;
  border-radius: 10rpx;
  font-size: 24rpx;
}
.tab-text {
  margin-top: 4rpx;
  font-size: 22rpx;
  line-height: 1;
}
</style>
