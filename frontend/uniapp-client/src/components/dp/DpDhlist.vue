<script setup lang="ts">
/**
 * dp-dhlist 列表导航（任务246 P27-2）
 * params（参考口径）:
 * - color1/fontsize1     主标题颜色/字号
 * - color2/fontsize2     右侧副文案颜色/字号
 * - arrowshow            右侧箭头 '0'/'1'（默认 1）
 * - arrowcolor           箭头颜色
 * - titlemarginleft      图标与标题间距（设计值 *2 → rpx）
 * - bgcolor/background   背景色
 * data: [{imgurl, title1, title2, hrefurl, showicon('0'/'1'), iconsize}]
 */
import { computed } from "vue";
import { num, str, flag, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";
import { imgUrl } from "../../utils/format";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

/** 列表项容错：非对象项过滤 */
const items = computed(() => {
  const arr = Array.isArray(props.data) ? props.data : [];
  return arr.filter((it) => it && typeof it === "object") as Record<string, unknown>[];
});

const color1 = computed(() => str(props.params.color1, "#333333"));
const fontsize1 = computed(() => `${num(props.params.fontsize1, 14) * 2}rpx`);
const color2 = computed(() => str(props.params.color2, "#999999"));
const fontsize2 = computed(() => `${num(props.params.fontsize2, 12) * 2}rpx`);
const arrowShow = computed(() => flag(props.params.arrowshow, 1) === 1);
const arrowColor = computed(() => str(props.params.arrowcolor, "#BBBBBB"));
const titleGap = computed(() => `${num(props.params.titlemarginleft, 8) * 2}rpx`);
const background = computed(() => str(props.params.bgcolor) || str(props.params.background, "#FFFFFF"));

function iconSize(item: Record<string, unknown>): string {
  return `${num(item.iconsize, 24) * 2}rpx`;
}
function onTap(item: Record<string, unknown>) {
  dpNavigate(item.hrefurl);
}
</script>

<template>
  <view class="dp-dhlist" :style="{ background: background }">
    <view
      v-for="(item, index) in items"
      :key="str(item.id, 'dh' + index)"
      class="dp-dhlist-item"
      @click="onTap(item)"
    >
      <image
        v-if="flag(item.showicon, 1) === 1 && imgUrl(str(item.imgurl))"
        class="dp-dhlist-icon"
        :src="imgUrl(str(item.imgurl))"
        :style="{ width: iconSize(item), height: iconSize(item) }"
        mode="aspectFit"
      />
      <text
        v-if="str(item.title1)"
        class="dp-dhlist-title"
        :style="{ color: color1, fontSize: fontsize1, marginLeft: titleGap }"
      >{{ str(item.title1) }}</text>
      <view class="dp-dhlist-spacer"></view>
      <text v-if="str(item.title2)" class="dp-dhlist-sub" :style="{ color: color2, fontSize: fontsize2 }">{{ str(item.title2) }}</text>
      <view v-if="arrowShow" class="dp-dhlist-arrow" :style="{ borderColor: arrowColor }"></view>
    </view>
  </view>
</template>

<style scoped>
.dp-dhlist {
  position: relative;
}
.dp-dhlist-item {
  display: flex;
  align-items: center;
  padding: 22rpx 24rpx;
  border-bottom: 1rpx solid #F5F5F5;
}
.dp-dhlist-item:last-child {
  border-bottom: 0;
}
.dp-dhlist-icon {
  flex-shrink: 0;
}
.dp-dhlist-title {
  flex-shrink: 1;
}
.dp-dhlist-spacer {
  flex: 1;
}
.dp-dhlist-sub {
  margin-right: 12rpx;
}
/* CSS 绘制右箭头（不依赖图片素材） */
.dp-dhlist-arrow {
  width: 16rpx;
  height: 16rpx;
  border-top: 3rpx solid #BBBBBB;
  border-right: 3rpx solid #BBBBBB;
  transform: rotate(45deg);
  margin-right: 6rpx;
  flex-shrink: 0;
}
</style>
