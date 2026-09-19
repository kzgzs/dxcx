<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getAuthorizerList,
  getAuthorizerDetail,
  getBindUrl,
  refreshAuthorizerToken,
  unbindAuthorizer,
} from '../api/open'
import { getTenantList } from '../api/tenant'
import type { AuthorizerDetail, AuthorizerItem, TenantItem } from '../api/types'
import { formatTime } from '../utils/time'

// ============ 筛选与列表 ============
const loading = ref(false)
const tableData = ref<AuthorizerItem[]>([])
const total = ref(0)

const filter = reactive({
  nick_name: '',
  authorizer_appid: '',
  account_type: '', // ''全部 official公众号 mini小程序
})

const query = reactive({
  page: 1,
  pagesize: 10,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getAuthorizerList({
      page: query.page,
      pagesize: query.pagesize,
      nick_name: filter.nick_name.trim() || undefined,
      authorizer_appid: filter.authorizer_appid.trim() || undefined,
      account_type: filter.account_type || undefined,
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
  filter.nick_name = ''
  filter.authorizer_appid = ''
  filter.account_type = ''
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

// ============ 类型映射 ============
// 微信 service_type_id：0订阅号 1升级订阅号 2服务号 3小程序
const SERVICE_TYPE_MAP: Record<number, string> = {
  0: '订阅号',
  1: '订阅号（升级）',
  2: '服务号',
  3: '小程序',
}

function serviceTypeText(row: AuthorizerItem) {
  return SERVICE_TYPE_MAP[row.service_type_id] ?? '未知类型'
}

// 认证类型映射
const VERIFY_TYPE_MAP: Record<number, string> = {
  [-1]: '未认证',
  0: '微信认证',
  1: '微博认证',
  2: '腾讯微博认证',
  3: '已资质认证',
  4: '已资质认证',
}

function verifyTypeText(id: number) {
  return VERIFY_TYPE_MAP[id] ?? '未知认证类型'
}

// 权限集数量
function funcCount(row: AuthorizerItem) {
  const f = row.func_info
  if (Array.isArray(f)) return f.length
  if (typeof f === 'string') {
    try {
      const parsed = JSON.parse(f)
      if (Array.isArray(parsed)) return parsed.length
    } catch {
      // 非 JSON 字符串，按 0 处理
    }
  }
  return 0
}

// ============ 查看详情 ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<AuthorizerDetail | null>(null)

async function handleDetail(row: AuthorizerItem) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await getAuthorizerDetail(row.id)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    detailLoading.value = false
  }
}

// 权限集名称列表（详情弹窗用）
function funcNames(detailRow: AuthorizerDetail): string[] {
  const f = detailRow.func_info
  const arr = Array.isArray(f)
    ? f
    : (() => {
        try {
          const p = JSON.parse(String(f))
          return Array.isArray(p) ? p : []
        } catch {
          return []
        }
      })()
  return arr
    .map((item: { funcscope_category?: { name?: string; id?: number } }) =>
      item?.funcscope_category?.name || `权限${item?.funcscope_category?.id ?? ''}`,
    )
}

// ============ 刷新令牌 ============
const refreshingId = ref(0)

function handleRefresh(row: AuthorizerItem) {
  refreshingId.value = row.id
  refreshAuthorizerToken(row.id)
    .then(() => {
      ElMessage.success('令牌刷新成功')
      fetchList()
    })
    .catch(() => {
      // 错误提示已由 axios 拦截器统一弹出
    })
    .finally(() => {
      refreshingId.value = 0
    })
}

// ============ 取消授权 ============
function handleUnbind(row: AuthorizerItem) {
  ElMessageBox.confirm(
    `确定要取消授权账号「${row.nick_name || row.authorizer_appid}」吗？取消后该账号将无法使用，需重新扫码授权。`,
    '取消授权确认',
    {
      confirmButtonText: '取消授权',
      cancelButtonText: '返回',
      type: 'warning',
    },
  )
    .then(async () => {
      try {
        await unbindAuthorizer(row.id)
        ElMessage.success('已取消授权')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 发起授权 ============
const authVisible = ref(false)
const bindLoading = ref(false)
const bindForm = reactive({
  auth_type: 1, // 1公众号 2小程序 3都有
  tenant_id: '' as number | '',
})
const tenantOptions = ref<TenantItem[]>([])

function openAuthDialog() {
  bindForm.auth_type = 1
  bindForm.tenant_id = ''
  authVisible.value = true
  // 拉取租户列表用于可选绑定
  if (tenantOptions.value.length === 0) {
    getTenantList({ page: 1, pagesize: 100 })
      .then((data) => {
        tenantOptions.value = data.list || []
      })
      .catch(() => {
        // 租户列表拉取失败不阻断授权流程
      })
  }
}

async function handleBind() {
  // 先同步打开空白窗口，避免 await 后再 window.open 被浏览器弹窗拦截
  const win = window.open('about:blank')
  bindLoading.value = true
  try {
    const data = await getBindUrl({
      auth_type: bindForm.auth_type,
      tenant_id: bindForm.tenant_id === '' ? undefined : bindForm.tenant_id,
    })
    if (win) {
      // 拿到 URL 后再把新窗口指向授权页
      win.location.href = data.url
      authVisible.value = false
      ElMessage.success('已打开授权页，请在微信页面完成授权')
      fetchList()
    } else {
      // 弹窗被拦截的兜底：提示用户并附复制链接
      ElMessageBox.alert('浏览器拦截了弹窗，请手动复制授权链接并在新窗口打开。', '发起授权提示', {
        confirmButtonText: '复制链接',
        type: 'warning',
        dangerouslyUseHTMLString: true,
        message: `<div style="word-break:break-all;margin-bottom:8px">浏览器拦截了弹窗，请手动打开以下授权链接：</div><div style="word-break:break-all;color:#409eff">${data.url}</div>`,
      })
        .then(() => {
          copyText(data.url)
        })
        .catch(() => {})
    }
  } catch {
    // 请求失败时关闭已打开的空白窗口；错误提示已由 axios 拦截器统一弹出
    win?.close()
  } finally {
    bindLoading.value = false
  }
}

// ============ 复制（失败降级 execCommand） ============
async function copyText(text: string) {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      ElMessage.success('已复制到剪贴板')
      return
    }
    throw new Error('clipboard api unavailable')
  } catch {
    const ta = document.createElement('textarea')
    ta.value = text
    ta.style.position = 'fixed'
    ta.style.left = '-9999px'
    ta.style.opacity = '0'
    document.body.appendChild(ta)
    ta.select()
    let ok = false
    try {
      ok = document.execCommand('copy')
    } catch {
      ok = false
    }
    document.body.removeChild(ta)
    if (ok) {
      ElMessage.success('已复制到剪贴板')
    } else {
      ElMessage.warning('复制失败，请手动选择内容复制')
    }
  }
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- 工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar">
        <span class="toolbar-tip">授权账号由微信公众号 / 小程序通过第三方平台授权绑定，授权长期有效</span>
        <el-button type="primary" @click="openAuthDialog">发起授权</el-button>
      </div>
    </el-card>

    <!-- 筛选 -->
    <el-card shadow="never" class="filter-card">
      <el-form inline @submit.prevent="handleSearch">
        <el-form-item label="昵称">
          <el-input
            v-model="filter.nick_name"
            placeholder="按昵称模糊搜索"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
            @clear="handleSearch"
          />
        </el-form-item>
        <el-form-item label="AppID">
          <el-input
            v-model="filter.authorizer_appid"
            placeholder="按 AppID 模糊搜索"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
            @clear="handleSearch"
          />
        </el-form-item>
        <el-form-item label="账号类型">
          <el-select
            v-model="filter.account_type"
            placeholder="全部"
            clearable
            style="width: 140px"
            @change="handleSearch"
          >
            <el-option label="公众号" value="official" />
            <el-option label="小程序" value="mini" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">查询</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 授权账号列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border empty-text="暂无授权账号，点击右上角「发起授权」绑定">
        <el-table-column label="账号" min-width="200">
          <template #default="{ row }">
            <div class="account-cell">
              <el-avatar :size="36" :src="row.head_img || undefined" shape="square" />
              <div class="account-info">
                <div class="account-name">{{ row.nick_name || '-' }}</div>
                <div class="account-appid">{{ row.authorizer_appid }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="账号类型" width="110">
          <template #default="{ row }">
            <el-tag :type="row.account_type === 'mini' ? 'warning' : 'primary'" size="small">
              {{ serviceTypeText(row) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="认证类型" width="110">
          <template #default="{ row }">
            <el-tag :type="row.verify_type_id === -1 ? 'info' : 'success'" size="small">
              {{ verifyTypeText(row.verify_type_id) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="权限集" width="80" align="center">
          <template #default="{ row }">
            {{ funcCount(row) }} 项
          </template>
        </el-table-column>
        <el-table-column label="授权状态" width="100">
          <template #default="{ row }">
            <el-tag :type="row.auth_status === 1 ? 'success' : 'danger'" size="small">
              {{ row.auth_status === 1 ? '已授权' : '已取消' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="授权时间" width="150">
          <template #default="{ row }">
            {{ formatTime(row.auth_time) }}
          </template>
        </el-table-column>
        <el-table-column label="到期时间" width="90" align="center">
          <template #default>
            <span class="expire-text">长期</span>
          </template>
        </el-table-column>
        <el-table-column label="绑定租户" min-width="110">
          <template #default="{ row }">
            {{ row.tenant_username || '未绑定' }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="184" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleDetail(row)">查看详情</el-button>
            <el-button
              link
              type="primary"
              :disabled="row.auth_status !== 1"
              :loading="refreshingId === row.id"
              @click="handleRefresh(row)"
            >
              刷新令牌
            </el-button>
            <el-button
              link
              type="danger"
              :disabled="row.auth_status !== 1"
              @click="handleUnbind(row)"
            >
              取消授权
            </el-button>
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

    <!-- 查看详情弹窗 -->
    <el-dialog v-model="detailVisible" title="授权账号详情" width="680px" destroy-on-close>
      <div v-loading="detailLoading">
        <el-descriptions v-if="detail" :column="2" border>
          <el-descriptions-item label="头像" :span="2">
            <el-avatar :size="48" :src="detail.head_img || undefined" shape="square" />
          </el-descriptions-item>
          <el-descriptions-item label="昵称">{{ detail.nick_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="AppID">{{ detail.authorizer_appid }}</el-descriptions-item>
          <el-descriptions-item label="原始ID">{{ detail.user_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="主体名称">{{ detail.principal_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="账号类型">
            <el-tag :type="detail.account_type === 'mini' ? 'warning' : 'primary'" size="small">
              {{ serviceTypeText(detail) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="认证类型">
            <el-tag :type="detail.verify_type_id === -1 ? 'info' : 'success'" size="small">
              {{ verifyTypeText(detail.verify_type_id) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="授权状态">
            <el-tag :type="detail.auth_status === 1 ? 'success' : 'danger'" size="small">
              {{ detail.auth_status === 1 ? '已授权' : '已取消' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="到期时间">长期</el-descriptions-item>
          <el-descriptions-item label="授权时间">{{ formatTime(detail.auth_time) }}</el-descriptions-item>
          <el-descriptions-item label="取消授权时间">{{ formatTime(detail.unauth_time) }}</el-descriptions-item>
          <el-descriptions-item label="绑定租户">{{ detail.tenant_username || '未绑定' }}</el-descriptions-item>
          <el-descriptions-item label="Refresh Token">{{ detail.refresh_token || '-' }}</el-descriptions-item>
          <el-descriptions-item label="权限集" :span="2">
            <template v-if="funcNames(detail).length">
              <el-tag
                v-for="name in funcNames(detail)"
                :key="name"
                size="small"
                class="func-tag"
              >
                {{ name }}
              </el-tag>
            </template>
            <span v-else>-</span>
          </el-descriptions-item>
          <el-descriptions-item v-if="detail.refresh_alert" label="令牌告警" :span="2">
            <span class="alert-text">{{ detail.refresh_alert }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ formatTime(detail.create_time) }}</el-descriptions-item>
          <el-descriptions-item label="更新时间">{{ formatTime(detail.update_time) }}</el-descriptions-item>
        </el-descriptions>
        <el-empty v-else-if="!detailLoading" description="暂无详情数据" />
      </div>
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>

    <!-- 发起授权弹窗 -->
    <el-dialog v-model="authVisible" title="发起授权" width="440px">
      <el-form label-width="100px" label-position="left">
        <el-form-item label="授权类型">
          <el-radio-group v-model="bindForm.auth_type">
            <el-radio :value="1">公众号</el-radio>
            <el-radio :value="2">小程序</el-radio>
            <el-radio :value="3">全部（公众号+小程序）</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="绑定租户">
          <el-select v-model="bindForm.tenant_id" placeholder="可选，授权后绑定到指定租户" clearable>
            <el-option
              v-for="t in tenantOptions"
              :key="t.id"
              :label="`${t.company || t.username}（${t.username}）`"
              :value="t.id"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="authVisible = false">取消</el-button>
        <el-button type="primary" :loading="bindLoading" @click="handleBind">
          打开授权页
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card :deep(.el-card__body) {
  padding: 12px 20px;
}

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.toolbar-tip {
  color: #909399;
  font-size: 13px;
}

.filter-card {
  margin-top: 16px;
}

.filter-card :deep(.el-card__body) {
  padding: 16px 20px 0;
}

.account-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.account-name {
  font-weight: 500;
  color: #303133;
}

.account-appid {
  color: #909399;
  font-size: 12px;
}

.expire-text {
  color: #67c23a;
}

.func-tag {
  margin-right: 6px;
  margin-bottom: 4px;
}

.alert-text {
  color: #f56c6c;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
