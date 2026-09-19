<script setup lang="ts">
// 微信代认证 - 认证订单（批次7-8 任务216）
// 全流程：创建（主体类型）→名称校验→企业/联系人资料→资质上传→提交→状态流转→进度查询
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import {
  checkNickName,
  chooseIdentity,
  deleteProxyverifyOrders,
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

// ============ 常量（状态文案与后端对齐） ============
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
  { value: 1, label: '消耗服务商预购包（代缴额度）' },
  { value: 2, label: '小程序开发者自行支付' },
]

function taskTagType(status: number) {
  if ([5, 8, 11, 14].includes(status)) return 'danger'
  if ([6, 9, 12].includes(status)) return 'success'
  if (status === 15) return 'warning'
  return 'info'
}
function applyTagType(status: number) {
  if (status === 4) return 'success'
  if (status === 5) return 'danger'
  if (status === 3) return 'warning'
  if (status === 2 || status === 1) return 'primary'
  return 'info'
}

// ============ 列表 ============
const loading = ref(false)
const list = ref<ProxyverifyOrder[]>([])
const total = ref(0)
const query = reactive<ProxyverifyOrderQuery>({ page: 1, pagesize: 20 })

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
function resetSearch() {
  Object.assign(query, {
    page: 1,
    customer_type: '',
    contact_name: '',
    account_name: '',
    pay_type: '',
    task_status: '',
    apply_status: '',
  })
  load()
}

// ============ 进度查询 / 删除 / 备注 ============
async function handleQueryStatus(row: ProxyverifyOrder) {
  const res = await queryTaskStatus(row.id)
  ElMessage.success(`进度已更新：${res.task_status_name} / ${res.apply_status_name}`)
  load()
}

async function handleDelete(row: ProxyverifyOrder) {
  await ElMessageBox.confirm(`确定删除认证订单 #${row.id}？`, '提示', { type: 'warning' })
  await deleteProxyverifyOrders([row.id])
  ElMessage.success('删除成功')
  load()
}

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

// ============ 详情 ============
const detailVisible = ref(false)
const detail = ref<ProxyverifyOrder | null>(null)
function openDetail(row: ProxyverifyOrder) {
  detail.value = row
  detailVisible.value = true
}

// ============ 创建/编辑向导 ============
const wizardVisible = ref(false)
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
    authorizer_appid: sessionStorage.getItem('wx_pv_new_appid') || '',
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
  await loadAuthorizers()
  wizardVisible.value = true
}

