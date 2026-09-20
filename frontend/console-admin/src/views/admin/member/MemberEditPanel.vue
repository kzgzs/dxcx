<template>
  <div class="me-panel">
    <div class="ge-head">
      <span class="ge-title">会员编辑</span>
      <el-button class="ge-close" circle @click="emit('close')">
        <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
      </el-button>
    </div>

    <div v-loading="loading" class="me-body">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" class="me-form">
        <div class="me-section">基本信息</div>

        <el-form-item label="公众号openid">
          <el-input :model-value="form.mpopenid || '未授权公众号'" readonly style="width: 360px" />
        </el-form-item>

        <el-form-item label="头像">
          <div class="avatar-row">
            <el-button @click="imgPickerVisible = true">上传图片</el-button>
            <span class="me-hint">建议尺寸：150×150像素</span>
          </div>
          <div class="avatar-preview">
            <el-image v-if="form.headimg" :src="assetUrl(form.headimg)" fit="cover" class="avatar-img">
              <template #error><div class="avatar-empty">暂无图片</div></template>
            </el-image>
            <div v-else class="avatar-empty">暂无图片</div>
          </div>
        </el-form-item>

        <el-form-item label="昵称" prop="nickname">
          <el-input v-model="form.nickname" maxlength="50" style="width: 360px" placeholder="请输入昵称" />
        </el-form-item>

        <el-form-item label="姓名">
          <el-input v-model="form.realname" maxlength="30" style="width: 360px" placeholder="请输入真实姓名" />
        </el-form-item>

        <el-form-item label="手机号">
          <el-input v-model="form.tel" maxlength="11" style="width: 360px" placeholder="请输入手机号" />
        </el-form-item>

        <el-form-item label="身份证号">
          <el-input v-model="form.usercard" maxlength="18" style="width: 360px" placeholder="请输入身份证号" />
        </el-form-item>

        <el-form-item label="性别">
          <el-radio-group v-model="form.sex">
            <el-radio value="男">男</el-radio>
            <el-radio value="女">女</el-radio>
            <el-radio value="未知">未知</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="生日">
          <el-date-picker
            v-model="form.birthday"
            type="date"
            value-format="YYYY-MM-DD"
            placeholder="选择日期"
            style="width: 240px"
          />
        </el-form-item>

        <el-form-item label="等级">
          <el-select v-model="form.levelid" placeholder="请选择等级" clearable style="width: 240px">
            <el-option :value="0" label="无等级" />
            <el-option v-for="lv in levels" :key="lv.id" :label="lv.name" :value="lv.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="推荐人ID">
          <el-input-number v-model="form.pid" :min="0" :controls="false" style="width: 240px" placeholder="无则填0" />
        </el-form-item>

        <el-form-item label="支付密码">
          <el-tag :type="hasPaypwd ? 'warning' : 'info'" disable-transitions>
            {{ hasPaypwd ? '已设置支付密码' : '未设置支付密码' }}
          </el-tag>
          <el-button v-if="hasPaypwd" type="danger" link class="clear-pwd" @click="clearPwd = !clearPwd">
            {{ clearPwd ? '已选择清除（保存生效）' : '清除支付密码' }}
          </el-button>
        </el-form-item>

        <el-form-item label="备注">
          <el-input v-model="form.remark" type="textarea" :rows="3" maxlength="255" show-word-limit style="width: 500px" />
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">确认修改</el-button>
        </el-form-item>
      </el-form>
    </div>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import ImgIconPicker from '../../../components/ImgIconPicker.vue'
import { editMember, getMemberDetail, getMemberLevelList, type MemberEditDetail, type MemberEditForm } from '../../../api/member'
import { assetUrl } from '../../../api/upload'

const props = defineProps<{ id: number }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const loading = ref(false)
const saving = ref(false)
const imgPickerVisible = ref(false)
const hasPaypwd = ref(false)
const clearPwd = ref(false)
const formRef = ref<FormInstance>()
const levels = ref<{ id: number; name: string }[]>([])

const form = ref<MemberEditForm>({
  id: props.id,
  headimg: '',
  mpopenid: '',
  nickname: '',
  realname: '',
  tel: '',
  usercard: '',
  sex: '未知',
  birthday: '',
  levelid: 0,
  pid: 0,
  levelendtime: '',
  remark: '',
})

const rules: FormRules = {
  nickname: [{ required: true, message: '请输入昵称', trigger: 'blur' }],
}

function onImgPick(urls: string[]) {
  if (urls && urls[0]) {
    form.value.headimg = urls[0]
  }
  imgPickerVisible.value = false
}

async function load() {
  loading.value = true
  try {
    const [detail, lvRes] = await Promise.all([getMemberDetail(props.id), getMemberLevelList()])
    const d: MemberEditDetail = detail
    hasPaypwd.value = d.has_paypwd === 1
    form.value = {
      id: d.id,
      headimg: d.headimg || '',
      mpopenid: d.mpopenid || '',
      nickname: d.nickname || '',
      realname: d.realname || '',
      tel: d.tel || '',
      usercard: d.usercard || '',
      sex: d.sex || '未知',
      birthday: d.birthday || '',
      levelid: d.levelid || 0,
      pid: d.pid || 0,
      levelendtime: d.levelendtime ? '' : '',
      remark: d.remark || '',
    }
    levels.value = (lvRes.list || []).map((lv) => ({ id: lv.id, name: lv.name }))
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    saving.value = true
    try {
      await editMember({ ...form.value, clear_paypwd: clearPwd.value ? 1 : 0 })
      ElMessage.success('保存成功')
      emit('saved')
      emit('close')
    } finally {
      saving.value = false
    }
  })
}

onMounted(load)
</script>

<style scoped>
.me-panel {
  background: #fff;
  border-radius: 6px;
  padding: 12px 16px 20px;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.ge-title {
  font-size: 15px;
  font-weight: 600;
}
.ge-close {
  border: none;
  background: transparent;
  color: #909399;
}
.ge-close:hover {
  color: #409eff;
  background: transparent;
}
.me-section {
  font-weight: 600;
  margin: 4px 0 16px;
  padding-left: 8px;
  border-left: 3px solid #409eff;
}
.me-form :deep(.el-form-item) {
  margin-bottom: 16px;
}
.avatar-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}
.me-hint {
  color: #909399;
  font-size: 12px;
}
.avatar-preview {
  width: 150px;
  height: 150px;
  border: 1px solid #ebeef5;
  border-radius: 6px;
  overflow: hidden;
}
.avatar-img {
  width: 150px;
  height: 150px;
}
.avatar-empty {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
}
.clear-pwd {
  margin-left: 10px;
}
</style>
