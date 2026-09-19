<script setup lang="ts">
/**
 * dp 设计器渲染主组件
 * - 遍历 content 节点，按 temp 分发到对应子组件
 * - 未实现的 temp 忽略并 console.warn（同 temp 只告警一次，不崩溃）
 * - 包裹层统一应用通用样式 margin_x/margin_y/padding_x/padding_y/bgcolor
 */
import { computed, type Component } from "vue";
import type { DpNode } from "../../api/designer";
import { boxStyle } from "./shared";
import DpBanner from "./DpBanner.vue";
import DpNotice from "./DpNotice.vue";
import DpSearch from "./DpSearch.vue";
import DpTitle from "./DpTitle.vue";
import DpText from "./DpText.vue";
import DpPicture from "./DpPicture.vue";
import DpPictures from "./DpPictures.vue";
import DpBlank from "./DpBlank.vue";
import DpLine from "./DpLine.vue";
import DpMenu from "./DpMenu.vue";

import DpRichText from "./DpRichText.vue";
// 任务 249：组件库扩容注册（基础 7 + 营销/行业 10，temp 键与后端/B端口径一致）
import DpVideo from "./DpVideo.vue";
import DpButton from "./DpButton.vue";
import DpDhlist from "./DpDhlist.vue";
import DpCover from "./DpCover.vue";
import DpMap from "./DpMap.vue";
import DpHotspot from "./DpHotspot.vue";
import DpCube from "./DpCube.vue";
import DpCollage from "./DpCollage.vue";
import DpLuckycollage from "./DpLuckycollage.vue";
import DpSeckill from "./DpSeckill.vue";
import DpKanjia from "./DpKanjia.vue";
import DpTuangou from "./DpTuangou.vue";
import DpScoreshop from "./DpScoreshop.vue";
import DpYuyue from "./DpYuyue.vue";
import DpKecheng from "./DpKecheng.vue";
import DpRestaurant from "./DpRestaurant.vue";
import DpArticle from "./DpArticle.vue";
// 任务302：基础组件补店招/精选
import DpShopSign from "./DpShopSign.vue";
import DpFeatured from "./DpFeatured.vue";
// 任务320：订单播报组件化（页面流内渲染）
import DpOrderBroadcast from "./DpOrderBroadcast.vue";
// 任务332：选项卡（双样式 + 子内容拉取）
import DpTab from "./DpTab.vue";
// 任务302：商城商品 C 端真实渲染
import DpProduct from "./DpProduct.vue";
import DpCoupon from "./DpCoupon.vue";

const props = withDefaults(
  defineProps<{
    /** 设计页 content 组件数组 */
    content?: DpNode[];
  }>(),
  { content: () => [] }
);

/** temp → 子组件 映射（对齐 B 端 designerModules.ts temp 全集，任务249 扩容至 30 个） */
const tempMap: Record<string, Component> = {
  banner: DpBanner,
  notice: DpNotice,
  search: DpSearch,
  title: DpTitle,
  text: DpText,
  richtext: DpRichText,
  picture: DpPicture,
  pictures: DpPictures,
  blank: DpBlank,
  line: DpLine,
  menu: DpMenu,
  coupon: DpCoupon,
  // 基础扩容 7 个
  video: DpVideo,
  button: DpButton,
  dhlist: DpDhlist,
  cover: DpCover,
  map: DpMap,
  hotspot: DpHotspot,
  cube: DpCube,
  // 营销/行业 10 个
  collage: DpCollage,
  luckycollage: DpLuckycollage,
  seckill: DpSeckill,
  kanjia: DpKanjia,
  tuangou: DpTuangou,
  scoreshop: DpScoreshop,
  yuyue: DpYuyue,
  kecheng: DpKecheng,
  restaurant: DpRestaurant,
  article: DpArticle,
  // 任务 302：店招/精选/商城商品
  shop: DpShopSign,
  featured: DpFeatured,
  product: DpProduct,
  // 任务 320：订单播报
  orderbroadcast: DpOrderBroadcast,
  // 任务 332：选项卡
  tab: DpTab,
};

/** 未知 temp 告警去重 */
const warnedTemps = new Set<string>();

/** 可渲染节点（未知 temp 过滤 + 告警） */
const nodes = computed(() => {
  const list: { node: DpNode; comp: Component }[] = [];
  for (const node of props.content || []) {
    if (!node || typeof node.temp !== "string") continue;
    const comp = tempMap[node.temp];
    if (!comp) {
      if (!warnedTemps.has(node.temp)) {
        warnedTemps.add(node.temp);
        console.warn(`[dp] 未实现的组件 temp 已忽略：${node.temp}`);
      }
      continue;
    }
    list.push({ node, comp });
  }
  return list;
});

/** 包裹层盒模型样式（任务320：订单播报自绘圆角背景，外层不再涂 bgcolor 避免直角底色溢出圆角） */
function nodeStyle(node: DpNode): Record<string, string> {
  const p = node.params || {};
  return boxStyle(node.temp === "orderbroadcast" ? { ...p, bgcolor: "" } : p);
}
</script>

<template>
  <view class="dp-renderer">
    <view v-for="item in nodes" :key="item.node.id" class="dp-node" :style="nodeStyle(item.node)">
      <component
        :is="item.comp"
        :params="item.node.params || {}"
        :data="item.node.data || []"
        :content="item.node.content || ''"
        :node="item.node"
      />
    </view>
  </view>
</template>

<style scoped>
.dp-renderer {
  width: 100%;
}
.dp-node {
  box-sizing: border-box;
}
</style>
