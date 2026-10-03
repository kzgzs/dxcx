<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { Close, Search } from '@element-plus/icons-vue'
import {
  copyCouponManage,
  deleteCouponManage,
  deleteCouponManageBatch,
  getCouponManageDetail,
  getCouponManageList,
  getCouponSet,
  saveCouponManage,
  saveCouponSet,
  sendCoupon,
} from '../../api/couponManage'
import { getCategoryOptions, getGoodsOptions } from '../../api/cuxiao'
import { getYuyueProductList } from '../../api/yuyue'
import { getMemberLevelList, getMemberList } from '../../api/member'
import type { MemberItem, MemberLevelItem } from '../../api/types'
import type { CouponManageItem, CouponSetData } from '../../api/types'
import { formatTime } from '../../utils/time'
import CouponRecord from './CouponRecord.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'

// 券类型（对齐参考系统口径）
const TYPE_OPTIONS = [
  { value: 1, label: '代金券' },
  { value: 10, label: '折扣券' },
  { value: 2, label: '礼品券' },
  { value: 3, label: '计次券' },
  { value: 4, label: '运费抵扣券' },
]
const TYPE_DESC =
  '代金券：减免固定金额的优惠券。折扣券：按比例减免订单金额的优惠券。礼品券：用于兑换指定实物商品或虚拟权益的凭证。计次券：提供固定使用次数的服务类优惠券。运费抵扣券：专门用于减免订单运费的优惠券。'

interface NamedId {
  id: number
  name: string
}
interface GiveRow extends NamedId {
  num: number
}

// ============ 视图切换 ============
const viewMode = ref<'list' | 'edit' | 'set'>('list')

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<CouponManageItem[]>([])
const total = ref(0)
const selected = ref<CouponManageItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCouponManageList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
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

