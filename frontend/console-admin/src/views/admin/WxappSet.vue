<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { uploadFile } from '../../api/goods'
import {
  getWxappAccountInfo,
  setWxappNickname,
  setWxappHeadimg,
  setWxappSignature,
  setWxappSearchStatus,
  getWxappServerDomain,
  saveWxappServerDomain,
  getWxappJumpDomain,
  saveWxappJumpDomain,
  getWxappJumpDomainFile,
  getWxappPrivacy,
  saveWxappPrivacy,
  getWxappIcpInfo,
  saveWxappIcp,
  launchWxappIcpFace,
  submitWxappIcp,
  type WxappAccountInfo,
  type WxappServerDomain,
  type WxappPrivacyInfo,
} from '../../api/wxapp'

const activeTab = ref('account')
const loading = ref(false)

/* ==================== 账号资料 ==================== */
const account = ref<WxappAccountInfo | null>(null)
const nickname = ref('')
const headimg = ref('')
const signature = ref('')
const savingAccount = ref(false)

async function loadAccount() {
  try {
    account.value = await getWxappAccountInfo()
    nickname.value = account.value.nickname
    headimg.value = account.value.headimg
    signature.value = account.value.signature
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

async function handleSaveAccount() {
  savingAccount.value = true
  try {
    if (nickname.value !== (account.value?.nickname ?? '')) {
      await setWxappNickname(nickname.value)
    }
    if (headimg.value !== (account.value?.headimg ?? '')) {
      await setWxappHeadimg(headimg.value)
    }
    if (signature.value !== (account.value?.signature ?? '')) {
      await setWxappSignature(signature.value)
    }
    ElMessage.success('保存成功')
    loadAccount()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    savingAccount.value = false
  }
}

async function handleSearchStatus(open: 0 | 1) {
  try {
    await setWxappSearchStatus(open)
    ElMessage.success('修改成功')
    loadAccount()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

/* ==================== 基本信息修改弹窗 ==================== */
const editVisible = ref(false)
const editType = ref<'nickname' | 'headimg' | 'signature'>('nickname')
const editVal = ref('')
const editSaving = ref(false)
const editTitleMap = { nickname: '修改小程序名称', headimg: '修改小程序头像', signature: '修改功能介绍' }
const editTitle = computed(() => editTitleMap[editType.value])

const categoriesText = computed(() => {
  const list = account.value?.categories
  if (!Array.isArray(list) || !list.length) return ''
  return list.map((c) => String((c as Record<string, unknown>).name ?? '')).filter(Boolean).join('\n')
})
const liveEnabled = computed(() => {
  const list = (account.value?.categories ?? []) as Array<Record<string, unknown>>
  return list.some((c) => Number(c.id) === 65 || String(c.name ?? '').includes('直播'))
})

function openEdit(type: 'nickname' | 'headimg' | 'signature') {
  editType.value = type
  editVal.value = type === 'nickname' ? account.value?.nickname ?? '' : type === 'headimg' ? account.value?.headimg ?? '' : account.value?.signature ?? ''
  editVisible.value = true
}

async function submitEdit() {
  editSaving.value = true
  try {
    if (editType.value === 'nickname') {
      await setWxappNickname(editVal.value)
    } else if (editType.value === 'headimg') {
      await setWxappHeadimg(editVal.value)
    } else {
      await setWxappSignature(editVal.value)
    }
    ElMessage.success('修改成功')
    editVisible.value = false
    loadAccount()
  } catch {
    /* 拦截器已提示 */
  } finally {
    editSaving.value = false
  }
}

async function uploadHeadimg(opts: { file: File }) {
  try {
    const data = await uploadFile(opts.file)
    editVal.value = data.url
    ElMessage.success('上传成功')
  } catch {
    /* 拦截器已提示 */
  }
}

function goLeimu() {
  window.location.hash = '#/Wxleimu/index'
}
function goNearby() {
  ElMessage.info('附近小程序地点需在微信小程序后台添加')
}

/* ==================== 服务器域名 ==================== */
const serverDomain = ref<WxappServerDomain>({
  requestdomain: '',
  wsrequestdomain: '',
  uploaddomain: '',
  downloaddomain: '',
})
const savingDomain = ref(false)

async function loadServerDomain() {
  try {
    serverDomain.value = await getWxappServerDomain()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

async function handleSaveDomain() {
  savingDomain.value = true
  try {
    await saveWxappServerDomain({ ...serverDomain.value })
    ElMessage.success('设置成功，发布小程序后生效')
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    savingDomain.value = false
  }
}

/* ==================== 业务域名 ==================== */
const webviewdomain = ref('')
const domainFile = ref<{ file_name: string; url: string; content: string } | null>(null)
const savingJump = ref(false)

async function loadJumpDomain() {
  try {
    const rs = await getWxappJumpDomain()
    webviewdomain.value = rs.webviewdomain || ''
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

async function handleSaveJump() {
  savingJump.value = true
  try {
    await saveWxappJumpDomain(webviewdomain.value)
    ElMessage.success('设置成功，发布小程序后生效')
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    savingJump.value = false
  }
}

async function handleDomainFile() {
  try {
    domainFile.value = await getWxappJumpDomainFile()
    ElMessage.success('校验文件已生成')
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

/* ==================== 隐私指引 ==================== */
const privacy = ref<WxappPrivacyInfo | null>(null)
// 已选隐私项 privacy_key -> { 用途说明 }
const selectedKeys = ref<string[]>([])
const purposeMap = ref<Record<string, string>>({})
const ownerSetting = ref<Record<string, string>>({
  contact_qq: '',
  contact_email: '',
  contact_phone: '',
  store_expire_timestamp: '',
})
const savingPrivacy = ref(false)

// 可选隐私项字典（后端 privacy_keys）
const allKeys = ref<Array<{ privacy_key: string; privacy_desc: string }>>([])

async function loadPrivacy() {
  try {
    privacy.value = await getWxappPrivacy()
    allKeys.value = privacy.value.privacy_keys || []
    selectedKeys.value = (privacy.value.setting_list || []).map(
      (item) => String(item.privacy_key ?? ''),
    )
    for (const item of privacy.value.setting_list || []) {
      purposeMap.value[String(item.privacy_key ?? '')] = String(item.ext_msg ?? '')
    }
    ownerSetting.value = { ...ownerSetting.value, ...(privacy.value.owner_setting || {}) }
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

async function handleSavePrivacy() {
  savingPrivacy.value = true
  try {
    const settingList = selectedKeys.value.map((key) => {
      const found = allKeys.value.find((k) => k.privacy_key === key)
      return {
        privacy_key: key,
        privacy_text: found?.privacy_desc || '',
        ext_msg: purposeMap.value[key] || '',
      }
    })
    await saveWxappPrivacy({ ...ownerSetting.value }, settingList)
    ElMessage.success('设置成功，发布小程序后生效')
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    savingPrivacy.value = false
  }
}

/* ==================== ICP 备案 ==================== */
const icp = ref<Record<string, unknown>>({})
const icpForm = ref<Record<string, string>>({
  icp_subject: '',
  subject_type: '',
  principal_name: '',
  principal_credentials_type: '1',
  principal_credentials_number: '',
  principal_mobile: '',
  principal_email: '',
  emergency_contact: '',
  emergency_contact_mobile: '',
})
const savingIcp = ref(false)

const faceStatusMap: Record<number, string> = {
  [-1]: '未核身',
  0: '核身中',
  3: '核身通过',
  4: '核身失败',
}

async function loadIcp() {
  try {
    icp.value = await getWxappIcpInfo()
    for (const key of Object.keys(icpForm.value)) {
      const val = icp.value[key]
      if (val !== undefined && val !== null && val !== '') {
        icpForm.value[key] = String(val)
      }
    }
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

async function handleSaveIcp() {
  savingIcp.value = true
  try {
    await saveWxappIcp({ ...icpForm.value })
    ElMessage.success('保存成功')
    loadIcp()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    savingIcp.value = false
  }
}

async function handleFaceCheck() {
  try {
    await launchWxappIcpFace()
    ElMessage.success('人脸核身已通过（本地演练）')
    loadIcp()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

async function handleSubmitIcp() {
  try {
    await ElMessageBox.confirm('确认提交备案申请？提交后进入平台审核流程。', '提交备案', { type: 'warning' })
  } catch {
    return
  }
  try {
    await submitWxappIcp()
    ElMessage.success('提交成功')
    loadIcp()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

function onTabChange(tab: string | number) {
  const name = String(tab)
  if (name === 'account') loadAccount()
  else if (name === 'domain') loadServerDomain()
  else if (name === 'jump') loadJumpDomain()
  else if (name === 'privacy') loadPrivacy()
  else if (name === 'icp') loadIcp()
}

onMounted(() => {
  loading.value = true
  loadAccount().finally(() => {
    loading.value = false
  })
})
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <span class="card-title">小程序运营设置</span>
      </template>

      <el-tabs v-model="activeTab" @tab-change="onTabChange">
        <!-- 账号资料（基本信息表，对齐点大运营设置） -->
        <el-tab-pane label="基本信息" name="account">
          <table class="info-table">
            <thead>
              <tr><th style="width:110px">基本信息</th><th style="width:260px"></th><th>说明</th><th style="width:100px">操作</th></tr>
            </thead>
            <tbody>
              <tr>
                <td class="label">小程序名称</td>
                <td class="value">{{ account?.nickname || '-' }}</td>
                <td class="desc">小程序发布后，将不支持改名</td>
                <td class="act"><el-button link type="primary" @click="openEdit('nickname')">修改</el-button></td>
              </tr>
              <tr>
                <td class="label">小程序头像</td>
                <td class="value">
                  <el-image v-if="account?.headimg" :src="account.headimg" :preview-src-list="[account.headimg]" style="width:48px;height:48px;border-radius:6px" />
                  <span v-else>-</span>
                </td>
                <td class="desc">一个月内可申请修改12次<br />本月还可修改12次</td>
                <td class="act"><el-button link type="primary" @click="openEdit('headimg')">修改</el-button></td>
              </tr>
              <tr>
                <td class="label">介绍</td>
                <td class="value">{{ account?.signature || '-' }}</td>
                <td class="desc">一个月内可申请5次修改<br />本月还可修改5次</td>
                <td class="act"><el-button link type="primary" @click="openEdit('signature')">修改</el-button></td>
              </tr>
              <tr>
                <td class="label">微信认证</td>
                <td class="value" :class="account?.verified ? 'ok' : ''">
                  {{ account?.verified ? '已认证' : '未认证' }}<span v-if="account?.verified" class="verify-check">✓</span>
                </td>
                <td class="desc"></td>
                <td class="act"></td>
              </tr>
              <tr>
                <td class="label">主体信息</td>
                <td class="value">{{ account?.principal_name || '-' }}</td>
                <td class="desc">企业法人及个体工商户</td>
                <td class="act"></td>
              </tr>
              <tr>
                <td class="label">AppID</td>
                <td class="value">{{ account?.appid || '-' }}</td>
                <td class="desc"></td>
                <td class="act"></td>
              </tr>
              <tr>
                <td class="label">服务类目</td>
                <td class="value" v-if="categoriesText">{{ categoriesText }}</td>
                <td class="value" v-else>-</td>
                <td class="desc">一个月内可申请修改5次<br />本月还可修改5次</td>
                <td class="act"><el-button link type="primary" @click="goLeimu">详情</el-button></td>
              </tr>
              <tr>
                <td class="label">隐私设置</td>
                <td class="value">{{ (account?.search_status ?? 0) === 1 ? '允许被搜索' : '不允许被搜索' }}</td>
                <td class="desc">设置是否允许用户通过名称搜索到小程序帐号</td>
                <td class="act">
                  <el-button link type="primary" @click="handleSearchStatus((account?.search_status ?? 0) === 1 ? 0 : 1)">
                    {{ (account?.search_status ?? 0) === 1 ? '关闭' : '开启' }}
                  </el-button>
                </td>
              </tr>
              <tr>
                <td class="label">附近小程序</td>
                <td class="value"></td>
                <td class="desc">最大可添加地点10个<br />剩余可添加地点10个</td>
                <td class="act"><el-button link type="primary" @click="goNearby">添加</el-button></td>
              </tr>
              <tr>
                <td class="label">小程序直播</td>
                <td class="value">{{ liveEnabled ? '已开通' : '未开通' }}</td>
                <td class="desc"></td>
                <td class="act"></td>
              </tr>
            </tbody>
          </table>

          <!-- 修改弹窗 -->
          <el-dialog v-model="editVisible" :title="editTitle" width="480px" append-to-body destroy-on-close>
            <el-form label-width="100px">
              <el-form-item v-if="editType === 'nickname'" label="小程序名称">
                <el-input v-model="editVal" placeholder="2-30 个字符" maxlength="30" show-word-limit />
              </el-form-item>
              <el-form-item v-else-if="editType === 'signature'" label="功能介绍">
                <el-input v-model="editVal" type="textarea" :rows="3" maxlength="120" show-word-limit />
              </el-form-item>
              <el-form-item v-else label="头像">
                <el-input v-model="editVal" placeholder="图片 URL" />
                <el-upload
                  class="edit-upload"
                  :show-file-list="false"
                  :http-request="(opts: any) => uploadHeadimg(opts)"
                  accept="image/*"
                >
                  <el-button size="small">上传头像</el-button>
                </el-upload>
                <el-image v-if="editVal" :src="editVal" style="width:64px;height:64px;margin-top:8px;border-radius:6px" />
              </el-form-item>
            </el-form>
            <template #footer>
              <el-button @click="editVisible = false">取消</el-button>
              <el-button type="primary" :loading="editSaving" @click="submitEdit">确定</el-button>
            </template>
          </el-dialog>
        </el-tab-pane>

        <!-- 服务器域名 -->
        <el-tab-pane label="服务器域名" name="domain">
          <p class="page-tip">服务器域名快配：保存后自动提交微信平台，发布小程序后生效。多个域名用分号分隔，仅支持 https / wss。</p>
          <el-form label-width="140px" class="form-narrow">
            <el-form-item label="request 域名">
              <el-input v-model="serverDomain.requestdomain" placeholder="https://..." />
            </el-form-item>
            <el-form-item label="socket 域名">
              <el-input v-model="serverDomain.wsrequestdomain" placeholder="wss://..." />
            </el-form-item>
            <el-form-item label="uploadFile 域名">
              <el-input v-model="serverDomain.uploaddomain" placeholder="https://..." />
            </el-form-item>
            <el-form-item label="downloadFile 域名">
              <el-input v-model="serverDomain.downloaddomain" placeholder="https://..." />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="savingDomain" @click="handleSaveDomain">保存</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 业务域名 -->
        <el-tab-pane label="业务域名" name="jump">
          <p class="page-tip">业务域名用于 web-view 打开网页；保存前需将校验文件放到域名根目录。多个域名用分号分隔，仅支持 https。</p>
          <el-form label-width="140px" class="form-narrow">
            <el-form-item label="业务域名">
              <el-input v-model="webviewdomain" type="textarea" :rows="3" placeholder="https://..." />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="savingJump" @click="handleSaveJump">保存</el-button>
              <el-button @click="handleDomainFile">下载校验文件</el-button>
            </el-form-item>
          </el-form>
          <div v-if="domainFile" class="domain-file-box">
            <p>校验文件：<a :href="domainFile.url" target="_blank">{{ domainFile.file_name }}</a></p>
            <p class="page-tip">将该文件放置到域名根目录可访问后，再保存业务域名。</p>
          </div>
        </el-tab-pane>

        <!-- 隐私指引 -->
        <el-tab-pane label="隐私指引" name="privacy">
          <p class="page-tip">勾选本小程序涉及的隐私接口并填写用途说明，保存后随小程序发布生效。</p>
          <el-form label-width="140px" class="form-narrow">
            <el-form-item v-for="item in allKeys" :key="item.privacy_key" :label="item.privacy_desc">
              <div class="privacy-row">
                <el-checkbox
                  :model-value="selectedKeys.includes(item.privacy_key)"
                  @change="(v: boolean | string | number) => {
                    if (v) selectedKeys.push(item.privacy_key)
                    else selectedKeys = selectedKeys.filter((k) => k !== item.privacy_key)
                  }"
                >使用</el-checkbox>
                <el-input
                  v-if="selectedKeys.includes(item.privacy_key)"
                  v-model="purposeMap[item.privacy_key]"
                  placeholder="用途说明，如：用于展示会员头像"
                  style="width: 320px"
                />
              </div>
            </el-form-item>
            <el-form-item label="联系 QQ">
              <el-input v-model="ownerSetting.contact_qq" />
            </el-form-item>
            <el-form-item label="联系邮箱">
              <el-input v-model="ownerSetting.contact_email" />
            </el-form-item>
            <el-form-item label="联系电话">
              <el-input v-model="ownerSetting.contact_phone" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="savingPrivacy" @click="handleSavePrivacy">保存</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- ICP 备案 -->
        <el-tab-pane label="ICP 备案" name="icp">
          <div class="icp-status-row">
            <span>备案状态：<el-tag size="small">{{ String(icp.beian_status || '未提交') }}</el-tag></span>
            <span>人脸核身：<el-tag size="small" :type="Number(icp.face_status) === 3 ? 'success' : 'info'">{{ faceStatusMap[Number(icp.face_status)] || '未核身' }}</el-tag></span>
          </div>
          <el-form label-width="140px" class="form-narrow">
            <el-form-item label="主体名称">
              <el-input v-model="icpForm.icp_subject" placeholder="企业/个人名称" />
            </el-form-item>
            <el-form-item label="主体性质">
              <el-select v-model="icpForm.subject_type" placeholder="请选择" style="width: 220px">
                <el-option label="企业" value="4" />
                <el-option label="个人" value="1" />
                <el-option label="个体工商户" value="27" />
              </el-select>
            </el-form-item>
            <el-form-item label="法人/负责人">
              <el-input v-model="icpForm.principal_name" />
            </el-form-item>
            <el-form-item label="证件类型">
              <el-select v-model="icpForm.principal_credentials_type" style="width: 220px">
                <el-option label="居民身份证" value="1" />
                <el-option label="护照" value="2" />
              </el-select>
            </el-form-item>
            <el-form-item label="证件号码">
              <el-input v-model="icpForm.principal_credentials_number" />
            </el-form-item>
            <el-form-item label="负责人手机">
              <el-input v-model="icpForm.principal_mobile" />
            </el-form-item>
            <el-form-item label="负责人邮箱">
              <el-input v-model="icpForm.principal_email" />
            </el-form-item>
            <el-form-item label="应急联系人">
              <el-input v-model="icpForm.emergency_contact" />
            </el-form-item>
            <el-form-item label="应急联系电话">
              <el-input v-model="icpForm.emergency_contact_mobile" />
            </el-form-item>
            <el-form-item>
              <el-button :loading="savingIcp" @click="handleSaveIcp">保存资料</el-button>
              <el-button @click="handleFaceCheck">人脸核身</el-button>
              <el-button type="primary" @click="handleSubmitIcp">提交备案</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<style scoped>
.card-title {
  font-weight: 600;
}

.page-tip {
  margin: 0 0 14px;
  color: #909399;
  font-size: 13px;
}

.form-narrow {
  max-width: 680px;
}

.form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}

.readonly-text {
  color: #606266;
}

.domain-file-box {
  margin-top: 8px;
  padding: 10px 14px;
  background: #f7f8fa;
  border-radius: 4px;
}

.privacy-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.icp-status-row {
  display: flex;
  gap: 28px;
  margin-bottom: 16px;
  font-size: 13px;
  color: #606266;
}

/* 基本信息表 */
.info-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.info-table th {
  text-align: left;
  padding: 12px 10px;
  background: #f7f8fa;
  color: #909399;
  font-weight: 500;
  border-bottom: 1px solid #ebeef5;
}
.info-table td {
  padding: 14px 10px;
  border-bottom: 1px solid #f0f2f5;
  vertical-align: middle;
  color: #303133;
  line-height: 1.6;
}
.info-table td.label {
  color: #606266;
}
.info-table td.value {
  white-space: pre-line;
  word-break: break-all;
}
.info-table td.desc {
  color: #909399;
  font-size: 12px;
}
.info-table td.value.ok {
  color: #2ecc71;
  font-weight: 500;
}
.verify-check {
  color: #2ecc71;
  margin-left: 2px;
}
.edit-upload {
  margin-left: 8px;
}
</style>
