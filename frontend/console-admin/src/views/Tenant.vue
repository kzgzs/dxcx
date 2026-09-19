<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown } from '@element-plus/icons-vue'
import type { FormInstance, FormRules } from 'element-plus'
import {
  addTenant,
  deleteTenant,
  downloadUniappZip,
  editTenant,
  getTenantList,
  proxyLogin,
} from '../api/tenant'
import type { TenantItem } from '../api/types'
import { useAuthStore } from '../stores/auth'
import { getDashboard } from '../api/dashboard'
import type { DashboardData } from '../api/types'

const authStore = useAuthStore()
const router = useRouter()

// ============ 统计四卡（原 Dashboard） ============
const statsLoading = ref(false)
const stats = ref<DashboardData>({
  tenant_total: 0,
  tenant_normal: 0,
  tenant_expired: 0,
  member_total: 0,
  order_total: 0,
  order_amount: 0,
  member_yesterday: 0,
  member_month: 0,
  goods_total: 0,
  goods_on: 0,
  goods_off: 0,
  order_yesterday_count: 0,
  order_yesterday_amount: 0,
  order_month_count: 0,
  order_month_amount: 0,
  pay_total: 0,
  pay_yesterday: 0,
  pay_month: 0,
})

// 千分位格式化
function fmtInt(n: number): string {
  return Math.round(n || 0).toLocaleString('en-US')
}
function fmtMoney(n: number): string {
  return (n || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

async function fetchStats() {
  statsLoading.value = true
  try {
    stats.value = await getDashboard()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    statsLoading.value = false
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<TenantItem[]>([])
const total = ref(0)
const selectedRows = ref<TenantItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  id: '',
  account: '',
  keyword: '',
  status: '' as number | '',
  sort_field: '',
  sort_order: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getTenantList({
      page: query.page,
      pagesize: query.pagesize,
      id: query.id || undefined,
      account: query.account || undefined,
      keyword: query.keyword || undefined,
      status: query.status === '' ? undefined : query.status,
      sort_field: query.sort_field || undefined,
      sort_order: query.sort_order || undefined,
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

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: TenantItem[]) {
  selectedRows.value = rows
}

// 登录时间/创建时间/到期时间 列排序（custom → 后端排序）
function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  if (order) {
    query.sort_field = prop
    query.sort_order = order === 'ascending' ? 'asc' : 'desc'
  } else {
    query.sort_field = ''
    query.sort_order = ''
  }
  fetchList()
}

// 展示 ID：admin 虚拟行=1，真实租户=真实 id+1（筛选反解在后端）
function displayId(row: TenantItem): number {
  return row.is_super === 1 ? 1 : row.id + 1
}

// 时间列短格式：只显示 YY-MM-DD（完整时间悬浮 tooltip 可见），列宽更紧凑
function shortDate(v: string): string {
  if (!v) return ''
  const d = v.slice(0, 10)
  return d.length === 10 ? d.slice(2) : v
}

// ============ 工具栏：添加 / 删除 / 复制 ============
async function handleBatchDelete() {
  if (!selectedRows.value.length) {
    ElMessage.warning('请先勾选要删除的用户')
    return
  }
  if (selectedRows.value.some((r) => r.is_super === 1)) {
    ElMessage.warning('平台超管账号不可删除，已自动忽略')
  }
  const rows = selectedRows.value.filter((r) => r.is_super !== 1)
  if (!rows.length) return
  try {
    await ElMessageBox.confirm(`确定要删除勾选的 ${rows.length} 个用户吗？删除后不可恢复。`, '删除确认', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    for (const row of rows) {
      await deleteTenant(row.id)
    }
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleCopy() {
  if (!selectedRows.value.length) {
    ElMessage.warning('请先勾选要复制的用户')
    return
  }
  const text = selectedRows.value
    .map((r) => `${displayId(r)}\t${r.username}${r.contacts ? '\t' + r.contacts : ''}${r.phone ? '\t' + r.phone : ''}`)
    .join('\n')
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success(`已复制 ${selectedRows.value.length} 个用户的账号信息`)
  } catch {
    ElMessage.error('复制失败，浏览器不支持剪贴板接口')
  }
}

// ============ 新增 / 编辑抽屉 ============
const drawerVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

function fmtDate(d: Date): string {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

const form = reactive({
  id: 0,
  is_super: 0,
  username: '',
  password: '',
  contacts: '',
  phone: '',
  remark: '',
  endtime: '',
  status: 1,
})

const rules: FormRules = {
  username: [{ required: true, message: '请输入登录账号', trigger: 'blur' }],
  password: [
    {
      validator: (_rule, value, callback) => {
        if (!isEdit.value && !value) {
          callback(new Error('请输入密码（留空默认 123456）'))
        } else if (value && (value.length < 6 || value.length > 32)) {
          callback(new Error('密码长度必须为 6-32 位'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
}

function openAdd() {
  isEdit.value = false
  const def = new Date()
  def.setDate(def.getDate() + 365)
  Object.assign(form, {
    id: 0,
    is_super: 0,
    username: '',
    password: '',
    contacts: '',
    phone: '',
    remark: '',
    endtime: fmtDate(def),
    status: 1,
  })
  drawerVisible.value = true
}

function openEdit(row: TenantItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    is_super: row.is_super === 1 ? 1 : 0,
    username: row.username,
    password: '',
    contacts: row.contacts,
    phone: row.phone,
    remark: row.remark,
    endtime: row.is_super === 1 ? '' : row.endtime.slice(0, 10),
    status: row.status,
  })
  drawerVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      if (isEdit.value) {
        await editTenant({
          id: form.id,
          username: form.username,
          password: form.password,
          contacts: form.contacts,
          phone: form.phone,
          remark: form.remark,
          endtime: form.is_super === 1 ? undefined : form.endtime,
          status: form.is_super === 1 ? undefined : form.status,
        })
        ElMessage.success('保存成功')
      } else {
        // 到期日期 → 开通天数
        const end = new Date(`${form.endtime}T23:59:59`).getTime()
        const days = Math.max(1, Math.round((end - Date.now()) / 86400000))
        await addTenant({
          username: form.username,
          company: '',
          contacts: form.contacts,
          phone: form.phone,
          remark: form.remark,
          expire_days: days,
          password: form.password || undefined,
          status: form.status,
        })
        ElMessage.success('新增成功')
      }
      drawerVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

// ============ 删除（单行） ============
function handleDelete(row: TenantItem) {
  if (row.is_super === 1) {
    ElMessage.warning('平台超管账号不可删除')
    return
  }
  ElMessageBox.confirm(`确定要删除用户「${row.username}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteTenant(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 代登录 / 登录后台 ============
// id=0（admin 行，super 通道）：寄存控制台会话，租户壳底部「控制台」入口可恢复返回
// id>0（普通租户）：真实退出超管——清除 console token/role 及 super_back_token 寄存，
//   再带租户 token 建立租户会话直跳 /#/Merchant/index，顶栏显示租户账号
async function handleProxyLogin(row: TenantItem) {
  try {
    const data = await proxyLogin(row.id)
    if (row.is_super === 1) {
      // 寄存控制台会话（供租户壳底部「控制台」入口返回），再清除当前会话，避免双身份并存
      authStore.stashConsoleSession()
      authStore.logout(true)
      location.href =
        `${location.origin}${location.pathname}${location.search}` +
        `#/login?token=${encodeURIComponent(data.token)}`
      return
    }
    // 普通租户：连寄存一并清除（真实退出超管），localStorage 不再残留 console token / super_back_token
    authStore.logout()
    try {
      // 带租户 token 建立会话（拉 profile 判角色写入 admin 会话），直跳租户壳唯一入口
      await authStore.loginByToken(data.token)
      router.push('/Merchant/index')
    } catch {
      // 换会话失败兜底走直登通道（会话已清，无寄存残留）
      location.href =
        `${location.origin}${location.pathname}${location.search}` +
        `#/login?token=${encodeURIComponent(data.token)}`
    }
  } catch {
    // 请求失败不清会话；错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 下载 uni-app 代码包 ============
const downloading = ref(false)
async function handleDownload() {
  if (downloading.value) return
  downloading.value = true
  try {
    await downloadUniappZip()
    ElMessage.success('uni-app 代码包已开始下载')
  } catch (e) {
    ElMessage.error(e instanceof Error ? e.message : '下载失败')
  } finally {
    downloading.value = false
  }
}

// ============ 微信认证说明弹窗 ============
const wxAuthVisible = ref(false)

// 操作列「更多」下拉：下载 uni-app 代码包 / 微信认证（次要操作收纳，列宽更紧凑）
function handleMoreCommand(command: string, _row: TenantItem) {
  if (command === 'download') {
    handleDownload()
  } else if (command === 'wxauth') {
    wxAuthVisible.value = true
  }
}

onMounted(() => {
  fetchStats()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 统计四卡（原 Dashboard，整合至此页顶部） -->
    <el-row :gutter="16" v-loading="statsLoading">
      <!-- 卡 1：平台会员总数 -->
      <el-col :xs="24" :sm="12" :md="12" :lg="6">
        <el-card class="stat-card" shadow="never">
          <div class="stat-title">平台会员总数</div>
          <div class="stat-value">{{ fmtInt(stats.member_total) }}</div>
          <div class="stat-sub">
            <div class="sub-row">
              <span>昨日新增</span>
              <span>{{ fmtInt(stats.member_yesterday) }}</span>
            </div>
            <div class="sub-row">
              <span>本月新增</span>
              <span>{{ fmtInt(stats.member_month) }}</span>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 卡 2：平台商品数量 -->
      <el-col :xs="24" :sm="12" :md="12" :lg="6">
        <el-card class="stat-card" shadow="never">
          <div class="stat-title">平台商品数量</div>
          <div class="stat-value">{{ fmtInt(stats.goods_total) }}</div>
          <div class="stat-sub">
            <div class="sub-row">
              <span>未上架</span>
              <span>{{ fmtInt(stats.goods_off) }}</span>
            </div>
            <div class="sub-row">
              <span>已上架</span>
              <span>{{ fmtInt(stats.goods_on) }}</span>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 卡 3：平台订单数 / 订单金额 -->
      <el-col :xs="24" :sm="12" :md="12" :lg="6">
        <el-card class="stat-card" shadow="never">
          <div class="stat-title">平台订单数 / 订单金额</div>
          <div class="stat-value">
            {{ fmtInt(stats.order_total) }} / {{ fmtMoney(stats.order_amount) }}
          </div>
          <div class="stat-sub">
            <div class="sub-row">
              <span>昨日新增</span>
              <span>
                {{ fmtInt(stats.order_yesterday_count) }} /
                {{ fmtMoney(stats.order_yesterday_amount) }}
              </span>
            </div>
            <div class="sub-row">
              <span>本月新增</span>
              <span>
                {{ fmtInt(stats.order_month_count) }} /
                {{ fmtMoney(stats.order_month_amount) }}
              </span>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 卡 4：平台收款金额（绿色大字） -->
      <el-col :xs="24" :sm="12" :md="12" :lg="6">
        <el-card class="stat-card" shadow="never">
          <div class="stat-title">平台收款金额</div>
          <div class="stat-value stat-money">¥ {{ fmtMoney(stats.pay_total) }}</div>
          <div class="stat-sub">
            <div class="sub-row">
              <span>昨日新增</span>
              <span>¥ {{ fmtMoney(stats.pay_yesterday) }}</span>
            </div>
            <div class="sub-row">
              <span>本月新增</span>
              <span>¥ {{ fmtMoney(stats.pay_month) }}</span>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 顶部提示横幅 -->
    <div class="tip-banner">
      列表中的每个用户对应一套独立的商城系统：用户使用账号密码登录后，即可绑定自己的公众号、小程序，开始搭建专属商城。<br />
      通过“下载uni-app代码包”获取的是 uni-app 前端代码，需借助 HBuilderX 编译后再发布；若要上传微信小程序代码，请点击“登录后台”，进入“平台-微信小程序-上传代码”页面扫码上传或手动上传。
    </div>

    <el-card shadow="never">
      <div class="list-title">用户列表</div>

      <!-- 工具栏：添加/删除/复制 + 筛选行 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button class="btn-teal" @click="openAdd">添加</el-button>
          <el-button @click="handleBatchDelete">删除</el-button>
          <el-button @click="handleCopy">复制</el-button>
        </div>
        <div class="toolbar-filter">
          <span class="filter-label">ID</span>
          <el-input
            v-model="query.id"
            style="width: 90px"
            clearable
            @keyup.enter="handleSearch"
          />
          <span class="filter-label">账号</span>
          <el-input
            v-model="query.account"
            placeholder="账号/联系人/电话"
            style="width: 170px"
            clearable
            @keyup.enter="handleSearch"
          />
          <span class="filter-label">关键字</span>
          <el-input
            v-model="query.keyword"
            placeholder="公众号名称/小程序名称"
            style="width: 190px"
            clearable
            @keyup.enter="handleSearch"
          />
          <span class="filter-label">开启状态</span>
          <el-select v-model="query.status" style="width: 110px">
            <el-option label="全部" value="" />
            <el-option label="正常" :value="1" />
            <el-option label="关闭" :value="2" />
          </el-select>
          <el-button class="btn-teal" title="搜索" @click="handleSearch">搜索</el-button>
        </div>
      </div>

      <!-- 用户列表表格（border 竖线，与通知公告统一） -->
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        border
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="42" />
        <el-table-column label="ID" width="50">
          <template #default="{ row }">{{ displayId(row) }}</template>
        </el-table-column>
        <el-table-column prop="username" label="账号" min-width="90" show-overflow-tooltip />
        <el-table-column prop="logintime" label="登录时间" width="96" sortable="custom">
          <template #default="{ row }">
            <el-tooltip v-if="row.logintime" :content="row.logintime" placement="top">
              <span>{{ shortDate(row.logintime) }}</span>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column prop="bind_mp" label="绑定公众号" min-width="80" show-overflow-tooltip />
        <el-table-column prop="bind_wx" label="绑定小程序" min-width="80" show-overflow-tooltip />
        <el-table-column label="联系人&电话" min-width="95" show-overflow-tooltip>
          <template #default="{ row }">
            <span>{{ [row.contacts, row.phone].filter(Boolean).join(' / ') }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="66" show-overflow-tooltip />
        <el-table-column prop="createtime" label="创建时间" width="96" sortable="custom">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="row.createtime" placement="top">
              <span>{{ shortDate(row.createtime) }}</span>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column
          prop="endtime"
          label="到期时间"
          width="120"
          sortable="custom"
          class-name="compact-col"
        >
          <template #default="{ row }">
            <el-tooltip v-if="row.endtime" :content="row.endtime" placement="top">
              <span>{{ shortDate(row.endtime) }}</span>
            </el-tooltip>
            <el-tag v-if="row.is_expired" type="danger" size="small" class="expired-tag">
              已到期
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="54">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'status-on' : 'status-off'">
              {{ row.status === 1 ? '正常' : '关闭' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <div class="op-wrap">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="primary" @click="handleProxyLogin(row)">登录后台</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              <el-dropdown trigger="click" @command="(cmd: string) => handleMoreCommand(cmd, row)">
                <el-button link type="primary">
                  更多<el-icon class="more-arrow"><ArrowDown /></el-icon>
                </el-button>
                <template #dropdown>
                  <el-dropdown-menu>
                    <el-dropdown-item command="download" :disabled="downloading">
                      下载uni-app代码包
                    </el-dropdown-item>
                    <el-dropdown-item command="wxauth">微信认证</el-dropdown-item>
                  </el-dropdown-menu>
                </template>
              </el-dropdown>
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

    <!-- 新增 / 编辑抽屉（当页保存，不刷新不跳转） -->
    <el-drawer
      v-model="drawerVisible"
      :title="isEdit ? '编辑用户' : '添加用户'"
      size="420px"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="账号" prop="username">
          <el-input
            v-model="form.username"
            :disabled="form.is_super === 1"
            placeholder="请输入登录账号"
          />
        </el-form-item>
        <el-form-item label="密码">
          <el-input
            v-model="form.password"
            type="password"
            show-password
            autocomplete="new-password"
            :placeholder="isEdit ? '留空则不修改密码' : '留空默认 123456'"
          />
        </el-form-item>
        <el-form-item label="联系人">
          <el-input v-model="form.contacts" placeholder="请输入联系人" />
        </el-form-item>
        <el-form-item label="电话">
          <el-input v-model="form.phone" placeholder="请输入电话" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input
            v-model="form.remark"
            type="textarea"
            :rows="3"
            placeholder="请输入备注"
          />
        </el-form-item>
        <el-form-item label="到期时间">
          <el-date-picker
            v-if="form.is_super !== 1"
            v-model="form.endtime"
            type="date"
            value-format="YYYY-MM-DD"
            placeholder="选择到期日期"
            style="width: 100%"
          />
          <el-input v-else model-value="永久" disabled />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="form.status" :disabled="form.is_super === 1" style="width: 100%">
            <el-option label="正常" :value="1" />
            <el-option label="关闭" :value="2" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="drawerVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          保存
        </el-button>
      </template>
    </el-drawer>

    <!-- 微信认证说明弹窗 -->
    <el-dialog v-model="wxAuthVisible" title="微信认证" width="460px">
      <div class="wx-auth-body">
        <p>
          微信认证需通过开放平台第三方平台完成授权绑定。请前往控制台左侧菜单
          「开放平台设置」配置第三方平台参数，并在「授权管理」中发起授权，
          绑定公众号 / 小程序后即可进行微信认证与代码发布。
        </p>
        <p class="wx-auth-tip">
          提示：登录后台后，在「平台 - 微信小程序」相关设置中可上传与管理小程序代码。
        </p>
      </div>
      <template #footer>
        <el-button type="primary" @click="wxAuthVisible = false">知道了</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
/* ===== 统计四卡样式（原 Dashboard） ===== */
.stat-card {
  margin-bottom: 16px;
}

.stat-title {
  font-size: 13px;
  color: #606266;
}

.stat-value {
  margin: 10px 0 12px;
  font-size: 26px;
  font-weight: 600;
  color: #303133;
  line-height: 1.3;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* 收款金额绿色大字 */
.stat-money {
  color: #52c41a;
}

.stat-sub {
  border-top: 1px solid var(--el-border-color-lighter, #ebeef5);
  padding-top: 8px;
}

.sub-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 3px 0;
  font-size: 12px;
  color: #909399;
}

.sub-row span:last-child {
  color: #606266;
}

/* ===== 原有样式从这开始 ===== */
.tip-banner {
  margin-bottom: 12px;
  padding: 10px 14px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  background-color: #f5f5f5;
  color: #606266;
  font-size: 13px;
  line-height: 1.9;
}

.list-title {
  margin-bottom: 12px;
  font-size: 14px;
  font-weight: 600;
  color: #303133;
}

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}

.toolbar-left,
.toolbar-filter {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.filter-label {
  color: #606266;
  font-size: 13px;
}

/* 青绿色主操作按钮（添加/搜索，对齐截图） */
.btn-teal {
  --el-button-bg-color: #13c2c2;
  --el-button-border-color: #13c2c2;
  --el-button-hover-bg-color: #36cfc9;
  --el-button-hover-border-color: #36cfc9;
  --el-button-active-bg-color: #0ea9a9;
  --el-button-active-border-color: #0ea9a9;
  --el-button-text-color: #ffffff;
  color: #ffffff;
  background-color: #13c2c2;
  border-color: #13c2c2;
}

.btn-teal:hover,
.btn-teal:focus {
  color: #ffffff;
  background-color: #36cfc9;
  border-color: #36cfc9;
}

/* 操作列：link 文字按钮单行排布（紧凑间距，控制在 180px 内） */
.op-wrap {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 2px;
  white-space: nowrap;
}

/* 到期时间/操作列：收紧单元格左右内边距，内容更紧凑 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}

.more-arrow {
  margin-left: 2px;
  font-size: 12px;
}

.status-on {
  color: #67c23a;
}

.status-off {
  color: #f56c6c;
}

.expired-tag {
  margin-left: 2px;
  padding-left: 4px;
  padding-right: 4px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.wx-auth-body {
  font-size: 13px;
  line-height: 1.9;
  color: #606266;
}

.wx-auth-tip {
  color: #909399;
}
</style>
