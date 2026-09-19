/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** 后端 API 网关地址（可选，留空走代理/同域；生产小程序优先用 ext.json 注入的 baseurl） */
  readonly VITE_API_BASE?: string;
}

declare module '*.vue' {
  import { DefineComponent } from 'vue'
  // eslint-disable-next-line @typescript-eslint/no-explicit-any, @typescript-eslint/ban-types
  const component: DefineComponent<{}, {}, any>
  export default component
}
