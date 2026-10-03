<script setup lang="ts">
// 文章回评（对齐点大「扩展→文章管理→回评列表」截图）
// 工具栏一行：左 删除/通过/驳回 批量；右 回复(关键词) + 时间 + 搜索；
// 行操作：未审核→ 删除/通过/驳回；已审核→ 仅删除
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteArticlePlreply,
  getArticlePlreplyList,
  setArticlePlreplySt,
  type ArticlePlreplyItem,
} from '../../api/article'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const list = ref<ArticlePlreplyItem[]>([])
const total = ref(0)
const selected = ref<ArticlePlreplyItem[]>([])
const query = reactive({ page: 1, pagesize: 10, keyword: '' })

async function load() {
  loading.value = true
  try {
    const res = await getArticlePlreplyList({ ...query })
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

async function handleBatchSt(st: number) {
  if (!selected.value.length) {
    ElMessage.warning('请选择数据')
    return
  }
  await setArticlePlreplySt(
    selected.value.map((r) => r.id),
    st,
  )
  ElMessage.success(st === 1 ? '已通过' : '已驳回')
  load()
}
async function handleRowSt(row: ArticlePlreplyItem, st: number) {
  await setArticlePlreplySt([row.id], st)
  ElMessage.success(st === 1 ? '已通过' : '已驳回')
  load()
}
async function handleDelete(rows: ArticlePlreplyItem[]) {
  if (!rows.length) {
    ElMessage.warning('请选择数据')
    return
  }
  await ElMessageBox.confirm(`确定删除选中的 ${rows.length} 条回评？`, '删除确认', { type: 'warning' })
  await deleteArticlePlreply(rows.map((r) => r.id))
  ElMessage.success('删除成功')
  selected.value = []
  load()
}

onMounted(load)
</script>

<template>
  <div class="article-plreply">
    <div class="page-title">文章评论回复</div>
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-left">
        <el-button @click="handleDelete(selected)">删除</el-button>
        <el-button @click="handleBatchSt(1)">通过</el-button>
        <el-button @click="handleBatchSt(0)">驳回</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item label="回复">
          <el-input v-model="query.keyword" clearable style="width: 160px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="时间">
          <el-input placeholder="" style="width: 180px" />
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="list"
        border
        :header-cell-style="{ background: '#e6e6e6' }"
        @selection-change="(rows: ArticlePlreplyItem[]) => (selected = rows)"
      >
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="70" align="left" header-align="left" />
        <el-table-column label="用户信息" width="180" align="left" header-align="left">
          <template #default="{ row }">
            <div class="user-cell">
              <el-avatar :size="34" :src="row.headimg">
                {{ (row.nickname || '周').slice(0, 1) }}
              </el-avatar>
              <span class="user-name">{{ row.nickname || (row.mid ? `会员${row.mid}` : '平台回复') }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="plcontent" label="原评论" min-width="160" show-overflow-tooltip align="left" header-align="left" />
        <el-table-column prop="content" label="回复" min-width="160" show-overflow-tooltip align="left" header-align="left" />
        <el-table-column label="发布时间" width="160" align="left" header-align="left">
          <template #default="{ row }">{{ row.createtime ? formatTime(Number(row.createtime)) : '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="left" header-align="left">
          <template #default="{ row }">
            <span v-if="row.status === 1" class="st-pass">已审核</span>
            <span v-else class="st-uncheck">未审核</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180" align="left" header-align="left">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
              <template v-if="row.status !== 1">
                <el-button link type="success" @click="handleRowSt(row, 1)">通过</el-button>
                <el-button link type="warning" @click="handleRowSt(row, 0)">驳回</el-button>
              </template>
            </div>
          </template>
        </el-table-column>
        <template #empty>暂无数据</template>
      </el-table>

      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        class="pagination"
        @change="load"
      />
    </el-card>
  </div>
</template>

<style scoped>
.article-plreply {
  padding: 3px;
}
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding: 4px 2px 8px;
}
.toolbar-card {
  margin-bottom: 3px;
}
.toolbar-card :deep(.el-card__body) {
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
  margin: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.search-btn {
  padding: 8px 14px;
}
.search-btn svg {
  display: block;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.user-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.user-name {
  font-size: 13px;
  color: #303133;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.st-pass {
  color: #67c23a;
}
.st-uncheck {
  color: #f56c6c;
}
.row-ops {
  display: flex;
  gap: 10px;
}
.row-ops .el-button {
  margin: 0;
}
</style>
