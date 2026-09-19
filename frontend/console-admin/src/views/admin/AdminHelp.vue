<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'
import { Search as SearchIcon } from '@element-plus/icons-vue'

interface HelpArticle {
  id: number
  name: string
  title?: string
  sort: number
  createtime: number
  readcount: number
  content: string
}

// 手风琴当前展开项
const activeNames = ref<string[]>([])
const listLoading = ref(false)
const list = ref<HelpArticle[]>([])

// 搜索关键词
const searchKeyword = ref('')

// 去除富文本 HTML 标签，提取纯文本用于答案匹配
function stripHtml(html: string): string {
  if (!html) return ''
  return html
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
}

// 按关键词过滤问答列表（匹配问题标题或答案内容，不区分大小写）
const filteredList = computed(() => {
  const kw = searchKeyword.value.trim().toLowerCase()
  if (!kw) return list.value
  return list.value.filter((item) => {
    const title = (item.title || '').toLowerCase()
    const answer = stripHtml(item.content).toLowerCase()
    return title.includes(kw) || answer.includes(kw)
  })
})

// API 请求 base URL
const API_BASE = import.meta.env.VITE_API_BASE_URL || ''

// 关闭事件：通知壳层（AdminLayout）切回首页
const emit = defineEmits<{ (e: 'close'): void }>()

// 打开帮助中心
function open() {
  fetchList()
}

// 关闭帮助中心（返回客户端首页）：阻止冒泡 + 显式通知壳层关闭
function handleClose(ev: MouseEvent) {
  ev.stopPropagation()
  emit('close')
}

// 格式化时间
function formatDate(timestamp: number): string {
  if (!timestamp) return '未知时间'
  const date = new Date(timestamp * 1000)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

// 获取帮助文章列表
async function fetchList() {
  try {
    listLoading.value = true
    const token = localStorage.getItem('token')
    if (!token) return
    
    const res = await axios.get(`${API_BASE}/admin/help/list`, {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    })

    if (res.data.code === 0 && res.data.data) {
      // 后端已将 name 映射为 title，兼容两种字段
      list.value = res.data.data.map((item: any) => ({
        ...item,
        title: item.title || item.name,
      }))
    } else {
      ElMessage.error(res.data.message || '加载失败')
    }
  } catch (error) {
    console.error('加载帮助文章列表失败:', error)
    ElMessage.error('加载失败')
  } finally {
    listLoading.value = false
  }
}



// 挂载即自动加载（壳层动态组件切换不会调用 open()，须自加载）
onMounted(() => {
  fetchList()
})

defineExpose({
  open,
})
</script>

<template>
  <!-- 帮助问答手风琴列表页 -->
  <div class="help-page">
    <div class="page-header">
      <h1 class="page-title">帮助问答</h1>
      <button class="close-btn" @click="handleClose">
        ✕
      </button>
    </div>

    <div class="page-body">
      <!-- 搜索框 -->
      <div class="search-bar">
        <el-input
          v-model="searchKeyword"
          placeholder="搜索问题/答案"
          clearable
          :prefix-icon="SearchIcon"
          class="search-input"
        />
      </div>

      <!-- 手风琴列表 -->
      <el-collapse v-model="activeNames" :accordion="true">
        <el-collapse-item v-for="item in filteredList" :key="item.id" :name="item.id.toString()">
          <template #title>
            <div class="collapse-item-header">
              <span class="question-text">{{ item.title || '(无标题)' }}</span>
              <span class="meta-info">
                <span class="publish-time">发布时间：{{ item.createtime ? formatDate(item.createtime) : '未知时间' }}</span>
                <span class="read-count">阅读量：{{ item.readcount || 0 }}</span>
              </span>
            </div>
          </template>
          
          <div class="collapse-item-content">
            <div class="answer-content" v-html="item.content"></div>
          </div>
        </el-collapse-item>
      </el-collapse>

      <div v-if="!listLoading && list.length === 0" class="empty-hint">
        <el-empty description="暂无帮助文章" :image-size="100" />
      </div>
      <div v-else-if="!listLoading && filteredList.length === 0" class="empty-hint">
        <el-empty description="暂无相关问题" :image-size="100" />
      </div>
    </div>
  </div>
</template>

<style scoped>
/* 帮助问答页容器 */
.help-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  background-color: #f5f7fa;
}

/* 顶部栏 */
.page-header {
  height: 48px;
  flex-shrink: 0;
  background-color: #ffffff;
  border-bottom: 1px solid #e4e7ed;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  box-sizing: border-box;
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  color: #303133;
}

.close-btn {
  width: 32px;
  height: 32px;
  border: none;
  background: transparent;
  cursor: pointer;
  color: #909399;
  font-size: 24px;
  line-height: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 4px;
  transition: all 0.2s;
}

.close-btn:hover {
  background-color: #f5f7fa;
  color: #f56c6c;
}

/* 主体内容区 */
.page-body {
  flex: 1;
  overflow: auto;
  padding: 16px;
}

/* 搜索栏（紧凑） */
.search-bar {
  margin-bottom: 12px;
}

.search-input {
  max-width: 420px;
}

/* 手风琴标题样式（紧凑行高） */
:deep(.el-collapse-item__header) {
  height: auto;
  min-height: 40px;
  line-height: 1.4;
  padding: 8px 0;
}

.collapse-item-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
  padding: 0;
  line-height: 1.4;
  transition: all 0.2s;
}

.question-text {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  line-height: 1.4;
  flex: 1;
}

.meta-info {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: #909399;
  line-height: 1.4;
}

/* 展开项内容样式 */
.collapse-item-content {
  background-color: #fafafa;
  border-left: 3px solid var(--primary);
  border-radius: 4px;
  margin: 4px 0 8px;
}

.answer-content {
  padding: 10px 12px;
  line-height: 1.6;
  font-size: 14px;
  color: #606266;
  word-break: break-word;
}

.answer-content img {
  max-width: 100%;
  height: auto;
  margin: 10px 0;
  border-radius: 4px;
}

.answer-content p {
  margin: 8px 0;
}

.empty-hint {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 100%;
  color: #909399;
}
</style>
