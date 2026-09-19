<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  delPeisongOrder,
  getPeisongOrderList,
  getPeisongUserOptions,
  setstPeisongOrder,
} from '../../api/peisong'
import type { PeisongOrderItem, PsUserOption } from '../../api/peisong'
import { formatTime } from '../../utils/time'

// 租户后台 - 同城配送·配送单（批次8 任务223；对标参考系统 PeisongOrder）
// bid prop 由 merchantViewOf 注入（'gt0' 商户过滤视图），缺省不过滤
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

const loading = ref(false)
const tableData = ref<PeisongOrderItem[]>([])
const total = ref(0)
const selection = ref<PeisongOrderItem[]>([])
const psOptions = ref<PsUserOption[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  psid: '',
  status: '',
  pstype: '',
  nickname: '',
})

// 状态 tag 颜色
const STATUS_TAG: Record<number, 'danger' | 'success' | 'info' | 'warning'> = {
  0: 'danger',
  1: 'success',
  2: 'success',
  3: 'success',
  4: 'info',
  10: 'warning',
}

const isMerchantView = computed(() => props.bid === 'gt0' || props.bid === 'merchant')

async function fetchList() {
  loading.value = true
  try {
    const data = await getPeisongOrderList({
      page: query.page,
      pagesize: query.pagesize,
      ordernum: query.ordernum || undefined,
      psid: query.psid === '' ? undefined : query.psid,
      status: query.status === '' ? undefined : query.status,
      pstype: query.pstype === '' ? undefined : query.pstype,
      nickname: query.nickname || undefined,
      bid: props.bid || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // axios 拦截器已提示
  } finally {
    loading.value = false
  }
}

async function fetchPsOptions() {
  try {
    const data = await getPeisongUserOptions()
    psOptions.value = data.list || []
  } catch {
    // 忽略
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.ordernum = ''
  query.psid = ''
  query.status = ''
  query.pstype = ''
  query.nickname = ''
  query.page = 1
  fetchList()
}

// 详情弹窗
const detailVisible = ref(false)
const detailRow = ref<PeisongOrderItem | null>(null)

function handleDetail(row: PeisongOrderItem) {
  detailRow.value = row
  detailVisible.value = true
}

async function handleSetst(row: PeisongOrderItem, st: number) {
  if (st === 10) {
    await ElMessageBox.confirm(`确认取消配送单 ${row.ordernum}？`, '提示', { type: 'warning' })
  }
  await setstPeisongOrder({ ids: [row.id], st, bid: props.bid || undefined })
  ElMessage.success('操作成功')
  fetchList()
}

async function handleBatchSetst(st: number) {
  const ids = selection.value.map((v) => v.id)
  if (!ids.length) return ElMessage.warning('请先选择配送单')
  await setstPeisongOrder({ ids, st, bid: props.bid || undefined })
  ElMessage.success('操作成功')
  fetchList()
}

async function handleDel(row?: PeisongOrderItem) {
  const ids = row ? [row.id] : selection.value.map((v) => v.id)
  if (!ids.length) return ElMessage.warning('请先选择配送单')
  await ElMessageBox.confirm(`确认删除选中的 ${ids.length} 条配送记录？`, '提示', { type: 'warning' })
  await delPeisongOrder(ids, props.bid || undefined)
  ElMessage.success('删除成功')
  fetchList()
}

// 商户视图切换时重查
watch(
  () => props.bid,
  () => {
    query.page = 1
    fetchList()
  },
)

onMounted(() => {
  fetchList()
  fetchPsOptions()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" placeholder="配送单号" clearable style="width: 160px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="待接单" value="0" />
            <el-option label="已接单" value="1" />
            <el-option label="已到店" value="2" />
            <el-option label="已取货" value="3" />
            <el-option label="已完成" value="4" />
            <el-option label="已取消" value="10" />
          </el-select>
        </el-form-item>
        <el-form-item label="配送员">
          <el-select v-model="query.psid" placeholder="全部" clearable filterable style="width: 150px">
            <el-option v-for="u in psOptions" :key="u.id" :label="`${u.realname}（${u.tel}）`" :value="String(u.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="配送类型">
          <el-select v-model="query.pstype" placeholder="全部" clearable style="width: 120px">
            <el-option label="系统配送" value="1" />
            <el-option label="码科配送" value="2" />
            <el-option label="已拒绝" value="3" />
          </el-select>
        </el-form-item>
        <el-form-item label="下单人">
          <el-input v-model="query.nickname" placeholder="昵称/手机号" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <div v-if="!isMerchantView" class="op-row">
        <el-button @click="handleBatchSetst(4)">批量完成</el-button>
        <el-button @click="handleBatchSetst(10)">批量取消</el-button>
        <el-button type="danger" plain @click="handleDel()">批量删除</el-button>
      </div>

      <el-table v-loading="loading" :data="tableData" stripe border size="small" @selection-change="(rows: PeisongOrderItem[]) => (selection = rows)">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column label="订单号" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.ordernum }}
            <div v-if="row.make_ordernum" class="sub-text">码科：{{ row.make_ordernum }}</div>
          </template>
        </el-table-column>
        <el-table-column label="下单人" min-width="100" show-overflow-tooltip>
          <template #default="{ row }">{{ row.member.nickname || `会员${row.member.id}` }}</template>
        </el-table-column>
        <el-table-column label="商家" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ (row.binfo && (row.binfo as Record<string, unknown>).name) || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="84" class-name="compact-col">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">{{ row.status_name }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="配送员" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.psid === -2" class="sub-text">已拒绝</span>
            <span v-else-if="row.psuser.realname">
              {{ row.psuser.realname }}
              <span class="sub-text">{{ row.psuser.tel }}</span>
            </span>
            <span v-else class="sub-text">待接单</span>
          </template>
        </el-table-column>
        <el-table-column label="提成" width="84" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.ticheng).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="配送费" width="84" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.psfee).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="类型" width="86" class-name="compact-col">
          <template #default="{ row }">{{ row.pstype || '-' }}</template>
        </el-table-column>
        <el-table-column label="下单时间" width="88" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ formatTime(row.createtime).slice(2, 10) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="handleDetail(row)">详情</el-button>
            <el-button v-if="row.status < 4" link type="warning" size="small" @click="handleSetst(row, 10)">取消</el-button>
            <el-button v-if="!isMerchantView" link type="danger" size="small" @click="handleDel(row)">删除</el-button>
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

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="配送单详情" width="640px" destroy-on-close>
      <template v-if="detailRow">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="订单号">{{ detailRow.ordernum }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ detailRow.status_name }}</el-descriptions-item>
          <el-descriptions-item label="下单人">{{ detailRow.member.nickname || `会员${detailRow.member.id}` }}</el-descriptions-item>
          <el-descriptions-item label="配送员">{{ detailRow.psuser.realname || '-' }}</el-descriptions-item>
          <el-descriptions-item label="提成">￥{{ Number(detailRow.ticheng).toFixed(2) }}</el-descriptions-item>
          <el-descriptions-item label="配送费">￥{{ Number(detailRow.psfee).toFixed(2) }}</el-descriptions-item>
          <el-descriptions-item label="下单时间">{{ detailRow.createtime ? formatTime(detailRow.createtime) : '-' }}</el-descriptions-item>
          <el-descriptions-item label="完成时间">{{ detailRow.endtime ? formatTime(detailRow.endtime) : '-' }}</el-descriptions-item>
          <el-descriptions-item label="收货信息" :span="2">
            {{ ((detailRow.orderinfo as Record<string, unknown>).linkman || '') as string }}
            {{ ((detailRow.orderinfo as Record<string, unknown>).tel || '') as string }}
            {{ ((detailRow.orderinfo as Record<string, unknown>).address || '') as string }}
          </el-descriptions-item>
        </el-descriptions>

        <div v-if="detailRow.prolist && detailRow.prolist.length" class="prolist">
          <div class="prolist-title">商品信息</div>
          <div v-for="(g, i) in detailRow.prolist" :key="i" class="pro-item">
            <span>{{ (g as Record<string, unknown>).name }}</span>
            <span v-if="(g as Record<string, unknown>).ggname" class="sub-text">{{ (g as Record<string, unknown>).ggname }}</span>
            <span class="pro-num">× {{ (g as Record<string, unknown>).num }}</span>
          </div>
        </div>
      </template>
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
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

.prolist {
  margin-top: 14px;
}

.prolist-title {
  font-weight: 600;
  margin-bottom: 8px;
}

.pro-item {
  font-size: 13px;
  line-height: 24px;
}

.pro-num {
  color: #f56c6c;
  margin-left: 8px;
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
