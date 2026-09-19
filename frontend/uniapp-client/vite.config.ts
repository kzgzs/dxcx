import { defineConfig } from "vite";
import uni from "@dcloudio/vite-plugin-uni";

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [uni()],
  server: {
    // 监听 IPv6 通配实现 IPv4/IPv6 双栈，使 localhost(本机优先解析到 ::1) 与 127.0.0.1 都能访问
    host: "::",
    port: 5174,
    proxy: {
      // H5 开发代理：/api -> 本地 ThinkPHP（php think run -p 8080）
      "/api": {
        target: "http://127.0.0.1:8080",
        changeOrigin: true,
      },
      // 上传的图片静态资源
      "/uploads": {
        target: "http://127.0.0.1:8080",
        changeOrigin: true,
      },
      // 图标渲染落盘目录（任务298：/upload/{aid}/Ymd/...，缺代理 H5 dev 裂图；生产同域无需）
      "/upload": {
        target: "http://127.0.0.1:8080",
        changeOrigin: true,
      },
    },
  },
});