function handleSelectionChange(rows: CouponManageItem[]) {
  selected.value = rows
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

// 优惠金额列（照 canzhao index.html templet）
function benefitHtml(row: CouponManageItem): { main: string; sub: string } {
  if (row.type === 1 || row.type === 4) {
    return {
      main: `¥${Number(row.money)}`,
      sub: Number(row.minprice) > 0 ? `满${Number(row.minprice)}元可用` : '无门槛',
    }
  }
  if (row.type === 10) {
    return { main: `${Number(row.discount)}%`, sub: '无门槛' }
  }
  if (row.type === 3) {
    return { main: `${Number(row.limit_count)}次`, sub: '' }
  }
  return { main: row.type_txt, sub: '' }
}

// 状态标签（照截图：绿色"进行中"/灰色"已结束"）
function statusTag(row: CouponManageItem): { text: string; type: 'success' | 'info' | 'warning' } {
  if (row.phase === 'ended') return { text: '已结束', type: 'info' }
  if (row.phase === 'not_started') return { text: '未开始', type: 'warning' }
  return { text: '进行中', type: 'success' }
}

// ============ 删除 / 批量删除 / 复制 ============
function handleDelete(row: CouponManageItem) {
  ElMessageBox.confirm(
    `删除后所有已领取优惠券都将被删除，确定要删除「${row.name}」吗？删除后无法恢复！`,
    '操作确认',
    { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await deleteCouponManage(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器 */
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (selected.value.length === 0) {
    ElMessage.warning('请先勾选要删除的优惠券')
    return
  }
  ElMessageBox.confirm(
    `删除后所有已领取优惠券都将被删除，确定要删除选中的 ${selected.value.length} 条吗？删除后无法恢复！`,
    '操作确认',
    { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await deleteCouponManageBatch(selected.value.map((r) => r.id))
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器 */
      }
    })
    .catch(() => {})
}

async function handleCopy(row: CouponManageItem) {
  try {
    const res = await copyCouponManage(row.id)
    await ElMessageBox.confirm('复制成功，是否前去编辑？', '提示', {
      confirmButtonText: '是',
      cancelButtonText: '否',
      type: 'success',
    })
    openEditById(res.id)
  } catch {
    /* 拦截器 / 用户取消 */
  }
}

// ============ 领取记录弹窗 ============
const recordDialogVisible = ref(false)
const recordCouponId = ref(0)
const recordCouponName = ref('')

function openRecords(row: CouponManageItem) {
  recordCouponId.value = row.id
  recordCouponName.value = row.name
  recordDialogVisible.value = true
}

// ============ 链接弹窗（全站统一 LinkAddressModal） ============
const linkVisible = ref(false)
const linkPath = ref('')
const linkName = ref('')

function openLink(row: CouponManageItem) {
  linkPath.value = `pagesExt/coupon/coupondetail?id=${row.id}`
  linkName.value = row.name
  linkVisible.value = true
}

// ============ 编辑表单 ============
const formRef = ref<FormInstance>()
const submitting = ref(false)
const isEdit = ref(false)

const emptyForm = () => ({
  id: 0,
  name: '',
  type: 1,
  money: 0,
  discount: 90,
  minprice: 0,
  usetips: '',
  // 使用规则
  price: 0,
  score: 0,
  perlimit: 1,
  stock: 100,
  timeRange: null as [Date, Date] | null,
  yxqtype: 1,
  yxqdate: 7,
  yxqtime: '',
  fwtype: 0,
  categoryList: [] as NamedId[],
  productList: [] as NamedId[],
  yuyueList: [] as NamedId[],
  gettjArr: ['-1'] as string[],
  showtjArr: ['-1'] as string[],
  tolist: 1,
  sort: 0,
  // 发放设置
  isgive: 0,
  paygive: 0,
  buyprogive: 0,
  buyproList: [] as GiveRow[],
  buyyuyueprogive: 0,
  buyyuyueList: [] as GiveRow[],
  give_num_type: 0,
  // 计次券
  limit_count: 10,
  // 页面设计
  font_color: '#2B2B2B',
  title_color: '#2B2B2B',
  bg_color: '#FFFFFF',
})

const form = reactive(emptyForm())

const rules: FormRules = {
  name: [{ required: true, message: '请输入优惠券名称', trigger: 'blur' }],
}

// 候选数据
const levelOptions = ref<MemberLevelItem[]>([])
const categoryOptions = ref<NamedId[]>([])
const goodsOptions = ref<NamedId[]>([])
const yuyueOptions = ref<NamedId[]>([])

async function loadCandidates() {
  try {
    const [levels, cats, goods, yuyue] = await Promise.all([
      getMemberLevelList(),
      getCategoryOptions(),
      getGoodsOptions(),
      getYuyueProductList({ page: 1, pagesize: 200 }),
    ])
    levelOptions.value = levels.list || []
    categoryOptions.value = (cats.list || []).map((c) => ({ id: c.id, name: c.name }))
    goodsOptions.value = (goods.list || []).map((g) => ({ id: g.id, name: g.name }))
    yuyueOptions.value = (yuyue.list || []).map((y) => ({ id: y.id, name: y.name }))
  } catch {
    /* 候选失败不阻断 */
  }
}

// 人群选项：所有人(-1) + 关注用户(0) + 会员等级
function crowdOptions(): { value: string; label: string }[] {
  return [
    { value: '-1', label: '所有人' },
    { value: '0', label: '关注用户' },
    ...levelOptions.value.map((l) => ({ value: String(l.id), label: l.name })),
  ]
}

function toggleCrowd(arr: string[], val: string) {
  if (val === '-1') {
    // 选所有人 = 清空其它
    if (arr.includes('-1') && arr.length === 1) return
    arr.splice(0, arr.length, '-1')
    return
  }
  const i = arr.indexOf('-1')
  if (i >= 0) arr.splice(i, 1)
  const j = arr.indexOf(val)
  if (j >= 0) arr.splice(j, 1)
  else arr.push(val)
  if (arr.length === 0) arr.push('-1')
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, emptyForm())
  viewMode.value = 'edit'
}

function csvToNamed(csv: string, options: { id: number; name: string }[]): NamedId[] {
  return csv
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
    .map((id) => {
      const found = options.find((o) => String(o.id) === id)
      return { id: Number(id), name: found ? found.name : `#${id}` }
    })
}

function csvPair(csv: string, numCsv: string): GiveRow[] {
  const ids = csv.split(',').map((s) => s.trim()).filter(Boolean)
  const nums = numCsv.split(',').map((s) => Number(s.trim()) || 1)
  return ids.map((id, i) => ({ id: Number(id), name: `#${id}`, num: nums[i] || 1 }))
}

async function openEditById(id: number) {
  isEdit.value = true
  try {
    const d = await getCouponManageDetail(id)
    const range: [Date, Date] | null =
      d.start_stamp && d.end_stamp
        ? ([new Date(d.start_stamp * 1000), new Date(d.end_stamp * 1000)] as [Date, Date])
        : null
    Object.assign(form, {
      id: d.id,
      name: d.name,
      type: d.type,
      money: Number(d.money),
      discount: Number(d.discount) || 90,
      minprice: Number(d.minprice),
      usetips: d.usetips || '',
      price: Number(d.price),
      score: d.score,
      perlimit: d.perlimit,
      stock: d.stock,
      timeRange: range,
      yxqtype: d.yxqtype || 1,
      yxqdate: d.yxqdate || 7,
      yxqtime: d.yxqtime || '',
      fwtype: d.fwtype,
      categoryList: csvToNamed(d.categoryids, categoryOptions.value),
      productList: csvToNamed(d.productids, goodsOptions.value),
      yuyueList: csvToNamed(d.yuyue_productids, yuyueOptions.value),
      gettjArr: d.gettj ? d.gettj.split(',').filter(Boolean) : ['-1'],
      showtjArr: d.showtj ? d.showtj.split(',').filter(Boolean) : ['-1'],
      tolist: d.tolist,
      sort: d.sort,
      isgive: d.isgive,
      paygive: d.paygive,
      buyprogive: d.buyprogive,
      buyproList: csvPair(d.buyproids, d.buypro_give_num),
      buyyuyueprogive: d.buyyuyueprogive,
      buyyuyueList: csvPair(d.buyyuyueproids, d.buyyuyuepro_give_num),
      give_num_type: d.give_num_type,
      limit_count: d.limit_count || 10,
      font_color: d.font_color || '#2B2B2B',
      title_color: d.title_color || '#2B2B2B',
      bg_color: d.bg_color || '#FFFFFF',
    })
    viewMode.value = 'edit'
  } catch {
    /* 拦截器 */
  }
}

function openEdit(row: CouponManageItem) {
  openEditById(row.id)
}

function handleCancelEdit() {
  viewMode.value = 'list'
}

function joinIds(list: { id: number }[]): string {
  return list.map((i) => i.id).join(',')
}

function joinNums(list: { num: number }[]): string {
  return list.map((i) => i.num).join(',')
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if ((form.yxqtype === 1) && (!form.timeRange || form.timeRange.length !== 2)) {
      ElMessage.warning('请选择有效期起止时间')
      return
    }
    if ((form.yxqtype === 2 || form.yxqtype === 3) && form.yxqdate <= 0) {
      ElMessage.warning('请填写领取后有效天数')
      return
    }
    if (form.fwtype === 1 && form.categoryList.length === 0) {
      ElMessage.warning('请选择指定类目')
      return
    }
    if (form.fwtype === 2 && form.productList.length === 0) {
      ElMessage.warning('请选择指定商品')
      return
    }
    if (form.fwtype === 4 && form.yuyueList.length === 0) {
      ElMessage.warning('请选择指定服务商品')
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
        yxqdate: form.yxqtype === 2 || form.yxqtype === 3 ? form.yxqdate : 0,
        yxqtime: form.yxqtime,
        starttime:
          form.timeRange && form.timeRange[0] ? Math.floor(form.timeRange[0].getTime() / 1000) : 0,
        endtime:
          form.timeRange && form.timeRange[1] ? Math.floor(form.timeRange[1].getTime() / 1000) : 0,
        gettj: form.gettjArr.join(','),
        showtj: form.showtjArr.join(','),
        isgive: form.isgive,
        tolist: form.tolist,
        fwtype: form.fwtype,
        categoryids: form.fwtype === 1 ? joinIds(form.categoryList) : '',
        productids: form.fwtype === 2 ? joinIds(form.productList) : '',
        yuyue_productids: form.fwtype === 4 ? joinIds(form.yuyueList) : '',
        price: form.price,
        score: form.score,
        limit_count: form.type === 3 ? form.limit_count : undefined,
        usetips: form.usetips,
        sort: form.sort,
        paygive: form.paygive,
        buyprogive: form.buyprogive,
        buyproids: form.buyprogive ? joinIds(form.buyproList) : '',
        buypro_give_num: form.buyprogive ? joinNums(form.buyproList) : '',
        buyyuyueprogive: form.buyyuyueprogive,
        buyyuyueproids: form.buyyuyueprogive ? joinIds(form.buyyuyueList) : '',
        buyyuyuepro_give_num: form.buyyuyueprogive ? joinNums(form.buyyuyueList) : '',
        give_num_type: form.give_num_type,
        font_color: form.font_color,
        title_color: form.title_color,
        bg_color: form.bg_color,
      })
      ElMessage.success('保存成功')
      viewMode.value = 'list'
      fetchList()
    } catch {
      /* 拦截器 */
    } finally {
      submitting.value = false
    }
  })
}

