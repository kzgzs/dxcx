<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteYuyueWorkerComment,
  getYuyueWorkerCommentDetail,
  getYuyueWorkerCommentList,
  getYuyueWorkerList,
} from '../../api/yuyue'
import type { YuyueWorkerCommentItem, YuyueWorkerItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// 预约 - 人员评价（任务222，对照参考系统 YuyueWorkerComment）
const loading = ref(false)
const tableData = ref<YuyueWorkerCommentItem[]>([])
const total = ref(0)
const workerOptions = ref<YuyueWorkerItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  worker_id: '' as number | '',
  content: '',
  timeRange: null as [string, string] | null,
})

async function fetchWorkers() {
  try {
    const data = await getYuyueWorkerList({ page: 1, pagesize: 100 })
    workerOptions.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueWorkerCommentList({
      page: query.page,
      pagesize: query.pagesize,
      worker_id: query.worker_id === '' ? undefined : query.worker_id,
      content: query.content || undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
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

function handleReset() {
  query.worker_id = ''
  query.content = ''
  query.timeRange = null
  query.page = 1
  fetchList()
}

// ============ 详情弹窗 ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detailData = ref<Record<string, unknown>>({})

async function openDetail(row: YuyueWorkerCommentItem) {
  detailVisible.value = true
  detailLoading.value = true
  detailData.value = { ...row }
  try {
    detailData.value = await getYuyueWorkerCommentDetail(row.id)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    detailLoading.value = false
  }
}

function detailPics(): string[] {
  const pics = detailData.value.pics
  return Array.isArray(pics) ? (pics as string[]) : []
}

// ============ 删除 ============
function handleDelete(row: YuyueWorkerCommentItem) {
  ElMessageBox.confirm('确定要删除这条人员评价吗？删除后不可恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueWorkerComment([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(() => {
  fetchWorkers()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="人员">
          <el-select v-model="query.worker_id" placeholder="全部" clearable filterable style="width: 150px">
            <el-option v-for="w in workerOptions" :key="w.id" :label="w.name" :value="w.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="内容">
          <el-input v-model="query.content" placeholder="评价内容关键词" clearable style="width: 160px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="人员" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.worker_name || `#${row.worker_id}` }}
            <span v-if="row.worker_tel" class="sub-text">{{ row.worker_tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="会员" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.nickname || `会员${row.mid}` }}</template>
        </el-table-column>
        <el-table-column label="评分" width="80">
          <template #default="{ row }">
            <el-rate :model-value="Number(row.score)" disabled size="small" />
          </template>
        </el-table-column>
        <el-table-column label="评价内容" min-width="220">
          <template #default="{ row }">
            <div class="comment-text">{{ row.content || '-' }}</div>
            <div v-if="row.pics && row.pics.length" class="pic-row">
              <el-image
                v-for="(pic, idx) in row.pics"
                :key="idx"
                :src="pic"
                :preview-src-list="row.pics"
                :initial-index="idx"
                fit="cover"
                class="comment-pic"
              />
            </div>
            <div v-if="row.reply_content" class="reply-text">回复：{{ row.reply_content }}</div>
          </template>
        </el-table-column>
        <el-table-column label="评价时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="pager"
        background
        layout="total, sizes, prev, pager, next"
        :total="total"
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :page-sizes="[10, 20, 50]"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </el-card>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="人员评价详情" width="520px">
      <div v-loading="detailLoading" class="detail-body">
        <div class="detail-row">
          <span class="detail-label">人员</span>
          <span>{{ detailData.worker_name || '-' }}（{{ detailData.worker_tel || '-' }}）</span>
        </div>
        <div class="detail-row">
          <span class="detail-label">会员</span>
          <span>{{ detailData.nickname || `会员${detailData.mid}` }}</span>
        </div>
        <div class="detail-row">
          <span class="detail-label">订单号</span>
          <span>{{ detailData.ordernum || '-' }}</span>
        </div>
        <div class="detail-row">
          <span class="detail-label">评分</span>
          <el-rate :model-value="Number(detailData.score || 0)" disabled size="small" />
        </div>
        <div class="detail-row">
          <span class="detail-label">评价内容</span>
          <span class="comment-text">{{ detailData.content || '-' }}</span>
        </div>
        <div v-if="detailPics().length" class="detail-row">
          <span class="detail-label">评价图片</span>
          <div class="pic-row">
            <el-image
              v-for="(pic, idx) in detailPics()"
              :key="idx"
              :src="pic"
              :preview-src-list="detailPics()"
              :initial-index="idx"
              fit="cover"
              class="comment-pic"
            />
          </div>
        </div>
        <div class="detail-row">
          <span class="detail-label">回复</span>
          <span>{{ detailData.reply_content || '-' }}</span>
        </div>
        <div class="detail-row">
          <span class="detail-label">评价时间</span>
          <span>{{ detailData.createtime ? formatTime(Number(detailData.createtime)) : '-' }}</span>
        </div>
      </div>
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 4px;
}

.comment-text {
  white-space: pre-wrap;
  word-break: break-all;
}

.pic-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
}

.comment-pic {
  width: 48px;
  height: 48px;
  border-radius: 4px;
}

.reply-text {
  color: #909399;
  font-size: 12px;
  margin-top: 4px;
}

.detail-body {
  min-height: 80px;
}

.detail-row {
  display: flex;
  gap: 8px;
  padding: 6px 0;
  font-size: 13px;
}

.detail-label {
  color: #909399;
  width: 64px;
  flex-shrink: 0;
}
</style>
