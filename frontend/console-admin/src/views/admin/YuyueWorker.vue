<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  addYuyueWorker,
  addYuyueWorkerMoney,
  deleteYuyueWorker,
  editYuyueWorker,
  getYuyueWorkerCategoryList,
  getYuyueWorkerList,
  getYuyueWorkerOrderProgress,
  getYuyueWorkerOrders,
} from '../../api/yuyue'
import type { YuyueWorkerListQuery } from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'
import { formatTime } from '../../utils/time'

// 后端行（真实库字段）
interface WorkerRow {
  id: number
  un: string
  headimg: string
  realname: string
  tel: string
  mid: number
  member_nickname: string
  member_headimg: string
  doing_count: number
  done_count: number
  money: number
  createtime: number
  status: number
  cid: number
  jineng: string
  desc: string
  sort: number
  pwd?: string
}

// ============ 列表 ============
type ViewMode = 'list' | 'edit' | 'orders'
const view = ref<ViewMode>('list')
const loading = ref(false)
const tableData = ref<WorkerRow[]>([])
const total = ref(0)
const selected = ref<WorkerRow[]>([])

const query = reactive<Required<Pick<YuyueWorkerListQuery, 'page' | 'pagesize'>> & { realname: string; status: number | '' }>({
  page: 1,
  pagesize: 10,
  realname: '',
  status: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueWorkerList({
      page: query.page,
      pagesize: query.pagesize,
      realname: query.realname || undefined,
      status: query.status === '' ? undefined : query.status,
    })
    const d = data as unknown as { list?: WorkerRow[]; total?: number }
    tableData.value = d.list || []
    total.value = d.total || 0
  } catch {
    // 拦截器统一提示
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
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
function handleSelectionChange(rows: WorkerRow[]) {
  selected.value = rows
}

// ============ 平铺增改 ============
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)
const categoryOptions = ref<{ id: number; name: string }[]>([])

const form = reactive({
  id: 0,
  un: '',
  pwd: '',
  mid: 0 as number | string,
  realname: '',
  tel: '',
  cid: 0,
  headimg: '',
  jineng: '',
  desc: '',
  sort: 0,
  status: 1,
})

const rules: FormRules = {
  un: [{ required: true, message: '请输入登录账号', trigger: 'blur' }],
  realname: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
}

async function fetchCategories() {
  try {
    const data = await getYuyueWorkerCategoryList()
    const list = ((data as unknown as { list?: { id: number; name: string }[] }).list || (data as unknown as { id: number; name: string }[]) || []) as { id: number; name: string }[]
    categoryOptions.value = list
  } catch {
    // ignore
  }
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, un: '', pwd: '', mid: '', realname: '', tel: '', cid: 0, headimg: '', jineng: '', desc: '', sort: 0, status: 1 })
  view.value = 'edit'
}

function openEdit(row: WorkerRow) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    un: row.un || '',
    pwd: '',
    mid: row.mid || '',
    realname: row.realname || '',
    tel: row.tel || '',
    cid: row.cid || 0,
    headimg: row.headimg || '',
    jineng: row.jineng || '',
    desc: row.desc || '',
    sort: row.sort || 0,
    status: row.status,
  })
  view.value = 'edit'
}

function backToList() {
  view.value = 'list'
  fetchList()
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      const payload = {
        id: form.id || undefined,
        un: form.un,
        pwd: form.pwd || undefined,
        mid: Number(form.mid) || 0,
        realname: form.realname,
        tel: form.tel,
        cid: Number(form.cid) || 0,
        headimg: form.headimg,
        jineng: form.jineng,
        desc: form.desc,
        sort: Number(form.sort) || 0,
        status: form.status,
      }
      if (isEdit.value) {
        await editYuyueWorker(payload)
        ElMessage.success('保存成功')
      } else {
        await addYuyueWorker(payload)
        ElMessage.success('添加成功')
      }
      view.value = 'list'
      fetchList()
    } catch {
      // 拦截器
    } finally {
      submitting.value = false
    }
  })
}

// 头像选图
const imgPickerVisible = ref(false)
function openImgPicker() {
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls.length) form.headimg = urls[0]
}

