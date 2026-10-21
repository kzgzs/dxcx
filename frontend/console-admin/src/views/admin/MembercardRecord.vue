<script setup lang="ts">
import { inject, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  mcList,
  mcRecordDel,
  mcRecordDetail,
  mcRecordExport,
  mcRecordList,
  mcSendScore,
  type MembercardRecordRow,
  type MembercardRow,
  type RecordListQuery,
} from '../../api/membercard'

const loading = ref(false)
const exporting = ref(false)
const list = ref<MembercardRecordRow[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(10)

const cards = ref<MembercardRow[]>([])

const filters = reactive({
  cardId: '' as number | '',
  card_code: '',
  name: '',
  mobile: '',
  range: [] as string[],
})

// 送积分弹窗
const scoreVisible = ref(false)
const sending = ref(false)
const scoreForm = reactive({ cardId: '' as number | '', score: 10, remark: '' })
const scoreProgress = ref('')

// 批量选择
const selected = ref<MembercardRecordRow[]>([])
function handleSelectionChange(rows: MembercardRecordRow[]) {
  selected.value = rows
}
async function handleBatchDelete() {
  if (!selected.value.length) {
    ElMessage.warning('请先选择要删除的记录')
    return
  }
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${selected.value.length} 条领取记录吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await mcRecordDel(selected.value.map((r) => r.id))
    ElMessage.success('删除成功')
    loadData()
  } catch {
    // 拦截器已提示
  }
}

// 查看详情弹窗
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<MembercardRecordRow | null>(null)
async function handleView(row: MembercardRecordRow) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = row
  try {
    detail.value = await mcRecordDetail(row.id)
  } catch {
    // 保留行数据
  } finally {
    detailLoading.value = false
  }
}

function statusText(status: number): string {
  if (status === 1 || status === 0) return '正常'
  if (status === 2) return '已注销'
  if (status === 3) return '已过期'
  return '正常'
}

// 跳转会员卡列表
const navigateByKey = inject<(key: string) => boolean>('adminNavigateByKey', () => false)
function goMembercard() {
  if (!navigateByKey('membercard-index')) {
    ElMessage.info('请从左侧菜单「微信会员卡-会员卡/创建」查看')
  }
}

