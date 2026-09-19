<script setup lang="ts">
/**
 * dp-search 搜索框（Vue3 重写）
 * params: title(文案)/placeholder(占位)/hrefurl(搜索落地页)/color/borderradius/bordercolor/btn
 * 交互：输入关键字 → 回车或点搜索按钮 → hrefurl 追加 keyword 跳转；
 *       未配置 hrefurl 时兜底跳分类页
 */
import { computed, ref } from "vue";
import { num, str, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

/** 左侧文案（无配置不展示） */
const title = computed(() => str(props.params.title) || str(props.params.text));
const placeholder = computed(() => str(props.params.placeholder, "输入关键字搜索您感兴趣的商品"));
const textColor = computed(() => str(props.params.color, "#333333"));
const radius = computed(() => `${num(props.params.borderradius, 5) * 2}rpx`);
const borderColor = computed(() => str(props.params.bordercolor, "#e5e5e5"));
const showBtn = computed(() => str(props.params.btn, "1") !== "0");

const keyword = ref("");

function doSearch() {
  const kw = keyword.value.trim();
  let url = str(props.params.hrefurl);
  if (!url) {
    // 未配置搜索落地页：兜底分类页
    dpNavigate("/pages/category/category");
    return;
  }
  url += (url.indexOf("?") > 0 ? "&" : "?") + "keyword=" + encodeURIComponent(kw);
  dpNavigate(url);
}
</script>

<template>
  <view class="dp-search" :style="{ color: textColor }">
    <view v-if="title" class="dp-search-title">{{ title }}</view>
    <view class="dp-search-box" :style="{ borderRadius: radius, borderColor }">
      <text class="dp-search-ico">🔍</text>
      <input
        class="dp-search-input"
        v-model="keyword"
        confirm-type="search"
        :placeholder="placeholder"
        placeholder-style="color:#aaaaaa;font-size:26rpx"
        @confirm="doSearch"
      />
      <view v-if="showBtn" class="dp-search-btn" :style="{ borderRadius: radius }" @click="doSearch">搜索</view>
    </view>
  </view>
</template>

<style scoped>
.dp-search {
  display: flex;
  align-items: center;
}
.dp-search-title {
  flex-shrink: 0;
  margin-right: 16rpx;
  font-size: 28rpx;
  font-weight: 600;
}
.dp-search-box {
  flex: 1;
  display: flex;
  align-items: center;
  height: 72rpx;
  background: #ffffff;
  border: 1rpx solid #e5e5e5;
  overflow: hidden;
}
.dp-search-ico {
  width: 64rpx;
  text-align: center;
  font-size: 28rpx;
  flex-shrink: 0;
}
.dp-search-input {
  flex: 1;
  height: 72rpx;
  font-size: 26rpx;
  color: #333333;
}
.dp-search-btn {
  flex-shrink: 0;
  height: 56rpx;
  line-height: 56rpx;
  padding: 0 26rpx;
  margin-right: 8rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 26rpx;
}
</style>
