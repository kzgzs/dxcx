<script setup lang="ts">
// 用户论坛 - 回评列表（对齐点大 LuntanPlreply/index）
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  auditLuntanPlreply,
  deleteLuntanPlreply,
  getLuntanPlreplyList,
} from '../../api/luntan'
import type { LuntanPlreplyItem } from '../../api/luntan'

const loading = ref(false)
const list = ref<LuntanPlreplyItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10, keyword: '' })
const dateRange = ref<[string, string] | null>(null)
const selected = ref<LuntanPlreplyItem[]>([])

async function load() {
  loading.value = true
  try {
    const res = await getLuntanPlreplyList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      starttime: dateRange.value?.[0] || undefined,
      endtime: dateRange.value?.[1] || undefined,
    })
    list.value = res.list || []
    total.value = res.total || 0
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  load()
}

function selectedIds() {
  return selected.value.map((r) => r.id)
}

function batchSet(status: number) {
  const ids = selectedIds()
  if (ids.length === 0) {
    ElMessage.warning('请选择回评')
    return
  }
  auditLuntanPlreply(ids, status)
    .then(() => {
      ElMessage.success(status === 1 ? '已通过' : '已驳回')
      load()
    })
    .catch(() => {})
}

function batchDelete() {
  const ids = selectedIds()
  if (ids.length === 0) {
    ElMessage.warning('请选择回评')
    return
  }
  ElMessageBox.confirm('确定要删除选中的回评吗？', '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteLuntanPlreply(ids)
      ElMessage.success('删除成功')
      load()
    })
    .catch(() => {})
}

function rowSet(row: LuntanPlreplyItem, status: number) {
  auditLuntanPlreply([row.id], status)
    .then(() => {
      ElMessage.success(status === 1 ? '已通过' : '已驳回')
      load()
    })
    .catch(() => {})
}

function rowDelete(row: LuntanPlreplyItem) {
  ElMessageBox.confirm('确定要删除该回评吗？', '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteLuntanPlreply([row.id])
      ElMessage.success('删除成功')
      load()
    })
    .catch(() => {})
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-title">帖子评论回复</div>

    <el-card shadow="never" class="toolbar">
      <div class="toolbar-left">
        <el-button @click="batchDelete">删除</el-button>
        <el-button @click="batchSet(1)">通过</el-button>
        <el-button @click="batchSet(0)">驳回</el-button>
      </div>
      <div class="toolbar-right">
        <el-input v-model="query.keyword" placeholder="标题" clearable style="width: 160px" @keyup.enter="handleSearch" @clear="handleSearch" />
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
        <el-button type="success" class="search-btn" @click="handleSearch">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274205 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.186354 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
        </el-button>
      </div>
    </el-card>

    <el-card shadow="never" class="table-card">
      <el-table v-loading="loading" :data="list" border size="small" @selection-change="(rows: LuntanPlreplyItem[]) => (selected = rows)">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="用户信息" width="150">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar :size="28" :src="row.headimg || undefined">{{ (row.nickname || '会员').slice(0, 1) }}</el-avatar>
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="原评论" min-width="200">
          <template #default="{ row }">{{ row.pinglun_content || '-' }}</template>
        </el-table-column>
        <el-table-column label="回复" min-width="200">
          <template #default="{ row }">{{ row.content || '-' }}</template>
        </el-table-column>
        <el-table-column label="发布时间" width="150">
          <template #default="{ row }">{{ row.createtime_text || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'st-on' : 'st-off'">{{ row.status === 1 ? '已审核' : '未审核' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="success" @click="rowSet(row, 1)">通过</el-button>
              <el-button link type="danger" @click="rowSet(row, 0)">驳回</el-button>
              <el-button link type="danger" @click="rowDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
        <template #empty>无数据</template>
      </el-table>

      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next, jumper"
        class="pagination"
        @current-change="load"
        @size-change="load"
      />
    </el-card>
  </div>
</template>

<style scoped>
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding: 2px 4px 8px;
}
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
  gap: 8px;
}
.toolbar-left .el-button {
  margin: 0;
}
.toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: nowrap;
}
.search-btn {
  padding: 8px 14px;
}
.search-btn svg {
  display: block;
}
.table-card {
  margin-top: 3px;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
.st-on {
  color: #67c23a;
}
.st-off {
  color: #909399;
}
.row-ops {
  display: flex;
  flex-direction: row;
  column-gap: 8px;
}
.row-ops .el-button {
  margin: 0;
  padding: 0;
  height: auto;
  font-size: 12px;
}
</style>
