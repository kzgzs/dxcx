<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getLuntanSysset, saveLuntanSysset } from '../../api/luntan'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  title: '',
  pic: '',
  picurl: '',
  sendtjAll: false, // -1 全部会员可发帖
  sendtjSubscribe: false, // 0 需关注公众号
  sendtjLevels: '', // 其余为等级 ID，逗号分隔
  sendcheck: 0,
  pingluncheck: 0,
  cansave: 0,
  status: 0,
})

function parseSendtj(arr: string[]) {
  form.sendtjAll = arr.includes('-1')
  form.sendtjSubscribe = arr.includes('0')
  form.sendtjLevels = arr.filter((v) => v !== '-1' && v !== '0').join(',')
}

function buildSendtj(): string[] {
  const out: string[] = []
  if (form.sendtjAll) out.push('-1')
  if (form.sendtjSubscribe) out.push('0')
  form.sendtjLevels
    .split(',')
    .map((v) => v.trim())
    .filter((v) => v !== '')
    .forEach((v) => {
      if (!out.includes(v)) out.push(v)
    })
  return out.length ? out : ['-1']
}

async function fetchSysset() {
  loading.value = true
  try {
    const res = await getLuntanSysset()
    form.title = res.title || ''
    form.pic = res.pic || ''
    form.picurl = res.picurl || ''
    form.sendcheck = res.sendcheck
    form.pingluncheck = res.pingluncheck
    form.cansave = res.cansave
    form.status = res.status
    parseSendtj(res.sendtj || [])
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveLuntanSysset({
      title: form.title,
      pic: form.pic,
      picurl: form.picurl,
      sendtj: buildSendtj(),
      sendcheck: form.sendcheck,
      pingluncheck: form.pingluncheck,
      cansave: form.cansave,
      status: form.status,
    })
    ElMessage.success('保存成功')
    fetchSysset()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(fetchSysset)
</script>

<template>
  <div>
    <el-card v-loading="loading" shadow="never" header="论坛系统设置">
      <el-form label-width="130px" style="max-width: 680px">
        <el-form-item label="功能总开关">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" />
        </el-form-item>
        <el-form-item label="论坛标题">
          <el-input v-model="form.title" placeholder="如：社区论坛" />
        </el-form-item>
        <el-form-item label="头部图片">
          <el-input v-model="form.pic" placeholder="图片地址" />
        </el-form-item>
        <el-form-item label="图片跳转链接">
          <el-input v-model="form.picurl" placeholder="点击头部图片跳转的地址" />
        </el-form-item>
        <el-form-item label="发帖权限">
          <div>
            <el-checkbox v-model="form.sendtjAll">全部会员可发帖</el-checkbox>
            <el-checkbox v-model="form.sendtjSubscribe">需关注公众号</el-checkbox>
            <div class="level-row">
              <span>指定等级 ID（逗号分隔）：</span>
              <el-input v-model="form.sendtjLevels" placeholder="如：1,2,3" style="width: 200px" />
            </div>
          </div>
        </el-form-item>
        <el-form-item label="发帖审核">
          <el-radio-group v-model="form.sendcheck">
            <el-radio :value="0">免审直发</el-radio>
            <el-radio :value="1">需管理员审核</el-radio>
            <el-radio :value="2">禁止发帖</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="评论审核">
          <el-switch v-model="form.pingluncheck" :active-value="1" :inactive-value="0" />
          <span class="tip">开启后评论需审核通过才显示</span>
        </el-form-item>
        <el-form-item label="允许收藏">
          <el-switch v-model="form.cansave" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.level-row {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
}

.tip {
  margin-left: 8px;
  color: #999;
  font-size: 12px;
}
</style>
