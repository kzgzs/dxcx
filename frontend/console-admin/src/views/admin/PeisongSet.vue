<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getPeisongSet, savePeisongSet } from '../../api/peisong'

// 租户后台 - 同城配送·系统设置（批次8 任务223；对标参考系统 Peisong::set/save）

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  status: 0,
  paidantype: 0,
  express_paidan: 0,
  yuji_sendminute: '10',
  yuji_psjuli1: '5',
  yuji_psminute1: '20',
  yuji_psjuli2: '1',
  yuji_psminute2: '2',
  jiesuantype: 0,
  tcmoney: '0.00',
  peisong_juli1: '5',
  peisong_tcmoney1: '5.00',
  peisong_juli2: '1',
  peisong_tcmoney2: '2.00',
  peisong_tcmoneymax: '20.00',
  withdraw_weixin: 1,
  withdraw_aliaccount: 1,
  withdraw_bankcard: 1,
  withdrawmin: '10',
  withdrawfee: '0',
  businessst: 0,
  businessfee: '0.00',
})

async function fetchSet() {
  loading.value = true
  try {
    const { info } = await getPeisongSet()
    if (info) {
      form.status = Number(info.status ?? 0)
      form.paidantype = Number(info.paidantype ?? 0)
      form.express_paidan = Number(info.express_paidan ?? 0)
      form.yuji_sendminute = String(info.yuji_sendminute ?? '10')
      form.yuji_psjuli1 = String(info.yuji_psjuli1 ?? '5')
      form.yuji_psminute1 = String(info.yuji_psminute1 ?? '20')
      form.yuji_psjuli2 = String(info.yuji_psjuli2 ?? '1')
      form.yuji_psminute2 = String(info.yuji_psminute2 ?? '2')
      form.jiesuantype = Number(info.jiesuantype ?? 0)
      form.tcmoney = String(info.tcmoney ?? '0.00')
      form.peisong_juli1 = String(info.peisong_juli1 ?? '5')
      form.peisong_tcmoney1 = String(info.peisong_tcmoney1 ?? '5.00')
      form.peisong_juli2 = String(info.peisong_juli2 ?? '1')
      form.peisong_tcmoney2 = String(info.peisong_tcmoney2 ?? '2.00')
      form.peisong_tcmoneymax = String(info.peisong_tcmoneymax ?? '20.00')
      form.withdraw_weixin = Number(info.withdraw_weixin ?? 1)
      form.withdraw_aliaccount = Number(info.withdraw_aliaccount ?? 1)
      form.withdraw_bankcard = Number(info.withdraw_bankcard ?? 1)
      form.withdrawmin = String(info.withdrawmin ?? '10')
      form.withdrawfee = String(info.withdrawfee ?? '0')
      form.businessst = Number(info.businessst ?? 0)
      form.businessfee = String(info.businessfee ?? '0.00')
    }
  } catch {
    // axios 拦截器已提示
  } finally {
    loading.value = false
  }
}

/** 保存（当页保存，不刷新不跳转） */
async function handleSave() {
  saving.value = true
  try {
    await savePeisongSet({ ...form })
    ElMessage.success('保存成功')
  } catch {
    // axios 拦截器已提示
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchSet()
})
</script>

<template>
  <div v-loading="loading">
    <el-form :model="form" label-width="150px" class="set-form">
      <el-card shadow="never" class="set-card">
        <template #header><span class="card-title">基础设置</span></template>
        <el-form-item label="同城配送开关">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">关闭后下单页不显示同城配送方式</span>
        </el-form-item>
        <el-form-item label="派单模式">
          <el-radio-group v-model="form.paidantype">
            <el-radio :value="0">配送员抢单</el-radio>
            <el-radio :value="1">强制派单</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="自动派单">
          <el-switch v-model="form.express_paidan" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后新配送单自动指派配送员</span>
        </el-form-item>
        <el-form-item label="商家服务费">
          <el-switch v-model="form.businessst" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item v-if="form.businessst === 1" label="服务费比例(%)">
          <el-input v-model="form.businessfee" style="width: 180px" />
          <span class="form-tip">向商家收取配送费的百分比加成</span>
        </el-form-item>
      </el-card>

      <el-card shadow="never" class="set-card">
        <template #header><span class="card-title">预计送达时间</span></template>
        <el-form-item label="接单时间(分钟)">
          <el-input v-model="form.yuji_sendminute" style="width: 180px" />
          <span class="form-tip">下单后预留的接单时间</span>
        </el-form-item>
        <el-form-item label="基础距离(公里)">
          <el-input v-model="form.yuji_psjuli1" style="width: 180px" />
        </el-form-item>
        <el-form-item label="基础配送时间(分钟)">
          <el-input v-model="form.yuji_psminute1" style="width: 180px" />
          <span class="form-tip">基础距离内的配送时间</span>
        </el-form-item>
        <el-form-item label="超出每(公里)">
          <el-input v-model="form.yuji_psjuli2" style="width: 180px" />
        </el-form-item>
        <el-form-item label="增加时间(分钟)">
          <el-input v-model="form.yuji_psminute2" style="width: 180px" />
          <span class="form-tip">超出基础距离后每公里增加的时间</span>
        </el-form-item>
      </el-card>

      <el-card shadow="never" class="set-card">
        <template #header><span class="card-title">提成结算</span></template>
        <el-form-item label="结算方式">
          <el-radio-group v-model="form.jiesuantype">
            <el-radio :value="0">固定提成</el-radio>
            <el-radio :value="1">按距离提成</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.jiesuantype === 0" label="每单提成(元)">
          <el-input v-model="form.tcmoney" style="width: 180px" />
        </el-form-item>
        <template v-else>
          <el-form-item label="起步距离(公里)">
            <el-input v-model="form.peisong_juli1" style="width: 180px" />
          </el-form-item>
          <el-form-item label="起步提成(元)">
            <el-input v-model="form.peisong_tcmoney1" style="width: 180px" />
          </el-form-item>
          <el-form-item label="超出每(公里)">
            <el-input v-model="form.peisong_juli2" style="width: 180px" />
          </el-form-item>
          <el-form-item label="增加提成(元)">
            <el-input v-model="form.peisong_tcmoney2" style="width: 180px" />
          </el-form-item>
          <el-form-item label="封顶提成(元)">
            <el-input v-model="form.peisong_tcmoneymax" style="width: 180px" />
            <span class="form-tip">0 为不封顶</span>
          </el-form-item>
        </template>
      </el-card>

      <el-card shadow="never" class="set-card">
        <template #header><span class="card-title">提现设置</span></template>
        <el-form-item label="提现方式">
          <el-checkbox v-model="form.withdraw_weixin" :true-value="1" :false-value="0">微信</el-checkbox>
          <el-checkbox v-model="form.withdraw_aliaccount" :true-value="1" :false-value="0">支付宝</el-checkbox>
          <el-checkbox v-model="form.withdraw_bankcard" :true-value="1" :false-value="0">银行卡</el-checkbox>
        </el-form-item>
        <el-form-item label="最低提现金额(元)">
          <el-input v-model="form.withdrawmin" style="width: 180px" />
        </el-form-item>
        <el-form-item label="提现手续费(%)">
          <el-input v-model="form.withdrawfee" style="width: 180px" />
        </el-form-item>
      </el-card>

      <div class="save-row">
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </div>
    </el-form>
  </div>
</template>

<style scoped>
.set-form {
  max-width: 760px;
}

.set-card {
  margin-bottom: 12px;
}

.card-title {
  font-weight: 600;
}

.form-tip {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
}

.save-row {
  padding: 4px 0 12px;
}
</style>
