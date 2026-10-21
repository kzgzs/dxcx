<script setup lang="ts">
// 微信代认证 - 小程序列表（对齐点大：授权绑定/删除 + 搜索同行，表格一体无 fixed）
import { inject, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteAuthorizers,
  getAuthBindUrl,
  getProxyverifyAuthorizerList,
  saveAuthorizerRemark,
  type ProxyverifyAuthorizer,
} from '../../api/wxproxyverify'

const loading = ref(false)
const list = ref<ProxyverifyAuthorizer[]>([])
const total = ref(0)
const selected = ref<ProxyverifyAuthorizer[]>([])
const query = reactive({
  page: 1,
  pagesize: 10,
  authorizer_appid: '',
  nick_name: '',
  principal_name: '',
  verify_status: '',
  ctime: [] as string[],
})

const navigateByKey = inject<((key: string) => void) | undefined>('adminNavigateByKey')

async function load() {
  loading.value = true
  try {
    const res = await getProxyverifyAuthorizerList({
      page: query.page,
      pagesize: query.pagesize,
      authorizer_appid: query.authorizer_appid || undefined,
      nick_name: query.nick_name || undefined,
      principal_name: query.principal_name || undefined,
      verify_status: query.verify_status || undefined,
      ctime: query.ctime && query.ctime.length === 2 ? `${query.ctime[0]} ~ ${query.ctime[1]}` : undefined,
    })
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

// 认证状态文案（含认证类型后缀，对标点大 verify_type_id）
function verifyText(row: ProxyverifyAuthorizer) {
  if (row.verify_status !== 2) return row.verify_status_name
  const suffixMap: Record<number, string> = {
    0: '微信认证',
    1: '新浪微博认证',
    3: '已资质认证通过但还未通过名称认证',
    4: '已资质认证通过、还未通过名称认证，但通过了新浪微博认证',
  }
  const suffix = suffixMap[row.verify_type_id]
  return suffix ? `已认证\n${suffix}` : '已认证'
}

// 授权绑定：新窗口打开微信扫码授权页
async function handleAuthBind() {
  try {
    const res = await getAuthBindUrl()
    if (res.url) window.open(res.url, '_blank')
  } catch {
    /* 错误提示由拦截器统一弹出 */
  }
}

// 认证（跳转认证列表并带上 appid）
function startVerify(row: ProxyverifyAuthorizer) {
  sessionStorage.setItem('wx_pv_new_appid', row.authorizer_appid)
  sessionStorage.setItem('wx_pv_new_nick', row.nick_name)
  if (navigateByKey) navigateByKey('wx-proxyverify-order')
  else ElMessage.info('请切换到「认证列表」页发起认证')
}

async function batchDelete() {
  if (!selected.value.length) {
    ElMessage.warning('请选择数据')
    return
  }
  await ElMessageBox.confirm('确定要删除吗?认证信息等将一并删除，删除后数据将无法恢复！', '操作确认', { type: 'warning' })
  await deleteAuthorizers(selected.value.map((r) => r.id))
  ElMessage.success('删除成功')
  load()
}

async function handleDelete(row: ProxyverifyAuthorizer) {
  await ElMessageBox.confirm('确定要删除吗?认证信息等将一并删除，删除后数据将无法恢复！', '操作确认', { type: 'warning' })
  await deleteAuthorizers([row.id])
  ElMessage.success('删除成功')
  load()
}

// 备注（小弹窗）
const remarkVisible = ref(false)
const remarkSaving = ref(false)
const remarkForm = reactive({ id: 0, nick_name: '', remark: '' })

function openRemark(row: ProxyverifyAuthorizer) {
  remarkForm.id = row.id
  remarkForm.nick_name = row.nick_name
  remarkForm.remark = row.remark
  remarkVisible.value = true
}

async function handleRemarkSave() {
  remarkSaving.value = true
  try {
    await saveAuthorizerRemark(remarkForm.id, remarkForm.remark)
    ElMessage.success('操作成功')
    remarkVisible.value = false
    load()
  } finally {
    remarkSaving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="wxpv-index">
    <el-alert type="warning" :closable="false" class="notice">
      <template #title>
        <div class="notice-lines">
          <div>认证功能要使用需要设置几处：</div>
          <div>1、扩展-微信代认证-系统设置-状态及各类型代缴次数设置；</div>
          <div>2、控制台-系统设置-微信认证统一设置；</div>
          <div>3、控制台-用户列表-操作-微信认证中单独设置（此处给子平台使用，若不给使用则无需设置）</div>
          <div>4、若给子平台认证，建议走控制台-用户列表-操作-微信认证流程</div>
          <div>5、控制台-服务商配置，这里服务商主体需要和服务市场账号主体一致；</div>
        </div>
      </template>
    </el-alert>

    <el-card shadow="never" class="toolbar">
      <div class="toolbar-left">
        <el-button type="primary" @click="handleAuthBind">授权绑定</el-button>
        <el-button @click="batchDelete">删除</el-button>
      </div>
      <div class="toolbar-right">
        <el-input v-model="query.authorizer_appid" placeholder="小程序appid" class="field-sm" clearable @keyup.enter="handleSearch" />
        <el-input v-model="query.nick_name" placeholder="小程序名称" class="field-sm" clearable @keyup.enter="handleSearch" />
        <el-input v-model="query.principal_name" placeholder="主体名称" class="field-sm" clearable @keyup.enter="handleSearch" />
        <el-select v-model="query.verify_status" placeholder="认证状态" class="field-xs" clearable>
          <el-option label="全部" value="" />
          <el-option label="未认证" value="0" />
          <el-option label="认证中" value="1" />
          <el-option label="已认证" value="2" />
        </el-select>
        <el-date-picker
          v-model="query.ctime"
          type="daterange"
          range-separator="~"
          start-placeholder="授权开始"
          end-placeholder="授权结束"
          value-format="YYYY-MM-DD"
          class="field-range"
        />
        <el-button type="success" circle @click="handleSearch">
          <svg viewBox="0 0 1024 1024" width="15" height="15" fill="currentColor"><path d="M908.1 353.1l-253.9-36.9L540.7 86.1c-3.1-6.3-8.2-11.4-14.5-14.5-15.8-7.8-35-1.3-42.9 14.5L369.8 316.2l-253.9 36.9c-7 1-13.4 4.3-18.3 9.3a32.05 32.05 0 0 0 .6 45.3l183.7 179.1-43.4 252.9a31.95 31.95 0 0 0 46.4 33.7L512 754l227.1 119.4c6.2 3.3 13.4 3.2 19.4-.2 6-3.4 10.4-9.3 12-16 .7-2.9.7-6.1 0-9.2l-43.4-252.9 183.7-179.1a32 32 0 0 0-10.7-52.9z" /></svg>
        </el-button>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="list" border @selection-change="(v: ProxyverifyAuthorizer[]) => (selected = v)">
        <el-table-column type="selection" width="40" align="center" />
        <el-table-column label="小程序信息" min-width="230">
          <template #default="{ row }">
            <div class="app-cell">
              <el-image v-if="row.head_img" :src="row.head_img" fit="cover" class="app-img" preview-teleported />
              <div v-else class="app-img app-img-empty">{{ (row.nick_name || '?').slice(0, 1) }}</div>
              <div class="app-meta">
                <div class="app-nick">{{ row.nick_name || '-' }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="小程序appid/原始id" min-width="220">
          <template #default="{ row }">
            <div class="app-ids">{{ row.authorizer_appid }}<span v-if="row.user_name"> / {{ row.user_name }}</span></div>
          </template>
        </el-table-column>
        <el-table-column prop="principal_name" label="主体名称" min-width="150" show-overflow-tooltip />
        <el-table-column prop="auth_time_text" label="授权时间" width="150" sortable="custom" align="left" />
        <el-table-column label="认证状态" min-width="120">
          <template #default="{ row }">
            <div v-for="(line, i) in verifyText(row).split('\n')" :key="i" :class="['verify-line', row.verify_status === 2 ? 'ck-pass' : row.verify_status === 1 ? 'ck-wait' : 'st-end']">{{ line }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="100" show-overflow-tooltip />
        <el-table-column label="操作" width="170">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="startVerify(row)">认证</el-button>
              <el-button link type="primary" @click="openRemark(row)">备注</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
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

    <el-dialog v-model="remarkVisible" title="设置备注" width="460px" destroy-on-close>
      <el-form label-width="60px">
        <el-form-item label="小程序">
          <span>{{ remarkForm.nick_name }}</span>
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
  </div>
</template>

<style scoped>
.wxpv-index {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.notice { margin: 0; }
.notice-lines { line-height: 1.7; }
.toolbar :deep(.el-card__body) { padding: 3px 6px; display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; }
.toolbar-left { display: flex; gap: 6px; flex-shrink: 0; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex: 1; justify-content: flex-end; flex-wrap: nowrap; }
.app-cell { display: flex; align-items: center; gap: 8px; }
.app-img { width: 60px; height: 60px; border-radius: 4px; flex-shrink: 0; }
.app-img-empty { display: flex; align-items: center; justify-content: center; background: #f5f7fa; color: #909399; }
.app-nick { font-weight: 600; line-height: 1.4; }
.app-ids { word-break: break-all; }
.verify-line { line-height: 1.6; }
.ck-pass { color: #67c23a; }
.ck-wait { color: #e6a23c; }
.st-end { color: #909399; }
.pagination { display: flex; justify-content: flex-end; margin-top: 3px; }
</style>
