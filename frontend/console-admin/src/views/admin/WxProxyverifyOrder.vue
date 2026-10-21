<script setup lang="ts">
// 微信代认证 - 认证列表（对齐点大：提示框 + 两行搜索 + 规定列；添加/重新提交/查看详情平铺主区域）
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { useAuthStore } from '../../stores/auth'
import {
  checkNickName,
  chooseIdentity,
  getProxyverifyAuthorizerList,
  getProxyverifyOrderList,
  queryTaskStatus,
  saveOrderRemark,
  saveProxyverifyOrder,
  type IdentityNode,
  type ProxyverifyAuthorizer,
  type ProxyverifyOrder,
  type ProxyverifyOrderQuery,
} from '../../api/wxproxyverify'

const authStore = useAuthStore()
const isPlatform = computed(() => authStore.adminProfile?.super === 1)

// ============ 常量 ============
const CUSTOMER_TYPES = [
  { value: 1, label: '企业' },
  { value: 12, label: '个体工商户' },
  { value: 15, label: '个人' },
]
const TASK_STATUS_OPTIONS = [
  { value: -1, label: '未提交' },
  { value: 0, label: '初始状态' },
  { value: 1, label: '任务超时，24小时内有效' },
  { value: 2, label: '用户授权拒绝' },
  { value: 3, label: '用户授权同意' },
  { value: 4, label: '发起人脸流程' },
  { value: 5, label: '人脸认证失败' },
  { value: 6, label: '人脸认证ok' },
  { value: 7, label: '已下发手机验证码' },
  { value: 8, label: '手机验证失败' },
  { value: 9, label: '手机验证成功' },
  { value: 11, label: '创建认证审核单失败' },
  { value: 12, label: '创建认证审核审核单成功' },
  { value: 14, label: '验证失败' },
  { value: 15, label: '等待支付' },
]
const APPLY_STATUS_OPTIONS = [
  { value: -1, label: '未提交' },
  { value: 0, label: '审核单不存在' },
  { value: 1, label: '待支付' },
  { value: 2, label: '审核中' },
  { value: 3, label: '打回重填' },
  { value: 4, label: '认证通过' },
  { value: 5, label: '认证最终失败' },
]
const PAY_TYPES = [
  { value: 1, label: '消耗服务商预购包' },
  { value: 2, label: '小程序开发者自行支付' },
]

const CUSTOMER_TYPE_MAP: Record<number, string> = { 1: '企业', 12: '个体工商户', 15: '个人' }
const INVOICE_MAP: Record<number, string> = { 1: '不开发票', 2: '电子发票', 4: '增值税专票（数电类型）' }
const ACCOUNT_NAME_TYPE_MAP: Record<number, string> = { 1: '基于自选词汇命名', 2: '基于商标命名' }
const PAY_TYPE_MAP: Record<number, string> = { 1: '消耗服务商预购包', 2: '小程序开发者自行支付' }

// ============ 列表 ============
const loading = ref(false)
const list = ref<ProxyverifyOrder[]>([])
const total = ref(0)
const query = reactive<ProxyverifyOrderQuery>({ page: 1, pagesize: 10 })
const presetAppid = ref('')

async function load() {
  loading.value = true
  try {
    const res = await getProxyverifyOrderList(query)
    list.value = res.list || []
    total.value = res.total || 0
  } finally {
    loading.value = false
  }
}
function handleSearch() {
  query.page = 1
  load()
}
function handlePage(p: number) {
  query.page = p
  load()
}
function handleSize(s: number) {
  query.pagesize = s
  query.page = 1
  load()
}

async function handleQueryStatus(row: ProxyverifyOrder) {
  const res = await queryTaskStatus(row.id)
  ElMessage.success(`进度已更新：${res.task_status_name} / ${res.apply_status_name}`)
  load()
}

