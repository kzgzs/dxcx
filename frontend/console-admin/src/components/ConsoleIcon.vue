<script setup lang="ts">
// 超管控制台顶栏内联图标（stroke 风格，颜色继承 currentColor）
// 无图标库依赖：path 数据按 24x24 视口手绘
defineProps<{ name: string }>()

// name → svg 内容片段（path/circle 等）
const ICONS: Record<string, string> = {
  // 折叠 / 展开（左侧菜单列收合）
  'fold-on':
    '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M9.5 4.5v15"/><path d="M15.8 9.5 13.3 12l2.5 2.5"/>',
  'fold-off':
    '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M9.5 4.5v15"/><path d="M13.3 9.5l2.5 2.5-2.5 2.5"/>',
  // 全屏（四角括号形，对齐参考截图 ⛶）
  fullscreen:
    '<path d="M4 9V4h5"/><path d="M20 9V4h-5"/><path d="M4 15v5h5"/><path d="M20 15v5h-5"/>',
  // 退出全屏
  'fullscreen-exit':
    '<path d="M9 4v5H4"/><path d="M15 4v5h5"/><path d="M9 20v-5H4"/><path d="M15 20v-5h5"/>',
  // 扩大 / 最大化（同全屏：四角括号形）
  expand:
    '<path d="M4 9V4h5"/><path d="M20 9V4h-5"/><path d="M4 15v5h5"/><path d="M20 15v5h-5"/>',
  // 刷新主区域（开口圆环 + 箭头，对齐参考截图 ↻）
  refresh:
    '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
  // 返回客户端（房子）
  home: '<path d="M4 10.5 12 4l8 6.5"/><path d="M6 9.5V20h12V9.5"/><path d="M10 20v-5h4v5"/>',
  // 配色方案（调色板）
  palette:
    '<path d="M12 3.5a8.5 8.5 0 1 0 0 17c1.4 0 2-.9 2-1.8 0-1.4-1.2-1.7-1.2-2.9 0-1 .8-1.8 2-1.8h1.7a4 4 0 0 0 4-4c0-3.6-3.9-6.5-8.5-6.5z"/><circle cx="7.8" cy="10.5" r="1"/><circle cx="11" cy="7.3" r="1"/><circle cx="15.2" cy="8.6" r="1"/>',
  // 用户
  user: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"/>',
  // 修改密码（锁）
  lock: '<rect x="5.5" y="10.5" width="13" height="9.5" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/><path d="M12 14.2v2.3"/>',
  // 退出登录
  logout:
    '<path d="M14 4H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7"/><path d="M10 12h10"/><path d="m17 9 3 3-3 3"/>',
  // 版本信息
  info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5"/><path d="M12 7.6v.4"/>',
  // 下拉箭头
  'chevron-down': '<path d="m7 10 5 5 5-5"/>',
}
</script>

<template>
  <svg
    class="console-icon"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    v-html="ICONS[name] || ICONS.info"
  />
</template>

<style scoped>
.console-icon {
  width: 1em;
  height: 1em;
  display: inline-block;
  vertical-align: -0.15em;
}
</style>
