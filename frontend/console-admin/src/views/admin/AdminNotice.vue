<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

interface NoticeItem {
  id: number
  title: string
  content: string
  time: string
  createtime: number
  isread: number
  is_unread: boolean
}

const listLoading = ref(false)
const unreadCount = ref(0)
const noticeList = ref<NoticeItem[]>([])

// 当前视图：list 列表 / detail 详情
const viewMode = ref<'list' | 'detail'>('list')
// 当前查看的公告
const currentNotice = ref<NoticeItem | null>(null)

// API 请求 base URL
const API_BASE = import.meta.env.VITE_API_BASE_URL || ''

// 关闭事件：通知壳层（AdminLayout）切回首页
const emit = defineEmits<{ (e: 'close'): void }>()

// 打开通知中心
function open() {
  viewMode.value = 'list'
  fetchUnreadCount()
  fetchList()
}

// 关闭通知中心（返回客户端首页）：阻止冒泡 + 显式通知壳层关闭
function handleClose(ev: MouseEvent) {
  ev.stopPropagation()
  emit('close')
}

// 获取未读数
async function fetchUnreadCount() {
  try {
    const token = localStorage.getItem('token')
    if (!token) return
    
    const res = await axios.get(`${API_BASE}/admin/notice/mine`, {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    })

    if (res.data.code === 0 && res.data.data) {
      unreadCount.value = res.data.data.unread_count || 0
    }
  } catch (error) {
    console.error('获取未读数失败:', error)
  }
}

// 获取公告列表
async function fetchList() {
  try {
    listLoading.value = true
    const token = localStorage.getItem('token')
    if (!token) return
    
    const res = await axios.get(`${API_BASE}/admin/notice/mine`, {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    })

    if (res.data.code === 0 && res.data.data?.list !== undefined) {
      noticeList.value = res.data.data.list || []
    } else {
      ElMessage.error(res.data.message || '加载失败')
    }
  } catch (error) {
    console.error('加载公告列表失败:', error)
    ElMessage.error('加载失败')
  } finally {
    listLoading.value = false
  }
}

// 查看公告详情：打开详情视图并标记已读
async function viewDetail(item: NoticeItem) {
  currentNotice.value = item
  viewMode.value = 'detail'

  // 已读则无需再调接口
  if (!item.is_unread) return

  try {
    const token = localStorage.getItem('token')
    if (!token) return

    const res = await axios.post(`${API_BASE}/admin/notice/read`, {
      id: item.id,
    }, {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    })

    // 更新未读数
    if (res.data.code === 0 && res.data.data) {
      unreadCount.value = res.data.data.unread_count || 0
    }

    // 同步本地行状态，避免返回列表时闪烁
    item.is_unread = false
  } catch (error) {
    console.error('标记已读失败:', error)
  }
}

// 返回列表
function backToList() {
  viewMode.value = 'list'
  currentNotice.value = null
  // 返回后刷新列表与未读数
  fetchList()
  fetchUnreadCount()
}

// 挂载即自动加载（壳层动态组件切换不会调用 open()，须自加载）
onMounted(() => {
  fetchList()
  fetchUnreadCount()
})

defineExpose({
  open,
})
</script>

<template>
  <!-- 通知列表页 -->
  <div class="notice-page">
    <div class="page-header">
      <div class="header-left">
        <button v-if="viewMode === 'detail'" class="back-btn" @click="backToList">← 返回</button>
        <h1 class="page-title">通知公告</h1>
      </div>
      <button class="close-btn" @click="handleClose">
        ✕
      </button>
    </div>

    <div class="page-body">
      <!-- 详情视图 -->
      <div v-if="viewMode === 'detail' && currentNotice" class="detail-view">
        <h2 class="detail-title">{{ currentNotice.title || '(无标题)' }}</h2>
        <div class="detail-meta">发送时间：{{ currentNotice.time }}</div>
        <div class="detail-content" v-html="currentNotice.content"></div>
      </div>

      <!-- 列表模式 -->
      <template v-else>
      <el-table :data="noticeList" :loading="listLoading" style="width: 100%" highlight-current-row>
        <el-table-column prop="title" label="公告标题" min-width="300px" sortable>
          <template #default="{ row }">
            <div 
              class="notice-item" 
              :class="{ 'unread': row.is_unread }"
              @click="viewDetail(row)"
            >
              <span class="text">{{ row.title || '(无标题)' }}</span>
              <span class="unread-dot" v-if="row.is_unread"></span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="发送时间" prop="time" width="180" sortable></el-table-column>
        <el-table-column label="状态" prop="is_unread" width="100">
          <template #default="{ row }">
            <span v-if="!row.is_unread" style="color: #67c23a;">✓ 已读</span>
            <span v-else style="color: #f56c6c;">○ 未读</span>
          </template>
        </el-table-column>
      </el-table>

      <div v-if="!listLoading && noticeList.length === 0" class="empty-hint">
        <el-empty description="暂无公告" :image-size="100" />
      </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
/* 通知列表页容器 */
.notice-page {
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

.header-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.back-btn {
  border: none;
  background: transparent;
  cursor: pointer;
  color: #606266;
  font-size: 14px;
  padding: 6px 10px;
  border-radius: 4px;
  transition: all 0.2s;
}

.back-btn:hover {
  background-color: #f5f7fa;
  color: var(--primary);
}

/* 详情视图 */
.detail-view {
  background-color: #ffffff;
  border-radius: 6px;
  padding: 24px;
  min-height: 100%;
  box-sizing: border-box;
}

.detail-title {
  margin: 0 0 8px;
  font-size: 18px;
  font-weight: 600;
  color: #303133;
  line-height: 1.4;
}

.detail-meta {
  font-size: 12px;
  color: #909399;
  padding-bottom: 12px;
  border-bottom: 1px solid #ebeef5;
  margin-bottom: 16px;
}

.detail-content {
  font-size: 14px;
  color: #606266;
  line-height: 1.6;
  word-break: break-word;
}

.detail-content img {
  max-width: 100%;
  height: auto;
}

.detail-content p {
  margin: 8px 0;
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

/* 列表样式 */
.notice-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  padding: 10px 0;
  transition: all 0.2s;
}

.notice-item:hover {
  color: var(--primary);
}

.notice-item.unread .text {
  font-weight: 600;
  color: #303133;
}

.unread-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background-color: #f56c6c;
  flex-shrink: 0;
}

.empty-hint {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 100%;
  color: #909399;
}
</style>
