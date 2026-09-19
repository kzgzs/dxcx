<script setup lang="ts">
/**
 * 设计器预览缩放容器（任务276）
 * - 内层 DesignerPageRender 固定 375px 手机口径，ResizeObserver 测卡宽 → scale=卡宽/375
 * - transform scale + transform-origin top left，外层固定高 400px 可鼠标滚轮滚动（任务277：420→400，高 prop 保留可调）
 * - 滚动条三件套隐藏（scrollbar-width:none / -ms-overflow-style:none / ::-webkit-scrollbar display:none）
 * - 高度补偿：transform 不影响布局高度，holder 高度 = 内容自然高度 × scale，保证可滚全貌
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import DesignerPageRender from './DesignerPageRender.vue'
import type { DesignerModule } from '../api/designer'

const props = withDefaults(defineProps<{
  topbar?: DesignerModule | null
  items?: DesignerModule[]
  /** 容器固定高度（默认 400px，任务277：对照参考系统卡片缩略区缩短） */
  height?: number
}>(), {
  topbar: null,
  items: () => [],
  height: 400,
})

const boxRef = ref<HTMLElement | null>(null)
const innerRef = ref<HTMLElement | null>(null)
const scale = ref(1)
const holderH = ref(props.height)

let ro: ResizeObserver | null = null

/** 测卡宽算缩放比，并按内容自然高度补偿 holder 高度 */
function recalc() {
  const el = boxRef.value
  if (!el) return
  const w = el.clientWidth
  if (w > 0) {
    scale.value = w / 375
  }
  const ih = innerRef.value?.offsetHeight ?? 0
  holderH.value = Math.max(ih * scale.value, props.height)
}

onMounted(() => {
  recalc()
  if (typeof ResizeObserver !== 'undefined') {
    ro = new ResizeObserver(() => nextTick(recalc))
    if (boxRef.value) ro.observe(boxRef.value)
    if (innerRef.value) ro.observe(innerRef.value)
  }
})

onBeforeUnmount(() => {
  ro?.disconnect()
  ro = null
})

// 内容变更（懒挂载完成/模板数据替换）后重算
watch(() => [props.items, props.topbar], () => nextTick(recalc), { deep: true })
</script>

<template>
  <div ref="boxRef" class="dpf-box" :style="{ height: `${props.height}px` }">
    <div class="dpf-holder" :style="{ height: `${holderH}px` }">
      <div ref="innerRef" class="dpf-inner" :style="{ transform: `scale(${scale})` }">
        <DesignerPageRender :topbar="props.topbar" :items="props.items" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.dpf-box {
  overflow-y: auto;
  border: 1px solid #eee;
  background: #fafafa;
  /* 隐藏滚动条但保留滚轮滚动（三件套） */
  scrollbar-width: none;
  -ms-overflow-style: none;
}

.dpf-box::-webkit-scrollbar {
  display: none;
}

.dpf-inner {
  width: 375px;
  transform-origin: top left;
}
</style>