// ============ 通用选择器弹窗（类目/商品/服务商品） ============
type PickerKind = 'category' | 'product' | 'yuyue' | 'buypro' | 'buyyuyue'
const pickerVisible = ref(false)
const pickerKind = ref<PickerKind>('category')
const pickerSelected = ref<number[]>([])
const pickerKeyword = ref('')

const pickerTitle = computed(() => {
  const map: Record<PickerKind, string> = {
    category: '选择商品分类',
    product: '选择商品',
    yuyue: '选择服务商品',
    buypro: '选择商品',
    buyyuyue: '选择服务商品',
  }
  return map[pickerKind.value]
})

function pickerOptions(): NamedId[] {
  if (pickerKind.value === 'category') return categoryOptions.value
  if (pickerKind.value === 'product' || pickerKind.value === 'buypro') return goodsOptions.value
  return yuyueOptions.value
}

function existingIds(kind: PickerKind): number[] {
  if (kind === 'category') return form.categoryList.map((i) => i.id)
  if (kind === 'product') return form.productList.map((i) => i.id)
  if (kind === 'yuyue') return form.yuyueList.map((i) => i.id)
  if (kind === 'buypro') return form.buyproList.map((i) => i.id)
  return form.buyyuyueList.map((i) => i.id)
}

const pickerFiltered = computed(() => {
  const kw = pickerKeyword.value.trim()
  return pickerOptions().filter((o) => !kw || o.name.includes(kw))
})

function openPicker(kind: PickerKind) {
  pickerKind.value = kind
  pickerKeyword.value = ''
  pickerSelected.value = []
  pickerVisible.value = true
}

function confirmPicker() {
  const opts = pickerOptions()
  const exist = existingIds(pickerKind.value)
  const picked = pickerSelected.value.filter((id) => !exist.includes(id))
  if (pickerKind.value === 'category') {
    form.categoryList.push(...picked.map((id) => ({ id, name: opts.find((o) => o.id === id)?.name || `#${id}` })))
  } else if (pickerKind.value === 'product') {
    form.productList.push(...picked.map((id) => ({ id, name: opts.find((o) => o.id === id)?.name || `#${id}` })))
  } else if (pickerKind.value === 'yuyue') {
    form.yuyueList.push(...picked.map((id) => ({ id, name: opts.find((o) => o.id === id)?.name || `#${id}` })))
  } else if (pickerKind.value === 'buypro') {
    form.buyproList.push(...picked.map((id) => ({ id, name: opts.find((o) => o.id === id)?.name || `#${id}`, num: 1 })))
  } else {
    form.buyyuyueList.push(...picked.map((id) => ({ id, name: opts.find((o) => o.id === id)?.name || `#${id}`, num: 1 })))
  }
  pickerVisible.value = false
}

