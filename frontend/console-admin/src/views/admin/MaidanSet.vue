<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { mdGetSet, mdSaveSet, mdUploadJstxt } from '../../api/maidan'
import LinkPicker from '../../components/LinkPicker.vue'

const loading = ref(false)
const saving = ref(false)
const uploading = ref(false)

const form = reactive({
  maidan_login: 1,
  maidan_getlocation: 0,
  maidan_payaftertourl: '',
  maidan_score_discount: 1,
})

const h5Url = ref('')
const mpPath = ref('pagesB/maidan/pay')
const showWx = ref(true)
const showAlipay = ref(true)

const wxRuleUrl = 'https://developers.weixin.qq.com/miniprogram/introduction/qrcode.html'
const alipayRuleUrl = 'https://opendocs.alipay.com/b/04ne6i'

const qrSrc = computed(() => {
  if (!h5Url.value) return ''
  return `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(h5Url.value)}`
})

// 查看小程序码弹窗
const mpCodeVisible = ref(false)
const mpCodeSrc = computed(() => {
  if (!mpPath.value) return ''
  return `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(mpPath.value)}`
})

async function loadData() {
  loading.value = true
  try {
    const result = await mdGetSet()
    form.maidan_login = Number(result.maidan_login) || 0
    form.maidan_getlocation = Number(result.maidan_getlocation) || 0
    form.maidan_payaftertourl = result.maidan_payaftertourl || ''
    form.maidan_score_discount = Number(result.maidan_score_discount) ?? 1
    h5Url.value = result.h5_url || ''
    mpPath.value = result.mp_path || 'pagesB/maidan/pay'
    showWx.value = result.show_wx !== false
    showAlipay.value = result.show_alipay !== false
  } catch {
    // 错误提示由拦截器统一处理
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await mdSaveSet({
      maidan_login: form.maidan_login,
      maidan_getlocation: form.maidan_getlocation,
      maidan_payaftertourl: form.maidan_payaftertourl,
      maidan_score_discount: form.maidan_score_discount,
    })
    ElMessage.success('设置成功')
  } catch {
    // 错误提示由拦截器统一处理
  } finally {
    saving.value = false
  }
}

function copyText(text: string) {
  if (!text) return
  navigator.clipboard?.writeText(text).then(() => {
    ElMessage.success('已复制')
  }).catch(() => {
    ElMessage.warning('复制失败，请手动复制')
  })
}

// 上传域名校验文件
const fileInputRef = ref<HTMLInputElement>()
function triggerUpload() {
  fileInputRef.value?.click()
}
async function handleFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploading.value = true
  try {
    await mdUploadJstxt(file)
    ElMessage.success('上传成功')
  } catch {
    // 拦截器统一提示
  } finally {
    uploading.value = false
    input.value = ''
  }
}

onMounted(() => {
  loadData()
})
</script>

