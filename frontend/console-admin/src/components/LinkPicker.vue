<script setup lang="ts">
/**
 * 链接选择器（任务263：选择按钮改接 FrontendLinks 大弹窗 pick 模式）
 * - v-model 绑定链接字符串，四个设计器视图（底部导航/内页导航/商品详情/分享设置）消费方零改动
 * - 输入框可手输；「选择」打开前端链接大弹窗，点选即回填页面路径
 */
import { computed, ref } from 'vue'
import FrontendLinks from './FrontendLinks.vue'

const props = defineProps<{
  modelValue: string
  placeholder?: string
  /** 选择按钮文案（默认「选择」，底部导航等场景传「选择链接」） */
  pickText?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: string): void
  /** pick 模式选中：链接 + 链接名（任务279：底部导航回显 >链接名） */
  (e: 'pick', url: string, name: string): void
}>()

const pickerVisible = ref(false)

const inputValue = computed({
  get: () => props.modelValue,
  set: (v: string) => emit('update:modelValue', v),
})

function open() {
  pickerVisible.value = true
}

/** pick 模式选中：回填路径字符串并关闭大弹窗，同时上抛链接名 */
function onPick(url: string, name: string) {
  emit('update:modelValue', url)
  emit('pick', url, name)
  pickerVisible.value = false
}
</script>

<template>
  <div class="link-picker">
    <el-input
      v-model="inputValue"
      :placeholder="placeholder || '页面链接，如 /pages/index/index'"
      clearable
    >
      <template #append>
        <el-button @click="open">{{ props.pickText || '选择' }}</el-button>
      </template>
    </el-input>

    <!-- 前端链接大弹窗（pick 模式：点选即回填） -->
    <FrontendLinks
      v-model:visible="pickerVisible"
      mode="pick"
      @pick="onPick"
    />
  </div>
</template>

<style scoped>
.link-picker {
  width: 100%;
}
</style>
