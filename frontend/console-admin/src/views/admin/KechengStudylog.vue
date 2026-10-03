<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteKechengStudylog, getKechengStudylogList } from '../../api/kecheng'
import type { KechengStudylogItem } from '../../api/kecheng'

// 学习记录（对齐点大 扩展-知识付费-学习记录：课程信息/学习时间/头像昵称/学习进度/来源/删除，截图无搜索栏）
const loading = ref(false)
const tableData = ref<KechengStudylogItem[]>([])
const total = ref(0)

const query = reactive({ page: 1, pagesize: 10 })

async function fetchList() {
  loading.value = true
  try {
    const data = await getKechengStudylogList({ page: query.page, pagesize: query.pagesize })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 拦截器统一提示
  } finally {
    loading.value = false
  }
}

function handlePageChange(p: number) {
  query.page = p
  fetchList()
}

// 进度展示：jindu==='100' 或 '已学完' → 已学完；否则 进度：xx%
function progressText(row: KechengStudylogItem): string {
  const j = String(row.jindu ?? '')
  if (j === '已学完' || j === '100') return '进度：已学完'
  return `进度：${j}%`
}

function handleDelete(row: KechengStudylogItem) {
  ElMessageBox.confirm('确定要删除该记录吗？删除后无法恢复！', '操作确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteKechengStudylog(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div class="kecheng-studylog">
    <el-table v-loading="loading" :data="tableData" class="data-table" size="default">
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column label="课程信息" min-width="160">
        <template #default="{ row }">
          <div>{{ row.kecheng_name || row.title }}</div>
          <div class="sub">章节：{{ row.title }}</div>
        </template>
      </el-table-column>
      <el-table-column label="学习时间" min-width="150" sortable>
        <template #default="{ row }">
          <span class="sub green">{{ row.createtime_text }}</span>
        </template>
      </el-table-column>
      <el-table-column label="头像昵称" min-width="140">
        <template #default="{ row }">
          <div class="member-cell">
            <el-avatar :src="row.member_headimg" :size="32">{{ (row.member_nickname || '?').slice(0, 1) }}</el-avatar>
            <span>{{ row.member_nickname || row.member_tel || '-' }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="学习进度" min-width="120">
        <template #default="{ row }">
          <span class="progress">{{ progressText(row) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="来源" width="110">
        <template #default="{ row }">{{ row.platform_text || '-' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="100">
        <template #default="{ row }">
          <el-button link type="primary" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      class="pager"
      background
      layout="total, sizes, prev, pager, next"
      :total="total"
      :current-page="query.page"
      :page-size="query.pagesize"
      :page-sizes="[10, 20, 50]"
      @current-change="handlePageChange"
      @size-change="(s: number) => { query.pagesize = s; query.page = 1; fetchList() }"
    />
  </div>
</template>

<style scoped>
.kecheng-studylog {
  margin: 3px;
}

.data-table {
  width: 100%;
}

.data-table :deep(th.el-table__cell) {
  background: #e6e6e6;
  text-align: left;
}

.data-table :deep(.cell) {
  text-align: left;
}

.sub {
  font-size: 12px;
  color: #999;
}

.sub.green {
  color: #080;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.progress {
  color: #ff8758;
  font-size: 13px;
}

.pager {
  margin-top: 10px;
  justify-content: flex-end;
}
</style>