function copyAuthUrl(url: string) {
  const ta = document.createElement('textarea')
  ta.value = url
  ta.style.position = 'fixed'
  ta.style.opacity = '0'
  document.body.appendChild(ta)
  ta.select()
  try {
    document.execCommand('copy')
    ElMessage.success('复制成功')
  } catch {
    ElMessage.warning('复制失败，请手动选择复制')
  }
  document.body.removeChild(ta)
}

// ============ 备注（小弹窗） ============
const remarkVisible = ref(false)
const remarkSaving = ref(false)
const remarkForm = reactive({ id: 0, account_name: '', remark: '' })
function openRemark(row: ProxyverifyOrder) {
  remarkForm.id = row.id
  remarkForm.account_name = row.account_name
  remarkForm.remark = row.remark
  remarkVisible.value = true
}
async function handleRemarkSave() {
  remarkSaving.value = true
  try {
    await saveOrderRemark(remarkForm.id, remarkForm.remark)
    ElMessage.success('操作成功')
    remarkVisible.value = false
    load()
  } finally {
    remarkSaving.value = false
  }
}

// ============ 添加 / 重新提交 / 查看详情（平铺主区域） ============
const viewMode = ref<'list' | 'edit'>('list')
const editReadonly = ref(false)
const editTitle = ref('添加认证')
const step = ref(0)
const submitting = ref(false)
const authorizers = ref<ProxyverifyAuthorizer[]>([])
const identityList = ref<IdentityNode[]>([])
const nameCheckTip = ref('')

const form = reactive({
  id: 0,
  authorizer_appid: '',
  customer_type: 1,
  company_name: '',
  company_pic: '',
  company_license_number: '',
  company_address: '',
  company_tel: '',
  contact_name: '',
  contact_email: '',
  contact_mobile: '',
  invoice_type: 1,
  electronic_id: '',
  electronic_desc: '',
  vat_enterprise_phone: '',
  vat_id: '',
  vat_enterprise_address: '',
  vat_desc: '',
  vat_bank_name: '',
  vat_bank_account: '',
  qualification_other: [] as string[],
  account_name: '',
  account_name_type: 1,
  account_supplemental: [] as string[],
  pay_type: 2,
  auth_identification: '',
  auth_ident_material: [] as string[],
})

async function loadAuthorizers() {
  const res = await getProxyverifyAuthorizerList({ page: 1, pagesize: 100 })
  authorizers.value = res.list || []
}

async function openCreate() {
  Object.assign(form, {
    id: 0,
    authorizer_appid: presetAppid.value || sessionStorage.getItem('wx_pv_new_appid') || '',
    customer_type: 1,
    company_name: '',
    company_pic: '',
    company_license_number: '',
    company_address: '',
    company_tel: '',
    contact_name: '',
    contact_email: '',
    contact_mobile: '',
    invoice_type: 1,
    electronic_id: '',
    electronic_desc: '',
    vat_enterprise_phone: '',
    vat_id: '',
    vat_enterprise_address: '',
    vat_desc: '',
    vat_bank_name: '',
    vat_bank_account: '',
    qualification_other: [],
    account_name: sessionStorage.getItem('wx_pv_new_nick') || '',
    account_name_type: 1,
    account_supplemental: [],
    pay_type: 2,
    auth_identification: '',
    auth_ident_material: [],
  })
  sessionStorage.removeItem('wx_pv_new_appid')
  sessionStorage.removeItem('wx_pv_new_nick')
  nameCheckTip.value = ''
  step.value = 0
  editReadonly.value = false
  editTitle.value = '添加认证'
  await loadAuthorizers()
  viewMode.value = 'edit'
}

