<script setup lang="ts">
/**
 * dp-button 按钮（任务246 P27-2）
 * params（参考口径）:
 * - btntext      按钮文案
 * - btnbgcolor   按钮背景色
 * - btncolor     文字颜色
 * - btnfontsize  字号（设计值 *2 → rpx）
 * - btnwidth     按钮宽度（设计值 *2.2 → rpx，0=自适应撑满）
 * - btnheight    按钮高度（设计值 *2.2 → rpx）
 * - btnradius    圆角（设计值 *2.2 → rpx）
 * - hrefurl      点击跳转（navigate.ts 口径）
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

const text = computed(() => str(props.params.btntext, "按钮"));
const btnStyle = computed(() => {
  const p = props.params;
  const style: Record<string, string> = {};
  style.background = str(p.btnbgcolor, "#FD4A46");
  style.color = str(p.btncolor, "#FFFFFF");
  style.fontSize = `${num(p.btnfontsize, 14) * 2}rpx`;
  const h = num(p.btnheight, 44);
  style.height = `${h * 2.2}rpx`;
  style.lineHeight = `${h * 2.2}rpx`;
  style.borderRadius = `${num(p.btnradius, 4) * 2.2}rpx`;
  const w = num(p.btnwidth);
  if (w > 0) style.width = `${w * 2.2}rpx`;
  return style;
});

function onTap() {
  dpNavigate(props.params.hrefurl);
}
</script>

<template>
  <view class="dp-button">
    <view class="dp-button-btn" :style="btnStyle" @click="onTap">
      <text>{{ text }}</text>
    </view>
  </view>
</template>

<style scoped>
.dp-button {
  display: flex;
  justify-content: center;
}
.dp-button-btn {
  min-width: 50%;
  box-sizing: border-box;
  padding: 0 20rpx;
  text-align: center;
  overflow: hidden;
}
</style>
