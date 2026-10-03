<script setup lang="ts">
import SharePosterEditor from '../../components/SharePosterEditor.vue'

// 课程海报设置（对齐点大 扩展-知识付费-课程海报：8 平台 tab + 海报编辑器，kind=kecheng 复用共享外壳）
</script>

<template>
  <div class="kecheng-poster">
    <SharePosterEditor kind="kecheng" :single="true" />
  </div>
</template>

<style scoped>
.kecheng-poster {
  margin: 3px;
}
</style>
