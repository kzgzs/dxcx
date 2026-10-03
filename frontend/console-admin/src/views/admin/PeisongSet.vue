<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getPeisongSet, savePeisongSet } from '../../api/peisong'

// 租户后台 - 同城配送·系统设置（照截图：配送设置；结算方式选"按距离计费提成"时条件展开对应提成字段）

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  status: 1,
  paidantype: 0,
  yuji_sendminute: '10',
  yuji_psjuli1: '5',
  yuji_psminute1: '20',
  yuji_psjuli2: '1',
  yuji_psminute2: '0',
  jiesuantype: 0,
  tcmoney: '70.00',
  peisong_juli1: '5',
  peisong_tcmoney1: '10.00',
  peisong_juli2: '1',
  peisong_tcmoney2: '1.00',
  peisong_tcmoneymax: '20.00',
  withdraw_weixin: 1,
  withdraw_aliaccount: 0,
  withdraw_bankcard: 0,
  withdrawmin: '0',
  withdrawfee: '0',
  businessst: 1,
  businessfee: '1.00',
})

function applyInfo(info: Record<string, unknown>) {
  const f = form as unknown as Record<keyof typeof form, string | number>
  const num = (k: keyof typeof form, def: string | number) => {
    const v = info[k]
    if (v === undefined || v === null || v === '') {
      f[k] = def
      return
    }
    f[k] = typeof f[k] === 'number' ? Number(v) : String(v)
  }
  num('status', 1)
  num('paidantype', 0)
  num('yuji_sendminute', '10')
  num('yuji_psjuli1', '5')
  num('yuji_psminute1', '20')
  num('yuji_psjuli2', '1')
  num('yuji_psminute2', '0')
  num('jiesuantype', 0)
  num('tcmoney', '70.00')
  num('peisong_juli1', '5')
  num('peisong_tcmoney1', '10.00')
  num('peisong_juli2', '1')
  num('peisong_tcmoney2', '1.00')
  num('peisong_tcmoneymax', '20.00')
  num('withdraw_weixin', 1)
  num('withdraw_aliaccount', 0)
  num('withdraw_bankcard', 0)
  num('withdrawmin', '0')
  num('withdrawfee', '0')
  num('businessst', 1)
  num('businessfee', '1.00')
}

async function fetchSet() {
  loading.value = true
  try {
    const { info } = await getPeisongSet()
    if (info) applyInfo(info as unknown as Record<string, unknown>)
  } catch { /* 拦截器 */ } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await savePeisongSet({ ...form })
    ElMessage.success('保存成功')
    await fetchSet() // 保存后回读，保证回填一致
  } catch { /* 拦截器 */ } finally {
    submitting.value = false
  }
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading" class="set-wrap">
    <el-card shadow="never">
      <!-- 提示条（照截图） -->
      <div class="tip-bar">配送方式为同城配送时，可以使用本模块进行分配配送员进行配送</div>
      <div class="tip-bar tip-bar-red">注意：订单发生退款时，需要进入对应的配送订单列表，手动取消配送订单，否则配送订单不受退款影响，会继续配送</div>

      <el-form label-width="130px" label-position="right" class="set-form">
        <el-form-item label="系统状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="派单模式">
          <el-radio-group v-model="form.paidantype">
            <el-radio :value="0">配送员抢单</el-radio>
            <el-radio :value="1">指定配送员</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="预计发货时间">
          <el-input v-model="form.yuji_sendminute" style="width: 100px" />
          <span class="tip">分钟</span>
          <span class="tip">预计送达时间 = 预计发货时间 + 预计配送时间</span>
        </el-form-item>

        <el-form-item label="预计配送时间">
          <el-input v-model="form.yuji_psjuli1" style="width: 100px" />
          <span class="tip">公里内</span>
          <el-input v-model="form.yuji_psminute1" style="width: 100px" />
          <span class="tip">分钟；每超出</span>
          <el-input v-model="form.yuji_psjuli2" style="width: 100px" />
          <span class="tip">公里，加</span>
          <el-input v-model="form.yuji_psminute2" style="width: 100px" />
          <span class="tip">分钟</span>
        </el-form-item>

        <el-form-item label="结算方式">
          <el-radio-group v-model="form.jiesuantype">
            <el-radio :value="0">按单计算提成</el-radio>
            <el-radio :value="1">按距离计费提成</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item v-if="form.jiesuantype === 0" label="提成金额">
          <el-input v-model="form.tcmoney" style="width: 160px" />
          <span class="tip">元每单</span>
        </el-form-item>

        <el-form-item v-else label="提成金额">
          <el-input v-model="form.peisong_juli1" style="width: 100px" />
          <span class="tip">公里内</span>
          <el-input v-model="form.peisong_tcmoney1" style="width: 120px" />
          <span class="tip">元；每超出</span>
          <el-input v-model="form.peisong_juli2" style="width: 100px" />
          <span class="tip">公里，加</span>
          <el-input v-model="form.peisong_tcmoney2" style="width: 120px" />
          <span class="tip">元</span>
          <span class="tip">封顶</span>
          <el-input v-model="form.peisong_tcmoneymax" style="width: 120px" />
          <span class="tip">元</span>
        </el-form-item>

        <el-form-item label="提现方式">
          <el-checkbox v-model="form.withdraw_weixin" :true-value="1" :false-value="0">微信钱包</el-checkbox>
          <el-checkbox v-model="form.withdraw_aliaccount" :true-value="1" :false-value="0">支付宝</el-checkbox>
          <el-checkbox v-model="form.withdraw_bankcard" :true-value="1" :false-value="0">银行卡</el-checkbox>
          <div class="tip block">提现到微信钱包需开通企业付款到零钱功能</div>
        </el-form-item>

        <el-form-item label="提现最小金额">
          <el-input v-model="form.withdrawmin" style="width: 120px" />
          <span class="tip">元</span>
        </el-form-item>

        <el-form-item label="提现手续费">
          <el-input v-model="form.withdrawfee" style="width: 120px" />
          <span class="tip">%</span>
        </el-form-item>

        <el-form-item label="商家是否可用">
          <el-radio-group v-model="form.businessst">
            <el-radio :value="1">是</el-radio>
            <el-radio :value="0">否</el-radio>
          </el-radio-group>
          <span class="tip">多商户的商家是否可用平台配送员进行配送</span>
        </el-form-item>

        <el-form-item label="商家费率">
          <el-input v-model="form.businessfee" style="width: 120px" />
          <span class="tip">%</span>
          <span class="tip">商家使用平台同城配送时加收配送员提成的百分之多少</span>
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="submitting" @click="handleSave">提交</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.set-wrap { margin: 3px; }
.set-form { max-width: 860px; padding: 16px 0; }
.tip-bar { background: #f5f6f7; border-left: 3px solid #67c23a; color: #606266; font-size: 13px; padding: 10px 14px; margin-bottom: 10px; }
.tip-bar-red { background: #f5f6f7; border-left: 3px solid #67c23a; color: #f56c6c; }
.tip { color: #909399; font-size: 12px; margin-left: 10px; white-space: nowrap; }
.tip.block { display: block; margin-left: 0; margin-top: 4px; }
</style>
