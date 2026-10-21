<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import {
  checkCycleComment,
  deleteCycleComment,
  getCycleCommentDetail,
  getCycleCommentList,
  replyCycleComment,
} from '../../api/cycleComment'
import type { CycleCommentItem } from '../../api/cycleComment'

// 周期购 评价管理（对齐「扩展--周期购--评价管理」截图）：
// 工具行=[删除][已审][未审] + 评价内容 + 评价时间区间 + 搜索；
// 列=复选框/ID/商品信息/评价人信息/评分(排序)/评价内容/评价时间(排序)/状态/操作(详情·回复·删除)。
// 真实落库回读；状态列点击切换 已审/未审。

const loading = ref(false)
const tableData = ref<CycleCommentItem[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  content: '',
  dateRange: [] as string[],
  order_field: 'id',
  order_type: 'desc' as 'asc' | 'desc',
})

async function fetchList() {
  loading.value = true
  try {
    const params: Record<string, unknown> = {
      page: query.page,
      pagesize: query.pagesize,
      order_field: query.order_field,
      order_type: query.order_type,
    }
    if (query.content) params.content = query.content
    if (query.dateRange && query.dateRange.length === 2) {
      params.start_time = Math.floor(new Date(query.dateRange[0]).getTime() / 1000)
      params.end_time = Math.floor(new Date(query.dateRange[1]).getTime() / 1000)
    }
    const data = await getCycleCommentList(params)
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

// 列头排序（评分 / 评价时间）
function handleSort({ prop, order }: { prop: string; order: 'ascending' | 'descending' | null }) {
  if (!order) {
    query.order_field = 'id'
    query.order_type = 'desc'
  } else {
    query.order_field = prop === 'score' ? 'score' : 'createtime'
    query.order_type = order === 'ascending' ? 'asc' : 'desc'
  }
  query.page = 1
  fetchList()
}

function onSelectionChange(rows: CycleCommentItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

// ============ 批量操作 ============
function needSelected(): boolean {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选评价')
    return false
  }
  return true
}

async function batchDelete() {
  if (!needSelected()) return
  await ElMessageBox.confirm('确定要删除选中的评价吗？删除后无法恢复！', '删除确认', { type: 'warning' })
  try {
    await deleteCycleComment(0, selectedIds.value)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    /* 取消或拦截器已提示 */
  }
}

async function batchSetStatus(status: number) {
  if (!needSelected()) return
  await ElMessageBox.confirm(`确定要将选中评价标记为「${status === 1 ? '已审核' : '未审核'}」吗？`, '操作确认', { type: 'warning' })
  try {
    await checkCycleComment(0, status, selectedIds.value)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    /* 取消或拦截器已提示 */
  }
}

// ============ 行操作：状态切换 ============
async function toggleStatus(row: CycleCommentItem) {
  // 已审核→取消审核(未审)；未审/已驳回→通过
  const next = row.status === 1 ? 0 : 1
  try {
    await checkCycleComment(row.id, next)
    ElMessage.success(next === 1 ? '已审核' : '已取消审核')
    fetchList()
  } catch {
    /* 拦截器已提示 */
  }
}

function statusText(row: CycleCommentItem): string {
  if (row.status === 1) return '已审核'
  if (row.status === 2) return '已驳回'
  return '未审核'
}

// ============ 行操作：详情 ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<CycleCommentItem | null>(null)
const detailMember = ref<Record<string, unknown>>({})

async function openDetail(row: CycleCommentItem) {
  detailVisible.value = true
  detailLoading.value = true
  try {
    const res = await getCycleCommentDetail(row.id)
    detail.value = res.detail
    detailMember.value = res.member || {}
  } catch {
    /* 拦截器已提示 */
  } finally {
    detailLoading.value = false
  }
}

// ============ 行操作：回复 ============
function handleReply(row: CycleCommentItem) {
  ElMessageBox.prompt('请输入回复内容', '回复评价', {
    confirmButtonText: '回复',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputValue: row.reply_content || '',
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
        /* 拦截器已提示 */
      }
    })
    .catch(() => {})
}

// ============ 行操作：删除 ============
function handleDelete(row: CycleCommentItem) {
  ElMessageBox.confirm('确定要删除这条评价吗？删除后无法恢复！', '删除确认', { type: 'warning' })
    .then(async () => {
      try {
        await deleteCycleComment(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器已提示 */
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never" class="compact-title">
      <template #header>商品评价</template>

      <!-- 工具行：批量按钮 + 搜索 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button @click="batchDelete">删除</el-button>
          <el-button @click="batchSetStatus(1)">已审</el-button>
          <el-button @click="batchSetStatus(0)">未审</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tf-label">评价内容</span>
          <el-input v-model="query.content" placeholder="" clearable class="field-md" @keyup.enter="handleSearch" />
          <span class="tf-label">评价时间</span>
          <el-date-picker
            v-model="query.dateRange"
            type="daterange"
            range-separator="~"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            class="field-range"
          />
          <el-button type="primary" :icon="Search" @click="handleSearch" />
        </div>
      </div>

      <!-- 评价表格 -->
      <el-table v-loading="loading" :data="tableData" stripe @selection-change="onSelectionChange" @sort-change="handleSort">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="商品信息" width="240">
          <template #default="{ row }">
            <div class="prod-cell">
              <el-image v-if="row.propic" :src="row.propic" fit="cover" class="prod-img" />
              <div class="prod-text">
                <div class="prod-name">{{ row.proname }}</div>
                <div class="prod-gg">{{ row.ggname }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评价人信息" width="180">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="score" label="评分" width="100" sortable="custom">
          <template #default="{ row }">{{ row.score }}星</template>
        </el-table-column>
        <el-table-column label="评价内容" min-width="200">
          <template #default="{ row }">
            <div>{{ row.content }}</div>
            <div v-if="row.reply_content" class="reply-line">商家回复：{{ row.reply_content }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="createtime" label="评价时间" width="160" sortable="custom">
          <template #default="{ row }">{{ row.createtime_text }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-button
              link
              :type="row.status === 1 ? 'success' : row.status === 2 ? 'info' : 'danger'"
              @click="toggleStatus(row)"
            >
              {{ statusText(row) }}
            </el-button>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button link type="primary" @click="handleReply(row)">回复</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, prev, pager, next"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        @current-change="(p: number) => ((query.page = p), fetchList())"
      />
    </el-card>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="评价详情" width="520px">
      <div v-loading="detailLoading" class="detail-box">
        <div class="d-item"><span class="d-t">评价人</span><span class="d-v">{{ detailMember.nickname }}</span></div>
        <div class="d-item"><span class="d-t">评价时间</span><span class="d-v">{{ detail?.createtime_text }}</span></div>
        <div class="d-item"><span class="d-t">评分</span><span class="d-v">{{ detail?.score }}星</span></div>
        <div class="d-item"><span class="d-t">内容</span><span class="d-v">{{ detail?.content }}</span></div>
        <div v-if="detail && (detail.content_pic_arr || []).length" class="d-item">
          <span class="d-t">图片</span>
          <span class="d-v pics">
            <el-image
              v-for="(pic, i) in detail.content_pic_arr"
              :key="i"
              :src="pic"
              fit="cover"
              style="width: 48px; height: 48px; margin-right: 6px; border-radius: 4px"
              :preview-src-list="detail.content_pic_arr"
              preview-teleported
            />
          </span>
        </div>
        <div v-if="detail?.reply_content" class="d-item">
          <span class="d-t">商家回复</span><span class="d-v">{{ detail.reply_content }}</span>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 3px;
}
.toolbar-left,
.toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
}
.tf-label {
  color: #606266;
  font-size: 13px;
}
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
.prod-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.prod-img {
  width: 40px;
  height: 40px;
  border-radius: 4px;
  flex-shrink: 0;
}
.prod-name {
  font-size: 13px;
  color: #303133;
}
.prod-gg {
  font-size: 12px;
  color: #f60;
  margin-top: 2px;
}
.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}
.reply-line {
  color: #909399;
  font-size: 12px;
  margin-top: 4px;
}
.detail-box {
  padding: 0 8px;
}
.d-item {
  display: flex;
  padding: 8px 0;
  border-bottom: 1px dashed #ededed;
}
.d-t {
  width: 90px;
  color: #909399;
  flex-shrink: 0;
}
.d-v {
  color: #303133;
}
.pics {
  display: flex;
}
</style>
