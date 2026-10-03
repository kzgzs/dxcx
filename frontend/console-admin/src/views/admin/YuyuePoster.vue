<script setup lang="ts">
import SharePosterEditor from '../../components/SharePosterEditor.vue'

// 预约海报设置（对齐点大 扩展-预约服务-海报设置：8 平台 tab + 海报编辑器，kind=yuyue 复用共享外壳）
</script>

<template>
  <div class="yuyue-poster">
    <SharePosterEditor kind="yuyue" :single="true" />
  </div>
</template>

<style scoped>
.yuyue-poster {
  margin: 3px;
}
</style>
