<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteShortvideoComment,
  getShortvideoCommentList,
  replyShortvideoComment,
  setShortvideoCommentStatus,
} from '../../api/shortvideo'
import type { ShortvideoCommentItem } from '../../api/shortvideo'

// ============ 列表 ============
const loading = ref(false)
const list = ref<ShortvideoCommentItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10, content: '' })
const dateRange = ref<[string, string] | null>(null)
const orderby = ref('')
const selected = ref<ShortvideoCommentItem[]>([])

function buildCreatetime(): string {
  if (!dateRange.value || dateRange.value.length < 2) return ''
  const [s, e] = dateRange.value
  if (!s || !e) return s || ''
  return `${s},${e}`
}

async function fetchList() {
  loading.value = true
  try {
    const res = await getShortvideoCommentList({
      page: query.page,
      pagesize: query.pagesize,
      content: query.content,
      createtime: buildCreatetime() || undefined,
      orderby: orderby.value || undefined,
    })
    list.value = res.list
    total.value = res.total
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

function onSortChange({ prop, order }: { prop?: string; order?: 'ascending' | 'descending' | null }) {
  orderby.value = prop && order ? `${prop}|${order === 'ascending' ? 'asc' : 'desc'}` : ''
  handleSearch()
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function statusTagType(s: number): 'success' | 'info' | 'warning' | 'danger' {
  return s === 1 ? 'success' : s === 2 ? 'danger' : 'warning'
}

// ============ 审核（通过=1 / 驳回=2） / 回复 / 删除 ============
async function handleSetStatus(rows: ShortvideoCommentItem[], status: number) {
  if (!rows.length) {
    ElMessage.warning('请选择数据')
    return
  }
  const label = status === 1 ? '通过' : '驳回'
  await ElMessageBox.confirm(`确定${label}选中的 ${rows.length} 条评论吗？`, '审核确认', { type: 'warning' })
  await setShortvideoCommentStatus(rows.map((r) => r.id), status)
  ElMessage.success(`已${label}`)
  selected.value = []
  fetchList()
}

function handleReply(row: ShortvideoCommentItem) {
  ElMessageBox.prompt('回复内容（以平台身份回复，直接显示）', '回复评论', {
    confirmButtonText: '回复',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputValidator: (v) => (v && v.trim() ? true : '回复内容不能为空'),
  })
    .then(async ({ value }) => {
      try {
        await replyShortvideoComment(row.id, value)
        ElMessage.success('回复成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

async function handleDelete(rows: ShortvideoCommentItem[]) {
  if (!rows.length) {
    ElMessage.warning('请选择数据')
    return
  }
  await ElMessageBox.confirm(`确定删除选中的 ${rows.length} 条评论吗？其回评与点赞记录一并清理。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
  await deleteShortvideoComment(rows.map((r) => r.id))
  ElMessage.success('删除成功')
  selected.value = []
  fetchList()
}

onMounted(fetchList)
</script>

<template>
  <div class="sv-page">
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-left">
        <el-button @click="handleDelete(selected)">删除</el-button>
        <el-button type="success" plain @click="handleSetStatus(selected, 1)">通过</el-button>
        <el-button type="danger" plain @click="handleSetStatus(selected, 2)">驳回</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item label="回复">
          <el-input
            v-model="query.content"
            placeholder="回复"
            clearable
            style="width: 130px"
            @keyup.enter="handleSearch"
            @clear="handleSearch"
          />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 240px"
            @change="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274205 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.186354 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="list" border @selection-change="(rows: ShortvideoCommentItem[]) => (selected = rows)" @sort-change="onSortChange">
        <el-table-column type="selection" width="42" align="center" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="用户信息" width="150">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="26" />
              <el-avatar v-else :size="26">{{ (row.nickname || '会员').slice(0, 1) }}</el-avatar>
              <span class="member-name">{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="vname" label="标题" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.vname || '-' }}</template>
        </el-table-column>
        <el-table-column prop="content" label="内容" min-width="200" show-overflow-tooltip />
        <el-table-column prop="replycount" label="回复数" width="80" />
        <el-table-column prop="createtime" label="发布时间" width="160" sortable="custom">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="160">
          <template #default="{ row }">
            <div class="row-ops">
              <template v-if="row.status === 0">
                <el-button link type="success" @click="handleSetStatus([row], 1)">通过</el-button>
                <el-button link type="danger" @click="handleSetStatus([row], 2)">驳回</el-button>
              </template>
              <el-button link type="primary" @click="handleReply(row)">回复</el-button>
              <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
            </div>
          </template>
        </el-table-column>
        <template #empty>暂无数据</template>
      </el-table>

      <el-pagination
        v-if="total > 0"
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next, jumper"
        class="pagination"
        @change="fetchList"
      />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar {
  margin-bottom: 3px;
}
.toolbar :deep(.el-card__body) {
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 0 0 auto;
}
.toolbar-left .el-button {
  margin: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  min-width: 0;
  margin: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.toolbar-search :deep(.el-form-item__label) {
  padding-right: 6px;
}
.search-icon-btn {
  padding: 8px 14px;
}
.search-icon-btn svg {
  display: block;
}
.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
.member-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.row-ops {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 8px;
}
.row-ops .el-button {
  margin: 0;
  height: auto;
  min-height: 0;
  padding: 0;
}
:deep(.el-table__header th) {
  background: #e6e6e6 !important;
}
:deep(.el-table .cell) {
  text-align: left;
}
</style>
