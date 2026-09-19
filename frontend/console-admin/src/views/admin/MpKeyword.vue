<script setup lang="ts">
// 公众号关键字回复（批次7-2 任务210，对标参考系统 Mpkeyword/index）
// ktype 0完全匹配 1包含匹配；msgtype 七种；素材类保存时后端自动换取 MediaId
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteMpKeyword,
  getMpKeywordList,
  msgtypeLabel,
  saveMpKeyword,
} from '../../api/mp'
import type { MpKeywordItem } from '../../api/mp'
import { formatTime } from '../../utils/time'
import MpReplyEditor from './MpReplyEditor.vue'

const loading = ref(false)
const list = ref<MpKeywordItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, limit: 15, keyword: '' })

async function load() {
  loading.value = true
  try {
    const res = await getMpKeywordList(query)
    list.value = res.data || []
    total.value = res.count || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  load()
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
const form = reactive({ id: 0, keyword: '', ktype: 0, sort: 0 })

function openAdd() {
  form.id = 0
  form.keyword = ''
  form.ktype = 0
  form.sort = 0
  editorRef.value?.reset()
  dialogVisible.value = true
}

function openEdit(row: MpKeywordItem) {
  form.id = row.id
  form.keyword = row.keyword
  form.ktype = Number(row.ktype)
  form.sort = Number(row.sort)
  editorRef.value?.setFromRecord(row)
  dialogVisible.value = true
}

async function handleSave() {
  if (!form.keyword.trim()) {
    ElMessage.warning('请填写关键字')
    return
  }
  const payload = {
    id: form.id || undefined,
    keyword: form.keyword.trim(),
    ktype: form.ktype,
    sort: form.sort,
    ...(editorRef.value?.toPayload() || {}),
  }
  saving.value = true
  try {
    await saveMpKeyword(payload)
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

async function handleDelete(row: MpKeywordItem) {
  try {
    await ElMessageBox.confirm(`确定删除关键字「${row.keyword}」的回复规则？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  await deleteMpKeyword([row.id])
  ElMessage.success('删除成功')
  load()
}

onMounted(load)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">关键字回复</span>
        <div class="card-actions">
          <el-input
            v-model="query.keyword"
            placeholder="搜索关键字"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
            @clear="handleSearch"
          />
          <el-button @click="handleSearch">搜索</el-button>
          <el-button type="primary" @click="openAdd">添加回复</el-button>
        </div>
      </div>
    </template>

    <el-table :data="list" border style="width: 100%">
      <el-table-column label="ID" prop="id" width="70" align="center" />
      <el-table-column label="关键字" prop="keyword" min-width="140" show-overflow-tooltip />
      <el-table-column label="匹配方式" width="100" align="center">
        <template #default="{ row }">
          <el-tag v-if="Number(row.ktype) === 0" type="primary">完全匹配</el-tag>
          <el-tag v-else type="warning">包含匹配</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="回复类型" width="110" align="center">
        <template #default="{ row }">{{ msgtypeLabel(row.msgtype) }}</template>
      </el-table-column>
      <el-table-column label="回复内容" min-width="200" show-overflow-tooltip>
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
        <el-empty description="暂无关键字回复规则" :image-size="80" />
      </template>
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.limit"
        :total="total"
        :page-sizes="[15, 30, 50]"
        layout="total, sizes, prev, pager, next"
        @current-change="load"
        @size-change="handleSearch"
      />
    </div>

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑关键字回复' : '添加关键字回复'" width="680px" destroy-on-close>
      <el-form label-width="90px" label-position="right">
        <el-form-item label="关键字" required>
          <el-input v-model="form.keyword" maxlength="250" placeholder="用户发送的关键字" />
        </el-form-item>
        <el-form-item label="匹配方式">
          <el-radio-group v-model="form.ktype">
            <el-radio :value="0">完全匹配</el-radio>
            <el-radio :value="1">包含匹配</el-radio>
          </el-radio-group>
        </el-form-item>
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
  gap: 8px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
</style>
