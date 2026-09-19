<script setup lang="ts">
import { onLaunch, onShow, onHide } from "@dcloudio/uni-app";
import { applyLaunchTableId, silentWxLogin } from "./utils/auth";
import { useSessionStore } from "./stores/session";
import { useThemeStore } from "./stores/theme";

onLaunch((options) => {
  // 扫码点餐带参：scene / query / H5 URL ?tableId= → storage（餐饮页自动选桌）
  applyLaunchTableId((options || {}) as Record<string, unknown>);
  // 启动静默登录：无 token 时 wx.login → member/wxlogin 换取会话；失败不阻塞
  const session = useSessionStore();
  if (!session.token) {
    void silentWxLogin();
  }
  // 系统配置主题（任务308）：主/辅色调 CSS 变量 + 导航色全局应用；失败不阻塞
  useThemeStore()
    .load()
    .catch(() => {
      // 配置拉取失败由各页面自行兑底
    });
});
// 小程序从后台扫码唤起时同样接收 scene 带参（App onShow 运行时带 options，类型未覆盖，断言处理）
onShow(((options?: Record<string, unknown>) => {
  applyLaunchTableId(options || {});
}) as unknown as () => void);
onHide(() => {
  console.log("App Hide");
});
</script>
<style></style>