<template>
  <div class="maidan-set-page" v-loading="loading">
    <!-- ========== 区块A：二维码设置 ========== -->
    <el-card shadow="never" class="block-card">
      <template #header>
        <span class="block-title">二维码设置</span>
      </template>

      <div class="qr-set-body">
        <!-- 微信设置 -->
        <div v-if="showWx" class="set-row">
          <div class="set-label">微信设置：</div>
          <div class="set-content">
            <p class="guide-text">
              开发管理-&gt;开发设置-&gt;扫普通链接二维码打开小程序
              <a :href="wxRuleUrl" target="_blank" class="rule-link">点击查看配置规则</a>
            </p>
            <p class="kv-row">协议类型：<span class="kv-val">https</span></p>
            <p class="kv-row">选择大小写：<span class="kv-val">小写</span></p>
            <p class="kv-row">
              二维码规则：<span class="kv-val">{{ h5Url }}</span>
              <el-button size="small" class="copy-btn" @click="copyText(h5Url)">复制</el-button>
            </p>
            <p class="kv-row">
              前缀占用规则：<span class="kv-val">开启多商户使用选择"不占用"</span> 平台开启此功能，多商户的收款码即支持聚合码
            </p>
            <p class="kv-row">
              小程序功能页面：<span class="kv-val">{{ mpPath }}</span>
              <el-button size="small" class="copy-btn" @click="copyText(mpPath)">复制</el-button>
            </p>
          </div>
        </div>

        <!-- 支付宝设置 -->
        <div v-if="showAlipay" class="set-row">
          <div class="set-label">支付宝设置：</div>
          <div class="set-content">
            <p class="guide-text">
              选择应用-&gt;审核&amp;发布-&gt;小程序码-&gt;关联普通链接二维码
              <a :href="alipayRuleUrl" target="_blank" class="rule-link">点击查看配置规则</a>
            </p>
            <p class="kv-row">
              关联模式：<span class="kv-val">开启多商户使用选择"模糊匹配"</span> 平台开启此功能，多商户的收款码即支持聚合码
            </p>
            <p class="kv-row">
              二维码地址：<span class="kv-val">{{ h5Url }}</span>
              <el-button size="small" class="copy-btn" @click="copyText(h5Url)">复制</el-button>
            </p>
            <p class="kv-row">
              小程序功能页：<span class="kv-val">{{ mpPath }}</span>
              <el-button size="small" class="copy-btn" @click="copyText(mpPath)">复制</el-button>
            </p>
          </div>
        </div>

        <!-- 二维码 -->
        <div class="set-row">
          <div class="set-label">二维码：</div>
          <div class="set-content">
            <div class="qr-wrap" v-if="qrSrc">
              <img :src="qrSrc" alt="聚合收款码" class="qr-img" />
            </div>
            <el-empty v-else description="无买单页链接" :image-size="60" />
            <div class="qr-actions">
              <el-button size="small" @click="mpCodeVisible = true">查看小程序码</el-button>
            </div>
          </div>
        </div>

        <!-- 链接地址 -->
        <div class="set-row">
          <div class="set-label">链接地址：</div>
          <div class="set-content">
            <span class="kv-val">{{ h5Url }}</span>
            <el-button size="small" class="copy-btn" @click="copyText(h5Url)">复制</el-button>
          </div>
        </div>

        <!-- 页面路径 -->
        <div class="set-row">
          <div class="set-label">页面路径：</div>
          <div class="set-content">
            <span class="kv-val">{{ mpPath }}</span>
            <el-button size="small" class="copy-btn" @click="copyText(mpPath)">复制</el-button>
          </div>
        </div>
      </div>
    </el-card>

    <!-- ========== 区块B：基础设置 ========== -->
    <el-card shadow="never" class="block-card">
      <template #header>
        <span class="block-title">基础设置</span>
      </template>

      <div class="base-set-body">
        <el-form label-width="130px" label-position="right">
          <el-form-item label="收款强制登录：">
            <el-radio-group v-model="form.maidan_login">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <div class="form-aux">选择开启：扫码进入买单页面后跳转登录页，会员登录后才能买单付款</div>
          </el-form-item>

          <el-form-item label="支付后跳转：">
            <div class="tourl-row">
              <LinkPicker v-model="form.maidan_payaftertourl" placeholder="买单支付成功后跳转的页面地址" pick-text="选择链接" />
            </div>
          </el-form-item>

          <el-form-item label="开启定位：">
            <el-radio-group v-model="form.maidan_getlocation">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="会员等级折扣：">
            <el-radio-group v-model="form.maidan_score_discount">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <div class="form-aux">是否开启会员等级折扣</div>
          </el-form-item>

          <el-form-item>
            <el-button type="success" :loading="saving" @click="handleSave">保 存</el-button>
            <el-button :loading="uploading" @click="triggerUpload">上传域名校验文件</el-button>
            <input ref="fileInputRef" type="file" accept=".txt,.html" style="display:none" @change="handleFileChange" />
          </el-form-item>
        </el-form>
      </div>
    </el-card>

    <!-- 查看小程序码弹窗 -->
    <el-dialog v-model="mpCodeVisible" title="小程序码" width="280px" align-center>
      <div class="mp-code-wrap">
        <img v-if="mpCodeSrc" :src="mpCodeSrc" alt="小程序码" class="mp-code-img" />
        <p v-else class="mp-code-empty">暂无小程序码</p>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.maidan-set-page {
  padding: 8px;
}
.block-card {
  margin-bottom: 8px;
}
.block-card :deep(.el-card__body) {
  padding: 12px 16px;
}
.block-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.qr-set-body {
  max-width: 860px;
}
.set-row {
  display: flex;
  margin-bottom: 14px;
  line-height: 1.8;
}
.set-label {
  width: 130px;
  flex-shrink: 0;
  text-align: right;
  padding-right: 12px;
  font-size: 13px;
  color: #606266;
}
.set-content {
  flex: 1;
  font-size: 13px;
  color: #303133;
}
.guide-text {
  margin: 0 0 4px;
}
.rule-link {
  color: #409eff;
  margin-left: 4px;
  text-decoration: none;
}
.kv-row {
  margin: 2px 0;
}
.kv-val {
  color: #409eff;
  word-break: break-all;
}
.copy-btn {
  margin-left: 6px;
  vertical-align: middle;
}
.qr-wrap {
  display: inline-block;
  padding: 8px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}
.qr-img {
  width: 200px;
  height: 200px;
  display: block;
}
.qr-actions {
  margin-top: 8px;
  text-align: center;
}
.base-set-body {
  max-width: 720px;
}
.tourl-row {
  width: 380px;
}
.form-aux {
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
  margin-top: 2px;
}
.mp-code-wrap {
  text-align: center;
  padding: 10px 0;
}
.mp-code-img {
  width: 200px;
  height: 200px;
}
.mp-code-empty {
  color: #909399;
  font-size: 13px;
}
</style>