function removeRow(list: { id: number }[], id: number) {
  const i = list.findIndex((r) => r.id === id)
  if (i >= 0) list.splice(i, 1)
}

// ============ 手机预览 ============
const previewAmount = computed(() => {
  if (form.type === 10) return `${Number(form.discount)}%`
  if (form.type === 3) return `${Number(form.limit_count)}次`
  if (form.type === 2) return '礼品券'
  return `¥${Number(form.money)}`
})

const previewRangeText = computed(() => {
  if (form.yxqtype === 2 || form.yxqtype === 3) {
    return `领取后${form.yxqdate}天内有效`
  }
  if (form.timeRange && form.timeRange[0] && form.timeRange[1]) {
    const f = (d: Date) => formatTime(d.getTime() / 1000)
    return `${f(form.timeRange[0])} ~ ${f(form.timeRange[1])}`
  }
  return '未设置'
})

const previewTypeText = computed(() => TYPE_OPTIONS.find((t) => t.value === form.type)?.label || '')

// ============ 批量发放（推送） ============
const sendVisible = ref(false)
const sendSubmitting = ref(false)
const sendTarget = ref<CouponManageItem | null>(null)
const sendForm = reactive({
  send_type: 0,
  mids: [] as number[],
  nickname: '',
  levelid: 0,
  per_num: 1,
})
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
  Object.assign(sendForm, { send_type: 0, mids: [], nickname: '', levelid: 0, per_num: 1 })
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
          ? { nickname: sendForm.nickname || undefined, levelid: sendForm.levelid || undefined }
          : undefined,
      per_num: sendForm.per_num,
    })
    ElMessage.success(`发放完成：成功 ${res.sucnum} 张，失败 ${res.errnum} 张`)
    sendVisible.value = false
    fetchList()
  } catch {
    /* 拦截器 */
  } finally {
    sendSubmitting.value = false
  }
}

// ============ 设置平铺页 ============
const setSaving = ref(false)
const setForm = reactive<CouponSetData>({ expire_status: 0, expire_rules: [] })
const RULE_OPTIONS = [
  { value: 1, label: '提前一天' },
  { value: 3, label: '提前三天' },
  { value: 7, label: '提前七天' },
]

async function openSet() {
  viewMode.value = 'set'
  try {
    const data = await getCouponSet()
    setForm.expire_status = data.expire_status
    setForm.expire_rules = data.expire_rules || []
  } catch {
    /* 拦截器 */
  }
}

function toggleRule(v: number) {
  const i = setForm.expire_rules.indexOf(v)
  if (i >= 0) setForm.expire_rules.splice(i, 1)
  else setForm.expire_rules.push(v)
}

async function handleSaveSet() {
  setSaving.value = true
  try {
    await saveCouponSet({ expire_status: setForm.expire_status, expire_rules: setForm.expire_rules })
    ElMessage.success('保存成功')
    viewMode.value = 'list'
  } catch {
    /* 拦截器 */
  } finally {
    setSaving.value = false
  }
}

onMounted(() => {
  fetchList()
  loadCandidates()
})
</script>

