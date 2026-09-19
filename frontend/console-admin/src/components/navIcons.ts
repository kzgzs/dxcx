// 底部导航内置线性图标集（任务279：对照参考系统同款线性图标，无图标库依赖）
// path 数据按 24x24 视口手绘，stroke 风格，颜色继承 currentColor

export interface NavIconDef {
  key: string
  label: string
  svg: string
}

export const NAV_ICONS: NavIconDef[] = [
  { key: 'home', label: '首页', svg: '<path d="M4 10.5 12 4l8 6.5"/><path d="M6 9.5V20h12V9.5"/><path d="M10 20v-5h4v5"/>' },
  {
    key: 'category',
    label: '分类',
    svg: '<path d="M12 6c-1.8-1.3-4.4-2-8-2v14c3.6 0 6.2.7 8 2 1.8-1.3 4.4-2 8-2V4c-3.6 0-6.2.7-8 2z"/><path d="M12 6v14"/><path d="M6.8 9c1.2.2 2.4.5 3.4.9"/><path d="M6.8 12.5c1.2.2 2.4.5 3.4.9"/><path d="M17.2 9c-1.2.2-2.4.5-3.4.9"/><path d="M17.2 12.5c-1.2.2-2.4.5-3.4.9"/>',
  },
  {
    key: 'grid',
    label: '宫格',
    svg: '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
  },
  { key: 'cart', label: '购物车', svg: '<circle cx="9.5" cy="19.5" r="1.5"/><circle cx="17" cy="19.5" r="1.5"/><path d="M4 4.5h2.5l2 11H18l2-8H7"/>' },
  { key: 'user', label: '会员', svg: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"/>' },
  { key: 'search', label: '搜索', svg: '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>' },
  { key: 'heart', label: '收藏', svg: '<path d="M12 20s-7.5-4.7-7.5-10A4.3 4.3 0 0 1 12 7a4.3 4.3 0 0 1 7.5 3c0 5.3-7.5 10-7.5 10z"/>' },
  { key: 'star', label: '星标', svg: '<path d="m12 4 2.4 5 5.6.7-4.1 3.8 1.1 5.5-5-2.8-5 2.8 1.1-5.5L4 9.7 9.6 9z"/>' },
  {
    key: 'setting',
    label: '设置',
    svg: '<circle cx="12" cy="12" r="3"/><path d="M12 4.5V7"/><path d="M12 17v2.5"/><path d="M4.5 12H7"/><path d="M17 12h2.5"/><path d="m6.7 6.7 1.8 1.8"/><path d="m15.5 15.5 1.8 1.8"/><path d="m17.3 6.7-1.8 1.8"/><path d="m8.5 15.5-1.8 1.8"/>',
  },
  { key: 'message', label: '消息', svg: '<path d="M4 5.5h16v11H10l-4 3v-3H4z"/>' },
  { key: 'order', label: '订单', svg: '<rect x="5.5" y="4" width="13" height="16.5" rx="2"/><path d="M9 8.5h6"/><path d="M9 12h6"/><path d="M9 15.5h4"/>' },
  {
    key: 'coupon',
    label: '优惠券',
    svg: '<path d="M4 7.5h16v3a2 2 0 0 0 0 4v3H4v-3a2 2 0 0 0 0-4z"/><path d="M14.5 7.5v10" stroke-dasharray="2 2"/>',
  },
  { key: 'coin', label: '积分', svg: '<circle cx="12" cy="12" r="8"/><path d="M9 9.5h6"/><path d="M9 12.5h6"/><path d="M12 9.5V17"/>' },
  {
    key: 'shop',
    label: '店铺',
    svg: '<path d="M5 9.5 6.5 4h11L19 9.5"/><path d="M5 9.5a2.3 2.3 0 0 0 4.6 0 2.3 2.3 0 0 0 4.7 0 2.3 2.3 0 0 0 4.7 0"/><path d="M6 12v8h12v-8"/><path d="M10 20v-5h4v5"/>',
  },
  { key: 'goods', label: '商品', svg: '<path d="M4 8 12 4l8 4v8l-8 4-8-4z"/><path d="M4 8l8 4 8-4"/><path d="M12 12v8"/>' },
  { key: 'video', label: '视频', svg: '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="m10.5 9.5 4.5 2.5-4.5 2.5z"/>' },
  { key: 'article', label: '文章', svg: '<path d="M6 3.5h9l4 4v13H6z"/><path d="M15 3.5v4h4"/><path d="M9 12h7"/><path d="M9 15.5h7"/>' },
  { key: 'map', label: '定位', svg: '<path d="M12 21s-6.5-5.3-6.5-10a6.5 6.5 0 0 1 13 0c0 4.7-6.5 10-6.5 10z"/><circle cx="12" cy="10.5" r="2.3"/>' },
  { key: 'phone', label: '电话', svg: '<rect x="6" y="3.5" width="12" height="17" rx="2"/><path d="M10.5 17.5h3"/>' },
  {
    key: 'share',
    label: '分享',
    svg: '<circle cx="6.5" cy="12" r="2.5"/><circle cx="17" cy="6" r="2.5"/><circle cx="17" cy="18" r="2.5"/><path d="m8.8 10.8 6-3.5"/><path d="m8.8 13.2 6 3.5"/>',
  },
  { key: 'plus', label: '添加', svg: '<circle cx="12" cy="12" r="8.5"/><path d="M12 8.5v7"/><path d="M8.5 12h7"/>' },
  { key: 'calendar', label: '日历', svg: '<rect x="4" y="5.5" width="16" height="15" rx="2"/><path d="M8 3.5v4"/><path d="M16 3.5v4"/><path d="M4 10h16"/>' },
  { key: 'wallet', label: '钱包', svg: '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M16 12h4.5v3H16a1.5 1.5 0 0 1 0-3z"/>' },
  {
    key: 'gift',
    label: '礼物',
    svg: '<rect x="4.5" y="8" width="15" height="4"/><path d="M6 12v8h12v-8"/><path d="M12 8v12"/><path d="M12 8s-4 0-4-2.5C8 3.5 11 3.5 12 8z"/><path d="M12 8s4 0 4-2.5C16 3.5 13 3.5 12 8z"/>',
  },
  { key: 'bell', label: '提醒', svg: '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2.5h-15z"/><path d="M10 19.5a2 2 0 0 0 4 0"/>' },
  {
    key: 'service',
    label: '客服',
    svg: '<path d="M5 13a7 7 0 0 1 14 0"/><rect x="4" y="13" width="4" height="6" rx="1.5"/><rect x="16" y="13" width="4" height="6" rx="1.5"/>',
  },
  { key: 'clock', label: '时间', svg: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>' },
  { key: 'edit', label: '编辑', svg: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>' },
]

/** key → svg 片段映射（O(1) 判定内置图标） */
export const NAV_ICON_MAP: Record<string, string> = Object.fromEntries(NAV_ICONS.map((it) => [it.key, it.svg]))
