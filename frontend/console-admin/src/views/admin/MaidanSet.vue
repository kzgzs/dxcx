<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { mdGetSet, mdSaveSet } from '../../api/maidan'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  maidan_login: 0,
  maidan_getlocation: 0,
  maidan_payaftertourl: '',
})

const h5Url = ref('')
const shopName = ref('')

// 二维码图片（聚合收款码指向 H5 买单页链接）
const qrSrc = computed(() => {
  if (!h5Url.value) return ''
  return `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(h5Url.value)}`
})

async function loadData() {
  loading.value = true
  try {
    const result = await mdGetSet()
    form.maidan_login = Number(result.maidan_login) || 0
    form.maidan_getlocation = Number(result.maidan_getlocation) || 0
    form.maidan_payaftertourl = result.maidan_payaftertourl || ''
    h5Url.value = result.h5_url || ''
    shopName.value = result.shop_name || ''
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await mdSaveSet({
      maidan_login: form.maidan_login ? 1 : 0,
      maidan_getlocation: form.maidan_getlocation ? 1 : 0,
      maidan_payaftertourl: form.maidan_payaftertourl.trim(),
    })
    ElMessage.success('保存成功')
    await loadData()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    saving.value = false
  }
}

function openH5() {
  if (h5Url.value) window.open(h5Url.value, '_blank')
}

onMounted(() => {
  loadData()
})
</script>

<template>
  <div class="maidan-set-page" v-loading="loading">
    <div class="page-card">
      <div class="card-title">聚合收款码设置</div>
      <div class="set-body">
        <el-form :model="form" label-width="160px" class="set-form">
          <el-form-item label="买单需登录">
            <el-switch v-model="form.maidan_login" :active-value="1" :inactive-value="0" />
            <span class="form-tip">开启后会员扫码买单需先登录授权，便于识别会员身份享受折扣与积分抵扣</span>
          </el-form-item>
          <el-form-item label="买单获取位置">
            <el-switch v-model="form.maidan_getlocation" :active-value="1" :inactive-value="0" />
            <span class="form-tip">开启后买单页自动获取会员位置，用于就近识别门店</span>
          </el-form-item>
          <el-form-item label="支付后跳转链接">
            <el-input
              v-model="form.maidan_payaftertourl"
              placeholder="买单支付成功后跳转的页面地址，留空显示默认成功页"
              style="width: 420px"
              maxlength="255"
            />
          </el-form-item>
          <el-form-item label="买单页链接">
            <div class="h5-url-box">
              <span class="h5-url">{{ h5Url || '—' }}</span>
              <el-button link type="primary" @click="openH5" :disabled="!h5Url">打开预览</el-button>
            </div>
          </el-form-item>
          <el-form-item>
            <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
          </el-form-item>
        </el-form>

        <div class="qr-panel">
          <div class="qr-title">收款二维码（{{ shopName || '门店' }}）</div>
          <div class="qr-wrap" v-if="qrSrc">
            <img :src="qrSrc" alt="聚合收款码" class="qr-img" />
            <div class="qr-label">微信 / 支付宝 扫码买单</div>
          </div>
          <el-empty v-else description="买单页链接为空，保存后生成" :image-size="80" />
          <div class="qr-tip">打印张贴于收银台，会员扫码进入 H5 买单页完成余额支付</div>
        </div>
      </div>

      <div class="page-note">
        <p>配置指引：</p>
        <p>1. 将右侧收款二维码打印后张贴于收银台显眼位置，支持微信、支付宝扫码（聚合码指向同一买单页）。</p>
        <p>2. 会员扫码后进入 H5 买单页，输入消费金额即可使用余额支付，自动享受等级折扣、优惠券与积分抵扣。</p>
        <p>3. 开启「买单需登录」可识别会员身份；未登录按散客收款，不享受会员优惠。</p>
        <p>4. 收款记录在「买单记录」中查询，支持筛选、退款与导出。</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.maidan-set-page {
  padding: 12px;
}
.page-card {
  background: #fff;
  border-radius: 4px;
  padding: 16px 20px;
}
.card-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #ebeef5;
}
.set-body {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}
.set-form {
  flex: 1;
  max-width: 760px;
}
.form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
.h5-url-box {
  display: flex;
  align-items: center;
  gap: 12px;
}
.h5-url {
  color: #409eff;
  word-break: break-all;
}
.qr-panel {
  width: 280px;
  flex-shrink: 0;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  padding: 16px;
  text-align: center;
}
.qr-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 12px;
}
.qr-img {
  width: 220px;
  height: 220px;
  border: 1px solid #ebeef5;
}
.qr-label {
  margin-top: 8px;
  font-size: 13px;
  color: #303133;
}
.qr-tip {
  margin-top: 8px;
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
}
.page-note {
  margin-top: 8px;
  padding: 12px 14px;
  background: #f7f8fa;
  border-radius: 4px;
  color: #909399;
  font-size: 12px;
  line-height: 1.9;
  max-width: 1040px;
}
.page-note p {
  margin: 0;
}
</style>
