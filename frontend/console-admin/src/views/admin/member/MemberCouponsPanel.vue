<template>
  <div class="cp-panel">
    <div class="ge-head">
      <span class="ge-title">优惠券 · 领取记录</span>
      <el-button class="ge-close" circle @click="emit('close')">
        <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
      </el-button>
    </div>

    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-line between">
        <div class="left-ops">
          <el-button @click="batchSetStatus(0)">改为未使用</el-button>
          <el-button @click="batchSetStatus(1)">改为已使用</el-button>
          <el-button type="danger" @click="batchDelete">删除</el-button>
          <el-button :loading="exporting" @click="handleExport">导出</el-button>
        </div>
        <div class="right-search">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="-"
            start-placeholder="使用开始"
            end-placeholder="使用结束"
            class="date-range"
          />
          <el-input v-model="query.remark" placeholder="备注" clearable class="w140" @keyup.enter="onSearch" />
          <el-button type="success" @click="onSearch">搜索</el-button>
        </div>
      </div>
    </el-card>

    <el-card shadow="never" class="table-card">
      <el-table
        :data="list"
        v-loading="loading"
        border
        size="small"
        class="data-table"
        @selection-change="onSelect"
      >
        <el-table-column type="selection" width="42" align="center" />
        <el-table-column prop="id" label="ID" width="80" sortable="custom" align="center" />
        <el-table-column label="所属商户" min-width="120">
          <template #default="{ row }">{{ row.business_name || '平台自营' }}</template>
        </el-table-column>
        <el-table-column prop="couponname" label="优惠券名称" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.couponname }}
            <span class="muted">（¥{{ fmtMoney(row.money) }}<template v-if="row.discount"> / {{ row.discount }}折</template>）</span>
          </template>
        </el-table-column>
        <el-table-column label="领取人" min-width="150">
          <template #default="{ row }">
            <div class="owner">
              <el-image :src="assetUrl(member.headimg)" class="owner-pic" fit="cover">
                <template #error><div class="img-fallback">无</div></template>
              </el-image>
              <span>{{ member.nickname || ('会员' + row.mid) }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="领取时间" width="155">
          <template #default="{ row }">{{ fmtTime(row.createtime || row.receive_time) }}</template>
        </el-table-column>
        <el-table-column label="到期时间" width="155">
          <template #default="{ row }">{{ fmtTime(row.endtime) }}</template>
        </el-table-column>
        <el-table-column label="总次数/已用" width="95" align="center">
          <template #default="{ row }">{{ row.limit_count }} / {{ row.used_count }}</template>
        </el-table-column>
        <el-table-column label="使用时间" width="155">
          <template #default="{ row }">{{ row.usetime ? fmtTime(row.usetime) : '--' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small" disable-transitions>
              {{ row.status === 1 ? '已使用' : '未使用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip />
        <el-table-column label="操作" width="90" align="center">
          <template #default="{ row }">
            <el-button type="danger" link size="small" @click="rowDelete(row.id)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :current-page="query.page"
          :page-size="query.pagesize"
          :page-sizes="[10, 20, 50]"
          @current-change="onPage"
          @size-change="onSize"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteMemberCoupons,
  exportMemberCoupons,
  getMemberCoupons,
  setMemberCouponStatus,
  type MemberCouponItem,
  type MemberCouponQuery,
  type MemberRow,
} from '../../../api/member'
import { assetUrl } from '../../../api/upload'

const props = defineProps<{ mid: number; member: Pick<MemberRow, 'headimg' | 'nickname'> }>()
const emit = defineEmits<{ close: [] }>()

const loading = ref(false)
const exporting = ref(false)
const list = ref<MemberCouponItem[]>([])
const total = ref(0)
const selected = ref<MemberCouponItem[]>([])
const dateRange = ref<[string, string] | null>(null)

const query = reactive<MemberCouponQuery>({
  mid: props.mid,
  page: 1,
  pagesize: 10,
  status: '',
  remark: '',
  start_time: '',
  end_time: '',
})

function fmtMoney(v: number): string {
  return Number(v || 0).toFixed(2)
}
function fmtTime(ts: number): string {
  if (!ts) return ''
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}
function downloadBlob(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = fileName
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

function buildParams(): MemberCouponQuery {
  return {
    ...query,
    start_time: dateRange.value?.[0] || '',
    end_time: dateRange.value?.[1] || '',
  }
}

async function load() {
  loading.value = true
  try {
    const res = await getMemberCoupons(buildParams())
    list.value = res.list
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function onSelect(rows: MemberCouponItem[]) {
  selected.value = rows
}
function onSearch() {
  query.page = 1
  load()
}
function onPage(p: number) {
  query.page = p
  load()
}
function onSize(s: number) {
  query.pagesize = s
  query.page = 1
  load()
}

async function batchSetStatus(status: number) {
  const ids = selected.value.map((r) => r.id)
  if (!ids.length) {
    ElMessage.warning('请先勾选记录')
    return
  }
  await setMemberCouponStatus({ mid: props.mid, ids, status })
  ElMessage.success('操作成功')
  load()
}
async function batchDelete() {
  const ids = selected.value.map((r) => r.id)
  if (!ids.length) {
    ElMessage.warning('请先勾选记录')
    return
  }
  await ElMessageBox.confirm(`确认删除选中的 ${ids.length} 条领取记录？`, '提示', { type: 'warning' })
  await deleteMemberCoupons({ mid: props.mid, ids })
  ElMessage.success('删除成功')
  load()
}
async function rowDelete(id: number) {
  await ElMessageBox.confirm('确认删除该领取记录？', '提示', { type: 'warning' })
  await deleteMemberCoupons({ mid: props.mid, ids: [id] })
  ElMessage.success('删除成功')
  load()
}
async function handleExport() {
  if (exporting.value) return
  exporting.value = true
  try {
    const blob = await exportMemberCoupons({ ...buildParams() } as Record<string, unknown>)
    downloadBlob(blob, `会员优惠券_${Date.now()}.xlsx`)
  } finally {
    exporting.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.cp-panel {
  background: #fff;
  border-radius: 6px;
  padding: 12px 12px 16px;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.ge-title {
  font-size: 15px;
  font-weight: 600;
}
.ge-close {
  border: none;
  background: transparent;
  color: #909399;
}
.ge-close:hover {
  color: #409eff;
  background: transparent;
}
.toolbar-card {
  margin-bottom: 6px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 8px 10px;
}
.toolbar-line {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 6px;
}
.toolbar-line.between {
  justify-content: space-between;
}
.left-ops,
.right-search {
  display: flex;
  align-items: center;
  gap: 6px;
}
.date-range { width: 240px; }
.w140 { width: 140px; }
.table-card :deep(.el-card__body) {
  padding: 8px;
}
.owner {
  display: flex;
  align-items: center;
  gap: 6px;
}
.owner-pic {
  width: 30px;
  height: 30px;
  border-radius: 50%;
}
.img-fallback {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: #f5f7fa;
  color: #c0c4cc;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.muted {
  color: #909399;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 8px;
}
</style>
