<script setup lang="ts">
/**
 * dp-text 文本（Vue3 重写）
 * params: content/fontsize/lineheight/letterspacing/isbold/align/color/bgcolor/bgimg/hrefurl
 * 旧数据兼容：content 回退 showcontent；isbold 回退 fontbold
 */
import { computed, type CSSProperties } from "vue";
import { num, str, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const content = computed(() => str(props.params.content) || str(props.params.showcontent, "文本内容"));
const textAlign = computed(() => str(props.params.align, "left") as CSSProperties["textAlign"]);
const color = computed(() => str(props.params.color, "#000"));
const fontSize = computed(() => `${num(props.params.fontsize, 14) * 2}rpx`);
const lineHeight = computed(() => `${num(props.params.lineheight, 20) * 2}rpx`);
const letterSpacing = computed(() => `${num(props.params.letterspacing, 0) * 2}rpx`);
const fontWeight = computed(() => (str(props.params.isbold, str(props.params.fontbold, "0")) !== "0" ? "bold" : "normal"));
const bgimg = computed(() => str(props.params.bgimg));
const hrefurl = computed(() => str(props.params.hrefurl));

const boxStyle = computed<CSSProperties>(() => {
  const s: CSSProperties = {};
  if (bgimg.value) {
    s.backgroundImage = `url(${bgimg.value})`;
    s.backgroundSize = "cover";
    s.backgroundPosition = "center";
  }
  return s;
});

function onTap() {
  if (hrefurl.value) dpNavigate(hrefurl.value);
}
</script>

<template>
  <view class="dp-text" :style="{ ...boxStyle, textAlign, color, fontWeight }" @click="onTap">
    <text :style="{ fontSize, lineHeight, letterSpacing }">{{ content }}</text>
  </view>
</template>

<style scoped>
.dp-text {
  white-space: pre-wrap;
  word-break: break-all;
}
</style>
