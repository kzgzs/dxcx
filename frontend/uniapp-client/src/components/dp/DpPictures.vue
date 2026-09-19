<script setup lang="ts">
/**
 * dp-pictures 多图宫格（任务327 对照点大截图重做）
 * params: layout(2堆积两列/3堆积三列/4堆积四列/showcase橱窗；旧数据回退 style 2/3/4，6 横滑保留)
 *         bgcolor/margin_x/margin_y/padding_x/padding_y（外盒由 DpRenderer boxStyle 应用；padding 兼作图间距）
 * data: [{imgurl,hrefurl,hrefname,clicktype}]；clicktype link=跳转链接/preview=预览图片（旧 gototype=1 兼容为 preview）
 * - 橱窗样式：首图左半跨两行大图 + 右侧小图，超出 3 张按两列续排
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

/** 布局：layout 优先，旧数据回退 style */
const lay = computed(() => {
  const l = str(props.params.layout, "");
  if (l === "showcase" || l === "2" || l === "3" || l === "4") return l;
  const s = str(props.params.style, "2");
  return s === "3" || s === "4" || s === "6" ? s : "2";
});
const isScroll = computed(() => lay.value === "6");
const cols = computed(() => (lay.value === "3" ? 3 : lay.value === "4" ? 4 : 2));
/** 行高 rpx：堆积=750/列数；橱窗=185（首图跨两行≈方图） */
const rowH = computed(() => (lay.value === "showcase" ? 185 : Math.floor(750 / cols.value)));
/** 图间距：padding_x/padding_y ×2 → rpx */
const gapX = computed(() => `${num(props.params.padding_x) * 2}rpx`);
const gapY = computed(() => `${num(props.params.padding_y) * 2}rpx`);

function onTap(item: Record<string, unknown>) {
  const ct = str(item.clicktype, "") || (str(item.gototype, "0") === "1" ? "preview" : "link");
  const href = str(item.hrefurl);
  if (ct === "preview" || !href) {
    const src = imgUrl(str(item.imgurl));
    if (src) uni.previewImage({ urls: [src] });
    return;
  }
  dpNavigate(href);
}
</script>

<template>
  <view v-if="items.length > 0" class="dp-pictures">
    <!-- 横滑（旧 style=6 兼容） -->
    <scroll-view v-if="isScroll" scroll-x class="dp-pictures-scroll">
      <view class="dp-pictures-scroll-inner">
        <view v-for="(item, index) in items" :key="str(item.id, 'ps' + index)" class="dp-pictures-scroll-item" :style="{ paddingLeft: gapX, paddingRight: gapX, paddingTop: gapY, paddingBottom: gapY }">
          <image class="dp-pictures-img" :src="imgUrl(str(item.imgurl))" mode="aspectFill" lazy-load @click="onTap(item)" />
        </view>
      </view>
    </scroll-view>
    <!-- 宫格/橱窗 -->
    <view
      v-else
      class="dp-pictures-grid"
      :style="{ gridTemplateColumns: `repeat(${cols}, 1fr)`, gridAutoRows: `${rowH}rpx`, gap: `${gapY} ${gapX}` }"
    >
      <view
        v-for="(item, index) in items"
        :key="str(item.id, 'pg' + index)"
        class="dp-pictures-cell"
        :class="{ 'dp-pictures-cell--big': lay === 'showcase' && index === 0 }"
        @click="onTap(item)"
      >
        <image class="dp-pictures-img" :src="imgUrl(str(item.imgurl))" mode="aspectFill" lazy-load />
      </view>
    </view>
  </view>
</template>

<style scoped>
.dp-pictures-grid {
  display: grid;
  width: 100%;
}
.dp-pictures-cell {
  overflow: hidden;
}
/* 橱窗首图跨两行 */
.dp-pictures-cell--big {
  grid-row: span 2;
}
.dp-pictures-img {
  display: block;
  width: 100%;
  height: 100%;
}
.dp-pictures-scroll {
  width: 100%;
  white-space: nowrap;
}
.dp-pictures-scroll-inner {
  display: inline-flex;
}
.dp-pictures-scroll-item {
  width: 320rpx;
  height: 220rpx;
  box-sizing: border-box;
  display: inline-block;
}
</style>
