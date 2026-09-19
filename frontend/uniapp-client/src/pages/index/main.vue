<script setup lang="ts">
/**
 * 设计页/模板通用渲染页（任务278）
 * - 路由：/pages/index/main?id=x（B端页面卡二维码自定义内页口径）
 *         /pages/index/main?tid=x（B端模板卡二维码：加载模板内容渲染预览）
 * - 消费：id → GET /api/designer/page?id=xx；tid → GET /api/designer/template?tid=xx
 *   （aid 隔离走 TenantMiddleware，返回 pageinfo/content，DpRenderer 渲染）
 * - topbar：title 设导航标题、bgcolor 设导航/页面背景；needlogin=1 未登录先跳登录
 * - 兜底：参数非法 / 页面或模板不存在 → 提示并返回首页
 */
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import DpRenderer from "../../components/dp/DpRenderer.vue";
import { fetchDesignerPage, fetchDesignerTemplate } from "../../api/designer";
import type { DesignerPage, DpNode, TopbarParams } from "../../api/designer";
import { LOGIN_PAGE } from "../../config";
import { useSessionStore } from "../../stores/session";

const session = useSessionStore();

const mode = ref<"loading" | "ready">("loading");
/** 设计器 content 组件数组 */
const dpContent = ref<DpNode[]>([]);
/** topbar 下发的页面背景色 */
const pageBg = ref("#F8F8F8");

/** 简易亮度判断（深色背景配白色导航字） */
function isDarkColor(hex: string): boolean {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
  if (!m) return false;
  const v = parseInt(m[1], 16);
  const r = (v >> 16) & 255;
  const g = (v >> 8) & 255;
  const b = v & 255;
  return r * 0.299 + g * 0.587 + b * 0.114 < 128;
}

/** 应用 topbar：title 设导航栏标题，bgcolor 设背景 */
function applyTopbar(pageinfo: DpNode[]) {
  const topbar = (pageinfo || []).find((n) => n && n.temp === "topbar");
  const params = ((topbar && topbar.params) || {}) as TopbarParams;
  const title = (params.title || "").trim();
  if (title) {
    uni.setNavigationBarTitle({ title });
  }
  const bgcolor = (params.bgcolor || "").trim();
  if (bgcolor) {
    pageBg.value = bgcolor;
    uni.setNavigationBarColor({
      frontColor: isDarkColor(bgcolor) ? "#ffffff" : "#000000",
      backgroundColor: bgcolor,
    });
  }
  return params;
}

/** 页面/模板不存在：友好提示并返回首页 */
function failBack() {
  uni.showToast({ title: "内容不存在或已删除", icon: "none", duration: 1500 });
  setTimeout(() => {
    uni.reLaunch({ url: "/pages/index/index" });
  }, 1200);
}

async function load(id: number, tid: number) {
  try {
    // tid 优先：模板预览口径；否则按设计页 id 消费
    const page: DesignerPage =
      tid > 0 ? await fetchDesignerTemplate(tid) : await fetchDesignerPage(id);
    const params = applyTopbar(page.pageinfo || []);

    // needlogin=1：未登录先跳登录，登录后回跳本页（还原 id/tid 参数）
    const needlogin = Number(params.needlogin || 0) === 1;
    if (needlogin && !session.isLoggedIn) {
      const back =
        "/pages/index/main?" + (tid > 0 ? "tid=" + tid : "id=" + id);
      uni.reLaunch({ url: LOGIN_PAGE + "?redirect=" + encodeURIComponent(back) });
      return;
    }

    dpContent.value = Array.isArray(page.content) ? page.content : [];
    mode.value = "ready";
  } catch {
    // code!==0（不存在）或网络异常：静默兜底
    failBack();
  }
}

onLoad((options) => {
  const id = parseInt((options && options.id) || "", 10) || 0;
  const tid = parseInt((options && options.tid) || "", 10) || 0;
  if (id <= 0 && tid <= 0) {
    failBack();
    return;
  }
  void load(id, tid);
});
</script>

<template>
  <view class="page" :style="{ background: pageBg }">
    <block v-if="mode === 'ready'">
      <dp-renderer :content="dpContent" />
    </block>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
</style>
