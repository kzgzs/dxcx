<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Close, Setting } from '@element-plus/icons-vue'
import type { UploadRequestOptions } from 'element-plus'
import { getSystemConfig, saveSystemConfig, uploadWxpayCert, revealSecret } from '../api/system'

// 服务商配置页（对照参考系统 WebSystem/wxpayset + sxpayset 双视图）
// 默认微信视图；右上按钮切随行付视图；随行付视图右上 X 切回微信视图

/** 后端敏感字段脱敏掩码：值等于掩码时显示“已隐藏内容，点击查看或编辑”遮罩 */
const MASK = '******'

const loading = ref(false)
const wxSaving = ref(false)
const sxSaving = ref(false)
const certUploading = ref(false)
const keyUploading = ref(false)

// 双视图：wx=微信支付服务商配置（默认）；sx=随行付服务商配置
const view = ref<'wx' | 'sx'>('wx')

// 微信支付服务商配置（字段口径对照参考系统 wxpayset）
const wx = reactive({
  mchname: '',
  appid: '',
  mchid: '',
  mchkey: '',
  apiclient_cert: '',
  apiclient_key: '',
  chouchengset: '0',
  chouchengrate: '',
  chouchengmin: '',
  chouchengmoney: '',
})

// 随行付服务商配置（字段口径对照参考系统 sxpayset）
const sx = reactive({
  mchname: '',
  orgId: '',
  orgIdOne: '',
  publicKey: '',
  privateKey: '',
  signType: 'RSA',
  feepercent: '',
})

const forms: Record<'wx' | 'sx', Record<string, string>> = { wx, sx }

