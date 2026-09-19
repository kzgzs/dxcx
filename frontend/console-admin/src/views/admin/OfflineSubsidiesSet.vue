<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getOfflineSubsidiesSet, saveOfflineSubsidiesSet } from '../../api/offlineSubsidies'
import { getMemberLevelList } from '../../api/member'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const submitting = ref(false)
const updatetime = ref(0)

/** 奖励场景枚举（口径对照后端 SCENE_TEXT） */
const SCENE_OPTIONS = [
  { value: 'shop', label: '商城订单' },
  { value: 'maidan', label: '买单订单' },
  { value: 'levelup', label: '升级订单' },
]

interface LevelOption {
  id: number
  name: string
}

const levelOptions = ref<LevelOption[]>([])

const form = reactive({
  status: 0,
  effective_ratio: '' as number | string,
  cycle: 0,
  fwtype: 0,
  base: 0,
  productids: '',
  scene: [] as string[],
  upgrade_levelid: '',
  upgrade_ratio: '' as number | string,
  upgrade_text: '',
  upgrade_num: 0,
  upgrade_auto_uplevelid: 0,
  pass_levelid: '',
  pass_ratio: '' as number | string,
  pass_text: '',
  recommend_levelid: '',
  recommend_ratio: '' as number | string,
  recommend_text: '',
  lecturer_levelid: '',
  lecturer_ratio: '' as number | string,
  lecturer_text: '',
  lovefund_levelid: '',
  lovefund_ratio: '' as number | string,
  lovefund_text: '',
})

function numOf(v: unknown): number {
  const n = Number(v)
  return Number.isFinite(n) ? n : 0
}

