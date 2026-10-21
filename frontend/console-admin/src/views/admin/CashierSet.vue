<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getCashdeskConfig, saveCashdeskConfig } from '../../api/cashier'
import { uploadBusinessCert } from '../../api/business'

const loading = ref(false)
const submitting = ref(false)

// 收银设置表单（对齐点大 cashier_set/index）
const form = reactive({
  name: '收银台',
  color1: '#ED3F14',
  wxpay: 0,
  cashpay: 1,
  moneypay: 1,
  remove_zero_length: 0,
  paypwd_use_status: 0,
  jiaoban_print_ids: [] as number[],
  is_use_mid_search: 1,
  cashdesk_give_score_set: 1,
  cashier_url: '',
  login_url: '',
})

// 微信支付设置（对齐点大：普通/服务商模式）
const pinfo = reactive({
  wxpay_type: 0,
  appid: '',
  wxpay_sub_mchid: '',
  wxpay_mchid: '',
  wxpay_mchkey: '',
  wxpay_apiclient_cert: '',
  wxpay_apiclient_key: '',
})

// 交班打印机候选
const printers = ref<{ id: number; name: string }[]>([])

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getCashdeskConfig()
    form.name = data?.name || '收银台'
    form.color1 = data?.color1 || '#ED3F14'
    form.wxpay = Number(data?.wxpay ?? 0)
    form.cashpay = Number(data?.cashpay ?? 0)
    form.moneypay = Number(data?.moneypay ?? 0)
    form.remove_zero_length = Number(data?.remove_zero_length ?? 0)
    form.paypwd_use_status = Number(data?.paypwd_use_status ?? 0)
    form.is_use_mid_search = Number(data?.is_use_mid_search ?? 1)
    form.cashdesk_give_score_set = Number(data?.cashdesk_give_score_set ?? 1)
    form.cashier_url = data?.cashier_url || ''
    form.login_url = data?.login_url || ''
    form.jiaoban_print_ids = String(data?.jiaoban_print_ids ?? '')
      .split(',')
      .filter((v) => v !== '')
      .map((v) => Number(v))
    printers.value = data?.printers || []
    if (data?.pinfo) {
      pinfo.wxpay_type = Number(data.pinfo.wxpay_type ?? 0)
      pinfo.appid = data.pinfo.appid || ''
      pinfo.wxpay_sub_mchid = data.pinfo.wxpay_sub_mchid || ''
      pinfo.wxpay_mchid = data.pinfo.wxpay_mchid || ''
      pinfo.wxpay_mchkey = data.pinfo.wxpay_mchkey || ''
      pinfo.wxpay_apiclient_cert = data.pinfo.wxpay_apiclient_cert || ''
      pinfo.wxpay_apiclient_key = data.pinfo.wxpay_apiclient_key || ''
    }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await saveCashdeskConfig({
      name: form.name,
      color1: form.color1,
      wxpay: form.wxpay,
      cashpay: form.cashpay,
      moneypay: form.moneypay,
      remove_zero_length: form.remove_zero_length,
      paypwd_use_status: form.paypwd_use_status,
      jiaoban_print_ids: form.jiaoban_print_ids.join(','),
      is_use_mid_search: form.is_use_mid_search,
      cashdesk_give_score_set: form.cashdesk_give_score_set,
      pinfo: { ...pinfo },
    })
    ElMessage.success('保存成功')
    fetchConfig()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

