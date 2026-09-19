<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteKouling,
  getKoulingDetail,
  getKoulingList,
  getKoulingRecords,
  getKoulingSet,
  saveKouling,
  saveKoulingSet,
} from '../../api/marketing'
import type {
  KoulingItem,
  KoulingRecordItem,
  KoulingSetData,
  KoulingType,
} from '../../api/marketing'
import { getCouponManageList } from '../../api/couponManage'

const activeTab = ref('kouling')

// ============ 页面设置 ============
const setForm = reactive<KoulingSetData>({ title: '口令游戏', bgpic: '', guize: '', status: 0 })

async function fetchSet() {
  try {
    const data = await getKoulingSet()
    Object.assign(setForm, data)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSaveSet() {
  try {
    await saveKoulingSet({ ...setForm })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 优惠券选项（供赠送选择） ============
const couponOptions = ref<{ id: number; name: string }[]>([])

async function fetchCoupons() {
  try {
    const data = await getCouponManageList({ page: 1, pagesize: 100 })
    couponOptions.value = (data.list || []).map((item) => ({
      id: Number(item.id),
      name: String(item.name),
    }))
  } catch {
    // 静默
  }
}

// ============ 活动列表（kouling/riddle 共用） ============
const loading = ref(false)
const tableData = ref<KoulingItem[]>([])
const total = ref(0)
const selection = ref<KoulingItem[]>([])

const query = reactive({ keyword: '', page: 1, pagesize: 10 })

function currentType(): KoulingType {
  return activeTab.value === 'riddle' ? 'riddle' : 'kouling'
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getKoulingList({
      type: currentType(),
      keyword: query.keyword,
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

function handleTabChange() {
  query.keyword = ''
  query.page = 1
  if (activeTab.value === 'kouling' || activeTab.value === 'riddle') {
    fetchList()
  } else if (activeTab.value === 'records') {
    fetchRecords()
  } else if (activeTab.value === 'set') {
    fetchSet()
  }
}

function handleSelectionChange(rows: KoulingItem[]) {
  selection.value = rows
}

// ============ 新增/编辑弹窗 ============
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<Record<string, unknown>>({})

function resetForm() {
  Object.keys(form).forEach((key) => delete form[key])
  Object.assign(form, {
    id: 0,
    name: '',
    title: '',
    alias: '谜底',
    content: '',
    timerange: [] as string[],
    status: 1,
    gettj: ['-1'],
    num: 0,
    perday: 0,
    pertotal: 0,
    give_coupon: 0,
    coupon_ids: [] as number[],
    money: 0,
    score: 0,
  })
}

function handleAdd() {
  resetForm()
  dialogVisible.value = true
}

async function handleEdit(row: KoulingItem) {
  resetForm()
  try {
    const detail = await getKoulingDetail(currentType(), row.id)
    Object.assign(form, {
      id: detail.id,
      name: detail.name,
      title: detail.title || '',
      alias: detail.alias || '谜底',
      content: detail.content || '',
      timerange:
        detail.starttime > 0 && detail.endtime > 0
          ? [
              new Date(detail.starttime * 1000).toLocaleString('sv-SE').replace('T', ' '),
              new Date(detail.endtime * 1000).toLocaleString('sv-SE').replace('T', ' '),
            ]
          : [],
      status: detail.status,
      gettj: Array.isArray(detail.gettj) ? detail.gettj.map(String) : ['-1'],
      num: detail.num,
      perday: detail.perday,
      pertotal: detail.pertotal,
      give_coupon: detail.give_coupon,
      coupon_ids: Array.isArray(detail.coupon_ids)
        ? detail.coupon_ids.map((v) => Number(v))
        : [],
      money: detail.money,
      score: detail.score,
    })
    dialogVisible.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function stampOf(value: unknown): number {
  if (!value) return 0
  const stamp = new Date(String(value).replace(/-/g, '/')).getTime()
  return Number.isNaN(stamp) ? 0 : Math.floor(stamp / 1000)
}

async function handleSave() {
  const timerange = (form.timerange as string[]) || []
  saving.value = true
  try {
    await saveKouling({
      type: currentType(),
      id: form.id,
      name: form.name,
      title: form.title,
      alias: form.alias,
      content: form.content,
      starttime: stampOf(timerange[0]),
      endtime: stampOf(timerange[1]),
      status: form.status,
      gettj: form.gettj,
      num: form.num,
      perday: form.perday,
      pertotal: form.pertotal,
      give_coupon: form.give_coupon,
      coupon_ids: form.coupon_ids,
      money: form.money,
      score: form.score,
    })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

function handleDelete(row?: KoulingItem) {
  const ids = row ? [row.id] : selection.value.map((item) => item.id)
  if (ids.length === 0) {
    ElMessage.warning('请选择活动')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个活动及其参与记录吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteKouling(currentType(), ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 参与记录 ============
const recordLoading = ref(false)
const recordData = ref<KoulingRecordItem[]>([])
const recordTotal = ref(0)
const recordQuery = reactive({
  type: 'kouling' as KoulingType,
  nickname: '',
  objid: 0,
  page: 1,
  pagesize: 10,
})

async function fetchRecords() {
  recordLoading.value = true
  try {
    const data = await getKoulingRecords({
      type: recordQuery.type,
      objid: recordQuery.objid > 0 ? recordQuery.objid : undefined,
      nickname: recordQuery.nickname,
      page: recordQuery.page,
      pagesize: recordQuery.pagesize,
    })
    recordData.value = data.list || []
    recordTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    recordLoading.value = false
  }
}

function handleRecordSearch() {
  recordQuery.page = 1
  fetchRecords()
}

function gettjText(value: unknown): string {
  const list = Array.isArray(value) ? value : String(value ?? '-1').split(',')
  if (list.includes('-1')) return '不限'
  return list
    .map((v) => (String(v) === '0' ? '关注公众号' : `等级${v}`))
    .join('、')
}

onMounted(() => {
  fetchList()
  fetchCoupons()
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane label="口令活动" name="kouling" />
        <el-tab-pane label="谜语活动" name="riddle" />
        <el-tab-pane label="参与记录" name="records" />
        <el-tab-pane label="页面设置" name="set" />
      </el-tabs>

      <!-- 活动列表（口令/谜语） -->
      <template v-if="activeTab === 'kouling' || activeTab === 'riddle'">
        <div class="toolbar">
          <el-input
            v-model="query.keyword"
            :placeholder="activeTab === 'riddle' ? '搜索谜底' : '搜索口令'"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <div class="toolbar-right">
            <el-button type="primary" @click="handleAdd">新增</el-button>
            <el-button type="danger" plain :disabled="selection.length === 0" @click="handleDelete()">
              批量删除
            </el-button>
          </div>
        </div>

        <el-table v-loading="loading" :data="tableData" stripe @selection-change="handleSelectionChange">
          <el-table-column type="selection" width="40" />
          <el-table-column label="名称" min-width="140">
            <template #default="{ row }">
              <div>{{ row.name }}</div>
              <div v-if="activeTab === 'riddle' && row.title" class="text-muted">
                谜面：{{ row.title }}
              </div>
            </template>
          </el-table-column>
          <el-table-column label="活动时间" min-width="240">
            <template #default="{ row }">
              {{ row.starttime_text }} ~ {{ row.endtime_text }}
            </template>
          </el-table-column>
          <el-table-column label="参与人群" min-width="120">
            <template #default="{ row }">{{ gettjText(row.gettj) }}</template>
          </el-table-column>
          <el-table-column label="已领/总量" width="110">
            <template #default="{ row }">{{ row.zjnum }} / {{ row.num || '不限' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="80">
            <template #default="{ row }">
              <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
                {{ row.status === 1 ? '开启' : '关闭' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="130" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="handleEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
            </template>
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
          @current-change="(p: number) => ((query.page = p), fetchList())"
          @size-change="(s: number) => ((query.pagesize = s), (query.page = 1), fetchList())"
        />
      </template>

      <!-- 参与记录 -->
      <template v-if="activeTab === 'records'">
        <div class="toolbar">
          <el-select v-model="recordQuery.type" style="width: 120px" @change="handleRecordSearch">
            <el-option label="口令记录" value="kouling" />
            <el-option label="谜语记录" value="riddle" />
          </el-select>
          <el-input-number
            v-model="recordQuery.objid"
            :min="0"
            placeholder="活动ID"
            controls-position="right"
            style="width: 120px"
          />
          <el-input
            v-model="recordQuery.nickname"
            placeholder="会员昵称"
            clearable
            style="width: 160px"
            @keyup.enter="handleRecordSearch"
          />
          <el-button type="primary" @click="handleRecordSearch">搜索</el-button>
        </div>

        <el-table v-loading="recordLoading" :data="recordData" stripe>
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column label="会员" min-width="140">
            <template #default="{ row }">
              <div class="member-cell">
                <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
                <span>{{ row.nickname || `会员${row.mid}` }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="活动" min-width="140">
            <template #default="{ row }">#{{ row.objid }} {{ row.name }}</template>
          </el-table-column>
          <el-table-column label="奖励" min-width="160">
            <template #default="{ row }">
              <span v-if="Number(row.money) > 0">余额￥{{ row.money }} </span>
              <span v-if="Number(row.score) > 0">积分{{ row.score }} </span>
              <span v-if="row.coupon_ids">优惠券</span>
            </template>
          </el-table-column>
          <el-table-column prop="createtime_text" label="参与时间" width="170" />
        </el-table>

        <el-pagination
          class="pagination"
          background
          layout="total, sizes, prev, pager, next"
          :total="recordTotal"
          :current-page="recordQuery.page"
          :page-size="recordQuery.pagesize"
          :page-sizes="[10, 20, 50]"
          @current-change="(p: number) => ((recordQuery.page = p), fetchRecords())"
          @size-change="(s: number) => ((recordQuery.pagesize = s), (recordQuery.page = 1), fetchRecords())"
        />
      </template>

      <!-- 页面设置 -->
      <template v-if="activeTab === 'set'">
        <el-form label-width="120px" style="max-width: 560px">
          <el-form-item label="页面标题">
            <el-input v-model="setForm.title" placeholder="口令游戏" />
          </el-form-item>
          <el-form-item label="背景图">
            <el-input v-model="setForm.bgpic" placeholder="背景图 URL" />
          </el-form-item>
          <el-form-item label="活动规则">
            <el-input v-model="setForm.guize" type="textarea" :rows="4" placeholder="活动规则说明" />
          </el-form-item>
          <el-form-item label="功能状态">
            <el-switch v-model="setForm.status" :active-value="1" :inactive-value="0" />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="handleSaveSet">保存设置</el-button>
          </el-form-item>
        </el-form>
      </template>
    </el-card>

    <!-- 新增/编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="Number(form.id) > 0 ? '编辑活动' : '新增活动'"
      width="600px"
    >
      <el-form label-width="110px">
        <el-form-item :label="activeTab === 'riddle' ? '谜底' : '口令'" required>
          <el-input v-model="form.name" :placeholder="activeTab === 'riddle' ? '请输入谜底' : '请输入口令'" />
        </el-form-item>
        <template v-if="activeTab === 'riddle'">
          <el-form-item label="谜面标题">
            <el-input v-model="form.title" placeholder="如：猜谜语" />
          </el-form-item>
          <el-form-item label="谜底别名">
            <el-input v-model="form.alias" placeholder="默认：谜底" />
          </el-form-item>
          <el-form-item label="谜面内容">
            <el-input v-model="form.content" type="textarea" :rows="2" placeholder="谜面描述" />
          </el-form-item>
        </template>
        <el-form-item label="活动时间" required>
          <el-date-picker
            v-model="form.timerange"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="参与人群">
          <el-select
            v-model="form.gettj"
            multiple
            filterable
            allow-create
            default-first-option
            style="width: 100%"
            placeholder="-1 不限 / 0 关注公众号 / 输入等级ID"
          >
            <el-option label="不限" value="-1" />
            <el-option label="关注公众号" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="奖品总量">
          <el-input-number v-model="form.num" :min="0" controls-position="right" />
          <span class="form-tip">0 为不限量</span>
        </el-form-item>
        <el-form-item label="每日每人限次">
          <el-input-number v-model="form.perday" :min="0" controls-position="right" />
          <span class="form-tip">0 为不限</span>
        </el-form-item>
        <el-form-item label="总参与限次">
          <el-input-number v-model="form.pertotal" :min="0" controls-position="right" />
          <span class="form-tip">0 为不限</span>
        </el-form-item>
        <el-form-item label="赠送优惠券">
          <el-switch v-model="form.give_coupon" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item v-if="form.give_coupon === 1" label="选择优惠券">
          <el-select v-model="form.coupon_ids" multiple style="width: 100%" placeholder="选择赠送的优惠券">
            <el-option v-for="c in couponOptions" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="奖励余额">
          <el-input-number v-model="form.money" :min="0" :precision="2" controls-position="right" />
        </el-form-item>
        <el-form-item label="奖励积分">
          <el-input-number v-model="form.score" :min="0" controls-position="right" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
}

.toolbar-right {
  margin-left: auto;
  display: flex;
  gap: 8px;
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

.text-muted {
  color: #909399;
  font-size: 12px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
