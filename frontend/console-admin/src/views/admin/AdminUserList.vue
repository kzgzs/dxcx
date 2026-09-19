<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import QRCode from 'qrcode'
import {
  bindAdminUserMember,
  deleteAdminUser,
  getAdminUserList,
  getUserGroupList,
  saveAdminUser,
  unbindAdminUserMember,
  type AdminUserItem,
  type UserGroupItem,
} from '../../api/system'
import { getMerchantList } from '../../api/merchant'
import type { MerchantItem } from '../../api/types'
import { formatTime } from '../../utils/time'
import UserGroupManage from './UserGroupManage.vue'

// ============ 手机端管理助手地址（buildH5Url 口径） ============
const ADMIN_PATH = 'admin/index/index'
function buildH5Url(p: string): string {
  const rel = p.replace(/^\//, '')
  return import.meta.env.DEV
    ? 'http://localhost:5174/#/' + rel
    : location.origin + '/h5/#/' + rel
}

// ============ 下拉选项（权限组 / 绑定商户） ============
const groupOptions = ref<UserGroupItem[]>([])
const merchantOptions = ref<MerchantItem[]>([])

async function fetchOptions() {
  try {
    const data = await getUserGroupList({ page: 1, pagesize: 100 })
    groupOptions.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
  try {
    const data = await getMerchantList({ page: 1, pagesize: 200 })
    merchantOptions.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<AdminUserItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getAdminUserList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
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

// bids 逗号串转数组
function parseBids(row: AdminUserItem): number[] {
  return String(row.bids || '')
    .split(',')
    .map((v) => Number(v))
    .filter((v) => v > 0)
}

// 权限组显示文案（主账号=全部权限；groupid=0=手动选择权限）
function groupText(row: AdminUserItem): string {
  if (row.is_main === 1) return '全部权限'
  if (row.groupid === 0) return '手动选择权限'
  return row.groupname || '未分配'
}

// ============ 删除 ============
function handleDelete(row: AdminUserItem) {
  ElMessageBox.confirm(`确定要删除账号「${row.un}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteAdminUser(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 绑定会员 / 解绑 ============
function handleBind(row: AdminUserItem) {
  ElMessageBox.prompt('请输入要绑定的会员ID（用于接收消息通知）', '绑定会员', {
    confirmButtonText: '绑定',
    cancelButtonText: '取消',
    inputPlaceholder: '请输入会员ID',
    inputValidator: (val) => {
      const n = Number(val)
      return Number.isInteger(n) && n > 0 ? true : '会员ID须为正整数'
    },
  })
    .then(async ({ value }) => {
      try {
        await bindAdminUserMember(row.id, Number(value))
        ElMessage.success('绑定成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleUnbind(row: AdminUserItem) {
  ElMessageBox.confirm(`确定要解绑账号「${row.un}」关联的会员吗？`, '解绑确认', {
    confirmButtonText: '解绑',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await unbindAdminUserMember(row.id)
        ElMessage.success('已解绑')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 权限组管理（内嵌弹窗） ============
const groupDialogVisible = ref(false)

// ============ 登录地址弹窗（H5 二维码 + 链接） ============
const loginUrlVisible = ref(false)
const loginQrDataUrl = ref('')
const h5AdminUrl = buildH5Url(ADMIN_PATH)

async function openLoginUrl() {
  loginUrlVisible.value = true
  try {
    loginQrDataUrl.value = await QRCode.toDataURL(h5AdminUrl, { width: 220, margin: 1 })
  } catch {
    loginQrDataUrl.value = ''
  }
}

function copyText(text: string) {
  const done = () => ElMessage.success('已复制')
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done))
  } else {
    fallbackCopy(text, done)
  }
}

function fallbackCopy(text: string, done: () => void) {
  const ta = document.createElement('textarea')
  ta.value = text
  ta.style.position = 'fixed'
  ta.style.opacity = '0'
  document.body.appendChild(ta)
  ta.select()
  try {
    document.execCommand('copy')
    done()
  } catch {
    ElMessage.warning('复制失败，请手动复制')
  }
  document.body.removeChild(ta)
}

function previewH5() {
  window.open(h5AdminUrl, '_blank')
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)
// 编辑时保留的原有字段（绑定商户/状态/姓名/手机，表单不再展示但不清空）
const keepFields = reactive({
  truename: '',
  mobile: '',
  bids: [] as number[],
  status: 1,
})

const form = reactive({
  id: 0,
  un: '',
  pwd: '',
  mid: 0,
  groupid: 0,
  remark: '',
})

const rules: FormRules = {
  un: [{ required: true, message: '请输入登录账号', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, un: '', pwd: '', mid: 0, groupid: 0, remark: '' })
  Object.assign(keepFields, { truename: '', mobile: '', bids: [], status: 1 })
  dialogVisible.value = true
}

function openEdit(row: AdminUserItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    un: row.un,
    pwd: '',
    mid: row.mid || 0,
    groupid: row.groupid || 0,
    remark: row.remark || '',
  })
  Object.assign(keepFields, {
    truename: row.truename || '',
    mobile: row.mobile || '',
    bids: parseBids(row),
    status: row.status,
  })
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if (!isEdit.value && !form.pwd) {
      ElMessage.warning('请设置登录密码')
      return
    }
    submitting.value = true
    try {
      await saveAdminUser({
        id: form.id || undefined,
        un: form.un,
        pwd: form.pwd || undefined,
        mid: form.mid || 0,
        groupid: form.groupid,
        remark: form.remark,
        truename: keepFields.truename,
        mobile: keepFields.mobile,
        bids: keepFields.bids,
        status: keepFields.status,
      })
      ElMessage.success(isEdit.value ? '保存成功' : '新增成功')
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

onMounted(() => {
  fetchList()
  fetchOptions()
})
</script>

<template>
  <div>
    <!-- 顶部说明条 -->
    <el-alert
      type="info"
      :closable="false"
      show-icon
      class="tip-alert"
      title="给管理员分配权限，可登录PC端后台及手机端管理助手，可设置核销权限用于订单核销"
    />

    <!-- 按钮区 + 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item>
          <el-button type="success" @click="openAdd">新增</el-button>
          <el-button type="primary" plain @click="openLoginUrl">登录地址</el-button>
          <el-button type="warning" plain @click="groupDialogVisible = true">权限组</el-button>
        </el-form-item>
        <el-form-item label="账号" style="margin-left: 12px">
          <el-input
            v-model="query.keyword"
            placeholder="请输入账号关键词"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 管理员列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="账号" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">
            <span>{{ row.un }}</span>
            <el-tag v-if="row.is_main === 1" type="warning" size="small" style="margin-left: 6px">主账号</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="所属权限组" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ groupText(row) }}</template>
        </el-table-column>
        <el-table-column label="创建人" width="110" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.is_main !== 1">{{ row.addname || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="最后登录时间" width="160">
          <template #default="{ row }">
            {{ row.logintime > 0 ? formatTime(row.logintime) : '-' }}
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip />
        <el-table-column label="绑定会员信息" min-width="190">
          <template #default="{ row }">
            <div v-if="row.mid > 0" class="member-cell">
              <el-avatar :size="32" :src="row.headimg || undefined">
                {{ (row.nickname || '?').slice(0, 1) }}
              </el-avatar>
              <span class="member-name">{{ row.nickname || `会员#${row.mid}` }}</span>
              <el-button link type="danger" @click="handleUnbind(row)">解绑</el-button>
            </div>
            <el-button v-else link type="primary" @click="handleBind(row)">绑定</el-button>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button
              v-if="row.is_main !== 1"
              link
              type="danger"
              @click="handleDelete(row)"
            >删除</el-button>
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

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑管理员' : '新增管理员'"
      width="560px"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="登录账号" prop="un">
          <el-input v-model="form.un" placeholder="请输入登录账号" />
        </el-form-item>
        <el-form-item label="登录密码">
          <el-input
            v-model="form.pwd"
            type="password"
            show-password
            :placeholder="isEdit ? '留空表示不修改密码' : '请设置登录密码（至少6位）'"
          />
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input-number
            v-model="form.mid"
            :min="0"
            :controls="false"
            placeholder="选填"
            style="width: 100%"
          />
          <div class="form-tip">用于绑定会员接收消息通知</div>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" type="textarea" :rows="2" placeholder="选填" />
        </el-form-item>
        <el-form-item label="权限组">
          <el-select v-model="form.groupid" placeholder="请选择权限组" style="width: 100%">
            <el-option
              v-for="g in groupOptions"
              :key="g.id"
              :label="g.name"
              :value="g.id"
            />
            <el-option label="手动选择权限" :value="0" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>

    <!-- 权限组管理（内嵌弹窗） -->
    <el-dialog
      v-model="groupDialogVisible"
      title="权限组管理"
      width="960px"
      top="5vh"
      destroy-on-close
    >
      <UserGroupManage />
    </el-dialog>

    <!-- 登录地址弹窗（H5 二维码 + 链接） -->
    <el-dialog v-model="loginUrlVisible" title="登录地址" width="480px">
      <div class="login-url-box">
        <img v-if="loginQrDataUrl" :src="loginQrDataUrl" class="login-qr" alt="H5登录二维码" />
        <div class="login-url-row">
          <span class="login-url-label">链接地址：</span>
          <span class="login-url-text">{{ h5AdminUrl }}</span>
        </div>
        <div class="login-url-actions">
          <el-button size="small" @click="copyText(h5AdminUrl)">复制</el-button>
          <el-button size="small" type="primary" @click="previewH5">预览</el-button>
        </div>
        <div class="login-url-tip">管理员可使用手机浏览器打开该地址，登录手机端管理助手</div>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.tip-alert {
  margin-bottom: 12px;
}

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

.member-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.member-name {
  max-width: 100px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.form-tip {
  font-size: 12px;
  color: #909399;
  line-height: 1.4;
  margin-top: 2px;
}

.login-url-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 8px 0 4px;
}

.login-qr {
  width: 220px;
  height: 220px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}

.login-url-row {
  width: 100%;
  font-size: 13px;
  word-break: break-all;
}

.login-url-label {
  color: #606266;
}

.login-url-text {
  color: #409eff;
}

.login-url-actions {
  display: flex;
  gap: 12px;
}

.login-url-tip {
  font-size: 12px;
  color: #909399;
}
</style>
