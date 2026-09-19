import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
// base 支持环境变量配置：上线子路径部署时设 VITE_BASE=/manage/（.env.production），本地默认 '/'
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: env.VITE_BASE || '/',
    plugins: [vue()],
    server: {
      // 监听 IPv6 通配实现 IPv4/IPv6 双栈，使 localhost(本机优先解析到 ::1) 与 127.0.0.1 都能访问
      host: '::',
      port: 5173,
      proxy: {
        // 后端接口代理到 ThinkPHP 服务
        '/console': {
          target: 'http://127.0.0.1:8080',
          changeOrigin: true,
        },
        // 租户后台接口
        '/admin': {
          target: 'http://127.0.0.1:8080',
          changeOrigin: true,
        },
        // 客户端接口（备用）
        '/api': {
          target: 'http://127.0.0.1:8080',
          changeOrigin: true,
        },
        // 素材静态目录（任务296裂图修复：dev 环境图片/视频回填依赖此代理，生产同域无需）
        '/uploads': {
          target: 'http://127.0.0.1:8080',
          changeOrigin: true,
        },
        '/upload': {
          target: 'http://127.0.0.1:8080',
          changeOrigin: true,
        },
      },
    },
  }
})
