<script setup lang="ts">
/**
 * 底部导航图标选择弹窗（任务279：内置线性图标集弹窗选择，对照参考系统图标选择）
 * - v-model:visible 控制显隐；点选图标 emit('pick', key) 并关闭
 */
import NavIcon from './NavIcon.vue'
import { NAV_ICONS } from './navIcons'

defineProps<{ visible: boolean }>()

const emit = defineEmits<{
  (e: 'update:visible', v: boolean): void
  (e: 'pick', key: string): void
}>()

function choose(key: string) {
  emit('pick', key)
  emit('update:visible', false)
}
</script>

<template>
  <el-dialog
    :model-value="visible"
    title="选择图标"
    width="680px"
    append-to-body
    @update:model-value="(v: boolean) => emit('update:visible', v)"
  >
    <div class="nip-grid">
      <div v-for="ic in NAV_ICONS" :key="ic.key" class="nip-cell" @click="choose(ic.key)">
        <NavIcon :name="ic.key" :size="26" />
        <span class="nip-label">{{ ic.label }}</span>
      </div>
    </div>
  </el-dialog>
</template>

<style scoped>
.nip-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 8px;
}

.nip-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 10px 4px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 6px;
  color: #606266;
  cursor: pointer;
}

.nip-cell:hover {
  color: var(--el-color-primary);
  border-color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}

.nip-label {
  font-size: 12px;
}
</style>
