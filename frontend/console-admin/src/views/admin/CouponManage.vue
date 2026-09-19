<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  copyCouponManage,
  deleteCouponManage,
  getCouponManageDetail,
  getCouponManageList,
  getCouponSet,
  saveCouponManage,
  saveCouponSet,
  sendCoupon,
  setCouponManageStatus,
} from '../../api/couponManage'
import { getCategoryList, getGoodsList } from '../../api/goods'
import { getMemberLevelList, getMemberList } from '../../api/member'
import type {
  CategoryItem,
  CouponManageItem,
  CouponSetData,
  GoodsItem,
  MemberItem,
  MemberLevelItem,
} from '../../api/types'
import { formatTime } from '../../utils/time'

// 券类型（对齐参考系统 getCouponTypeTxt 口径）
const TYPE_OPTIONS = [
  { value: 1, label: '代金券' },
  { value: 10, label: '折扣券' },
  { value: 4, label: '运费抵扣券' },
  { value: 11, label: '兑换券' },
  { value: 2, label: '礼品券' },
  { value: 3, label: '计次券' },
]
const TYPE_TAG: Record<number, '' | 'success' | 'warning' | 'info' | 'danger'> = {
  1: 'danger',
  10: 'warning',
  4: 'success',
  11: 'info',
  2: '',
  3: 'info',
}

// ============ 视图切换（列表 / 编辑铺满主区域） ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<CouponManageItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  type: '' as number | '',
  status: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCouponManageList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      type: query.type === '' ? undefined : query.type,
      status: query.status === '' ? undefined : query.status,
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
  query.status = ''
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

// 面值文案
function benefitText(row: CouponManageItem) {
  if (row.type === 10) return `${Number(row.discount)}折`
  if (row.type === 3) return `${row.limit_count}次`
  if (row.type === 1 || row.type === 4) return `抵${Number(row.money)}元`
  return row.type_txt
}

// 有效期文案
function periodText(row: CouponManageItem) {
  if (row.yxqtype === 2) return `领取后${row.yxqdate}天`
  if (!row.starttime && !row.endtime) return '长期有效'
  return `${row.starttime || '…'} ~ ${row.endtime || '…'}`
}

const PHASE_TEXT: Record<string, { label: string; type: '' | 'success' | 'info' | 'danger' }> = {
  not_started: { label: '未开始', type: 'info' },
  ongoing: { label: '进行中', type: 'success' },
  ended: { label: '已结束', type: 'danger' },
}

// ============ 状态开关 ============
async function handleStatusChange(row: CouponManageItem) {
  try {
    await setCouponManageStatus(row.id, row.status)
    ElMessage.success(row.status === 1 ? '已启用' : '已停用')
  } catch {
    row.status = row.status === 1 ? 0 : 1
  }
}

