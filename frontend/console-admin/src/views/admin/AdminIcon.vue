<script setup lang="ts">
// 租户后台菜单内联图标（stroke 风格，颜色继承 currentColor）
// 无图标库依赖：path 数据按 24x24 视口手绘
defineProps<{ name: string }>()

// name → svg 内容片段（path/circle 等）
const ICONS: Record<string, string> = {
  // 首页：房子
  home: '<path d="M4 10.5 12 4l8 6.5"/><path d="M6 9.5V20h12V9.5"/><path d="M10 20v-5h4v5"/>',
  // 商城：购物袋
  shop:
    '<path d="M5.5 8h13l-1 12h-11z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
  // 会员：人像
  member:
    '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"/>',
  // 财务：圆币 + ¥
  finance:
    '<circle cx="12" cy="12" r="8.5"/><path d="M9 8l3 4 3-4"/><path d="M12 12v5"/><path d="M9.5 13.5h5"/><path d="M9.5 15.8h5"/>',
  // 商户：楼宇
  merchant:
    '<path d="M4 20h16"/><path d="M6 20V6l7-3v17"/><path d="M13 9l5 2v9"/><path d="M9 8h1.5M9 11h1.5M9 14h1.5"/>',
  // 营销：喇叭
  marketing:
    '<path d="M4 10v4h3l6 4V6l-6 4z"/><path d="M16.5 9a4.5 4.5 0 0 1 0 6"/><path d="M18.5 7a7.5 7.5 0 0 1 0 10"/>',
  // 扩展：四宫格
  extend:
    '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
  // 系统：齿轮
  setting:
    '<circle cx="12" cy="12" r="3.2"/><path d="M12 3.5v2.6M12 17.9v2.6M3.5 12h2.6M17.9 12h2.6M6 6l1.8 1.8M16.2 16.2 18 18M18 6l-1.8 1.8M7.8 16.2 6 18"/>',
  // 餐饮：餐具
  restaurant:
    '<path d="M6 3v5a2.5 2.5 0 0 0 5 0V3"/><path d="M8.5 3v18"/><path d="M17 3c-2 2.5-2 6 0 8v10"/>',
  // 设计：画笔
  design:
    '<path d="M4 20l1-4L16.5 4.5a2.12 2.12 0 0 1 3 3L8 19z"/><path d="M14.5 6.5l3 3"/>',
  // 平台：手机
  platform:
    '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 17.5h2"/>',
// ===== 租户后台固定顶栏新增图标 =====
// 全屏（四角括号形，对齐参考截图 ⛶，与 ConsoleIcon 同款）
  fullscreen:
    '<path d="M4 9V4h5"/><path d="M20 9V4h-5"/><path d="M4 15v5h5"/><path d="M20 15v5h-5"/>',
  // 扩大 / 最大化（同全屏：四角括号形）
  expand:
    '<path d="M4 9V4h5"/><path d="M20 9V4h-5"/><path d="M4 15v5h5"/><path d="M20 15v5h-5"/>',
  // 刷新（开口圆环 + 箭头，对齐参考截图 ↻，与 ConsoleIcon 同款）
  refresh:
    '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
  // 链接
  link:
    '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
  // 手机
  mobile:
    '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 17.5h2"/>',
  // 快捷菜单
  'quick-menu':
    '<circle cx="12" cy="12" r="4"/><path d="M16 8v8"/><path d="M8 8v8"/><path d="M12 4V2"/>',
  // 铃铛
  bell:
    '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
  // 帮助
  help:
    '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
  // 配色方案
  palette:
    '<circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 0-8 8c0 4.4 6 4 8 8 2-4 8-3.6 8-8a8 8 0 0 0-8-8z"/>',
  // 三点（竖排，顶栏更多菜单）
  'more-dots':
    '<circle cx="12" cy="5" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="19" r="1.6" fill="currentColor" stroke="none"/>',
  // 折叠 / 展开（顶栏按钮）
  'fold-on':
    '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M9.5 4.5v15"/><path d="M15.8 9.5 13.3 12l2.5 2.5"/>',
  'fold-off':
    '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M9.5 4.5v15"/><path d="M13.3 9.5l2.5 2.5-2.5 2.5"/>',
  // 分组展开箭头（二级列分组项）
  'chevron-down': '<path d="m7 10 5 5 5-5"/>',
  // 星标（快捷菜单收藏：空心 / 实心）
  star:
    '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/>',
  'star-on':
    '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z" fill="currentColor"/>',
}
</script>

<template>
  <svg
    class="admin-icon"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    v-html="ICONS[name] || ICONS.extend"
  />
</template>

<style scoped>
.admin-icon {
  width: 1em;
  height: 1em;
  display: inline-block;
  vertical-align: -0.15em;
}
</style>
