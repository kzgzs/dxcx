<script setup lang="ts">
// 微信代认证 - 小程序列表（批次7-8 任务216）
// 聚合 open_authorizer / wx_proxyverify_authorizer 展示本租户已授权小程序与认证状态
import { inject, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteAuthorizers,
  getProxyverifyAuthorizerList,
  saveAuthorizerRemark,
  type ProxyverifyAuthorizer,
} from '../../api/wxproxyverify'

const loading = ref(false)
const list = ref<ProxyverifyAuthorizer[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 20, keyword: '' })

// 菜单页跳转（发起认证 → 认证列表页携带 appid）
const navigateByKey = inject<((key: string) => void) | undefined>('adminNavigateByKey')

async function load() {
  loading.value = true
  try {
    const res = await getProxyverifyAuthorizerList(query)
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

function verifyTagType(status: number) {
  return status === 2 ? 'success' : status === 1 ? 'warning' : 'info'
}

// ============ 发起认证（跳转认证订单页） ============
function startVerify(row: ProxyverifyAuthorizer) {
  sessionStorage.setItem('wx_pv_new_appid', row.authorizer_appid)
  sessionStorage.setItem('wx_pv_new_nick', row.nick_name)
  if (navigateByKey) {
    navigateByKey('wx-proxyverify-order')
  } else {
    ElMessage.info('请切换到「认证列表」页发起认证')
  }
}

// ============ 备注 ============
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

// ============ 删除 ============
async function handleDelete(row: ProxyverifyAuthorizer) {
  await ElMessageBox.confirm(
    `确定删除小程序「${row.nick_name || row.authorizer_appid}」？其认证订单将一并删除`,
    '提示',
    { type: 'warning' },
  )
  await deleteAuthorizers([row.id])
  ElMessage.success('删除成功')
  load()
}

onMounted(load)
</script>

<template>
  <div class="wxpv-index">
    <div class="toolbar">
      <el-input
        v-model="query.keyword"
        placeholder="昵称 / AppID / 主体名称"
        clearable
        style="width: 260px"
        @keyup.enter="handleSearch"
        @clear="handleSearch"
      />
      <el-button type="primary" @click="handleSearch">搜索</el-button>
      <el-button @click="load">刷新同步</el-button>
      <span class="tip">列表聚合本租户已授权小程序，「刷新同步」从开放平台授权记录更新快照</span>
    </div>

    <el-table v-loading="loading" :data="list" border>
      <el-table-column label="小程序" min-width="220">
        <template #default="{ row }">
          <div class="app-cell">
            <el-avatar v-if="row.head_img" :src="row.head_img" :size="36" shape="square" />
            <el-avatar v-else :size="36" shape="square">{{ (row.nick_name || '?').slice(0, 1) }}</el-avatar>
            <div>
              <div class="nick">{{ row.nick_name || '-' }}</div>
              <div class="appid">{{ row.authorizer_appid }}</div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column prop="principal_name" label="主体名称" min-width="160" show-overflow-tooltip />
      <el-table-column label="认证状态" width="110">
        <template #default="{ row }">
          <el-tag :type="verifyTagType(row.verify_status)">{{ row.verify_status_name }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="auth_time_text" label="授权时间" width="150" />
      <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip />
      <el-table-column label="操作" width="220" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="startVerify(row)">发起认证</el-button>
          <el-button link type="primary" @click="openRemark(row)">备注</el-button>
          <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
      <template #empty>暂无已授权小程序</template>
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

    <el-dialog v-model="remarkVisible" title="修改备注" width="460px" destroy-on-close>
      <el-form label-width="80px">
        <el-form-item label="小程序">
          <span>{{ remarkForm.nick_name }}</span>
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
  </div>
</template>

<style scoped>
.wxpv-index {
  padding: 12px;
}
.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.toolbar .tip {
  color: #999;
  font-size: 12px;
  margin-left: 8px;
}
.app-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}
.app-cell .nick {
  font-weight: 600;
}
.app-cell .appid {
  color: #999;
  font-size: 12px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
</style>
