<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteWebMessage,
  getWebMessageList,
  setWebMessageStatus,
} from '../api/message'
import type { WebMessageItem } from '../api/message'
import { formatTime } from '../utils/time'

// 网站留言（对照参考系统 WebMessage + web_message/index.html）
// 结构：删除按钮 + 检索行（联系人/联系方式/提交时间/状态）+ 数据表（ID/客户ip/联系人/联系方式/留言内容/留言时间/状态/操作）

// ============ 列表：筛选 + 排序 + 分页 ============
const loading = ref(false)
const tableData = ref<WebMessageItem[]>([])
const total = ref(0)
const selected = ref<WebMessageItem[]>([])

const filter = reactive({
  realname: '',
  tel: '',
  ctime: null as [string, string] | null,
  status: '' as '' | 0 | 1,
})
const query = reactive({
  page: 1,
  pagesize: 15,
  field: '',
  order: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getWebMessageList({
      page: query.page,
      pagesize: query.pagesize,
      realname: filter.realname || undefined,
      tel: filter.tel || undefined,
      status: filter.status === '' ? undefined : filter.status,
      start_date: filter.ctime?.[0] || undefined,
      end_date: filter.ctime?.[1] || undefined,
      field: query.field || undefined,
      order: query.order || undefined,
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
  filter.realname = ''
  filter.tel = ''
  filter.ctime = null
  filter.status = ''
  query.page = 1
  fetchList()
}

// 表头排序（参考系统 ID 列可排序）
function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  query.field = order ? prop : ''
  query.order = order === 'ascending' ? 'asc' : order === 'descending' ? 'desc' : ''
  fetchList()
}

function handleSelectionChange(rows: WebMessageItem[]) {
  selected.value = rows
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

// ============ 批量/行内操作（当页完成，不跳转） ============

// 取操作目标：行内传 row，批量取选中项（未选中提示「请选择数据」同参考系统）
function pickIds(row?: WebMessageItem): number[] | null {
  if (row) return [row.id]
  if (selected.value.length === 0) {
    ElMessage.warning('请选择数据')
    return null
  }
  return selected.value.map((item) => item.id)
}

// 删除（二次确认弹窗）
function handleDelete(row?: WebMessageItem) {
  const ids = pickIds(row)
  if (!ids) return
  ElMessageBox.confirm('删除后将无法恢复，确定要删除吗？', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteWebMessage(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 改为已处理 / 改为未处理（二次确认弹窗）
function handleSetStatus(st: 0 | 1, row?: WebMessageItem) {
  const ids = pickIds(row)
  if (!ids) return
  ElMessageBox.confirm('确认要更新该留言的处理状态吗？', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setWebMessageStatus(ids, st)
        ElMessage.success('操作成功')
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
  <el-card shadow="never">
    <template #header>
      <span>网站留言</span>
    </template>

    <!-- 操作按钮行（对照参考系统：删除） -->
    <div class="toolbar-btns">
      <el-button @click="handleDelete()">删除</el-button>
    </div>

    <!-- 检索行（对照参考系统：联系人 / 联系方式 / 提交时间 / 状态） -->
    <div class="toolbar-filter">
      <span class="filter-label">联系人</span>
      <el-input
        v-model="filter.realname"
        placeholder="请输入联系人"
        style="width: 180px"
        clearable
        @keyup.enter="handleSearch"
      />
      <span class="filter-label">联系方式</span>
      <el-input
        v-model="filter.tel"
        placeholder="请输入联系方式"
        style="width: 180px"
        clearable
        @keyup.enter="handleSearch"
      />
      <span class="filter-label">提交时间</span>
      <el-date-picker
        v-model="filter.ctime"
        type="daterange"
        range-separator="~"
        start-placeholder="开始日期"
        end-placeholder="结束日期"
        value-format="YYYY-MM-DD"
        style="width: 260px"
      />
      <span class="filter-label">状态</span>
      <el-select v-model="filter.status" style="width: 110px" placeholder="全部">
        <el-option label="全部" value="" />
        <el-option label="未处理" :value="0" />
        <el-option label="已处理" :value="1" />
      </el-select>
      <el-button class="btn-teal" @click="handleSearch">搜索</el-button>
      <el-button @click="handleReset">清空</el-button>
    </div>

    <!-- 留言列表（对照参考系统表头：ID / 客户ip / 联系人 / 联系方式 / 留言内容 / 留言时间 / 状态 / 操作，border 竖线与通知公告统一） -->
    <el-table
      v-loading="loading"
      :data="tableData"
      stripe
      border
      @selection-change="handleSelectionChange"
      @sort-change="handleSortChange"
    >
      <el-table-column type="expand">
        <template #default="{ row }">
          <div class="content-expand">{{ row.content }}</div>
        </template>
      </el-table-column>
      <el-table-column type="selection" width="42" />
      <el-table-column prop="id" label="ID" width="60" sortable="custom" />
      <el-table-column prop="ip" label="客户ip" min-width="120" show-overflow-tooltip />
      <el-table-column prop="realname" label="联系人" min-width="90" show-overflow-tooltip />
      <el-table-column prop="tel" label="联系方式" min-width="110" show-overflow-tooltip />
      <el-table-column prop="content" label="留言内容" min-width="200" show-overflow-tooltip />
      <el-table-column prop="createtime" label="留言时间" width="150" sortable="custom">
        <template #default="{ row }">
          {{ formatTime(row.createtime) }}
        </template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }">
          <span :class="row.status === 1 ? 'status-done' : 'status-pending'">
            {{ row.status === 1 ? '已处理' : '未处理' }}
          </span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="150" fixed="right">
        <template #default="{ row }">
          <el-button
            v-if="row.status === 0"
            link
            type="primary"
            @click="handleSetStatus(1, row)"
          >
            改为已处理
          </el-button>
          <el-button v-else link type="primary" @click="handleSetStatus(0, row)">
            改为未处理
          </el-button>
          <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>

      <template #empty>
        <el-empty description="暂无留言" :image-size="90" />
      </template>
    </el-table>

    <el-pagination
      class="pagination"
      background
      layout="total, sizes, prev, pager, next, jumper"
      :total="total"
      :current-page="query.page"
      :page-size="query.pagesize"
      :page-sizes="[15, 30, 50, 100]"
      @current-change="handlePageChange"
      @size-change="handleSizeChange"
    />
  </el-card>
</template>

<style scoped>
.toolbar-btns {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.toolbar-filter {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.filter-label {
  font-size: 13px;
  color: var(--el-text-color-regular);
  margin-left: 6px;
}
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
/* 展开行：留言内容全文（pre-wrap 仅用于展开查看，不影响列表行高） */
.content-expand {
  padding: 10px 24px;
  white-space: pre-wrap;
  word-break: break-all;
  font-size: 13px;
  line-height: 1.7;
  color: #303133;
}
.status-done {
  color: #67c23a;
}
.status-pending {
  color: #f56c6c;
}
/* 青绿色主操作按钮（搜索，与租户页对齐） */
.btn-teal {
  --el-button-bg-color: #13c2c2;
  --el-button-border-color: #13c2c2;
  --el-button-hover-bg-color: #36cfc9;
  --el-button-hover-border-color: #36cfc9;
  --el-button-active-bg-color: #0ea9a9;
  --el-button-active-border-color: #0ea9a9;
  --el-button-text-color: #ffffff;
  color: #ffffff;
  background-color: #13c2c2;
  border-color: #13c2c2;
}
.btn-teal:hover,
.btn-teal:focus {
  color: #ffffff;
  background-color: #36cfc9;
  border-color: #36cfc9;
}
</style>
