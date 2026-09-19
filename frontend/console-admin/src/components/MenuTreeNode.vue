<script setup lang="ts">
import { computed } from 'vue'
import type { MenuNode } from '../menu/menuTypes'
import { visibleOf } from '../menu/menuTypes'

/**
 * 递归菜单节点（菜单配置中心驱动，支持二三级 submenu）：
 * - 有可见子项 → el-sub-menu（index = idPath，祖先 key 以 "/" 连接，全局唯一）
 * - 无可见子项 → el-menu-item（index = idPath）
 * 不使用 el-menu 的 router 模式：点击只触发 select，由壳层切换内部组件，绝不改动 URL
 */
const props = defineProps<{
  node: MenuNode
  /** 祖先 idPath 前缀，顶层传空串 */
  idPath: string
  /** 弹出面板样式类（租户侧飞梭子导航） */
  popperClass?: string
}>()

const selfId = computed(() =>
  props.idPath ? `${props.idPath}/${props.node.key}` : props.node.key,
)
const children = computed(() => visibleOf(props.node.child))
</script>

<template>
  <el-sub-menu
    v-if="children.length"
    :index="selfId"
    :popper-class="popperClass"
  >
    <template #title>{{ node.name }}</template>
    <MenuTreeNode
      v-for="child in children"
      :key="child.key"
      :node="child"
      :id-path="selfId"
      :popper-class="popperClass"
    />
  </el-sub-menu>
  <el-menu-item v-else :index="selfId"
    ><span v-if="node.icon" class="menu-item-icon">{{ node.icon }}</span>{{ node.name }}</el-menu-item
  >
</template>

<style scoped>
.menu-item-icon {
  margin-right: 4px;
}
</style>
