<script setup lang="ts">
/**
 * 电子面单 - 面单设置（对标参考「扩展--电子面单--面单设置」）
 * 三个平铺视图（非弹窗，主区域切换 + 右上 X 返回）：
 *  - set    ：面单设置（状态/用户ID/API KEY/打印订单备注/商品打印字段/打印内容/提交/绑定快递公司账号入口）
 *  - account：快递公司账号列表（整页 + 右上 X）
 *  - bind   ：绑定/编辑快递公司账号（整页 + 右上 X）
 * 账号真实落库 ewaybill_express_account，编辑回填一致。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteExpressAccount,
  getExpressAccountList,
  getExpressDict,
  getEwaybillSet,
  saveEwaybillSet,
  saveExpressAccount,
} from '../../api/ewaybill'
import type { EwaybillSet, ExpressAccount, ExpressDictItem } from '../../api/ewaybill'

/** 当前平铺视图：set=设置 account=账号列表 bind=绑定/编辑 */
const view = ref<'set' | 'account' | 'bind'>('set')

/* ================= 面单设置 ================= */
const setForm = reactive({
  status: 1,
  account_id: '',
  account_secret: '',
  print_remarks: 1,
  print_shop: 1,
  print_shop_content: [] as string[],
})
const setSaving = ref(false)

/** 打印内容候选（照截图：商品名称/商品别名/商品编码/规格名称/规格编码） */
const PRINT_CONTENT_OPTIONS = [
  { key: 'shop_name', label: '商品名称' },
  { key: 'shop_alias', label: '商品别名' },
  { key: 'shop_code', label: '商品编码' },
  { key: 'guige_name', label: '规格名称' },
  { key: 'guige_code', label: '规格编码' },
]

async function fetchSet() {
  try {
    const data: EwaybillSet = await getEwaybillSet()
    setForm.status = data.status ?? 1
    setForm.account_id = data.account_id || ''
    setForm.account_secret = data.account_secret || ''
    setForm.print_remarks = data.print_remarks
    setForm.print_shop = data.print_shop
    setForm.print_shop_content = data.print_shop_content || []
  } catch {
    // ignore
  }
}

function togglePrintContent(key: string) {
  const idx = setForm.print_shop_content.indexOf(key)
  if (idx >= 0) setForm.print_shop_content.splice(idx, 1)
  else setForm.print_shop_content.push(key)
}

async function handleSaveSet() {
  if (!setForm.account_id.trim()) {
    ElMessage.warning('请输入用户ID')
    return
  }
  if (!setForm.account_secret.trim()) {
    ElMessage.warning('请输入API KEY')
    return
  }
  setSaving.value = true
  try {
    await saveEwaybillSet({
      status: setForm.status,
      account_id: setForm.account_id,
      account_secret: setForm.account_secret,
      print_remarks: setForm.print_remarks,
      print_shop: setForm.print_shop,
      print_shop_content: setForm.print_shop_content,
    })
    ElMessage.success('保存成功')
    fetchSet()
  } catch {
    // ignore
  } finally {
    setSaving.value = false
  }
}

/* ================= 快递公司账号列表 ================= */
const accountLoading = ref(false)
const accountList = ref<ExpressAccount[]>([])
const accountTotal = ref(0)
const accountQuery = reactive({ page: 1, pagesize: 10 })
const selectedAccountIds = ref<number[]>([])

async function fetchAccounts() {
  accountLoading.value = true
  try {
    const data = await getExpressAccountList({ page: accountQuery.page, pagesize: accountQuery.pagesize })
    accountList.value = data.list || []
    accountTotal.value = data.total || 0
  } catch {
    // ignore
  } finally {
    accountLoading.value = false
  }
}

function openAccountView() {
  accountQuery.page = 1
  fetchAccounts()
  view.value = 'account'
}

function handleAccountSelection(rows: ExpressAccount[]) {
  selectedAccountIds.value = rows.map((r) => r.id)
}

