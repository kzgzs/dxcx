<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getKechengSysset, saveKechengSysset } from '../../api/kecheng'

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  details_rec: 1,
  showcommission: 0,
  show_lvupsavemoney: 0,
  show_join_num: 1,
  show_buyed_kecheng_price: 1,
  upgrade_text: '',
  ios_canbuy: 1,
  send_score_num: 0,
  give_score_num: 0,
  lecturer_open: 0,
})

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getKechengSysset()
    Object.assign(form, {
      details_rec: Number(data.details_rec ?? 1),
      showcommission: Number(data.showcommission ?? 0),
      show_lvupsavemoney: Number(data.show_lvupsavemoney ?? 0),
      show_join_num: Number(data.show_join_num ?? 1),
      show_buyed_kecheng_price: Number(data.show_buyed_kecheng_price ?? 1),
      upgrade_text: String(data.upgrade_text ?? ''),
      ios_canbuy: Number(data.ios_canbuy ?? 1),
      send_score_num: Number(data.send_score_num ?? 0),
      give_score_num: Number(data.give_score_num ?? 0),
      lecturer_open: Number(data.lecturer_open ?? 0),
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await saveKechengSysset({ ...form })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

onMounted(fetchConfig)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <div class="list-title">课程设置</div>
      <el-form label-width="170px" label-position="left" style="max-width: 640px">
        <el-form-item label="详情页显示推荐课程">
          <el-switch v-model="form.details_rec" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="显示分销佣金">
          <el-switch v-model="form.showcommission" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后课程详情向可分销会员展示预计返佣</span>
        </el-form-item>
        <el-form-item label="显示升级省钱金额">
          <el-switch v-model="form.show_lvupsavemoney" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="显示学习人数">
          <el-switch v-model="form.show_join_num" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="显示已购课程价格">
          <el-switch v-model="form.show_buyed_kecheng_price" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="升级提示文案">
          <el-input
            v-model="form.upgrade_text"
            type="textarea"
            :rows="3"
            placeholder="引导会员升级的提示文案（可选）"
          />
        </el-form-item>
        <el-form-item label="iOS 允许购买">
          <el-switch v-model="form.ios_canbuy" :active-value="1" :inactive-value="0" />
          <span class="form-tip">关闭后 iOS 端不展示支付入口（规避苹果内购审核）</span>
        </el-form-item>
        <el-form-item label="答题积分仅首次发放">
          <el-switch v-model="form.send_score_num" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后同一题目仅首次答对计分，重复答对不再计分</span>
        </el-form-item>
        <el-form-item label="答题送积分仅首次">
          <el-switch v-model="form.give_score_num" :active-value="0" :inactive-value="1" />
          <span class="form-tip">开启（首次）后同一题目答对送积分仅发放一次，关闭则每次答对都发放</span>
        </el-form-item>
        <el-form-item label="讲师体系">
          <el-switch v-model="form.lecturer_open" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后 C 端可申请讲师、讲师可自建课程，后台可审核</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="handleSave">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.list-title {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 20px;
}

.form-tip {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}
</style>
