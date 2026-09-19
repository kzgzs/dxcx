<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { useAuthStore } from '../stores/auth'
import { getCaptcha } from '../api/captcha'
import { getRegisterConfig } from '../api/register'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const formRef = ref<FormInstance>()
const loading = ref(false)
// token 直登（代登录通道）状态
const directLoading = ref(false)

const form = reactive({
  username: '',
  password: '',
  captcha: '',
})

const rules: FormRules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
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

// 登录成功后按角色跳转对应首页
function afterLogin() {
  const redirect = route.query.redirect as string | undefined
  router.push(redirect || authStore.homePath)
}

async function handleLogin() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    loading.value = true
    try {
      await authStore.login(
        form.username,
        form.password,
        captchaId.value,
        form.captcha,
      )
      ElMessage.success('登录成功')
      afterLogin()
    } catch {
      // 错误提示已由 axios 拦截器/store 统一弹出；登录失败自动刷新验证码
      form.captcha = ''
      loadCaptcha()
    } finally {
      loading.value = false
    }
  })
}

// 注册入口开关：默认显示，读取 /console/register/config 的 reg_open 后联动
const regOpen = ref(1)

async function loadRegConfig() {
  try {
    const data = await getRegisterConfig()
    regOpen.value = data.reg_open === 1 ? 1 : 0
  } catch {
    // 接口失败默认显示注册入口
    regOpen.value = 1
  }
}

function goRegister() {
  router.push('/register')
}

// URL 携带 ?token=xxx：直接存入 token → 拉 profile 判角色 → 跳转对应首页
onMounted(async () => {
  loadRegConfig()
  loadCaptcha()
  const raw = route.query.token
  if (!raw || typeof raw !== 'string') return
  directLoading.value = true
  try {
    await authStore.loginByToken(raw)
    ElMessage.success('登录成功')
    afterLogin()
  } catch {
    ElMessage.error('登录链接无效或已过期，请重新登录')
    router.replace({ path: '/login' })
  } finally {
    directLoading.value = false
  }
})
</script>

<template>
  <div class="login-page">
    <el-card v-if="directLoading" class="login-card" shadow="always">
      <div class="direct-tip" v-loading="true" element-loading-text="正在进入系统…">
        <div class="direct-tip-text">正在验证登录凭证</div>
      </div>
    </el-card>
    <el-card v-else class="login-card" shadow="always">
      <template #header>
        <div class="login-title">鼎极多端</div>
        <div class="login-slogan">不用写代码，小程序、公众号、H5、APP 一站端出</div>
      </template>
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="0"
        size="large"
        @keyup.enter="handleLogin"
      >
        <el-form-item prop="username">
          <el-input
            v-model="form.username"
            placeholder="请输入用户名"
            autocomplete="username"
          />
        </el-form-item>
        <el-form-item prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="请输入密码"
            show-password
            autocomplete="current-password"
          />
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
            <el-button v-else class="captcha-img" @click="loadCaptcha">刷新</el-button>
          </div>
        </el-form-item>
        <el-form-item>
          <el-button
            type="primary"
            class="login-btn"
            :loading="loading"
            @click="handleLogin"
          >
            登 录
          </el-button>
        </el-form-item>
      </el-form>
      <div v-if="regOpen === 1" class="to-register">
        没有账号？<el-link type="primary" @click="goRegister">前往注册</el-link>
      </div>
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

.direct-tip {
  height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.to-register {
  text-align: center;
  font-size: 13px;
  color: #909399;
}

.direct-tip-text {
  color: #606266;
  font-size: 14px;
}
</style>
