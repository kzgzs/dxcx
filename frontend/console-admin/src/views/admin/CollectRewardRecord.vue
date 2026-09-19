<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { getCollectRewardRecords } from '../../api/marketing'
import type { CollectRewardRecordItem } from '../../api/marketing'

const loading = ref(false)
const tableData = ref<CollectRewardRecordItem[]>([])
const total = ref(0)

const query = reactive({
  ordertype: '',
  mid: 0,
  page: 1,
  pagesize: 10,
})

const ORDERTYPE_TEXT: Record<string, string> = {
  shop: '商城订单',
  collage: '拼团订单',
  seckill: '秒杀订单',
  kanjia: '砍价订单',
  tuangou: '团购订单',
  yuyue: '预约订单',
  scoreshop: '积分商城',
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getCollectRewardRecords({
      ordertype: query.ordertype,
      mid: query.mid > 0 ? query.mid : undefined,
      page: query.page,
      pagesize: query.pagesize,
    })
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

function handleReset() {
  query.ordertype = ''
  query.mid = 0
  query.page = 1
  fetchList()
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="订单类型">
          <el-select v-model="query.ordertype" placeholder="全部" clearable style="width: 140px">
            <el-option v-for="(label, key) in ORDERTYPE_TEXT" :key="key" :label="label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input-number v-model="query.mid" :min="0" controls-position="right" style="width: 130px" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="订单类型" width="110">
          <template #default="{ row }">{{ ORDERTYPE_TEXT[row.ordertype] || row.ordertype }}</template>
        </el-table-column>
        <el-table-column label="订单号" min-width="150">
          <template #default="{ row }">{{ row.ordernum || `#${row.orderid}` }}</template>
        </el-table-column>
        <el-table-column label="会员" min-width="130">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="reward_text" label="奖励内容" min-width="160" />
        <el-table-column prop="ordertime_text" label="下单时间" width="170" />
        <el-table-column prop="createtime_text" label="发放时间" width="170" />
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="(p: number) => ((query.page = p), fetchList())"
        @size-change="(s: number) => ((query.pagesize = s), (query.page = 1), fetchList())"
      />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
