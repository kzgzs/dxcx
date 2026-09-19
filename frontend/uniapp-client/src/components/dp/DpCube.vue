<script setup lang="ts">
/**
 * dp-cube 图片魔方（任务300 全量：4×4 slots 无缝布局）
 * params.slots: [{x,y,w,h,imgurl,hrefurl}]（设计宽640口径，单格160）
 * 渲染口径：内宽=750rpx-左右边距*2-左右内边距*2（系数同 boxStyle 2.2），单格=内宽/4，
 * slots 按 x/y/w/h 绝对定位无缝拼接；点击图位走 dpNavigate 链接
 * 无 slots 时兼容旧版列数模式（num 1/2/3 + data 列表）
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

interface CubeSlot {
  x?: unknown;
  y?: unknown;
  w?: unknown;
  h?: unknown;
  imgurl?: unknown;
  hrefurl?: unknown;
}

/** slots 图位（容错：非对象过滤） */
const slots = computed<CubeSlot[]>(() => {
  const arr = (props.params as Record<string, unknown>).slots;
  if (!Array.isArray(arr)) return [];
  return arr.filter((s) => s && typeof s === "object") as CubeSlot[];
});

const legacy = computed(() => slots.value.length === 0);

/** 单格边长（rpx）：内宽/4，内宽=750-左右边距*2.2*2-左右内边距*2.2*2（与包裹层 boxStyle 同口径） */
const cell = computed(() => {
  const mx = num(props.params.margin_x) * 2.2;
  const px = num(props.params.padding_x) * 2.2;
  return Math.max(0, (750 - mx * 2 - px * 2) / 4);
});

/** 布局总行数（决定容器高） */
const maxH = computed(() => slots.value.reduce((m, s) => Math.max(m, num(s.y) + num(s.h)), 0));

const background = computed(() => str(props.params.bgcolor) || str(props.params.background, "#FFFFFF"));

function slotStyle(s: CubeSlot): Record<string, string> {
  return {
    left: `${num(s.x) * cell.value}rpx`,
    top: `${num(s.y) * cell.value}rpx`,
    width: `${num(s.w) * cell.value}rpx`,
    height: `${num(s.h) * cell.value}rpx`,
  };
}

function onTap(item: { hrefurl?: unknown }) {
  dpNavigate(str(item.hrefurl));
}

/* ---- 旧版列数模式兼容（无 slots 时） ---- */
const columns = computed(() => {
  const n = num(props.params.num, 2);
  return n === 1 || n === 3 ? n : 2;
});
const radius = computed(() => `${num(props.params.borderradius) * 2.2}rpx`);
const gap = computed(() => `${num(props.params.gap) * 2.2}rpx`);

const cells = computed(() => {
  const arr = Array.isArray(props.data) ? props.data : [];
  return arr.filter((it) => it && typeof it === "object") as Record<string, unknown>[];
});

function cellStyle(): Record<string, string> {
  return {
    width: `calc(${(100 / columns.value).toFixed(4)}% - ${gap.value})`,
    marginRight: gap.value,
    marginBottom: gap.value,
    borderRadius: radius.value,
  };
}
</script>

<template>
  <!-- 任务300：slots 无缝布局 -->
  <view
    v-if="!legacy"
    class="dp-cube"
    :style="{ background: background, height: `${maxH * cell}rpx` }"
  >
    <view
      v-for="(s, i) in slots"
      :key="'s' + i"
      class="dp-cube-slot"
      :style="slotStyle(s)"
      @click="onTap(s)"
    >
      <image
        v-if="imgUrl(str(s.imgurl))"
        class="dp-cube-img"
        :src="imgUrl(str(s.imgurl))"
        mode="scaleToFill"
        lazy-load
      />
    </view>
  </view>
  <!-- 旧版列数模式兜底 -->
  <view v-else-if="cells.length > 0" class="dp-cube-legacy" :style="{ background: background }">
    <view
      v-for="(item, index) in cells"
      :key="str(item.id, 'cube' + index)"
      class="dp-cube-cell"
      :style="cellStyle()"
      @click="onTap(item)"
    >
      <image v-if="imgUrl(str(item.imgurl))" class="dp-cube-img-fix" :src="imgUrl(str(item.imgurl))" mode="widthFix" lazy-load />
      <view v-else class="dp-cube-empty"><text>图</text></view>
    </view>
  </view>
</template>

<style scoped>
.dp-cube {
  position: relative;
  overflow: hidden;
  font-size: 0;
}
.dp-cube-slot {
  position: absolute;
  overflow: hidden;
  font-size: 0;
}
.dp-cube-img {
  display: block;
  width: 100%;
  height: 100%;
}
/* 旧版列数模式 */
.dp-cube-legacy {
  display: flex;
  flex-wrap: wrap;
}
.dp-cube-cell {
  overflow: hidden;
  font-size: 0;
}
.dp-cube-img-fix {
  display: block;
  width: 100%;
}
.dp-cube-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 160rpx;
  background: #F2F4F7;
}
.dp-cube-empty text {
  font-size: 24rpx;
  color: #BBBBBB;
}
</style>
