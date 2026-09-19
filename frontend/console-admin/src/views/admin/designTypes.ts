/**
 * 设计模块 8 项共享配置：菜单 key → 设计项名称
 * - DesignList.vue 列表页：按 key 显示标题、拼新窗口地址
 * - DesignerPage.vue 独立设计页：按路由参数 type 反查名称
 * key 与 src/menu/tenantMenus.ts「设计」主菜单下 8 项一一对应，勿单独改动
 */
export const DESIGN_ITEMS: Record<string, string> = {
  'designer-page-index': '页面设计',
  'designer-menu-index': '底部导航',
  'designer-menu-menu2': '内页导航',
  'designer-menu-shopdetail-shopdetail': '商品详情',
  'designer-login-index': '登录页面',
  'designer-mobile-index': '移动端后台',
  'designer-share-index': '分享设置',
  'designer-page-chooseurl': '链接地址',
}

/** 独立设计页新标签地址（hash 路由 /designer/:type，不走壳内菜单切换） */
export function designerUrl(key: string): string {
  return `${location.origin}${location.pathname}#/designer/${key}`
}
