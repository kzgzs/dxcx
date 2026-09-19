<script setup lang="ts">
/**
 * 餐饮 - 营销 - 优惠券（对照参考系统 restaurant_coupon）
 * 读写 coupon / coupon_record（type=5 餐饮券 + bid=0），不建新表
 * 列表 + 编辑 + 复制 + 领取记录（核销/恢复/删除） + 发送会员
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  copyRestaurantCoupon,
  deleteRestaurantCoupon,
  deleteRestaurantCouponRecord,
  getRestaurantCouponDetail,
  getRestaurantCouponList,
  getRestaurantCouponRecord,
  saveRestaurantCoupon,
  sendRestaurantCoupon,
  setRestaurantCouponRecordStatus,
  type RestaurantCouponRecordRow,
  type RestaurantCouponRow,
} from '../../api/restaurantExt'
import { getMemberList } from '../../api/member'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<RestaurantCouponRow[]>([])
const total = ref(0)
const query = reactive({ page: 1, limit: 10, name: '' })

async function fetchList() {
  loading.value = true
  try {
    const data = await getRestaurantCouponList({
      page: query.page,
      limit: query.limit,
      name: query.name || undefined,
    })
    tableData.value = data.list || []
    total.value = data.count || 0
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

function statusTag(status: number): 'info' | 'success' | 'danger' {
  if (status === 1) return 'success'
  if (status === 2) return 'danger'
  return 'info'
}

function handleDelete(row: RestaurantCouponRow) {
  ElMessageBox.confirm(`确定删除优惠券「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteRestaurantCoupon([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

async function handleCopy(row: RestaurantCouponRow) {
  try {
    await copyRestaurantCoupon(row.id)
    ElMessage.success('复制成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 编辑 ============
const editVisible = ref(false)
const editSaving = ref(false)
const levels = ref<{ id: number; name: string }[]>([])
const form = reactive<Record<string, unknown>>({})

function resetForm() {
  Object.assign(form, {
    id: 0,
    name: '',
    money: 0,
    minprice: 0,
    stock: 100,
    perlimit: 1,
    starttime: '',
    endtime: '',
    yxqtype: 1,
    yxqdate: 7,
    gettj: ['-1'],
    fwtype: 0,
    categoryids: [],
    productids: [],
    gettips: '',
    usetips: '',
    sort: 0,
  })
}

async function openEdit(id: number) {
  resetForm()
  try {
    const data = await getRestaurantCouponDetail(id)
    const info = data.info || ({} as RestaurantCouponRow)
    Object.assign(form, info)
    levels.value = info.levels || []
    if (!Array.isArray(form.gettj)) form.gettj = ['-1']
    if (!Array.isArray(form.categoryids)) form.categoryids = []
    if (!Array.isArray(form.productids)) form.productids = []
    editVisible.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function submitEdit() {
  if (!String(form.name || '').trim()) {
    ElMessage.warning('请填写优惠券名称')
    return
  }
  editSaving.value = true
  try {
    await saveRestaurantCoupon({ ...form })
    ElMessage.success('保存成功')
    editVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    editSaving.value = false
  }
}

// ============ 领取记录 ============
const recordVisible = ref(false)
const recordLoading = ref(false)
const recordCoupon = ref<RestaurantCouponRow | null>(null)
const recordList = ref<RestaurantCouponRecordRow[]>([])
const recordTotal = ref(0)
const recordQuery = reactive({ page: 1, limit: 10, nickname: '' })
const recordSelection = ref<RestaurantCouponRecordRow[]>([])

async function openRecord(row: RestaurantCouponRow) {
  recordCoupon.value = row
  recordQuery.page = 1
  recordQuery.nickname = ''
  recordVisible.value = true
  await fetchRecord()
}

async function fetchRecord() {
  if (!recordCoupon.value) return
  recordLoading.value = true
  try {
    const data = await getRestaurantCouponRecord({
      couponid: recordCoupon.value.id,
      page: recordQuery.page,
      limit: recordQuery.limit,
      nickname: recordQuery.nickname || undefined,
    })
    recordList.value = data.list || []
    recordTotal.value = data.count || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    recordLoading.value = false
  }
}

function recordStatusText(row: RestaurantCouponRecordRow): string {
  if (row.status === 1) return '已使用'
  if (row.status === 2) return '已过期'
  return '未使用'
}

async function handleRecordSetst(rows: RestaurantCouponRecordRow[], st: number) {
  if (!rows.length) {
    ElMessage.warning('请先选择记录')
    return
  }
  try {
    await setRestaurantCouponRecordStatus(rows.map((r) => r.id), st)
    ElMessage.success('操作成功')
    fetchRecord()
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleRecordDel(rows: RestaurantCouponRecordRow[]) {
  if (!rows.length) {
    ElMessage.warning('请先选择记录')
    return
  }
  ElMessageBox.confirm(`确定删除所选 ${rows.length} 条领取记录吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteRestaurantCouponRecord(rows.map((r) => r.id))
        ElMessage.success('删除成功')
        fetchRecord()
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 发送会员 ============
const sendVisible = ref(false)
const sendSaving = ref(false)
const sendCoupon = ref<RestaurantCouponRow | null>(null)
const sendNum = ref(1)
const sendMids = ref<number[]>([])
const memberLoading = ref(false)
const memberOptions = ref<{ id: number; nickname: string }[]>([])

function openSend(row: RestaurantCouponRow) {
  sendCoupon.value = row
  sendNum.value = 1
  sendMids.value = []
  memberOptions.value = []
  sendVisible.value = true
  searchMembers('')
}

async function searchMembers(keyword: string) {
  memberLoading.value = true
  try {
    const data = await getMemberList({ page: 1, pagesize: 20, keyword: keyword || undefined })
    memberOptions.value = (data.list || []).map((m) => ({ id: m.id, nickname: m.nickname }))
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    memberLoading.value = false
  }
}

async function submitSend() {
  if (!sendMids.value.length) {
    ElMessage.warning('请选择发送会员')
    return
  }
  sendSaving.value = true
  try {
    const data = await sendRestaurantCoupon(sendCoupon.value!.id, sendMids.value, sendNum.value)
    ElMessage.success(`发送完成：成功 ${data.sucnum} 张，失败 ${data.errnum} 张`)
    sendVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    sendSaving.value = false
  }
}

onMounted(fetchList)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="rx-header">
        <span class="rx-title">餐饮优惠券</span>
        <span class="rx-tip">餐饮域专用券（type=5），领取记录与商城券中心同表隔离</span>
        <el-button type="primary" class="rx-add" @click="openEdit(0)">新增优惠券</el-button>
      </div>
    </template>

    <div class="rx-toolbar">
      <el-input v-model="query.name" placeholder="券名称关键字" clearable style="width: 200px" @keyup.enter="handleSearch" />
      <el-button type="primary" @click="handleSearch">搜索</el-button>
    </div>

    <el-table :data="tableData" v-loading="loading" stripe>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="券名称" min-width="160" show-overflow-tooltip />
      <el-table-column label="面额" width="90">
        <template #default="{ row }">￥{{ row.money }}</template>
      </el-table-column>
      <el-table-column label="使用门槛" width="110">
        <template #default="{ row }">{{ row.minprice > 0 ? `满${row.minprice}元` : '无门槛' }}</template>
      </el-table-column>
      <el-table-column label="库存/已领/已用" width="130">
        <template #default="{ row }">{{ row.stock }} / {{ row.getnum }} / {{ row.used }}</template>
      </el-table-column>
      <el-table-column label="领取时间" min-width="180">
        <template #default="{ row }">{{ row.starttime }} ~ {{ row.endtime }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="statusTag(row.status || 0)" size="small">{{ row.status_text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="70" />
      <el-table-column label="操作" width="300" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row.id)">编辑</el-button>
          <el-button link type="primary" @click="openRecord(row)">领取记录</el-button>
          <el-button link type="primary" @click="openSend(row)">发送</el-button>
          <el-button link type="primary" @click="handleCopy(row)">复制</el-button>
          <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
      <template #empty>
        <el-empty description="暂无优惠券" />
      </template>
    </el-table>

    <div class="rx-pager">
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.limit"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </div>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editVisible" :title="form.id ? '编辑优惠券' : '新增优惠券'" width="680px" :close-on-click-modal="false">
      <el-form label-width="120px">
        <el-form-item label="券名称：">
          <el-input v-model="form.name" placeholder="如：新客立减券" style="width: 300px" />
        </el-form-item>
        <el-form-item label="面额：">
          <el-input-number v-model="form.money" :min="0" :precision="2" />
          <span class="rx-inline">元</span>
        </el-form-item>
        <el-form-item label="使用门槛：">
          <el-input-number v-model="form.minprice" :min="0" :precision="2" />
          <span class="rx-inline">元（0 为无门槛）</span>
        </el-form-item>
        <el-form-item label="库存：">
          <el-input-number v-model="form.stock" :min="0" :max="999999" />
          <span class="rx-inline">张</span>
        </el-form-item>
        <el-form-item label="每人限领：">
          <el-input-number v-model="form.perlimit" :min="1" :max="99" />
          <span class="rx-inline">张</span>
        </el-form-item>
        <el-form-item label="领取时间：">
          <el-date-picker
            v-model="form.starttime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="开始时间"
            style="width: 190px"
          />
          <span class="rx-inline">至</span>
          <el-date-picker
            v-model="form.endtime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="结束时间"
            style="width: 190px"
          />
        </el-form-item>
        <el-form-item label="有效期：">
          <el-radio-group v-model="form.yxqtype">
            <el-radio :value="1">按领取时间段</el-radio>
            <el-radio :value="2">领取后N天</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.yxqtype === 2" label="有效天数：">
          <el-input-number v-model="form.yxqdate" :min="0" :max="365" />
          <span class="rx-inline">天</span>
        </el-form-item>
        <el-form-item label="领取条件：">
          <el-select v-model="form.gettj" multiple placeholder="不限" style="width: 300px">
            <el-option label="不限等级" value="-1" />
            <el-option v-for="lv in levels" :key="lv.id" :label="lv.name" :value="String(lv.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="领取提示：">
          <el-input v-model="form.gettips" placeholder="领取成功提示文案" style="width: 300px" />
        </el-form-item>
        <el-form-item label="使用说明：">
          <el-input v-model="form.usetips" type="textarea" :rows="2" placeholder="券使用说明" />
        </el-form-item>
        <el-form-item label="排序：">
          <el-input-number v-model="form.sort" :min="0" :max="9999" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="editSaving" @click="submitEdit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 领取记录弹窗 -->
    <el-dialog v-model="recordVisible" :title="`领取记录 - ${recordCoupon?.name || ''}`" width="860px">
      <div class="rx-toolbar">
        <el-input
          v-model="recordQuery.nickname"
          placeholder="会员昵称"
          clearable
          style="width: 200px"
          @keyup.enter="fetchRecord"
        />
        <el-button type="primary" @click="fetchRecord">搜索</el-button>
        <div class="rx-toolbar-right">
          <el-button @click="handleRecordSetst(recordSelection, 1)">批量核销</el-button>
          <el-button @click="handleRecordSetst(recordSelection, 0)">恢复未使用</el-button>
          <el-button type="danger" plain @click="handleRecordDel(recordSelection)">批量删除</el-button>
        </div>
      </div>
      <el-table :data="recordList" v-loading="recordLoading" stripe @selection-change="(rows: RestaurantCouponRecordRow[]) => (recordSelection = rows)">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="会员" min-width="130">
          <template #default="{ row }">
            <div class="rx-member">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="26" />
              <span>{{ row.nickname || '会员' + row.mid }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="面额" width="90">
          <template #default="{ row }">￥{{ row.money }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : row.status === 2 ? 'danger' : 'info'" size="small">
              {{ recordStatusText(row) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="领取时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="使用时间" width="150">
          <template #default="{ row }">{{ row.usetime ? formatTime(row.usetime) : '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.status === 0" link type="primary" @click="handleRecordSetst([row], 1)">核销</el-button>
            <el-button v-else-if="row.status === 1" link type="warning" @click="handleRecordSetst([row], 0)">恢复</el-button>
            <el-button link type="danger" @click="handleRecordDel([row])">删除</el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无领取记录" />
        </template>
      </el-table>
      <div class="rx-pager">
        <el-pagination
          v-model:current-page="recordQuery.page"
          :total="recordTotal"
          :page-size="recordQuery.limit"
          layout="total, prev, pager, next"
          @current-change="fetchRecord"
        />
      </div>
    </el-dialog>

    <!-- 发送弹窗 -->
    <el-dialog v-model="sendVisible" :title="`发送优惠券 - ${sendCoupon?.name || ''}`" width="520px" :close-on-click-modal="false">
      <el-form label-width="90px">
        <el-form-item label="选择会员：">
          <el-select
            v-model="sendMids"
            multiple
            filterable
            remote
            reserve-keyword
            placeholder="输入昵称搜索会员"
            :remote-method="searchMembers"
            :loading="memberLoading"
            style="width: 100%"
          >
            <el-option v-for="m in memberOptions" :key="m.id" :label="m.nickname" :value="m.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="每人张数：">
          <el-input-number v-model="sendNum" :min="1" :max="10" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="sendVisible = false">取消</el-button>
        <el-button type="primary" :loading="sendSaving" @click="submitSend">发送</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<style scoped>
.rx-header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.rx-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.rx-tip {
  font-size: 12px;
  color: #909399;
}

.rx-add {
  margin-left: auto;
}

.rx-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.rx-toolbar-right {
  margin-left: auto;
  display: flex;
  gap: 8px;
}

.rx-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.rx-inline {
  margin-left: 8px;
  color: #909399;
}

.rx-member {
  display: flex;
  align-items: center;
  gap: 6px;
}
</style>
