<script setup lang="ts">
/**
 * dp-menu 宫格导航（Vue3 重写）
 * data: [{imgurl,title|text,hrefurl}]
 * params: num(列数 2-5)/showicon/iconsize/fontsize/newdata(分页数据)
 * - 超出单页容量（列数 × 行数）自动分页横滑，带指示条
 */
import { computed, ref } from "vue";
import { num, str, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";
import { imgUrl } from "../../utils/format";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

/** 单项结构 */
interface MenuItem {
  imgurl: string;
  title: string;
  hrefurl: unknown;
  color: string;
}

function normalize(raw: unknown): MenuItem {
  const it = (raw && typeof raw === "object" ? raw : {}) as Record<string, unknown>;
  return {
    imgurl: str(it.imgurl),
    title: str(it.title) || str(it.text) || "导航",
    hrefurl: it.hrefurl,
    color: str(it.color, "#333333"),
  };
}

/** 列数（2-5，默认 4） */
const columns = computed(() => {
  const n = num(props.params.num, 4);
  return n >= 2 && n <= 5 ? n : 4;
});
/** 每页行数（默认 2） */
const lineNum = computed(() => Math.max(1, num(props.params.newdata_linenum, 2)));
const showIcon = computed(() => str(props.params.showicon, "1") !== "0");
const iconSize = computed(() => `${num(props.params.iconsize, 40) * 2.2}rpx`);
const fontSize = computed(() => `${num(props.params.fontsize, 12) * 2.2}rpx`);

/** 分页后的数据：params.newdata（设计器已分页）优先，否则按 列数×行数 切页 */
const pages = computed<MenuItem[][]>(() => {
  const raw = props.data;
  // 设计器已分页：newdata 为二维数组
  if (Array.isArray(props.params.newdata) && (props.params.newdata as unknown[]).length > 0) {
    return (props.params.newdata as unknown[][])
      .filter((page) => Array.isArray(page))
      .map((page) => page.map(normalize));
  }
  const items = (Array.isArray(raw) ? raw : []).map(normalize);
  if (items.length === 0) return [];
  const perPage = columns.value * lineNum.value;
  const result: MenuItem[][] = [];
  for (let i = 0; i < items.length; i += perPage) {
    result.push(items.slice(i, i + perPage));
  }
  return result;
});

const pageIndex = ref(0);
function onPageChange(e: { detail: { current: number } }) {
  pageIndex.value = e.detail.current;
}
/** 单页 swiper 高度估算：行数 ×（图标 + 文字 + 间距） */
const pageHeight = computed(() => `${lineNum.value * (num(props.params.iconsize, 40) * 2.2 + num(props.params.fontsize, 12) * 2 + 56)}rpx`);

function onTap(item: MenuItem) {
  dpNavigate(item.hrefurl);
}
</script>

<template>
  <view v-if="pages.length > 0" class="dp-menu">
    <!-- 多页：横滑分页 + 指示条 -->
    <block v-if="pages.length > 1">
      <swiper class="dp-menu-swiper" :style="{ height: pageHeight }" :indicator-dots="false" @change="onPageChange">
        <swiper-item v-for="(page, pIdx) in pages" :key="'page' + pIdx">
          <view class="dp-menu-grid">
            <view
              v-for="(item, iIdx) in page"
              :key="'pi' + pIdx + '-' + iIdx"
              class="dp-menu-item"
              :style="{ width: 100 / columns + '%' }"
              @click="onTap(item)"
            >
              <image v-if="showIcon && item.imgurl" class="dp-menu-icon" :src="imgUrl(item.imgurl)" :style="{ width: iconSize, height: iconSize }" mode="aspectFill" lazy-load />
              <text class="dp-menu-text" :style="{ fontSize, color: item.color }">{{ item.title }}</text>
            </view>
          </view>
        </swiper-item>
      </swiper>
      <view class="dp-menu-dots">
        <view
          v-for="(page, index) in pages"
          :key="'mdot' + index"
          class="dp-menu-dot"
          :class="pageIndex === index ? 'dp-menu-dot--active' : ''"
        ></view>
      </view>
    </block>
    <!-- 单页：直接宫格铺开 -->
    <view v-else class="dp-menu-grid">
      <view
        v-for="(item, index) in pages[0]"
        :key="'m' + index"
        class="dp-menu-item"
        :style="{ width: 100 / columns + '%' }"
        @click="onTap(item)"
      >
        <image v-if="showIcon && item.imgurl" class="dp-menu-icon" :src="imgUrl(item.imgurl)" :style="{ width: iconSize, height: iconSize }" mode="aspectFill" lazy-load />
        <text class="dp-menu-text" :style="{ fontSize, color: item.color }">{{ item.title }}</text>
      </view>
    </view>
  </view>
</template>

<style scoped>
.dp-menu {
  position: relative;
}
.dp-menu-swiper {
  width: 100%;
}
.dp-menu-grid {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  padding-top: 8rpx;
}
.dp-menu-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 20rpx;
  box-sizing: border-box;
}
.dp-menu-icon {
  border-radius: 12rpx;
}
.dp-menu-text {
  margin-top: 8rpx;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.dp-menu-dots {
  display: flex;
  justify-content: center;
  padding: 4rpx 0 12rpx;
}
.dp-menu-dot {
  width: 16rpx;
  height: 6rpx;
  margin: 0 4rpx;
  border-radius: 3rpx;
  background: #edeef0;
  transition: all 0.2s;
}
.dp-menu-dot--active {
  width: 26rpx;
  background: #3db51e;
}
</style>
