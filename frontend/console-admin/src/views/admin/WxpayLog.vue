<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { cancelWxTransfer, getWxTransferLogList } from '../../api/finance'
import type { WxTransferLogItem } from '../../api/finance'
import { assetUrl } from '../../api/upload'
import { formatTime } from '../../utils/time'

// 状态筛选（契约第5页 state 文案映射）
const STATE_OPTIONS = [
  { label: '转账已受理', value: 'ACCEPTED' },
  { label: '转账处理中', value: 'PROCESSING' },
  { label: '待收款用户确认', value: 'WAIT_USER_CONFIRM' },
  { label: '转账结果尚未明确', value: 'TRANSFERING' },
  { label: '转账成功', value: 'SUCCESS' },
  { label: '转账失败', value: 'FAIL' },
  { label: '撤销中', value: 'CANCELING' },
  { label: '撤销完成', value: 'CANCELLED' },
]

const loading = ref(false)
const tableData = ref<WxTransferLogItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  mid: '',
  state: '',
  timeRange: null as [string, string] | null,
})

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    mid: query.mid ? Number(query.mid) : undefined,
    state: query.state || undefined,
    start_date: query.timeRange?.[0] || undefined,
    end_date: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getWxTransferLogList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

// 行[提现记录]：按 data_tbl/data_id 提示来源（前端无对应跳转页，照 canzhao 不瞎编路由）
function showSource(row: WxTransferLogItem) {
  if (!row.data_id) {
    ElMessage.info('该转账无关联提现单')
    return
  }
  ElMessage.info(`提现记录来源：${row.data_tbl} #${row.data_id}（平台订单号 ${row.ordernum || '-'}）`)
}

// 行[撤销]：仅 ACCEPTED/PROCESSING/WAIT_USER_CONFIRM 可撤销，照 canzhao wx_transfer_cancel
const CANCELLABLE_STATES = ['ACCEPTED', 'PROCESSING', 'WAIT_USER_CONFIRM']
function canCancel(row: WxTransferLogItem): boolean {
  return CANCELLABLE_STATES.includes(row.state)
}
async function handleCancel(row: WxTransferLogItem) {
  try {
    await ElMessageBox.confirm(`确定要撤销ID：${row.id}的转账吗？`, '操作确认', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  try {
    await cancelWxTransfer(row.id)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  }
}
onMounted(() => {
  fetchList()
})
</script>

<template>
  <div class="fin-page">
    <el-card shadow="never" class="main-card">
      <!-- 工具栏：本页无顶栏批量按钮，仅搜索（绿色圆形放大镜） -->
      <div class="toolbar">
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="会员ID">
            <el-input v-model="query.mid" placeholder="会员ID" clearable style="width: 110px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="query.state" placeholder="全部" clearable style="width: 160px">
              <el-option v-for="o in STATE_OPTIONS" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
          </el-form-item>
          <el-form-item label="时间">
            <el-date-picker
              v-model="query.timeRange"
              type="daterange"
              range-separator="至"
              start-placeholder="开始"
              end-placeholder="结束"
              value-format="YYYY-MM-DD"
              style="width: 220px"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-icon-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 表格：无 fixed 列，整体横向滚动 -->
      <el-table v-loading="loading" :data="tableData" border>
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="mid" label="会员ID" width="80" />
        <el-table-column label="会员信息" min-width="170">
          <template #default="{ row }">
            <div class="member-info">
              <img v-if="row.headimg" :src="assetUrl(row.headimg)" class="member-avatar" @error="onAvatarError" />
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="转账金额" width="110">
          <template #default="{ row }">
            <span class="money-plus">+{{ Number(row.money).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="ordernum" label="平台订单号" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.ordernum || '-' }}</template>
        </el-table-column>
        <el-table-column prop="desc" label="转账备注" width="110">
          <template #default="{ row }">{{ row.desc || '-' }}</template>
        </el-table-column>
        <el-table-column label="变更时间" width="140">
          <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">{{ row.state_str || row.state || '-' }}</template>
        </el-table-column>
        <el-table-column prop="transfer_bill_no" label="微信订单号" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.transfer_bill_no || '-' }}</template>
        </el-table-column>
        <el-table-column prop="message" label="错误信息" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.message || '-' }}</template>
        </el-table-column>
        <el-table-column prop="status_code" label="状态码" width="110">
          <template #default="{ row }">{{ row.status_code || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <div class="op-row">
              <el-button link type="primary" @click="showSource(row)">提现记录</el-button>
              <el-button v-if="canCancel(row)" link type="warning" @click="handleCancel(row)">撤销</el-button>
            </div>
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
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>
  </div>
</template>

<style scoped>
.fin-page {
  padding: 3px;
}
.main-card :deep(.el-card__body) {
  padding: 3px;
}
.toolbar {
  display: flex;
  align-items: center;
  margin-bottom: 3px;
  flex-wrap: nowrap;
}
.toolbar-search {
  flex: 1;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 10px;
}
.toolbar-search :deep(.el-input__inner) {
  text-align: left;
}
.search-icon-btn {
  border-radius: 50%;
  padding: 8px 13px;
}
.search-icon-btn svg {
  display: block;
}
/* 表头 #e6e6e6、左对齐；单元格紧凑 */
:deep(.el-table th.el-table__cell) {
  background: #e6e6e6;
  text-align: left;
}
:deep(.el-table .cell) {
  text-align: left;
  padding-left: 6px;
  padding-right: 6px;
}
:deep(.el-table td.el-table__cell) {
  padding: 3px 0;
}
.member-info {
  display: flex;
  align-items: center;
  gap: 6px;
}
.member-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  object-fit: cover;
}
.money-plus {
  color: #03bc01;
}
.op-row {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 2px 6px;
  white-space: nowrap;
}
.op-row :deep(.el-button) {
  margin: 0;
}
.pagination {
  margin-top: 3px;
  justify-content: flex-end;
}
</style>
