<script setup lang="ts">
import { onMounted } from 'vue'
import SharePosterEditor from '../../components/SharePosterEditor.vue'
import { ensureCyclePoster } from '../../api/cycle'

// 周期购分享海报（对齐「扩展--周期购--分享海报」截图）：
// 顶部 8 平台子 tab + 左侧手机预览 + 右侧组件面板（背景图/海报元素/二维码元素）+ 保存。
// 复用 SharePosterEditor（kind=cycle，single 单海报模式，无参团海报）；进页面先幂等播种 8 平台默认模板。
onMounted(async () => {
  try {
    await ensureCyclePoster()
  } catch {
    // 拦截器已提示；海报编辑器自身会按平台自动建默认模板
  }
})
</script>

<template>
  <div>
    <SharePosterEditor kind="cycle" single />
  </div>
</template>
