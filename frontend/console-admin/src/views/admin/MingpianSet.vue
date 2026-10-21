<script setup lang="ts">
/**
 * 名片 - 名片系统设置（mingpian_set 单行，对标 canzhao app/view/mingpian/set.html）
 * - 状态 radio 开启/关闭；系统背景图批量上传（建议 700×480，缩略图带×删除）
 * - 背景|按钮颜色 radio 默认/跟随系统（默认则保持原样，头部背景及底部按钮是蓝色；跟随系统则颜色变为系统颜色）
 * - 创建条件：所有人(-1) + 会员等级多选（createtj 逗号 implode 保存）
 * - 联系信息表：状态(开启|关闭)/字段名称(固定7字段只读、field1-5 可编辑)/图标(缩略+上传)/是否必填(是|否)
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getMingpianSet, saveMingpianSet } from '../../api/mingpian'
import type { MingpianFieldItem } from '../../api/mingpian'
import { getMemberLevelList } from '../../api/member'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

/** 固定字段：名称只读（口径同 canzhao set.html in_array 判断） */
const FIXED_FIELDS = ['tel', 'weixin', 'address', 'email', 'douyin', 'weibo', 'toutiao']

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

// ============ 选图（ImgIconPicker 统一选图；背景图批量、图标单张） ============
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
  <div v-loading="loading" class="mp-set-page">
    <div class="page-title"><span class="title-bar">≡</span>名片系统设置</div>

    <el-card shadow="never">
      <el-form label-width="110px" label-position="left" class="set-form">
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="系统背景图">
          <div class="bgpic-block">
            <div class="bgpic-line">
              <div v-for="(pic, idx) in form.bgpics" :key="idx" class="bgpic-item">
                <el-image :src="pic" class="bgpic-thumb" fit="cover" />
                <span class="bgpic-close" title="删除" @click="removeBgpic(idx)">&times;</span>
              </div>
            </div>
            <div class="bgpic-actions">
              <el-button
                @click="
                  openImgPicker((urls) => {
                    urls.forEach((u) => {
                      if (!form.bgpics.includes(u)) form.bgpics.push(u)
                    })
                  }, true)
                "
              >批量上传</el-button>
              <span class="form-tip">建议尺寸：700×480像素</span>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="背景|按钮颜色">
          <div class="color-line">
            <el-radio-group v-model="form.bgbtncolor">
              <el-radio :value="0">默认</el-radio>
              <el-radio :value="1">跟随系统</el-radio>
            </el-radio-group>
            <span class="form-tip">默认则保持原样，头部背景及底部按钮是蓝色；跟随系统则颜色变为系统颜色</span>
          </div>
        </el-form-item>

        <el-form-item label="创建条件">
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
        </el-form-item>

        <el-form-item label="联系信息">
          <el-table :data="form.field_list" border :header-cell-style="{ background: '#e6e6e6' }" class="field-table">
            <el-table-column label="状态" width="130" align="left" header-align="left">
              <template #default="{ row }">
                <el-switch v-model="row.isshow" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" />
              </template>
            </el-table-column>
            <el-table-column label="字段名称" min-width="180" align="left" header-align="left">
              <template #default="{ row }">
                <span v-if="FIXED_FIELDS.includes(row.field)">{{ row.name }}</span>
                <el-input v-else v-model="row.name" placeholder="请填写字段名称" maxlength="20" />
              </template>
            </el-table-column>
            <el-table-column label="图标" min-width="170" align="left" header-align="left">
              <template #default="{ row }">
                <div class="icon-cell">
                  <el-image v-if="row.icon" :src="row.icon" class="icon-thumb" fit="contain" />
                  <el-button size="small" @click="openImgPicker((urls) => {
                    if (urls[0]) row.icon = urls[0]
                  })">上传</el-button>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="是否必填" width="120" align="left" header-align="left">
              <template #default="{ row }">
                <el-switch v-model="row.required" :active-value="1" :inactive-value="0" active-text="是" inactive-text="否" />
              </template>
            </el-table-column>
            <template #empty>暂无数据</template>
          </el-table>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">提 交</el-button>
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
.mp-set-page {
  padding: 3px;
}
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding: 4px 2px 8px;
}
.title-bar {
  margin-right: 4px;
}
.set-form {
  max-width: 960px;
}
.set-form :deep(.el-form-item) {
  margin-bottom: 12px;
}
.form-tip {
  color: #909399;
  font-size: 12px;
}
.bgpic-block {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.bgpic-line {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  align-items: center;
}
.bgpic-item {
  position: relative;
  width: 100px;
  height: 50px;
}
.bgpic-thumb {
  width: 100px;
  height: 50px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 2px;
}
.bgpic-close {
  position: absolute;
  top: -7px;
  right: -7px;
  width: 16px;
  height: 16px;
  line-height: 14px;
  text-align: center;
  border-radius: 50%;
  background: #f56c6c;
  color: #fff;
  font-size: 12px;
  cursor: pointer;
}
.bgpic-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
.color-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: nowrap;
  white-space: nowrap;
}
.createtj-line {
  display: flex;
  flex-wrap: wrap;
  gap: 3px 12px;
}
.field-table {
  max-width: 700px;
}
.icon-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.icon-thumb {
  width: 34px;
  height: 34px;
}
</style>
