<script setup lang="ts">
import { onMounted, onUnmounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  getRegisterConfig,
  sendRegisterSms,
  submitRegister,
} from '../api/register'
import { getCaptcha } from '../api/captcha'

const router = useRouter()

const formRef = ref<FormInstance>()
// 页面状态：loading 加载中 / open 可注册 / closed 暂未开放 / error 配置读取失败
const pageState = ref<'loading' | 'open' | 'closed' | 'error'>('loading')
const regConfig = reactive({
  reg_open: 0,
  sms_verify: 0,
  reg_agreement_url: '',
})

const form = reactive({
  mobile: '',
  password: '',
  password2: '',
  code: '',
  captcha: '',
  agreement: false,
})

const rules: FormRules = {
  mobile: [
    { required: true, message: '请输入手机号', trigger: 'blur' },
    { pattern: /^1\d{10}$/, message: '手机号格式不正确', trigger: 'blur' },
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, max: 32, message: '密码长度需为 6-32 位', trigger: 'blur' },
  ],
  password2: [
    { required: true, message: '请再次输入密码', trigger: 'blur' },
    {
      validator: (_rule: unknown, value: string, callback: (e?: Error) => void) => {
        if (value !== form.password) callback(new Error('两次输入的密码不一致'))
        else callback()
      },
      trigger: 'blur',
    },
  ],
  code: [{ required: true, message: '请输入短信验证码', trigger: 'blur' }],
  captcha: [{ required: true, message: '请输入图形验证码', trigger: 'blur' }],
}

// ============ 图形验证码（点击刷新，失败后自动刷新） ============
const captchaId = ref('')
const captchaImg = ref('')

async function loadCaptcha() {
  try {
    const data = await getCaptcha()
    captchaId.value = data.captcha_id
    captchaImg.value = data.image
  } catch {
    // 验证码获取失败不阻断页面，用户可点击重试
  }
}

// ============ 获取验证码（60s 倒计时） ============
const smsLoading = ref(false)
const smsCountdown = ref(0)
let smsTimer: ReturnType<typeof setInterval> | null = null

function stopSmsTimer() {
  if (smsTimer) {
    clearInterval(smsTimer)
    smsTimer = null
  }
}

onUnmounted(stopSmsTimer)

async function handleSendSms() {
  if (smsCountdown.value > 0 || smsLoading.value) return
  if (!/^1\d{10}$/.test(form.mobile)) {
    ElMessage.warning('请先输入正确的手机号')
    return
  }
  smsLoading.value = true
  try {
    await sendRegisterSms(form.mobile)
    ElMessage.success('验证码已发送，请注意查收短信')
    smsCountdown.value = 60
    smsTimer = setInterval(() => {
      smsCountdown.value -= 1
      if (smsCountdown.value <= 0) stopSmsTimer()
    }, 1000)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    smsLoading.value = false
  }
}

// ============ 提交注册 ============
const submitLoading = ref(false)

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if (!form.agreement) {
      ElMessage.warning('请先阅读并同意注册协议')
      return
    }
    submitLoading.value = true
    try {
      await submitRegister({
        mobile: form.mobile,
        password: form.password,
        code: form.code,
        agreement: form.agreement,
        captcha_id: captchaId.value,
        captcha: form.captcha,
      })
      ElMessage.success('注册成功，请登录')
      router.push('/login')
    } catch {
      // 错误提示已由 axios 拦截器统一弹出；注册失败自动刷新验证码
      form.captcha = ''
      loadCaptcha()
    } finally {
      submitLoading.value = false
    }
  })
}

function goLogin() {
  router.push('/login')
}

// ============ 页面加载：读取注册开关等公开配置 ============
onMounted(async () => {
  try {
    const data = await getRegisterConfig()
    regConfig.reg_open = data.reg_open
    regConfig.sms_verify = data.sms_verify
    regConfig.reg_agreement_url = data.reg_agreement_url
    pageState.value = data.reg_open === 1 ? 'open' : 'closed'
    if (pageState.value === 'open') loadCaptcha()
  } catch {
    pageState.value = 'error'
  }
})
</script>

