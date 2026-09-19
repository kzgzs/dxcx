<script setup lang="ts">
/**
 * dp-line 辅助线（Vue3 重写）
 * params: height(线粗)/style(solid|dashed|dotted)/color
 */
import { computed, type CSSProperties } from "vue";
import { num, str, type DpParams } from "./shared";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const borderWidth = computed(() => `${Math.max(1, num(props.params.height, 1))}rpx`);
const borderStyle = computed(() => {
  const s = str(props.params.style, "solid");
  return (["solid", "dashed", "dotted"].includes(s) ? s : "solid") as CSSProperties["borderTopStyle"];
});
const borderColor = computed(() => str(props.params.color, "#dddddd"));
/** 圆点样式：径向渐变实现真圆点（border dotted 粗线时会变方点） */
const isDotted = computed(() => str(props.params.style, "solid") === "dotted");
const lineStyle = computed(() => {
  const h = Math.max(1, num(props.params.height, 1));
  if (isDotted.value) {
    const r = Math.max(h / 2, 1);
    const hp = uni.upx2px(h);
    const rp = uni.upx2px(r);
    return {
      height: `${hp}px`,
      borderTop: "none",
      backgroundImage: `radial-gradient(circle, ${borderColor.value} ${rp}px, transparent ${rp}px)`,
      backgroundSize: `${hp * 3}px ${hp}px`,
      backgroundRepeat: "repeat-x",
      backgroundPosition: "0 center",
    } as CSSProperties;
  }
  return {
    borderTopWidth: borderWidth.value,
    borderTopStyle: borderStyle.value,
    borderTopColor: borderColor.value,
  } as CSSProperties;
});
</script>

<template>
  <view class="dp-line" :style="lineStyle"></view>
</template>

<style scoped>
.dp-line {
  width: 100%;
  height: 0;
  margin: 16rpx 0;
}
</style>