<template>
  <div class="page-wrap">
    <!-- ============ 列表 ============ -->
    <template v-if="viewMode === 'list'">
      <el-card shadow="never" class="toolbar-card">
        <div class="toolbar-row">
          <div class="toolbar-left">
            <el-button type="success" @click="openAdd">添加</el-button>
            <el-button @click="handleBatchDelete">删除</el-button>
            <el-button @click="openSet">设置</el-button>
          </div>
          <div class="toolbar-right">
            <el-input v-model="query.keyword" placeholder="名称" clearable class="w-keyword" @keyup.enter="handleSearch" />
            <el-button circle type="success" :icon="Search" @click="handleSearch" />
          </div>
        </div>
      </el-card>

      <el-card shadow="never" class="table-card">
        <el-table
          v-loading="loading"
          :data="tableData"
          :header-cell-style="{ background: '#e6e6e6' }"
          :cell-style="{ textAlign: 'left' }"
          @selection-change="handleSelectionChange"
        >
          <el-table-column type="selection" width="42" />
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column prop="type_txt" label="类型" width="100" />
          <el-table-column prop="name" label="名称" min-width="120" show-overflow-tooltip />
          <el-table-column label="优惠金额" min-width="120">
            <template #default="{ row }">
              <div class="money-main">{{ benefitHtml(row).main }}</div>
              <div v-if="benefitHtml(row).sub" class="money-sub">{{ benefitHtml(row).sub }}</div>
            </template>
          </el-table-column>
          <el-table-column prop="stock" label="库存" width="70" />
          <el-table-column prop="getnum" label="已领取" width="80" />
          <el-table-column prop="used" label="已使用" width="80" />
          <el-table-column label="活动时间" min-width="180">
            <template #default="{ row }">
              <div>{{ row.starttime }}</div>
              <div>{{ row.endtime }}</div>
            </template>
          </el-table-column>
          <el-table-column label="创建时间" width="150">
            <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="statusTag(row).type" size="small" effect="plain">{{ statusTag(row).text }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" min-width="220">
            <template #default="{ row }">
              <div class="op-flex">
                <el-button link type="primary" @click="openRecords(row)">领取记录</el-button>
                <el-button link type="primary" @click="openSend(row)">推送</el-button>
                <el-button link type="primary" @click="openLink(row)">链接</el-button>
                <el-button link type="primary" @click="handleCopy(row)">复制</el-button>
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              </div>
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

    <!-- ============ 添加/编辑平铺页 ============ -->
    <el-card v-else-if="viewMode === 'edit'" shadow="never" class="edit-card">
      <template #header>
        <div class="edit-header">
          <span>{{ isEdit ? '编辑优惠券' : '添加优惠券' }}</span>
          <el-button link :icon="Close" @click="handleCancelEdit" />
        </div>
      </template>

      <div class="edit-layout">
        <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" label-position="right" class="compact-form">
          <!-- 基础信息 -->
          <fieldset class="section">
            <legend class="section-title">基础信息</legend>
            <el-form-item label="优惠券名称" prop="name">
              <el-input v-model="form.name" placeholder="请输入优惠券名称" />
            </el-form-item>
            <el-form-item label="优惠券类型">
              <el-radio-group v-model="form.type">
                <el-radio-button :value="1">代金券</el-radio-button>
                <el-radio-button :value="10">折扣券</el-radio-button>
                <el-radio-button :value="2">礼品券</el-radio-button>
                <el-radio-button :value="3">计次券</el-radio-button>
                <el-radio-button :value="4">运费抵扣券</el-radio-button>
              </el-radio-group>
              <div class="field-aux">{{ TYPE_DESC }}</div>
            </el-form-item>
            <el-form-item v-if="form.type === 1 || form.type === 4" label="优惠金额">
              <el-input v-model.number="form.money" placeholder="0" style="width: 200px" />
              <span class="unit">元</span>
              <div class="field-aux">注：用于商品订单抵扣时，优惠券只能抵消商品金额，不能抵消运费</div>
            </el-form-item>
            <el-form-item v-if="form.type === 10" label="折扣比例">
              <el-input v-model.number="form.discount" placeholder="如9折填90" style="width: 200px" />
              <span class="unit">%</span>
              <div class="field-aux">例如9折优惠券则填写90</div>
            </el-form-item>
            <el-form-item v-if="form.type === 3" label="可用次数">
              <el-input-number v-model="form.limit_count" :min="1" style="width: 200px" />
              <span class="unit">次</span>
              <div class="field-aux">每张券可以使用多少次</div>
            </el-form-item>
            <el-form-item label="最低消费金额">
              <el-input v-model.number="form.minprice" placeholder="0" style="width: 200px" />
              <span class="unit">元</span>
              <div class="field-aux">注：购物金额（不含运费）达到最低抵扣金额才可使用优惠券，无门槛优惠券请填0</div>
            </el-form-item>
            <el-form-item label="使用说明">
              <el-input v-model="form.usetips" type="textarea" :rows="3" style="width: 420px" />
            </el-form-item>
          </fieldset>

          <!-- 使用规则 -->
          <fieldset class="section">
            <legend class="section-title">使用规则</legend>
            <el-form-item label="所需金额">
              <el-input v-model.number="form.price" placeholder="0" style="width: 200px" />
              <div class="field-aux inline-aux">需要消耗多少钱购买</div>
            </el-form-item>
            <el-form-item label="所需积分">
              <el-input v-model.number="form.score" placeholder="0" style="width: 200px" />
              <div class="field-aux inline-aux">需要消耗多少积分兑换，当设置需要花钱购买时此项不生效</div>
            </el-form-item>
            <el-form-item label="每人限领">
              <el-input v-model.number="form.perlimit" placeholder="0" style="width: 200px" />
              <div class="field-aux inline-aux">每人最多可领取/购买/购买赠送多少张，0为不限制（不建议）</div>
            </el-form-item>
            <el-form-item label="库存">
              <el-input v-model.number="form.stock" placeholder="100" style="width: 200px" />
            </el-form-item>
            <el-form-item label="开始时间">
              <el-date-picker
                v-model="form.timeRange"
                type="datetimerange"
                range-separator="至"
                start-placeholder="开始时间"
                end-placeholder="结束时间"
                style="width: 360px"
              />
              <div class="field-aux inline-aux">活动开始时间</div>
            </el-form-item>
            <el-form-item label="使用有效期">
              <el-radio-group v-model="form.yxqtype">
                <el-radio :value="1">固定时间范围</el-radio>
                <el-radio :value="2">领取后时长</el-radio>
                <el-radio :value="3">领取后时长（次日起）</el-radio>
              </el-radio-group>
              <div class="field-aux inline-aux">会员只能在此期间内使用，过期作废</div>
            </el-form-item>
            <el-form-item v-if="form.yxqtype === 2 || form.yxqtype === 3" label="有效期时间段">
              <span class="unit">领取后</span>
              <el-input-number v-model="form.yxqdate" :min="1" style="width: 120px" />
              <span class="unit">{{ form.yxqtype === 3 ? '天内有效（次日0点开始计算有效期）' : '天内有效' }}</span>
            </el-form-item>
            <el-form-item label="适用范围">
              <el-radio-group v-model="form.fwtype">
                <el-radio :value="0">所有商品</el-radio>
                <el-radio :value="1">指定类目</el-radio>
                <el-radio :value="2">指定商品</el-radio>
                <el-radio :value="4">指定服务商品</el-radio>
              </el-radio-group>
            </el-form-item>

            <el-form-item v-if="form.fwtype === 1" label="选择类目">
              <el-table :data="form.categoryList" size="small" border style="width: 420px">
                <el-table-column prop="id" label="分类ID" width="90" />
                <el-table-column prop="name" label="分类名称" />
                <el-table-column label="操作" width="80">
                  <template #default="{ row }">
                    <el-button link type="danger" @click="removeRow(form.categoryList, row.id)">删除</el-button>
                  </template>
                </el-table-column>
                <template #append>
                  <el-button size="small" @click="openPicker('category')">添加</el-button>
                </template>
              </el-table>
            </el-form-item>
            <el-form-item v-if="form.fwtype === 2" label="选择商品">
              <el-table :data="form.productList" size="small" border style="width: 420px">
                <el-table-column prop="id" label="商品ID" width="90" />
                <el-table-column prop="name" label="商品名称" />
                <el-table-column label="操作" width="80">
                  <template #default="{ row }">
                    <el-button link type="danger" @click="removeRow(form.productList, row.id)">删除</el-button>
                  </template>
                </el-table-column>
                <template #append>
                  <el-button size="small" @click="openPicker('product')">添加</el-button>
                </template>
              </el-table>
            </el-form-item>
            <el-form-item v-if="form.fwtype === 4" label="选择服务商品">
              <el-table :data="form.yuyueList" size="small" border style="width: 420px">
                <el-table-column prop="id" label="商品ID" width="90" />
                <el-table-column prop="name" label="商品名称" />
                <el-table-column label="操作" width="80">
                  <template #default="{ row }">
                    <el-button link type="danger" @click="removeRow(form.yuyueList, row.id)">删除</el-button>
                  </template>
                </el-table-column>
                <template #append>
                  <el-button size="small" @click="openPicker('yuyue')">添加</el-button>
                </template>
              </el-table>
            </el-form-item>

            <el-form-item label="领取条件">
              <div class="chip-row">
                <span
                  v-for="o in crowdOptions()"
                  :key="o.value"
                  class="chip"
                  :class="{ active: form.gettjArr.includes(o.value) }"
                  @click="toggleCrowd(form.gettjArr, o.value)"
                >
                  {{ o.label }}<span v-if="form.gettjArr.includes(o.value)" class="chip-check">✓</span>
                </span>
              </div>
            </el-form-item>
            <el-form-item label="领券中心显示">
              <el-radio-group v-model="form.tolist">
                <el-radio :value="1">是</el-radio>
                <el-radio :value="0">否</el-radio>
              </el-radio-group>
              <div class="field-aux inline-aux">是否显示在领券中心和商品详情页</div>
            </el-form-item>
            <el-form-item label="显示条件">
              <div class="chip-row">
                <span
                  v-for="o in crowdOptions()"
                  :key="o.value"
                  class="chip"
                  :class="{ active: form.showtjArr.includes(o.value) }"
                  @click="toggleCrowd(form.showtjArr, o.value)"
                >
                  {{ o.label }}<span v-if="form.showtjArr.includes(o.value)" class="chip-check">✓</span>
                </span>
              </div>
            </el-form-item>
            <el-form-item label="序号">
              <el-input v-model.number="form.sort" placeholder="0" style="width: 200px" />
              <div class="field-aux inline-aux">用于排序，越大越靠前</div>
            </el-form-item>
          </fieldset>

          <!-- 发放设置 -->
          <fieldset class="section">
            <legend class="section-title">发放设置</legend>
            <el-form-item label="使用范围">
              <el-radio-group v-model="form.isgive">
                <el-radio :value="0">仅自用</el-radio>
                <el-radio :value="1">自用+转赠</el-radio>
                <el-radio :value="2">仅转赠</el-radio>
              </el-radio-group>
              <div class="field-aux inline-aux">优惠券使用范围，自用或转赠（仅转赠类型前端不显示核销码）</div>
            </el-form-item>
            <el-form-item label="支付后赠送">
              <el-radio-group v-model="form.paygive">
                <el-radio :value="0">关闭</el-radio>
                <el-radio :value="1">开启</el-radio>
              </el-radio-group>
              <div class="field-aux inline-aux">支付成功后自动发放优惠券，退款不扣除</div>
            </el-form-item>
            <el-form-item label="购买商品赠送">
              <el-radio-group v-model="form.buyprogive">
                <el-radio :value="0">关闭</el-radio>
                <el-radio :value="1">开启</el-radio>
              </el-radio-group>
              <div v-if="form.buyprogive === 1" class="give-table">
                <el-table :data="form.buyproList" size="small" border style="width: 480px">
                  <el-table-column prop="id" label="商品ID" width="90" />
                  <el-table-column prop="name" label="商品名称" />
                  <el-table-column label="赠送数量" width="120">
                    <template #default="{ row }">
                      <el-input-number v-model="row.num" :min="1" size="small" controls-position="right" style="width: 90px" />
                    </template>
                  </el-table-column>
                  <el-table-column label="操作" width="70">
                    <template #default="{ row }">
                      <el-button link type="danger" @click="removeRow(form.buyproList, row.id)">删除</el-button>
                    </template>
                  </el-table-column>
                  <template #append>
                    <el-button size="small" @click="openPicker('buypro')">添加</el-button>
                  </template>
                </el-table>
              </div>
            </el-form-item>
            <el-form-item label="购买服务商品赠送">
              <el-radio-group v-model="form.buyyuyueprogive">
                <el-radio :value="0">关闭</el-radio>
                <el-radio :value="1">开启</el-radio>
              </el-radio-group>
              <div class="field-aux inline-aux">购买预约服务商品赠送</div>
              <div v-if="form.buyyuyueprogive === 1" class="give-table">
                <el-table :data="form.buyyuyueList" size="small" border style="width: 480px">
                  <el-table-column prop="id" label="商品ID" width="90" />
                  <el-table-column prop="name" label="商品名称" />
                  <el-table-column label="赠送数量" width="120">
                    <template #default="{ row }">
                      <el-input-number v-model="row.num" :min="1" size="small" controls-position="right" style="width: 90px" />
                    </template>
                  </el-table-column>
                  <el-table-column label="操作" width="70">
                    <template #default="{ row }">
                      <el-button link type="danger" @click="removeRow(form.buyyuyueList, row.id)">删除</el-button>
                    </template>
                  </el-table-column>
                  <template #append>
                    <el-button size="small" @click="openPicker('buyyuyue')">添加</el-button>
                  </template>
                </el-table>
              </div>
            </el-form-item>
            <el-form-item label="赠送数量类型">
              <el-radio-group v-model="form.give_num_type">
                <el-radio :value="0">按设置数量</el-radio>
                <el-radio :value="1">按设置数量×购买数量</el-radio>
              </el-radio-group>
              <div class="field-aux">
                按设置数量：赠送固定数量。例如：设置为2，则每单赠送2张，与购买数量无关；按设置数量 ×
                购买数量：赠送数量与购买量挂钩。例如：设置为2，购买3件商品，则赠送 2 × 3 = 6 张。
              </div>
            </el-form-item>
          </fieldset>

          <!-- 页面设计 -->
          <fieldset class="section">
            <legend class="section-title">页面设计</legend>
            <el-form-item label="字体颜色">
              <el-color-picker v-model="form.font_color" />
              <span class="color-tip">字体颜色，如：#2B2B2B</span>
            </el-form-item>
            <el-form-item label="标题颜色">
              <el-color-picker v-model="form.title_color" />
              <span class="color-tip">标题颜色，如：#2B2B2B</span>
            </el-form-item>
            <el-form-item label="背景颜色">
              <el-color-picker v-model="form.bg_color" />
              <span class="color-tip">背景颜色，如：#FFFFFF</span>
            </el-form-item>
          </fieldset>

          <div class="edit-footer">
            <el-button type="success" :loading="submitting" @click="handleSubmit">提 交</el-button>
          </div>
        </el-form>

        <!-- 右侧固定手机预览 -->
        <aside class="phone-preview">
          <div class="phone-frame">
            <div class="phone-status">
              <span>● wechat</span>
              <span>12:00</span>
              <span>100%</span>
            </div>
            <div class="phone-title" :style="{ color: form.title_color }">优惠券详情</div>
            <div class="phone-card" :style="{ background: form.bg_color }">
              <div class="phone-amount-row">
                <span class="phone-amount" :style="{ color: form.font_color }">{{ previewAmount }}</span>
                <span class="phone-name" :style="{ color: form.font_color }">{{ form.name || '优惠券名称' }}</span>
              </div>
              <div class="phone-type" :style="{ color: form.font_color }">{{ previewTypeText }}</div>
              <div class="phone-block" :style="{ color: form.font_color }">
                <div class="phone-block-label">活动时间</div>
                <div>{{ previewRangeText }}</div>
              </div>
              <div class="phone-block" :style="{ color: form.font_color }">
                <div class="phone-block-label">有效期</div>
                <div>{{ previewRangeText }}</div>
              </div>
              <div class="phone-block" :style="{ color: form.font_color }">
                <div class="phone-block-label">使用说明</div>
                <div>{{ form.usetips || '暂无说明' }}</div>
              </div>
            </div>
            <div class="phone-btn">立即领取</div>
            <div class="phone-back">返回首页</div>
          </div>
          <div class="phone-note">*仅供参考，实际效果以手机端为准</div>
        </aside>
      </div>
    </el-card>

    <!-- ============ 设置平铺页 ============ -->
    <el-card v-else shadow="never" class="edit-card">
      <template #header>
        <div class="edit-header">
          <span>优惠券设置</span>
          <el-button link :icon="Close" @click="viewMode = 'list'" />
        </div>
      </template>
      <el-form label-width="110px" label-position="right" class="compact-form">
        <el-form-item label="到期提醒">
          <el-radio-group v-model="setForm.expire_status">
            <el-radio :value="0">关闭</el-radio>
            <el-radio :value="1">开启</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="setForm.expire_status === 1" label="提醒时间">
          <div class="chip-row">
            <span
              v-for="r in RULE_OPTIONS"
              :key="r.value"
              class="chip green"
              :class="{ active: setForm.expire_rules.includes(r.value) }"
              @click="toggleRule(r.value)"
            >
              {{ r.label }}<span v-if="setForm.expire_rules.includes(r.value)" class="chip-check">✓</span>
            </span>
          </div>
          <div class="field-aux">提前几天提醒</div>
        </el-form-item>
        <el-form-item>
          <el-button type="success" :loading="setSaving" @click="handleSaveSet">提 交</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 领取记录大弹窗 -->
    <el-dialog v-model="recordDialogVisible" :title="`领取记录 - ${recordCouponName}`" width="90%" top="3vh">
      <CouponRecord :coupon-id="recordCouponId" />
    </el-dialog>

    <!-- 链接弹窗（全站统一组件） -->
    <LinkAddressModal v-model:visible="linkVisible" :name="linkName" :path="linkPath" />

    <!-- 推送弹窗 -->
    <el-dialog v-model="sendVisible" :title="`批量发放 - ${sendTarget?.name || ''}`" width="560px">
      <el-form label-width="100px" label-position="right">
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
                :label="`${m.nickname || m.tel || '会员' + m.id}（${m.tel || '-'}）`"
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

    <!-- 通用选择器弹窗 -->
    <el-dialog v-model="pickerVisible" :title="pickerTitle" width="720px">
      <el-input v-model="pickerKeyword" placeholder="输入名称搜索" clearable style="margin-bottom: 10px" />
      <el-table :data="pickerFiltered" height="360" @selection-change="(rows: NamedId[]) => (pickerSelected = rows.map((r: NamedId) => r.id))">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="90" />
        <el-table-column prop="name" label="名称" />
      </el-table>
      <template #footer>
        <el-button @click="pickerVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmPicker">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page-wrap {
  margin: 3px;
}

.toolbar-card {
  margin-bottom: 3px;
}

.toolbar-card :deep(.el-card__body) {
  padding: 5px;
}

.toolbar-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.toolbar-left,
.toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.w-keyword {
  width: 160px;
}

.table-card :deep(.el-card__body) {
  padding: 5px;
}

.table-card :deep(.el-table .cell) {
  text-align: left;
  padding: 3px 0;
}

.money-main {
  color: #000;
  font-size: 15px;
  font-weight: 600;
}

.money-sub {
  color: #909399;
  font-size: 12px;
}

.pagination {
  margin-top: 3px;
  justify-content: flex-end;
}

.op-flex {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 4px;
}

.edit-card {
  overflow: visible;
}

.edit-card :deep(.el-card__body) {
  overflow: visible;
}

.edit-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 600;
}

