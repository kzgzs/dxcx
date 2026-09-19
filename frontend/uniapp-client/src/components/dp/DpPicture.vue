<script setup lang="ts">
/**
 * dp-picture 单图（任务326 对照点大截图重做：data 多图纵向堆叠）
 * data: [{imgurl,hrefurl,hrefname}]（旧数据为单对象时自动包数组兼容）
 * params: borderradius(圆角度)；bgcolor/margin_x/margin_y/padding_x/padding_y 外盒由 DpRenderer boxStyle 应用
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

const items = computed(() => {
  const raw = props.data;
  const arr = Array.isArray(raw) ? raw : raw && typeof raw === "object" ? [raw] : [];
  return arr.filter((it) => it && typeof it === "object") as Record<string, unknown>[];
});
const radius = computed(() => `${num(props.params.borderradius, 0) * 2.2}rpx`);

function onTap(item: Record<string, unknown>) {
  const href = str(item.hrefurl);
  if (href) {
    dpNavigate(href);
    return;
  }
  // 无跳转链接：预览大图
  const src = imgUrl(str(item.imgurl));
  if (src) {
    uni.previewImage({ urls: [src] });
  }
}
</script>

<template>
  <view class="dp-picture">
    <image
      v-for="(item, index) in items"
      :key="str(item.id, 'p' + index)"
      class="dp-picture-img"
      :src="imgUrl(str(item.imgurl))"
      mode="widthFix"
      lazy-load
      :style="{ borderRadius: radius }"
      @click="onTap(item)"
    />
  </view>
</template>

<style scoped>
.dp-picture {
  font-size: 0;
}
.dp-picture-img {
  display: block;
  width: 100%;
}
</style>