async function handleDeleteDetail() {
  if (!detail.value) return
  const row = detail.value
  try {
    await ElMessageBox.confirm('确定删除该领取记录吗？', '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await mcRecordDel([row.id])
    ElMessage.success('删除成功')
    detailVisible.value = false
    loadData()
  } catch {
    // 拦截器已提示
  }
}

function selectedCardIdValue(): string {
  const card = cards.value.find((c) => c.id === filters.cardId)
  return card ? card.card_id : ''
}

function buildQuery(): RecordListQuery {
  return {
    page: page.value,
    pagesize: pagesize.value,
    card_id: selectedCardIdValue(),
    card_code: filters.card_code.trim(),
    name: filters.name.trim(),
    mobile: filters.mobile.trim(),
    starttime: filters.range?.[0] || '',
    endtime: filters.range?.[1] || '',
  }
}

async function loadData() {
  loading.value = true
  try {
    const result = await mcRecordList(buildQuery())
    list.value = result.list || []
    total.value = result.total || 0
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

async function loadCards() {
  try {
    const result = await mcList({ page: 1, pagesize: 100 })
    cards.value = result.list || []
  } catch {
    cards.value = []
  }
}

function handleSearch() {
  page.value = 1
  loadData()
}

function handleReset() {
  filters.cardId = ''
  filters.card_code = ''
  filters.name = ''
  filters.mobile = ''
  filters.range = []
  handleSearch()
}

function formatTime(ts: number) {
  if (!ts) return '—'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

// 展开行可见的开卡表单字段
function formFields(row: MembercardRecordRow): { label: string; value: string }[] {
  const fields = [
    { label: '姓名', value: row.name },
    { label: '手机号', value: row.mobile },
    { label: '性别', value: row.sex },
    { label: '生日', value: row.birthday },
    { label: '邮箱', value: row.email },
    { label: '身份证', value: row.idcard },
    { label: '学历', value: row.education },
    { label: '行业', value: row.industry },
    { label: '年收入', value: row.income },
    { label: '爱好', value: row.habit },
    { label: '地址', value: row.location },
    { label: '自定义1', value: row.field1 },
    { label: '自定义2', value: row.field2 },
    { label: '自定义3', value: row.field3 },
    { label: '自定义4', value: row.field4 },
    { label: '自定义5', value: row.field5 },
  ]
  return fields.filter((f) => f.value)
}

async function handleExport() {
  exporting.value = true
  try {
    await mcRecordExport({ ...buildQuery(), page: undefined, pagesize: undefined })
  } catch {
    ElMessage.error('导出失败')
  } finally {
    exporting.value = false
  }
}

async function handleDelete(row: MembercardRecordRow) {
  try {
    await ElMessageBox.confirm(`确定删除该领取记录吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await mcRecordDel([row.id])
    ElMessage.success('删除成功')
    loadData()
  } catch {
    // 拦截器已提示
  }
}

// ============ 送积分（分页幂等） ============
function openSendScore(row?: MembercardRecordRow) {
  scoreForm.cardId = row ? (cards.value.find((c) => c.card_id === row.card_id)?.id ?? '') : filters.cardId
  scoreForm.score = 10
  scoreForm.remark = ''
  scoreProgress.value = ''
  scoreVisible.value = true
}

async function handleSendScore() {
  if (!scoreForm.cardId) {
    ElMessage.warning('请选择会员卡')
    return
  }
  if (!(Number(scoreForm.score) > 0)) {
    ElMessage.warning('请填写赠送积分数量')
    return
  }
  sending.value = true
  try {
    let logid = 0
    let pagenum = 1
    const pagelimit = 50
    // 分页驱动：status=2 还有下一页，携带 logid 续传（幂等归账）
    for (;;) {
      const rs = await mcSendScore({
        id: Number(scoreForm.cardId),
        score: Number(scoreForm.score),
        remark: scoreForm.remark.trim(),
        logid,
        pagenum,
        pagelimit,
      })
      logid = rs.logid
      scoreProgress.value = `已发送 ${rs.sendcount} 人，成功 ${rs.successcount}，失败 ${rs.errorcount}`
      if (rs.status === 1) break
      pagenum += 1
    }
    ElMessage.success('送积分完成：' + scoreProgress.value)
    scoreVisible.value = false
  } catch {
    // 拦截器已提示
  } finally {
    sending.value = false
  }
}

onMounted(async () => {
  // 来自会员卡列表「领取记录」的按卡过滤
  const presetPk = sessionStorage.getItem('mc_record_card_pk')
  if (presetPk) {
    sessionStorage.removeItem('mc_record_card_pk')
    filters.cardId = Number(presetPk)
  }
  await loadCards()
  loadData()
})
</script>

<template>
  <div class="membercard-record-page">
    <div class="page-card">
      <div class="card-title">会员卡领取记录</div>

      <div class="filter-bar">
        <el-select v-model="filters.cardId" placeholder="会员卡" clearable style="width: 160px">
          <el-option v-for="c in cards" :key="c.id" :label="c.title" :value="c.id" />
        </el-select>
        <el-input v-model="filters.card_code" placeholder="卡号" clearable style="width: 150px" @keyup.enter="handleSearch" />
        <el-input v-model="filters.name" placeholder="姓名" clearable style="width: 120px" @keyup.enter="handleSearch" />
        <el-input v-model="filters.mobile" placeholder="手机号" clearable style="width: 140px" @keyup.enter="handleSearch" />
        <el-date-picker
          v-model="filters.range"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="领取时间"
          end-placeholder="结束日期"
          style="width: 240px"
        />
        <el-button type="primary" @click="handleSearch">查询</el-button>
        <el-button @click="handleReset">重置</el-button>
      </div>
      <div class="filter-bar second-row">
        <el-button @click="goMembercard">查看会员卡</el-button>
        <el-button :loading="exporting" @click="handleExport">导出</el-button>
        <el-button type="success" @click="openSendScore()">送积分</el-button>
        <el-button type="danger" plain :disabled="!selected.length" @click="handleBatchDelete">批量删除</el-button>
      </div>

      <el-table :data="list" v-loading="loading" border stripe row-key="id" style="width: 100%; margin-top: 6px" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="头像昵称" min-width="160">
          <template #default="{ row }">
            <div class="user-cell">
              <el-avatar :src="row.headimg" :size="30" class="user-avatar">
                {{ (row.nickname || '会').charAt(0) }}
              </el-avatar>
              <span>{{ row.nickname || '用户' + row.mid }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="card_code" label="卡号" min-width="170" show-overflow-tooltip />
        <el-table-column prop="name" label="姓名" width="100">
          <template #default="{ row }">{{ row.name || '—' }}</template>
        </el-table-column>
        <el-table-column prop="mobile" label="手机号" width="130">
          <template #default="{ row }">{{ row.mobile || '—' }}</template>
        </el-table-column>
        <el-table-column label="领取时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="row.status === 1 ? 'success' : 'info'">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleView(row)">查看</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pagesize"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="handleSearch"
          @current-change="loadData"
        />
      </div>
    </div>

    <!-- 送积分弹窗 -->
    <el-dialog v-model="scoreVisible" title="会员卡送积分" width="440px" :close-on-click-modal="false">
      <el-form label-width="90px">
        <el-form-item label="会员卡">
          <el-select v-model="scoreForm.cardId" placeholder="选择会员卡" style="width: 240px">
            <el-option v-for="c in cards" :key="c.id" :label="c.title" :value="c.id" :disabled="!c.card_id" />
          </el-select>
        </el-form-item>
        <el-form-item label="赠送积分">
          <el-input-number v-model="scoreForm.score" :min="1" :max="100000" />
          <span class="form-tip">该卡全部领取人每人赠送该数量积分</span>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="scoreForm.remark" placeholder="选填，展示在积分记录" style="width: 240px" />
        </el-form-item>
      </el-form>
      <div v-if="scoreProgress" class="score-progress">{{ scoreProgress }}</div>
      <template #footer>
        <el-button @click="scoreVisible = false">取消</el-button>
        <el-button type="primary" :loading="sending" @click="handleSendScore">开始赠送</el-button>
      </template>
    </el-dialog>

    <!-- 查看详情弹窗 -->
    <el-dialog v-model="detailVisible" title="领取记录详情" width="520px">
      <div v-loading="detailLoading" class="detail-body" v-if="detail">
        <div class="detail-head">
          <el-avatar :src="detail.headimg" :size="44">{{ (detail.nickname || '会').charAt(0) }}</el-avatar>
          <div>
            <div class="detail-name">{{ detail.nickname || '用户' + detail.mid }}</div>
            <div class="detail-sub">卡号：{{ detail.card_code }}</div>
          </div>
        </div>
        <el-descriptions :column="1" border size="small">
          <el-descriptions-item label="会员卡">{{ detail.card_title || detail.card_id }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ statusText(detail.status) }}</el-descriptions-item>
          <el-descriptions-item label="领取时间">{{ formatTime(detail.createtime) }}</el-descriptions-item>
          <template v-for="f in formFields(detail)" :key="f.label">
            <el-descriptions-item :label="f.label">{{ f.value }}</el-descriptions-item>
          </template>
        </el-descriptions>
      </div>
      <template #footer>
        <el-button
          type="success"
          @click="detail && openSendScore(detail); detailVisible = false"
        >送积分</el-button>
        <el-button type="danger" @click="handleDeleteDetail">删除</el-button>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.membercard-record-page {
  padding: 12px;
}
.page-card {
  background: #fff;
  border-radius: 4px;
  padding: 16px 20px;
}
.card-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #ebeef5;
}
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  align-items: center;
}
.filter-bar.second-row {
  margin-top: 3px;
}
.user-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
.user-avatar {
  flex-shrink: 0;
}
.detail-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}
.detail-name {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.detail-sub {
  font-size: 12px;
  color: #909399;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.expand-box {
  padding: 8px 24px;
}
.expand-title {
  font-weight: 600;
  color: #303133;
  margin-bottom: 8px;
}
.expand-item {
  display: inline-flex;
  gap: 6px;
  margin: 0 24px 6px 0;
  font-size: 13px;
  color: #606266;
}
.expand-label {
  color: #909399;
}
.expand-empty {
  color: #909399;
  font-size: 13px;
}
.form-tip {
  font-size: 12px;
  color: #909399;
  margin-left: 8px;
}
.score-progress {
  font-size: 13px;
  color: #67c23a;
  padding: 0 20px;
}
</style>