.edit-layout {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}

.compact-form {
  flex: 1;
  min-width: 0;
}

.compact-form :deep(.el-form-item) {
  margin-bottom: 14px;
}

.section {
  border: none;
  border-top: 1px solid #ebeef5;
  margin: 0 0 18px 0;
  padding: 14px 0 0 0;
}

.section-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 14px;
  padding: 0 6px;
}

.field-aux {
  width: 100%;
  color: #909399;
  font-size: 12px;
  line-height: 1.5;
  margin-top: 4px;
}

.inline-aux {
  display: inline-block;
  margin-left: 12px;
  width: auto;
}

.unit {
  margin: 0 6px;
  color: #606266;
}

.color-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}

.chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 12px;
  border: 1px solid #dcdfe6;
  border-radius: 3px;
  cursor: pointer;
  font-size: 13px;
  color: #606266;
  background: #fff;
}

.chip.active {
  background: #67c23a;
  border-color: #67c23a;
  color: #fff;
}

.chip-check {
  font-size: 12px;
}

.give-table {
  margin-top: 8px;
}

.edit-footer {
  padding-top: 8px;
  border-top: 1px solid #ebeef5;
  text-align: left;
}

/* 手机预览 */
.phone-preview {
  width: 300px;
  flex-shrink: 0;
  position: sticky;
  top: 10px;
  align-self: flex-start;
}

