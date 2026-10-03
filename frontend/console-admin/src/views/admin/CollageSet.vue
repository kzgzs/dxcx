<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getCollageSet, saveCollageSet } from '../../api/collage'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  autoshdays: 7,
  comment: 1,
  comment_check: 0,
  showjd: 1,
  team_refund: 0,
  leveldiscount: 1,
  pics: '',
  // 保留原字段（不在页面展示，提交时原样回传）
  teamnum: 3,
  teamhour: 24,
  leadermoney: 0,
  timeset: '',
})

// 轮播图多图（逗号分隔字符串 -> 数组）
const picList = ref<string[]>([])
function syncPicList() {
  picList.value = form.pics ? form.pics.split(',').filter(Boolean) : []
}

async function fetchSet() {
  loading.value = true
  try {
    const data: any = await getCollageSet()
    Object.assign(form, {
      autoshdays: data.autoshdays ?? 7,
      comment: data.comment ?? 1,
      comment_check: data.comment_check ?? 0,
      showjd: data.showjd ?? 1,
      team_refund: data.team_refund ?? 0,
      leveldiscount: data.leveldiscount ?? 1,
      pics: data.pics ?? '',
      teamnum: data.teamnum ?? 3,
      teamhour: data.teamhour ?? 24,
      leadermoney: Number(data.leadermoney ?? 0),
      timeset: data.timeset ?? '',
    })
    syncPicList()
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveCollageSet({ ...form })
    ElMessage.success('保存成功')
  } catch {
    /* 拦截器统一提示 */
  } finally {
    saving.value = false
  }
}

// ============ 轮播图多图上传 ============
const imgPickerVisible = ref(false)
function openImgPicker() {
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  const merged = [...picList.value, ...urls]
  picList.value = merged
  form.pics = merged.join(',')
}
function removePic(idx: number) {
  picList.value.splice(idx, 1)
  form.pics = picList.value.join(',')
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <div class="page-title">系统设置</div>
      <el-form label-width="120px" label-position="left" class="set-form">
        <el-form-item label="轮播图">
          <div class="pics-row">
            <el-button size="small" @click="openImgPicker">批量上传</el-button>
            <span class="form-tip">建议尺寸：640×320像素</span>
          </div>
          <div class="pics-list">
            <div v-for="(p, i) in picList" :key="i" class="pic-box">
              <el-image :src="p" fit="cover" class="pic-img" />
              <span class="pic-del" @click="removePic(i)">×</span>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="自动收货天数">
          <el-input-number v-model="form.autoshdays" :min="1" :max="90" :precision="0" :step="1" />
          <span class="form-unit">天</span>
          <span class="form-tip">发货后多少天用户未确认收货自动完成收货（最小值为1）</span>
        </el-form-item>

        <el-form-item label="商品评价">
          <el-radio-group v-model="form.comment">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="评价审核">
          <el-radio-group v-model="form.comment_check">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="form-tip">关闭后用户的评价将直接显示,不需要后台审核</span>
        </el-form-item>

        <el-form-item label="进店及咨询">
          <el-radio-group v-model="form.showjd">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="form-tip">商品详情页是否显示进店及咨询</span>
        </el-form-item>

        <el-form-item label="团长发起订单退款">
          <el-radio-group v-model="form.team_refund">
            <el-radio :value="0">不解散团</el-radio>
            <el-radio :value="1">解散团</el-radio>
          </el-radio-group>
          <span class="form-tip">未拼团成功时，团长发起订单退款通过后，此团状态是保持不变，还是就此解散团</span>
        </el-form-item>

        <el-form-item label="会员等级折扣">
          <el-radio-group v-model="form.leveldiscount">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="form-tip">是否开启会员等级折扣</span>
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提交</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" multi @pick="onImgPick" />
  </div>
</template>

<style scoped>
.page-title {
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 16px;
  color: #303133;
}
.set-form {
  max-width: 720px;
}
.pics-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.pics-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}
.pic-box {
  position: relative;
  width: 100px;
  height: 50px;
}
.pic-img {
  width: 100%;
  height: 100%;
  border-radius: 4px;
}
.pic-del {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 16px;
  height: 16px;
  background: rgba(0, 0, 0, 0.6);
  color: #fff;
  border-radius: 50%;
  font-size: 12px;
  line-height: 16px;
  text-align: center;
  cursor: pointer;
}
.form-unit {
  margin-left: 8px;
  font-size: 13px;
  color: #606266;
}
.form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
</style>
