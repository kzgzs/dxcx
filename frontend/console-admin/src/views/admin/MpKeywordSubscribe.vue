<script setup lang="ts">
// 公众号被关注回复（批次7-2 任务210，对标参考系统 Mpkeyword/subscribe）
// ktype=2，最多3条；消息类型七种，与关键字回复共用 MpReplyEditor
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteMpSubscribe,
  getMpSubscribeList,
  msgtypeLabel,
  saveMpSubscribe,
} from '../../api/mp'
import type { MpKeywordItem } from '../../api/mp'
import { formatTime } from '../../utils/time'
import MpReplyEditor from './MpReplyEditor.vue'

const loading = ref(false)
const list = ref<MpKeywordItem[]>([])
const limit = ref(3)

async function load() {
  loading.value = true
  try {
    const res = await getMpSubscribeList()
    list.value = res.list || []
    limit.value = res.limit || 3
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

/** 列表内容摘要（text 直显，素材类取标题/地址） */
function contentSummary(row: MpKeywordItem): string {
  const raw = row.content || ''
  if (row.msgtype === 'text') return raw
  try {
    const data = JSON.parse(raw) || {}
    return data.title || data.url || data.pic || data.media_id || data.pagepath || raw
  } catch {
    return raw
  }
}

// ============ 编辑弹窗 ============
const dialogVisible = ref(false)
const saving = ref(false)
const editorRef = ref<InstanceType<typeof MpReplyEditor>>()
const form = reactive({ id: 0, sort: 0 })

function openAdd() {
  if (list.value.length >= limit.value) {
    ElMessage.warning(`被关注回复最多设置 ${limit.value} 条`)
    return
  }
  form.id = 0
  form.sort = 0
  editorRef.value?.reset()
  dialogVisible.value = true
}

function openEdit(row: MpKeywordItem) {
  form.id = row.id
  form.sort = Number(row.sort)
  editorRef.value?.setFromRecord(row)
  dialogVisible.value = true
}

async function handleSave() {
  const payload = {
    id: form.id || undefined,
    sort: form.sort,
    ...(editorRef.value?.toPayload() || {}),
  }
  saving.value = true
  try {
    await saveMpSubscribe(payload)
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出（含3条上限拦截）
  } finally {
    saving.value = false
  }
}

async function handleDelete(row: MpKeywordItem) {
  try {
    await ElMessageBox.confirm('确定删除该条被关注回复？', '提示', { type: 'warning' })
  } catch {
    return
  }
  await deleteMpSubscribe([row.id])
  ElMessage.success('删除成功')
  load()
}

onMounted(load)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">被关注回复</span>
        <div class="card-actions">
          <span class="count-tip">{{ list.length }} / {{ limit }} 条</span>
          <el-button type="primary" :disabled="list.length >= limit" @click="openAdd">添加回复</el-button>
        </div>
      </div>
    </template>

    <div class="page-desc">用户关注公众号后自动推送的消息，最多设置 {{ limit }} 条，按创建时间顺序依次发送。</div>

    <el-table :data="list" border style="width: 100%">
      <el-table-column label="ID" prop="id" width="70" align="center" />
      <el-table-column label="回复类型" width="120" align="center">
        <template #default="{ row }">{{ msgtypeLabel(row.msgtype) }}</template>
      </el-table-column>
      <el-table-column label="回复内容" min-width="260" show-overflow-tooltip>
        <template #default="{ row }">{{ contentSummary(row) }}</template>
      </el-table-column>
      <el-table-column label="排序" prop="sort" width="70" align="center" />
      <el-table-column label="添加时间" width="160" align="center">
        <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="110" align="center" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
      <template #empty>
        <el-empty description="暂未设置被关注回复" :image-size="80" />
      </template>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑被关注回复' : '添加被关注回复'" width="680px" destroy-on-close>
      <el-form label-width="90px" label-position="right">
        <MpReplyEditor ref="editorRef" />
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="9999" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.card-title {
  font-weight: 600;
}
.card-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.count-tip {
  color: #999;
  font-size: 12px;
}
.page-desc {
  margin-bottom: 12px;
  color: #999;
  font-size: 12px;
}
</style>
