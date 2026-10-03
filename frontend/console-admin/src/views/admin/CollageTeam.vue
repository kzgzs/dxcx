<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { getCollageTeamList } from '../../api/collage'
import CollageOrder from './CollageOrder.vue'

const loading = ref(false)
const tableData = ref<Record<string, any>[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  ctime: [] as string[],
  status: '',
})

// 查看详情：平铺订单列表面板
const detailVisible = ref(false)
const detailTeamId = ref(0)

async function fetchList() {
  loading.value = true
  try {
    const params: Record<string, any> = {
      page: query.page,
      pagesize: query.pagesize,
      status: query.status === '' ? undefined : Number(query.status),
    }
    if (query.ctime && query.ctime.length === 2) {
      params.ctime_start = query.ctime[0]
      params.ctime_end = query.ctime[1]
    }
    const data = await getCollageTeamList(params as any)
    tableData.value = (data as any).list || []
    total.value = (data as any).total || 0
  } catch {
    /* 拦截器统一提示 */
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

function viewOrders(row: Record<string, any>) {
  detailTeamId.value = row.id
  detailVisible.value = true
}

onMounted(fetchList)
</script>

<template>
  <div class="collage-team">
    <div v-if="!detailVisible" class="page-body">
      <div class="toolbar">
        <div class="toolbar-left" />
        <div class="toolbar-search">
          <el-date-picker
            v-model="query.ctime"
            type="daterange"
            size="small"
            range-separator="~"
            start-placeholder="开团时间"
            end-placeholder="~"
            value-format="YYYY-MM-DD"
            style="width: 230px"
          />
          <el-select v-model="query.status" placeholder="状态" size="small" style="width: 110px">
            <el-option label="全部" value="" />
            <el-option label="拼团中" value="1" />
            <el-option label="拼团成功" value="2" />
            <el-option label="拼团失败" value="3" />
          </el-select>
          <button class="search-btn" @click="handleSearch" title="搜索">
            <svg viewBox="0 0 1024 1024" width="14" height="14"><path fill="#fff" d="M795.904 750.72l124.992 124.928a32 32 0 1 1-45.269 45.269l-124.928-124.992a416 416 0 1 1 45.269-45.269zM480 832a352 352 0 1 0 0-704 352 352 0 0 0 0 704z"/></svg>
          </button>
        </div>
      </div>

      <el-table v-loading="loading" :data="tableData" border>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="商品信息" min-width="160">
          <template #default="{ row }">
            <div class="goods-cell">
              <el-image v-if="row.product_pic" :src="row.product_pic" fit="cover" class="goods-thumb" />
              <div class="goods-name">{{ row.product_name || '#' + row.proid }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="参团人" min-width="240">
          <template #default="{ row }">
            <div class="member-wrap">
              <div v-for="(m, i) in row.members" :key="i" class="member-item">
                <el-avatar :size="32" :src="m.headimg || undefined">
                  {{ m.headimg ? '' : '?' }}
                </el-avatar>
                <div class="member-tel">{{ m.tel_mask || m.nickname || '?' }}</div>
              </div>
              <div class="cell-sub">{{ row.num }}/{{ row.teamnum }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <span :class="['status-tag', row.status === 2 ? 'ok' : row.status === 3 ? 'fail' : 'doing']">
              {{ row.status_text }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="viewOrders(row)">查看详情</el-button>
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
    </div>

    <!-- 查看详情：平铺订单列表（右上X关闭） -->
    <div v-else class="order-panel">
      <CollageOrder :embedded="true" :teamid="detailTeamId" @close="detailVisible = false" />
    </div>
  </div>
</template>

<style scoped>
.collage-team {
  margin: 3px;
}
.toolbar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  padding: 6px 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  gap: 6px;
}
.search-btn {
  width: 28px;
  height: 28px;
  border: none;
  border-radius: 4px;
  background: #67c23a;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
}
.search-btn:hover {
  background: #529b2e;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.goods-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.goods-thumb {
  width: 40px;
  height: 40px;
  border-radius: 4px;
  flex-shrink: 0;
}
.goods-name {
  font-size: 13px;
}
.cell-sub {
  font-size: 12px;
  color: #909399;
  margin-top: 2px;
}
.member-wrap {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  flex-wrap: wrap;
}
.member-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}
.member-tel {
  font-size: 11px;
  color: #606266;
}
.status-tag {
  display: inline-block;
  padding: 2px 10px;
  font-size: 12px;
  border: 1px solid #dcdfe6;
  border-radius: 3px;
  color: #909399;
}
.status-tag.ok {
  color: #67c23a;
  border-color: #67c23a;
  background: #f0f9eb;
}
.status-tag.fail {
  color: #909399;
  background: #f4f4f5;
}
.status-tag.doing {
  color: #e6a23c;
  border-color: #e6a23c;
  background: #fdf6ec;
}
:deep(.el-table__header th) {
  background: #e6e6e6;
}
:deep(.el-table .cell) {
  text-align: left;
}
</style>