async function handleDeleteAccounts(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择要删除的账号')
    return
  }
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个快递公司账号吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await deleteExpressAccount(ids)
    ElMessage.success('删除成功')
    fetchAccounts()
  } catch {
    // ignore
  }
}

/* ================= 绑定/编辑快递公司账号 ================= */
const dictList = ref<ExpressDictItem[]>([])
const paytypeMap = ref<Record<string, string>>({ '1': '现付', '2': '到付', '3': '月结' })
const bindSaving = ref(false)
const bindForm = reactive({
  id: 0,
  express_code: '',
  customer_name: '',
  customer_pwd: '',
  month_code: '',
  send_site: '',
  send_staff: '',
  paytype: 1 as number | string,
  param3: '',
  param4: '',
  param5: '',
})

/** 当前选中快递公司的动态字段（决定哪些字段必填，照快递鸟字典） */
const currentDict = computed(() => dictList.value.find((x) => x.code === bindForm.express_code))
function fieldRequired(key: string) {
  const map: Record<string, string> = {
    customer_name: 'CustomerName',
    customer_pwd: 'CustomerPwd',
    month_code: 'MonthCode',
    send_site: 'SendSite',
    send_staff: 'SendStaff',
  }
  if (!currentDict.value) return true
  return (currentDict.value.field || []).includes(map[key])
}
/** 京东等 customerMap 额外字段（AppKey/AppSecret/AccessToken），仅在需要时显示 */
const paramFields = computed(() => {
  const map: Record<string, { key: string; label: string }> = {
    Param3: { key: 'param3', label: 'AppKey' },
    Param4: { key: 'param4', label: 'AppSecret' },
    Param5: { key: 'param5', label: 'AccessToken' },
  }
  return (currentDict.value?.customerMap || [])
    .filter((f) => map[f])
    .map((f) => map[f])
})

function resetBindForm() {
  Object.assign(bindForm, {
    id: 0,
    express_code: '',
    customer_name: '',
    customer_pwd: '',
    month_code: '',
    send_site: '',
    send_staff: '',
    paytype: 1,
    param3: '',
    param4: '',
    param5: '',
  })
}

async function loadDict() {
  if (!dictList.value.length) {
    const data = await getExpressDict()
    dictList.value = data.list || []
    paytypeMap.value = data.paytype || paytypeMap.value
  }
}

async function openBind() {
  try {
    await loadDict()
  } catch {
    dictList.value = []
  }
  resetBindForm()
  view.value = 'bind'
}

function editAccount(row: ExpressAccount) {
  openBind().then(() => {
    Object.assign(bindForm, {
      id: row.id,
      express_code: row.express_code,
      customer_name: row.customer_name,
      customer_pwd: row.customer_pwd,
      month_code: row.month_code,
      send_site: row.send_site,
      send_staff: row.send_staff,
      paytype: row.paytype,
      param3: row.param3 || '',
      param4: row.param4 || '',
      param5: row.param5 || '',
    })
  })
}

async function handleBindSave() {
  if (!bindForm.express_code) {
    ElMessage.warning('请选择快递公司')
    return
  }
  // 动态必填字段校验
  const labels: Record<string, string> = {
    customer_name: '客户编码',
    customer_pwd: '客户密码',
    month_code: '月结号',
    send_site: '网点编码',
    send_staff: '网点名称',
  }
  for (const key of Object.keys(labels)) {
    if (fieldRequired(key) && !String(bindForm[key as 'customer_name'] ?? '').trim()) {
      ElMessage.warning(`请输入${labels[key]}`)
      return
    }
  }
  for (const f of paramFields.value) {
    if (!String(bindForm[f.key as 'param3'] ?? '').trim()) {
      ElMessage.warning(`请输入${f.label}`)
      return
    }
  }
  if (!bindForm.paytype) {
    ElMessage.warning('请选择运费支付方式')
    return
  }
  bindSaving.value = true
  try {
    await saveExpressAccount({ ...bindForm })
    ElMessage.success('操作成功')
    view.value = 'account'
    fetchAccounts()
  } catch {
    // ignore
  } finally {
    bindSaving.value = false
  }
}

