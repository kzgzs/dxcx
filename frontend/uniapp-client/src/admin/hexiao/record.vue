<script setup lang="ts">
/**
 * 手机端后台-核销记录（骨架页：对照参考页 ../hexiao/record 入口）
 * 契约接口就绪后替换 skeleton-page 为真实列表即可
 */
import { onLoad } from "@dcloudio/uni-app";
import { guardAdmin } from "../api";
import SkeletonPage from "../components/skeleton-page.vue";

onLoad(() => {
  // 未登录先跳登录页
  guardAdmin();
});
</script>

<template>
  <skeleton-page title="核销记录" sub="核销记录功能建设中，敬请期待" />
</template>
