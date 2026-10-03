<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getKechengSysset, saveKechengSysset } from '../../api/kecheng'

// 课程设置（对齐点大 扩展-知识付费-课程设置：详情页推荐/学习人数/iOS购买 + 详情页显示(佣金/升级优惠/文案)）
const loading = ref(false)
const submitting = ref(false)

// 升级优惠文字默认文案（与后端 KechengService::DEFAULT_UPGRADE_TEXT 对齐；[等级名称]/[优惠金额] 为C端动态占位符）
const DEFAULT_UPGRADE_TEXT = '升级到[等级名称]，优惠[优惠金额]元'

// 空串 / 历史 mojibake（含 ? 且无中文字符）时回退默认文案
function normalizeUpgradeText(v: unknown): string {
  const s = String(v ?? '').trim()
  if (!s || (s.includes('?') && !/[\u4e00-\u9fa5]/.test(s))) return DEFAULT_UPGRADE_TEXT
  return s
}

const form = reactive<Record<string, number | string>>({
  details_rec: 0,
  show_join_num: 1,
  ios_canbuy: 1,
  showcommission: 1,
  show_lvupsavemoney: 1,
  upgrade_text: '',
  show_buyed_kecheng_price: 1,
  send_score_num: 0,
  give_score_num: 0,
  lecturer_open: 0,
})

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getKechengSysset()
    Object.assign(form, {
      details_rec: Number(data.details_rec ?? 0),
      show_join_num: Number(data.show_join_num ?? 1),
      ios_canbuy: Number(data.ios_canbuy ?? 1),
      showcommission: Number(data.showcommission ?? 1),
      show_lvupsavemoney: Number(data.show_lvupsavemoney ?? 1),
      upgrade_text: normalizeUpgradeText(data.upgrade_text),
      show_buyed_kecheng_price: Number(data.show_buyed_kecheng_price ?? 1),
      send_score_num: Number(data.send_score_num ?? 0),
      give_score_num: Number(data.give_score_num ?? 0),
      lecturer_open: Number(data.lecturer_open ?? 0),
    })
  } catch {
    // 拦截器统一提示
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
    // 拦截器统一提示
  } finally {
    submitting.value = false
  }
}

onMounted(fetchConfig)
</script>

<template>
  <div v-loading="loading" class="kecheng-set">
    <div class="card">
      <div class="card-head"><i class="el-icon-setting" /> 课程设置</div>

      <div class="form-item">
        <span class="fi-label">详情页推荐：</span>
        <el-radio-group v-model="form.details_rec">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="fi-aux">开启详情页显示为您推荐</span>
      </div>

      <div class="form-item">
        <span class="fi-label">是否显示学习人数：</span>
        <el-radio-group v-model="form.show_join_num">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">不显示</el-radio>
        </el-radio-group>
        <span class="fi-aux">列表页和详情页是否显示学习人数</span>
      </div>

      <div class="form-item">
        <span class="fi-label">iOS端购买：</span>
        <el-radio-group v-model="form.ios_canbuy">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="fi-aux">关闭后前端点击购买会提示“iOS端不支持虚拟商品销售”</span>
      </div>

      <div class="fieldset-title">详情页显示</div>

      <div class="form-item">
        <span class="fi-label">是否显示佣金：</span>
        <el-radio-group v-model="form.showcommission">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">不显示</el-radio>
        </el-radio-group>
        <span class="fi-aux">开启后商品详情页显示分销佣金</span>
      </div>

      <div class="form-item">
        <span class="fi-label">是否显示升级优惠：</span>
        <el-radio-group v-model="form.show_lvupsavemoney">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">不显示</el-radio>
        </el-radio-group>
        <span class="fi-aux">商品详情页是否显示升级到下一级可节省多少元</span>
      </div>

      <div class="form-item">
        <span class="fi-label">升级优惠文字：</span>
        <el-input v-model="form.upgrade_text" class="w400" placeholder="升级到[等级名称]，优惠[优惠金额]元" />
        <span class="fi-aux">[等级名称],[优惠金额]可自动替换</span>
      </div>

      <div class="form-item">
        <el-button type="primary" :loading="submitting" @click="handleSave">提 交</el-button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.kecheng-set {
  margin: 3px;
}

.card {
  background: #fff;
  padding: 20px 24px;
}

.card-head {
  font-size: 14px;
  color: #333;
  margin-bottom: 18px;
}

.form-item {
  display: flex;
  align-items: center;
  line-height: 36px;
}

.fi-label {
  width: 150px;
  color: #333;
  font-size: 13px;
  white-space: nowrap;
}

.fi-aux {
  margin-left: 14px;
  color: #999;
  font-size: 12px;
}

.w400 {
  width: 400px;
}

.fieldset-title {
  border-top: 1px solid #e4e7ed;
  margin: 18px 0 12px;
  padding-top: 14px;
  color: #333;
  font-size: 13px;
}
</style>
