<script setup lang="ts">
/**
 * dp-notice 公告条（Vue3 重写）
 * data: [{title,hrefurl}]
 * params: showimg/img/showicon/icon/color/bgcolor/fontsize/scroll
 * - 单条：静态展示（超长省略）；多条：垂直轮播
 */
import { computed } from "vue";
import { num, str, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const items = computed(() => {
  const arr = Array.isArray(props.data) ? props.data : [];
  return arr.filter((it) => it && typeof it === "object") as Record<string, unknown>[];
});

/** 左侧喇叭图标（showicon=1 生效，无图用文字兜底） */
const showIcon = computed(() => str(props.params.showicon, "0") === "1");
const iconUrl = computed(() => str(props.params.icon));
/** 左侧图片（showimg=1 生效） */
const showImg = computed(() => str(props.params.showimg, "0") === "1");
const imgUrl = computed(() => str(props.params.img));
const fontSize = computed(() => `${num(props.params.fontsize, 14) * 2.2}rpx`);
const textColor = computed(() => str(props.params.color, "#666666"));
const scrollInterval = computed(() => Math.max(1, num(props.params.scroll, 3)) * 1000);

function onTap(item: Record<string, unknown>) {
  dpNavigate(item.hrefurl);
}
</script>

<template>
  <view v-if="items.length > 0" class="dp-notice" :style="{ color: textColor }">
    <view v-if="showImg && imgUrl" class="dp-notice-imgbox">
      <image class="dp-notice-img" :src="imgUrl" mode="heightFix" lazy-load />
    </view>
    <view class="dp-notice-body">
      <image v-if="showIcon && iconUrl" class="dp-notice-icon" :src="iconUrl" lazy-load />
      <text v-else-if="showIcon" class="dp-notice-icon dp-notice-icon-text">📢</text>
      <!-- 单条静态 -->
      <view v-if="items.length === 1" class="dp-notice-single" :style="{ fontSize }" @click="onTap(items[0])">
        <text class="dp-notice-single-text">{{ str(items[0].title, "公告") }}</text>
      </view>
      <!-- 多条垂直轮播 -->
      <swiper
        v-else
        class="dp-notice-swiper"
        vertical
        autoplay
        circular
        :interval="scrollInterval"
        :style="{ fontSize }"
      >
        <swiper-item v-for="(item, index) in items" :key="str(item.id, 'n' + index)" @click="onTap(item)">
          <text class="dp-notice-swiper-text">{{ str(item.title, "公告") }}</text>
        </swiper-item>
      </swiper>
    </view>
  </view>
</template>

<style scoped>
.dp-notice {
  display: flex;
  align-items: center;
  overflow: hidden;
}
.dp-notice-imgbox {
  display: flex;
  align-items: center;
  height: 44rpx;
  padding-right: 20rpx;
  margin-right: 20rpx;
  border-right: 1rpx solid #e2e2e2;
}
.dp-notice-img {
  height: 36rpx;
  width: auto;
}
.dp-notice-body {
  flex: 1;
  display: flex;
  align-items: center;
  overflow: hidden;
}
.dp-notice-icon {
  width: 36rpx;
  height: 36rpx;
  margin-right: 12rpx;
  flex-shrink: 0;
}
.dp-notice-icon-text {
  line-height: 36rpx;
  text-align: center;
  font-size: 30rpx;
}
.dp-notice-single {
  flex: 1;
  overflow: hidden;
  white-space: nowrap;
}
.dp-notice-single-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
}
.dp-notice-swiper {
  flex: 1;
  height: 44rpx;
  line-height: 44rpx;
}
.dp-notice-swiper-text {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
