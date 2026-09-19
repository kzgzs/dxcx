<script setup lang="ts">
// 文章评论管理（批次5-A 任务194）：列表/审核/删除/后台回复
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteArticlePinglun,
  getArticlePinglunList,
  replyArticlePinglun,
  setArticlePinglunSt,
  type ArticlePinglunItem,
} from '../../api/article'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const list = ref<ArticlePinglunItem[]>([])
const total = ref(0)
const selected = ref<ArticlePinglunItem[]>([])
const query = reactive({ page: 1, pagesize: 10, keyword: '', status: '' })

async function load() {
  loading.value = true
  try {
    const res = await getArticlePinglunList({ ...query })
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

// 审核（st 1通过 0驳回待审）
async function handleSetst(rows: ArticlePinglunItem[], st: number) {
  if (!rows.length) return
  await setArticlePinglunSt(rows.map((r) => r.id), st)
  ElMessage.success(st === 1 ? '已通过' : '已驳回')
  load()
}
async function handleDelete(rows: ArticlePinglunItem[]) {
  if (!rows.length) return
  await ElMessageBox.confirm(`确定删除选中的 ${rows.length} 条评论？其回评将一并删除`, '提示', { type: 'warning' })
  await deleteArticlePinglun(rows.map((r) => r.id))
  ElMessage.success('删除成功')
  load()
}

// 后台回复
const replyVisible = ref(false)
const replyTarget = ref<ArticlePinglunItem | null>(null)
const replyContent = ref('')
const replySaving = ref(false)
function openReply(row: ArticlePinglunItem) {
  replyTarget.value = row
  replyContent.value = ''
  replyVisible.value = true
}
async function handleReplySave() {
  if (!replyTarget.value) return
  if (!replyContent.value.trim()) {
    ElMessage.warning('回复内容不能为空')
    return
  }
  replySaving.value = true
  try {
    await replyArticlePinglun(replyTarget.value.id, replyContent.value.trim())
    ElMessage.success('回复成功')
    replyVisible.value = false
    load()
  } finally {
    replySaving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="article-pinglun">
    <div class="toolbar">
      <el-input v-model="query.keyword" placeholder="评论内容" clearable style="width: 200px" @keyup.enter="handleSearch" />
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

    <el-table v-loading="loading" :data="list" border stripe @selection-change="(rows: ArticlePinglunItem[]) => (selected = rows)">
      <el-table-column type="selection" width="42" />
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="会员" width="150">
        <template #default="{ row }">
          <div style="display: flex; align-items: center; gap: 6px">
            <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
            <span>{{ row.nickname || `会员${row.mid}` }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column prop="content" label="评论内容" min-width="200" show-overflow-tooltip />
      <el-table-column prop="title" label="所属文章" min-width="140" show-overflow-tooltip />
      <el-table-column prop="replycount" label="回评数" width="80" />
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
      <el-table-column label="操作" width="220" fixed="right">
        <template #default="{ row }">
          <el-button v-if="row.status !== 1" link type="success" @click="handleSetst([row], 1)">通过</el-button>
          <el-button v-else link @click="handleSetst([row], 0)">驳回</el-button>
          <el-button link type="primary" @click="openReply(row)">回复</el-button>
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

    <el-dialog v-model="replyVisible" title="后台回复" width="520px" destroy-on-close>
      <div class="reply-origin">原评论：{{ replyTarget?.content }}</div>
      <el-input v-model="replyContent" type="textarea" :rows="4" maxlength="500" show-word-limit placeholder="以平台身份回复，回复后直接显示" />
      <template #footer>
        <el-button @click="replyVisible = false">取消</el-button>
        <el-button type="primary" :loading="replySaving" @click="handleReplySave">回复</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.article-pinglun {
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
.reply-origin {
  margin-bottom: 12px;
  padding: 8px 12px;
  background: #f5f7fa;
  border-radius: 4px;
  color: #606266;
}
</style>
