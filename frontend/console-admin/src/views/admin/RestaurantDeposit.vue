<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  checkDepositOrder,
  deleteDepositOrder,
  getDepositOrderDetail,
  getDepositOrderList,
  setDepositRemark,
  takeoutDepositOrder,
  type DepositLogItem,
  type DepositOrderItem,
} from '../../api/restaurantDeposit'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const data = ref<DepositOrderItem[]>([])
const total = ref(0)

const query = reactive({
  st: 'all',
  keyword: '',
  page: 1,
  pagesize: 10,
})

const statusTabs = [
  { label: '全部', value: 'all' },
  { label: '审核中', value: '0' },
  { label: '寄存中', value: '1' },
  { label: '已取出', value: '2' },
  { label: '未通过', value: '3' },
]

function statusTagType(status: number): '' | 'success' | 'warning' | 'info' | 'danger' {
  if (status === 0) return 'warning'
  if (status === 1) return 'success'
  if (status === 2) return 'info'
  if (status === 3) return 'danger'
  return 'info'
}

async function load() {
  loading.value = true
  try {
    const res = await getDepositOrderList({ ...query })
    data.value = res?.list ?? []
    total.value = res?.total ?? 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  load()
}

function handleTabChange() {
  query.page = 1
  load()
}

// ============ 详情抽屉 ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detailOrder = ref<DepositOrderItem | null>(null)
const detailLog = ref<DepositLogItem[]>([])

async function openDetail(row: DepositOrderItem) {
  detailVisible.value = true
  detailLoading.value = true
  detailOrder.value = null
  detailLog.value = []
  try {
    const res = await getDepositOrderDetail(row.id)
    detailOrder.value = res?.order ?? null
    detailLog.value = res?.log ?? []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    detailLoading.value = false
  }
}

// ============ 审核 ============
async function handleCheck(row: DepositOrderItem, type: 'access' | 'refuse') {
  const tip = type === 'access' ? '审核通过后将进入寄存中状态' : '驳回后会员不可取出该存酒'
  try {
    await ElMessageBox.confirm(`确定${type === 'access' ? '通过' : '驳回'}该寄存申请？${tip}`, '审核确认', {
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    await checkDepositOrder(row.id, type)
    ElMessage.success(type === 'access' ? '审核通过' : '已驳回')
    load()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 代取出 ============
const takeoutVisible = ref(false)
const takeoutSubmitting = ref(false)
const takeoutForm = reactive({ id: 0, name: '', num: 0, numbers: 1 })

function openTakeout(row: DepositOrderItem) {
  takeoutForm.id = row.id
  takeoutForm.name = row.name
  takeoutForm.num = row.num
  takeoutForm.numbers = row.num > 0 ? row.num : 1
  takeoutVisible.value = true
}

async function submitTakeout() {
  if (takeoutForm.numbers <= 0 || takeoutForm.numbers > takeoutForm.num) {
    ElMessage.warning(`取出数量需在 1 ~ ${takeoutForm.num} 之间`)
    return
  }
  takeoutSubmitting.value = true
  try {
    await takeoutDepositOrder(takeoutForm.id, takeoutForm.numbers)
    ElMessage.success(takeoutForm.numbers >= takeoutForm.num ? '已全部取出' : '取出成功')
    takeoutVisible.value = false
    load()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    takeoutSubmitting.value = false
  }
}

// ============ 后台备注 ============
const remarkVisible = ref(false)
const remarkSubmitting = ref(false)
const remarkForm = reactive({ id: 0, remark: '' })

function openRemark(row: DepositOrderItem) {
  remarkForm.id = row.id
  remarkForm.remark = row.remark || ''
  remarkVisible.value = true
}

async function submitRemark() {
  remarkSubmitting.value = true
  try {
    await setDepositRemark(remarkForm.id, remarkForm.remark)
    ElMessage.success('设置完成')
    remarkVisible.value = false
    load()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    remarkSubmitting.value = false
  }
}

// ============ 删除 ============
async function handleDelete(row: DepositOrderItem) {
  try {
    await ElMessageBox.confirm(`确定删除寄存单「${row.name}」？流水将一并删除`, '提示', {
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    await deleteDepositOrder(row.id)
    ElMessage.success('删除成功')
    load()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

onMounted(load)
</script>

<template>
  <div>
    <el-card shadow="never">
      <div class="list-title">寄存管理</div>
      <div class="toolbar">
        <el-radio-group v-model="query.st" @change="handleTabChange">
          <el-radio-button v-for="t in statusTabs" :key="t.value" :value="t.value">
            {{ t.label }}
          </el-radio-button>
        </el-radio-group>
        <el-input
          v-model="query.keyword"
          placeholder="物品名/联系人/电话"
          clearable
          style="width: 220px"
          @keyup.enter="handleSearch"
          @clear="handleSearch"
        />
        <el-button type="primary" @click="handleSearch">搜索</el-button>
      </div>

      <el-table v-loading="loading" :data="data" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="物品" min-width="160">
          <template #default="{ row }">
            <div style="display: flex; align-items: center; gap: 8px">
              <el-image
                v-if="row.pic"
                :src="row.pic"
                style="width: 36px; height: 36px; border-radius: 4px"
                fit="cover"
              />
              <el-tooltip :content="row.name" placement="top">
                <span class="cell-ellipsis">{{ row.name }}</span>
              </el-tooltip>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="num" label="剩余数量" width="90" align="center" />
        <el-table-column label="联系人" min-width="120">
          <template #default="{ row }">
            <div>{{ row.linkman }}</div>
            <div class="text-muted">{{ row.tel }}</div>
          </template>
        </el-table-column>
        <el-table-column label="会员" min-width="120">
          <template #default="{ row }">
            <span v-if="row.mid">{{ row.nickname || `会员${row.mid}` }}</span>
            <span v-else class="text-muted">后台录入</span>
          </template>
        </el-table-column>
        <el-table-column label="存放位置" min-width="120">
          <template #default="{ row }">
            <span v-if="row.position_name">
              {{ row.position_name }}{{ row.number_name ? ` / ${row.number_name}` : '' }}
            </span>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="寄存时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="210" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <template v-if="row.status === 0">
              <el-button link type="success" @click="handleCheck(row, 'access')">通过</el-button>
              <el-button link type="danger" @click="handleCheck(row, 'refuse')">驳回</el-button>
            </template>
            <el-button v-if="row.status === 1" link type="warning" @click="openTakeout(row)">
              取出
            </el-button>
            <el-dropdown trigger="click" style="margin-left: 8px">
              <el-button link type="info">更多</el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item @click="openRemark(row)">后台备注</el-dropdown-item>
                  <el-dropdown-item @click="handleDelete(row)" divided>删除</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.pagesize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          @current-change="load"
          @size-change="handleSearch"
        />
      </div>
    </el-card>

    <!-- 详情抽屉 -->
    <el-drawer v-model="detailVisible" title="寄存详情" size="480px">
      <div v-loading="detailLoading">
        <template v-if="detailOrder">
          <el-descriptions :column="1" border size="small">
            <el-descriptions-item label="物品名称">{{ detailOrder.name }}</el-descriptions-item>
            <el-descriptions-item label="剩余数量">{{ detailOrder.num }}</el-descriptions-item>
            <el-descriptions-item label="联系人">
              {{ detailOrder.linkman }}（{{ detailOrder.tel }}）
            </el-descriptions-item>
            <el-descriptions-item label="会员">
              {{ detailOrder.mid > 0 ? detailOrder.nickname || `会员${detailOrder.mid}` : '后台录入' }}
            </el-descriptions-item>
            <el-descriptions-item label="存放位置">
              {{
                detailOrder.position_name
                  ? `${detailOrder.position_name}${detailOrder.number_name ? ' / ' + detailOrder.number_name : ''}`
                  : '-'
              }}
            </el-descriptions-item>
            <el-descriptions-item label="状态">
              <el-tag :type="statusTagType(detailOrder.status)" size="small">
                {{ detailOrder.status_text }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="客户备注">
              {{ detailOrder.message || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="后台备注">
              {{ detailOrder.remark || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="寄存时间">
              {{ formatTime(detailOrder.createtime) }}
            </el-descriptions-item>
            <el-descriptions-item label="最近取出">
              {{ detailOrder.takeout_time > 0 ? formatTime(detailOrder.takeout_time) : '-' }}
            </el-descriptions-item>
          </el-descriptions>

          <div v-if="detailOrder.pic" style="margin-top: 12px">
            <div class="section-subtitle">物品照片</div>
            <el-image :src="detailOrder.pic" style="max-width: 200px" fit="contain" />
          </div>

          <div class="section-subtitle" style="margin-top: 16px">存取流水</div>
          <el-timeline v-if="detailLog.length">
            <el-timeline-item
              v-for="l in detailLog"
              :key="l.id"
              :timestamp="formatTime(l.createtime)"
              :type="l.type === 0 ? 'success' : 'warning'"
            >
              {{ l.type_text }} × {{ l.num }}（{{ l.remark || '无备注' }}）
            </el-timeline-item>
          </el-timeline>
          <el-empty v-else description="暂无流水" :image-size="60" />
        </template>
      </div>
    </el-drawer>

    <!-- 代取出 -->
    <el-dialog v-model="takeoutVisible" title="代取出" width="420px">
      <el-form label-width="90px">
        <el-form-item label="物品">{{ takeoutForm.name }}</el-form-item>
        <el-form-item label="剩余数量">{{ takeoutForm.num }}</el-form-item>
        <el-form-item label="取出数量">
          <el-input-number v-model="takeoutForm.numbers" :min="1" :max="takeoutForm.num" />
          <span class="form-tip">取出全部后订单自动置为已取出</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="takeoutVisible = false">取消</el-button>
        <el-button type="primary" :loading="takeoutSubmitting" @click="submitTakeout">
          确认取出
        </el-button>
      </template>
    </el-dialog>

    <!-- 后台备注 -->
    <el-dialog v-model="remarkVisible" title="后台备注" width="420px">
      <el-input
        v-model="remarkForm.remark"
        type="textarea"
        :rows="3"
        maxlength="200"
        show-word-limit
        placeholder="后台备注（会员不可见）"
      />
      <template #footer>
        <el-button @click="remarkVisible = false">取消</el-button>
        <el-button type="primary" :loading="remarkSubmitting" @click="submitRemark">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.list-title {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 16px;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.cell-ellipsis {
  display: inline-block;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: middle;
}

.text-muted {
  color: #909399;
  font-size: 12px;
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}

.form-tip {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}

.section-subtitle {
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 8px;
}
</style>
