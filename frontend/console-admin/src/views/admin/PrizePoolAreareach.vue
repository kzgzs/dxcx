<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deletePool,
  getPoolList,
  getPoolOrderLog,
  getProvinces,
  savePool,
} from '../../api/prizepool'
import type { AreareachPoolItem, PrizePoolOrderLogItem, ProvinceItem } from '../../api/prizepool'
import { getMemberLevelList, getMemberList } from '../../api/member'
import type { MemberLevelItem, MemberItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// poolType：0 区域奖金池 / 1 达标奖金池（菜单两入口分别传入）
const props = defineProps<{ poolType: number }>()

const isArea = props.poolType === 0
const pageTitle = isArea ? '区域奖金池' : '达标奖金池'

// ===== 列表 =====
const loading = ref(false)
const tableData = ref<AreareachPoolItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 20 })

async function fetchList() {
  loading.value = true
  try {
    const data = await getPoolList({ type: props.poolType, page: query.page, pagesize: query.pagesize })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // ignore
  } finally {
    loading.value = false
  }
}

// ===== 新增/编辑弹窗 =====
const dialogVisible = ref(false)
const saving = ref(false)
const provinces = ref<ProvinceItem[]>([])
const levels = ref<MemberLevelItem[]>([])

const form = reactive({
  id: 0,
  name: '',
  status: 1,
  province_name: '',
  mids: [] as number[],
  send_type: 0,
  gettjArr: ['-1'] as string[],
  reach_money: 0,
  remark: '',
})

// 发放人远程搜索
const memberOptions = ref<MemberItem[]>([])
const memberSearching = ref(false)

async function searchMembers(keyword: string) {
  memberSearching.value = true
  try {
    const data = await getMemberList({ keyword: keyword || undefined, page: 1, pagesize: 20 })
    memberOptions.value = data.list || []
  } catch {
    // ignore
  } finally {
    memberSearching.value = false
  }
}

function openAdd() {
  form.id = 0
  form.name = ''
  form.status = 1
  form.province_name = ''
  form.mids = []
  form.send_type = 0
  form.gettjArr = ['-1']
  form.reach_money = 0
  form.remark = ''
  dialogVisible.value = true
  searchMembers('')
}

function openEdit(row: AreareachPoolItem) {
  form.id = row.id
  form.name = row.name
  form.status = row.status
  form.province_name = row.province_name
  form.mids = String(row.mids || '')
    .split(',')
    .filter(Boolean)
    .map(Number)
  form.send_type = row.send_type
  form.gettjArr = String(row.gettj || '-1').split(',').filter(Boolean)
  form.reach_money = Number(row.reach_money)
  form.remark = row.remark || ''
  dialogVisible.value = true
  searchMembers('')
}

async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写名称')
    return
  }
  if (isArea && !form.province_name) {
    ElMessage.warning('请选择省份')
    return
  }
  if (isArea && form.mids.length === 0) {
    ElMessage.warning('请选择发放人')
    return
  }
  saving.value = true
  try {
    await savePool({
      id: form.id,
      type: props.poolType,
      name: form.name.trim(),
      status: form.status,
      province_name: form.province_name,
      mids: form.mids,
      send_type: form.send_type,
      gettj: form.gettjArr.join(','),
      reach_money: form.reach_money,
      remark: form.remark,
    })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // ignore
  } finally {
    saving.value = false
  }
}

