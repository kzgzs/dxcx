<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { getSendLogList } from '../../api/prizepool'
import type { PrizePoolSendLogItem } from '../../api/prizepool'
import { formatTime } from '../../utils/time'

// 发放记录：三池合并视图（利润池 / 区域池 / 达标池），任务184
const loading = ref(false)
const tableData = ref<PrizePoolSendLogItem[]>([])
const total = ref(0)
const poolType = ref<'profit' | 'area' | 'reach'>('profit')
const query = reactive({ page: 1, pagesize: 10 })

async function fetchList() {
  loading.value = true
  try {
    const data = await getSendLogList({ pool_type: poolType.value, page: query.page, pagesize: query.pagesize })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // ignore
  } finally {
    loading.value = false
  }
}

watch(poolType, () => {
  query.page = 1
  fetchList()
})

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <el-tabs v-model="poolType" class="pool-tabs">
        <el-tab-pane label="利润奖金池" name="profit" />
        <el-tab-pane label="区域奖金池" name="area" />
        <el-tab-pane label="达标奖金池" name="reach" />
      </el-tabs>

      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column label="池名称" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.name || '-' }}</template>
        </el-table-column>
        <el-table-column v-if="poolType !== 'profit'" label="受益会员" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.nickname || `会员${row.mid}` }}</template>
        </el-table-column>
        <el-table-column label="发放金额" width="100" class-name="compact-col">
          <template #default="{ row }">
            <span class="money-minus">￥{{ Number(row.send_money).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="发放时池金额" width="110" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.pool_money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column v-if="poolType === 'profit'" label="等级" width="90" class-name="compact-col">
          <template #default="{ row }">{{ row.level_text || '-' }}</template>
        </el-table-column>
        <el-table-column v-if="poolType === 'profit'" label="人数" width="66" class-name="compact-col">
          <template #default="{ row }">{{ row.member_count }}</template>
        </el-table-column>
        <el-table-column v-if="poolType === 'profit'" label="方式" width="72" class-name="compact-col">
          <template #default="{ row }">
            <el-tag size="small" :type="row.send_type === 1 ? 'primary' : 'warning'">{{ row.send_type === 1 ? '自动' : '手动' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="detail" label="明细" min-width="170" show-overflow-tooltip />
        <el-table-column label="发放时间" width="140" class-name="compact-col">
          <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50]"
        @current-change="(p: number) => { query.page = p; fetchList() }"
        @size-change="(s: number) => { query.pagesize = s; query.page = 1; fetchList() }"
      />
    </el-card>
  </div>
</template>

<style scoped>
.pool-tabs {
  margin-bottom: 8px;
}

.money-minus {
  color: #f56c6c;
  font-weight: 600;
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
