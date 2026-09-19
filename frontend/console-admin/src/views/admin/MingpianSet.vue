<script setup lang="ts">
/**
 * 名片 - 名片设置（mingpian_set 单行）
 * - GET /admin/mingpian/set 回显 / POST saveMingpianSet 保存
 * - 状态 radio；背景按钮色 radio（0默认/1跟随系统）；bgpics 多图上传（建议 700×480）
 * - createtj 创建权限：'-1' 所有人 + 会员等级多选
 * - field_list 联系信息字段表（isshow/name/icon/required）
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getMingpianSet, saveMingpianSet } from '../../api/mingpian'
import type { MingpianFieldItem } from '../../api/mingpian'
import { getMemberLevelList } from '../../api/member'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  status: 0,
  bgbtncolor: 0,
  bgpics: [] as string[],
  createtj: ['-1'] as string[],
  field_list: [] as MingpianFieldItem[],
})

/** 会员等级选项（createtj 勾选） */
const levelOptions = ref<{ id: number; name: string }[]>([])

/** '-1' 所有人勾中时等级项禁用 */
const allChecked = () => form.createtj.includes('-1')

function onAllChange(val: boolean | string | number) {
  if (val) {
    form.createtj = ['-1']
  } else {
    form.createtj = form.createtj.filter((v) => v !== '-1')
    if (!form.createtj.length) form.createtj = ['-1']
  }
}

function onLevelChange(checked: boolean) {
  if (checked) {
    // 勾选具体等级时移除「所有人」
    form.createtj = form.createtj.filter((v) => v !== '-1')
  } else if (!form.createtj.length) {
    form.createtj = ['-1']
  }
}

// ============ 选图（ImgIconPicker 统一选图） ============
const imgPickerVisible = ref(false)
const imgPickerMulti = ref(false)
let imgPickerSetter: ((urls: string[]) => void) | null = null
function openImgPicker(setter: (urls: string[]) => void, multi = false) {
  imgPickerMulti.value = multi
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  imgPickerSetter?.(urls)
  imgPickerSetter = null
}

function removeBgpic(index: number) {
  form.bgpics.splice(index, 1)
}

async function fetchSet() {
  loading.value = true
  try {
    const data = await getMingpianSet()
    form.status = Number(data.status ?? 0)
    form.bgbtncolor = Number(data.bgbtncolor ?? 0)
    form.bgpics = Array.isArray(data.bgpics) ? data.bgpics.filter((v) => !!v) : []
    const tj = Array.isArray(data.createtj) ? data.createtj.map(String) : []
    form.createtj = tj.length ? tj : ['-1']
    form.field_list = Array.isArray(data.field_list)
      ? data.field_list.map((f) => ({
          field: String(f.field ?? ''),
          name: String(f.name ?? ''),
          icon: String(f.icon ?? ''),
          isshow: Number(f.isshow ?? 1),
          required: Number(f.required ?? 0),
        }))
      : []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function fetchLevels() {
  try {
    const data = await getMemberLevelList()
    levelOptions.value = (data.list || []).map((l) => ({ id: l.id, name: l.name }))
  } catch {
    levelOptions.value = []
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveMingpianSet({
      status: form.status,
      bgbtncolor: form.bgbtncolor,
      bgpics: [...form.bgpics],
      createtj: [...form.createtj],
      field_list: form.field_list.map((f) => ({ ...f })),
    })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchSet()
  fetchLevels()
})
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <div class="set-header">
          <span>名片设置</span>
          <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
        </div>
      </template>

      <el-form label-width="160px" label-position="left" class="set-form">
        <el-form-item label="名片功能">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="背景按钮颜色">
          <el-radio-group v-model="form.bgbtncolor">
            <el-radio :value="0">默认</el-radio>
            <el-radio :value="1">跟随系统主题色</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="名片背景图">
          <div class="bgpic-line">
            <div v-for="(pic, idx) in form.bgpics" :key="idx" class="bgpic-item">
              <el-image :src="pic" class="bgpic-thumb" fit="cover" />
              <el-button link type="danger" class="bgpic-del" @click="removeBgpic(idx)">删除</el-button>
            </div>
            <div
              class="bgpic-add"
              @click="
                openImgPicker((urls) => {
                  urls.forEach((u) => {
                    if (!form.bgpics.includes(u)) form.bgpics.push(u)
                  })
                }, true)
              "
            >
              +
            </div>
          </div>
          <div class="form-tip">建议尺寸 700×480 像素，可上传多张供用户切换</div>
        </el-form-item>

        <el-form-item label="允许创建名片">
          <div class="createtj-line">
            <el-checkbox :model-value="allChecked()" @change="onAllChange">所有人</el-checkbox>
            <template v-for="lv in levelOptions" :key="lv.id">
              <el-checkbox
                :model-value="form.createtj.includes(String(lv.id))"
                :disabled="allChecked()"
                @change="(val: string | number | boolean) => onLevelChange(!!val)"
              >
                {{ lv.name }}
              </el-checkbox>
            </template>
          </div>
          <div class="form-tip">勾选「所有人」时全部会员可创建；也可按会员等级限定</div>
        </el-form-item>

        <el-divider content-position="left">联系信息字段配置</el-divider>
        <el-table :data="form.field_list" border stripe style="max-width: 860px">
          <el-table-column prop="field" label="字段标识" width="120" />
          <el-table-column label="显示" width="80">
            <template #default="{ row }">
              <el-switch v-model="row.isshow" :active-value="1" :inactive-value="0" />
            </template>
          </el-table-column>
          <el-table-column label="字段名称" min-width="160">
            <template #default="{ row }">
              <el-input v-model="row.name" placeholder="字段名称" maxlength="20" />
            </template>
          </el-table-column>
          <el-table-column label="图标" min-width="180">
            <template #default="{ row }">
              <div class="icon-cell">
                <el-image v-if="row.icon" :src="row.icon" class="icon-thumb" fit="contain" />
                <el-button size="small" @click="openImgPicker((urls) => {
                  if (urls[0]) row.icon = urls[0]
                })">{{ row.icon ? '更换' : '选择图片' }}</el-button>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="必填" width="80">
            <template #default="{ row }">
              <el-switch v-model="row.required" :active-value="1" :inactive-value="0" />
            </template>
          </el-table-column>
        </el-table>

        <el-form-item class="save-line">
          <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      :multi="imgPickerMulti"
      default-tab="library"
      @pick="onImgPick"
    />
  </div>
</template>

<style scoped>
.set-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.set-form {
  max-width: 960px;
}

.form-tip {
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
}

.bgpic-line {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.bgpic-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.bgpic-thumb {
  width: 105px;
  height: 72px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 4px;
}

.bgpic-add {
  width: 105px;
  height: 72px;
  border: 1px dashed var(--el-border-color);
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  color: #909399;
  cursor: pointer;
}

.bgpic-add:hover {
  border-color: var(--el-color-primary);
  color: var(--el-color-primary);
}

.createtj-line {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
}

.icon-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.icon-thumb {
  width: 28px;
  height: 28px;
}

.save-line {
  margin-top: 20px;
}
</style>