async function fetchConfig() {
  loading.value = true
  try {
    const [wxData, sxData] = await Promise.all([getSystemConfig('wxpay'), getSystemConfig('sxpay')])
    Object.assign(wx, {
      mchname: wxData.mchname || '',
      appid: wxData.appid || '',
      mchid: wxData.mchid || '',
      mchkey: wxData.mchkey || '',
      apiclient_cert: wxData.apiclient_cert || '',
      apiclient_key: wxData.apiclient_key || '',
      chouchengset: wxData.chouchengset || '0',
      chouchengrate: wxData.chouchengrate || '',
      chouchengmin: wxData.chouchengmin || '',
      chouchengmoney: wxData.chouchengmoney || '',
    })
    Object.assign(sx, {
      mchname: sxData.mchname || '',
      orgId: sxData.orgId || '',
      orgIdOne: sxData.orgIdOne || '',
      publicKey: sxData.publicKey || '',
      privateKey: sxData.privateKey || '',
      signType: sxData.signType || 'RSA',
      feepercent: sxData.feepercent || '',
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

/** 密钥类字段：点击遮罩拉取原文，变为可编辑输入框 */
async function reveal(group: 'wx' | 'sx', field: string) {
  try {
    const data = await revealSecret(group === 'wx' ? 'wxpay' : 'sxpay', field)
    forms[group][field] = data.value || ''
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

/** 证书上传：真实上传 pem 到服务器，返回路径写入表单并显示“已上传” */
async function doCertUpload(options: UploadRequestOptions, field: 'apiclient_cert' | 'apiclient_key', uploading: { value: boolean }) {
  uploading.value = true
  try {
    const data = await uploadWxpayCert(options.file)
    wx[field] = data.url
    ElMessage.success('上传成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    uploading.value = false
  }
}

function handleCertUpload(options: UploadRequestOptions) {
  return doCertUpload(options, 'apiclient_cert', certUploading)
}

function handleKeyUpload(options: UploadRequestOptions) {
  return doCertUpload(options, 'apiclient_key', keyUploading)
}

// 当页保存：成功后原地提示并重新拉取（密钥恢复隐藏态），不跳转
async function saveWx() {
  wxSaving.value = true
  try {
    await saveSystemConfig('wxpay', { ...wx })
    ElMessage.success('设置成功')
    await fetchConfig()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    wxSaving.value = false
  }
}

async function saveSx() {
  sxSaving.value = true
  try {
    await saveSystemConfig('sxpay', { ...sx })
    ElMessage.success('设置成功')
    await fetchConfig()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    sxSaving.value = false
  }
}

onMounted(fetchConfig)
</script>

<template>
  <div v-loading="loading" class="wxpayset-page">
    <!-- ============ 视图一：微信支付服务商配置 ============ -->
    <template v-if="view === 'wx'">
      <div class="page-header">
        <div class="header-left">
          <el-icon class="header-icon"><Setting /></el-icon>
          <span>微信支付服务商配置</span>
        </div>
        <el-button size="small" @click="view = 'sx'">随行付服务商</el-button>
      </div>

      <div class="page-body">
        <div class="config-guide">本配置适用于服务商模式，通过微信支付商户号完成收款；若未开通微信支付服务商能力，可留空不填</div>

        <el-form label-width="130px" label-position="right">
          <el-form-item label="服务商名称：">
            <el-input v-model="wx.mchname" class="w200" clearable />
            <span class="form-help">填写服务商对应的商户名称，可在微信支付服务商后台的[商户中心]-[商户信息]页面查看</span>
          </el-form-item>

          <el-form-item label="服务商AppID：">
            <el-input v-model="wx.appid" class="w200" clearable />
            <span class="form-help">即服务商公众号的开发者ID，可在微信公众平台[设置与开发]-[账号设置]-[注册信息]中获取</span>
          </el-form-item>

          <el-form-item label="支付商户号：">
            <el-input v-model="wx.mchid" class="w200" clearable />
            <span class="form-help">填写服务商对应的微信支付商户号(MCHID)</span>
          </el-form-item>

          <el-form-item label="支付密钥：">
            <div class="secret-wrap w280">
              <el-input v-model="wx.mchkey" />
              <div v-if="wx.mchkey === MASK" class="secret-cover" @click="reveal('wx', 'mchkey')">
                已隐藏内容，点击查看或编辑
              </div>
            </div>
            <span class="form-help">
              登录 <a href="https://pay.weixin.qq.com" target="_blank">微信支付商户平台</a>
              ，在[账户中心]-[API安全]页面配置[APIv2密钥]后填入此处
            </span>
          </el-form-item>

          <el-form-item label="PEM证书：">
            <el-input v-model="wx.apiclient_cert" class="w450" readonly />
            <el-upload :show-file-list="false" :http-request="handleCertUpload" accept=".pem">
              <el-button :loading="certUploading">上传</el-button>
            </el-upload>
            <span v-if="wx.apiclient_cert" class="uploaded">已上传</span>
            <span class="form-help">
              请上传 apiclient_cert.pem 证书文件，证书可在 <a href="https://pay.weixin.qq.com" target="_blank">微信支付商户平台</a>
              [账户中心]-[API安全]的[API证书]栏目中申请
            </span>
          </el-form-item>

          <el-form-item label="证书密钥：">
            <el-input v-model="wx.apiclient_key" class="w450" readonly />
            <el-upload :show-file-list="false" :http-request="handleKeyUpload" accept=".pem">
              <el-button :loading="keyUploading">上传</el-button>
            </el-upload>
            <span v-if="wx.apiclient_key" class="uploaded">已上传</span>
            <span class="form-help">
              请上传 apiclient_key.pem 证书密钥文件，同样在 <a href="https://pay.weixin.qq.com" target="_blank">微信支付商户平台</a>
              [账户中心]-[API安全]的[API证书]栏目中申请
            </span>
          </el-form-item>

          <el-form-item label="默认抽成：">
            <el-radio-group v-model="wx.chouchengset">
              <el-radio value="0">不抽成</el-radio>
              <el-radio value="1">按百分百抽成</el-radio>
              <el-radio value="2">固定抽成</el-radio>
            </el-radio-group>
            <span class="form-help red">
              启用抽成前需先开通服务商分账能力并配置好计划任务；每次分账的金额必须小于：（订单金额-手续费）*允许分账的最大比例
            </span>
          </el-form-item>

          <el-form-item v-if="wx.chouchengset === '1'" label=" ">
            <el-input v-model="wx.chouchengrate" class="w160">
              <template #prepend>抽成比例(%)</template>
            </el-input>
            <el-input v-model="wx.chouchengmin" class="w200 ml12">
              <template #prepend>最低抽成金额(元)</template>
            </el-input>
          </el-form-item>

          <el-form-item v-if="wx.chouchengset === '2'" label=" ">
            <el-input v-model="wx.chouchengmoney" class="w160">
              <template #prepend>抽成金额(元)</template>
            </el-input>
          </el-form-item>

          <el-form-item label=" ">
            <el-button class="submit-btn" :loading="wxSaving" @click="saveWx">提 交</el-button>
          </el-form-item>
        </el-form>
      </div>
    </template>

    <!-- ============ 视图二：随行付服务商配置 ============ -->
    <template v-else>
      <div class="page-header">
        <div class="header-left">
          <el-icon class="header-icon"><Setting /></el-icon>
          <span>随行付服务商配置</span>
        </div>
        <el-icon class="close-btn" title="关闭" @click="view = 'wx'"><Close /></el-icon>
      </div>

      <div class="page-body">
        <div class="config-guide">此页面仅面向随行付服务商，用于商户进件与收款接入；未接入随行付服务商的可忽略本页</div>

        <el-form label-width="130px" label-position="right">
          <el-form-item label="服务商名称：">
            <el-input v-model="sx.mchname" class="w200" clearable />
          </el-form-item>

          <el-form-item label="机构号：">
            <el-input v-model="sx.orgId" class="w200" clearable />
          </el-form-item>

          <el-form-item label="一级机构号：">
            <el-input v-model="sx.orgIdOne" class="w200" clearable />
            <span class="form-help">非必填项，仅在后台发起分账申请时用到</span>
          </el-form-item>

          <el-form-item label="公钥：">
            <div class="secret-wrap w400">
              <el-input v-model="sx.publicKey" />
              <div v-if="sx.publicKey === MASK" class="secret-cover" @click="reveal('sx', 'publicKey')">
                已隐藏内容，点击查看或编辑
              </div>
            </div>
            <span class="form-help">粘贴公钥内容，去掉首尾标记与换行，保持为单行字符串</span>
          </el-form-item>

          <el-form-item label="私钥：">
            <div class="secret-wrap w400">
              <el-input v-model="sx.privateKey" />
              <div v-if="sx.privateKey === MASK" class="secret-cover" @click="reveal('sx', 'privateKey')">
                已隐藏内容，点击查看或编辑
              </div>
            </div>
            <span class="form-help">粘贴私钥内容，去掉首尾标记与换行，保持为单行字符串</span>
          </el-form-item>

          <el-form-item label="密钥类型：">
            <el-radio-group v-model="sx.signType">
              <el-radio value="RSA">RSA</el-radio>
              <el-radio value="RSA2">RSA2</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="进件费率：">
            <el-input v-model="sx.feepercent" class="w200" clearable />
            <span class="suffix">%</span>
          </el-form-item>

          <el-form-item label=" ">
            <el-button class="submit-btn" :loading="sxSaving" @click="saveSx">提 交</el-button>
          </el-form-item>
        </el-form>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* 页头：标题 + 右侧操作（对照参考系统 layui-card-header） */
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  padding: 12px 16px;
  font-size: 14px;
  color: #333;
  border-bottom: 1px solid #e6e6e6;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

/* 右上操作按钮：不压缩不挤出，窄屏时随 header 换行仍完整可见 */
.page-header :deep(.el-button) {
  flex: none;
  margin-left: auto;
}

.header-icon {
  font-size: 15px;
}

.close-btn {
  font-size: 18px;
  font-weight: bold;
  cursor: pointer;
  color: #333;
}

.close-btn:hover {
  color: #000;
}

.page-body {
  padding: 16px;
}

/* 说明块：灰底 + 左绿竖条（对照参考系统 layui-elem-quote） */
.config-guide {
  margin-bottom: 24px;
  padding: 12px 15px;
  background-color: #f6f6f6;
  border-left: 4px solid #28a745;
  font-size: 13px;
  color: #555;
}

/* 行内说明：输入框右侧同行灰色小字 */
.form-help {
  margin-left: 10px;
  font-size: 12px;
  line-height: 1.6;
  color: #909399;
}

.form-help a {
  color: #409eff;
}

.form-help.red {
  color: red;
}

/* 窄输入框宽度（对照参考系统 layui-input-inline 各档；窄屏允许收缩避免擑宽页面） */
.w160 { width: 160px; max-width: 100%; flex: 0 1 auto; }
.w200 { width: 200px; max-width: 100%; flex: 0 1 auto; }
.w280 { width: 280px; max-width: 100%; flex: 0 1 auto; }
.w400 { width: 400px; max-width: 100%; flex: 0 1 auto; }
.w450 { width: 450px; max-width: 100%; flex: 0 1 auto; }

.ml12 { margin-left: 12px; }

/* 密钥隐藏遮罩（对照参考系统：白底盖住输入框，点击后查看或编辑） */
.secret-wrap {
  position: relative;
  max-width: 100%;
}

.secret-cover {
  position: absolute;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  background: #fff;
  border: 1px solid rgba(0, 0, 0, 0.15);
  border-radius: 4px;
  color: #636c72;
  padding: 0 10px;
  display: flex;
  align-items: center;
  cursor: pointer;
  font-size: 13px;
}

/* 证书“已上传”状态 */
.uploaded {
  margin-left: 20px;
  font-size: 13px;
  color: #333;
}

/* 进件费率后缀 % */
.suffix {
  margin-left: 10px;
  font-size: 13px;
  color: #333;
}

/* 提交按钮：参考系统绿色 */
.submit-btn {
  background-color: #009688;
  border-color: #009688;
  color: #fff;
}

.submit-btn:hover,
.submit-btn:focus {
  background-color: #00897b;
  border-color: #00897b;
  color: #fff;
}
</style>
