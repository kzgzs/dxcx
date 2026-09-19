<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { delPeisongMoneyLog, getPeisongMoneyLogList } from '../../api/peisong'
import type { PeisongMoneyLogItem } from '../../api/peisong'
import { formatTime } from '../../utils/time'

// 租户后台 - 同城配送·提成明细（批次8 任务223；对标参考系统 PeisongMoney::moneylog）

const loading = ref(false)
const tableData = ref<PeisongMoneyLogItem[]>([])
const total = ref(0)
const selection = ref<PeisongMoneyLogItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  realname: '',
  uid: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getPeisongMoneyLogList({
      page: query.page,
      pagesize: query.pagesize,
      realname: query.realname || undefined,
      uid: query.uid || undefined,
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
  query.page = 1
  fetchList()
}

async function handleDel(row?: PeisongMoneyLogItem) {
  const ids = row ? [row.id] : selection.value.map((v) => v.id)
  if (!ids.length) return ElMessage.warning('请先选择明细')
  await ElMessageBox.confirm(`确认删除选中的 ${ids.length} 条明细？`, '提示', { type: 'warning' })
  await delPeisongMoneyLog(ids)
  ElMessage.success('删除成功')
  fetchList()
}

function shortDate(ts: number): string {
  if (!ts) return '-'
  const full = formatTime(ts)
  return full.length >= 10 ? full.slice(2, 10) : full
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

      <el-table v-loading="loading" :data="tableData" stripe border size="small" @selection-change="(rows: PeisongMoneyLogItem[]) => (selection = rows)">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column label="配送员" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.realname || `配送员${row.uid}` }}
            <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="变更金额" width="100" class-name="compact-col">
          <template #default="{ row }">
            <span :class="Number(row.money) >= 0 ? 'money-plus' : 'money-minus'">
              {{ Number(row.money) >= 0 ? '+' : '' }}{{ Number(row.money).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="变更后剩余" width="104" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.after).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="变更时间" width="88" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ shortDate(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="76" fixed="right" class-name="compact-col">
          <template #default="{ row }">
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

.money-plus {
  color: #67c23a;
  font-weight: 600;
}

.money-minus {
  color: #f56c6c;
  font-weight: 600;
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