// ============ 删除 ============
function handleBatchDelete() {
  if (!selected.value.length) {
    ElMessage.warning('请先勾选人员')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${selected.value.length} 个人员吗？`, '删除确认', { type: 'warning' })
    .then(async () => {
      try {
        for (const r of selected.value) await deleteYuyueWorker(r.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch { /* 拦截器 */ }
    })
    .catch(() => {})
}

function handleDelete(row: WorkerRow) {
  ElMessageBox.confirm(`确定删除人员「${row.realname}」吗？`, '删除确认', { type: 'warning' })
    .then(async () => {
      try {
        await deleteYuyueWorker(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch { /* 拦截器 */ }
    })
    .catch(() => {})
}

// ============ 加余额 ============
const moneyVisible = ref(false)
const moneySubmitting = ref(false)
const moneyRow = ref<WorkerRow | null>(null)
const moneyForm = reactive({ money: 0, remark: '' })

function openMoney(row: WorkerRow) {
  moneyRow.value = row
  moneyForm.money = 0
  moneyForm.remark = ''
  moneyVisible.value = true
}

async function submitMoney() {
  if (!moneyRow.value) return
  if (!moneyForm.money) {
    ElMessage.warning('请输入增加数量')
    return
  }
  moneySubmitting.value = true
  try {
    const res = await addYuyueWorkerMoney(moneyRow.value.id, Number(moneyForm.money), moneyForm.remark)
    ElMessage.success(`操作成功，当前余额 ${Number(res.money).toFixed(2)}`)
    moneyVisible.value = false
    fetchList()
  } catch { /* 拦截器 */ } finally {
    moneySubmitting.value = false
  }
}

// ============ 查看链接 / 服务人员登录链接 ============
const linkVisible = ref(false)
const linkName = ref('')
const linkPath = ref('')
function openWorkerLink() {
  linkName.value = '服务人员登录'
  linkPath.value = '/yuyue/worker/login'
  linkVisible.value = true
}
function openViewLink() {
  linkName.value = '人员服务链接'
  linkPath.value = '/yuyue/worker'
  linkVisible.value = true
}

// ============ 查看服务记录 ============
const ordersWorker = ref<WorkerRow | null>(null)
const ordersLoading = ref(false)
const ordersData = ref<Record<string, unknown>[]>([])
const ordersTotal = ref(0)
const ordersQuery = reactive({
  page: 1,
  pagesize: 10,
  proname: '',
  linkman: '',
  tel: '',
  ctime: '',
  status: '' as number | '',
})

async function fetchOrders() {
  if (!ordersWorker.value) return
  ordersLoading.value = true
  try {
    const data = await getYuyueWorkerOrders({
      worker_id: ordersWorker.value.id,
      page: ordersQuery.page,
      pagesize: ordersQuery.pagesize,
      proname: ordersQuery.proname || undefined,
      linkman: ordersQuery.linkman || undefined,
      tel: ordersQuery.tel || undefined,
      ctime: ordersQuery.ctime || undefined,
      status: ordersQuery.status === '' ? undefined : ordersQuery.status,
    })
    ordersData.value = (data.list || []) as unknown as Record<string, unknown>[]
    ordersTotal.value = data.total || 0
  } catch { /* 拦截器 */ } finally {
    ordersLoading.value = false
  }
}

function openOrders(row: WorkerRow) {
  ordersWorker.value = row
  Object.assign(ordersQuery, { page: 1, pagesize: 10, proname: '', linkman: '', tel: '', ctime: '', status: '' })
  view.value = 'orders'
  fetchOrders()
}

// ============ 查进度 ============
const progressVisible = ref(false)
const progressLoading = ref(false)
const progressData = ref<{ reassign: boolean; current: number; steps: { time: string; text: string }[] } | null>(null)

async function openProgress(row: Record<string, unknown>) {
  progressVisible.value = true
  progressLoading.value = true
  progressData.value = null
  try {
    progressData.value = await getYuyueWorkerOrderProgress(Number(row.id))
  } catch { /* 拦截器 */ } finally {
    progressLoading.value = false
  }
}

onMounted(() => {
  fetchList()
  fetchCategories()
})
</script>

<template>
  <!-- ================= 人员列表 ================= -->
  <div v-if="view === 'list'">
    <div class="notice-bar">订单派单时可选择指定人员进行服务，请设置模板消息通知，派单后人员会收到模板消息</div>

    <el-card shadow="never" class="toolbar">
      <div class="toolbar-inner">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleBatchDelete">删除</el-button>
          <el-button @click="openViewLink">查看链接</el-button>
          <el-button @click="openWorkerLink">服务人员登录链接</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tf-label">姓名</span>
          <el-input v-model="query.realname" placeholder="请输入" style="width: 130px" @keyup.enter="handleSearch" />
          <span class="tf-label">状态</span>
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="已启用" :value="1" />
            <el-option label="已停用" :value="0" />
          </el-select>
          <button class="search-btn" title="搜索" @click="handleSearch">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M11.4 10.2a6 6 0 1 0-1.2 1.2l3.4 3.4 1.2-1.2-3.4-3.4zM7 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
          </button>
        </div>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="un" label="账号" min-width="100" />
        <el-table-column label="头像" width="80">
          <template #default="{ row }">
            <el-image v-if="row.headimg" :src="row.headimg" fit="cover" class="avatar" :preview-src-list="[row.headimg]" preview-teleported />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="realname" label="姓名" min-width="100" />
        <el-table-column prop="tel" label="电话" min-width="120" />
        <el-table-column label="绑定会员" min-width="130">
          <template #default="{ row }">
            <span v-if="row.member_nickname">
              <el-image v-if="row.member_headimg" :src="row.member_headimg" fit="cover" class="avatar avatar-xs" />
              {{ row.member_nickname }}
            </span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="服务中/已完成" width="110">
          <template #default="{ row }">{{ row.doing_count }}/{{ row.done_count }}</template>
        </el-table-column>
        <el-table-column label="余额" width="90">
          <template #default="{ row }"><span class="money-blue">{{ Number(row.money).toFixed(2) }}</span></template>
        </el-table-column>
        <el-table-column label="创建时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="接单状态" width="90">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'tag-green' : 'tag-gray'">{{ row.status === 1 ? '已启用' : '已停用' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="180">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              <el-button link type="primary" @click="openOrders(row)">查看服务记录</el-button>
              <el-button link type="primary" @click="openMoney(row)">加余额</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager" background layout="total, sizes, prev, pager, next, jumper"
        :total="total" v-model:current-page="query.page" v-model:page-size="query.pagesize"
        :page-sizes="[10, 20, 50]" @current-change="handlePageChange" @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 加余额弹窗 -->
    <el-dialog v-model="moneyVisible" title="加余额" width="420px">
      <el-form label-width="90px" label-position="right">
        <el-form-item label="增加数量">
          <el-input v-model.number="moneyForm.money" placeholder="输入负值表示扣除余额" type="number" class="field-xs" />
        </el-form-item>
        <el-form-item label="备注信息">
          <el-input v-model="moneyForm.remark" placeholder="请输入备注" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="primary" :loading="moneySubmitting" @click="submitMoney">确定</el-button>
        <el-button @click="moneyVisible = false">取消</el-button>
      </template>
    </el-dialog>

    <LinkAddressModal v-model:visible="linkVisible" :name="linkName" :path="linkPath" />
    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="(urls: string[]) => onImgPick(urls)" />
  </div>

  <!-- ================= 平铺增改 ================= -->
  <div v-else-if="view === 'edit'" class="goods-edit">
    <div class="ge-head">
      <span class="ge-title">{{ isEdit ? '编辑人员' : '添加人员' }}</span>
      <span class="ge-close" @click="backToList">×</span>
    </div>
    <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" label-position="right" class="ge-body">
      <el-form-item label="登录账号" prop="un">
        <el-input v-model="form.un" placeholder="请输入登录账号" style="width: 300px" />
      </el-form-item>
      <el-form-item label="登录密码">
        <el-input v-model="form.pwd" placeholder="请输入登录密码" style="width: 300px" />
      </el-form-item>
      <el-form-item label="会员ID">
        <el-input v-model="form.mid" placeholder="请输入会员ID" style="width: 300px" />
        <el-link type="primary" class="ml8">查找会员ID</el-link>
      </el-form-item>
      <el-form-item label="姓名" prop="realname">
        <el-input v-model="form.realname" placeholder="请输入姓名" style="width: 300px" />
      </el-form-item>
      <el-form-item label="手机号">
        <el-input v-model="form.tel" placeholder="请输入手机号" style="width: 300px" />
      </el-form-item>
      <el-form-item label="人员类型">
        <el-select v-model="form.cid" placeholder="-请选择-" style="width: 300px">
          <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="头像">
        <div class="img-pick-row">
          <el-image v-if="form.headimg" :src="form.headimg" class="img-pick-thumb" fit="cover" :preview-src-list="[form.headimg]" preview-teleported />
          <el-button size="small" @click="openImgPicker">{{ form.headimg ? '更换图片' : '上传图片' }}</el-button>
          <span class="img-tip">建议尺寸：150×150像素</span>
        </div>
      </el-form-item>
      <el-form-item label="熟悉技能">
        <el-input v-model="form.jineng" placeholder="如：安装 | 维修 | 清洗" style="width: 360px" />
      </el-form-item>
      <el-form-item label="描述">
        <el-input v-model="form.desc" type="textarea" :rows="3" placeholder="在详情页下面展示简介信息，建议60字以内" style="width: 420px" />
      </el-form-item>
      <el-form-item label="序号">
        <el-input-number v-model="form.sort" :min="0" :precision="0" />
        <span class="img-tip">用于排序,越大越靠前</span>
      </el-form-item>
      <el-form-item label="接单状态">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item>
        <el-button type="warning" :loading="submitting" @click="handleSubmit">提交</el-button>
      </el-form-item>
    </el-form>
  </div>

  <!-- ================= 查看服务记录 ================= -->
  <div v-else-if="view === 'orders'" class="goods-edit">
    <div class="ge-head">
      <span class="ge-title">查看服务记录（{{ ordersWorker?.realname }}）</span>
      <span class="ge-close" @click="backToList">×</span>
    </div>

    <div class="toolbar" style="margin: 3px 0">
      <div class="toolbar-inner">
        <div class="toolbar-left">
          <el-button>导出</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tf-label">商品名称</span>
          <el-input v-model="ordersQuery.proname" style="width: 120px" @keyup.enter="fetchOrders" />
          <span class="tf-label">下单人</span>
          <el-input v-model="ordersQuery.linkman" style="width: 110px" @keyup.enter="fetchOrders" />
          <span class="tf-label">手机号</span>
          <el-input v-model="ordersQuery.tel" style="width: 120px" @keyup.enter="fetchOrders" />
          <span class="tf-label">下单时间</span>
          <el-date-picker v-model="ordersQuery.ctime" type="date" value-format="YYYY-MM-DD" style="width: 140px" />
          <span class="tf-label">状态</span>
          <el-select v-model="ordersQuery.status" clearable style="width: 120px">
            <el-option label="已派单" :value="1" />
            <el-option label="已完成" :value="2" />
          </el-select>
          <button class="search-btn" title="搜索" @click="fetchOrders">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M11.4 10.2a6 6 0 1 0-1.2 1.2l3.4 3.4 1.2-1.2-3.4-3.4zM7 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
          </button>
        </div>
      </div>
    </div>

    <el-table v-loading="ordersLoading" :data="ordersData" border size="small">
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column label="商品信息" min-width="160">
        <template #default="{ row }">
          <div class="prod-cell">
            <el-image v-if="row.propic" :src="row.propic" fit="cover" class="avatar" />
            <div>
              <div>{{ row.proname }}</div>
              <div class="sub">{{ row.ggname }} x{{ row.num }} 购买价¥{{ row.product_price }}</div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="订单号/下单时间" min-width="180">
        <template #default="{ row }">
          <div>{{ row.ordernum }}</div>
          <div class="sub">{{ row.create_time_text }}</div>
        </template>
      </el-table-column>
      <el-table-column label="总价/实付款" min-width="130">
        <template #default="{ row }">
          <div>订单金额¥{{ row.totalprice }}</div>
          <div class="sub">实付¥{{ row.pay_amount }}</div>
        </template>
      </el-table-column>
      <el-table-column label="预约时间" min-width="120">
        <template #default="{ row }">{{ row.book_date }} {{ row.book_time }}</template>
      </el-table-column>
      <el-table-column label="客户信息" min-width="140">
        <template #default="{ row }">{{ row.linkman }} {{ row.tel }}</template>
      </el-table-column>
      <el-table-column label="服务人员" min-width="120">
        <template #default="{ row }">{{ row.worker_name }} {{ row.worker_tel }}</template>
      </el-table-column>
      <el-table-column label="服务方式" width="90">
        <template #default="{ row }">{{ row.fwtype_text }}</template>
      </el-table-column>
      <el-table-column label="服务状态" width="90">
        <template #default="{ row }">{{ row.fwstatus_text }}</template>
      </el-table-column>
      <el-table-column label="来源" width="100">
        <template #default="{ row }">{{ row.platform_text }}</template>
      </el-table-column>
      <el-table-column label="支付方式" width="90">
        <template #default="{ row }">{{ row.paytype_text }}</template>
      </el-table-column>
      <el-table-column label="订单状态" min-width="110">
        <template #default="{ row }">
          {{ row.status_text }}
          <span v-if="row.balance_unpaid" class="sub"> 尾款¥{{ row.balance_price }}未支付</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="130">
        <template #default="{ row }">
          <div class="row-ops">
            <el-button link type="primary">查看详情</el-button>
            <el-button link type="primary" @click="openProgress(row)">查进度</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      class="pager" background layout="total, sizes, prev, pager, next"
      :total="ordersTotal" v-model:current-page="ordersQuery.page" v-model:page-size="ordersQuery.pagesize"
      :page-sizes="[10, 20, 50]" @current-change="fetchOrders" @size-change="fetchOrders"
    />

    <!-- 查进度弹窗 -->
    <el-dialog v-model="progressVisible" title="查进度" width="440px">
      <div v-loading="progressLoading" class="timeline">
        <template v-if="progressData && progressData.steps.length">
          <div v-for="(s, i) in progressData.steps" :key="i" class="tl-item" :class="{ active: i === progressData!.current }">
            <span class="tl-dot"></span>
            <div class="tl-body">
              <div class="tl-time">{{ s.time }}</div>
              <div class="tl-text">{{ s.text }}</div>
            </div>
          </div>
        </template>
        <div v-else class="empty-tip">暂无进度</div>
      </div>
      <template #footer>
        <el-button v-if="progressData?.reassign" type="primary">重新派单</el-button>
        <el-button @click="progressVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.notice-bar { background: #f4f4f5; color: #606266; padding: 8px 12px; font-size: 12px; margin-bottom: 3px; }
.toolbar { margin-bottom: 3px; }
.toolbar-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap; }
.toolbar-left { display: flex; gap: 8px; flex-wrap: nowrap; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.tf-label { font-size: 13px; color: #606266; white-space: nowrap; }
.search-btn { width: 30px; height: 30px; border: none; background: #67c23a; color: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 3px; }

.el-card { border-radius: 0; }
.el-table :deep(th) { background: #e6e6e6; text-align: left; }
.el-table :deep(td .cell) { text-align: left; }
.pager { margin-top: 12px; justify-content: flex-end; }

.avatar { width: 36px; height: 36px; border-radius: 4px; }
.avatar-xs { width: 20px; height: 20px; border-radius: 50%; vertical-align: middle; margin-right: 4px; }
.row-ops { display: flex; gap: 10px; flex-wrap: nowrap; }
.money-blue { color: #409eff; }
.tag-green { color: #67c23a; }
.tag-gray { color: #909399; }
.sub { color: #909399; font-size: 12px; }
.prod-cell { display: flex; gap: 8px; align-items: flex-start; }
.ml8 { margin-left: 8px; }

.goods-edit { margin: 3px; }
.ge-head { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; margin-bottom: 12px; }
.ge-title { font-size: 15px; font-weight: 600; }
.ge-close { font-size: 22px; cursor: pointer; color: #909399; line-height: 1; }
.ge-close:hover { color: #333; }
.ge-body { padding: 8px 0; }

.img-pick-row { display: flex; align-items: center; gap: 10px; }
.img-pick-thumb { width: 72px; height: 72px; border-radius: 4px; border: 1px solid #ebeef5; }
.img-tip { color: #909399; font-size: 12px; margin-left: 8px; }

.timeline { padding: 8px 0; }
.tl-item { display: flex; gap: 12px; padding: 8px 0; position: relative; }
.tl-dot { width: 10px; height: 10px; border-radius: 50%; background: #dcdfe6; margin-top: 4px; flex-shrink: 0; }
.tl-item.active .tl-dot { background: #67c23a; }
.tl-time { font-size: 13px; color: #303133; }
.tl-text { font-size: 13px; color: #606266; margin-top: 2px; }
.empty-tip { color: #909399; text-align: center; padding: 20px; }
</style>