.phone-frame {
  border: 8px solid #2b2b2b;
  border-radius: 28px;
  background: #fff;
  overflow: hidden;
}

.phone-status {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #000;
  color: #fff;
  font-size: 10px;
  padding: 4px 10px;
}

.phone-title {
  background: #000;
  color: #fff;
  text-align: center;
  font-size: 13px;
  padding: 6px 0;
}

.phone-card {
  margin: 12px;
  border-radius: 8px;
  padding: 16px 14px;
  background: #fff;
}

.phone-amount-row {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.phone-amount {
  font-size: 26px;
  font-weight: 700;
  color: #ff7d00;
}

.phone-name {
  font-size: 16px;
  font-weight: 600;
}

.phone-type {
  font-size: 12px;
  margin-top: 2px;
}

.phone-block {
  margin-top: 12px;
  font-size: 12px;
}

.phone-block-label {
  font-weight: 600;
  margin-bottom: 2px;
}

.phone-btn {
  margin: 12px;
  background: linear-gradient(90deg, #ff9a2e, #ff7d00);
  color: #fff;
  text-align: center;
  border-radius: 20px;
  padding: 9px 0;
  font-size: 14px;
}

.phone-back {
  text-align: center;
  color: #909399;
  font-size: 12px;
  padding-bottom: 14px;
}

.phone-note {
  text-align: center;
  color: #909399;
  font-size: 11px;
  margin-top: 6px;
}

.form-tip {
  color: #909399;
  font-size: 12px;
}
</style>
