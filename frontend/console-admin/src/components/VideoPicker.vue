<script setup lang="ts">
/**
 * 视频独立选择弹窗（任务297）
 * - 数据源复用视频管理列表接口 /admin/videoList/list（名称搜索/类型/分页）
 * - 与图片/图标选择弹窗（ImgIconPicker）完全分离
 * - emit pick(video)：url=本地视频链接，name/cover/type 一并带回
 */
import { reactive, ref, watch } from 'vue'
import { getVideoList, type VideoListItem } from '../api/videolist'

export interface PickedVideo {
  id: number
  url: string
  name: string
  cover: string
  type: number
}

const props = defineProps<{ visible: boolean }>()
const emit = defineEmits<{
  (e: 'update:visible', v: boolean): void
  (e: 'pick', video: PickedVideo): void
}>()

const loading = ref(false)
const list = ref<VideoListItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 8,
  name: '',
  type: '' as string,
})

async function fetchList() {
  loading.value = true
  try {
    const res = await getVideoList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name || undefined,
      type: query.type === '' ? undefined : query.type,
    })
    list.value = res.list || []
    total.value = res.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function typeText(t: number) {
  return t === 1 ? '微信视频号' : '本地视频'
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function handlePick(row: VideoListItem) {
  emit('pick', {
    id: row.id,
    url: row.video_url || '',
    name: row.name || '',
    cover: row.pic || '',
    type: Number(row.type) || 0,
  })
  emit('update:visible', false)
}

watch(
  () => props.visible,
  (v) => {
    if (v) fetchList()
  },
)
</script>

<template>
  <el-dialog
    :model-value="visible"
    title="选择视频"
    width="780px"
    destroy-on-close
    @update:model-value="(v: boolean) => emit('update:visible', v)"
  >
    <div class="vp-card">
      <div class="vp-card-title">视频管理</div>

      <!-- 搜索条 -->
      <div class="vp-toolbar">
        <span class="vp-label">名称</span>
        <el-input
          v-model="query.name"
          placeholder="视频名称"
          clearable
          style="width: 180px"
          @keyup.enter="handleSearch"
          @clear="handleSearch"
        />
        <span class="vp-label">类型</span>
        <el-select v-model="query.type" placeholder="全部" clearable style="width: 140px" @change="handleSearch">
          <el-option label="本地视频" value="0" />
          <el-option label="微信视频号" value="1" />
        </el-select>
        <el-button type="primary" @click="handleSearch">搜索</el-button>
      </div>

      <!-- 视频表格 -->
      <el-table v-loading="loading" :data="list" size="small" border stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="视频封面" width="100">
          <template #default="{ row }">
            <el-image v-if="row.pic" :src="row.pic" fit="cover" class="vp-cover" />
            <span v-else class="vp-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="视频名称" min-width="140" show-overflow-tooltip />
        <el-table-column label="视频类型" width="110">
          <template #default="{ row }">{{ typeText(Number(row.type)) }}</template>
        </el-table-column>
        <el-table-column label="创建时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template #default="{ row }">
            <el-button size="small" @click="handlePick(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="vp-pager"
        background
        layout="total, prev, pager, next"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        @current-change="handlePageChange"
      />
    </div>
  </el-dialog>
</template>

<style scoped>
.vp-card {
  border: 1px solid #ebeef5;
  border-radius: 4px;
  padding: 12px;
}

.vp-card-title {
  font-weight: 600;
  margin-bottom: 12px;
}

.vp-toolbar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  margin-bottom: 12px;
}

.vp-label {
  color: #606266;
  font-size: 13px;
}

.vp-cover {
  width: 64px;
  height: 40px;
  border-radius: 4px;
}

.vp-muted {
  color: #909399;
}

.vp-pager {
  margin-top: 12px;
  justify-content: flex-end;
}
</style>
