<script setup lang="ts">
// 底部导航内置图标渲染（stroke 风格，颜色继承 currentColor，可由 color prop 指定）
import { NAV_ICON_MAP } from './navIcons'

withDefaults(defineProps<{ name: string; size?: number; color?: string }>(), {
  size: 24,
  color: '',
})
</script>

<template>
  <svg
    class="nav-icon"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    :style="color ? { color } : undefined"
    aria-hidden="true"
    v-html="NAV_ICON_MAP[name] || NAV_ICON_MAP.goods"
  />
</template>

<style scoped>
.nav-icon {
  display: inline-block;
  vertical-align: middle;
}
</style>
