<script setup lang="ts">
import { onMounted, reactive, ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { getExpressSet, saveExpressSet } from '../../api/express'

// 租户后台 - 扩展·同城配送·快递鸟设置（片C；照截图两部分）

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  status: 0,
  user_id: '',
  api_key: '',
  paidan_mode: 0,
  default_channel: 'standard',
  member_fee_type: 0,
  member_fee_value: '0.00',
  merchant_fee_type: 1,
  merchant_fee_value: '55.00',
})

// 加价单位：0百分比→比例%；1固定金额→元
const memberFeeUnit = computed(() => (Number(form.member_fee_type) === 0 ? '比例%' : '元'))
const merchantFeeUnit = computed(() => (Number(form.merchant_fee_type) === 0 ? '比例%' : '元'))

async function fetchSet() {
  loading.value = true
  try {
    const { info } = await getExpressSet()
    if (info) {
      form.status = Number(info.status ?? 0)
      form.user_id = String(info.user_id ?? '')
      form.api_key = String(info.api_key ?? '')
      form.paidan_mode = Number(info.paidan_mode ?? 0)
      form.default_channel = String(info.default_channel ?? 'standard')
      form.member_fee_type = Number(info.member_fee_type ?? 0)
      form.member_fee_value = String(info.member_fee_value ?? '0.00')
      form.merchant_fee_type = Number(info.merchant_fee_type ?? 0)
      form.merchant_fee_value = String(info.merchant_fee_value ?? '0.00')
    }
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveExpressSet({ ...form })
    ElMessage.success('保存成功')
    await fetchSet()
  } catch {
    /* 拦截器已提示 */
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchSet()
})
</script>

<template>
  <div v-loading="loading" class="express-set">
    <!-- 顶部说明条 -->
    <div class="top-banner">
      用于对接快递鸟即时配送，不需要对接不用配置，开启后若使用快递鸟即时配送进行配送，前往系统-配送方式-同城配送勾选配送费计价方式快递鸟账号填写后不可随意更改，不然无法与门店匹配，无法正常发货。
    </div>
    <div class="join-tip">普通入驻：自主联系快递鸟完成入驻；快速入驻：通过平台已有通道，快速接入。</div>

    <el-form :model="form" label-width="120px" class="set-form">
      <el-form-item label="状态">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item label="*用户ID">
        <el-input v-model="form.user_id" style="width: 240px" placeholder="请输入用户ID" />
        <a class="open-link" href="https://www.kdniao.com" target="_blank">去开通</a>
        <span class="red-tip">注册后联系客服签约开通功能，非本系统代理开通的账号不提供售后服务。</span>
        <div class="sub-tip">登录官网-首页-右上角-用户ID和API KEY</div>
      </el-form-item>

      <el-form-item label="*API KEY">
        <el-input v-model="form.api_key" style="width: 240px" placeholder="请输入API KEY" />
        <div class="sub-tip">登录官网-首页-右上角-用户ID和API KEY</div>
      </el-form-item>

      <el-form-item label="派单模式">
        <el-radio-group v-model="form.paidan_mode">
          <el-radio :value="0">手动派单</el-radio>
          <el-radio :value="1">自动派单</el-radio>
        </el-radio-group>
        <div class="sub-tip">
          手动派单：需在后台订单列表中手动操作派单。自动派单：下单后，系统自动调用选择的默认渠道进行派单。若预约单自动派单失败，需手动再次派单。
        </div>
      </el-form-item>

      <el-form-item label="默认配送渠道">
        <el-radio-group v-model="form.default_channel">
          <el-radio value="standard">优配标准</el-radio>
          <el-radio value="special">优配特惠</el-radio>
          <el-radio value="premium">优配尊享</el-radio>
        </el-radio-group>
        <div class="sub-tip">请选择开通的渠道，未开通不可用。设置系统派单时优先使用的配送渠道，不同渠道、不同城市，配送价格均有差异</div>
      </el-form-item>

      <el-form-item label="配送费加价策略">
        <el-radio-group v-model="form.member_fee_type">
          <el-radio :value="0">百分比</el-radio>
          <el-radio :value="1">固定金额</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="">
        <div class="fee-row">
          <el-input v-model="form.member_fee_value" style="width: 140px" />
          <span class="fee-unit">{{ memberFeeUnit }}</span>
        </div>
        <div class="sub-tip">
          向会员收取的配送费，会在快递鸟实际返回的配送费基础上，再增加一定费用，0表示平台不额外加收。
        </div>
        <div class="sub-tip">百分比加价：按快递鸟配送费的百分比增加费用。举例：快递鸟计算订单配送费为10元，若设置百分比加价为20%，则会员应付10+10x20%=12元。</div>
        <div class="sub-tip">固定金额加价：直接加上一个固定的金额。举例：快递鸟计算订单配送费为10元，若设置固定加价为3元，则会员应付10+3元=13元。</div>
      </el-form-item>

      <el-form-item label="商户配送费加价策略">
        <el-radio-group v-model="form.merchant_fee_type">
          <el-radio :value="0">百分比</el-radio>
          <el-radio :value="1">固定金额</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="">
        <div class="fee-row">
          <el-input v-model="form.merchant_fee_value" style="width: 140px" />
          <span class="fee-unit">{{ merchantFeeUnit }}</span>
        </div>
        <div class="sub-tip">
          当商户使用平台账号时，平台向商户收取的配送费，可在快递鸟配送费基础上加价。0表示不加价。
        </div>
        <div class="sub-tip">百分比加价：按快递鸟配送费的百分比增加商户承担的费用。举例：快递鸟计算订单配送费为8元，若设置百分比加价为10%，则平台向商户收取8元+8x10%=8.8元。</div>
        <div class="sub-tip">固定金额加价：直接加上一个固定的金额。举例：快递鸟计算订单配送费为8元，若设置固定加价为2元，则平台向商户收取8元+2元=10元。</div>
      </el-form-item>

      <el-form-item label="">
        <el-button type="primary" :loading="saving" @click="handleSave">提交</el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.express-set {
  margin: 3px;
}
.top-banner {
  background: #f5f5f5;
  border-left: 4px solid #19be6b;
  padding: 10px 14px;
  color: #333;
  font-size: 13px;
  line-height: 1.7;
  margin-bottom: 8px;
}
.join-tip {
  color: #ed4014;
  font-size: 13px;
  margin: 4px 0 12px 120px;
}
.set-form {
  max-width: 860px;
}
.open-link {
  color: #2d8cf0;
  margin-left: 10px;
  font-size: 13px;
}
.red-tip {
  color: #ed4014;
  font-size: 12px;
  margin-left: 10px;
}
.sub-tip {
  color: #999;
  font-size: 12px;
  line-height: 1.8;
}
.fee-row {
  display: flex;
  align-items: center;
}
.fee-unit {
  background: #eee;
  color: #666;
  padding: 0 12px;
  height: 32px;
  line-height: 32px;
  font-size: 13px;
}
</style>
