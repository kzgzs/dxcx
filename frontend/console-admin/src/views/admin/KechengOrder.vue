<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { getKechengOrderList } from '../../api/kecheng'
import type { KechengOrderItem } from '../../api/kecheng'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<KechengOrderItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(20)
const keyword = ref('')
const filterStatus = ref<string>('')

async function fetchList() {
  loading.value = true
  try {
    const data = await getKechengOrderList({
      page: page.value,
      pagesize: pagesize.value,
      keyword: keyword.value,
      status: filterStatus.value,
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
  page.value = 1
  fetchList()
}

function commissionText(row: KechengOrderItem) {
  const parts = [
    `一级 ¥${Number(row.parent1commission || 0).toFixed(2)}`,
    `二级 ¥${Number(row.parent2commission || 0).toFixed(2)}`,
    `三级 ¥${Number(row.parent3commission || 0).toFixed(2)}`,
  ]
  return parts.join(' / ')
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- 工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="keyword"
            placeholder="订单号 / 课程名 / 会员"
            clearable
            style="width: 220px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="filterStatus" style="width: 130px">
            <el-option label="全部" value="" />
            <el-option label="已支付" :value="1" />
            <el-option label="未支付" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">查询</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 订单列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="ordernum" label="订单号" min-width="180" show-overflow-tooltip />
        <el-table-column prop="title" label="课程" min-width="160" show-overflow-tooltip />
        <el-table-column label="会员" width="150">
          <template #default="{ row }">
            {{ row.member_nickname || '-' }}
            <span v-if="row.member_tel">（{{ row.member_tel }}）</span>
          </template>
        </el-table-column>
        <el-table-column label="实付金额" width="100">
          <template #default="{ row }">¥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="分销佣金" width="220">
          <template #default="{ row }">{{ commissionText(row) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'warning'" size="small">
              {{ row.status === 1 ? '已支付' : '未支付' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="paytype" label="支付方式" width="90" />
        <el-table-column label="支付时间" width="160">
          <template #default="{ row }">
            {{ row.paytime > 0 ? formatTime(row.paytime) : '-' }}
          </template>
        </el-table-column>
        <el-table-column label="下单时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pager"
        v-model:current-page="page"
        v-model:page-size="pagesize"
        :total="total"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next"
        @current-change="fetchList"
        @size-change="handleSearch"
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

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
