<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getMoneyGivesetConfig,
  saveMoneyGivesetConfig,
  type GiveRow,
} from '../../api/moneyGiveset'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  caninput: 1,
  status: 0,
  shuoming: '',
})

const givedata = ref<GiveRow[]>([])

function addRow() {
  givedata.value.push({ money: 0, give: 0, give_score: 0 })
}

function removeRow(index: number) {
  givedata.value.splice(index, 1)
}

async function fetchConfig() {
  loading.value = true
  try {
    const d = await getMoneyGivesetConfig()
    form.caninput = d.caninput ?? 1
    form.status = d.status || 0
    form.shuoming = d.shuoming || ''
    givedata.value = (d.givedata || []).map((r) => ({
      money: r.money || 0,
      give: r.give || 0,
      give_score: r.give_score || 0,
    }))
    if (givedata.value.length === 0) {
      addRow()
    }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  const rows = givedata.value.filter((r) => Number(r.money) > 0)
  const moneys = rows.map((r) => Number(r.money))
  if (new Set(moneys).size !== moneys.length) {
    ElMessage.warning('充值档位金额不能重复')
    return
  }
  if (rows.some((r) => Number(r.give) < 0 || Number(r.give_score) < 0)) {
    ElMessage.warning('赠送数值不能为负数')
    return
  }
  saving.value = true
  try {
    await saveMoneyGivesetConfig({
      caninput: form.caninput,
      status: form.status,
      shuoming: form.shuoming,
      givedata: rows,
    })
    ElMessage.success('保存成功')
    fetchConfig()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchConfig()
})
</script>

<template>
  <div class="page-wrap">
    <div v-loading="loading" class="panel">
      <el-form label-width="110px" class="mg-form">
        <el-form-item label="赠送设置">
          <div class="tier-list">
            <div v-for="(row, index) in givedata" :key="index" class="tier-row">
              <span>充值满</span>
              <el-input v-model.number="row.money" type="number" class="w-110" />
              <span>元，赠送</span>
              <el-input v-model.number="row.give" type="number" class="w-110" />
              <span>元，赠送</span>
              <el-input v-model.number="row.give_score" type="number" class="w-110" />
              <span>积分</span>
              <el-button v-if="index === 0" size="small" @click="addRow">新增</el-button>
              <el-button size="small" @click="removeRow(index)">删除</el-button>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="用户输入金额">
          <el-radio-group v-model="form.caninput">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <div class="tip">关闭后用户不能输入金额，只能选择设置的充值赠送金额</div>
        </el-form-item>

        <el-form-item label="赠送状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <div class="tip">关闭后不再赠送</div>
        </el-form-item>

        <el-form-item label="充值说明">
          <SimpleRichEditor v-model="form.shuoming" class="rich" />
          <div class="tip mt-8">
            *赠送优惠券：打开营销-优惠券-添加-支付后赠送-开启-支付赠送场景-充值-设置金额
          </div>
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提交</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped>
.page-wrap {
  margin: 3px;
}
.panel {
  padding: 12px;
}
.mg-form :deep(.el-form-item) {
  margin-bottom: 14px;
}
.tier-list {
  width: 100%;
}
.tier-row {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.w-110 {
  width: 110px;
}
.tip {
  margin-top: 4px;
  color: #909399;
  font-size: 12px;
  line-height: 1.4;
}
.mt-8 {
  margin-top: 8px;
}
.rich {
  width: 640px;
}
</style>
