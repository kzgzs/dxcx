<script setup lang="ts">
/**
 * dp-featured 精选（任务303 重做：左轮播+右双图，图片组件口径，非商品）
 * params: height(组件高度px，C端×2转rpx)/indicatordots(1/0)/dotshape(0默认1长方形2圆形3正方形)
 *         dotposition(left/center/right)/dotbottom(与下方距离px)/dotcolor/dotactivecolor
 *         interval(自动切换秒)/imgtop+imgtopurl/imgbot+imgboturl
 *         margin_x/margin_y/padding_x/padding_y/bgcolor（外盒由 DpRenderer boxStyle 应用；内间距=padding）
 * data: 轮播图列表 [{imgurl,hrefurl,hrefname}]
 * - 图片走 imgUrl 助手；点击走 dpNavigate
 */
import { computed, ref } from "vue";
import { num, str, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";
import { imgUrl } from "../../utils/format";

interface FeatImg {
  imgurl: string;
  hrefurl: string;
}

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

/** 轮播图列表 */
const slides = computed<FeatImg[]>(() =>
  (Array.isArray(props.data) ? props.data : [])
    .map((raw) => {
      const it = (raw && typeof raw === "object" ? raw : {}) as Record<string, unknown>;
      return { imgurl: str(it.imgurl), hrefurl: str(it.hrefurl) };
    })
    .filter((it) => it.imgurl)
);

const rightTop = computed<FeatImg>(() => ({ imgurl: str(props.params.imgtop), hrefurl: str(props.params.imgtopurl) }));
const rightBot = computed<FeatImg>(() => ({ imgurl: str(props.params.imgbot), hrefurl: str(props.params.imgboturl) }));

/** 组件高度 rpx（B端 px ×2） */
const heightRpx = computed(() => num(props.params.height, 249) * 2);
/** 内间距 rpx（左列与右列 gap 用 padding_x，右双图间 gap 用 padding_y） */
const gapXRpx = computed(() => num(props.params.padding_x, 10) * 2);
const gapYRpx = computed(() => num(props.params.padding_y, 10) * 2);

const showDots = computed(() => str(props.params.indicatordots, "1") === "1");
const dotShape = computed(() => str(props.params.dotshape, "0"));
const dotPos = computed(() => str(props.params.dotposition, "center"));
const dotBottomRpx = computed(() => num(props.params.dotbottom, 8) * 2);
const dotColor = computed(() => str(props.params.dotcolor, "#ffffff"));
const dotActiveColor = computed(() => str(props.params.dotactivecolor, "#3DB51E"));
const intervalMs = computed(() => Math.max(1, num(props.params.interval, 5)) * 1000);

const current = ref(0);
function onSwiperChange(e: { detail: { current: number } }) {
  current.value = e.detail?.current || 0;
}

function go(item: FeatImg) {
  if (item.hrefurl) dpNavigate(item.hrefurl);
}
</script>

<template>
  <view class="dp-featured" :style="{ height: heightRpx + 'rpx', gap: gapXRpx + 'rpx' }">
    <!-- 左：轮播 -->
    <view class="dp-featured-left">
      <swiper
        v-if="slides.length"
        class="dp-featured-swiper"
        :autoplay="slides.length > 1"
        :interval="intervalMs"
        :circular="true"
        :indicator-dots="false"
        @change="onSwiperChange"
      >
        <swiper-item v-for="(s, si) in slides" :key="si" @click="go(s)">
          <image class="dp-featured-img" :src="imgUrl(s.imgurl)" mode="aspectFill" />
        </swiper-item>
      </swiper>
      <view v-else class="dp-featured-ph"><text>轮播图</text></view>
      <view
        v-if="showDots && slides.length"
        class="dp-featured-dots"
        :class="['pos-' + dotPos, 'shape-' + dotShape]"
        :style="{ bottom: dotBottomRpx + 'rpx' }"
      >
        <view
          v-for="(s, si) in slides"
          :key="si"
          class="dp-featured-dot"
          :style="{ backgroundColor: si === current ? dotActiveColor : dotColor }"
        ></view>
      </view>
    </view>
    <!-- 右：上下双图 -->
    <view class="dp-featured-right" :style="{ gap: gapYRpx + 'rpx' }">
      <view class="dp-featured-rimg" @click="go(rightTop)">
        <image v-if="rightTop.imgurl" class="dp-featured-img" :src="imgUrl(rightTop.imgurl)" mode="aspectFill" />
        <view v-else class="dp-featured-ph"><text>右上方图片</text></view>
      </view>
      <view class="dp-featured-rimg" @click="go(rightBot)">
        <image v-if="rightBot.imgurl" class="dp-featured-img" :src="imgUrl(rightBot.imgurl)" mode="aspectFill" />
        <view v-else class="dp-featured-ph"><text>右下方图片</text></view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.dp-featured {
  display: flex;
  width: 100%;
  overflow: hidden;
  box-sizing: border-box;
}
.dp-featured-left {
  position: relative;
  flex: 1;
  min-width: 0;
  height: 100%;
  overflow: hidden;
  background: #f2f2f5;
}
.dp-featured-swiper {
  width: 100%;
  height: 100%;
}
.dp-featured-img {
  width: 100%;
  height: 100%;
}
.dp-featured-ph {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.dp-featured-right {
  flex: 1;
  min-width: 0;
  height: 100%;
  display: flex;
  flex-direction: column;
}
.dp-featured-rimg {
  position: relative;
  flex: 1;
  min-height: 0;
  overflow: hidden;
  background: #f2f2f5;
}
.dp-featured-dots {
  position: absolute;
  left: 0;
  right: 0;
  display: flex;
  gap: 8rpx;
}
.dp-featured-dots.pos-center {
  justify-content: center;
}
.dp-featured-dots.pos-left {
  justify-content: flex-start;
  padding-left: 16rpx;
}
.dp-featured-dots.pos-right {
  justify-content: flex-end;
  padding-right: 16rpx;
}
.dp-featured-dot {
  width: 28rpx;
  height: 6rpx;
  border-radius: 4rpx;
}
.dp-featured-dots.shape-1 .dp-featured-dot {
  width: 24rpx;
  height: 8rpx;
  border-radius: 2rpx;
}
.dp-featured-dots.shape-2 .dp-featured-dot {
  width: 12rpx;
  height: 12rpx;
  border-radius: 50%;
}
.dp-featured-dots.shape-3 .dp-featured-dot {
  width: 12rpx;
  height: 12rpx;
  border-radius: 0;
}
</style>
