import { computed, ref } from "vue";
import { defineStore } from "pinia";
import { fetchIndexSysset, type IndexSysset, type SysGate } from "../api/designer";

/**
 * 系统配置主题 store（任务308）
 *
 * - 主/辅色调作 CSS 主题变量全局应用（H5 注入 :root；小程序端页面可用 store 值绑定）
 * - 导航背景/文字色：navbgmode=1 用自定义色，否则跟随主色调；navtextcolor 0黑 1白
 * - 前端门控：status=0 关停页；visitperm 不符出提示页（后端 syssetGate 判定）
 */
export const useThemeStore = defineStore("theme", () => {
  const sysset = ref<IndexSysset | null>(null);
  const gate = ref<SysGate | null>(null);
  let loader: Promise<void> | null = null;

  const primary = computed(() => {
    const c = (sysset.value?.color1 || "").trim();
    return c || "#FD4A46";
  });
  const secondary = computed(() => {
    const c = (sysset.value?.color2 || "").trim();
    return c || "#7E71F6";
  });
  const navBg = computed(() => {
    const s = sysset.value;
    if (!s) return primary.value;
    const custom = (s.navbgcolor || "").trim();
    return Number(s.navbgmode || 0) === 1 && custom ? custom : primary.value;
  });
  const navText = computed(() => (Number(sysset.value?.navtextcolor || 0) === 1 ? "#ffffff" : "#000000"));

  /** 门控拦截类型：''=放行 close=关停 perm=无访问权限 */
  const blocked = computed<"" | "close" | "perm">(() => {
    const g = gate.value;
    if (!g) return "";
    if (Number(g.status) === 0) return "close";
    if (!g.perm_ok) return "perm";
    return "";
  });

  function apply() {
    const s = sysset.value;
    if (!s) return;
    // #ifdef H5
    try {
      const root = document.documentElement;
      root.style.setProperty("--tp-primary", primary.value);
      root.style.setProperty("--tp-secondary", secondary.value);
    } catch {
      // 忽略注入异常
    }
    // #endif
    // 导航栏颜色全局应用（页面级 topbar 可再覆盖）
    uni.setNavigationBarColor({
      frontColor: navText.value,
      backgroundColor: navBg.value,
    });
  }

  /** 启动/页面加载时调用：仅首次真实请求，失败可重试 */
  function load(): Promise<void> {
    if (!loader) {
      loader = fetchIndexSysset()
        .then((s) => {
          sysset.value = s;
          gate.value = s.gate || null;
          apply();
        })
        .catch((e) => {
          loader = null;
          throw e;
        });
    }
    return loader;
  }

  return { sysset, gate, primary, secondary, navBg, navText, blocked, load, apply };
});
