<script setup lang="ts">
/**
 * 简单富文本编辑器（任务333）：wangEditor Toolbar + Editor 封装
 * - props: modelValue（html 字符串），emits update:modelValue 双向绑定
 * - 工具栏排除 fullScreen / group-video；图片上传走 api/goods 的 uploadFile
 * - 编辑区 min-height:160px max-height:260px 内部滚动，卸载时销毁实例
 */
import { computed, onBeforeUnmount, shallowRef } from 'vue'
import { ElMessage } from 'element-plus'
import '@wangeditor/editor/dist/css/style.css'
import { Editor, Toolbar } from '@wangeditor/editor-for-vue'
import type { IDomEditor } from '@wangeditor/editor'
import { uploadFile } from '../api/goods'

const props = withDefaults(defineProps<{ modelValue?: string }>(), { modelValue: '' })
const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>()

const editorRef = shallowRef<IDomEditor | null>(null)
function onCreated(editor: IDomEditor) {
  editorRef.value = editor
}

/** html 双向桥接（v-model 口径） */
const html = computed<string>({
  get: () => props.modelValue || '',
  set: (v: string) => emit('update:modelValue', v),
})

const toolbarConfig = { excludeKeys: ['fullScreen', 'group-video'] }
const editorConfig = {
  placeholder: '请输入内容',
  MENU_CONF: {
    uploadImage: {
      async customUpload(file: File, insertFn: (url: string) => void) {
        try {
          const res = await uploadFile(file)
          insertFn(res.url)
        } catch {
          ElMessage.error('图片上传失败')
        }
      },
    },
  },
}

onBeforeUnmount(() => {
  editorRef.value?.destroy()
  editorRef.value = null
})
</script>

<template>
  <div class="sre-box">
    <Toolbar :editor="editorRef" :defaultConfig="toolbarConfig" mode="default" class="sre-toolbar" />
    <Editor v-model="html" :defaultConfig="editorConfig" mode="default" class="sre-editor" @onCreated="onCreated" />
  </div>
</template>

<style scoped>
.sre-box {
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  overflow: hidden;
}

.sre-toolbar {
  border-bottom: 1px solid #f0f0f0;
}

.sre-editor {
  min-height: 160px;
  max-height: 260px;
  overflow-y: auto;
}
</style>
