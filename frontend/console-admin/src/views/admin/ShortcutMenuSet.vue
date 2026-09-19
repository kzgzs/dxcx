<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { ElTree } from 'element-plus'
import {
  getShortcutMenu,
  getUserGroupMenuTree,
  saveShortcutMenu,
  type AuthTreeNode,
} from '../../api/system'

const loading = ref(false)
const saving = ref(false)
const treeData = ref<AuthTreeNode[]>([])
const treeRef = ref<InstanceType<typeof ElTree>>()

// 过滤：快捷菜单只挂可见页面菜单（去掉 hide 按钮级权限点）
function stripHidden(nodes: AuthTreeNode[]): AuthTreeNode[] {
  const result: AuthTreeNode[] = []
  for (const n of nodes) {
    if (n.hide) continue
    const item: AuthTreeNode = { name: n.name, path: n.path }
    if (n.child && n.child.length) {
      const kids = stripHidden(n.child)
      if (kids.length) item.child = kids
    }
    result.push(item)
  }
  return result
}

async function fetchAll() {
  loading.value = true
  try {
    const [tree, saved] = await Promise.all([getUserGroupMenuTree(), getShortcutMenu()])
    treeData.value = stripHidden(tree || [])
    await nextTick()
    const leafKeys = (saved?.menus || []).map((m) => m.key)
    treeRef.value?.setCheckedKeys(leafKeys)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  // 只取叶子节点（分组本身不作为快捷入口）
  const leafNodes = (treeRef.value?.getCheckedNodes(true) || []) as AuthTreeNode[]
  const menus = leafNodes.map((n) => ({ name: n.name, key: n.path }))

  saving.value = true
  try {
    await saveShortcutMenu(menus)
    ElMessage.success('保存成功，顶栏快捷菜单已更新')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchAll()
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>快捷菜单设置（勾选后显示在顶栏快捷菜单入口）</span>
          <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
        </div>
      </template>

      <div v-loading="loading" class="tree-box">
        <el-tree
          v-if="treeData.length"
          ref="treeRef"
          :data="treeData"
          node-key="path"
          show-checkbox
          default-expand-all
          :props="{ label: 'name', children: 'child' }"
        />
        <el-empty v-else-if="!loading" description="暂无菜单数据" :image-size="60" />
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.tree-box {
  max-height: 640px;
  overflow: auto;
}
</style>
