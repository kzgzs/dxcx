<script setup lang="ts">
/**
 * dp-richtext 富文本（任务242 补全，temp=richtext）
 * - 消费设计器节点 content（HTML 字符串），uni rich-text 组件渲染，严禁 v-html
 * - 渲染前做基础净化：剥离 script/style/iframe 等危险标签与 on* 事件属性
 * - params: bgcolor（背景色由 DpRenderer 包裹层统一应用，这里兜底图片自适应）
 */
import { computed } from "vue";
import { str, type DpParams } from "./shared";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
    /** 设计器节点 content：富文本 HTML */
    content?: string;
  }>(),
  { params: () => ({}), data: () => [], content: "" }
);

/** 危险标签整段剥离（含闭合） */
const STRIP_TAGS = ["script", "style", "iframe", "frame", "object", "embed", "link", "meta"];

/** 净化后的富文本 HTML */
const nodes = computed(() => {
  let html = str(props.content);
  if (!html) return "";
  for (const tag of STRIP_TAGS) {
    html = html.replace(new RegExp("<" + tag + "[\\s\\S]*?<\\/" + tag + ">", "gi"), "");
    html = html.replace(new RegExp("<" + tag + "[^>]*\\/?>", "gi"), "");
  }
  // 剥离行内事件属性 on*="..."
  html = html.replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, "");
  // 剥离 javascript: 伪协议
  html = html.replace(/href\s*=\s*(["']?)javascript:[^"'>\s]*\1/gi, 'href="#"');
  return html;
});
</script>

<template>
  <view v-if="nodes" class="dp-richtext">
    <rich-text :nodes="nodes"></rich-text>
  </view>
</template>

<style scoped>
.dp-richtext {
  word-break: break-all;
}
/* H5 下 rich-text 展开为真实 DOM，约束图片不撑破容器 */
.dp-richtext :deep(img) {
  max-width: 100%;
  height: auto;
}
</style>
