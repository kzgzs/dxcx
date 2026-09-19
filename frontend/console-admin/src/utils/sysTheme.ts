/**
 * 系统主题段缓存与导航色解析（任务309：系统设置导航色贯通设计编辑器）
 * - 来源：GET /admin/sysset（复用既有系统设置读取接口），仅取主题段缓存
 * - 口径：页面「导航背景颜色=跟随系统设置」→ 系统 navbgmode=1?navbgcolor:主色调
 *         页面「导航文字颜色=跟随系统设置」→ 系统 navtextcolor（1白/0黑）
 */
import { ref } from 'vue'
import { getTenantSysset } from '../api/tenantSet'

/** 系统设置主题段（admin_set） */
export interface SysTheme {
  color1: string
  color2: string
  navbgmode: number
  navbgcolor: string
  navtextcolor: number
}

/** 全局响应式主题段（默认值与后端 DEFAULTS 一致） */
export const sysTheme = ref<SysTheme>({
  color1: '#FD4A46',
  color2: '#7E71F6',
  navbgmode: 0,
  navbgcolor: '',
  navtextcolor: 0,
})

let loader: Promise<SysTheme> | null = null

/** 拉取系统设置主题段（缓存单次，失败回落默认值不抛错） */
export function loadSysTheme(): Promise<SysTheme> {
  if (!loader) {
    loader = getTenantSysset()
      .then((d) => {
        if (d) {
          sysTheme.value = {
            color1: String(d.color1 || '#FD4A46'),
            color2: String(d.color2 || '#7E71F6'),
            navbgmode: Number(d.navbgmode || 0),
            navbgcolor: String(d.navbgcolor || ''),
            navtextcolor: Number(d.navtextcolor || 0),
          }
        }
        return sysTheme.value
      })
      .catch(() => {
        loader = null
        return sysTheme.value
      })
  }
  return loader
}

/** 系统导航背景色（navbgmode=1 自定义色，否则主色调） */
export function sysNavBg(theme: SysTheme): string {
  const custom = String(theme.navbgcolor || '').trim()
  return theme.navbgmode === 1 && custom ? custom : String(theme.color1 || '#FD4A46')
}

/**
 * 导航背景色解析：页面自定义用页面值；跟随系统用系统值
 * @param params topbar.params（navbgmode '0'跟随/'1'自定义）
 */
export function resolveNavBg(theme: SysTheme, params: Record<string, any>): string {
  if (String(params.navbgmode) === '1') return String(params.navbgcolor || '#FFFFFF')
  return sysNavBg(theme)
}

/**
 * 导航文字色解析：页面 1白/2黑；跟随系统取系统 navtextcolor（1白/0黑）
 * @param params topbar.params（navtextmode '0'跟随/'1'白色/'2'黑色）
 */
export function resolveNavText(theme: SysTheme, params: Record<string, any>): string {
  const m = String(params.navtextmode ?? '0')
  if (m === '1') return '#FFFFFF'
  if (m === '2') return '#303133'
  return theme.navtextcolor === 1 ? '#FFFFFF' : '#303133'
}
