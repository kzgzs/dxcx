<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  delPeisongWithdrawLog,
  getPeisongWithdrawLogList,
  setstPeisongWithdrawLog,
} from '../../api/peisong'
import type { PeisongWithdrawLogItem } from '../../api/peisong'
import { formatTime } from '../../utils/time'

// 租户后台 - 同城配送·提现记录（批次8 任务223；对标参考系统 PeisongMoney::withdrawlog）
// 状态：0审核中 1已审核 2已驳回 3已打款；打款走 mock（.env PEISONG.MOCK=1）

const loading = ref(false)
const tableData = ref<PeisongWithdrawLogItem[]>([])
const total = ref(0)
const selection = ref<PeisongWithdrawLogItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  realname: '',
  uid: '',
  status: '',
})

const STATUS_TAG: Record<number, 'warning' | 'primary' | 'danger' | 'success'> = {
  0: 'warning',
  1: 'primary',
  2: 'danger',
  3: 'success',
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getPeisongWithdrawLogList({
      page: query.page,
      pagesize: query.pagesize,
      realname: query.realname || undefined,
      uid: query.uid || undefined,
      status: query.status === '' ? undefined : query.status,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // axios 拦截器已提示
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.realname = ''
  query.uid = ''
  query.status = ''
  query.page = 1
  fetchList()
}

/** 收款账号（按提现方式展示） */
function accountOf(row: PeisongWithdrawLogItem): string {
  if (row.paytype === '支付宝') return row.aliaccount || '-'
  if (row.paytype === '银行卡') return [row.bankname, row.bankcarduser, row.bankcardnum].filter(Boolean).join(' - ') || '-'
  if (row.paytype === '微信') return row.weixin || '-'
  return row.aliaccount || row.weixin || '-'
}

/** 审核通过（st=1） */
async function handleAuditPass(row: PeisongWithdrawLogItem) {
  await ElMessageBox.confirm(`确认通过「${row.realname || row.uid}」的提现申请（￥${Number(row.txmoney).toFixed(2)}）？`, '提示', { type: 'warning' })
  await setstPeisongWithdrawLog({ id: row.id, st: 1 })
  ElMessage.success('已审核')
  fetchList()
}

/** 驳回（st=2，返还余额） */
async function handleReject(row: PeisongWithdrawLogItem) {
  const { value } = await ElMessageBox.prompt('请输入驳回原因', '驳回提现', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputPlaceholder: '驳回原因',
    type: 'warning',
  })
  await setstPeisongWithdrawLog({ id: row.id, st: 2, reason: value || '' })
  ElMessage.success('已驳回，余额已返还')
  fetchList()
}

/** 打款（st=10 mock 即时成功） */
async function handlePay(row: PeisongWithdrawLogItem) {
  await ElMessageBox.confirm(`确认向「${row.realname || row.uid}」打款 ￥${Number(row.money).toFixed(2)}（${row.paytype}）？`, '打款确认', { type: 'warning' })
  const rs = await setstPeisongWithdrawLog({ id: row.id, st: 10 })
  ElMessage.success(rs && (rs as { mock?: boolean }).mock ? '打款成功（mock）' : '打款成功')
  fetchList()
}

async function handleDel(row?: PeisongWithdrawLogItem) {
  const ids = row ? [row.id] : selection.value.map((v) => v.id)
  if (!ids.length) return ElMessage.warning('请先选择记录')
  await ElMessageBox.confirm(`确认删除选中的 ${ids.length} 条提现记录？`, '提示', { type: 'warning' })
  await delPeisongWithdrawLog(ids)
  ElMessage.success('删除成功')
  fetchList()
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="配送员">
          <el-input v-model="query.realname" placeholder="姓名" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="配送员ID">
          <el-input v-model="query.uid" placeholder="ID" clearable style="width: 90px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="审核中" value="0" />
            <el-option label="已审核" value="1" />
            <el-option label="已驳回" value="2" />
            <el-option label="已打款" value="3" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <div class="op-row">
        <el-button type="danger" plain @click="handleDel()">批量删除</el-button>
      </div>

      <el-table v-loading="loading" :data="tableData" stripe border size="small" @selection-change="(rows: PeisongWithdrawLogItem[]) => (selection = rows)">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column label="配送员" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.realname || `配送员${row.uid}` }}
            <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="提现金额" width="96" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.txmoney).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="打款金额" width="96" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="提现方式" width="92" class-name="compact-col">
          <template #default="{ row }">{{ row.paytype || '-' }}</template>
        </el-table-column>
        <el-table-column label="收款账号" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ accountOf(row) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="84" class-name="compact-col">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">{{ row.status_name }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="申请时间" width="88" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ formatTime(row.createtime).slice(2, 10) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="驳回原因/打款单号" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.paynum || row.reason || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="170" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <el-button v-if="row.status === 0" link type="primary" size="small" @click="handleAuditPass(row)">通过</el-button>
            <el-button v-if="row.status === 0" link type="warning" size="small" @click="handleReject(row)">驳回</el-button>
            <el-button v-if="row.status === 1" link type="success" size="small" @click="handlePay(row)">打款</el-button>
            <el-button link type="danger" size="small" @click="handleDel(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="(p: number) => { query.page = p; fetchList() }"
        @size-change="(s: number) => { query.pagesize = s; query.page = 1; fetchList() }"
      />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 12px;
}

.op-row {
  margin-bottom: 12px;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 4px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
