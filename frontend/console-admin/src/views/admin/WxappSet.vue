<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
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
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
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
  Promise.all([loadAccount(), loadServerDomain(), loadJumpDomain(), loadPrivacy(), loadIcp()]).finally(() => {
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
        <!-- 账号资料 -->
        <el-tab-pane label="账号资料" name="account">
          <el-form label-width="140px" class="form-narrow">
            <el-form-item label="AppID">
              <span class="readonly-text">{{ account?.appid || '-' }}</span>
            </el-form-item>
            <el-form-item label="小程序名称">
              <el-input v-model="nickname" placeholder="2-30 个字符" maxlength="30" show-word-limit />
            </el-form-item>
            <el-form-item label="头像地址">
              <el-input v-model="headimg" placeholder="图片 URL" />
            </el-form-item>
            <el-form-item label="功能简介">
              <el-input v-model="signature" type="textarea" :rows="3" maxlength="120" show-word-limit />
            </el-form-item>
            <el-form-item label="被搜索">
              <el-switch
                :model-value="(account?.search_status ?? 0) === 1"
                @change="(v: boolean | string | number) => handleSearchStatus(v ? 1 : 0)"
              />
              <span class="form-tip">开启后用户可在微信搜索到本小程序</span>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="savingAccount" @click="handleSaveAccount">保存</el-button>
            </el-form-item>
          </el-form>
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
</style>
