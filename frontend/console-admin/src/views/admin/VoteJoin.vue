<script setup lang="ts">
/**
 * 扩展·投票活动·选手列表（平铺整页 + 右上 X；添加/编辑选手为平铺表单）
 * - 行操作：编辑/删除/投票记录/加票（加票小弹窗，真实落库并写投票记录）
 * - 从活动列表「选手列表」进入时，按 voteNavState.joinHid 锁定活动并显示 X 返回
 */
import { computed, inject, onActivated, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadProps } from 'element-plus'
import {
  addVoteJoinPiao,
  auditVoteJoin,
  deleteVoteJoin,
  getVoteJoinList,
  getVoteList,
  saveVoteJoin,
} from '../../api/vote'
import type { VoteActivityItem, VoteJoinItem } from '../../api/vote'
import { voteNavState } from '../../api/vote'
import { formatTime } from '../../utils/time'
import { useAuthStore } from '../../stores/auth'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'
import VoteRecord from './VoteRecord.vue'

const props = defineProps<{
  /** 内嵌模式：锁定活动 id（从活动列表「选手列表」平铺进入） */
  lockedHid?: number
  /** 内嵌模式（在活动主区域平铺打开），X 触发 close */
  embedded?: boolean
}>()
const emit = defineEmits<{ (e: 'close'): void }>()

const authStore = useAuthStore()
const navigateByKey = inject<(key: string) => boolean>('adminNavigateByKey', () => false)

// ============ 上传 ============
const uploadHeaders = computed(() => ({ Authorization: `Bearer ${authStore.token}` }))
function parseUploadResponse(response: { code?: number; msg?: string; data?: { url?: string } }): string {
  if (response && response.code === 0 && response.data?.url) return response.data.url
  ElMessage.error(response?.msg || '上传失败')
  return ''
}
const onUploadError: UploadProps['onError'] = () => ElMessage.error('上传失败，请重试')

// ============ 活动下拉 ============
const activityOptions = ref<VoteActivityItem[]>([])
async function loadActivities() {
  const res = await getVoteList({ page: 1, pagesize: 100 })
  activityOptions.value = res.list || []
}
function activityName(hid: number): string {
  return activityOptions.value.find((a) => a.id === hid)?.name || `活动${hid}`
}

// ============ 列表 ============
const joinLoading = ref(false)
const joinData = ref<VoteJoinItem[]>([])
const joinTotal = ref(0)
const selectedIds = ref<number[]>([])
const joinQuery = reactive({
  page: 1,
  pagesize: 10,
  hid: 0,
  status: '' as number | '',
  keyword: '',
})

