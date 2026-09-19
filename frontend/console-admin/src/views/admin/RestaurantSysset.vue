<script setup lang="ts">
/**
 * 餐饮 - 设置（对照参考系统 restaurant/sysset，表 restaurant_admin_set）
 * 字段组：点餐评价 / 外卖设置 / 排队叫号接入 / 收银台支付方式
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getRestaurantSysset, saveRestaurantSysset } from '../../api/restaurantExt'

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  shop_comment: 1,
  shop_comment_check: 0,
  shop_showcommission: 0,
  takeaway_autoclose: 0,
  takeaway_comment: 1,
  takeaway_comment_check: 0,
  takeaway_showcommission: 0,
  queue_appid: '',
  queue_secretid: '',
  queue_secretkey: '',
  queue_voicetype: '',
  business_cashdesk_wxpay_type: 0,
  business_cashdesk_sxpay_type: 0,
  business_cashdesk_alipay_type: 0,
  business_cashdesk_yue: 1,
  business_cashdesk_cashpay: 1,
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getRestaurantSysset()
    Object.assign(form, data.info || {})
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await saveRestaurantSysset({ ...form })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

onMounted(fetchSet)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">餐饮设置</span>
        <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
      </div>
    </template>

    <el-form :model="form" label-width="160px" label-position="right" class="compact-form">
      <div class="section-title">点餐评价</div>
      <el-form-item label="商品评价">
        <el-radio-group v-model="form.shop_comment">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <template v-if="form.shop_comment === 1">
        <el-form-item label="评价审核">
          <el-radio-group v-model="form.shop_comment_check">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="是否显示佣金栏">
          <el-radio-group v-model="form.shop_showcommission">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">不显示</el-radio>
          </el-radio-group>
        </el-form-item>
      </template>

      <div class="section-title">外卖设置</div>
      <el-form-item label="商品评价">
        <el-radio-group v-model="form.takeaway_comment">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <template v-if="form.takeaway_comment === 1">
        <el-form-item label="评价审核">
          <el-radio-group v-model="form.takeaway_comment_check">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="是否显示佣金栏">
          <el-radio-group v-model="form.takeaway_showcommission">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">不显示</el-radio>
          </el-radio-group>
        </el-form-item>
      </template>
      <el-form-item label="自动关闭订单天数">
        <el-input-number v-model="form.takeaway_autoclose" :min="0" :max="365" />
        <span class="form-tip">天（0 为不自动关闭）</span>
      </el-form-item>

      <div class="section-title">排队叫号接入（腾讯云）</div>
      <el-form-item label="腾讯云APPID">
        <el-input v-model="form.queue_appid" placeholder="排队叫号小程序 APPID" style="width: 300px" />
      </el-form-item>
      <el-form-item label="腾讯云SecretID">
        <el-input v-model="form.queue_secretid" placeholder="云API SecretID" style="width: 300px" />
      </el-form-item>
      <el-form-item label="腾讯云SecretKEY">
        <el-input v-model="form.queue_secretkey" placeholder="云API SecretKEY" style="width: 300px" />
      </el-form-item>
      <el-form-item label="语音播报类型">
        <el-input v-model="form.queue_voicetype" placeholder="音色编号，如 1002" style="width: 200px" />
      </el-form-item>

      <div class="section-title">收银台支付方式（商户侧）</div>
      <el-form-item label="商户微信支付">
        <el-radio-group v-model="form.business_cashdesk_wxpay_type">
          <el-radio :value="1">独立商户</el-radio>
          <el-radio :value="2">平台收款</el-radio>
          <el-radio :value="3">商户收款</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="商户随行付收款">
        <el-radio-group v-model="form.business_cashdesk_sxpay_type">
          <el-radio :value="1">独立商户</el-radio>
          <el-radio :value="2">平台收款</el-radio>
          <el-radio :value="3">商户收款</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="商户支付宝收款">
        <el-radio-group v-model="form.business_cashdesk_alipay_type">
          <el-radio :value="2">平台收款</el-radio>
          <el-radio :value="3">商户收款</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="商户余额收款">
        <el-radio-group v-model="form.business_cashdesk_yue">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="商户现金收款">
        <el-radio-group v-model="form.business_cashdesk_cashpay">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
    </el-form>
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.section-title {
  margin: 18px 0 12px;
  padding-left: 8px;
  border-left: 3px solid #409eff;
  font-size: 14px;
  font-weight: 600;
  color: #303133;
}

.compact-form {
  max-width: 860px;
}

.form-tip {
  margin-left: 10px;
  font-size: 12px;
  color: #909399;
}
</style>
