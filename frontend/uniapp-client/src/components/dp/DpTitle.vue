<script setup lang="ts">
/**
 * dp-title 标题栏（Vue3 重写）
 * params: title/fontsize/color/linecolor/showicon/imgurl/iconsize/bgcolor/margin_x/margin_y/padding_x/padding_y
 * 旧数据兼容：showicon 缺省视为显示；icon 兼容旧 icon key
 */
import { computed } from "vue";
import { num, str, type DpParams } from "./shared";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const title = computed(() => str(props.params.title));
const fontSize = computed(() => `${num(props.params.fontsize, 14) * 2}rpx`);
const color = computed(() => str(props.params.color, "#333"));
const lineColor = computed(() => str(props.params.linecolor, "#DDDDDD"));
const showIcon = computed(() => str(props.params.showicon, "1") === "1");
const iconUrl = computed(() => str(props.params.imgurl) || str(props.params.icon));
const iconSize = computed(() => `${num(props.params.iconsize, 14) * 2}rpx`);
</script>

<template>
  <view class="dp-title">
    <view class="dp-title-line" :style="{ borderColor: lineColor }"></view>
    <view class="dp-title-text" :style="{ fontSize, color }">
      <image v-if="showIcon && iconUrl" class="dp-title-icon" :src="iconUrl" :style="{ width: iconSize, height: iconSize }" mode="aspectFit" lazy-load />
      <text v-if="title">{{ title }}</text>
    </view>
    <view class="dp-title-line" :style="{ borderColor: lineColor }"></view>
  </view>
</template>

<style scoped>
.dp-title {
  display: flex;
  align-items: center;
}
.dp-title-line {
  flex: 1;
  height: 0;
  border-top: 1rpx solid #cccccc;
}
.dp-title-text {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 32rpx;
  font-weight: 600;
}
.dp-title-icon {
  flex: none;
  margin-right: 8rpx;
}
</style>
