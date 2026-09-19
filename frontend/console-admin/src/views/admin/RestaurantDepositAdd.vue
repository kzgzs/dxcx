<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import {
  addDepositOrder,
  getDepositForm,
  type DepositPositionOption,
} from '../../api/restaurantDeposit'

const formRef = ref<FormInstance>()
const submitting = ref(false)
const loading = ref(false)

const positions = ref<DepositPositionOption[]>([])
const syssetStatus = ref(0)
const syssetTime = ref('')

const form = reactive({
  name: '',
  num: 1,
  linkman: '',
  tel: '',
  pic: '',
  message: '',
  mid: undefined as number | undefined,
  position_id: undefined as number | undefined,
  number_id: undefined as number | undefined,
})

const rules: FormRules = {
  name: [{ required: true, message: '请填写物品名称', trigger: 'blur' }],
  num: [{ required: true, message: '请填写数量', trigger: 'blur' }],
  linkman: [{ required: true, message: '请填写联系人', trigger: 'blur' }],
  tel: [
    { required: true, message: '请填写联系电话', trigger: 'blur' },
    { pattern: /^1\d{10}$|^0\d{2,3}-?\d{7,8}$/, message: '电话格式不正确', trigger: 'blur' },
  ],
}

const numberOptions = computed(() => {
  const position = positions.value.find((p) => p.id === form.position_id)
  return position?.numbers ?? []
})

function handlePositionChange() {
  form.number_id = undefined
}

async function loadForm() {
  loading.value = true
  try {
    const res = await getDepositForm()
    positions.value = res?.positions ?? []
    syssetStatus.value = res?.sysset?.status ?? 0
    syssetTime.value = res?.sysset?.time ?? ''
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.pic = urls[0]
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await addDepositOrder({
        name: form.name,
        num: form.num,
        linkman: form.linkman,
        tel: form.tel,
        pic: form.pic,
        message: form.message,
        mid: form.mid ?? 0,
        position_id: form.position_id ?? 0,
        number_id: form.number_id ?? 0,
      })
      ElMessage.success('添加成功，已直接入账寄存中')
      formRef.value?.resetFields()
      form.pic = ''
      form.message = ''
      form.mid = undefined
      form.position_id = undefined
      form.number_id = undefined
      form.num = 1
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

onMounted(loadForm)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <div class="list-title">添加寄存</div>
      <el-alert
        v-if="syssetStatus !== 1"
        type="warning"
        :closable="false"
        title="寄存功能当前已关闭（寄存设置中未开启），后台仍可代录入；C端会员暂无法提交寄存申请"
        style="margin-bottom: 16px"
      />
      <el-alert
        v-if="syssetTime"
        type="info"
        :closable="false"
        :title="`寄存时限：${syssetTime}`"
        style="margin-bottom: 16px"
      />
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="110px"
        label-position="left"
        style="max-width: 640px"
      >
        <el-form-item label="物品名称" prop="name">
          <el-input v-model="form.name" maxlength="120" placeholder="如：茅台飞天 53度" />
        </el-form-item>
        <el-form-item label="数量" prop="num">
          <el-input-number v-model="form.num" :min="1" :max="9999" />
        </el-form-item>
        <el-form-item label="联系人" prop="linkman">
          <el-input v-model="form.linkman" maxlength="30" placeholder="取件联系人" />
        </el-form-item>
        <el-form-item label="联系电话" prop="tel">
          <el-input v-model="form.tel" maxlength="20" placeholder="取件联系电话" />
        </el-form-item>
        <el-form-item label="关联会员">
          <el-input-number v-model="form.mid" :min="0" :controls="false" placeholder="会员ID，可不填" style="width: 220px" />
          <span class="form-tip">填写后会员可在 C端我的存酒中看到该记录并自助取出</span>
        </el-form-item>
        <el-form-item label="物品照片">
          <div style="display: flex; align-items: center; gap: 12px">
            <el-image v-if="form.pic" :src="form.pic" style="width: 100px; height: 100px" fit="cover" />
            <el-button size="small" @click="openImgPicker">{{ form.pic ? '更换' : '选择图片' }}</el-button>
            <el-button v-if="form.pic" link type="danger" @click="form.pic = ''">移除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="存放位置">
          <el-select
            v-model="form.position_id"
            placeholder="选择位置（可不选）"
            clearable
            style="width: 200px"
            @change="handlePositionChange"
          >
            <el-option v-for="p in positions" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
          <el-select
            v-model="form.number_id"
            placeholder="选择编号（可不选）"
            clearable
            :disabled="!form.position_id"
            style="width: 200px; margin-left: 12px"
          >
            <el-option v-for="n in numberOptions" :key="n.id" :label="n.name" :value="n.id" />
          </el-select>
          <span class="form-tip">位置/编号在「寄存位置」菜单维护</span>
        </el-form-item>
        <el-form-item label="备注">
          <el-input
            v-model="form.message"
            type="textarea"
            :rows="2"
            maxlength="200"
            show-word-limit
            placeholder="客户备注（可选）"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="handleSubmit">保存寄存</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 选图弹窗（任务294） -->
    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
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