// ============ 删除 / 复制 ============
function handleDelete(row: CouponManageItem) {
  ElMessageBox.confirm(`确定要删除优惠券「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCouponManage(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

async function handleCopy(row: CouponManageItem) {
  try {
    await copyCouponManage(row.id)
    ElMessage.success('复制成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 编辑表单（铺满主区域，紧凑行距） ============
const formRef = ref<FormInstance>()
const submitting = ref(false)
const isEdit = ref(false)
const coreLocked = ref(false) // 已领取后锁定核心面值字段

const form = reactive({
  id: 0,
  name: '',
  type: 1,
  money: 0,
  discount: 8.5,
  minprice: 0,
  stock: 100,
  perlimit: 1,
  yxqtype: 1,
  yxqdate: 7,
  yxqtime: '',
  timeRange: null as [Date, Date] | null,
  gettjArr: ['-1'] as string[],
  isgive: 0,
  tolist: 1,
  fwtype: 0,
  categoryArr: [] as number[],
  productArr: [] as number[],
  price: 0,
  score: 0,
  limit_count: 10,
  gettips: '',
  usetips: '',
  sort: 0,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入优惠券名称', trigger: 'blur' }],
}

// 等级/类目/商品候选（用于人群与适用范围选择）
const levelOptions = ref<MemberLevelItem[]>([])
const categoryOptions = ref<CategoryItem[]>([])
const goodsOptions = ref<GoodsItem[]>([])

async function loadCandidates() {
  try {
    const [levels, cats, goods] = await Promise.all([
      getMemberLevelList(),
      getCategoryList({ page: 1, pagesize: 100 }),
      getGoodsList({ page: 1, pagesize: 100 }),
    ])
    levelOptions.value = levels.list || []
    categoryOptions.value = cats.list || []
    goodsOptions.value = goods.list || []
  } catch {
    // 候选加载失败不阻断编辑
  }
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    name: '',
    type: 1,
    money: 0,
    discount: 8.5,
    minprice: 0,
    stock: 100,
    perlimit: 1,
    yxqtype: 1,
    yxqdate: 7,
    yxqtime: '',
    timeRange: null,
    gettjArr: ['-1'],
    isgive: 0,
    tolist: 1,
    fwtype: 0,
    categoryArr: [],
    productArr: [],
    price: 0,
    score: 0,
    limit_count: 10,
    gettips: '',
    usetips: '',
    sort: 0,
  })
}

function openAdd() {
  isEdit.value = false
  coreLocked.value = false
  resetForm()
  viewMode.value = 'edit'
}

async function openEdit(row: CouponManageItem) {
  isEdit.value = true
  try {
    const d = await getCouponManageDetail(row.id)
    coreLocked.value = d.getnum > 0
    Object.assign(form, {
      id: d.id,
      name: d.name,
      type: d.type,
      money: d.money,
      discount: d.discount || 8.5,
      minprice: d.minprice,
      stock: d.stock,
      perlimit: d.perlimit,
      yxqtype: d.yxqtype,
      yxqdate: d.yxqdate || 7,
      yxqtime: d.yxqtime,
      timeRange:
        d.start_stamp && d.end_stamp
          ? ([new Date(d.start_stamp * 1000), new Date(d.end_stamp * 1000)] as [Date, Date])
          : null,
      gettjArr: d.gettj ? d.gettj.split(',') : ['-1'],
      isgive: d.isgive,
      tolist: d.tolist,
      fwtype: d.fwtype,
      categoryArr: d.categoryids ? d.categoryids.split(',').map(Number) : [],
      productArr: d.productids ? d.productids.split(',').map(Number) : [],
      price: d.price,
      score: d.score,
      limit_count: d.limit_count || 10,
      gettips: d.gettips,
      usetips: d.usetips,
      sort: d.sort,
    })
    viewMode.value = 'edit'
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleCancelEdit() {
  viewMode.value = 'list'
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if (form.yxqtype === 1 && (!form.timeRange || form.timeRange.length !== 2)) {
      ElMessage.warning('请选择有效期起止时间')
      return
    }
    if (form.fwtype === 1 && form.categoryArr.length === 0) {
      ElMessage.warning('请选择指定类目')
      return
    }
    if (form.fwtype === 2 && form.productArr.length === 0) {
      ElMessage.warning('请选择指定商品')
      return
    }
    submitting.value = true
    try {
      await saveCouponManage({
        id: form.id || undefined,
        name: form.name,
        type: form.type,
        money: form.type === 1 || form.type === 4 ? form.money : 0,
        discount: form.type === 10 ? form.discount : 0,
        minprice: form.minprice,
        stock: form.stock,
        perlimit: form.perlimit,
        yxqtype: form.yxqtype,
        yxqdate: form.yxqtype === 2 ? form.yxqdate : 0,
        yxqtime: form.yxqtime,
        starttime:
          form.timeRange && form.timeRange[0]
            ? Math.floor(form.timeRange[0].getTime() / 1000)
            : 0,
        endtime:
          form.timeRange && form.timeRange[1]
            ? Math.floor(form.timeRange[1].getTime() / 1000)
            : 0,
        gettj: form.gettjArr.join(',') || '-1',
        isgive: form.isgive,
        tolist: form.tolist,
        fwtype: form.fwtype,
        categoryids: form.fwtype === 1 ? form.categoryArr.join(',') : '',
        productids: form.fwtype === 2 ? form.productArr.join(',') : '',
        price: form.price,
        score: form.score,
        limit_count: form.type === 3 ? form.limit_count : undefined,
        gettips: form.gettips,
        usetips: form.usetips,
        sort: form.sort,
      })
      ElMessage.success('保存成功')
      viewMode.value = 'list'
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

// ============ 批量发放 ============
const sendVisible = ref(false)
const sendLoading = ref(false)
const sendSubmitting = ref(false)
const sendTarget = ref<CouponManageItem | null>(null)

const sendForm = reactive({
  send_type: 0,
  mids: [] as number[],
  nickname: '',
  levelid: 0,
  ctimeRange: null as [Date, Date] | null,
  per_num: 1,
})

// 会员候选（指定会员模式：远程搜索）
const memberOptions = ref<MemberItem[]>([])
const memberSearching = ref(false)

async function searchMembers(kw: string) {
  if (!kw) {
    memberOptions.value = []
    return
  }
  memberSearching.value = true
  try {
    const data = await getMemberList({ keyword: kw, page: 1, pagesize: 20 })
    memberOptions.value = data.list || []
  } catch {
    memberOptions.value = []
  } finally {
    memberSearching.value = false
  }
}

function openSend(row: CouponManageItem) {
  sendTarget.value = row
  Object.assign(sendForm, {
    send_type: 0,
    mids: [],
    nickname: '',
    levelid: 0,
    ctimeRange: null,
    per_num: 1,
  })
  memberOptions.value = []
  sendVisible.value = true
}

async function handleSend() {
  if (!sendTarget.value) return
  if (sendForm.send_type === 0 && sendForm.mids.length === 0) {
    ElMessage.warning('请选择要发放的会员')
    return
  }
  sendSubmitting.value = true
  try {
    const res = await sendCoupon({
      coupon_id: sendTarget.value.id,
      send_type: sendForm.send_type,
      mids: sendForm.mids,
      condition:
        sendForm.send_type === 1
          ? {
              nickname: sendForm.nickname || undefined,
              levelid: sendForm.levelid || undefined,
              ctime_start:
                sendForm.ctimeRange && sendForm.ctimeRange[0]
                  ? Math.floor(sendForm.ctimeRange[0].getTime() / 1000)
                  : undefined,
              ctime_end:
                sendForm.ctimeRange && sendForm.ctimeRange[1]
                  ? Math.floor(sendForm.ctimeRange[1].getTime() / 1000)
                  : undefined,
            }
          : undefined,
      per_num: sendForm.per_num,
    })
    ElMessage.success(`发放完成：成功 ${res.sucnum} 张，失败 ${res.errnum} 张`)
    sendVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    sendSubmitting.value = false
  }
}

// ============ 过期提醒设置 ============
const setVisible = ref(false)
const setSaving = ref(false)
const setForm = reactive<CouponSetData>({ expire_status: 0, expire_rules: [] })
const expireRuleOptions = Array.from({ length: 30 }, (_, i) => i + 1)

async function openSet() {
  setVisible.value = true
  setSaving.value = false
  try {
    const data = await getCouponSet()
    setForm.expire_status = data.expire_status
    setForm.expire_rules = data.expire_rules || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSaveSet() {
  setSaving.value = true
  try {
    await saveCouponSet({
      expire_status: setForm.expire_status,
      expire_rules: setForm.expire_rules,
    })
    ElMessage.success('保存成功')
    setVisible.value = false
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    setSaving.value = false
  }
}

const benefitColumnLabel = computed(() => {
  if (form.type === 10) return '折扣值'
  if (form.type === 3) return '可用次数'
  return '抵扣金额'
})

onMounted(() => {
  fetchList()
  loadCandidates()
})
</script>

<template>
  <div>
    <!-- ============ 列表视图 ============ -->
    <template v-if="viewMode === 'list'">
      <el-card shadow="never" class="toolbar-card">
        <el-form inline @submit.prevent>
          <el-form-item label="关键词">
            <el-input
              v-model="query.keyword"
              placeholder="优惠券名称"
              clearable
              style="width: 180px"
              @keyup.enter="handleSearch"
            />
          </el-form-item>
          <el-form-item label="类型">
            <el-select v-model="query.type" placeholder="全部" clearable style="width: 130px">
              <el-option
                v-for="t in TYPE_OPTIONS"
                :key="t.value"
                :label="t.label"
                :value="t.value"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
              <el-option label="启用" :value="1" />
              <el-option label="停用" :value="0" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="handleSearch">搜索</el-button>
            <el-button @click="handleReset">重置</el-button>
          </el-form-item>
          <el-form-item style="float: right">
            <el-button @click="openSet">过期提醒设置</el-button>
            <el-button type="success" @click="openAdd">新增优惠券</el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-table v-loading="loading" :data="tableData" stripe>
          <el-table-column prop="id" label="ID" width="64" />
          <el-table-column prop="name" label="名称" min-width="130" show-overflow-tooltip />
          <el-table-column label="类型" width="104">
            <template #default="{ row }">
              <el-tag :type="TYPE_TAG[row.type] || 'info'" size="small">{{ row.type_txt }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="面值" width="90">
            <template #default="{ row }">{{ benefitText(row) }}</template>
          </el-table-column>
          <el-table-column label="最低消费" width="90">
            <template #default="{ row }">
              {{ Number(row.minprice) > 0 ? `满${Number(row.minprice)}` : '无门槛' }}
            </template>
          </el-table-column>
          <el-table-column label="库存/已领/已用" width="126">
            <template #default="{ row }">{{ row.stock }} / {{ row.getnum }} / {{ row.used }}</template>
          </el-table-column>
          <el-table-column prop="perlimit" label="限领" width="60" />
          <el-table-column label="有效期" min-width="180" show-overflow-tooltip>
            <template #default="{ row }">{{ periodText(row) }}</template>
          </el-table-column>
          <el-table-column label="阶段" width="82">
            <template #default="{ row }">
              <el-tag
                size="small"
                effect="plain"
                :type="(PHASE_TEXT[row.phase] || PHASE_TEXT.ongoing).type"
              >
                {{ (PHASE_TEXT[row.phase] || PHASE_TEXT.ongoing).label }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="80">
            <template #default="{ row }">
              <el-switch
                v-model="row.status"
                :active-value="1"
                :inactive-value="0"
                @change="handleStatusChange(row)"
              />
            </template>
          </el-table-column>
          <el-table-column label="创建时间" width="150">
            <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="190" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="primary" @click="openSend(row)">发放</el-button>
              <el-button link type="primary" @click="handleCopy(row)">复制</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
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
    </template>

    <!-- ============ 编辑视图（铺满主区域，紧凑行距） ============ -->
    <el-card v-else shadow="never" class="edit-card">
      <template #header>
        <div class="edit-header">
          <span>{{ isEdit ? '编辑优惠券' : '新增优惠券' }}</span>
          <el-button link type="primary" @click="handleCancelEdit">← 返回列表</el-button>
        </div>
      </template>

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="100px"
        label-position="right"
        class="compact-form"
      >
        <el-row :gutter="24">
          <el-col :span="12">
            <el-form-item label="优惠券名称" prop="name">
              <el-input v-model="form.name" placeholder="请输入优惠券名称" :disabled="coreLocked" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="优惠券类型">
              <el-select v-model="form.type" :disabled="coreLocked" style="width: 100%">
                <el-option
                  v-for="t in TYPE_OPTIONS"
                  :key="t.value"
                  :label="t.label"
                  :value="t.value"
                />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="24">
          <el-col :span="8" v-if="form.type === 1 || form.type === 4">
            <el-form-item :label="benefitColumnLabel">
              <el-input-number v-model="form.money" :min="0.01" :precision="2" :disabled="coreLocked" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8" v-if="form.type === 10">
            <el-form-item label="折扣值">
              <el-input-number v-model="form.discount" :min="1" :max="9.9" :precision="1" :step="0.1" :disabled="coreLocked" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8" v-if="form.type === 3">
            <el-form-item label="可用次数">
              <el-input-number v-model="form.limit_count" :min="1" :disabled="coreLocked" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="最低消费">
              <el-input-number v-model="form.minprice" :min="0" :precision="2" :disabled="coreLocked" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="库存（总量）">
              <el-input-number v-model="form.stock" :min="1" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="24">
          <el-col :span="8">
            <el-form-item label="每人限领">
              <el-input-number v-model="form.perlimit" :min="1" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="有效期类型">
              <el-radio-group v-model="form.yxqtype">
                <el-radio :value="1">定时间范围</el-radio>
                <el-radio :value="2">领取后N天</el-radio>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col :span="8" v-if="form.yxqtype === 2">
            <el-form-item label="有效天数">
              <el-input-number v-model="form.yxqdate" :min="1" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="24">
          <el-col :span="12" v-if="form.yxqtype === 1">
            <el-form-item label="有效期">
              <el-date-picker
                v-model="form.timeRange"
                type="datetimerange"
                range-separator="至"
                start-placeholder="开始时间"
                end-placeholder="结束时间"
                style="width: 100%"
              />
            </el-form-item>
          </el-col>
          <el-col :span="12" v-else>
            <el-form-item label="领取窗口">
              <el-date-picker
                v-model="form.timeRange"
                type="datetimerange"
                range-separator="至"
                start-placeholder="开始时间（可选）"
                end-placeholder="结束时间（可选）"
                style="width: 100%"
              />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="适用人群">
              <el-select v-model="form.gettjArr" multiple style="width: 100%" placeholder="不限">
                <el-option label="不限制" value="-1" />
                <el-option
                  v-for="lv in levelOptions"
                  :key="lv.id"
                  :label="lv.name"
                  :value="String(lv.id)"
                />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="24">
          <el-col :span="8">
            <el-form-item label="允许转赠">
              <el-switch v-model="form.isgive" :active-value="1" :inactive-value="0" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="主动领取">
              <el-switch v-model="form.tolist" :active-value="1" :inactive-value="0" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="排序">
              <el-input-number v-model="form.sort" :min="0" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="24">
          <el-col :span="8">
            <el-form-item label="适用范围">
              <el-radio-group v-model="form.fwtype">
                <el-radio :value="0">全场通用</el-radio>
                <el-radio :value="1">指定类目</el-radio>
                <el-radio :value="2">指定商品</el-radio>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col :span="16" v-if="form.fwtype === 1">
            <el-form-item label="选择类目">
              <el-select
                v-model="form.categoryArr"
                multiple
                filterable
                style="width: 100%"
                placeholder="请选择类目"
              >
                <el-option
                  v-for="c in categoryOptions"
                  :key="c.id"
                  :label="c.name"
                  :value="c.id"
                />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="16" v-if="form.fwtype === 2">
            <el-form-item label="选择商品">
              <el-select
                v-model="form.productArr"
                multiple
                filterable
                style="width: 100%"
                placeholder="请选择商品"
              >
                <el-option
                  v-for="g in goodsOptions"
                  :key="g.id"
                  :label="g.name"
                  :value="g.id"
                />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="24">
          <el-col :span="8">
            <el-form-item label="购买金额">
              <el-input-number v-model="form.price" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="所需积分">
              <el-input-number v-model="form.score" :min="0" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="有效期说明">
              <el-input v-model="form.yxqtime" placeholder="如：仅限工作日使用（可选）" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="24">
          <el-col :span="12">
            <el-form-item label="领取说明">
              <el-input v-model="form.gettips" placeholder="展示在领取页的说明（可选）" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="使用说明">
              <el-input v-model="form.usetips" placeholder="展示在券详情的使用说明（可选）" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-alert
          v-if="coreLocked"
          class="core-lock-tip"
          type="warning"
          :closable="false"
          show-icon
          title="该优惠券已被领取，名称/类型/面值/门槛等核心字段已锁定"
        />

        <div class="edit-footer">
          <el-button @click="handleCancelEdit">取消</el-button>
          <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
        </div>
      </el-form>
    </el-card>

    <!-- ============ 批量发放弹窗 ============ -->
    <el-dialog
      v-model="sendVisible"
      :title="`批量发放 - ${sendTarget?.name || ''}`"
      width="560px"
    >
      <el-form label-width="100px" label-position="right" v-loading="sendLoading">
        <el-form-item label="发放方式">
          <el-radio-group v-model="sendForm.send_type">
            <el-radio :value="0">指定会员</el-radio>
            <el-radio :value="1">按条件</el-radio>
          </el-radio-group>
        </el-form-item>

        <template v-if="sendForm.send_type === 0">
          <el-form-item label="选择会员">
            <el-select
              v-model="sendForm.mids"
              multiple
              filterable
              remote
              :remote-method="searchMembers"
              :loading="memberSearching"
              placeholder="输入昵称/手机号搜索"
              style="width: 100%"
            >
              <el-option
                v-for="m in memberOptions"
                :key="m.id"
                :label="`${m.nickname || m.tel || ('会员' + m.id)}（${m.tel || '-'}）`"
                :value="m.id"
              />
            </el-select>
          </el-form-item>
        </template>

        <template v-else>
          <el-form-item label="昵称关键词">
            <el-input v-model="sendForm.nickname" placeholder="可选" clearable />
          </el-form-item>
          <el-form-item label="会员等级">
            <el-select v-model="sendForm.levelid" placeholder="不限" clearable style="width: 100%">
              <el-option v-for="lv in levelOptions" :key="lv.id" :label="lv.name" :value="lv.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="注册时间">
            <el-date-picker
              v-model="sendForm.ctimeRange"
              type="datetimerange"
              range-separator="至"
              start-placeholder="开始"
              end-placeholder="结束"
              style="width: 100%"
            />
          </el-form-item>
        </template>

        <el-form-item label="每人张数">
          <el-input-number v-model="sendForm.per_num" :min="1" :max="100" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="sendVisible = false">取消</el-button>
        <el-button type="primary" :loading="sendSubmitting" @click="handleSend">发放</el-button>
      </template>
    </el-dialog>

    <!-- ============ 过期提醒设置弹窗 ============ -->
    <el-dialog v-model="setVisible" title="过期提醒设置" width="480px">
      <el-form label-width="100px" label-position="right">
        <el-form-item label="过期提醒">
          <el-switch v-model="setForm.expire_status" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item v-if="setForm.expire_status === 1" label="提醒时机">
          <el-select
            v-model="setForm.expire_rules"
            multiple
            style="width: 100%"
            placeholder="选择到期前几天提醒"
          >
            <el-option v-for="d in expireRuleOptions" :key="d" :label="`到期前 ${d} 天`" :value="d" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="setVisible = false">取消</el-button>
        <el-button type="primary" :loading="setSaving" @click="handleSaveSet">保存</el-button>
      </template>
    </el-dialog>
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

.edit-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 600;
}

/* 编辑视图铺满主区域 + 紧凑行距 */
.compact-form :deep(.el-form-item) {
  margin-bottom: 12px;
}

.core-lock-tip {
  margin-bottom: 12px;
}

.edit-footer {
  padding-top: 8px;
  border-top: 1px solid #ebeef5;
  text-align: right;
}
</style>