function openEdit(row: ProxyverifyOrder) {
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
  loadAuthorizers().then(() => {
    wizardVisible.value = true
  })
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

// ============ 选图（ImgIconPicker 统一选图，提交时后端转微信 mediaid） ============
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
    wizardVisible.value = false
    load()
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="wxpv-order">
    <div class="toolbar">
      <el-select v-model="query.customer_type" placeholder="主体类型" clearable style="width: 130px">
        <el-option v-for="t in CUSTOMER_TYPES" :key="t.value" :label="t.label" :value="String(t.value)" />
      </el-select>
      <el-select v-model="query.task_status" placeholder="任务状态" clearable style="width: 170px">
        <el-option v-for="s in TASK_STATUS_OPTIONS" :key="s.value" :label="s.label" :value="String(s.value)" />
      </el-select>
      <el-select v-model="query.apply_status" placeholder="审核单状态" clearable style="width: 140px">
        <el-option v-for="s in APPLY_STATUS_OPTIONS" :key="s.value" :label="s.label" :value="String(s.value)" />
      </el-select>
      <el-select v-model="query.pay_type" placeholder="支付方式" clearable style="width: 130px">
        <el-option label="服务商预购包" value="1" />
        <el-option label="开发者自付" value="2" />
      </el-select>
      <el-input v-model="query.account_name" placeholder="小程序名称" clearable style="width: 150px" />
      <el-button type="primary" @click="handleSearch">搜索</el-button>
      <el-button @click="resetSearch">重置</el-button>
      <div style="flex: 1" />
      <el-button type="primary" @click="openCreate">发起认证</el-button>
    </div>

    <el-table v-loading="loading" :data="list" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="account_name" label="小程序名称" min-width="140" show-overflow-tooltip />
      <el-table-column prop="customer_type_name" label="主体类型" width="110" />
      <el-table-column prop="contact_name" label="联系人" width="100" />
      <el-table-column prop="pay_type_name" label="支付方式" width="150" show-overflow-tooltip />
      <el-table-column label="任务状态" min-width="170">
        <template #default="{ row }">
          <el-tag :type="taskTagType(row.task_status)" size="small">{{ row.task_status_name }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="审核单状态" width="110">
        <template #default="{ row }">
          <el-tag :type="applyTagType(row.apply_status)" size="small">{{ row.apply_status_name }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="service_money" label="服务费" width="90" />
      <el-table-column prop="createtime_text" label="创建时间" width="150" />
      <el-table-column label="操作" width="250" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openDetail(row)">详情</el-button>
          <el-button v-if="row.taskid && row.apply_status >= 0 && row.apply_status < 4" link type="primary" @click="handleQueryStatus(row)">查询进度</el-button>
          <el-button v-if="row.apply_status === 3" link type="warning" @click="openEdit(row)">重新提交</el-button>
          <el-button link type="primary" @click="openRemark(row)">备注</el-button>
          <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
      <template #empty>暂无认证订单</template>
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

    <!-- ============ 创建/编辑向导 ============ -->
    <el-dialog v-model="wizardVisible" :title="form.id ? '重新提交认证' : '发起微信认证'" width="780px" destroy-on-close>
      <el-steps :active="step" finish-status="success" simple style="margin-bottom: 16px">
        <el-step title="选择主体类型" />
        <el-step title="名称校验" />
        <el-step title="填写资料并提交" />
      </el-steps>

      <!-- 步骤1：主体类型 -->
      <el-form v-if="step === 0" label-width="110px">
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
      <el-form v-if="step === 1" label-width="110px">
        <el-form-item label="账号名称" required>
          <div style="display: flex; gap: 8px">
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
      <el-form v-if="step === 2" label-width="110px">
        <template v-if="isCompany">
          <el-divider content-position="left">企业信息</el-divider>
          <el-form-item label="企业名称">
            <el-input v-model="form.company_name" maxlength="100" />
          </el-form-item>
          <el-form-item label="营业执照" required>
            <div class="upload-block">
              <el-image v-if="form.company_pic" :src="form.company_pic" class="up-img" :preview-src-list="[form.company_pic]" />
              <el-button size="small" @click="openImgPicker((urls) => { if (urls[0]) form.company_pic = urls[0] })">{{ form.company_pic ? '更换' : '选择图片' }}</el-button>
            </div>
          </el-form-item>
          <el-form-item label="信用代码">
            <el-input v-model="form.company_license_number" maxlength="50" placeholder="统一社会信用代码" />
          </el-form-item>
          <el-form-item label="企业地址">
            <el-input v-model="form.company_address" maxlength="100" />
          </el-form-item>
          <el-form-item label="企业电话">
            <el-input v-model="form.company_tel" maxlength="30" />
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
            <el-input v-model="form.electronic_desc" maxlength="100" />
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
            <el-input v-model="form.vat_enterprise_address" maxlength="100" />
          </el-form-item>
          <el-form-item label="开户银行">
            <el-input v-model="form.vat_bank_name" maxlength="50" />
          </el-form-item>
          <el-form-item label="银行账号">
            <el-input v-model="form.vat_bank_account" maxlength="50" />
          </el-form-item>
          <el-form-item label="发票备注">
            <el-input v-model="form.vat_desc" maxlength="100" />
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

      <template #footer>
        <el-button @click="wizardVisible = false">取消</el-button>
        <el-button v-if="step > 0" @click="step--">上一步</el-button>
        <el-button v-if="step === 0" type="primary" @click="nextFromType">下一步</el-button>
        <el-button v-if="step === 1" type="primary" @click="nextFromName">下一步</el-button>
        <el-button v-if="step === 2" type="primary" :loading="submitting" @click="handleSubmit">提交认证</el-button>
      </template>
    </el-dialog>

    <!-- ============ 详情 ============ -->
    <el-dialog v-model="detailVisible" :title="`认证订单详情 #${detail?.id || ''}`" width="720px" destroy-on-close>
      <el-descriptions v-if="detail" :column="2" border>
        <el-descriptions-item label="小程序名称">{{ detail.account_name || '-' }}</el-descriptions-item>
        <el-descriptions-item label="授权AppID">{{ detail.authorizer_appid }}</el-descriptions-item>
        <el-descriptions-item label="主体类型">{{ detail.customer_type_name }}</el-descriptions-item>
        <el-descriptions-item label="支付方式">{{ detail.pay_type_name }}</el-descriptions-item>
        <el-descriptions-item label="任务状态">
          <el-tag :type="taskTagType(detail.task_status)" size="small">{{ detail.task_status_name }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="审核单状态">
          <el-tag :type="applyTagType(detail.apply_status)" size="small">{{ detail.apply_status_name }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="任务号">{{ detail.taskid || '-' }}</el-descriptions-item>
        <el-descriptions-item label="认证订单号">{{ detail.orderid || '-' }}</el-descriptions-item>
        <el-descriptions-item label="服务费">{{ detail.service_money }}</el-descriptions-item>
        <el-descriptions-item label="服务市场appid">{{ detail.service_appid || '-' }}</el-descriptions-item>
        <el-descriptions-item label="联系人">{{ detail.contact_name }} / {{ detail.contact_email }}</el-descriptions-item>
        <el-descriptions-item label="联系电话">{{ detail.contact_mobile || '-' }}</el-descriptions-item>
        <el-descriptions-item v-if="detail.company_name" label="企业名称" :span="2">{{ detail.company_name }}</el-descriptions-item>
        <el-descriptions-item v-if="detail.company_license_number" label="信用代码" :span="2">{{ detail.company_license_number }}</el-descriptions-item>
        <el-descriptions-item v-if="detail.company_pic" label="营业执照" :span="2">
          <el-image :src="detail.company_pic" style="width: 120px; height: 80px" fit="cover" :preview-src-list="[detail.company_pic]" />
        </el-descriptions-item>
        <el-descriptions-item v-if="detail.auth_identification" label="认证身份" :span="2">{{ detail.auth_identification }}</el-descriptions-item>
        <el-descriptions-item v-if="detail.auth_url" label="管理员授权链接" :span="2">
          <a :href="detail.auth_url" target="_blank" rel="noopener">{{ detail.auth_url }}</a>
        </el-descriptions-item>
        <el-descriptions-item v-if="detail.apply_status === 3 && detail.refill_reason" label="打回原因" :span="2">
          <span style="color: #e6a23c">{{ detail.refill_reason }}</span>
        </el-descriptions-item>
        <el-descriptions-item v-if="detail.apply_status === 5 && detail.fail_reason" label="失败原因" :span="2">
          <span style="color: #f56c6c">{{ detail.fail_reason }}</span>
        </el-descriptions-item>
        <el-descriptions-item v-if="detail.errmsg" label="错误信息" :span="2">{{ detail.errmsg }}</el-descriptions-item>
        <el-descriptions-item label="创建时间">{{ detail.createtime_text }}</el-descriptions-item>
        <el-descriptions-item label="更新时间">{{ detail.updatetime_text }}</el-descriptions-item>
        <el-descriptions-item v-if="detail.remark" label="备注" :span="2">{{ detail.remark }}</el-descriptions-item>
      </el-descriptions>
    </el-dialog>

    <!-- ============ 备注 ============ -->
    <el-dialog v-model="remarkVisible" title="修改备注" width="460px" destroy-on-close>
      <el-form label-width="90px">
        <el-form-item label="小程序名称">
          <span>{{ remarkForm.account_name }}</span>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="remarkForm.remark" maxlength="100" show-word-limit />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="remarkVisible = false">取消</el-button>
        <el-button type="primary" :loading="remarkSaving" @click="handleRemarkSave">保存</el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="imgPickerMulti" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.wxpv-order {
  padding: 12px;
}
.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.name-tip {
  color: #e6a23c;
  font-size: 12px;
  line-height: 1.6;
  margin-top: 4px;
}
.form-tip {
  color: #999;
  font-size: 12px;
  line-height: 1.6;
}
.upload-block {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.up-img {
  width: 80px;
  height: 60px;
  border: 1px solid #eee;
  border-radius: 4px;
}
</style>
