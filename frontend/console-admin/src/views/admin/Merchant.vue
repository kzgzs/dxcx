<script setup lang="ts">
/**
 * 商户 - 商户列表（多商户 business 表），对齐参考系统 Business
 * - 搜索：ID / 名称 / 分类 / 状态(待审核/已通过/已驳回/已过期) / 营业状态(营业/停业)
 * - 顶部：添加 / 删除 / 登录地址（二维码 + 手机端/电脑端地址）
 * - 添加/编辑：基本信息、描述简介与地址、其他设置、后台权限树、手机端权限（查看/通知/核销）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import QRCode from 'qrcode'
import { Close } from '@element-plus/icons-vue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'
import MapPicker from '../../components/MapPicker.vue'
import { getBusinessCategoryOptions, type BusinessOption } from '../../api/business'
import {
  getBusinessMerchantList,
  getBusinessMerchantDetail,
  saveBusinessMerchant,
  deleteBusinessMerchant,
  setBusinessMerchantStatus,
  rechargeBusinessMerchant,
  getBusinessLoginUrl,
  type BusinessMerchantItem,
} from '../../api/businessMerchant'
import {
  businessAuthGroups,
  wxViewOptions,
  noticeOptions,
  hexiaoOptions,
  groupLeafValues,
  allAuthLeafValues,
  type AuthGroup,
  type AuthLeaf,
} from '../../utils/businessAuthMenu'
import { getFreightList } from '../../api/tenantSet'
import type { FreightMapConfig } from '../../api/types'
import { areaProvinces } from '../../utils/areaData'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<BusinessMerchantItem[]>([])
const total = ref(0)
const selectedRows = ref<BusinessMerchantItem[]>([])
const categoryOptions = ref<BusinessOption[]>([])

const query = reactive({
  page: 1,
  pagesize: 20,
  id: '' as number | '',
  name: '',
  cid: '' as number | '',
  status: '' as number | '',
  is_open: '' as number | '',
  field: 'sort',
  order: 'desc' as 'asc' | 'desc',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getBusinessMerchantList({
      page: query.page,
      pagesize: query.pagesize,
      id: query.id === '' ? undefined : query.id,
      name: query.name || undefined,
      cid: query.cid === '' ? undefined : query.cid,
      status: query.status === '' ? undefined : query.status,
      is_open: query.is_open === '' ? undefined : query.is_open,
      field: query.field,
      order: query.order,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 统一拦截
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  if (!order) {
    query.field = 'sort'
    query.order = 'desc'
  } else {
    query.field = prop
    query.order = order === 'ascending' ? 'asc' : 'desc'
  }
  query.page = 1
  fetchList()
}

function sortOrder(prop: string): 'ascending' | 'descending' | null {
  return query.field === prop ? (query.order === 'asc' ? 'ascending' : 'descending') : null
}

function handlePageChange(p: number) {
  query.page = p
  fetchList()
}
function handleSizeChange(s: number) {
  query.pagesize = s
  query.page = 1
  fetchList()
}
function handleSelectionChange(rows: BusinessMerchantItem[]) {
  selectedRows.value = rows
}

function fmtDate(ts: number, withTime = false): string {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  const base = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
  return withTime ? `${base} ${p(d.getHours())}:${p(d.getMinutes())}` : base
}
function money(v: number): string {
  return Number(v || 0).toFixed(2)
}
function statusText(s: number): string {
  return ['待审核', '已通过', '已驳回', '已过期'][s] ?? '未知'
}
function isExpired(row: BusinessMerchantItem): boolean {
  return row.endtime > 0 && row.endtime < Math.floor(Date.now() / 1000)
}
function fullAddress(row: BusinessMerchantItem): string {
  const r = `${row.province || ''}${row.city || ''}${row.district || ''}${row.address || ''}`
  return r || '-'
}

// ============ 添加 / 编辑 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const submitting = ref(false)
const accountId = ref(0)
const editingId = ref(0)

interface MerchantForm {
  cid: number | ''
  name: string
  feepercent: number
  wxpayst: number
  linkman: string
  linktel: string
  tel: string
  kfurl: string
  logo: string
  pics: string[]
  desc: string
  content: string
  province: string
  city: string
  district: string
  address: string
  longitude: string
  latitude: string
  kdniao_intsend: number
  zhengming: string[]
  endtime: string
  status: number
  sort: number
  is_open: number
  un: string
  pwd: string
  authKeys: string[]
  wxKeys: string[]
  noticeKeys: string[]
  hexiaoKeys: string[]
}

function defaultForm(): MerchantForm {
  return {
    cid: '',
    name: '',
    feepercent: 0,
    wxpayst: 0,
    linkman: '',
    linktel: '',
    tel: '',
    kfurl: '',
    logo: '',
    pics: [],
    desc: '',
    content: '',
    province: '',
    city: '',
    district: '',
    address: '',
    longitude: '',
    latitude: '',
    kdniao_intsend: 0,
    zhengming: [],
    endtime: '',
    status: 1,
    sort: 0,
    is_open: 1,
    un: '',
    pwd: '',
    authKeys: [],
    wxKeys: [],
    noticeKeys: [],
    hexiaoKeys: [],
  }
}
const form = reactive<MerchantForm>(defaultForm())

// 省市区联动
const cityList = computed(() => {
  const p = areaProvinces.find((x) => x.name === form.province)
  return p ? p.cityList : []
})
const areaList = computed(() => {
  const c = cityList.value.find((x) => x.name === form.city)
  return c ? c.areaList : []
})
function onProvinceChange() {
  form.city = ''
  form.district = ''
}
function onCityChange() {
  form.district = ''
}

// ===================== 权限（后台 + 手机端） =====================
const allAuthValues = allAuthLeafValues(businessAuthGroups)
const allAuthOpts = allAuthValues.map((value) => ({ value }))

type AuthBlock = { kind: 'group'; name: string; leaves: AuthLeaf[] } | { kind: 'flat'; leaves: AuthLeaf[] }
function authBlocks(g: AuthGroup): AuthBlock[] {
  const blocks: AuthBlock[] = []
  for (const sub of g.children) {
    if (sub.leaves && sub.leaves.length) {
      blocks.push({ kind: 'group', name: sub.name, leaves: sub.leaves })
    } else if (sub.value) {
      const leaf: AuthLeaf = { name: sub.name, value: sub.value }
      const last = blocks[blocks.length - 1]
      if (last && last.kind === 'flat') {
        last.leaves.push(leaf)
      } else {
        blocks.push({ kind: 'flat', leaves: [leaf] })
      }
    }
  }
  return blocks
}
function groupOpts(g: AuthGroup): { value: string }[] {
  return groupLeafValues(g).map((value) => ({ value }))
}
// 一组选项是否全选 / 半选
function listAllChecked(list: string[], opts: { value: string }[]): boolean {
  return opts.length > 0 && opts.every((o) => list.includes(o.value))
}
function listIndeterminate(list: string[], opts: { value: string }[]): boolean {
  const n = opts.filter((o) => list.includes(o.value)).length
  return n > 0 && n < opts.length
}
// 整组全选 / 取消（直接 mutate，与 el-checkbox-group 同步）
function setListAll(list: string[], opts: { value: string }[], checked: boolean | string | number) {
  if (checked) {
    for (const o of opts) if (!list.includes(o.value)) list.push(o.value)
  } else {
    for (let i = list.length - 1; i >= 0; i--) {
      if (opts.some((o) => o.value === list[i])) list.splice(i, 1)
    }
  }
}
// 手机端权限总全选
function mobileAllChecked(): boolean {
  return (
    listAllChecked(form.wxKeys, wxViewOptions) &&
    listAllChecked(form.noticeKeys, noticeOptions) &&
    listAllChecked(form.hexiaoKeys, hexiaoOptions)
  )
}
function mobileAllIndeterminate(): boolean {
  let n = 0
  let total = 0
  const groups: [string[], { value: string }[]][] = [
    [form.wxKeys, wxViewOptions],
    [form.noticeKeys, noticeOptions],
    [form.hexiaoKeys, hexiaoOptions],
  ]
  for (const [list, opts] of groups) {
    total += opts.length
    n += opts.filter((o) => list.includes(o.value)).length
  }
  return n > 0 && n < total
}
function setMobileAll(checked: boolean | string | number) {
  setListAll(form.wxKeys, wxViewOptions, checked)
  setListAll(form.noticeKeys, noticeOptions, checked)
  setListAll(form.hexiaoKeys, hexiaoOptions, checked)
}

// 图片选择
const logoPickerVisible = ref(false)
const picsPickerVisible = ref(false)
const zmPickerVisible = ref(false)
function onLogoPick(urls: string[]) {
  if (urls[0]) form.logo = urls[0]
  logoPickerVisible.value = false
}
function onPicsPick(urls: string[]) {
  const merged = [...form.pics]
  for (const u of urls) if (!merged.includes(u)) merged.push(u)
  form.pics = merged
  picsPickerVisible.value = false
}
function onZmPick(urls: string[]) {
  const merged = [...form.zhengming]
  for (const u of urls) if (!merged.includes(u)) merged.push(u)
  form.zhengming = merged
  zmPickerVisible.value = false
}
function removeAt(arr: string[], idx: number) {
  arr.splice(idx, 1)
}

function openAdd() {
  isEdit.value = false
  accountId.value = 0
  Object.assign(form, defaultForm())
  dialogVisible.value = true
}

async function openEdit(row: BusinessMerchantItem) {
  isEdit.value = true
  editingId.value = row.id
  Object.assign(form, defaultForm())
  try {
    const data = await getBusinessMerchantDetail(row.id)
    const i = data.info || {}
    accountId.value = data.account?.id ?? 0
    form.cid = Array.isArray(i.cid_arr) && i.cid_arr[0] ? Number(i.cid_arr[0]) : ''
    form.name = i.name ?? ''
    form.feepercent = Number(i.feepercent ?? 0)
    form.wxpayst = Number(i.wxpayst ?? 0)
    form.linkman = i.linkman ?? ''
    form.linktel = i.linktel ?? ''
    form.tel = i.tel ?? ''
    form.kfurl = i.kfurl ?? ''
    form.logo = i.logo ?? ''
    form.pics = i.pics_arr ?? []
    form.desc = i.desc ?? ''
    form.content = i.content ?? ''
    form.province = i.province ?? ''
    form.city = i.city ?? ''
    form.district = i.district ?? ''
    form.address = i.address ?? ''
    form.longitude = i.longitude ?? ''
    form.latitude = i.latitude ?? ''
    form.kdniao_intsend = Number(i.kdniao_intsend ?? 0)
    form.zhengming = i.zhengming_arr ?? []
    form.endtime = i.endtime_str ?? ''
    form.status = Number(i.status ?? 1)
    form.sort = Number(i.sort ?? 0)
    form.is_open = Number(i.is_open ?? 1)
    form.un = data.account?.un ?? ''
    form.authKeys = (data.account?.auth_data ?? []).map(String)
    form.wxKeys = data.account?.wxauth_data ?? []
    form.noticeKeys = data.account?.notice_auth_data ?? []
    form.hexiaoKeys = data.account?.hexiao_auth_data ?? []
    dialogVisible.value = true
  } catch {
    // 统一拦截
  }
}

async function handleSubmit() {
  if (!form.name.trim()) return ElMessage.warning('请填写商户名称')
  if (!form.un.trim()) return ElMessage.warning('请填写登录账号')
  if (!isEdit.value && !form.pwd) return ElMessage.warning('请设置登录密码')
  if (form.pwd && form.pwd.length < 6) return ElMessage.warning('登录密码长度至少 6 位')

  submitting.value = true
  try {
    await saveBusinessMerchant({
      info: {
        id: editingId.value || 0,
        cid: form.cid === '' ? '' : String(form.cid),
        name: form.name.trim(),
        feepercent: form.feepercent,
        wxpayst: form.wxpayst,
        linkman: form.linkman.trim(),
        linktel: form.linktel.trim(),
        tel: form.tel.trim(),
        kfurl: form.kfurl.trim(),
        logo: form.logo,
        pics: form.pics,
        desc: form.desc.trim(),
        content: form.content,
        province: form.province,
        city: form.city,
        district: form.district,
        address: form.address.trim(),
        longitude: form.longitude.trim(),
        latitude: form.latitude.trim(),
        kdniao_intsend: form.kdniao_intsend,
        zhengming: form.zhengming,
        endtime: form.endtime,
        status: form.status,
        sort: form.sort,
        is_open: form.is_open,
      },
      uinfo: { id: accountId.value || undefined, un: form.un.trim(), pwd: form.pwd },
      auth_data: form.authKeys,
      wxauth_data: form.wxKeys,
      notice_auth_data: form.noticeKeys,
      hexiao_auth_data: form.hexiaoKeys,
    })
    ElMessage.success(isEdit.value ? '保存成功' : '添加成功')
    dialogVisible.value = false
    form.pwd = ''
    fetchList()
  } catch {
    // 统一拦截
  } finally {
    submitting.value = false
  }
}
// ============ 行内操作 ============
function passRow(row: BusinessMerchantItem) {
  ElMessageBox.confirm(`确定通过商户「${row.name}」的审核吗？`, '操作确认', {
    confirmButtonText: '通过',
    cancelButtonText: '取消',
    type: 'success',
  })
    .then(() => setBusinessMerchantStatus([row.id], 1))
    .then(() => {
      ElMessage.success('已通过')
      fetchList()
    })
    .catch(() => {})
}
function rejectRow(row: BusinessMerchantItem) {
  ElMessageBox.prompt(`请填写驳回「${row.name}」的原因`, '商户驳回', {
    confirmButtonText: '确定驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '请输入驳回原因',
    inputValidator: (v: string) => (v && v.trim() ? true : '请填写驳回原因'),
  })
    .then(({ value }) => setBusinessMerchantStatus([row.id], 2, value.trim()))
    .then(() => {
      ElMessage.success('已驳回')
      fetchList()
    })
    .catch(() => {})
}
function handleDelete(row: BusinessMerchantItem) {
  ElMessageBox.confirm(`确定删除商户「${row.name}」吗？其名下商品将被下架。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(() => deleteBusinessMerchant([row.id]))
    .then(() => {
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}
function batchDelete() {
  if (!selectedRows.value.length) return ElMessage.warning('请先勾选商户')
  const ids = selectedRows.value.map((r) => r.id)
  ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个商户吗？`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(() => deleteBusinessMerchant(ids))
    .then(() => {
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

// 充值
const rechargeVisible = ref(false)
const rechargeId = ref(0)
const rechargeMoney = ref<number | ''>('')
function openRecharge(row: BusinessMerchantItem) {
  rechargeId.value = row.id
  rechargeMoney.value = ''
  rechargeVisible.value = true
}
async function confirmRecharge() {
  if (!rechargeMoney.value || Number(rechargeMoney.value) === 0)
    return ElMessage.warning('请输入充值金额')
  try {
    await rechargeBusinessMerchant(rechargeId.value, Number(rechargeMoney.value))
    ElMessage.success('充值成功')
    rechargeVisible.value = false
    fetchList()
  } catch {
    // 统一拦截
  }
}

// 登录地址弹窗
const loginVisible = ref(false)
const qrImg = ref('')
const pcUrl = ref('')
const mobileUrl = ref('')
async function openLoginUrl() {
  loginVisible.value = true
  qrImg.value = ''
  try {
    const u = await getBusinessLoginUrl()
    pcUrl.value = u.pc_url
    mobileUrl.value = u.mobile_url
    qrImg.value = await QRCode.toDataURL(u.pc_url, { width: 240, margin: 1 })
  } catch {
    // 统一拦截
  }
}
async function copyText(t: string) {
  try {
    await navigator.clipboard.writeText(t)
    ElMessage.success('已复制')
  } catch {
    ElMessage.warning('复制失败，请手动选择复制')
  }
}

// ============ 地图选点（复用系统高德 MapPicker；未配置 key 时降级手动输入） ============
const mapConfig = ref<FreightMapConfig>({ map_type: '', map_key: '', map_jscode: '', map_qq_key: '' })
const hasMapKey = computed(() => mapConfig.value.map_type === 'amap' || mapConfig.value.map_type === 'qq')
const mapPickerVisible = ref(false)
function openMapPicker() {
  if (hasMapKey.value) {
    mapPickerVisible.value = true
  } else {
    ElMessage.info('未配置地图 Key，可在平台「系统设置」填写高德或腾讯地图 Key 后使用地图选点，或直接手动输入经纬度')
  }
}
function onMapPick(lng: string, lat: string) {
  form.longitude = lng
  form.latitude = lat
}

onMounted(async () => {
  try {
    categoryOptions.value = await getBusinessCategoryOptions()
  } catch {
    categoryOptions.value = []
  }
  try {
    const freight = await getFreightList()
    if ((freight as any)?.map_config) {
      mapConfig.value = (freight as any).map_config
    }
  } catch {
    // 未配置物流地图时保持手动输入降级
  }
  fetchList()
})
</script>

<template>
  <div class="bm-root">
    <template v-if="!dialogVisible">
    <!-- 搜索行（一行） -->
    <el-card shadow="never" class="bm-filter-card">
      <el-form inline class="bm-filter-form" @submit.prevent>
        <el-form-item label="ID">
          <el-input v-model.number="query.id" placeholder="ID" clearable style="width: 88px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="名称">
          <el-input v-model="query.name" placeholder="名称" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="query.cid" placeholder="全部" clearable style="width: 118px">
            <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 108px">
            <el-option label="待审核" :value="0" />
            <el-option label="已通过" :value="1" />
            <el-option label="已驳回" :value="2" />
            <el-option label="已过期" :value="3" />
          </el-select>
        </el-form-item>
        <el-form-item label="营业状态">
          <el-select v-model="query.is_open" placeholder="全部" clearable style="width: 104px">
            <el-option label="营业" :value="1" />
            <el-option label="停业" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <!-- 按钮行 -->
      <div class="bm-toolbar">
        <el-button type="success" @click="openAdd">添加</el-button>
        <el-button @click="batchDelete">删除</el-button>
        <el-button plain @click="openLoginUrl">登录地址</el-button>
      </div>

      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="44" />
        <el-table-column prop="id" label="ID" width="72" sortable="custom" :order="sortOrder('id')" />
        <el-table-column prop="cname" label="商家分类" width="100" show-overflow-tooltip />
        <el-table-column prop="name" label="商家名称" min-width="140" show-overflow-tooltip />
        <el-table-column label="商家图片" width="78">
          <template #default="{ row }">
            <el-image
              v-if="row.logo"
              :src="row.logo"
              fit="cover"
              class="bm-logo"
              :preview-src-list="[row.logo]"
              preview-teleported
            />
            <span v-else class="bm-empty">-</span>
          </template>
        </el-table-column>
        <el-table-column label="商家地址" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ fullAddress(row) }}</template>
        </el-table-column>
        <el-table-column prop="un" label="店主信息" width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.un || '-' }}</template>
        </el-table-column>
        <el-table-column label="联系人" width="130" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.linkman || '-' }}</div>
            <div class="bm-sub">{{ row.linktel || '' }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="money" label="余额" width="96" sortable="custom" :order="sortOrder('money')">
          <template #default="{ row }">
            <span class="bm-money">{{ money(row.money) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="viewnum" label="浏览量" width="80" />
        <el-table-column label="创建时间" width="140">
          <template #default="{ row }">{{ fmtDate(row.createtime, true) }}</template>
        </el-table-column>
        <el-table-column label="到期时间" width="110" sortable="custom" prop="endtime" :order="sortOrder('endtime')">
          <template #default="{ row }">
            <span :class="{ 'bm-expired': isExpired(row) }">{{ fmtDate(row.endtime) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="86">
          <template #default="{ row }">
            <span :class="['bm-state', 'bm-state-' + row.status]">{{ statusText(row.status) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="营业状态" width="80">
          <template #default="{ row }">
            <span :class="row.is_open === 1 ? 'bm-open' : 'bm-close'">{{ row.is_open === 1 ? '营业' : '停业' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="warning" @click="openRecharge(row)">充值</el-button>
            <el-button v-if="row.status === 0" link type="success" @click="passRow(row)">通过</el-button>
            <el-button v-if="row.status === 0" link type="danger" @click="rejectRow(row)">驳回</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="bm-pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[20, 50, 100]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    </template>

    <!-- 添加 / 编辑（平铺主区域） -->
    <div v-else class="bm-edit">
      <div class="bm-edit-head">
        <span class="bm-edit-title">{{ isEdit ? '编辑商户' : '添加商户' }}</span>
        <el-button class="bm-edit-close" link aria-label="关闭" @click="dialogVisible = false">
          <el-icon :size="20"><Close /></el-icon>
        </el-button>
      </div>
      <div class="bm-form-scroll">
        <!-- 第一部分：基本信息 -->
        <div class="bm-section-title">基本信息</div>
        <el-form :model="form" label-width="110px" label-position="right">
          <el-row :gutter="16">
            <el-col :span="12">
              <el-form-item label="商户分类">
                <el-select v-model="form.cid" placeholder="--请选择--" clearable style="width: 100%">
                  <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="商户名称" required>
                <el-input v-model="form.name" placeholder="请输入商户名称" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="登录账号" required>
                <el-input v-model="form.un" placeholder="商户后台登录账号" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item :label="isEdit ? '登录密码' : '登录密码'" :required="!isEdit">
                <el-input
                  v-model="form.pwd"
                  type="password"
                  show-password
                  :placeholder="isEdit ? '留空则不修改密码' : '请设置登录密码'"
                />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="抽成费率">
                <el-input v-model.number="form.feepercent" type="number">
                  <template #append>%</template>
                </el-input>
                <div class="bm-tip">平台收取的佣金比例，如设置10%，平台抽成10%，商户得90%</div>
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="子商户号收款">
                <el-radio-group v-model="form.wxpayst">
                  <el-radio :value="0">关闭</el-radio>
                  <el-radio :value="1">开启</el-radio>
                </el-radio-group>
                <div class="bm-tip">开启后可设置子商户号独立收款结算，通过分账扣除（不能大于30%）</div>
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="联系人">
                <el-input v-model="form.linkman" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="联系电话">
                <el-input v-model="form.linktel" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="客服电话">
                <el-input v-model="form.tel" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="客服链接">
                <el-input v-model="form.kfurl" placeholder="不填写则使用商城内部客服系统" />
              </el-form-item>
            </el-col>
            <el-col :span="24">
              <el-form-item label="商户LOGO">
                <el-button @click="logoPickerVisible = true">上传图片</el-button>
                <span class="bm-hint">建议尺寸：640×640像素</span>
                <div v-if="form.logo" class="bm-thumb-box">
                  <el-image :src="form.logo" fit="cover" class="bm-thumb" :preview-src-list="[form.logo]" preview-teleported />
                  <el-button link type="danger" @click="form.logo = ''">删除</el-button>
                </div>
              </el-form-item>
            </el-col>
            <el-col :span="24">
              <el-form-item label="商户照片">
                <el-button @click="picsPickerVisible = true">批量上传</el-button>
                <span class="bm-hint">建议尺寸：600×340像素</span>
                <div class="bm-multi-imgs">
                  <div v-for="(u, idx) in form.pics" :key="u" class="bm-multi-item">
                    <el-image :src="u" fit="cover" class="bm-thumb" :preview-src-list="form.pics" :initial-index="idx" preview-teleported />
                    <el-button link type="danger" @click="removeAt(form.pics, idx)">删除</el-button>
                  </div>
                </div>
              </el-form-item>
            </el-col>
          </el-row>
        </el-form>

        <!-- 第二部分：描述 / 简介 / 地址 -->
        <div class="bm-section-title">商户描述与地址</div>
        <el-form :model="form" label-width="110px" label-position="right">
          <el-form-item label="商户描述">
            <el-input v-model="form.desc" placeholder="一句话商户描述" />
          </el-form-item>
          <el-form-item label="商户简介">
            <SimpleRichEditor v-model="form.content" />
          </el-form-item>
          <el-form-item label="商户地址" required>
            <div class="bm-region">
              <el-select v-model="form.province" placeholder="请选择省" @change="onProvinceChange">
                <el-option v-for="p in areaProvinces" :key="p.name" :label="p.name" :value="p.name" />
              </el-select>
              <el-select v-model="form.city" placeholder="请选择市" @change="onCityChange">
                <el-option v-for="c in cityList" :key="c.name" :label="c.name" :value="c.name" />
              </el-select>
              <el-select v-model="form.district" placeholder="请选择地区">
                <el-option v-for="a in areaList" :key="a" :label="a" :value="a" />
              </el-select>
            </div>
          </el-form-item>
          <el-form-item label="详细地址">
            <el-input v-model="form.address" placeholder="街道、门牌号等详细地址" />
          </el-form-item>
          <el-form-item label="经纬度">
            <div class="bm-lnglat">
              <el-input v-model="form.longitude" placeholder="经度，如 116.397829" />
              <el-input v-model="form.latitude" placeholder="纬度，如 39.903580" />
              <el-button @click="openMapPicker">选择坐标</el-button>
            </div>
            <div class="bm-tip">点击「选择坐标」在地图上选点，经纬度自动回填，也可手动输入</div>
          </el-form-item>
          <el-form-item label="快递鸟即时配送">
            <el-radio-group v-model="form.kdniao_intsend">
              <el-radio :value="0">使用平台</el-radio>
              <el-radio :value="1">独立配置</el-radio>
            </el-radio-group>
            <div class="bm-kdniao">
              <div>使用平台：使用平台账号，多商户的配送费用同平台设置，商户的配送费通过商户余额扣除。</div>
              <div>独立配置：商户自己注册快递鸟账号使用，平台无法对该商户的配送费进行加价。</div>
              <div class="bm-kdniao-warn">设置后不要随意切换，否则会导致订单查询出错</div>
            </div>
          </el-form-item>
        </el-form>

        <!-- 第三部分：其他设置 -->
        <div class="bm-section-title">其他设置</div>
        <el-form :model="form" label-width="110px" label-position="right">
          <el-form-item label="证明材料">
            <el-button @click="zmPickerVisible = true">批量上传</el-button>
            <div class="bm-multi-imgs">
              <div v-for="(u, idx) in form.zhengming" :key="u" class="bm-multi-item">
                <el-image :src="u" fit="cover" class="bm-thumb" :preview-src-list="form.zhengming" :initial-index="idx" preview-teleported />
                <el-button link type="danger" @click="removeAt(form.zhengming, idx)">删除</el-button>
              </div>
            </div>
          </el-form-item>
          <el-row :gutter="16">
            <el-col :span="12">
              <el-form-item label="到期时间">
                <el-date-picker
                  v-model="form.endtime"
                  type="date"
                  value-format="YYYY-MM-DD"
                  placeholder="选择到期日期"
                  style="width: 100%"
                />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="序号">
                <el-input v-model.number="form.sort" type="number" />
                <div class="bm-tip">用于排序，越大越靠前</div>
              </el-form-item>
            </el-col>
            <el-col :span="24">
              <el-form-item label="状态">
                <el-radio-group v-model="form.status">
                  <el-radio :value="0">待审核</el-radio>
                  <el-radio :value="1">已通过</el-radio>
                  <el-radio :value="2">已驳回</el-radio>
                  <el-radio :value="3">已过期</el-radio>
                </el-radio-group>
              </el-form-item>
            </el-col>
          </el-row>
        </el-form>

        <!-- 第四/五部分：权限设置 -->
        <div class="bm-section-title">权限设置</div>
        <el-form label-width="110px" label-position="right">
          <el-form-item label="后台权限">
            <div class="ua">
              <div class="ua-all">
                <el-checkbox
                  :model-value="listAllChecked(form.authKeys, allAuthOpts)"
                  :indeterminate="listIndeterminate(form.authKeys, allAuthOpts)"
                  @change="(c: any) => setListAll(form.authKeys, allAuthOpts, c)"
                >全部选择</el-checkbox>
              </div>
              <div v-for="g in businessAuthGroups" :key="g.name" class="ua-group">
                <div class="ua-gtitle">
                  <el-checkbox
                    :model-value="listAllChecked(form.authKeys, groupOpts(g))"
                    :indeterminate="listIndeterminate(form.authKeys, groupOpts(g))"
                    @change="(c: any) => setListAll(form.authKeys, groupOpts(g), c)"
                  >{{ g.name }}</el-checkbox>
                </div>
                <el-checkbox-group v-model="form.authKeys" class="ua-body">
                  <template v-for="(b, bi) in authBlocks(g)" :key="bi">
                    <div class="ua-row">
                      <div v-if="b.kind === 'group'" class="ua-label">{{ b.name }}</div>
                      <div class="ua-leaves" :class="{ 'ua-leaves--flat': b.kind === 'flat' }">
                        <div v-for="l in b.leaves" :key="l.value" class="ua-item">
                          <el-checkbox :value="l.value">{{ l.name }}</el-checkbox>
                        </div>
                      </div>
                    </div>
                  </template>
                </el-checkbox-group>
              </div>
            </div>
          </el-form-item>
        </el-form>

        <div class="bm-section-title">手机端权限</div>
        <el-form label-width="110px" label-position="right">
          <el-form-item label="手机端权限">
            <div class="ua">
              <div class="ua-all">
                <el-checkbox
                  :model-value="mobileAllChecked()"
                  :indeterminate="mobileAllIndeterminate()"
                  @change="(c: any) => setMobileAll(c)"
                >全部选择</el-checkbox>
              </div>
              <div class="ua-group">
                <div class="ua-gtitle">
                  <el-checkbox
                    :model-value="listAllChecked(form.wxKeys, wxViewOptions)"
                    :indeterminate="listIndeterminate(form.wxKeys, wxViewOptions)"
                    @change="(c: any) => setListAll(form.wxKeys, wxViewOptions, c)"
                  >查看权限</el-checkbox>
                </div>
                <el-checkbox-group v-model="form.wxKeys" class="ua-body">
                  <div class="ua-row">
                    <div class="ua-leaves ua-leaves--flat">
                      <div v-for="o in wxViewOptions" :key="o.value" class="ua-item">
                        <el-checkbox :value="o.value">{{ o.name }}</el-checkbox>
                      </div>
                    </div>
                  </div>
                </el-checkbox-group>
              </div>
              <div class="ua-group">
                <div class="ua-gtitle">
                  <el-checkbox
                    :model-value="listAllChecked(form.noticeKeys, noticeOptions)"
                    :indeterminate="listIndeterminate(form.noticeKeys, noticeOptions)"
                    @change="(c: any) => setListAll(form.noticeKeys, noticeOptions, c)"
                  >接收通知权限</el-checkbox>
                </div>
                <el-checkbox-group v-model="form.noticeKeys" class="ua-body">
                  <div class="ua-row">
                    <div class="ua-leaves ua-leaves--flat">
                      <div v-for="o in noticeOptions" :key="o.value" class="ua-item">
                        <el-checkbox :value="o.value">{{ o.name }}</el-checkbox>
                      </div>
                    </div>
                  </div>
                </el-checkbox-group>
              </div>
              <div class="ua-group">
                <div class="ua-gtitle">
                  <el-checkbox
                    :model-value="listAllChecked(form.hexiaoKeys, hexiaoOptions)"
                    :indeterminate="listIndeterminate(form.hexiaoKeys, hexiaoOptions)"
                    @change="(c: any) => setListAll(form.hexiaoKeys, hexiaoOptions, c)"
                  >核销权限</el-checkbox>
                </div>
                <el-checkbox-group v-model="form.hexiaoKeys" class="ua-body">
                  <div class="ua-row">
                    <div class="ua-leaves ua-leaves--flat">
                      <div v-for="o in hexiaoOptions" :key="o.value" class="ua-item">
                        <el-checkbox :value="o.value">{{ o.name }}</el-checkbox>
                      </div>
                    </div>
                  </div>
                </el-checkbox-group>
              </div>
            </div>
          </el-form-item>
        </el-form>
      </div>

      <div class="bm-edit-footer">
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">提交</el-button>
      </div>
    </div>

    <!-- 充值 -->
    <el-dialog v-model="rechargeVisible" title="商户充值" width="380px" append-to-body>
      <el-input v-model.number="rechargeMoney" type="number" placeholder="请输入充值金额">
        <template #prepend>金额</template>
      </el-input>
      <template #footer>
        <el-button @click="rechargeVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmRecharge">确认充值</el-button>
      </template>
    </el-dialog>

    <!-- 登录地址 -->
    <el-dialog v-model="loginVisible" title="登录地址" width="420px" append-to-body>
      <div class="bm-login">
        <div class="bm-qr">
          <el-image v-if="qrImg" :src="qrImg" fit="contain" class="bm-qr-img" />
          <div v-else class="bm-qr-loading">二维码生成中…</div>
        </div>
        <div class="bm-url-line">
          <span>手机端：{{ mobileUrl }}</span>
          <el-button link type="primary" @click="copyText(mobileUrl)">复制</el-button>
        </div>
        <div class="bm-url-line">
          <span>电脑端：{{ pcUrl }}</span>
          <el-button link type="primary" @click="copyText(pcUrl)">复制</el-button>
        </div>
      </div>
    </el-dialog>

    <!-- 复用：上传图片/图标选择器 -->
    <ImgIconPicker v-model:visible="logoPickerVisible" default-tab="upload" @pick="onLogoPick" />
    <ImgIconPicker v-model:visible="picsPickerVisible" :multi="true" default-tab="upload" @pick="onPicsPick" />
    <ImgIconPicker v-model:visible="zmPickerVisible" :multi="true" default-tab="upload" @pick="onZmPick" />
    <MapPicker
      v-model:visible="mapPickerVisible"
      :map-key="mapConfig.map_key"
      :map-jscode="mapConfig.map_jscode"
      :map-qq-key="mapConfig.map_qq_key"
      :init-lng="form.longitude"
      :init-lat="form.latitude"
      @pick="onMapPick"
    />
  </div>
</template>

<style scoped>
.bm-filter-card {
  margin-bottom: 12px;
}
.bm-filter-form {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  overflow-x: auto;
}
.bm-filter-form :deep(.el-form-item) {
  margin-right: 10px;
  margin-bottom: 8px;
  flex-shrink: 0;
}
.bm-filter-form :deep(.el-form-item__label) {
  white-space: nowrap;
  padding-right: 6px;
}
.bm-filter-card :deep(.el-card__body) {
  padding: 10px 14px 2px;
}
.bm-toolbar {
  margin-bottom: 12px;
}
.bm-logo {
  width: 44px;
  height: 44px;
  border-radius: 4px;
}
.bm-sub {
  color: #909399;
  font-size: 12px;
}
.bm-money {
  color: #409eff;
}
.bm-empty {
  color: #c0c4cc;
}
.bm-state-0 {
  color: #e6a23c;
}
.bm-state-1 {
  color: #19be6b;
}
.bm-state-2,
.bm-expired {
  color: #f56c6c;
}
.bm-state-3 {
  color: #909399;
}
.bm-open {
  color: #19be6b;
}
.bm-close {
  color: #909399;
}
.bm-pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
.bm-edit {
  background: #fff;
  border-radius: 6px;
  padding: 16px 20px 20px;
  position: relative;
}
.bm-edit-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #ebeef5;
  padding-bottom: 12px;
  margin-bottom: 16px;
}
.bm-edit-title {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}
.bm-edit-close {
  color: #909399;
}
.bm-edit-close:hover {
  color: #409eff;
}
.bm-form-scroll {
  padding-right: 8px;
}
.bm-edit-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  border-top: 1px solid #ebeef5;
  padding-top: 16px;
  margin-top: 8px;
}
.bm-section-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  border-left: 3px solid #409eff;
  padding-left: 8px;
  margin: 6px 0 14px;
}
.bm-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.4;
}
.bm-hint {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
}
.bm-thumb-box {
  margin-top: 8px;
}
.bm-thumb {
  width: 96px;
  height: 96px;
  border-radius: 4px;
  border: 1px solid #ebeef5;
}
.bm-multi-imgs {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 8px;
}
.bm-multi-item {
  text-align: center;
}
.bm-multi-item .bm-thumb {
  width: 80px;
  height: 80px;
}
.bm-region {
  display: flex;
  gap: 8px;
  width: 100%;
}
.bm-region .el-select {
  flex: 1;
}
.bm-lnglat {
  display: flex;
  gap: 8px;
  width: 100%;
}
.bm-lnglat .el-input {
  flex: 1 1 auto;
  min-width: 0;
}
.bm-lnglat .el-button {
  flex: 0 0 auto;
}
.bm-kdniao {
  flex: 0 0 100%;
  width: 100%;
  margin-top: 4px;
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}
.bm-kdniao-warn {
  color: #f56c6c;
}
.ua {
  width: 100%;
}
.ua-all {
  font-weight: 600;
  color: #303030;
  margin-bottom: 2px;
}
.ua-group {
  margin-top: 2px;
}
.ua-gtitle {
  margin-top: 8px;
  font-weight: 600;
  color: #303030;
  line-height: 28px;
}
.ua-body {
  margin-left: 20px;
}
.ua-row {
  display: flex;
  align-items: flex-start;
}
.ua-label {
  flex: 0 0 96px;
  font-size: 14px;
  width: 96px;
  color: #606266;
  line-height: 28px;
}
.ua-leaves {
  flex: 1 1 auto;
  display: flex;
  flex-wrap: wrap;
  min-width: 0;
}
.ua-leaves--flat {
  flex: 1 1 100%;
}
.ua-item {
  flex: 0 0 auto;
  min-width: 118px;
  height: 28px;
}
.ua-body :deep(.el-checkbox) {
  margin-right: 0;
  height: 28px;
}
.ua-body :deep(.el-checkbox__label) {
  white-space: nowrap;
  padding-left: 8px;
}
.bm-login {
  text-align: center;
}
.bm-qr {
  display: flex;
  justify-content: center;
  margin-bottom: 14px;
}
.bm-qr-img {
  width: 240px;
  height: 240px;
}
.bm-qr-loading {
  width: 240px;
  height: 240px;
  line-height: 240px;
  color: #909399;
}
.bm-url-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  color: #606266;
  margin-top: 8px;
  gap: 8px;
}
.bm-url-line span {
  word-break: break-all;
  text-align: left;
}
</style>
