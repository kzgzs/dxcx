<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  checkCycleComment,
  deleteCycleComment,
  getCycleCommentList,
  replyCycleComment,
} from '../../api/cycleComment'
import type { CycleCommentItem, CycleCommentStat } from '../../api/cycleComment'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<CycleCommentItem[]>([])
const total = ref(0)
const stat = ref<CycleCommentStat>({
  total: 0,
  approved: 0,
  pending: 0,
  avg_score: 0,
  haopercent: 100,
})

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  score: 0,
  status: -1,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCycleCommentList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword,
      score: query.score > 0 ? query.score : undefined,
      status: query.status >= 0 ? query.status : undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || stat.value
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
  query.keyword = ''
  query.score = 0
  query.status = -1
  query.page = 1
  fetchList()
}

function statusText(status: number): string {
  return status === 1 ? '已通过' : status === 2 ? '已驳回' : '待审核'
}

// ============ 审核 ============
async function handleCheck(row: CycleCommentItem, status: number) {
  try {
    await checkCycleComment(row.id, status)
    ElMessage.success(status === 1 ? '已通过' : '已驳回')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 回复 ============
function handleReply(row: CycleCommentItem) {
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
        await replyCycleComment({ id: row.id, reply_content: content })
        ElMessage.success('回复成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 删除 ============
function handleDelete(row: CycleCommentItem) {
  ElMessageBox.confirm('确定要删除这条评价吗？删除后不可恢复，商品好评率将重新计算。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCycleComment(row.id)
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
    <!-- 顶部统计 -->
    <el-card shadow="never" class="stat-card">
      <div class="stat-row">
        <div class="stat-item">
          <div class="stat-label">周期购评价总数</div>
          <div class="stat-value">{{ stat.total }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">待审核</div>
          <div class="stat-value">{{ stat.pending }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">已通过</div>
          <div class="stat-value">{{ stat.approved }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">平均评分</div>
          <div class="stat-value">{{ stat.avg_score }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">好评率</div>
          <div class="stat-value">{{ stat.haopercent }}%</div>
        </div>
      </div>
    </el-card>

    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="昵称/商品/内容"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="评分">
          <el-select v-model="query.score" placeholder="全部" clearable style="width: 110px">
            <el-option v-for="n in 5" :key="n" :label="`${n} 星`" :value="n" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="待审核" :value="0" />
            <el-option label="已通过" :value="1" />
            <el-option label="已驳回" :value="2" />
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
            <el-rate :model-value="Number(row.score)" disabled />
          </template>
        </el-table-column>
        <el-table-column label="评价内容" min-width="220">
          <template #default="{ row }">
            <div>{{ row.content }}</div>
            <div v-if="row.content_pic" class="comment-pics">
              <el-image
                v-for="(pic, i) in String(row.content_pic).split(',').filter((v: string) => v)"
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
              {{ statusText(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="评价时间" width="170">
          <template #default="{ row }">{{ row.createtime_text }}</template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 0">
              <el-button link type="success" @click="handleCheck(row, 1)">通过</el-button>
              <el-button link type="warning" @click="handleCheck(row, 2)">驳回</el-button>
            </template>
            <template v-else>
              <el-button
                link
                :type="row.status === 1 ? 'warning' : 'success'"
                @click="handleCheck(row, row.status === 1 ? 2 : 1)"
              >
                {{ row.status === 1 ? '驳回' : '通过' }}
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
        @current-change="(p: number) => ((query.page = p), fetchList())"
        @size-change="(s: number) => ((query.pagesize = s), (query.page = 1), fetchList())"
      />
    </el-card>
  </div>
</template>

<style scoped>
.stat-card {
  margin-bottom: 16px;
}

.stat-row {
  display: flex;
  gap: 48px;
}

.stat-item {
  text-align: center;
}

.stat-label {
  color: #909399;
  font-size: 13px;
}

.stat-value {
  font-size: 22px;
  font-weight: 600;
  margin-top: 4px;
}

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
