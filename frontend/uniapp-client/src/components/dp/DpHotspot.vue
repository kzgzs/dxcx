<script setup lang="ts">
/**
 * dp-hotspot 热区图（任务246 P27-2）
 * params:
 * - imgurl        底图（widthFix 自适应宽度）
 * - borderradius  圆角
 * data: [{left,top,width,height,hrefurl,tip,showtip}]
 * - left/top/width/height 均为百分比坐标（0-100，相对底图尺寸），越界自动裁剪
 * - showtip=1 时点击弹 tip 提示不跳转
 */
import { computed } from "vue";
import { num, str, flag, clampNum, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";
import { imgUrl } from "../../utils/format";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const pic = computed(() => imgUrl(str(props.params.imgurl)));
const radius = computed(() => `${num(props.params.borderradius) * 2.2}rpx`);

/** 热区：百分比坐标容错（非法/对象缺失一律 0，越界裁剪到 0-100） */
interface HotArea {
  key: string;
  left: number;
  top: number;
  width: number;
  height: number;
  hrefurl: string;
  tip: string;
  showtip: boolean;
}
const areas = computed<HotArea[]>(() => {
  const arr = Array.isArray(props.data) ? props.data : [];
  const list: HotArea[] = [];
  arr.forEach((it, index) => {
    if (!it || typeof it !== "object") return;
    const item = it as Record<string, unknown>;
    const left = clampNum(item.left, 0, 100, 0);
    const top = clampNum(item.top, 0, 100, 0);
    const width = clampNum(item.width, 0, 100 - left, 0);
    const height = clampNum(item.height, 0, 100 - top, 0);
    if (width <= 0 || height <= 0) return;
    list.push({
      key: str(item.id, "hs" + index),
      left,
      top,
      width,
      height,
      hrefurl: str(item.hrefurl),
      tip: str(item.tip),
      showtip: flag(item.showtip) === 1,
    });
  });
  return list;
});

function areaStyle(a: HotArea): Record<string, string> {
  return {
    left: `${a.left}%`,
    top: `${a.top}%`,
    width: `${a.width}%`,
    height: `${a.height}%`,
  };
}

function onTap(a: HotArea) {
  if (a.showtip) {
    if (a.tip) uni.showToast({ title: a.tip, icon: "none" });
    return;
  }
  dpNavigate(a.hrefurl);
}
</script>

<template>
  <view v-if="pic" class="dp-hotspot" :style="{ borderRadius: radius }">
    <image class="dp-hotspot-img" :src="pic" mode="widthFix" />
    <view
      v-for="a in areas"
      :key="a.key"
      class="dp-hotspot-area"
      :style="areaStyle(a)"
      @click="onTap(a)"
    ></view>
  </view>
</template>

<style scoped>
.dp-hotspot {
  position: relative;
  font-size: 0;
  overflow: hidden;
}
.dp-hotspot-img {
  display: block;
  width: 100%;
}
.dp-hotspot-area {
  position: absolute;
  z-index: 3;
}
</style>