async function loadJoins() {
  joinLoading.value = true
  try {
    const res = await getVoteJoinList({ ...joinQuery })
    joinData.value = res.list || []
    joinTotal.value = res.total || 0
  } finally {
    joinLoading.value = false
  }
}
function handleSearch() {
  joinQuery.page = 1
  loadJoins()
}
function handleSelectionChange(rows: VoteJoinItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

const statusText = (s: number) => (s === 1 ? '已通过' : s === 2 ? '已驳回' : '待审核')
const statusType = (s: number): 'success' | 'danger' | 'info' => (s === 1 ? 'success' : s === 2 ? 'danger' : 'info')

// 嵌套返回（从活动列表进入时显示 X）
const nestedMode = computed(() => !!voteNavState.returnTo)
function closeNested() {
  if (props.embedded) {
    emit('close')
    return
  }
  const back = voteNavState.returnTo
  voteNavState.returnTo = ''
  voteNavState.joinHid = 0
  if (back) navigateByKey(back)
}

// 内嵌「投票记录」平铺层（按选手 joinid 过滤）
const recordOverlay = ref<{ joinid: number; hid: number } | null>(null)

// ============ 批量通过 / 驳回 / 删除 ============
async function batchDelete() {
  if (!selectedIds.value.length) { ElMessage.warning('请先勾选选手'); return }
  await ElMessageBox.confirm(`确定删除所选 ${selectedIds.value.length} 名选手？其投票记录将一并删除`, '提示', { type: 'warning' })
  for (const id of selectedIds.value) await deleteVoteJoin(id)
  ElMessage.success('删除成功')
  selectedIds.value = []
  loadJoins()
}
async function batchPass() {
  if (!selectedIds.value.length) { ElMessage.warning('请先勾选选手'); return }
  await ElMessageBox.confirm(`确定通过所选 ${selectedIds.value.length} 名选手？通过后将自动生成选手编号`, '提示', { type: 'warning' })
  await auditVoteJoin(selectedIds.value, 1)
  ElMessage.success('已通过')
  selectedIds.value = []
  loadJoins()
}
async function batchReject() {
  if (!selectedIds.value.length) { ElMessage.warning('请先勾选选手'); return }
  const { value } = await ElMessageBox.prompt('请填写驳回原因', '驳回选手', {
    confirmButtonText: '驳回', cancelButtonText: '取消', inputPlaceholder: '驳回原因（必填）',
    inputValidator: (v: string) => (!!v && !!v.trim()) || '驳回原因不能为空',
  })
  await auditVoteJoin(selectedIds.value, 2, String(value).trim())
  ElMessage.success('已驳回')
  selectedIds.value = []
  loadJoins()
}

// ============ 导出（当前页 CSV） ============
function exportCsv() {
  const header = ['ID', '所属活动', '选手名称', '选手编号', '联系方式', '累计票数', '访问次数', '排名', '参与时间', '状态']
  const rows = joinData.value.map((r) => [
    r.id, r.hdname || activityName(r.hid), r.name, r.number, r.weixin, r.helpnum, r.readcount, r.mingci,
    formatTime(r.createtime), statusText(r.status),
  ])
  const csv = [header, ...rows].map((line) => line.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = '选手列表.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}

// ============ 平铺 添加/编辑选手 ============
const view = ref<'list' | 'form'>('list')
const joinSaving = ref(false)
const joinForm = reactive({
  id: 0,
  hid: 0,
  name: '',
  pic: '',
  pics: '',
  detail_txt: '',
  detail: '',
  weixin: '',
  status: 1,
  reason: '',
})
const picFileList = computed(() => (joinForm.pic ? [{ name: 'pic', url: joinForm.pic }] : []))
const picsFileList = computed(() =>
  joinForm.pics ? joinForm.pics.split(',').filter(Boolean).map((u) => ({ url: u })) : [],
)

function openAdd() {
  Object.assign(joinForm, {
    id: 0, hid: joinQuery.hid || activityOptions.value[0]?.id || 0, name: '', pic: '', pics: '',
    detail_txt: '', detail: '', weixin: '', status: 1, reason: '',
  })
  view.value = 'form'
}
function openEdit(row: VoteJoinItem) {
  Object.assign(joinForm, {
    id: row.id, hid: row.hid, name: row.name, pic: row.pic || '', pics: row.pics || '',
    detail_txt: row.detail_txt || '', detail: row.detail || '', weixin: row.weixin || '',
    status: row.status, reason: row.reason || '',
  })
  view.value = 'form'
}
function closeForm() {
  view.value = 'list'
  loadJoins()
}
async function handleSave() {
  if (!joinForm.hid) { ElMessage.warning('请选择所属活动'); return }
  if (!joinForm.name.trim()) { ElMessage.warning('请填写选手名称'); return }
  if (joinForm.status === 2 && !joinForm.reason.trim()) { ElMessage.warning('已驳回时请填写驳回原因'); return }
  joinSaving.value = true
  try {
    await saveVoteJoin({ ...joinForm })
    ElMessage.success('保存成功')
    closeForm()
  } finally {
    joinSaving.value = false
  }
}

async function handleRowDelete(row: VoteJoinItem) {
  await ElMessageBox.confirm(`确定删除选手「${row.name}」？其投票记录将一并删除`, '提示', { type: 'warning' })
  await deleteVoteJoin(row.id)
  ElMessage.success('删除成功')
  loadJoins()
}

// ============ 加票小弹窗 ============
const addpiaoVisible = ref(false)
const addpiaoNum = ref<number>(1)
const addpiaoTarget = ref<VoteJoinItem | null>(null)
function openAddpiao(row: VoteJoinItem) {
  addpiaoTarget.value = row
  addpiaoNum.value = 1
  addpiaoVisible.value = true
}
async function confirmAddpiao() {
  if (!addpiaoTarget.value) return
  if (!addpiaoNum.value || addpiaoNum.value < 1) { ElMessage.warning('请输入大于0的票数'); return }
  await addVoteJoinPiao(addpiaoTarget.value.id, addpiaoNum.value)
  ElMessage.success(`已加 ${addpiaoNum.value} 票`)
  addpiaoVisible.value = false
  loadJoins()
}

// ============ 行操作：投票记录 ============
// 无论内嵌/菜单直达，一律在选手列表主区域内 v-if 平铺打开（按选手 joinid 过滤），
// 右上 X 返回选手列表，绝不跳「投票记录」菜单页。
function goRecord(row: VoteJoinItem) {
  recordOverlay.value = { joinid: row.id, hid: row.hid }
}

function applyNavState() {
  // 内嵌 props 优先
  if (props.lockedHid && props.lockedHid > 0) {
    joinQuery.hid = props.lockedHid
  } else if (voteNavState.joinHid > 0) {
    joinQuery.hid = voteNavState.joinHid
  }
}

onMounted(async () => {
  await loadActivities()
  applyNavState()
  loadJoins()
})
onActivated(() => {
  applyNavState()
  if (view.value === 'list') loadJoins()
})
</script>

<template>
  <!-- ==================== 内嵌：选手行「投票记录」平铺层 ==================== -->
  <div v-if="recordOverlay" class="vote-join">
    <VoteRecord :locked-hid="recordOverlay.hid" :locked-joinid="recordOverlay.joinid" embedded @close="recordOverlay = null" />
  </div>

  <!-- ==================== 列表视图 ==================== -->
  <div v-else-if="view === 'list'" class="vote-join">
    <div class="page-head">
      <span class="page-title">选手列表</span>
      <span v-if="nestedMode || props.embedded" class="page-close" title="关闭" @click="closeNested">&times;</span>
    </div>

    <div class="toolbar">
      <el-button type="success" @click="openAdd">添加</el-button>
      <el-button @click="batchDelete">删除</el-button>
      <el-button @click="batchPass">通过</el-button>
      <el-button @click="batchReject">驳回</el-button>
      <el-button @click="exportCsv">导出</el-button>
      <el-divider direction="vertical" />
      <el-select v-if="!(props.embedded && props.lockedHid)" v-model="joinQuery.hid" placeholder="活动" class="w-hid" @change="handleSearch">
        <el-option label="全部" :value="0" />
        <el-option v-for="a in activityOptions" :key="a.id" :label="a.name" :value="a.id" />
      </el-select>
      <el-select v-model="joinQuery.status" placeholder="状态" class="w-status" @change="handleSearch">
        <el-option label="全部" :value="''" />
        <el-option label="待审核" :value="0" />
        <el-option label="已通过" :value="1" />
        <el-option label="已驳回" :value="2" />
      </el-select>
      <el-button type="primary" @click="handleSearch">搜索</el-button>
    </div>

    <el-table :data="joinData" v-loading="joinLoading" border @selection-change="handleSelectionChange">
      <el-table-column type="selection" width="40" />
      <el-table-column prop="id" label="ID" width="64" sortable />
      <el-table-column label="所属活动" min-width="130">
        <template #default="{ row }">{{ row.hdname || activityName(row.hid) }}</template>
      </el-table-column>
      <el-table-column prop="name" label="选手名称" min-width="110" />
      <el-table-column prop="number" label="选手编号" width="90" align="center" />
      <el-table-column prop="weixin" label="联系方式" width="110" />
      <el-table-column label="选手主图" width="80">
        <template #default="{ row }">
          <el-image v-if="row.pic" :src="row.pic" class="thumb" fit="cover" />
        </template>
      </el-table-column>
      <el-table-column prop="helpnum" label="累计票数" width="90" align="center" sortable />
      <el-table-column prop="readcount" label="访问次数" width="90" align="center" sortable />
      <el-table-column prop="mingci" label="排名" width="70" align="center" sortable />
      <el-table-column label="参与时间" width="150">
        <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="statusType(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="110">
        <template #default="{ row }">
          <div class="op-col">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleRowDelete(row)">删除</el-button>
            <el-button link type="primary" size="small" @click="goRecord(row)">投票记录</el-button>
            <el-button link type="primary" size="small" @click="openAddpiao(row)">加票</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="joinQuery.page"
        v-model:page-size="joinQuery.pagesize"
        :total="joinTotal"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        @current-change="loadJoins"
        @size-change="handleSearch"
      />
    </div>

    <!-- 加票小弹窗 -->
    <el-dialog v-model="addpiaoVisible" title="加票" width="360px" align-center>
      <div class="addpiao-row">
        <span class="addpiao-label">增加票数：</span>
        <el-input-number v-model="addpiaoNum" :min="1" :precision="0" />
      </div>
      <template #footer>
        <el-button type="primary" @click="confirmAddpiao">确定</el-button>
        <el-button @click="addpiaoVisible = false">取消</el-button>
      </template>
    </el-dialog>
  </div>

  <!-- ==================== 添加/编辑选手 平铺表单 ==================== -->
  <div v-else class="vote-form">
    <div class="form-head">
      <span class="form-head-title">+ {{ joinForm.id ? '编辑选手' : '添加选手' }}</span>
      <span class="form-close" title="关闭" @click="closeForm">&times;</span>
    </div>

    <el-form :model="joinForm" label-width="100px" class="form-body">
      <el-form-item label="所属活动" required>
        <el-select v-model="joinForm.hid" placeholder="请选择" :disabled="!!joinForm.id" class="w-input">
          <el-option v-for="a in activityOptions" :key="a.id" :label="a.name" :value="a.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="选手名称" required>
        <el-input v-model="joinForm.name" class="w-input" />
      </el-form-item>
      <el-form-item label="选手主图">
        <el-upload
          action="/admin/upload" name="file" :headers="uploadHeaders" list-type="picture-card"
          :file-list="picFileList" :limit="1" accept="image/*"
          :on-success="(res: unknown) => { const u = parseUploadResponse(res as { code?: number; msg?: string; data?: { url?: string } }); if (u) joinForm.pic = u }"
          :on-remove="() => { joinForm.pic = '' }" :on-error="onUploadError"
        >
          <span class="upload-plus">+</span>
        </el-upload>
        <span class="tip">建议尺寸：640x640像素</span>
      </el-form-item>
      <el-form-item label="详情图片">
        <el-upload
          action="/admin/upload" name="file" :headers="uploadHeaders" list-type="picture-card" multiple accept="image/*"
          :file-list="picsFileList"
          :on-success="(res: unknown) => { const u = parseUploadResponse(res as { code?: number; msg?: string; data?: { url?: string } }); if (u) joinForm.pics = joinForm.pics ? joinForm.pics + ',' + u : u }"
          :on-error="onUploadError"
        >
          <span class="upload-plus">+</span>
        </el-upload>
        <span class="tip">批量上传</span>
      </el-form-item>
      <el-form-item label="详情文字">
        <el-input v-model="joinForm.detail_txt" type="textarea" :rows="3" class="w-input" />
      </el-form-item>
      <el-form-item label="详情">
        <SimpleRichEditor v-model="joinForm.detail" class="w-editor" />
      </el-form-item>
      <el-form-item label="状态">
        <el-radio-group v-model="joinForm.status">
          <el-radio :value="0">待审核</el-radio>
          <el-radio :value="1">已通过</el-radio>
          <el-radio :value="2">已驳回</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="joinForm.status === 2" label="驳回原因">
        <el-input v-model="joinForm.reason" class="w-input" placeholder="请填写驳回原因" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="joinSaving" @click="handleSave">提交</el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.vote-join,
.vote-form {
  padding: 3px;
}
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 2px 2px 8px;
}
.page-title { font-size: 15px; font-weight: 600; color: #303133; }
.page-close { font-size: 24px; color: #909399; cursor: pointer; }
.page-close:hover { color: #f56c6c; }
.toolbar {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 3px;
  flex-wrap: nowrap;
}
.w-hid { width: 160px; }
.w-status { width: 120px; }
.pager { display: flex; justify-content: flex-end; margin-top: 3px; }
.thumb { width: 46px; height: 32px; border-radius: 4px; }
.addpiao-row { display: flex; align-items: center; padding: 12px 0; }
.addpiao-label { font-size: 14px; color: #303133; }
.form-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 4px 10px;
}
.form-head-title { font-size: 16px; font-weight: 600; }
.form-close { font-size: 24px; color: #909399; cursor: pointer; }
.form-close:hover { color: #f56c6c; }
.form-body { max-width: 860px; }
.w-input { width: 320px; }
.w-editor { width: 100%; max-width: 640px; }
.tip { margin-left: 10px; color: #909399; font-size: 12px; }
.upload-plus { font-size: 22px; color: #999; }
</style>