function openOrder(row: ProxyverifyOrder) {
  const resubmit = row.task_status === -1 || row.apply_status === 3
  Object.assign(form, {
    id: row.id,
    authorizer_appid: row.authorizer_appid,
    customer_type: Number(row.customer_type),
    company_name: row.company_name || '',
    company_pic: row.company_pic || '',
    company_license_number: row.company_license_number || '',
    company_address: row.company_address || '',
    company_tel: row.company_tel || '',
    contact_name: row.contact_name || '',
    contact_email: row.contact_email || '',
    contact_mobile: row.contact_mobile || '',
    invoice_type: Number(row.invoice_type) || 1,
    electronic_id: row.electronic_id || '',
    electronic_desc: row.electronic_desc || '',
    vat_enterprise_phone: row.vat_enterprise_phone || '',
    vat_id: row.vat_id || '',
    vat_enterprise_address: row.vat_enterprise_address || '',
    vat_desc: row.vat_desc || '',
    vat_bank_name: row.vat_bank_name || '',
    vat_bank_account: row.vat_bank_account || '',
    qualification_other: row.qualification_other ? row.qualification_other.split(',').filter(Boolean) : [],
    account_name: row.account_name || '',
    account_name_type: Number(row.account_name_type) || 1,
    account_supplemental: row.account_supplemental ? row.account_supplemental.split(',').filter(Boolean) : [],
    pay_type: Number(row.pay_type) || 2,
    auth_identification: row.auth_identification || '',
    auth_ident_material: row.auth_ident_material ? row.auth_ident_material.split(',').filter(Boolean) : [],
  })
  nameCheckTip.value = ''
  step.value = 0
  editReadonly.value = !resubmit
  editTitle.value = resubmit ? '重新提交认证' : '查看详情'
  loadAuthorizers().then(() => {
    viewMode.value = 'edit'
  })
}

function backToList() {
  viewMode.value = 'list'
  load()
}

const isCompany = computed(() => form.customer_type === 1 || form.customer_type === 12)
const identityOptions = computed(() => identityList.value.filter((n) => n.deep === 1))

async function loadIdentity() {
  if (form.customer_type !== 15 || identityList.value.length) return
  const res = await chooseIdentity(form.authorizer_appid)
  identityList.value = res.list || []
}

function nextFromType() {
  if (![1, 12, 15].includes(form.customer_type)) {
    ElMessage.warning('请选择主体类型')
    return
  }
  loadIdentity()
  step.value = 1
}

async function handleCheckName() {
  if (!form.account_name.trim()) {
    ElMessage.warning('请填写小程序账号名称')
    return
  }
  const res = await checkNickName(form.authorizer_appid, form.account_name.trim())
  nameCheckTip.value = res.msg
  if (res.hit_keyword) {
    ElMessage.warning(res.msg)
  } else {
    ElMessage.success(res.msg)
  }
}

function nextFromName() {
  if (!form.account_name.trim()) {
    ElMessage.warning('请填写小程序账号名称')
    return
  }
  step.value = 2
}

// ============ 选图 ============
const imgPickerVisible = ref(false)
const imgPickerMulti = ref(false)
let imgPickerSetter: ((urls: string[]) => void) | null = null
function openImgPicker(setter: (urls: string[]) => void, multi = false) {
  imgPickerMulti.value = multi
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  imgPickerSetter?.(urls)
  imgPickerSetter = null
}

// ============ 提交 ============
async function handleSubmit() {
  if (!form.contact_name.trim() || !form.contact_email.trim()) {
    ElMessage.warning('认证联系人姓名与邮箱必须填写')
    return
  }
  if (isCompany.value && !form.company_pic) {
    ElMessage.warning('请上传工商营业执照')
    return
  }
  submitting.value = true
  try {
    const res = await saveProxyverifyOrder({
      id: form.id || undefined,
      authorizer_appid: form.authorizer_appid,
      customer_type: form.customer_type,
      company_name: form.company_name,
      company_pic: form.company_pic,
      company_license_number: form.company_license_number,
      company_address: form.company_address,
      company_tel: form.company_tel,
      contact_name: form.contact_name,
      contact_email: form.contact_email,
      contact_mobile: form.contact_mobile,
      invoice_type: form.invoice_type,
      electronic_id: form.electronic_id,
      electronic_desc: form.electronic_desc,
      vat_enterprise_phone: form.vat_enterprise_phone,
      vat_id: form.vat_id,
      vat_enterprise_address: form.vat_enterprise_address,
      vat_desc: form.vat_desc,
      vat_bank_name: form.vat_bank_name,
      vat_bank_account: form.vat_bank_account,
      qualification_other: form.qualification_other.join(','),
      account_name: form.account_name,
      account_name_type: form.account_name_type,
      account_supplemental: form.account_supplemental.join(','),
      pay_type: form.pay_type,
      auth_identification: form.auth_identification,
      auth_ident_material: form.auth_ident_material.join(','),
    })
    ElMessage.success(`提交成功，认证任务号：${res.taskid}`)
    backToList()
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  presetAppid.value = sessionStorage.getItem('wx_pv_new_appid') || ''
  load()
})
</script>

