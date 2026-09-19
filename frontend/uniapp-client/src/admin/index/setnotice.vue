<script setup lang="ts">
/**
 * 手机端后台-消息通知（骨架页：本期仅占位，对照参考页 ../index/setnotice 入口）
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
  <skeleton-page title="消息通知" sub="通知设置功能建设中，敬请期待" />
</template>
