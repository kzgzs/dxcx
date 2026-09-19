<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { getHexiaoList } from '../../api/finance'
import type { HexiaoItem } from '../../api/finance'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<HexiaoItem[]>([])
const total = ref(0)

const TYPE_OPTIONS = [
  { label: '商城订单', value: 'shop' },
  { label: '预约订单', value: 'yuyue' },
  { label: '酒店订单', value: 'hotel' },
  { label: '网约车订单', value: 'carhailing' },
  { label: '餐饮订单', value: 'restaurant' },
  { label: '礼品卡', value: 'lipin' },
  { label: '课程订单', value: 'kecheng' },
  { label: '拼团订单', value: 'collage' },
  { label: '秒杀订单', value: 'seckill' },
  { label: '砍价订单', value: 'kanjia' },
  { label: '积分兑换', value: 'scoreshop' },
  { label: '团购订单', value: 'tuangou' },
]

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  type: '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getHexiaoList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      type: query.type || undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
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
  query.keyword = ''
  query.type = ''
  query.timeRange = null
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
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="订单号 / 标题 / 会员昵称"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="类型">
          <el-select v-model="query.type" placeholder="全部" clearable style="width: 130px">
            <el-option v-for="t in TYPE_OPTIONS" :key="t.value" :label="t.label" :value="t.value" />
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
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表（border 竖线 + 紧凑列宽，1366 无横向滚动） -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="60" class-name="compact-col" />
        <el-table-column prop="title" label="商品/标题" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title || '-' }}</template>
        </el-table-column>
        <el-table-column prop="type_text" label="类型" width="92" class-name="compact-col" show-overflow-tooltip />
        <el-table-column prop="ordernum" label="订单号" min-width="150" show-overflow-tooltip />
        <el-table-column label="会员" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.nickname || (row.mid ? `会员${row.mid}` : '-') }}
            <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="核销员" width="84" class-name="compact-col">
          <template #default="{ row }">
            <span v-if="row.uid > 0">管理员{{ row.uid }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="mendian_name" label="门店" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.mendian_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="核销时间" width="92" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ shortDate(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
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
.toolbar-card {
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

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