<template>
  <div class="wxpv-order">
    <!-- ============ 列表 ============ -->
    <template v-if="viewMode === 'list'">
      <el-alert type="warning" :closable="false" class="notice">
        <template #title>
          <div class="notice-lines">
            <template v-if="isPlatform">
              <div>认证功能要使用需要设置几处：</div>
              <div>1、扩展-微信代认证-系统设置-状态及各类型代缴次数设置；</div>
              <div>2、控制台-系统设置-微信认证统一设置；</div>
              <div>3、控制台-用户列表-操作-微信认证中单独设置（此处给子平台使用，若不给使用则无需设置）</div>
              <div>4、控制台-服务商配置，这里服务商主体需要和服务市场账号主体一致；</div>
            </template>
            <div>认证任务创建后需要在24小时内完成授权与支付，过期后需要重新创建任务</div>
            <div>如果授权任务已经到达支付页面，为了避免临界情况支付完成事件延迟，该小程序在6小时内不能重新创建认证任务，即使上个任务已经超过24小时</div>
          </div>
        </template>
      </el-alert>

      <el-card v-if="presetAppid" shadow="never" class="add-bar">
        <el-button type="success" @click="openCreate">添加认证</el-button>
      </el-card>

      <el-card shadow="never" class="search-card">
        <div class="search-row">
          <el-select v-model="query.customer_type" placeholder="机构类型" class="field-xs" clearable>
            <el-option label="全部" value="" />
            <el-option v-for="t in CUSTOMER_TYPES" :key="t.value" :label="t.label" :value="String(t.value)" />
          </el-select>
          <el-input v-model="query.contact_name" placeholder="认证联系人姓名" class="field-sm" clearable @keyup.enter="handleSearch" />
          <el-input v-model="query.account_name" placeholder="小程序账号名称" class="field-sm" clearable @keyup.enter="handleSearch" />
          <el-select v-model="query.pay_type" placeholder="支付方式" class="field-sm" clearable>
            <el-option label="全部" value="" />
            <el-option v-for="p in PAY_TYPES" :key="p.value" :label="p.label" :value="String(p.value)" />
          </el-select>
          <el-select v-model="query.task_status" placeholder="任务状态" class="field-md" clearable>
            <el-option label="全部" value="" />
            <el-option v-for="s in TASK_STATUS_OPTIONS" :key="s.value" :label="s.label" :value="String(s.value)" />
          </el-select>
        </div>
        <div class="search-row">
          <el-select v-model="query.apply_status" placeholder="审核单状态" class="field-sm" clearable>
            <el-option label="全部" value="" />
            <el-option v-for="s in APPLY_STATUS_OPTIONS" :key="s.value" :label="s.label" :value="String(s.value)" />
          </el-select>
          <el-date-picker
            v-model="query.ctime"
            type="daterange"
            range-separator="~"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            class="field-range"
          />
          <el-button type="success" circle @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="15" height="15" fill="currentColor"><path d="M908.1 353.1l-253.9-36.9L540.7 86.1c-3.1-6.3-8.2-11.4-14.5-14.5-15.8-7.8-35-1.3-42.9 14.5L369.8 316.2l-253.9 36.9c-7 1-13.4 4.3-18.3 9.3a32.05 32.05 0 0 0 .6 45.3l183.7 179.1-43.4 252.9a31.95 31.95 0 0 0 46.4 33.7L512 754l227.1 119.4c6.2 3.3 13.4 3.2 19.4-.2 6-3.4 10.4-9.3 12-16 .7-2.9.7-6.1 0-9.2l-43.4-252.9 183.7-179.1a32 32 0 0 0-10.7-52.9z" /></svg>
          </el-button>
        </div>
      </el-card>

      <el-card shadow="never">
        <el-table v-loading="loading" :data="list" border>
          <el-table-column type="selection" width="40" align="center" />
          <el-table-column label="机构类型" width="90">
            <template #default="{ row }">{{ CUSTOMER_TYPE_MAP[Number(row.customer_type)] || '-' }}</template>
          </el-table-column>
          <el-table-column label="认证联系人信息" min-width="150">
            <template #default="{ row }">
              <div class="contact-cell">
                <div>认证联系人姓名：</div>
                <div>{{ row.contact_name || '-' }}</div>
                <div>认证联系人邮箱：</div>
                <div>{{ row.contact_email || '-' }}</div>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="发票类型" min-width="150">
            <template #default="{ row }">{{ INVOICE_MAP[Number(row.invoice_type)] || '-' }}</template>
          </el-table-column>
          <el-table-column label="小程序账号类型" width="140">
            <template #default="{ row }">{{ ACCOUNT_NAME_TYPE_MAP[Number(row.account_name_type)] || '-' }}</template>
          </el-table-column>
          <el-table-column prop="account_name" label="小程序账号名称" min-width="140" show-overflow-tooltip />
          <el-table-column label="支付方式" min-width="150">
            <template #default="{ row }">{{ PAY_TYPE_MAP[Number(row.pay_type)] || '-' }}</template>
          </el-table-column>
          <el-table-column label="任务状态" min-width="200">
            <template #default="{ row }">
              <div>{{ row.task_status_name }}</div>
              <div v-if="row.auth_url" class="auth-url-block">
                <div class="auth-url-label">小程序管理员授权链接：</div>
                <div class="auth-url-text">{{ row.auth_url }}</div>
                <el-button size="small" @click="copyAuthUrl(row.auth_url)">一键复制</el-button>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="审核单状态" min-width="170">
            <template #default="{ row }">
              <div class="apply-cell">
                <span :class="row.apply_status === 4 ? 'ck-pass' : ''">{{ row.apply_status_name }}</span>
                <el-button
                  v-if="row.apply_status >= 1 && row.apply_status <= 3"
                  link
                  class="refresh-btn"
                  title="刷新状态"
                  @click="handleQueryStatus(row)"
                >
                  <svg viewBox="0 0 1024 1024" width="14" height="14" fill="currentColor"><path d="M909.1 209.3l-56.4 44.1C775.8 155.1 656.2 92 521.9 92 289 92 99.5 281.3 99.2 513.9c-.3 232.8 189 422.1 421.9 422.1 188.4 0 347.5-123.4 404.4-297 1.5-4.7-.9-9.8-5.6-11.4l-33.3-11.2c-4.7-1.5-9.8.9-11.4 5.6-48.1 147.7-183.9 252.1-354.1 252.1-200 0-362.1-162.1-362.1-362.1S321.9 150 521.9 150c113.7 0 215.3 52.5 283 134.7l-65.7-11.3c-5.2-.9-10.1 2.8-10.8 8-.7 5.2 2.8 10.1 8 10.8l161.8 27.9c4.6.8 9.2-1.5 11.1-5.8l78.7-136.3c2.6-4.7.9-10.7-3.9-13.3-4.6-2.6-10.7-.9-13.3 3.9z" /></svg>
                </el-button>
              </div>
              <div v-if="row.apply_status === 3 && row.refill_reason" class="reason-text">{{ row.refill_reason }}</div>
              <div v-if="row.apply_status === 5 && row.fail_reason" class="reason-text">{{ row.fail_reason }}</div>
            </template>
          </el-table-column>
          <el-table-column prop="remark" label="备注" min-width="100" show-overflow-tooltip />
          <el-table-column prop="createtime_text" label="提交时间" width="140" />
          <el-table-column label="操作" width="150">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="openOrder(row)">
                  {{ row.task_status === -1 || row.apply_status === 3 ? '重新提交' : '查看详情' }}
                </el-button>
                <el-button link type="primary" @click="openRemark(row)">备注</el-button>
              </div>
            </template>
          </el-table-column>
          <template #empty>无数据</template>
        </el-table>
        <el-pagination
          class="pagination"
          background
          layout="total, sizes, prev, pager, next"
          :total="total"
          :current-page="query.page"
          :page-size="query.pagesize"
          :page-sizes="[10, 20, 50]"
          @current-change="handlePage"
          @size-change="handleSize"
        />
      </el-card>
    </template>

    <!-- ============ 添加/编辑/详情（平铺主区域） ============ -->
    <div v-else class="goods-edit">
      <div class="ge-head">
        <div class="ge-title">{{ editTitle }}</div>
        <el-button class="ge-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <el-steps :active="step" finish-status="success" simple class="edit-steps">
        <el-step title="选择主体类型" />
        <el-step title="名称校验" />
        <el-step title="填写资料并提交" />
      </el-steps>

      <fieldset :disabled="editReadonly" class="edit-fieldset">
        <!-- 步骤1：主体类型 -->
        <el-form v-if="step === 0" label-width="110px" class="ge-form">
          <el-form-item label="授权小程序" required>
            <el-select v-model="form.authorizer_appid" placeholder="请选择已授权小程序" style="width: 320px">
              <el-option
                v-for="a in authorizers"
                :key="a.id"
                :label="`${a.nick_name || '-'}（${a.authorizer_appid}）`"
                :value="a.authorizer_appid"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="主体类型" required>
            <el-radio-group v-model="form.customer_type">
              <el-radio v-for="t in CUSTOMER_TYPES" :key="t.value" :value="t.value">{{ t.label }}</el-radio>
            </el-radio-group>
          </el-form-item>
        </el-form>

        <!-- 步骤2：名称校验 -->
        <el-form v-if="step === 1" label-width="110px" class="ge-form">
          <el-form-item label="账号名称" required>
            <div class="inline-block">
              <el-input v-model="form.account_name" maxlength="30" show-word-limit style="width: 300px" />
              <el-button type="primary" plain @click="handleCheckName">校验名称</el-button>
            </div>
            <div v-if="nameCheckTip" class="name-tip">{{ nameCheckTip }}</div>
          </el-form-item>
          <el-form-item label="命名类型">
            <el-radio-group v-model="form.account_name_type">
              <el-radio :value="1">基于自选词汇命名</el-radio>
              <el-radio :value="2">基于商标命名</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="命名补充材料">
            <div class="upload-block">
              <el-image
                v-for="(img, i) in form.account_supplemental"
                :key="img"
                :src="img"
                class="up-img"
                :preview-src-list="form.account_supplemental"
                :initial-index="i"
              />
              <el-button size="small" @click="openImgPicker((urls) => { urls.forEach((u) => { if (!form.account_supplemental.includes(u)) form.account_supplemental.push(u) }) }, true)">选择图片</el-button>
            </div>
            <div class="form-tip">名称命中关键词或基于商标命名时需提供（如商标注册证）</div>
          </el-form-item>
        </el-form>

        <!-- 步骤3：资料填写 -->
        <el-form v-if="step === 2" label-width="110px" class="ge-form">
          <template v-if="isCompany">
            <el-divider content-position="left">企业信息</el-divider>
            <el-form-item label="企业名称">
              <el-input v-model="form.company_name" maxlength="100" style="width: 320px" />
            </el-form-item>
            <el-form-item label="营业执照" required>
              <div class="upload-block">
                <el-image v-if="form.company_pic" :src="form.company_pic" class="up-img" :preview-src-list="[form.company_pic]" />
                <el-button size="small" @click="openImgPicker((urls) => { if (urls[0]) form.company_pic = urls[0] })">{{ form.company_pic ? '更换' : '选择图片' }}</el-button>
              </div>
            </el-form-item>
            <el-form-item label="信用代码">
              <el-input v-model="form.company_license_number" maxlength="50" style="width: 320px" placeholder="统一社会信用代码" />
            </el-form-item>
            <el-form-item label="企业地址">
              <el-input v-model="form.company_address" maxlength="100" style="width: 320px" />
            </el-form-item>
            <el-form-item label="企业电话">
              <el-input v-model="form.company_tel" maxlength="30" style="width: 320px" />
            </el-form-item>
            <el-form-item label="其他证明材料">
              <div class="upload-block">
                <el-image
                  v-for="(img, i) in form.qualification_other"
                  :key="img"
                  :src="img"
                  class="up-img"
                  :preview-src-list="form.qualification_other"
                  :initial-index="i"
                />
                <el-button size="small" @click="openImgPicker((urls) => { urls.forEach((u) => { if (!form.qualification_other.includes(u)) form.qualification_other.push(u) }) }, true)">选择图片</el-button>
              </div>
            </el-form-item>
          </template>
          <template v-else>
            <el-divider content-position="left">个人认证身份</el-divider>
            <el-form-item label="认证身份">
              <el-select v-model="form.auth_identification" placeholder="可选，从身份选项树选择" clearable style="width: 300px">
                <el-option v-for="n in identityOptions" :key="n.id" :label="n.name" :value="n.name">
                  <span>{{ n.showname }}</span>
                  <span v-if="n.desc" style="color: #999; font-size: 12px; margin-left: 8px">{{ n.desc }}</span>
                </el-option>
              </el-select>
            </el-form-item>
            <el-form-item label="身份证明材料">
              <div class="upload-block">
                <el-image
                  v-for="(img, i) in form.auth_ident_material"
                  :key="img"
                  :src="img"
                  class="up-img"
                  :preview-src-list="form.auth_ident_material"
                  :initial-index="i"
                />
                <el-button size="small" @click="openImgPicker((urls) => { urls.forEach((u) => { if (!form.auth_ident_material.includes(u)) form.auth_ident_material.push(u) }) }, true)">选择图片</el-button>
              </div>
              <div class="form-tip">选择了认证身份时必须上传对应证明材料</div>
            </el-form-item>
          </template>

          <el-divider content-position="left">联系人信息</el-divider>
          <el-form-item label="联系人姓名" required>
            <el-input v-model="form.contact_name" maxlength="30" style="width: 260px" />
          </el-form-item>
          <el-form-item label="联系人邮箱" required>
            <el-input v-model="form.contact_email" maxlength="60" style="width: 260px" />
          </el-form-item>
          <el-form-item label="联系人手机">
            <el-input v-model="form.contact_mobile" maxlength="20" style="width: 260px" placeholder="打回重填时必填" />
          </el-form-item>

          <el-divider content-position="left">发票信息</el-divider>
          <el-form-item label="发票类型">
            <el-radio-group v-model="form.invoice_type">
              <el-radio :value="1">不开发票</el-radio>
              <el-radio :value="2">电子发票</el-radio>
              <el-radio :value="4">增值税专票（数电）</el-radio>
            </el-radio-group>
          </el-form-item>
          <template v-if="form.invoice_type === 2">
            <el-form-item label="纳税识别号" required>
              <el-input v-model="form.electronic_id" maxlength="20" style="width: 260px" placeholder="15/17/18/20位" />
            </el-form-item>
            <el-form-item label="发票备注">
              <el-input v-model="form.electronic_desc" maxlength="100" style="width: 320px" />
            </el-form-item>
          </template>
          <template v-if="form.invoice_type === 4">
            <el-form-item label="企业电话" required>
              <el-input v-model="form.vat_enterprise_phone" maxlength="30" style="width: 260px" />
            </el-form-item>
            <el-form-item label="纳税识别号" required>
              <el-input v-model="form.vat_id" maxlength="20" style="width: 260px" />
            </el-form-item>
            <el-form-item label="注册地址" required>
              <el-input v-model="form.vat_enterprise_address" maxlength="100" style="width: 320px" />
            </el-form-item>
            <el-form-item label="开户银行">
              <el-input v-model="form.vat_bank_name" maxlength="50" style="width: 320px" />
            </el-form-item>
            <el-form-item label="银行账号">
              <el-input v-model="form.vat_bank_account" maxlength="50" style="width: 320px" />
            </el-form-item>
            <el-form-item label="发票备注">
              <el-input v-model="form.vat_desc" maxlength="100" style="width: 320px" />
            </el-form-item>
          </template>

          <el-divider content-position="left">支付方式</el-divider>
          <el-form-item label="支付方式" required>
            <el-radio-group v-model="form.pay_type">
              <el-radio v-for="p in PAY_TYPES" :key="p.value" :value="p.value">{{ p.label }}</el-radio>
            </el-radio-group>
            <div class="form-tip">选择服务商预购包将消耗系统设置中的代缴额度</div>
          </el-form-item>
        </el-form>
      </fieldset>

      <div class="edit-actions">
        <el-button @click="backToList">{{ editReadonly ? '关闭' : '取消' }}</el-button>
        <template v-if="!editReadonly">
          <el-button v-if="step > 0" @click="step--">上一步</el-button>
          <el-button v-if="step === 0" type="primary" @click="nextFromType">下一步</el-button>
          <el-button v-if="step === 1" type="primary" @click="nextFromName">下一步</el-button>
          <el-button v-if="step === 2" type="success" :loading="submitting" @click="handleSubmit">提交认证</el-button>
        </template>
      </div>
    </div>

    <!-- ============ 备注（小弹窗） ============ -->
    <el-dialog v-model="remarkVisible" title="设置备注" width="460px" destroy-on-close>
      <el-form label-width="90px">
        <el-form-item label="小程序名称">
          <span>{{ remarkForm.account_name }}</span>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="remarkForm.remark" maxlength="100" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="remarkVisible = false">取消</el-button>
        <el-button type="primary" :loading="remarkSaving" @click="handleRemarkSave">确定</el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="imgPickerMulti" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.wxpv-order {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.notice { margin: 0; }
.notice-lines { line-height: 1.7; }
.add-bar :deep(.el-card__body) { padding: 3px 6px; }
.search-card :deep(.el-card__body) { padding: 3px 6px; display: flex; flex-direction: column; gap: 3px; }
.search-row { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.contact-cell { line-height: 1.6; }
.auth-url-block { margin-top: 4px; line-height: 1.6; }
.auth-url-label { color: #606266; }
.auth-url-text { word-break: break-all; color: #409eff; }
.apply-cell { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.refresh-btn { padding: 0; color: #c0c4cc; }
.ck-pass { color: #67c23a; }
.reason-text { color: #e6a23c; font-size: 12px; line-height: 1.6; word-break: break-all; }
.pagination { display: flex; justify-content: flex-end; margin-top: 3px; }
.goods-edit { background: #fff; border-radius: 6px; padding: 3px 6px; }
.ge-head { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #ebeef5; margin-bottom: 6px; min-height: 34px; }
.ge-title { font-size: 15px; font-weight: 600; }
.edit-steps { margin-bottom: 8px; }
.edit-fieldset { border: none; padding: 0; margin: 0; }
.ge-form { max-width: 760px; }
.inline-block { display: flex; gap: 8px; }
.edit-actions { display: flex; gap: 8px; justify-content: center; padding: 8px 0; border-top: 1px solid #ebeef5; margin-top: 6px; }
.name-tip { color: #e6a23c; font-size: 12px; line-height: 1.6; margin-top: 4px; }
.form-tip { color: #999; font-size: 12px; line-height: 1.6; }
.upload-block { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.up-img { width: 80px; height: 60px; border: 1px solid #eee; border-radius: 4px; }
</style>
