<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import { auditLevelup, deleteLevelup, getLevelupList } from '../../api/memberGroup'
import { assetUrl } from '../../api/upload'
import type { LevelupListQuery, LevelupOrderItem } from '../../api/memberGroup'

// ===== 过滤条件（对齐点大：会员ID/昵称/姓名/申请资料/申请时间/类型） =====
const filters = reactive({
  mid: '' as number | '',
  nickname: '',
  realname: '',
  type: 'all',
  timerange: [] as string[],
  formx: '',
})

const page = ref(1)
const pageSize = ref(10)
const total = ref(0)
const list = ref<LevelupOrderItem[]>([])
const loading = ref(false)
const selected = ref<LevelupOrderItem[]>([])

function fmtTime(ts: number): string {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(
    d.getMinutes(),
  )}:${p(d.getSeconds())}`
}

async function load() {
  loading.value = true
  try {
    const params: LevelupListQuery = {
      page: page.value,
      pagesize: pageSize.value,
      mid: filters.mid,
      nickname: filters.nickname,
      realname: filters.realname,
      type: filters.type,
      formx: filters.formx,
      start_time: filters.timerange?.[0] || '',
      end_time: filters.timerange?.[1] || '',
    }
    const res = await getLevelupList(params)
    list.value = res.list
    total.value = res.total
  } catch {
    // 错误提示已由请求拦截器统一处理
  } finally {
    loading.value = false
  }
}

function onSearch() {
  page.value = 1
  load()
}

function onSelectionChange(rows: LevelupOrderItem[]) {
  selected.value = rows
}

// ===== 申请资料列：有表单渲染表单，空则按来源兜底“类型” =====
interface FormLine {
  label: string
  value: string
  type: string
}
function formLines(row: LevelupOrderItem): FormLine[] {
  if (row.formdata && row.formdata.length) {
    return row.formdata.map((f) => ({ label: f.title || '资料', value: f.value || '', type: f.type || '' }))
  }
  let kind = '自动升级'
  if (row.platform === 'admin' || (row.title && row.title.includes('后台调整'))) {
    kind = '后台调整'
  } else if (Number(row.totalprice) > 0 || row.paytime > 0 || row.ordernum) {
    kind = '付费申请'
  }
  return [{ label: '类型', value: kind, type: 'text' }]
}

// 状态颜色：0未支付(默认) 1待审核(红) 2已通过(绿) 3已驳回(灰)
function statusClass(v: number): string {
  if (v === 1) return 'txt-red'
  if (v === 2) return 'txt-green'
  if (v === 3) return 'txt-gray'
  return 'txt-normal'
}

// ===== 审核（仅待审核 status=1） =====
async function doAudit(row: LevelupOrderItem, st: 2 | 3) {
  const pass = st === 2
  let remark = ''
  try {
    const ret = await ElMessageBox.prompt(
      pass
        ? `通过「${row.nickname}」的${row.type_text}申请（${row.before_level || '-'} → ${row.apply_level || '-'}）？通过后将变更会员等级并发放升级奖励。`
        : `驳回「${row.nickname}」的${row.type_text}申请？${Number(row.totalprice) > 0 && row.paytime > 0 ? '已付款金额将按原支付方式退回。' : ''}`,
      pass ? '审核通过' : '驳回申请',
      {
        confirmButtonText: pass ? '确认通过' : '确认驳回',
        cancelButtonText: '取消',
        inputPlaceholder: '审核备注（选填）',
        inputValue: '',
        type: pass ? 'success' : 'warning',
      },
    )
    remark = String(ret.value || '')
  } catch {
    return
  }

  try {
    await auditLevelup({ id: row.id, st, remark })
    ElMessage.success(pass ? '已通过审核' : '已驳回申请')
    load()
  } catch {
    // 拦截器已提示
  }
}

// ===== 删除（单条 / 批量，物理删除，谨慎） =====
function doDelete(row: LevelupOrderItem) {
  ElMessageBox.confirm(
    `确定删除「${row.nickname || `会员${row.mid}`}」的升级记录吗？升级记录影响分红计算，删除请谨慎。`,
    '删除确认',
    { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        const res = await deleteLevelup([row.id])
        ElMessage.success(`已删除 ${res.count} 条`)
        load()
      } catch {
        // 拦截器已提示
      }
    })
    .catch(() => {})
}

function batchDelete() {
  if (!selected.value.length) {
    ElMessage.warning('请先勾选要删除的记录')
    return
  }
  const ids = selected.value.map((r) => r.id)
  ElMessageBox.confirm(`确定删除勾选的 ${ids.length} 条升级记录吗？删除后不可恢复，请谨慎操作。`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        const res = await deleteLevelup(ids)
        ElMessage.success(`已删除 ${res.count} 条`)
        load()
      } catch {
        // 拦截器已提示
      }
    })
    .catch(() => {})
}

onMounted(load)
</script>

<template>
  <el-card shadow="never">
    <el-alert
      type="warning"
      :closable="false"
      show-icon
      title="升级记录影响分红计算，删除请谨慎"
      style="margin-bottom: 8px"
    />

    <!-- 删除 + 搜索项：同一行 -->
    <div class="toolbar">
      <el-button @click="batchDelete">删除</el-button>
      <div class="search-row">
        <el-input
          v-model="filters.mid"
          class="filter-item w90"
          placeholder="会员ID"
          clearable
          type="number"
        />
        <el-input v-model="filters.nickname" class="filter-item w110" placeholder="昵称" clearable />
        <el-input v-model="filters.realname" class="filter-item w100" placeholder="姓名" clearable />
        <el-input v-model="filters.formx" class="filter-item w130" placeholder="申请资料" clearable />
        <el-date-picker
          v-model="filters.timerange"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="申请开始"
          end-placeholder="申请结束"
          style="width: 200px"
        />
        <el-select v-model="filters.type" style="width: 96px">
          <el-option label="全部" value="all" />
          <el-option label="升级" value="0" />
          <el-option label="降级" value="1" />
        </el-select>
        <el-button type="success" circle @click="onSearch">
          <el-icon><Search /></el-icon>
        </el-button>
      </div>
    </div>

    <el-table
      v-loading="loading"
      :data="list"
      border
      stripe
      size="small"
      style="width: 100%"
      @selection-change="onSelectionChange"
    >
      <el-table-column type="selection" width="40" />
      <el-table-column prop="id" label="ID" width="70" sortable />
      <el-table-column label="头像昵称" min-width="170">
        <template #default="{ row }">
          <div class="member-cell">
            <el-avatar :size="40" :src="row.headimg ? assetUrl(row.headimg) : undefined">
              {{ (row.nickname || '会').slice(0, 1) }}
            </el-avatar>
            <span>{{ row.nickname || `会员${row.mid}` }}(ID:{{ row.mid }})</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="推荐人" min-width="150">
        <template #default="{ row }">
          <div v-if="row.pid > 0" class="member-cell">
            <el-avatar :size="30" :src="row.pheadimg ? assetUrl(row.pheadimg) : undefined">
              {{ (row.pnickname || '会').slice(0, 1) }}
            </el-avatar>
            <span>{{ row.pnickname || `会员${row.pid}` }}(ID:{{ row.pid }})</span>
          </div>
          <span v-else class="txt-gray">-</span>
        </template>
      </el-table-column>
      <el-table-column prop="before_level" label="当前等级" width="100" show-overflow-tooltip />
      <el-table-column prop="apply_level" label="申请等级" width="100" show-overflow-tooltip />
      <el-table-column label="申请资料" min-width="260">
        <template #default="{ row }">
          <div class="form-lines">
            <div v-for="(ln, i) in formLines(row)" :key="i" class="form-line">
              <span class="fl-label">{{ ln.label }}：</span>
              <el-image
                v-if="ln.type === 'upload' && ln.value"
                :src="assetUrl(ln.value)"
                :preview-src-list="[assetUrl(ln.value)]"
                class="fl-img"
                fit="cover"
              >
                <template #error><span class="fl-img-fail">无图</span></template>
              </el-image>
              <span v-else>{{ ln.value }}</span>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="金额" width="90" align="right">
        <template #default="{ row }">{{ Number(row.totalprice || 0).toFixed(2) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <span :class="statusClass(row.status)">{{ row.status_text }}</span>
        </template>
      </el-table-column>
      <el-table-column label="类型" width="80">
        <template #default="{ row }">
          <span :class="row.type === 1 ? 'txt-red' : 'txt-green'">{{ row.type_text }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="createtime" label="申请时间" width="160" sortable>
        <template #default="{ row }">{{ fmtTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="160">
        <template #default="{ row }">
          <div class="op-wrap">
            <template v-if="row.status === 1">
              <el-button link type="success" size="small" @click="doAudit(row, 2)">通过</el-button>
              <el-button link type="danger" size="small" @click="doAudit(row, 3)">驳回</el-button>
            </template>
            <el-button link type="danger" size="small" @click="doDelete(row)">删除</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="page"
        v-model:page-size="pageSize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        @current-change="load"
        @size-change="onSearch"
      />
    </div>
  </el-card>
</template>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 3px;
  margin-bottom: 8px;
}
.search-row {
  margin-left: auto;
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 3px;
}
.filter-item {
  flex: none;
}
.w90 {
  width: 90px;
}
.w100 {
  width: 100px;
}
.w110 {
  width: 110px;
}
.w130 {
  width: 130px;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}

.form-lines {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.form-line {
  font-size: 12px;
  line-height: 1.5;
  word-break: break-all;
}
.fl-label {
  color: #909399;
}
.fl-img-fail {
  font-size: 10px;
  color: #c0c4cc;
}
.fl-img {
  width: 60px;
  height: 60px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  vertical-align: top;
}

.op-wrap {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 2px;
  white-space: nowrap;
}

.txt-red {
  color: #f56c6c;
}
.txt-green {
  color: #67c23a;
}
.txt-gray {
  color: #909399;
}
.txt-normal {
  color: #606266;
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
}
</style>
