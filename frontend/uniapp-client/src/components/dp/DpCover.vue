<script setup lang="ts">
/**
 * dp-cover 悬浮按钮（任务246 P27-2）
 * fixed 定位，默认右下角；设计器给 top/left 时按 vh/vw 精确定位
 * params（参考口径）:
 * - style        '1' 文字按钮 / '2' 图片按钮
 * - text         文字按钮文案
 * - pic          图片按钮图片
 * - picwidth/picheight 图片尺寸（设计值 *2.2 → rpx）
 * - hrefurl      点击跳转
 * - top/left     定位（vh/vw，缺省默认右下角）
 * - width/height 按钮尺寸（设计值 *2.2 → rpx）
 * - radius       圆角（设计值 *2.2 → rpx）
 * - background/bgcolor 背景色，color/fontsize 文字样式
 * - border/bordercolor 描边
 */
import { computed } from "vue";
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

const mode = computed(() => (str(props.params.style, "2") === "1" ? "text" : "image"));
const pic = computed(() => imgUrl(str(props.params.pic)));
const text = computed(() => str(props.params.text, "悬浮"));

/** 定位：top/left 任一有值则按 vh/vw 定位，否则默认右下角 */
const posStyle = computed(() => {
  const p = props.params;
  const top = str(p.top);
  const left = str(p.left);
  const style: Record<string, string> = {};
  if (top !== "") style.top = `${num(p.top)}vh`;
  if (left !== "") style.left = `${num(p.left)}vw`;
  if (top === "") style.bottom = "calc(140rpx + env(safe-area-inset-bottom))";
  if (left === "") style.right = "24rpx";
  return style;
});

const btnStyle = computed(() => {
  const p = props.params;
  const style: Record<string, string> = {};
  const w = num(p.width, 48);
  const h = num(p.height, 48);
  style.width = `${w * 2.2}rpx`;
  style.height = `${h * 2.2}rpx`;
  style.borderRadius = `${num(p.radius, 24) * 2.2}rpx`;
  style.background = str(p.bgcolor) || str(p.background, "#FD4A46");
  style.color = str(p.color, "#FFFFFF");
  style.fontSize = `${num(p.fontsize, 12) * 2}rpx`;
  const bw = num(p.border);
  if (bw > 0) style.border = `${bw * 2.2}rpx solid ${str(p.bordercolor, "#FFFFFF")}`;
  return style;
});

function onTap() {
  dpNavigate(props.params.hrefurl);
}
</script>

<template>
  <view class="dp-cover" :style="posStyle" @click="onTap">
    <view class="dp-cover-btn" :style="btnStyle">
      <text v-if="mode === 'text'" class="dp-cover-text">{{ text }}</text>
      <image
        v-else-if="pic"
        class="dp-cover-img"
        :src="pic"
        :style="{
          width: `${num(props.params.picwidth, 28) * 2.2}rpx`,
          height: `${num(props.params.picheight, 28) * 2.2}rpx`,
        }"
        mode="aspectFit"
      />
      <text v-else class="dp-cover-text">▲</text>
    </view>
  </view>
</template>

<style scoped>
.dp-cover {
  position: fixed;
  z-index: 999;
}
.dp-cover-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  box-shadow: 0 4rpx 12rpx rgba(0, 0, 0, 0.12);
}
.dp-cover-text {
  padding: 0 6rpx;
  text-align: center;
}
</style>
