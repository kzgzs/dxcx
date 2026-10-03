<script setup lang="ts">
/**
 * 答题记录（课程列表行操作「答题记录」嵌入视图）
 *
 * 对外契约：
 * - 可选 prop embeddedProid?: number | string —— 传入时只展示该课程(kcid)的答题记录，并自带返回头
 * - emit('back') —— 返回课程列表
 */
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteKechengRecord,
  getKechengRecordList,
  getKechengRecordLogs,
} from '../../api/kecheng'
import type { KechengRecordItem, KechengRecordLogItem } from '../../api/kecheng'
import { assetUrl } from '../../api/upload'

const props = defineProps<{ embeddedProid?: number | string }>()
const emit = defineEmits<{ (e: 'back'): void }>()
const embedded = computed(
  () => props.embeddedProid !== undefined && props.embeddedProid !== null && props.embeddedProid !== '',
)
const embeddedKcid = computed(() => (embedded.value ? Number(props.embeddedProid) : 0))

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<KechengRecordItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(20)
const filterMid = ref('')

async function fetchList() {
  loading.value = true
  try {
    const data = await getKechengRecordList({
      kcid: embedded.value ? embeddedKcid.value : undefined,
      mid: filterMid.value ? Number(filterMid.value) : undefined,
      page: page.value,
      pagesize: pagesize.value,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  page.value = 1
  fetchList()
}

// ============ 多选 / 删除 ============
const selectedRows = ref<KechengRecordItem[]>([])
const selectedIds = computed(() => selectedRows.value.map((r) => r.id))
function handleSelectionChange(rows: KechengRecordItem[]) {
  selectedRows.value = rows
}

async function handleBatchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选记录')
    return
  }
  try {
    await ElMessageBox.confirm(`确认删除选中的 ${selectedIds.value.length} 条记录？`, '提示', {
      type: 'warning',
    })
    await deleteKechengRecord(selectedIds.value)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    /* 取消或拦截器已提示 */
  }
}

async function handleDelete(row: KechengRecordItem) {
  try {
    await ElMessageBox.confirm('确认删除该答题记录？', '提示', { type: 'warning' })
    await deleteKechengRecord([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    /* 取消或拦截器已提示 */
  }
}

// ============ 答题详情 ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detailRows = ref<KechengRecordLogItem[]>([])

async function openDetail(row: KechengRecordItem) {
  detailVisible.value = true
  detailLoading.value = true
  detailRows.value = []
  try {
    const data = await getKechengRecordLogs({ rid: row.id, page: 1, pagesize: 200 })
    detailRows.value = data.list || []
  } catch {
    /* 拦截器已提示 */
  } finally {
    detailLoading.value = false
  }
}

function judgeText(s: number) {
  if (s === 1) return { text: '正确', cls: 'st-on' }
  if (s === 2) return { text: '错误', cls: 'st-warn' }
  return { text: '未答', cls: 'st-warn' }
}

onMounted(fetchList)
</script>

<template>
  <div>
    <div v-if="embedded" class="back-bar" @click="emit('back')">← 返回课程列表</div>

    <el-card shadow="never">
      <div class="toolbar-row">
        <div class="tb-left">
          <el-button @click="handleBatchDelete">删除</el-button>
        </div>
        <div class="tb-right">
          <template v-if="!embedded">
            <span class="tb-label">会员ID</span>
            <el-input v-model="filterMid" style="width: 130px" @keyup.enter="handleSearch" />
          </template>
          <el-button type="primary" @click="handleSearch">🔍</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="tableData" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" sortable />
        <el-table-column prop="kcname" label="所属课程" min-width="140" show-overflow-tooltip />
        <el-table-column label="用户信息" min-width="200">
          <template #default="{ row }">
            <div class="user-cell">
              <img v-if="row.headimg" :src="assetUrl(row.headimg)" class="user-thumb" alt="" />
              <div class="user-meta">
                <div>ID:{{ row.mid }}</div>
                <div>{{ row.nickname }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="答题时间" width="150">
          <template #default="{ row }">{{ row.time_text }}</template>
        </el-table-column>
        <el-table-column label="结束时间" width="150">
          <template #default="{ row }">{{ row.endtime_text || '-' }}</template>
        </el-table-column>
        <el-table-column prop="score" label="分数" width="70" />
        <el-table-column label="答题状态" width="90">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'st-on' : 'st-warn'">
              {{ row.status === 1 ? '已答完' : '未答完' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="是否合格" width="90">
          <template #default="{ row }">
            <span :class="row.ishg === 1 ? 'st-on' : 'st-warn'">
              {{ row.ishg === 1 ? '已合格' : '不合格' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openDetail(row)">答题详情</el-button>
              <el-button link type="primary" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager"
        v-model:current-page="page"
        v-model:page-size="pagesize"
        :total="total"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </el-card>

    <!-- 答题详情 -->
    <el-dialog v-model="detailVisible" title="答题详情" width="760px" append-to-body>
      <el-table v-loading="detailLoading" :data="detailRows" size="small">
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="title" label="题目" min-width="200" show-overflow-tooltip />
        <el-table-column label="答案" width="120">
          <template #default="{ row }">
            <span class="ans">{{ row.answer || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="是否答对" width="90">
          <template #default="{ row }">
            <span :class="judgeText(row.status).cls">{{ judgeText(row.status).text }}</span>
          </template>
        </el-table-column>
        <el-table-column label="答题时间" width="150">
          <template #default="{ row }">{{ row.time_text }}</template>
        </el-table-column>
        <el-table-column prop="score" label="分数" width="70" />
      </el-table>
    </el-dialog>
  </div>
</template>

<style scoped>
.back-bar {
  cursor: pointer;
  color: #409eff;
  font-size: 14px;
  padding: 6px 2px;
  margin-bottom: 3px;
}

.back-bar:hover {
  text-decoration: underline;
}

.toolbar-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: nowrap;
  gap: 8px;
}

.tb-left,
.tb-right {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: nowrap;
}

.tb-label {
  font-size: 13px;
  color: #606266;
  white-space: nowrap;
}

.pager {
  margin-top: 6px;
  justify-content: flex-end;
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.user-thumb {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  object-fit: cover;
  background: #f5f7fa;
}

.user-meta {
  font-size: 12px;
  line-height: 1.5;
}

.st-on {
  color: #67c23a;
}

.st-warn {
  color: #e6a23c;
}

.ans {
  color: #f60;
}
</style>
