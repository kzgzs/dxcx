<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { getOpenConfig, saveOpenConfig } from '../api/open'
import type { OpenConfigData } from '../api/types'

// ============ 配置读取 ============
const loading = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()
const rawConfig = ref<OpenConfigData | null>(null)

const form = reactive({
  status: 0,
  component_appid: '',
  component_appsecret: '',
  token: '',
  encoding_aes_key: '',
  ip_whitelist: '',
  auth_domain: '',
  mock_mode: 0,
})

// 脱敏 secret 仅作输入框占位
const secretPlaceholder = computed(() =>
  rawConfig.value?.appsecret_masked ? rawConfig.value.appsecret_masked : '留空则不修改',
)

// 只读 URL 区
const readonlyUrls = computed(() => {
  const c = rawConfig.value
  if (!c) return [] as { label: string; value: string }[]
  return [
    { label: '授权事件接收地址（event_url）', value: c.event_url || '' },
    { label: '消息与事件接收地址（msg_url_pattern）', value: c.msg_url_pattern || '' },
    { label: '授权回调地址（callback_url）', value: c.callback_url || '' },
  ]
})

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getOpenConfig()
    rawConfig.value = data
    Object.assign(form, {
      status: data.status ?? 0,
      component_appid: data.component_appid || '',
      component_appsecret: '',
      token: data.token || '',
      encoding_aes_key: data.encoding_aes_key || '',
      ip_whitelist: data.ip_whitelist || '',
      auth_domain: data.auth_domain || '',
      mock_mode: data.mock_mode ?? 0,
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// ============ 表单校验 ============
const rules: FormRules = {
  component_appid: [{ required: true, message: '请输入第三方平台 AppID', trigger: 'blur' }],
  token: [{ required: true, message: '请输入消息校验 Token', trigger: 'blur' }],
  encoding_aes_key: [
    { required: true, message: '请输入 EncodingAESKey', trigger: 'blur' },
    { len: 43, message: 'EncodingAESKey 必须为 43 位字符', trigger: 'blur' },
  ],
}

// ============ 保存 ============
async function handleSave() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    saving.value = true
    try {
      await saveOpenConfig({
        status: form.status,
        component_appid: form.component_appid,
        component_appsecret: form.component_appsecret, // 空串=不修改
        token: form.token,
        encoding_aes_key: form.encoding_aes_key,
        ip_whitelist: form.ip_whitelist,
        auth_domain: form.auth_domain,
        mock_mode: form.mock_mode,
      })
      ElMessage.success('保存成功')
      form.component_appsecret = ''
      fetchConfig()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      saving.value = false
    }
  })
}

// ============ 复制（失败降级 execCommand） ============
async function copyText(text: string) {
  if (!text) {
    ElMessage.warning('内容为空，无需复制')
    return
  }
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      ElMessage.success('已复制到剪贴板')
      return
    }
    throw new Error('clipboard api unavailable')
  } catch {
    // 降级方案：隐藏 textarea + execCommand
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

onMounted(fetchConfig)
</script>

<template>
  <div v-loading="loading">
    <!-- 平台设置表单 -->
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>第三方平台设置</span>
          <div class="switch-group">
            <span class="switch-item">
              <span class="switch-label">平台总开关</span>
              <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
            </span>
            <span class="switch-item">
              <span class="switch-label">模拟模式（mock）</span>
              <el-switch v-model="form.mock_mode" :active-value="1" :inactive-value="0" />
            </span>
          </div>
        </div>
      </template>

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="160px"
        label-position="left"
        style="max-width: 680px"
      >
        <el-form-item label="第三方平台 AppID" prop="component_appid">
          <el-input v-model="form.component_appid" placeholder="请输入 component_appid" clearable />
        </el-form-item>
        <el-form-item label="组件 AppSecret" prop="component_appsecret">
          <el-input
            v-model="form.component_appsecret"
            :placeholder="secretPlaceholder"
            clearable
            show-password
          />
          <div class="form-tip">留空则不修改</div>
        </el-form-item>
        <el-form-item label="消息校验 Token" prop="token">
          <el-input v-model="form.token" placeholder="请输入消息校验 Token" clearable />
        </el-form-item>
        <el-form-item label="EncodingAESKey" prop="encoding_aes_key">
          <el-input
            v-model="form.encoding_aes_key"
            placeholder="请输入 43 位 EncodingAESKey"
            clearable
            maxlength="43"
          />
        </el-form-item>
        <el-form-item label="IP 白名单">
          <el-input
            v-model="form.ip_whitelist"
            type="textarea"
            :rows="4"
            placeholder="每行一个 IP"
          />
        </el-form-item>
        <el-form-item label="授权发起页域名">
          <el-input v-model="form.auth_domain" placeholder="如：auth.example.com" clearable />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 只读回调地址 -->
    <el-card shadow="never" class="url-card">
      <template #header>
        <span>回调地址（只读，需配置到微信开放平台 / 转发服务）</span>
      </template>
      <div v-for="item in readonlyUrls" :key="item.label" class="url-row">
        <div class="url-label">{{ item.label }}</div>
        <div class="url-value">{{ item.value || '（未生成）' }}</div>
        <el-button size="small" @click="copyText(item.value)">复制</el-button>
      </div>
    </el-card>

    <!-- 授权链接 / 二维码使用说明 -->
    <el-card shadow="never" class="url-card">
      <template #header>
        <span>授权链接 / 二维码使用说明</span>
      </template>
      <el-alert type="info" :closable="false" show-icon class="guide-alert">
        <template #title>
          授权流程说明
        </template>
        <div class="guide-list">
          <p>1. 在「授权账号」页点击「发起授权」，系统会生成微信官方授权页链接（pre_auth_code 有效期 10 分钟）并在新窗口打开；</p>
          <p>2. 公众号 / 小程序管理员在授权页扫码或登录后，确认授权范围即可完成绑定，无需手动复制链接；</p>
          <p>3. 若浏览器拦截弹窗，可复制授权链接发给管理员在浏览器打开，再用微信扫码授权；</p>
          <p>4. 授权成功后微信回调「授权回调地址」，系统自动落库并刷新列表；授权长期有效，无需续期；</p>
          <p>5. 「授权发起页域名」需与微信开放平台后台配置的域名一致，否则授权页无法打开；</p>
          <p>6. 模拟模式（mock）下生成的是模拟授权页，仅用于无资质环境联调，不会真实调用微信接口。</p>
        </div>
      </el-alert>
    </el-card>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.switch-group {
  display: flex;
  align-items: center;
  gap: 24px;
}

.switch-item {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.switch-label {
  color: #606266;
  font-size: 14px;
}

.form-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.4;
}

.url-card {
  margin-top: 16px;
}

.url-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px dashed #ebeef5;
}

.url-row:last-child {
  border-bottom: none;
}

.url-label {
  width: 300px;
  flex-shrink: 0;
  color: #606266;
  font-size: 13px;
}

.url-value {
  flex: 1;
  color: #303133;
  font-family: Consolas, Monaco, monospace;
  font-size: 13px;
  word-break: break-all;
}

.guide-alert {
  background-color: #ffffff;
  padding: 0;
}

.guide-list p {
  margin: 4px 0;
  color: #606266;
  font-size: 13px;
  line-height: 1.7;
}
</style>
