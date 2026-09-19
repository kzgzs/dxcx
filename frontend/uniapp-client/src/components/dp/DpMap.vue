<script setup lang="ts">
/**
 * dp-map 地图（任务246 P27-2）
 * params（参考口径）:
 * - latitude/longitude  经纬度（组件参数下发，非法时降级为地址卡片不崩溃）
 * - scale               缩放级别 5-20（默认 15）
 * - height              地图高度（设计值 *2.2 → rpx）
 * - address             标注文案（marker label + openLocation 名称）
 * - borderradius        圆角
 */
import { computed } from "vue";
import { num, str, clampNum, type DpParams } from "./shared";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const latitude = computed(() => num(props.params.latitude, 999));
const longitude = computed(() => num(props.params.longitude, 999));
/** 坐标合法才渲染地图，否则降级地址卡片 */
const valid = computed(
  () =>
    latitude.value >= -90 && latitude.value <= 90 &&
    longitude.value >= -180 && longitude.value <= 180 &&
    (latitude.value !== 0 || longitude.value !== 0)
);
const scale = computed(() => clampNum(props.params.scale, 5, 20, 15));
const address = computed(() => str(props.params.address, "目标位置"));
const boxHeight = computed(() => `${num(props.params.height, 180) * 2.2}rpx`);
const radius = computed(() => `${num(props.params.borderradius) * 2.2}rpx`);

/** 自绘定位标记（SVG dataURI，不引用外部图片素材） */
const MARKER_ICON =
  "data:image/svg+xml;charset=utf-8," +
  encodeURIComponent(
    "<svg xmlns='http://www.w3.org/2000/svg' width='30' height='30'>" +
      "<circle cx='15' cy='15' r='10' fill='#FD4A46' stroke='#ffffff' stroke-width='3'/>" +
      "</svg>"
  );

const markers = computed(() => {
  if (!valid.value) return [];
  return [
    {
      id: 1,
      latitude: latitude.value,
      longitude: longitude.value,
      iconPath: MARKER_ICON,
      width: 30,
      height: 30,
      label: {
        content: address.value,
        color: "#333333",
        fontSize: "12px",
        borderRadius: "4px",
        bgColor: "#FFFFFF",
        padding: 4,
      },
    },
  ];
});

/** 点击地图：调起原生位置页（导航/查看） */
function onMapTap() {
  if (!valid.value) return;
  uni.openLocation({
    latitude: latitude.value,
    longitude: longitude.value,
    name: address.value,
    scale: scale.value,
    fail: () => uni.showToast({ title: "无法打开位置", icon: "none" }),
  });
}
</script>

<template>
  <view class="dp-map">
    <map
      v-if="valid"
      class="dp-map-view"
      :style="{ height: boxHeight, borderRadius: radius }"
      :latitude="latitude"
      :longitude="longitude"
      :scale="scale"
      :markers="markers"
      :show-location="false"
      @tap="onMapTap"
    ></map>
    <!-- 坐标缺失/非法：降级为地址卡片，保证页面不崩溃 -->
    <view v-else class="dp-map-fallback" :style="{ height: boxHeight, borderRadius: radius }" @click="onMapTap">
      <view class="dp-map-fallback-pin">📍</view>
      <text class="dp-map-fallback-text">{{ address }}</text>
    </view>
  </view>
</template>

<style scoped>
.dp-map {
  font-size: 0;
}
.dp-map-view {
  width: 100%;
}
.dp-map-fallback {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: #F2F4F7;
}
.dp-map-fallback-pin {
  font-size: 40rpx;
  line-height: 1;
}
.dp-map-fallback-text {
  margin-top: 10rpx;
  font-size: 24rpx;
  color: #666666;
  padding: 0 20rpx;
  text-align: center;
}
</style>
