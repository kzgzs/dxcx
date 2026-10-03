<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  delPeisongComment,
  getPeisongCommentList,
  getPeisongUserList,
  replyPeisongComment,
} from '../../api/peisong'
import type { PeisongCommentItem } from '../../api/peisong'
import { formatTime } from '../../utils/time'

// 租户后台 - 同城配送·评价列表（照截图：左删除 + 右配送员/评价内容/评价时间/搜索一行；行操作 回复/删除）

const loading = ref(false)
const tableData = ref<PeisongCommentItem[]>([])
const total = ref(0)
const selected = ref<PeisongCommentItem[]>([])
const riderOptions = ref<{ id: number; realname: string; tel: string }[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  psid: '' as number | '',
  content: '',
  timeRange: null as [string, string] | null,
})

async function fetchRiders() {
  try {
    const data = await getPeisongUserList({ page: 1, pagesize: 100 })
    riderOptions.value = (data.list || []).map((u) => ({
      id: Number(u.id),
      realname: String(u.realname || ''),
      tel: String(u.tel || ''),
    }))
  } catch { /* 拦截器 */ }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getPeisongCommentList({
      page: query.page,
      pagesize: query.pagesize,
      psid: query.psid === '' ? undefined : query.psid,
      content: query.content || undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch { /* 拦截器 */ } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

// 回复弹窗
const replyVisible = ref(false)
const replySubmitting = ref(false)
const replyRow = ref<PeisongCommentItem | null>(null)
const replyForm = reactive({ content: '' })

function openReply(row: PeisongCommentItem) {
  replyRow.value = row
  replyForm.content = row.reply_content || ''
  replyVisible.value = true
}

async function submitReply() {
  if (!replyRow.value) return
  replySubmitting.value = true
  try {
    await replyPeisongComment({ id: replyRow.value.id, content: replyForm.content })
    ElMessage.success('回复成功')
    replyVisible.value = false
    fetchList()
  } catch { /* 拦截器 */ } finally {
    replySubmitting.value = false
  }
}

// 删除
async function handleDelete(row?: PeisongCommentItem) {
  const ids = row ? [row.id] : selected.value.map((v) => v.id)
  if (!ids.length) {
    ElMessage.warning('请先选择评价')
    return
  }
  try {
    await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  await delPeisongComment(ids)
  ElMessage.success('删除成功')
  fetchList()
}

onMounted(() => {
  fetchList()
  fetchRiders()
})
</script>

<template>
  <div>
    <!-- 一行工具条：左 删除；右 配送员/评价内容/评价时间/搜索 -->
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-inner">
        <div class="toolbar-left">
          <el-button @click="handleDelete()">删除</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tf-label">配送员</span>
          <el-select v-model="query.psid" placeholder="全部" clearable style="width: 150px">
            <el-option
              v-for="u in riderOptions"
              :key="u.id"
              :label="u.realname"
              :value="u.id"
            />
          </el-select>
          <span class="tf-label">评价内容</span>
          <el-input
            v-model="query.content"
            placeholder=""
            clearable
            style="width: 160px"
            @keyup.enter="handleSearch"
          />
          <span class="tf-label">评价时间</span>
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            range-separator="~"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 240px"
          />
          <button class="search-btn" title="搜索" @click="handleSearch">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M11.4 10.2a6 6 0 1 0-1.2 1.2l3.4 3.4 1.2-1.2-3.4-3.4zM7 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
          </button>
        </div>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="(rows: PeisongCommentItem[]) => (selected = rows)">
        <el-table-column type="selection" width="40" />
        <el-table-column label="配送员" min-width="140">
          <template #default="{ row }">
            <div>{{ row.psuser?.realname || '-' }}</div>
            <div v-if="row.psuser?.tel" class="sub">{{ row.psuser.tel }}</div>
          </template>
        </el-table-column>
        <el-table-column label="配送商家" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.business?.name || '自营' }}</template>
        </el-table-column>
        <el-table-column label="评价人信息" min-width="140">
          <template #default="{ row }">
            <div class="member-cell">
              <el-image v-if="row.headimg" :src="row.headimg" fit="cover" class="member-img" />
              <span>{{ row.nickname || '-' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评分" width="90">
          <template #default="{ row }">{{ row.score }}星</template>
        </el-table-column>
        <el-table-column prop="content" label="评价内容" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.content || '-' }}</template>
        </el-table-column>
        <el-table-column label="评价时间" width="150" sortable>
          <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" min-width="130">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openReply(row)">回复</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager" background layout="total, sizes, prev, pager, next, jumper"
        :total="total" v-model:current-page="query.page" v-model:page-size="query.pagesize"
        :page-sizes="[10, 20, 50]" @current-change="fetchList" @size-change="handleSearch"
      />
    </el-card>

    <!-- 回复弹窗 -->
    <el-dialog v-model="replyVisible" title="回复评价" width="480px" destroy-on-close>
      <el-form label-width="80px">
        <el-form-item label="回复内容">
          <el-input v-model="replyForm.content" type="textarea" :rows="4" placeholder="请输入回复内容" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="replyVisible = false">取消</el-button>
        <el-button type="primary" :loading="replySubmitting" @click="submitReply">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
.toolbar-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap; }
.toolbar-left { display: flex; gap: 8px; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.tf-label { font-size: 13px; color: #606266; white-space: nowrap; }
.search-btn { width: 30px; height: 30px; border: none; background: #67c23a; color: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 3px; }
.el-card { border-radius: 0; }
.el-table :deep(th) { background: #e6e6e6; text-align: left; }
.el-table :deep(td .cell) { text-align: left; }
.pager { margin-top: 12px; justify-content: flex-end; }
.sub { color: #909399; font-size: 12px; }
.row-ops { display: flex; gap: 10px; flex-wrap: nowrap; }
.member-cell { display: flex; align-items: center; gap: 6px; }
.member-img { width: 30px; height: 30px; border-radius: 50%; background: #f2f3f5; }
</style>
