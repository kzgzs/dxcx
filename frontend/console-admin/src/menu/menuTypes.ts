import type { Component } from 'vue'

/**
 * 菜单配置中心 —— 节点结构对齐参考系统：{ name, key, component?, child?, hide? }
 * - key：由参考系统 path 转 kebab-case，稳定可引用（后续任务按 key 挂位/扩展）
 * - hide：参考系统隐藏项，保留在配置中但不渲染（与参考系统一致）
 * - component：叶子菜单页面组件；未实现的统一挂"建设中"占位组件
 */
export interface MenuNode {
  /** 菜单名称（与参考系统一致） */
  name: string
  /** 稳定 key（参考系统 path 转 kebab-case） */
  key: string
  /** 叶子菜单页面组件 */
  component?: Component
  /** 菜单前缀图标字符（任务315：如音乐控制 ♪，与编辑器同款） */
  icon?: string
  /** 子菜单 */
  child?: MenuNode[]
  /** 参考系统隐藏项：保留配置、不渲染 */
  hide?: boolean
}

/** 索引节点：idPath 为祖先 key 以 "/" 连接的全局唯一标识，用作 el-menu index */
export interface IndexedNode {
  idPath: string
  node: MenuNode
}

/** 过滤可见节点（hide 项保留在配置但不渲染） */
export function visibleOf(nodes?: MenuNode[]): MenuNode[] {
  return (nodes || []).filter((n) => !n.hide)
}

/** 是否叶子（无可见子菜单） */
export function isLeaf(node: MenuNode): boolean {
  return visibleOf(node.child).length === 0
}

/**
 * 展开全部可见叶子：idPath → 节点（Map 按菜单顺序插入）
 * 壳层点击 el-menu 时用 index(=idPath) 反查节点，切换内部动态组件，绝不改动 URL
 */
export function indexLeaves(nodes: MenuNode[]): Map<string, MenuNode> {
  const map = new Map<string, MenuNode>()
  const walk = (list: MenuNode[], prefix: string) => {
    for (const n of visibleOf(list)) {
      const idPath = prefix ? `${prefix}/${n.key}` : n.key
      if (isLeaf(n)) map.set(idPath, n)
      else walk(n.child!, idPath)
    }
  }
  walk(nodes, '')
  return map
}

/** 第一个可见叶子的 idPath（刷新后自然回到各自第一个菜单，激活状态只存内存） */
export function firstLeafId(nodes: MenuNode[]): string {
  const it = indexLeaves(nodes).keys().next()
  return it.done ? '' : it.value
}

/** 某节点子树内第一个可见叶子的 idPath（一级菜单点击直达默认页） */
export function firstLeafIdOf(node: MenuNode, prefix = ''): string {
  const idPath = prefix ? `${prefix}/${node.key}` : node.key
  if (isLeaf(node)) return idPath
  const first = visibleOf(node.child)[0]
  return first ? firstLeafIdOf(first, idPath) : idPath
}
