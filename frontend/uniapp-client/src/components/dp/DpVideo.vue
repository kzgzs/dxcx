<script setup lang="ts">
/**
 * dp-video 视频播放（任务246 P27-2）
 * params:
 * - src          视频地址（必填，缺失不渲染）
 * - pic          封面图（poster）
 * - autoplay     自动播放 '0'/'1'（默认 0）
 * - controls     显示控制条 '0'/'1'（默认 1）
 * - loop         循环播放 '0'/'1'（默认 0）
 * - muted        静音 '0'/'1'（默认 0）
 * - fullscreen   显示全屏按钮 '0'/'1'（默认 1）
 * - objectfit    填充模式 contain/fill/cover（默认 contain）
 * - videoheight  高度百分比：100% = 9:16 竖屏（宽*16/9），默认 30
 * - borderradius 圆角
 */
import { computed } from "vue";
import { num, str, flag, type DpParams } from "./shared";
import { imgUrl } from "../../utils/format";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

/** 视频地址（外链直用，相对路径走 imgUrl 补全） */
const src = computed(() => {
  const s = str(props.params.src);
  if (!s) return "";
  return /^https?:\/\//.test(s) ? s : imgUrl(s);
});
/** 封面图 */
const poster = computed(() => imgUrl(str(props.params.pic)));
/** 高度：100% = 9:16 竖屏（窗口宽 * 16/9），按 videoheight% 缩放 */
const boxHeight = computed(() => {
  const percent = Math.min(100, Math.max(10, num(props.params.videoheight, 30)));
  let ww = 375;
  try {
    const info = uni.getSystemInfoSync();
    if (info.windowWidth > 0) ww = info.windowWidth;
  } catch {
    /* 获取失败用兜底宽度 */
  }
  return `${Math.round(((ww * 16) / 9 * percent) / 100)}px`;
});
const radius = computed(() => `${num(props.params.borderradius) * 2.2}rpx`);
const autoplay = computed(() => flag(props.params.autoplay) === 1);
const controls = computed(() => flag(props.params.controls, 1) === 1);
const loop = computed(() => flag(props.params.loop) === 1);
const muted = computed(() => flag(props.params.muted) === 1);
const fullscreen = computed(() => flag(props.params.fullscreen, 1) === 1);
/** 填充模式（字面量联合，兼容 StyleValue 类型） */
type VideoFit = "contain" | "fill" | "cover";
const objectFit = computed<VideoFit>(() => {
  const v = str(props.params.objectfit, "contain");
  return v === "fill" || v === "cover" ? v : "contain";
});
</script>

<template>
  <view v-if="src" class="dp-video">
    <!-- #ifdef H5 -->
    <video
      class="dp-video-player"
      :style="{ height: boxHeight, borderRadius: radius, objectFit: objectFit }"
      :src="src"
      :poster="poster"
      :autoplay="autoplay"
      :controls="controls"
      :loop="loop"
      :muted="muted"
      preload="metadata"
      playsinline
      webkit-playsinline
    ></video>
    <!-- #endif -->
    <!-- #ifndef H5 -->
    <video
      class="dp-video-player"
      :style="{ height: boxHeight, borderRadius: radius }"
      :src="src"
      :poster="poster"
      :autoplay="autoplay"
      :controls="controls"
      :loop="loop"
      :muted="muted"
      :show-fullscreen-btn="fullscreen"
      :show-mute-btn="true"
      :object-fit="objectFit"
    ></video>
    <!-- #endif -->
  </view>
</template>

<style scoped>
.dp-video {
  font-size: 0;
}
.dp-video-player {
  width: 100%;
  margin: 0;
  padding: 0;
  background: #000;
}
</style>