<template>
  <div class="login-page">
    <el-card v-if="pageState === 'loading'" class="login-card" shadow="always">
      <div class="state-tip" v-loading="true" element-loading-text="加载中…">
        <div class="state-tip-text">正在加载注册页</div>
      </div>
    </el-card>

    <el-card v-else-if="pageState !== 'open'" class="login-card" shadow="always">
      <template #header>
        <div class="login-title">鼎极多端</div>
        <div class="login-slogan">不用写代码，小程序、公众号、H5、APP 一站端出</div>
      </template>
      <div class="state-tip">
        <div class="state-tip-text">
          {{ pageState === 'closed' ? '暂未开放注册' : '注册服务暂不可用，请稍后再试' }}
        </div>
        <el-button type="primary" class="login-btn" @click="goLogin">去登录</el-button>
      </div>
    </el-card>

    <el-card v-else class="login-card" shadow="always">
      <template #header>
        <div class="login-title">会员注册</div>
        <div class="login-slogan">填写以下信息，即刻开启鼎极多端</div>
      </template>
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="0"
        size="large"
        @keyup.enter="handleSubmit"
      >
        <el-form-item prop="mobile">
          <el-input
            v-model="form.mobile"
            placeholder="请输入手机号"
            maxlength="11"
            autocomplete="username"
          />
        </el-form-item>
        <el-form-item prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="请输入密码（6-32 位）"
            show-password
            autocomplete="new-password"
          />
        </el-form-item>
        <el-form-item prop="password2">
          <el-input
            v-model="form.password2"
            type="password"
            placeholder="请再次输入密码"
            show-password
            autocomplete="new-password"
          />
        </el-form-item>
        <el-form-item v-if="regConfig.sms_verify === 1" prop="code">
          <div class="code-row">
            <el-input
              v-model="form.code"
              placeholder="请输入短信验证码"
              maxlength="6"
              class="code-input"
            />
            <el-button
              class="code-btn"
              :loading="smsLoading"
              :disabled="smsCountdown > 0"
              @click="handleSendSms"
            >
              {{ smsCountdown > 0 ? `${smsCountdown}s 后重试` : '获取验证码' }}
            </el-button>
          </div>
        </el-form-item>
        <el-form-item prop="captcha">
          <div class="captcha-row">
            <el-input
              v-model="form.captcha"
              placeholder="请输入图形验证码"
              maxlength="4"
              class="captcha-input"
              autocomplete="off"
            />
            <img
              v-if="captchaImg"
              :src="captchaImg"
              class="captcha-img"
              title="点击刷新验证码"
              alt="图形验证码"
              @click="loadCaptcha"
            />
            <el-button v-else class="captcha-img captcha-btn-fallback" @click="loadCaptcha">刷新</el-button>
          </div>
        </el-form-item>
        <el-form-item v-if="regConfig.reg_agreement_url" class="agreement-item">
          <el-checkbox v-model="form.agreement">
            我已阅读并同意
            <a
              :href="regConfig.reg_agreement_url"
              target="_blank"
              class="agreement-link"
              @click.stop
            >《注册协议》</a>
          </el-checkbox>
        </el-form-item>
        <el-form-item v-else class="agreement-item">
          <el-checkbox v-model="form.agreement">我已阅读并同意注册协议</el-checkbox>
        </el-form-item>
        <el-form-item>
          <el-button
            type="primary"
            class="login-btn"
            :loading="submitLoading"
            @click="handleSubmit"
          >
            注 册
          </el-button>
        </el-form-item>
        <div class="to-login">
          已有账号？<el-link type="primary" @click="goLogin">去登录</el-link>
        </div>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.login-page {
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #1f3b73 0%, #2d5aa0 100%);
}

.login-card {
  width: 380px;
}

.login-title {
  text-align: center;
  font-size: 22px;
  font-weight: 600;
  color: #303133;
  letter-spacing: 2px;
}

.login-slogan {
  margin-top: 6px;
  text-align: center;
  font-size: 12px;
  color: #909399;
}

.login-btn {
  width: 100%;
}

.state-tip {
  padding: 24px 0 12px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 20px;
}

.state-tip-text {
  color: #606266;
  font-size: 14px;
}

.code-row {
  display: flex;
  width: 100%;
  gap: 10px;
}

.code-input {
  flex: 1;
}

.code-btn {
  width: 128px;
  flex-shrink: 0;
}

.captcha-row {
  display: flex;
  width: 100%;
  gap: 10px;
}

.captcha-input {
  flex: 1;
}

.captcha-img {
  width: 110px;
  height: 40px;
  flex-shrink: 0;
  border-radius: 4px;
  cursor: pointer;
  border: 1px solid #dcdfe6;
}

.captcha-btn-fallback {
  width: 110px;
}

.agreement-item :deep(.el-form-item__content) {
  line-height: 1.4;
}

.agreement-link {
  color: #409eff;
  text-decoration: none;
}

.to-login {
  text-align: center;
  font-size: 13px;
  color: #909399;
}
</style>
