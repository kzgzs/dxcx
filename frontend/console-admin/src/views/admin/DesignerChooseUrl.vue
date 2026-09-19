<script setup lang="ts">
/**
 * 链接地址（任务285：对照参考系统 designer_page/chooseurl 重做为独立链接目录页）
 * - 菜单场景（tenantMenus「客户端→设计→链接地址」）：无调用方传入 props，
 *   渲染独立页面形态——标题「链接地址」+ 说明条 + 页面内嵌目录
 *   （FrontendLinks embed：分组Tab + 链接按钮网格，点击弹「链接地址」小弹窗
 *   查看二维码/链接地址/页面路径，可复制）
 * - 选链场景（DesignerEditor 等 v-if 挂载并传 picker）：维持原 pick 弹窗形态，
 *   点选 emit('select', url, name)，关闭 emit('close')
 */
import { ref } from 'vue'
import FrontendLinks from '../../components/FrontendLinks.vue'

const props = defineProps<{
  /** 设计器选链弹窗形态（调用方 v-if 挂载并传 true） */
  picker?: boolean
}>()

const emit = defineEmits<{
  (e: 'select', url: string, name: string): void
  (e: 'close'): void
}>()

/**
 * 场景判定（任务288修正）：AdminLayout/Layout 对全部菜单组件统一挂 @close 监听，
 * 故不能以 vnode.props.onClose 判定选链场景（会把菜单页误判为弹窗）；
 * 改为调用方显式传 picker（DesignerEditor 已同步补传），菜单场景无 props 即页面形态
 */
const isPicker = props.picker === true

const visible = ref(true)

function onPick(url: string, name: string) {
  visible.value = false
  emit('select', url, name)
}

function onVisibleChange(v: boolean) {
  visible.value = v
  if (!v) emit('close')
}
</script>

<template>
  <!-- 选链弹窗形态（DesignerEditor 等既有消费方，需显式传 picker） -->
  <FrontendLinks
    v-if="isPicker"
    :visible="visible"
    mode="pick"
    @update:visible="onVisibleChange"
    @pick="onPick"
  />

  <!-- 独立页面形态：链接目录页 -->
  <div v-else class="cu-page">
    <div class="cu-header">
      <div class="cu-title">链接地址</div>
      <div class="cu-desc">
        前端页面链接目录，按分组查看各功能页面地址；点击链接可查看二维码、H5链接地址与小程序页面路径（均可复制）。
      </div>
    </div>
    <FrontendLinks embed mode="view" />
  </div>
</template>

<style scoped>
.cu-page {
  padding: 12px;
}

.cu-header {
  margin-bottom: 12px;
}

.cu-title {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
  line-height: 1.6;
}

.cu-desc {
  margin-top: 4px;
  padding: 8px 12px;
  font-size: 13px;
  color: #606266;
  line-height: 1.7;
  background: #f5f7fa;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}
</style>
