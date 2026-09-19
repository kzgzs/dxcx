<script setup lang="ts">
/**
 * 手机端后台-菜品管理-菜品分类（骨架页：对照参考页 ../restaurant/category/index）
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
  <skeleton-page title="菜品分类" sub="菜品分类功能建设中，敬请期待" />
</template>
