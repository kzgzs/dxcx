<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteLuckyCollageTeam,
  getLuckyCollageProductList,
  getLuckyCollageTeamList,
  luckyCollageKaituan,
} from '../../api/luckyCollage'
import type { LuckyCollageProductItem, LuckyCollageTeamItem } from '../../api/luckyCollage'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<LuckyCollageTeamItem[]>([])
const total = ref(0)

// tabs：空=全部 1进行中 2已开奖 3失败
const activeTab = ref('')

const query = reactive({
  page: 1,
  pagesize: 10,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getLuckyCollageTeamList({
      page: query.page,
      pagesize: query.pagesize,
      status: activeTab.value === '' ? undefined : Number(activeTab.value),
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleTabChange() {
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

// 状态标签配色
function statusTagType(status: number) {
  if (status === 1) return 'warning'
  if (status === 2) return 'success'
  if (status === 3) return 'danger'
  return 'info'
}

// 是否已超时（进行中但已过截止时间）
function isOverdue(row: LuckyCollageTeamItem) {
  return row.status === 1 && row.deadline > 0 && row.deadline * 1000 < Date.now()
}

// 团员头像中奖配色
function memberIszjClass(iszj: number) {
  if (iszj === 1) return 'member-winner'
  if (iszj === 2) return 'member-loser'
  return ''
}

// ============ 机器人凑团 ============
const robotVisible = ref(false)
const robotSubmitting = ref(false)
const productOptions = ref<LuckyCollageProductItem[]>([])

const robotForm = reactive({
  proid: 0,
  buytype: 3,
  teamid: 0,
})

async function fetchProducts() {
  try {
    const data = await getLuckyCollageProductList({ page: 1, pagesize: 100, status: 1 })
    productOptions.value = data.list || []
  } catch {
    // 忽略
  }
}

function openRobot(buytype: number, row?: LuckyCollageTeamItem) {
  robotForm.buytype = buytype
  robotForm.proid = row ? row.proid : 0
  robotForm.teamid = row ? row.id : 0
  robotVisible.value = true
}

async function handleRobotSubmit() {
  if (!robotForm.proid) {
    ElMessage.warning('请选择商品')
    return
  }
  if (robotForm.buytype === 3 && !robotForm.teamid) {
    ElMessage.warning('请填写要参与的团ID')
    return
  }
  robotSubmitting.value = true
  try {
    const res = await luckyCollageKaituan(
      robotForm.proid,
      robotForm.buytype,
      robotForm.buytype === 3 ? robotForm.teamid : 0,
    )
    robotVisible.value = false
    ElMessage.success(res.kaijiang ? '机器人已入团并触发开奖' : '机器人已入团')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    robotSubmitting.value = false
  }
}

// ============ 删除 ============
function handleDelete(row: LuckyCollageTeamItem) {
  ElMessageBox.confirm(
    `确定要删除该拼团记录吗？（商品：${row.product_name || row.proid}）删除后不可恢复。`,
    '删除确认',
    {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning',
    },
  )
    .then(async () => {
      try {
        await deleteLuckyCollageTeam(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出（进行中的团会被后端拒绝删除）
      }
    })
    .catch(() => {})
}

onMounted(() => {
  fetchProducts()
  fetchList()
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <div class="team-toolbar">
        <el-tabs v-model="activeTab" class="team-tabs" @tab-change="handleTabChange">
          <el-tab-pane label="全部" name="" />
          <el-tab-pane label="进行中" name="1" />
          <el-tab-pane label="已开奖" name="2" />
          <el-tab-pane label="成团失败" name="3" />
        </el-tabs>
        <div>
          <el-button type="warning" @click="openRobot(2)">机器人发团</el-button>
          <el-button type="primary" @click="openRobot(3)">机器人参团</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="团ID" width="80" />
        <el-table-column prop="product_name" label="商品" min-width="160" show-overflow-tooltip />
        <el-table-column label="成团进度" width="120">
          <template #default="{ row }">
            <span :class="{ 'text-danger': isOverdue(row) }">
              {{ row.num }} / {{ row.teamnum }} 人
            </span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">
              {{ row.status_text }}
            </el-tag>
            <div v-if="isOverdue(row)" class="overdue-tip">待定时任务解散</div>
          </template>
        </el-table-column>
        <el-table-column label="团员（开奖结果）" min-width="220">
          <template #default="{ row }">
            <div v-if="row.members.length" class="member-row">
              <el-tooltip
                v-for="m in row.members"
                :key="`${row.id}-${m.mid}-${m.nickname}`"
                :content="`${m.nickname}：${m.iszj_text}`"
                placement="top"
              >
                <el-avatar :size="28" :src="m.headimg || undefined" :class="memberIszjClass(m.iszj)">
                  {{ m.nickname.slice(0, 1) }}
                </el-avatar>
              </el-tooltip>
            </div>
            <span v-else class="text-muted">暂无</span>
          </template>
        </el-table-column>
        <el-table-column label="开团时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="截止时间" width="160">
          <template #default="{ row }">
            <span :class="{ 'text-danger': isOverdue(row) }">
              {{ row.deadline ? formatTime(row.deadline) : '-' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="170" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 1"
              link
              type="primary"
              @click="openRobot(3, row)"
            >
              机器人凑团
            </el-button>
            <el-button
              link
              type="danger"
              :disabled="row.status === 1"
              @click="handleDelete(row)"
            >
              删除
            </el-button>
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

    <!-- 机器人凑团弹窗 -->
    <el-dialog
      v-model="robotVisible"
      :title="robotForm.buytype === 2 ? '机器人发团' : '机器人参团'"
      width="440px"
    >
      <el-form label-width="90px" label-position="left">
        <el-form-item label="商品">
          <el-select
            v-model="robotForm.proid"
            filterable
            placeholder="请选择商品"
            style="width: 100%"
          >
            <el-option
              v-for="p in productOptions"
              :key="p.id"
              :label="p.name"
              :value="p.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item v-if="robotForm.buytype === 3" label="团ID">
          <el-input-number
            v-model="robotForm.teamid"
            :min="0"
            :precision="0"
            :controls="false"
            style="width: 100%"
            placeholder="留空自动选择进行中的团"
          />
        </el-form-item>
        <el-alert
          type="info"
          :closable="false"
          title="从机器人池随机抽取机器人入团凑人数，满员自动触发开奖"
        />
      </el-form>
      <template #footer>
        <el-button @click="robotVisible = false">取消</el-button>
        <el-button type="primary" :loading="robotSubmitting" @click="handleRobotSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.team-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.team-tabs {
  flex: 1;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.member-row {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
}

.member-winner {
  outline: 2px solid #67c23a;
}

.member-loser {
  opacity: 0.6;
}

.text-danger {
  color: #f56c6c;
}

.text-muted {
  color: #909399;
}

.overdue-tip {
  margin-top: 2px;
  color: #f56c6c;
  font-size: 12px;
}
</style>
