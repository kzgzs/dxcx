<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteYuyueComment,
  getYuyueCommentList,
  replyYuyueComment,
  setYuyueCommentStatus,
} from '../../api/yuyue'
import type { YuyueCommentItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// 预约 - 商品评价（任务222，对照参考系统 YuyueComment）
const loading = ref(false)
const tableData = ref<YuyueCommentItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  content: '',
  status: '' as number | '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueCommentList({
      page: query.page,
      pagesize: query.pagesize,
      content: query.content || undefined,
      status: query.status === '' ? undefined : query.status,
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
  query.content = ''
  query.status = ''
  query.timeRange = null
  query.page = 1
  fetchList()
}

// ============ 审核：通过 / 隐藏 ============
async function handleSetStatus(row: YuyueCommentItem, st: number) {
  try {
    await setYuyueCommentStatus([row.id], st)
    ElMessage.success(st === 1 ? '已显示' : '已隐藏')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 回复 ============
async function handleReply(row: YuyueCommentItem) {
  try {
    const { value } = await ElMessageBox.prompt('请输入回复内容', '回复评价', {
      confirmButtonText: '回复',
      cancelButtonText: '取消',
      inputValue: row.reply_content || '',
      inputType: 'textarea',
      inputPlaceholder: '请输入回复内容',
      inputValidator: (v) => (v && v.trim() ? true : '回复内容不能为空'),
    })
    await replyYuyueComment(row.id, value)
    ElMessage.success('回复成功')
    fetchList()
  } catch {
    // 取消或接口错误（拦截器已提示）
  }
}

// ============ 删除 ============
function handleDelete(row: YuyueCommentItem) {
  ElMessageBox.confirm('确定要删除这条评价吗？删除后不可恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueComment([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="内容">
          <el-input v-model="query.content" placeholder="评价内容关键词" clearable style="width: 160px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="显示" :value="1" />
            <el-option label="隐藏" :value="0" />
          </el-select>
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
        <el-table-column label="会员" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.nickname || `会员${row.mid}` }}</template>
        </el-table-column>
        <el-table-column prop="proname" label="预约项目" min-width="140" show-overflow-tooltip />
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
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '显示' : '隐藏' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="评价时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.status !== 1" link type="success" @click="handleSetStatus(row, 1)">显示</el-button>
            <el-button v-if="row.status !== 0" link type="warning" @click="handleSetStatus(row, 0)">隐藏</el-button>
            <el-button link type="primary" @click="handleReply(row)">回复</el-button>
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
</style>
