/// <reference types="vite/client" />

// @wangeditor/editor-for-vue 的 package.json "exports" 未正确暴露类型，这里手动声明模块
declare module '@wangeditor/editor-for-vue' {
  import type { DefineComponent } from 'vue'
  export const Editor: DefineComponent<any, any, any>
  export const Toolbar: DefineComponent<any, any, any>
}