// PEM证书/证书密钥上传（.pem 后缀由后端校验）
const certUploading = ref(false)
const keyUploading = ref(false)
async function handleUploadCert(kind: 'cert' | 'key', file: File) {
  if (kind === 'cert') certUploading.value = true
  else keyUploading.value = true
  try {
    const data = await uploadBusinessCert(file)
    if (kind === 'cert') pinfo.wxpay_apiclient_cert = data.url
    else pinfo.wxpay_apiclient_key = data.url
    ElMessage.success('上传成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    if (kind === 'cert') certUploading.value = false
    else keyUploading.value = false
  }
  return false
}

onMounted(() => {
  fetchConfig()
})
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <!-- 对齐点大顶部提示 -->
      <el-alert type="success" :closable="false" class="top-quote">
        收银台需先保存设置后使用，相应的扫码进行收款和选择商品类目，商品条码需在“商城-商品管理-编辑”进行编辑，使用账号密码登录进行手动获取可获取修改数据。
      </el-alert>

      <el-form label-width="110px" label-position="left" class="set-form">
        <el-form-item label="收银台地址：" required>
          <a class="addr-link" :href="form.cashier_url" target="_blank">{{ form.cashier_url }}</a>
          <span class="aux">*复制收银台地址，并在商户登录的浏览器环境下打开链接</span>
        </el-form-item>
        <el-form-item label="登录地址：" required>
          <a class="addr-link" :href="form.login_url" target="_blank">{{ form.login_url }}</a>
          <span class="aux">*复制收银台登录地址，在未登录的浏览器中打开链接，使用商户的账号和密码进行登录</span>
        </el-form-item>
        <el-form-item label="主色调：">
          <el-input v-model="form.color1" style="width: 160px" />
          <el-color-picker v-model="form.color1" style="margin-left: 8px" />
          <span class="aux">收银台主色调，如：#2792FF</span>
        </el-form-item>
        <el-form-item label="抹零位数：">
          <el-input v-model.number="form.remove_zero_length" style="width: 300px" />
          <span class="aux">金额默认为2位小数，长度大于2时，将进行整数位抹零；小于100的金额不支持整数位抹零</span>
        </el-form-item>
        <el-form-item label="收款方式：">
          <el-checkbox v-model="form.wxpay" :true-value="1" :false-value="0">微信</el-checkbox>
          <el-checkbox v-model="form.cashpay" :true-value="1" :false-value="0">现金</el-checkbox>
          <el-checkbox v-model="form.moneypay" :true-value="1" :false-value="0">余额</el-checkbox>
          <span class="aux">开启微信收款时，请确定下方微信支付已配置完成，其他支付平台同理</span>
        </el-form-item>
        <el-form-item label="交班打印机：">
          <el-checkbox-group v-model="form.jiaoban_print_ids">
            <el-checkbox v-for="p in printers" :key="p.id" :value="p.id">{{ p.name }}</el-checkbox>
          </el-checkbox-group>
          <span v-if="!printers.length" class="aux">暂无打印机</span>
          <span class="aux">请在“系统-小票打印机”中增加小票打印机</span>
        </el-form-item>
        <el-form-item label="会员ID搜索：">
          <el-radio-group v-model="form.is_use_mid_search">
            <el-radio :value="0">关闭</el-radio>
            <el-radio :value="1">开启</el-radio>
          </el-radio-group>
          <span class="aux">开启后，可使用会员ID进行搜索会员并进行扣款</span>
        </el-form-item>
        <el-form-item label="积分赠送：">
          <el-radio-group v-model="form.cashdesk_give_score_set">
            <el-radio :value="1">按系统设置消费送积分</el-radio>
            <el-radio :value="2">按商品独立设置送积分</el-radio>
          </el-radio-group>
          <span class="aux">直接收款按系统设置消费送积分</span>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" class="block">
      <div class="list-title">微信支付设置</div>
      <el-form label-width="110px" label-position="left" class="set-form">
        <el-form-item label="微支付模式：">
          <el-radio-group v-model="pinfo.wxpay_type">
            <el-radio :value="0">普通模式</el-radio>
            <el-radio :value="1">服务商模式</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="AppID：">
          <el-input v-model="pinfo.appid" style="width: 260px" />
          <span class="aux">
            登录微信公众平台(mp.weixin.qq.com)[设置与开发]-[账号设置]-[账号信息]-在基本信息中查找
          </span>
        </el-form-item>
        <!-- 服务商模式：仅服务商分配的商户号 -->
        <template v-if="pinfo.wxpay_type === 1">
          <el-form-item label="支付商户号：">
            <el-input v-model="pinfo.wxpay_sub_mchid" style="width: 260px" />
            <span class="aux">服务商分配的商户号</span>
          </el-form-item>
        </template>
        <!-- 普通模式：商户号+密钥+证书 -->
        <template v-else>
          <el-form-item label="支付商户号：">
            <el-input v-model="pinfo.wxpay_mchid" style="width: 260px" />
          </el-form-item>
          <el-form-item label="支付密钥：">
            <el-input v-model="pinfo.wxpay_mchkey" style="width: 260px" placeholder="已隐藏内容，点击查看或编辑" />
            <span class="aux">
              请在
              <a href="https://pay.weixin.qq.com" target="_blank" class="aux-link">微信支付商户平台</a>
              [账户中心]-[API安全]中设置APIv2密钥
            </span>
          </el-form-item>
          <el-form-item label="PEM证书：">
            <el-input v-model="pinfo.wxpay_apiclient_cert" readonly style="width: 320px" />
            <el-upload
              :show-file-list="false"
              :http-request="() => {}"
              :before-upload="(f: File) => handleUploadCert('cert', f)"
              accept=".pem"
              style="margin-left: 8px"
            >
              <el-button :loading="certUploading">上传</el-button>
            </el-upload>
            <el-tag v-if="pinfo.wxpay_apiclient_cert" type="success" size="small" style="margin-left: 8px">已上传</el-tag>
            <span class="aux">
              apiclient_cert.pem 请在
              <a href="https://pay.weixin.qq.com" target="_blank" class="aux-link">微信支付商户平台</a>
              [账户中心]-[API安全]中设置API证书，设置完成后上传
            </span>
          </el-form-item>
          <el-form-item label="证书密钥：">
            <el-input v-model="pinfo.wxpay_apiclient_key" readonly style="width: 320px" />
            <el-upload
              :show-file-list="false"
              :http-request="() => {}"
              :before-upload="(f: File) => handleUploadCert('key', f)"
              accept=".pem"
              style="margin-left: 8px"
            >
              <el-button :loading="keyUploading">上传</el-button>
            </el-upload>
            <el-tag v-if="pinfo.wxpay_apiclient_key" type="success" size="small" style="margin-left: 8px">已上传</el-tag>
            <span class="aux">
              apiclient_key.pem 请在
              <a href="https://pay.weixin.qq.com" target="_blank" class="aux-link">微信支付商户平台</a>
              [账户中心]-[API安全]中设置API证书，设置完成后上传
            </span>
          </el-form-item>
        </template>
        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.top-quote { margin-bottom: 12px; }
.block { margin-top: 3px; }
.list-title { font-size: 16px; font-weight: 600; margin-bottom: 12px; }
.set-form { max-width: 1100px; }
.addr-link { color: #409eff; word-break: break-all; }
.aux { margin-left: 10px; color: #909399; font-size: 12px; }
.aux-link { color: #409eff; }
</style>
