<script setup lang="ts">
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

// ===== 快递鸟设置 =====
const isMock = ref(false)
const setForm = reactive({
  account_id: '',
  account_secret: '',
  print_remarks: 1,
  print_shop: 1,
  print_shop_content: [] as string[],
})
const printContentMap = ref<Record<string, string>>({})
const setSaving = ref(false)

async function fetchSet() {
  try {
    const data: EwaybillSet = await getEwaybillSet()
    setForm.account_id = data.account_id || ''
    setForm.account_secret = data.account_secret || ''
    setForm.print_remarks = data.print_remarks
    setForm.print_shop = data.print_shop
    setForm.print_shop_content = data.print_shop_content || []
    printContentMap.value = data.print_content_map || {}
    isMock.value = !!data.is_mock
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
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

// ===== 快递公司账号 =====
const accountLoading = ref(false)
const accountList = ref<ExpressAccount[]>([])
const accountTotal = ref(0)
const accountQuery = reactive({ page: 1, pagesize: 10 })

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

async function handleDeleteAccount(row: ExpressAccount) {
  try {
    await ElMessageBox.confirm(`确定解绑「${row.express}」吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await deleteExpressAccount([row.id])
    ElMessage.success('解绑成功')
    fetchAccounts()
  } catch {
    // ignore
  }
}

// ===== 绑定弹窗 =====
const bindVisible = ref(false)
const bindSaving = ref(false)
const dictList = ref<ExpressDictItem[]>([])
const paytypeMap = ref<Record<string, string>>({})
const bindForm = reactive<Record<string, unknown>>({
  id: 0,
  express_code: '',
  customer_name: '',
  customer_pwd: '',
  month_code: '',
  send_site: '',
  send_staff: '',
  paytype: '',
  param3: '',
  param4: '',
  param5: '',
})

const currentDict = computed(() => dictList.value.find((x) => x.code === bindForm.express_code))

/** 动态字段：field 列表 → 表单控件 */
const dynamicFields = computed(() => {
  const map: Record<string, { key: string; label: string }> = {
    CustomerName: { key: 'customer_name', label: '客户编码' },
    CustomerPwd: { key: 'customer_pwd', label: '客户密码' },
    MonthCode: { key: 'month_code', label: '月结号' },
    SendSite: { key: 'send_site', label: '网点编码' },
    SendStaff: { key: 'send_staff', label: '网点名称' },
  }
  return (currentDict.value?.field || [])
    .filter((f) => map[f])
    .map((f) => map[f])
})

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

async function openBind() {
  try {
    const data = await getExpressDict()
    dictList.value = data.list || []
    paytypeMap.value = data.paytype || {}
  } catch {
    dictList.value = []
  }
  Object.assign(bindForm, {
    id: 0,
    express_code: '',
    customer_name: '',
    customer_pwd: '',
    month_code: '',
    send_site: '',
    send_staff: '',
    paytype: '',
    param3: '',
    param4: '',
    param5: '',
  })
  bindVisible.value = true
}

function editAccount(row: ExpressAccount) {
  // 编辑复用字典（字典已加载则直接打开）
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
      param3: row.param3,
      param4: row.param4,
      param5: row.param5,
    })
  })
}

async function handleBindSave() {
  if (!bindForm.express_code) {
    ElMessage.warning('请选择快递公司')
    return
  }
  for (const f of dynamicFields.value) {
    if (!String(bindForm[f.key] ?? '').trim()) {
      ElMessage.warning(`请输入${f.label}`)
      return
    }
  }
  for (const f of paramFields.value) {
    if (!String(bindForm[f.key] ?? '').trim()) {
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
    bindVisible.value = false
    fetchAccounts()
  } catch {
    // ignore
  } finally {
    bindSaving.value = false
  }
}

onMounted(() => {
  fetchSet()
  fetchAccounts()
})
</script>

<template>
  <div class="ewaybill-set-page">
    <!-- 快递鸟设置 -->
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>面单设置</span>
          <el-tag v-if="isMock" type="warning" size="small">EWAYBILL_MOCK 演示模式</el-tag>
        </div>
      </template>
      <el-form label-width="180px" style="max-width: 640px">
        <el-form-item label="快递鸟用户ID" required>
          <el-input v-model="setForm.account_id" placeholder="请输入快递鸟用户ID" clearable />
        </el-form-item>
        <el-form-item label="快递鸟API KEY" required>
          <el-input v-model="setForm.account_secret" placeholder="请输入快递鸟API KEY" clearable />
        </el-form-item>
        <el-form-item label="打印备注">
          <el-switch v-model="setForm.print_remarks" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="打印商品信息">
          <el-switch v-model="setForm.print_shop" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="打印内容">
          <el-checkbox-group v-model="setForm.print_shop_content">
            <el-checkbox v-for="(label, key) in printContentMap" :key="key" :value="key">{{ label }}</el-checkbox>
          </el-checkbox-group>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="setSaving" @click="handleSaveSet">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 快递公司账号 -->
    <el-card shadow="never" style="margin-top: 14px">
      <template #header>
        <div class="card-header">
          <span>快递公司账号</span>
          <el-button type="primary" size="small" @click="openBind">绑定快递公司</el-button>
        </div>
      </template>

      <el-table v-loading="accountLoading" :data="accountList" border stripe style="width: 100%">
        <el-table-column prop="express" label="快递公司" width="140" />
        <el-table-column prop="express_code" label="编码" width="100" />
        <el-table-column prop="customer_name" label="客户编码" min-width="130" />
        <el-table-column prop="month_code" label="月结号" min-width="120" />
        <el-table-column prop="send_site" label="网点编码" min-width="120" />
        <el-table-column prop="paytype_text" label="运费支付" width="100" />
        <el-table-column prop="createtime_text" label="绑定时间" width="165" />
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="editAccount(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDeleteAccount(row)">解绑</el-button>
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

    <!-- 绑定/编辑弹窗 -->
    <el-dialog v-model="bindVisible" :title="bindForm.id ? '编辑快递公司账号' : '绑定快递公司'" width="560px">
      <el-form label-width="130px">
        <el-form-item label="快递公司" required>
          <el-select v-model="bindForm.express_code" :disabled="!!bindForm.id" placeholder="请选择快递公司" filterable style="width: 100%">
            <el-option v-for="item in dictList" :key="item.code" :label="item.name" :value="item.code" />
          </el-select>
        </el-form-item>
        <el-form-item v-for="f in dynamicFields" :key="f.key" :label="f.label" required>
          <el-input v-model="bindForm[f.key]" :placeholder="`请输入${f.label}`" clearable />
        </el-form-item>
        <el-form-item v-for="f in paramFields" :key="f.key" :label="f.label" required>
          <el-input v-model="bindForm[f.key]" :placeholder="`请输入${f.label}`" clearable />
        </el-form-item>
        <el-form-item label="运费支付方式" required>
          <el-select v-model="bindForm.paytype" placeholder="请选择" style="width: 100%">
            <el-option v-for="(label, key) in paytypeMap" :key="key" :label="label" :value="Number(key)" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="bindVisible = false">取消</el-button>
        <el-button type="primary" :loading="bindSaving" @click="handleBindSave">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}
</style>
