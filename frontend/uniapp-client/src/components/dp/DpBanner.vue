<script setup lang="ts">
/**
 * dp-banner 轮播图（任务325 对照点大截图重做）
 * params: height(轮播图高度px，C端×2转rpx)/borderradius(圆角度)/interval(自动切换秒)/bgcolor(外盒由 DpRenderer boxStyle 应用)
 *         indicatordots(1显示/0不显示)/dotshape(0默认=swiper原生点 1长方形 2圆形 3正方形)
 *         dotposition(left/center/right)/dotbottom(指示点与下方距离px)/dotcolor(默认#EDEEF0)/dotactivecolor(默认#3DB51E)
 *         margin_x/margin_y/padding_x/padding_y（外盒由 DpRenderer boxStyle 应用）
 * data: [{imgurl,hrefurl,hrefname}]
 * - 旧数据兼容：dotshape 缺省回退旧 shape（shape1长方形/shape2正方形/shape3圆形）映射；dotposition 缺省回退旧 align
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

const slides = computed(() => {
  const arr = Array.isArray(props.data) ? props.data : [];
  return arr.filter((it) => it && typeof it === "object") as Record<string, unknown>[];
});

/** 轮播高度（设计 px ×2 → rpx，兜底 200） */
const swiperHeight = computed(() => `${num(props.params.height, 200) * 2}rpx`);
/** 自动切换间隔（秒 → ms，兜底 5） */
const interval = computed(() => Math.max(1, num(props.params.interval, 5)) * 1000);
/** 圆角度 */
const radius = computed(() => `${num(props.params.borderradius, 0)}px`);
/** 指示点显隐 */
const showDots = computed(() => str(props.params.indicatordots, "1") === "1" && slides.value.length > 1);
/** 指示点形状：0默认 1长方形 2圆形 3正方形（旧 shape 兼容映射） */
const dotShape = computed(() => {
  const v = str(props.params.dotshape, "");
  if (["0", "1", "2", "3"].includes(v)) return v;
  const old = str(props.params.shape, "");
  if (old === "shape1") return "1";
  if (old === "shape3") return "2";
  if (old === "shape2") return "3";
  return "0";
});
/** 默认=跟随 swiper 原生指示点；其余自定义点覆盖渲染 */
const useNativeDots = computed(() => showDots.value && dotShape.value === "0");
const useCustomDots = computed(() => showDots.value && dotShape.value !== "0");
const dotColor = computed(() => str(props.params.dotcolor, "#EDEEF0"));
const dotActiveColor = computed(() => str(props.params.dotactivecolor, "#3DB51E"));
const dotAlign = computed(() => {
  const a = str(props.params.dotposition, "") || str(props.params.align, "center");
  return a === "left" ? "flex-start" : a === "right" ? "flex-end" : "center";
});
/** 指示点与下方距离（px ×2 → rpx，兜底 8） */
const dotBottom = computed(() => `${num(props.params.dotbottom, 8) * 2}rpx`);

const current = ref(0);
function onChange(e: { detail: { current: number } }) {
  current.value = e.detail.current;
}
function onTap(item: Record<string, unknown>) {
  dpNavigate(str(item.hrefurl));
}
</script>

<template>
  <view v-if="slides.length > 0" class="dp-banner">
    <swiper
      class="dp-banner-swiper"
      :style="{ height: swiperHeight }"
      autoplay
      circular
      :interval="interval"
      :indicator-dots="useNativeDots"
      :indicator-color="dotColor"
      :indicator-active-color="dotActiveColor"
      @change="onChange"
    >
      <swiper-item v-for="(item, index) in slides" :key="str(item.id, 'b' + index)">
        <view class="dp-banner-slide" :style="{ height: swiperHeight, borderRadius: radius }" @click="onTap(item)">
          <image class="dp-banner-img" :src="imgUrl(str(item.imgurl))" mode="aspectFill" lazy-load />
        </view>
      </swiper-item>
    </swiper>
    <!-- 自定义指示点（长方形/圆形/正方形） -->
    <view v-if="useCustomDots" class="dp-banner-dots" :style="{ justifyContent: dotAlign, bottom: dotBottom }">
      <view
        v-for="(item, index) in slides"
        :key="'dot' + index"
        class="dp-banner-dot"
        :class="['dp-banner-dot--s' + dotShape, current === index ? 'dp-banner-dot--active' : '']"
        :style="{ backgroundColor: current === index ? dotActiveColor : dotColor }"
      ></view>
    </view>
  </view>
</template>

<style scoped>
.dp-banner {
  position: relative;
}
.dp-banner-swiper {
  width: 100%;
}
.dp-banner-slide {
  width: 100%;
  overflow: hidden;
}
.dp-banner-img {
  width: 100%;
  height: 100%;
}
.dp-banner-dots {
  position: absolute;
  left: 0;
  right: 0;
  display: flex;
  gap: 8rpx;
}
.dp-banner-dot {
  transition: all 0.2s;
}
/* 长方形 */
.dp-banner-dot--s1 {
  width: 24rpx;
  height: 8rpx;
  border-radius: 2rpx;
}
/* 圆形 */
.dp-banner-dot--s2 {
  width: 14rpx;
  height: 14rpx;
  border-radius: 50%;
}
/* 正方形 */
.dp-banner-dot--s3 {
  width: 12rpx;
  height: 12rpx;
  border-radius: 0;
}
</style>