async function handleDelete(row: AreareachPoolItem) {
  try {
    await ElMessageBox.confirm(`确定删除「${row.name}」？`, '删除确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await deletePool(row.id)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // ignore
  }
}

// ===== 入池日志抽屉 =====
const logDrawer = ref(false)
const logLoading = ref(false)
const logData = ref<PrizePoolOrderLogItem[]>([])
const logTotal = ref(0)
const logQuery = reactive({ page: 1, pagesize: 10, pool_id: 0 })

async function openLog(poolId = 0) {
  logQuery.pool_id = poolId
  logQuery.page = 1
  logDrawer.value = true
  fetchLog()
}

async function fetchLog() {
  logLoading.value = true
  try {
    const data = await getPoolOrderLog({
      type: props.poolType,
      pool_id: logQuery.pool_id || undefined,
      page: logQuery.page,
      pagesize: logQuery.pagesize,
    })
    logData.value = data.list || []
    logTotal.value = data.total || 0
  } catch {
    // ignore
  } finally {
    logLoading.value = false
  }
}

function sendTypeText(v: number): string {
  return v === 1 ? '按月' : '按周'
}

onMounted(async () => {
  fetchList()
  try {
    const [p, l] = await Promise.all([getProvinces(), getMemberLevelList()])
    provinces.value = p.list || []
    levels.value = l.list || []
  } catch {
    // ignore
  }
})
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-row">
        <div class="page-title">{{ pageTitle }}</div>
        <div>
          <el-button @click="openLog(0)">入池记录</el-button>
          <el-button type="primary" @click="openAdd">新增</el-button>
        </div>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column prop="name" label="名称" min-width="110" show-overflow-tooltip />
        <el-table-column v-if="isArea" label="省份" width="90" class-name="compact-col">
          <template #default="{ row }">{{ row.province_name || '-' }}</template>
        </el-table-column>
        <el-table-column v-if="isArea" label="发放人" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.mids_text || row.mids || '-' }}</template>
        </el-table-column>
        <el-table-column v-if="!isArea" label="达标金额" width="96" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.reach_money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column v-if="!isArea" label="参与等级" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.gettj === '-1' || !row.gettj ? '全部' : row.gettj }}
          </template>
        </el-table-column>
        <el-table-column label="池金额" width="100" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="发放周期" width="84" class-name="compact-col">
          <template #default="{ row }">
            <el-tag size="small" :type="row.send_type === 1 ? 'warning' : 'primary'">{{ sendTypeText(row.send_type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="72" class-name="compact-col">
          <template #default="{ row }">
            <el-tag size="small" :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '开启' : '关闭' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="primary" size="small" @click="openLog(row.id)">入池记录</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, prev, pager, next"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        @current-change="(p: number) => { query.page = p; fetchList() }"
      />
    </el-card>

    <!-- 新增/编辑 -->
    <el-dialog v-model="dialogVisible" :title="(form.id ? '编辑' : '新增') + pageTitle" width="560px">
      <el-form label-width="100px">
        <el-form-item label="名称">
          <el-input v-model="form.name" placeholder="池名称" maxlength="60" style="width: 280px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" />
        </el-form-item>
        <el-form-item v-if="isArea" label="省份">
          <el-select v-model="form.province_name" placeholder="收货省匹配" filterable style="width: 200px">
            <el-option v-for="p in provinces" :key="p.id" :label="p.name" :value="p.name" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="isArea" label="发放人">
          <el-select
            v-model="form.mids"
            multiple
            filterable
            remote
            reserve-keyword
            placeholder="搜索昵称/手机号"
            :remote-method="searchMembers"
            :loading="memberSearching"
            style="width: 100%"
          >
            <el-option v-for="m in memberOptions" :key="m.id" :label="`${m.nickname}（ID:${m.id}）`" :value="m.id" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="!isArea" label="达标金额">
          <el-input-number v-model="form.reach_money" :min="0" :precision="2" style="width: 180px" />
          <span class="form-tip">周期内累计确认收货金额达标即参与平分</span>
        </el-form-item>
        <el-form-item v-if="!isArea" label="参与等级">
          <el-select v-model="form.gettjArr" multiple collapse-tags style="width: 100%">
            <el-option label="全部等级" value="-1" />
            <el-option v-for="lv in levels" :key="lv.id" :label="lv.name" :value="String(lv.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="发放周期">
          <el-radio-group v-model="form.send_type">
            <el-radio :value="0">按周（周一结算上周）</el-radio>
            <el-radio :value="1">按月（1日结算上月）</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" maxlength="200" style="width: 360px" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- 入池记录 -->
    <el-drawer v-model="logDrawer" title="入池记录" size="620px">
      <el-table v-loading="logLoading" :data="logData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column label="订单号" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.ordernum || (row.orderid ? `订单${row.orderid}` : '-') }}</template>
        </el-table-column>
        <el-table-column label="入池金额" width="96" class-name="compact-col">
          <template #default="{ row }">
            <span class="money-plus">+{{ Number(row.money).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="入池前" width="96" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.before_money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip />
        <el-table-column label="时间" width="135" class-name="compact-col">
          <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pagination"
        background
        layout="total, prev, pager, next"
        :total="logTotal"
        :current-page="logQuery.page"
        :page-size="logQuery.pagesize"
        @current-change="(p: number) => { logQuery.page = p; fetchLog() }"
      />
    </el-drawer>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 12px;
}

.toolbar-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.page-title {
  font-weight: 600;
  font-size: 15px;
}

.form-tip {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
}

.money-plus {
  color: #67c23a;
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
