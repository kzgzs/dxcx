<script setup lang="ts">
/**
 * 属性面板数值滑杆行（任务310：滑杆+数字框+单位 统一口径）
 * - 左 el-slider（flex1）+ 右 el-input-number（小宽）+ 单位后缀，双向同步
 * - modelValue 兼容字符串/数字（设计器 params 为字符串口径），内部转数字回写字符串
 */
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue: string | number
    min?: number
    max?: number
    unit?: string
  }>(),
  { min: 0, max: 100, unit: 'px' },
)

const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>()

const num = computed<number>({
  get: () => {
    const n = Number(props.modelValue)
    return Number.isFinite(n) ? n : 0
  },
  set: (n) => emit('update:modelValue', String(n)),
})
</script>

<template>
  <div class="ds-row">
    <el-slider v-model="num" :min="props.min" :max="props.max" />
    <el-input-number v-model="num" :min="props.min" :max="props.max" size="small" :controls="false" class="ds-num" />
    <span class="ds-unit">{{ props.unit }}</span>
  </div>
</template>

<style scoped>
.ds-row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
}

.ds-row .el-slider {
  flex: 1;
}

.ds-num {
  width: 46px;
}

.ds-num :deep(.el-input__wrapper) {
  height: 32px;
  padding: 0 4px;
}

.ds-num :deep(.el-input__inner) {
  padding: 0;
  text-align: center;
}

.ds-row :deep(.el-slider__button) {
  width: 16px;
  height: 16px;
}

.ds-unit {
  font-size: 12px;
  color: #909399;
}
</style>