onMounted(fetchSet)
</script>

<template>
  <!-- ============ 面单设置 ============ -->
  <el-card v-show="view === 'set'" shadow="never" class="compact-title">
    <template #header>
      <div class="card-header">
        <span>⚙ 面单设置</span>
      </div>
    </template>

    <el-form label-width="110px" style="max-width: 760px">
      <el-form-item label="状态">
        <el-radio-group v-model="setForm.status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item label="用户ID" required>
        <el-input v-model="setForm.account_id" placeholder="请输入用户ID" clearable style="max-width: 320px" />
        <span class="form-hint">
          登录官网-基本资料查看 <a class="link" href="https://www.kdniao.com" target="_blank">注册链接</a>
          ，联系系统客服以代理商价格购买电子面单条数，比官网充值优惠。
        </span>
      </el-form-item>

      <el-form-item label="API KEY" required>
        <el-input v-model="setForm.account_secret" placeholder="请输入API KEY" clearable style="max-width: 320px" />
        <span class="form-hint">
          登录官网-首页-右上角-用户ID和API KEY <el-tooltip content="示例：API KEY 在官网首页右上角「用户ID / API KEY」处查看，形如 08ed0562-acc6-4ef0-9f37-f7">
            <a class="link">示例</a>
          </el-tooltip>
        </span>
      </el-form-item>

      <el-form-item label="打印订单备注">
        <el-radio-group v-model="setForm.print_remarks">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="form-hint">用户下单时的备注</span>
      </el-form-item>

      <el-form-item label="商品打印字段">
        <el-radio-group v-model="setForm.print_shop">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item label="打印内容">
        <div class="print-content">
          <span
            v-for="opt in PRINT_CONTENT_OPTIONS"
            :key="opt.key"
            class="chip"
            :class="{ 'chip-on': setForm.print_shop_content.includes(opt.key) }"
            @click="togglePrintContent(opt.key)"
          >
            <span v-if="setForm.print_shop_content.includes(opt.key)">✓ </span>{{ opt.label }}
          </span>
        </div>
        <div class="form-hint">没有别名的自动使用商品名称替换；商品名称与别名同时开启时只显示商品名称；</div>
      </el-form-item>

      <el-form-item>
        <el-button type="primary" :loading="setSaving" @click="handleSaveSet">提交</el-button>
        <el-button @click="openAccountView">绑定快递公司账号</el-button>
      </el-form-item>
    </el-form>
  </el-card>

  <!-- ============ 快递公司账号列表（整页 + 右上 X） ============ -->
  <el-card v-if="view === 'account'" shadow="never" class="compact-title">
    <template #header>
      <div class="page-header">
        <span>快递公司账号</span>
        <el-button circle size="small" @click="view = 'set'">✕</el-button>
      </div>
    </template>

    <div class="toolbar">
      <el-button type="primary" size="small" @click="openBind">绑定快递公司账号</el-button>
      <el-button size="small" @click="handleDeleteAccounts(selectedAccountIds)">删除</el-button>
    </div>

    <el-table
      v-loading="accountLoading"
      :data="accountList"
      border
      stripe
      @selection-change="handleAccountSelection"
    >
      <el-table-column type="selection" width="40" />
      <el-table-column prop="customer_name" label="客户编码" min-width="180" show-overflow-tooltip />
      <el-table-column label="快递公司" min-width="160">
        <template #default="{ row }">{{ row.express_text || row.express }}</template>
      </el-table-column>
      <el-table-column prop="paytype_text" label="结算方式" width="120" />
      <el-table-column prop="createtime_text" label="绑定时间" width="170" />
      <el-table-column label="操作" width="140">
        <template #default="{ row }">
          <el-button link type="primary" @click="editAccount(row)">编辑</el-button>
          <el-button link type="danger" @click="handleDeleteAccounts([row.id])">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="accountQuery.page"
        v-model:page-size="accountQuery.pagesize"
        :total="accountTotal"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        @size-change="fetchAccounts"
        @current-change="fetchAccounts"
      />
    </div>
  </el-card>

  <!-- ============ 绑定/编辑快递公司账号（整页 + 右上 X） ============ -->
  <el-card v-if="view === 'bind'" shadow="never" class="compact-title">
    <template #header>
      <div class="page-header">
        <span>＋{{ bindForm.id ? '编辑' : '绑定' }}快递公司账号</span>
        <el-button circle size="small" @click="view = 'account'">✕</el-button>
      </div>
    </template>

    <el-form label-width="110px" style="max-width: 640px">
      <el-form-item label="快递公司" required>
        <el-select
          v-model="bindForm.express_code"
          :disabled="!!bindForm.id"
          placeholder="请选择快递公司"
          filterable
          class="field-md"
        >
          <el-option v-for="item in dictList" :key="item.code" :label="item.name" :value="item.code" />
        </el-select>
      </el-form-item>

      <el-form-item label="客户编码" :required="fieldRequired('customer_name')">
        <el-input v-model="bindForm.customer_name" placeholder="请输入客户编码" clearable class="field-md" />
        <span class="form-hint">快递公司客户编码 也称客户号、月结账号、白马账号（韵达）、K码（圆通）、面单账号（中通）等</span>
      </el-form-item>

      <el-form-item label="客户密码" :required="fieldRequired('customer_pwd')">
        <el-input v-model="bindForm.customer_pwd" placeholder="请输入客户密码" clearable class="field-md" />
        <span class="form-hint">也称密钥、用户密码、接口联调密码、面单密码等</span>
      </el-form-item>

      <el-form-item label="月结号" :required="fieldRequired('month_code')">
        <el-input v-model="bindForm.month_code" placeholder="请输入月结号" clearable class="field-md" />
        <span class="form-hint">也称密钥、月结卡号、月结编码等</span>
      </el-form-item>

      <el-form-item label="网点编码" :required="fieldRequired('send_site')">
        <el-input v-model="bindForm.send_site" placeholder="请输入网点编码" clearable class="field-md" />
        <span class="form-hint">也称客户商号ID、仓库ID、网点名称、网点编号等</span>
      </el-form-item>

      <el-form-item label="网点名称" :required="fieldRequired('send_staff')">
        <el-input v-model="bindForm.send_staff" placeholder="请输入网点名称" clearable class="field-md" />
        <span class="form-hint">也称取件员编号、收件快递员等</span>
      </el-form-item>

      <el-form-item v-for="f in paramFields" :key="f.key" :label="f.label" required>
        <el-input v-model="bindForm[f.key as 'param3']" :placeholder="`请输入${f.label}`" clearable class="field-md" />
      </el-form-item>

      <el-form-item label="运费支付方式" required>
        <el-radio-group v-model="bindForm.paytype">
          <el-radio :value="1">现付</el-radio>
          <el-radio :value="2">到付</el-radio>
          <el-radio :value="3">月结</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item>
        <el-button type="warning" :loading="bindSaving" @click="handleBindSave">提交</el-button>
      </el-form-item>
    </el-form>
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.form-hint {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
.link {
  color: #2d8cf0;
  cursor: pointer;
}
.toolbar {
  display: flex;
  gap: 10px;
  margin-bottom: 3px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 3px;
}
.print-content {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.chip {
  padding: 4px 12px;
  border: 1px solid #dcdee2;
  border-radius: 3px;
  cursor: pointer;
  color: #515a6e;
  background: #f8f8f9;
  font-size: 13px;
}
.chip-on {
  background: #19be6b;
  border-color: #19be6b;
  color: #fff;
}
</style>
