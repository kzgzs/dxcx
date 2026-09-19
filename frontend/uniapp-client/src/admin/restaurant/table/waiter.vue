<script setup lang="ts">
/**
 * 手机端后台-菜品管理-点餐清台（骨架页：对照参考页 ../restaurant/tableWaiter）
 */
import { onLoad } from "@dcloudio/uni-app";
import { guardAdmin } from "../../api";
import SkeletonPage from "../../components/skeleton-page.vue";

onLoad(() => {
  // 未登录先跳登录页
  guardAdmin();
});
</script>

<template>
  <skeleton-page title="点餐清台" sub="点餐清台功能建设中，敬请期待" />
</template>
