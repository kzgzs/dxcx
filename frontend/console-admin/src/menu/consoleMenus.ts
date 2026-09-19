import type { MenuNode } from './menuTypes'
import { defineAsyncComponent } from 'vue'
import Tenant from '../views/Tenant.vue'
import OpenPlatform from '../views/OpenPlatform.vue'
import WxpaySet from '../views/WxpaySet.vue'
import WxpayLog from '../views/WxpayLog.vue'
import Remote from '../views/Remote.vue'
import Help from '../views/Help.vue'
import Message from '../views/Message.vue'
import Notice from '../views/Notice.vue'
import ClearCache from '../views/ClearCache.vue'
import Upgrade from '../views/Upgrade.vue'
import SystemSetting from '../views/SystemSetting.vue'

/**
 * 超管控制台菜单（纯平铺 11 项，无任何子级，对齐参考系统控制台）
 * 参考：canzhao\app\common\Menu.php getdata2() + canzhao\app\controller\WebSystem.php index()
 * key 由参考系统 path 转 kebab-case，稳定可引用
 * 「开放平台设置」为平铺单项，页内用 el-tabs 承载平台设置/授权账号（见 OpenPlatform.vue）
 * 注：「控制台」（统计面板 Dashboard）不再作为菜单项，改由壳层顶部标题触发（见 Layout.vue 哨兵机制）
 */
export const consoleMenus: MenuNode[] = [
  { name: '用户列表', key: 'web-user-index', component: Tenant },
  // 音乐控制（任务315：平台级音乐库 aid=0，接口 /console/music/*；♪ 图标与设计编辑器同款）
  {
    name: '音乐控制',
    key: 'web-music-index',
    icon: '♪',
    component: defineAsyncComponent(() => import('../views/admin/MusicManage.vue')),
  },
  { name: '服务商配置', key: 'web-system-wxpayset', component: WxpaySet },
  { name: '微信支付记录', key: 'web-system-wxpaylog', component: WxpayLog },
  { name: '开放平台设置', key: 'web-system-component', component: OpenPlatform },
  { name: '系统设置', key: 'web-system-set', component: SystemSetting },
  { name: '附件设置', key: 'web-system-remote', component: Remote },
  { name: '帮助中心', key: 'web-help-index', component: Help },
  { name: '网站留言', key: 'web-message-index', component: Message },
  { name: '通知公告', key: 'web-notice-index', component: Notice },
  { name: '清理缓存', key: 'web-cache-clear', component: ClearCache },
  { name: '系统升级', key: 'web-upgrade-index', component: Upgrade },
]
