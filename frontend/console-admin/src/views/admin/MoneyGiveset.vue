<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Delete } from '@element-plus/icons-vue'
import {
  getMoneyGivesetConfig,
  saveMoneyGivesetConfig,
  type GiveRow,
} from '../../api/moneyGiveset'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  status: 0,
  caninput: 1,
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
    form.status = d.status || 0
    form.caninput = d.caninput ?? 1
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
  const rows = givedata.value.filter((r) => r.money > 0)
  const moneys = rows.map((r) => Number(r.money.toFixed(2)))
  if (new Set(moneys).size !== moneys.length) {
    ElMessage.warning('充值档位金额不能重复')
    return
  }
  if (rows.some((r) => r.give < 0 || r.give_score < 0)) {
    ElMessage.warning('赠送数值不能为负数')
    return
  }
  saving.value = true
  try {
    await saveMoneyGivesetConfig({
      ...form,
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
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span>充值赠送设置</span>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </div>
    </template>

    <el-form label-width="140px" style="max-width: 860px">
      <el-form-item label="充值功能状态">
        <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
      </el-form-item>
      <el-form-item label="自定义金额">
        <el-switch v-model="form.caninput" :active-value="1" :inactive-value="0" />
        <span class="form-tip">关闭后会员只能按档位金额充值</span>
      </el-form-item>
      <el-form-item label="充值说明">
        <el-input
          v-model="form.shuoming"
          type="textarea"
          :rows="2"
          maxlength="500"
          placeholder="C 端充值页展示说明"
        />
      </el-form-item>
      <el-form-item label="充值档位">
        <el-table :data="givedata" border size="small" style="width: 100%">
          <el-table-column label="充值金额（元）" min-width="160">
            <template #default="{ row }">
              <el-input-number v-model="row.money" :min="0" :precision="2" size="small" />
            </template>
          </el-table-column>
          <el-table-column label="赠送余额（元）" min-width="160">
            <template #default="{ row }">
              <el-input-number v-model="row.give" :min="0" :precision="2" size="small" />
            </template>
          </el-table-column>
          <el-table-column label="赠送积分" min-width="140">
            <template #default="{ row }">
              <el-input-number v-model="row.give_score" :min="0" size="small" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="70" align="center">
            <template #default="{ $index }">
              <el-button link type="danger" :icon="Delete" @click="removeRow($index)" />
            </template>
          </el-table-column>
        </el-table>
        <el-button :icon="Plus" style="margin-top: 8px" @click="addRow">添加档位</el-button>
        <span class="form-tip">支付成功按充值金额匹配满足条件的最高档发放赠送</span>
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
.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
