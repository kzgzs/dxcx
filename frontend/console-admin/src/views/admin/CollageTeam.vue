<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteCollageTeam, getCollageTeamList } from '../../api/collage'
import type { CollageTeamItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<CollageTeamItem[]>([])
const total = ref(0)

// tabs：空=全部（后端排除未支付空团）1进行中 2成功 3失败
const activeTab = ref('')

const query = reactive({
  page: 1,
  pagesize: 10,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCollageTeamList({
      page: query.page,
      pagesize: query.pagesize,
      status: activeTab.value === '' ? undefined : Number(activeTab.value),
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleTabChange() {
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

// 状态标签配色
function statusTagType(status: number) {
  if (status === 1) return 'warning'
  if (status === 2) return 'success'
  if (status === 3) return 'danger'
  return 'info'
}

// 是否已超时（进行中但已过截止时间）
function isOverdue(row: CollageTeamItem) {
  return row.status === 1 && row.deadline > 0 && row.deadline * 1000 < Date.now()
}

// ============ 删除 ============
function handleDelete(row: CollageTeamItem) {
  ElMessageBox.confirm(
    `确定要删除该拼团记录吗？（商品：${row.product_name || row.proid}）删除后不可恢复。`,
    '删除确认',
    {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning',
    },
  )
    .then(async () => {
      try {
        await deleteCollageTeam(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出（进行中的团会被后端拒绝删除）
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane label="全部" name="" />
        <el-tab-pane label="进行中" name="1" />
        <el-tab-pane label="成团成功" name="2" />
        <el-tab-pane label="成团失败" name="3" />
      </el-tabs>

      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="团ID" width="80" />
        <el-table-column prop="product_name" label="商品" min-width="160" show-overflow-tooltip />
        <el-table-column label="成团进度" width="120">
          <template #default="{ row }">
            <span :class="{ 'text-danger': isOverdue(row) }">
              {{ row.num }} / {{ row.teamnum }} 人
            </span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">
              {{ row.status_text }}
            </el-tag>
            <div v-if="isOverdue(row)" class="overdue-tip">待定时任务解散</div>
          </template>
        </el-table-column>
        <el-table-column label="团员" min-width="180">
          <template #default="{ row }">
            <div v-if="row.members.length" class="member-row">
              <el-tooltip
                v-for="m in row.members"
                :key="`${row.id}-${m.mid}-${m.nickname}`"
                :content="m.nickname"
                placement="top"
              >
                <el-avatar :size="28" :src="m.headimg || undefined">
                  {{ m.nickname.slice(0, 1) }}
                </el-avatar>
              </el-tooltip>
            </div>
            <span v-else class="text-muted">暂无</span>
          </template>
        </el-table-column>
        <el-table-column label="开团时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="截止时间" width="160">
          <template #default="{ row }">
            <span :class="{ 'text-danger': isOverdue(row) }">
              {{ row.deadline ? formatTime(row.deadline) : '-' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button
              link
              type="danger"
              :disabled="row.status === 1"
              @click="handleDelete(row)"
            >
              删除
            </el-button>
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
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.member-row {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
}

.text-danger {
  color: #f56c6c;
}

.text-muted {
  color: #909399;
}

.overdue-tip {
  margin-top: 2px;
  color: #f56c6c;
  font-size: 12px;
}
</style>
