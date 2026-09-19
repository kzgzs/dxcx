<script setup lang="ts">
// 文章系统设置（批次5-A 任务194）：列表样式/标题字数/分享送积分/打赏设置
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getArticleSet, saveArticleSet } from '../../api/article'

const loading = ref(false)
const saving = ref(false)
const form = reactive({
  listtype: 1,
  title_size: 0,
  share_score: 0,
  share_score_max_perday: 0,
  reward_status: 0,
  moneyRows: [''] as string[],
  scoreRows: [''] as string[],
})

async function load() {
  loading.value = true
  try {
    const res = await getArticleSet()
    form.listtype = Number(res.listtype || 1)
    form.title_size = Number(res.title_size || 0)
    form.share_score = Number(res.share_score || 0)
    form.share_score_max_perday = Number(res.share_score_max_perday || 0)
    form.reward_status = Number(res.reward_status || 0)
    const money = String(res.money_data || '').split(',').filter((v) => v.trim() !== '')
    const score = String(res.score_data || '').split(',').filter((v) => v.trim() !== '')
    form.moneyRows = money.length ? money : ['']
    form.scoreRows = score.length ? score : ['']
  } finally {
    loading.value = false
  }
}

function addMoneyRow() {
  form.moneyRows.push('')
}
function removeMoneyRow(i: number) {
  form.moneyRows.splice(i, 1)
  if (!form.moneyRows.length) form.moneyRows = ['']
}
function addScoreRow() {
  form.scoreRows.push('')
}
function removeScoreRow(i: number) {
  form.scoreRows.splice(i, 1)
  if (!form.scoreRows.length) form.scoreRows = ['']
}

async function handleSave() {
  saving.value = true
  try {
    await saveArticleSet({
      listtype: form.listtype,
      title_size: form.title_size,
      share_score: form.share_score,
      share_score_max_perday: form.share_score_max_perday,
      reward_status: form.reward_status,
      money_data: form.moneyRows.filter((v) => v.trim() !== '').join(','),
      score_data: form.scoreRows.filter((v) => v.trim() !== '').join(','),
    })
    ElMessage.success('保存成功')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="article-set" v-loading="loading">
    <el-form label-width="140px" style="max-width: 760px">
      <el-divider content-position="left">列表展示</el-divider>
      <el-form-item label="列表样式">
        <el-radio-group v-model="form.listtype">
          <el-radio :value="1">样式一（大图）</el-radio>
          <el-radio :value="2">样式二（小图）</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="标题显示字数">
        <el-input-number v-model="form.title_size" :min="0" :max="200" />
        <span class="tip">0 表示不限制</span>
      </el-form-item>

      <el-divider content-position="left">分享送积分</el-divider>
      <el-form-item label="每次分享赠送">
        <el-input-number v-model="form.share_score" :min="0" />
        <span class="tip">会员转发文章获得的积分</span>
      </el-form-item>
      <el-form-item label="每日上限次数">
        <el-input-number v-model="form.share_score_max_perday" :min="0" />
        <span class="tip">每日可获得赠送的最大次数，0 表示不赠送</span>
      </el-form-item>

      <el-divider content-position="left">打赏设置</el-divider>
      <el-form-item label="打赏功能">
        <el-switch v-model="form.reward_status" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" />
        <span class="tip">开启后，文章配置了收款会员即可被打赏</span>
      </el-form-item>
      <el-form-item label="现金打赏档位">
        <div class="rows">
          <div v-for="(_, i) in form.moneyRows" :key="`m${i}`" class="row">
            <el-input v-model="form.moneyRows[i]" placeholder="金额（元）" style="width: 160px" />
            <el-button link type="danger" @click="removeMoneyRow(i)">删除</el-button>
          </div>
          <el-button size="small" @click="addMoneyRow">+ 添加档位</el-button>
        </div>
      </el-form-item>
      <el-form-item label="积分打赏档位">
        <div class="rows">
          <div v-for="(_, i) in form.scoreRows" :key="`s${i}`" class="row">
            <el-input v-model="form.scoreRows[i]" placeholder="积分数" style="width: 160px" />
            <el-button link type="danger" @click="removeScoreRow(i)">删除</el-button>
          </div>
          <el-button size="small" @click="addScoreRow">+ 添加档位</el-button>
        </div>
      </el-form-item>

      <el-form-item>
        <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.article-set {
  padding: 16px;
}
.tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
.rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.row {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
