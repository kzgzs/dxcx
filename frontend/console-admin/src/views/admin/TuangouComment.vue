<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteTuangouComment,
  getTuangouCommentList,
  replyTuangouComment,
  setTuangouCommentStatus,
} from '../../api/tuangou'
import type { TuangouCommentItem } from '../../api/tuangou'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<TuangouCommentItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  status: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getTuangouCommentList({
      page: query.page,
      pagesize: query.pagesize,
      status: query.status === '' ? undefined : query.status,
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
  query.status = ''
  query.page = 1
  fetchList()
}

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

// ============ 审核（显示/隐藏） ============
async function handleSetStatus(row: TuangouCommentItem, status: number) {
  try {
    await setTuangouCommentStatus(row.id, status)
    ElMessage.success(status === 1 ? '已显示' : '已隐藏')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 回复 ============
function handleReply(row: TuangouCommentItem) {
  ElMessageBox.prompt('请输入回复内容', '商家回复', {
    confirmButtonText: '回复',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputValue: row.reply_content,
    inputPlaceholder: '请填写回复内容',
  })
    .then(async ({ value }) => {
      const content = (value || '').trim()
      if (!content) {
        ElMessage.warning('回复内容不能为空')
        return
      }
      try {
        await replyTuangouComment(row.id, content)
        ElMessage.success('回复成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 删除 ============
function handleDelete(row: TuangouCommentItem) {
  ElMessageBox.confirm('确定要删除这条评价吗？删除后不可恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteTuangouComment(row.id)
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
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 140px">
            <el-option label="待审核" :value="0" />
            <el-option label="已显示" :value="1" />
            <el-option label="已隐藏" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 评价列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column label="会员" min-width="130">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="32" />
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="proname" label="商品" min-width="160" show-overflow-tooltip />
        <el-table-column label="评分" width="140">
          <template #default="{ row }">
            <el-rate :model-value="row.score" disabled />
          </template>
        </el-table-column>
        <el-table-column label="评价内容" min-width="220">
          <template #default="{ row }">
            <div>{{ row.content }}</div>
            <div v-if="row.content_pic" class="comment-pics">
              <el-image
                v-for="(pic, i) in row.content_pic.split(',').filter((v: string) => v)"
                :key="i"
                :src="pic"
                fit="cover"
                style="width: 32px; height: 32px; border-radius: 4px"
              />
            </div>
            <div v-if="row.reply_content" class="text-muted">
              商家回复：{{ row.reply_content }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag
              :type="row.status === 1 ? 'success' : row.status === 2 ? 'info' : 'warning'"
              size="small"
            >
              {{ row.status_text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="评价时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="210" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 0">
              <el-button link type="success" @click="handleSetStatus(row, 1)">通过</el-button>
              <el-button link type="warning" @click="handleSetStatus(row, 2)">隐藏</el-button>
            </template>
            <template v-else>
              <el-button
                link
                :type="row.status === 1 ? 'warning' : 'success'"
                @click="handleSetStatus(row, row.status === 1 ? 2 : 1)"
              >
                {{ row.status === 1 ? '隐藏' : '显示' }}
              </el-button>
            </template>
            <el-button link type="primary" @click="handleReply(row)">回复</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
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

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.comment-pics {
  display: flex;
  gap: 4px;
  margin-top: 4px;
}

.text-muted {
  color: #909399;
  font-size: 12px;
  margin-top: 4px;
}
</style>
