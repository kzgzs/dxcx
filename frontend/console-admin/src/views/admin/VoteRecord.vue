<script setup lang="ts">
/**
 * 扩展·投票活动·投票记录
 * - 可从菜单直达，也可从活动列表/选手行嵌套进入（嵌套时右上 X 返回）
 * - 列对齐截图：所属活动/选手名称/选手编号/投票人(头像+昵称)/投票时间/操作(删除)
 */
import { computed, inject, onActivated, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteVoteHelp, getVoteHelpList, getVoteJoinList, getVoteList } from '../../api/vote'
import type { VoteActivityItem, VoteHelpItem, VoteJoinItem } from '../../api/vote'
import { voteNavState } from '../../api/vote'
import { formatTime } from '../../utils/time'

const props = defineProps<{
  /** 内嵌模式：锁定活动 id（不跳菜单页） */
  lockedHid?: number
  /** 内嵌模式：锁定选手 id */
  lockedJoinid?: number
  /** 内嵌模式（在活动/选手主区域平铺打开），X 触发 close 事件 */
  embedded?: boolean
}>()
const emit = defineEmits<{ (e: 'close'): void }>()

const navigateByKey = inject<(key: string) => boolean>('adminNavigateByKey', () => false)

const loading = ref(false)
const list = ref<VoteHelpItem[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])
const query = reactive({
  page: 1,
  pagesize: 10,
  hid: 0,
  joinid: 0,
  ctime: '' as string | string[],
})

const activityOptions = ref<VoteActivityItem[]>([])
const joinOptions = ref<VoteJoinItem[]>([])

async function loadActivities() {
  const res = await getVoteList({ page: 1, pagesize: 100 })
  activityOptions.value = res.list || []
}
async function loadJoinOptions() {
  joinOptions.value = []
  if (query.hid > 0) {
    const res = await getVoteJoinList({ hid: query.hid, page: 1, pagesize: 200 })
    joinOptions.value = res.list || []
  }
}

async function load() {
  loading.value = true
  try {
    const ctime = Array.isArray(query.ctime) ? query.ctime.join(' ~ ') : query.ctime
    const res = await getVoteHelpList({
      page: query.page,
      pagesize: query.pagesize,
      hid: query.hid || undefined,
      joinid: query.joinid || undefined,
      ctime: ctime || undefined,
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
async function onHidChange() {
  query.joinid = 0
  await loadJoinOptions()
  handleSearch()
}
function handleSelectionChange(rows: VoteHelpItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

// 投票人：头像 + 昵称；昵称为空时展示脱敏手机号
function voterName(row: VoteHelpItem): string {
  if (row.nickname) return row.nickname
  if (row.tel && row.tel.length >= 7) return row.tel.slice(0, 3) + '****' + row.tel.slice(-4)
  return '会员' + row.mid
}

// ============ 删除 / 导出 ============
async function doDelete(ids: number[]) {
  await deleteVoteHelp(ids)
  ElMessage.success('删除成功')
  selectedIds.value = []
  load()
}
async function handleRowDelete(row: VoteHelpItem) {
  await ElMessageBox.confirm('确定删除该条投票记录？', '提示', { type: 'warning' })
  await doDelete([row.id])
}
async function batchDelete() {
  if (!selectedIds.value.length) { ElMessage.warning('请先勾选记录'); return }
  await ElMessageBox.confirm(`确定删除所选 ${selectedIds.value.length} 条记录？`, '提示', { type: 'warning' })
  await doDelete(selectedIds.value)
}
function exportCsv() {
  const header = ['ID', '所属活动', '选手名称', '选手编号', '投票人', '投票时间']
  const rows = list.value.map((r) => [r.id, r.hdname, r.joinname, r.number, voterName(r), formatTime(r.createtime)])
  const csv = [header, ...rows].map((line) => line.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = '投票记录.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}

// 嵌套返回
const nestedMode = computed(() => !!voteNavState.returnTo)
function closeNested() {
  if (props.embedded) {
    emit('close')
    return
  }
  const back = voteNavState.returnTo
  voteNavState.returnTo = ''
  voteNavState.recordHid = 0
  voteNavState.recordJoinid = 0
  if (back) navigateByKey(back)
}

function applyNavState() {
  // 内嵌 props 优先（在主区域平铺，按传入 hid/joinid 过滤）
  if (props.lockedHid && props.lockedHid > 0) query.hid = props.lockedHid
  else if (voteNavState.recordHid > 0) query.hid = voteNavState.recordHid
  if (props.lockedJoinid && props.lockedJoinid > 0) query.joinid = props.lockedJoinid
  else if (voteNavState.recordJoinid > 0) query.joinid = voteNavState.recordJoinid
}

onMounted(async () => {
  await loadActivities()
  applyNavState()
  await loadJoinOptions()
  load()
})
onActivated(() => {
  applyNavState()
  load()
})
</script>

<template>
  <div class="vote-record">
    <div class="page-head">
      <span class="page-title">投票记录</span>
      <span v-if="nestedMode || props.embedded" class="page-close" title="关闭" @click="closeNested">&times;</span>
    </div>

    <div class="toolbar">
      <el-button @click="exportCsv">导出</el-button>
      <el-button @click="batchDelete">删除</el-button>
      <el-divider direction="vertical" />
      <el-select v-if="!(props.embedded && props.lockedJoinid)" v-model="query.hid" placeholder="活动" class="w-hid" @change="onHidChange">
        <el-option label="全部" :value="0" />
        <el-option v-for="a in activityOptions" :key="a.id" :label="a.name" :value="a.id" />
      </el-select>
      <el-select v-model="query.joinid" placeholder="选手" class="w-join" @change="handleSearch">
        <el-option label="全部" :value="0" />
        <el-option v-for="j in joinOptions" :key="j.id" :label="`${j.number || ''} ${j.name}`" :value="j.id" />
      </el-select>
      <el-date-picker
        v-model="query.ctime" type="daterange" range-separator="~"
        start-placeholder="投票时间" end-placeholder="结束" class="w-time"
        value-format="YYYY-MM-DD" @change="handleSearch"
      />
      <el-button type="primary" @click="handleSearch">搜索</el-button>
    </div>

    <el-table :data="list" v-loading="loading" border @selection-change="handleSelectionChange">
      <el-table-column type="selection" width="40" />
      <el-table-column prop="id" label="ID" width="80" sortable />
      <el-table-column prop="hdname" label="所属活动" min-width="160" />
      <el-table-column prop="joinname" label="选手名称" min-width="120" />
      <el-table-column prop="number" label="选手编号" width="100" align="center" />
      <el-table-column label="投票人" min-width="150">
        <template #default="{ row }">
          <div class="voter">
            <el-avatar v-if="row.headimg" :src="row.headimg" :size="26" />
            <span>{{ voterName(row) }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="投票时间" width="170" sortable>
        <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="90">
        <template #default="{ row }">
          <el-button link type="danger" size="small" @click="handleRowDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        @current-change="load"
        @size-change="handleSearch"
      />
    </div>
  </div>
</template>

<style scoped>
.vote-record { padding: 3px; }
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
.w-join { width: 160px; }
.w-time { width: 260px; }
.pager { display: flex; justify-content: flex-end; margin-top: 3px; }
.voter { display: flex; align-items: center; gap: 6px; }
</style>