async function fetchLevels() {
  try {
    const res = await getMemberLevelList()
    levelOptions.value = (res?.list || []).map((v) => ({
      id: v.id,
      name: v.name,
    }))
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function fetchInfo() {
  loading.value = true
  try {
    const info = await getOfflineSubsidiesSet()
    form.status = numOf(info.status)
    form.effective_ratio = info.effective_ratio ?? ''
    form.cycle = numOf(info.cycle)
    form.fwtype = numOf(info.fwtype)
    form.base = numOf(info.base)
    form.productids = info.productids || ''
    form.scene = info.scene ? String(info.scene).split(',').filter(Boolean) : []
    form.upgrade_levelid = info.upgrade_levelid || ''
    form.upgrade_ratio = info.upgrade_ratio ?? ''
    form.upgrade_text = info.upgrade_text || ''
    form.upgrade_num = numOf(info.upgrade_num)
    form.upgrade_auto_uplevelid = numOf(info.upgrade_auto_uplevelid)
    form.pass_levelid = info.pass_levelid || ''
    form.pass_ratio = info.pass_ratio ?? ''
    form.pass_text = info.pass_text || ''
    form.recommend_levelid = info.recommend_levelid || ''
    form.recommend_ratio = info.recommend_ratio ?? ''
    form.recommend_text = info.recommend_text || ''
    form.lecturer_levelid = info.lecturer_levelid || ''
    form.lecturer_ratio = info.lecturer_ratio ?? ''
    form.lecturer_text = info.lecturer_text || ''
    form.lovefund_levelid = info.lovefund_levelid || ''
    form.lovefund_ratio = info.lovefund_ratio ?? ''
    form.lovefund_text = info.lovefund_text || ''
    updatetime.value = numOf(info.updatetime)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await saveOfflineSubsidiesSet({
      status: form.status,
      effective_ratio: form.effective_ratio === '' ? 0 : form.effective_ratio,
      cycle: form.cycle,
      fwtype: form.fwtype,
      base: form.base,
      productids: form.productids,
      scene: form.scene.join(','),
      upgrade_levelid: form.upgrade_levelid,
      upgrade_ratio: form.upgrade_ratio === '' ? 0 : form.upgrade_ratio,
      upgrade_text: form.upgrade_text,
      upgrade_num: form.upgrade_num,
      upgrade_auto_uplevelid: form.upgrade_auto_uplevelid,
      pass_levelid: form.pass_levelid,
      pass_ratio: form.pass_ratio === '' ? 0 : form.pass_ratio,
      pass_text: form.pass_text,
      recommend_levelid: form.recommend_levelid,
      recommend_ratio: form.recommend_ratio === '' ? 0 : form.recommend_ratio,
      recommend_text: form.recommend_text,
      lecturer_levelid: form.lecturer_levelid,
      lecturer_ratio: form.lecturer_ratio === '' ? 0 : form.lecturer_ratio,
      lecturer_text: form.lecturer_text,
      lovefund_levelid: form.lovefund_levelid,
      lovefund_ratio: form.lovefund_ratio === '' ? 0 : form.lovefund_ratio,
      lovefund_text: form.lovefund_text,
    })
    ElMessage.success('保存成功')
    fetchInfo()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  fetchLevels()
  fetchInfo()
})
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>线下补助配置</span>
          <span v-if="updatetime" class="text-muted">最近更新：{{ formatTime(updatetime) }}</span>
        </div>
      </template>

      <el-form label-width="140px" label-position="left" style="max-width: 720px">
        <!-- 基础设置 -->
        <el-divider content-position="left">基础设置</el-divider>
        <el-form-item label="启用状态">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
            active-text="开启"
            inactive-text="关闭"
          />
        </el-form-item>
        <el-form-item label="生效比例（%）">
          <el-input-number
            v-model="form.effective_ratio"
            :min="0"
            :max="100"
            :precision="2"
            :controls="false"
          />
          <span class="form-tip">补助金额按订单金额的该比例核算</span>
        </el-form-item>
        <el-form-item label="发放周期（天）">
          <el-input-number v-model="form.cycle" :min="0" :controls="false" />
          <span class="form-tip">订单完成后延迟发放的天数，0 为即时发放</span>
        </el-form-item>
        <el-form-item label="发放方式">
          <el-radio-group v-model="form.fwtype">
            <el-radio :value="0">积分</el-radio>
            <el-radio :value="1">佣金</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="单笔补助基数">
          <el-input-number v-model="form.base" :min="0" :precision="2" :controls="false" />
        </el-form-item>
        <el-form-item label="奖励场景">
          <el-checkbox-group v-model="form.scene">
            <el-checkbox v-for="opt in SCENE_OPTIONS" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </el-checkbox>
          </el-checkbox-group>
        </el-form-item>
        <el-form-item label="指定商品ID">
          <el-input
            v-model="form.productids"
            placeholder="多个商品ID用英文逗号分隔，留空表示全部商品"
            clearable
          />
        </el-form-item>

        <!-- 升级奖励 -->
        <el-divider content-position="left">升级奖励（佣金）</el-divider>
        <el-form-item label="适用会员等级">
          <el-select v-model="form.upgrade_levelid" multiple filterable placeholder="全部等级" clearable style="width: 320px">
            <el-option v-for="lv in levelOptions" :key="lv.id" :label="lv.name" :value="String(lv.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="奖励比例（%）">
          <el-input-number
            v-model="form.upgrade_ratio"
            :min="0"
            :max="100"
            :precision="2"
            :controls="false"
          />
        </el-form-item>
        <el-form-item label="奖励文案">
          <el-input v-model="form.upgrade_text" placeholder="展示给会员的奖励说明" clearable />
        </el-form-item>
        <el-form-item label="升级所需笔数">
          <el-input-number v-model="form.upgrade_num" :min="0" :controls="false" />
        </el-form-item>
        <el-form-item label="自动升级目标等级">
          <el-select v-model="form.upgrade_auto_uplevelid" filterable placeholder="不自动升级" clearable style="width: 320px">
            <el-option v-for="lv in levelOptions" :key="lv.id" :label="lv.name" :value="lv.id" />
          </el-select>
        </el-form-item>

        <!-- 过关奖励 -->
        <el-divider content-position="left">过关奖励</el-divider>
        <el-form-item label="适用会员等级">
          <el-select v-model="form.pass_levelid" multiple filterable placeholder="全部等级" clearable style="width: 320px">
            <el-option v-for="lv in levelOptions" :key="lv.id" :label="lv.name" :value="String(lv.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="奖励比例（%）">
          <el-input-number
            v-model="form.pass_ratio"
            :min="0"
            :max="100"
            :precision="2"
            :controls="false"
          />
        </el-form-item>
        <el-form-item label="奖励文案">
          <el-input v-model="form.pass_text" placeholder="展示给会员的奖励说明" clearable />
        </el-form-item>

        <!-- 推荐奖励 -->
        <el-divider content-position="left">推荐奖励</el-divider>
        <el-form-item label="适用会员等级">
          <el-select v-model="form.recommend_levelid" multiple filterable placeholder="全部等级" clearable style="width: 320px">
            <el-option v-for="lv in levelOptions" :key="lv.id" :label="lv.name" :value="String(lv.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="奖励比例（%）">
          <el-input-number
            v-model="form.recommend_ratio"
            :min="0"
            :max="100"
            :precision="2"
            :controls="false"
          />
        </el-form-item>
        <el-form-item label="奖励文案">
          <el-input v-model="form.recommend_text" placeholder="展示给会员的奖励说明" clearable />
        </el-form-item>

        <!-- 讲师奖励 -->
        <el-divider content-position="left">讲师奖励</el-divider>
        <el-form-item label="适用会员等级">
          <el-select v-model="form.lecturer_levelid" multiple filterable placeholder="全部等级" clearable style="width: 320px">
            <el-option v-for="lv in levelOptions" :key="lv.id" :label="lv.name" :value="String(lv.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="奖励比例（%）">
          <el-input-number
            v-model="form.lecturer_ratio"
            :min="0"
            :max="100"
            :precision="2"
            :controls="false"
          />
        </el-form-item>
        <el-form-item label="奖励文案">
          <el-input v-model="form.lecturer_text" placeholder="展示给会员的奖励说明" clearable />
        </el-form-item>

        <!-- 爱心基金 -->
        <el-divider content-position="left">爱心基金</el-divider>
        <el-form-item label="适用会员等级">
          <el-select v-model="form.lovefund_levelid" multiple filterable placeholder="全部等级" clearable style="width: 320px">
            <el-option v-for="lv in levelOptions" :key="lv.id" :label="lv.name" :value="String(lv.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="奖励比例（%）">
          <el-input-number
            v-model="form.lovefund_ratio"
            :min="0"
            :max="100"
            :precision="2"
            :controls="false"
          />
        </el-form-item>
        <el-form-item label="奖励文案">
          <el-input v-model="form.lovefund_text" placeholder="展示给会员的奖励说明" clearable />
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.text-muted {
  color: #909399;
  font-size: 12px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
