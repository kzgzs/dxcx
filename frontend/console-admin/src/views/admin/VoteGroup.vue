<script setup lang="ts">
/**
 * 扩展·投票活动·投票分组
 * - 列表对齐截图：ID/分组名称/排序/创建时间/操作；添加编辑为小弹窗（分组名称+排序+保存）
 */
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteVoteGroup, getVoteGroupList, saveVoteGroup } from '../../api/vote'
import type { VoteGroupItem } from '../../api/vote'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const list = ref<VoteGroupItem[]>([])
const keyword = ref('')
const selectedIds = ref<number[]>([])

async function load() {
  loading.value = true
  try {
    const res = await getVoteGroupList(keyword.value)
    list.value = res.list || []
  } finally {
    loading.value = false
  }
}
function handleSearch() {
  load()
}
function handleSelectionChange(rows: VoteGroupItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

// ============ 小弹窗 添加/编辑 ============
const dialogVisible = ref(false)
const saving = ref(false)
const form = ref({ id: 0, name: '', sort: 0 })

function openAdd() {
  form.value = { id: 0, name: '', sort: 0 }
  dialogVisible.value = true
}
function openEdit(row: VoteGroupItem) {
  form.value = { id: row.id, name: row.name, sort: row.sort || 0 }
  dialogVisible.value = true
}
async function handleSave() {
  if (!form.value.name.trim()) { ElMessage.warning('请填写分组名称'); return }
  saving.value = true
  try {
    await saveVoteGroup({ id: form.value.id || undefined, name: form.value.name.trim(), sort: form.value.sort })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function handleRowDelete(row: VoteGroupItem) {
  await ElMessageBox.confirm(`确定删除分组「${row.name}」？`, '提示', { type: 'warning' })
  await deleteVoteGroup(row.id)
  ElMessage.success('删除成功')
  load()
}
async function batchDelete() {
  if (!selectedIds.value.length) { ElMessage.warning('请先勾选分组'); return }
  await ElMessageBox.confirm(`确定删除所选 ${selectedIds.value.length} 个分组？`, '提示', { type: 'warning' })
  for (const id of selectedIds.value) await deleteVoteGroup(id)
  ElMessage.success('删除成功')
  selectedIds.value = []
  load()
}

onMounted(load)
</script>

<template>
  <div class="vote-group">
    <div class="toolbar">
      <el-button type="success" @click="openAdd">添加分组</el-button>
      <el-button @click="batchDelete">删除</el-button>
      <el-divider direction="vertical" />
      <el-input v-model="keyword" placeholder="分组名称" clearable class="w-name" @keyup.enter="handleSearch" />
      <el-button type="primary" @click="handleSearch">搜索</el-button>
    </div>

    <el-table :data="list" v-loading="loading" border @selection-change="handleSelectionChange">
      <el-table-column type="selection" width="40" />
      <el-table-column prop="id" label="ID" width="90" sortable />
      <el-table-column prop="name" label="分组名称" min-width="180" />
      <el-table-column prop="sort" label="排序" width="120" align="center" />
      <el-table-column label="创建时间" width="200">
        <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="160">
        <template #default="{ row }">
          <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" size="small" @click="handleRowDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <!-- 添加/编辑分组小弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑分组' : '添加分组'" width="420px" align-center>
      <el-form :model="form" label-width="80px">
        <el-form-item label="分组名称" required>
          <el-input v-model="form.name" placeholder="" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :precision="0" class="field-xs" />
          <span class="tip">越大越靠前</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="success" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.vote-group { padding: 3px; }
.toolbar {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 3px;
}
.w-name { width: 200px; }
.tip { margin-left: 10px; color: #909399; font-size: 12px; }
</style>
