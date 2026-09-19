<script setup lang="ts">
// 文章回评管理（批次5-A 任务194）：列表/审核/删除
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
const query = reactive({ page: 1, pagesize: 10, keyword: '', status: '' })

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
async function handleSetst(rows: ArticlePlreplyItem[], st: number) {
  if (!rows.length) return
  await setArticlePlreplySt(rows.map((r) => r.id), st)
  ElMessage.success(st === 1 ? '已通过' : '已驳回')
  load()
}
async function handleDelete(rows: ArticlePlreplyItem[]) {
  if (!rows.length) return
  await ElMessageBox.confirm(`确定删除选中的 ${rows.length} 条回评？`, '提示', { type: 'warning' })
  await deleteArticlePlreply(rows.map((r) => r.id))
  ElMessage.success('删除成功')
  load()
}

onMounted(load)
</script>

<template>
  <div class="article-plreply">
    <div class="toolbar">
      <el-input v-model="query.keyword" placeholder="回评内容" clearable style="width: 200px" @keyup.enter="handleSearch" />
      <el-select v-model="query.status" placeholder="状态" clearable style="width: 120px">
        <el-option label="待审核" value="0" />
        <el-option label="已通过" value="1" />
      </el-select>
      <el-button type="primary" @click="handleSearch">搜索</el-button>
      <div class="toolbar-right">
        <el-button type="success" @click="handleSetst(selected, 1)" :disabled="!selected.length">批量通过</el-button>
        <el-button @click="handleSetst(selected, 0)" :disabled="!selected.length">批量驳回</el-button>
        <el-button type="danger" @click="handleDelete(selected)" :disabled="!selected.length">批量删除</el-button>
      </div>
    </div>

    <el-table v-loading="loading" :data="list" border stripe @selection-change="(rows: ArticlePlreplyItem[]) => (selected = rows)">
      <el-table-column type="selection" width="42" />
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="会员" width="150">
        <template #default="{ row }">
          <div style="display: flex; align-items: center; gap: 6px">
            <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
            <span>{{ row.nickname || (row.mid === 0 ? '平台回复' : `会员${row.mid}`) }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column prop="content" label="回评内容" min-width="180" show-overflow-tooltip />
      <el-table-column prop="plcontent" label="原评论" min-width="160" show-overflow-tooltip />
      <el-table-column prop="title" label="所属文章" min-width="140" show-overflow-tooltip />
      <el-table-column prop="zan" label="点赞" width="70" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag v-if="row.status === 1" type="success">已通过</el-tag>
          <el-tag v-else type="warning">待审核</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="时间" width="150">
        <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="170" fixed="right">
        <template #default="{ row }">
          <el-button v-if="row.status !== 1" link type="success" @click="handleSetst([row], 1)">通过</el-button>
          <el-button v-else link @click="handleSetst([row], 0)">驳回</el-button>
          <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
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
      style="margin-top: 12px; justify-content: flex-end"
      @change="load"
    />
  </div>
</template>

<style scoped>
.article-plreply {
  padding: 12px;
}
.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.toolbar-right {
  margin-left: auto;
  display: flex;
  gap: 8px;
}
</style>
