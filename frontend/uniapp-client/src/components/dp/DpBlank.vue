<script setup lang="ts">
/**
 * dp-blank 辅助空白（Vue3 重写）
 * params: height（设计值 *2.2 → rpx）
 */
import { computed } from "vue";
import { num, type DpParams } from "./shared";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const height = computed(() => `${num(props.params.height, 10) * 2.2}rpx`);
</script>

<template>
  <view class="dp-blank" :style="{ height }"></view>
</template>

<style scoped>
.dp-blank {
  width: 100%;
}
</style>
