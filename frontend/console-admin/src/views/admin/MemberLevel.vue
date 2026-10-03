<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteMemberLevel,
  getMemberLevelDetail,
  getMemberLevelList,
  saveMemberLevel,
} from '../../api/member'
import { assetUrl } from '../../api/upload'
import type { MemberLevelItem, MemberLevelSaveParams } from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'
import { CircleClose } from '@element-plus/icons-vue'

// ============ 列表 / 平铺切换 ============
const loading = ref(false)
const tableData = ref<MemberLevelItem[]>([])
const viewMode = ref<'list' | 'form'>('list')

async function fetchList() {
  loading.value = true
  try {
    const data = await getMemberLevelList()
    tableData.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// ============ 表单 ============
interface LevelForm {
  id: number
  name: string
  icon: string
  sort: number
  discount: number
  isdefault: number
  maxnum: number
  // 分销配置组
  can_agent: number
  agent_rule: number
  commissiontype: number
  commission1: number
  commission2: number
  commission3: number
  commission_parent: number
  // 推荐积分
  score1: number
  score2: number
  score3: number
  scoremax: number
  // 自己拿一级提成 / 我的团队权限
  commission1own: number
  team_showtel: number
  team_givemoney: number
  team_givescore: number
  // 特权说明
  explain: string
}

function buildEmptyForm(): LevelForm {
  return {
    id: 0,
    name: '',
    icon: '',
    sort: 1,
    discount: 10,
    isdefault: 0,
    maxnum: 0,
    can_agent: 0,
    agent_rule: 0,
    commissiontype: 0,
    commission1: 0,
    commission2: 0,
    commission3: 0,
    commission_parent: 0,
    score1: 0,
    score2: 0,
    score3: 0,
    scoremax: 0,
    commission1own: 0,
    team_showtel: 0,
    team_givemoney: 0,
    team_givescore: 0,
    explain: '',
  }
}

const isEdit = ref(false)
const detailLoading = ref(false)
const activeCollapse = ref<string[]>(['base', 'fenxiao', 'other'])
const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive<LevelForm>(buildEmptyForm())

// 等级图标选择器
const iconPickerVisible = ref(false)
function onIconPick(urls: string[]) {
  form.icon = urls[0] || ''
  iconPickerVisible.value = false
}

const rules: FormRules = {
  name: [{ required: true, message: '请输入等级名称', trigger: 'blur' }],
  discount: [
    {
      validator: (_rule, value, callback) => {
        const n = Number(value)
        if (!Number.isFinite(n) || n <= 0 || n > 10) {
          callback(new Error('折扣须为 0-10 之间的数字（10为无折扣）'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
}

function backToList() {
  viewMode.value = 'list'
}

async function openAdd() {
  isEdit.value = false
  activeCollapse.value = ['base', 'fenxiao', 'other']
  Object.assign(form, buildEmptyForm())
  form.sort = (tableData.value.length || 0) + 1
  viewMode.value = 'form'
}

async function openEdit(row: MemberLevelItem) {
  isEdit.value = true
  activeCollapse.value = ['base', 'fenxiao', 'other']
  Object.assign(form, buildEmptyForm())
  viewMode.value = 'form'
  detailLoading.value = true
  try {
    const d = await getMemberLevelDetail(row.id)
    Object.assign(form, buildEmptyForm(), {
      id: d.id,
      name: d.name,
      icon: d.icon,
      sort: d.sort,
      discount: d.discount,
      isdefault: d.isdefault,
      maxnum: d.maxnum,
      can_agent: d.can_agent,
      agent_rule: d.agent_rule,
      commissiontype: d.commissiontype,
      commission1: d.commission1,
      commission2: d.commission2,
      commission3: d.commission3,
      commission_parent: d.commission_parent,
      score1: d.score1,
      score2: d.score2,
      score3: d.score3,
      scoremax: d.scoremax,
      commission1own: d.commission1own,
      team_showtel: d.team_showtel,
      team_givemoney: d.team_givemoney,
      team_givescore: d.team_givescore,
      explain: d.explain || '',
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    detailLoading.value = false
  }
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      const payload: MemberLevelSaveParams = {
        id: form.id,
        name: form.name,
        icon: form.icon,
        sort: form.sort,
        discount: Number(form.discount),
        isdefault: form.isdefault,
        maxnum: form.maxnum || 0,
        can_agent: form.can_agent,
        agent_rule: form.agent_rule,
        commissiontype: form.commissiontype,
        commission1: Number(form.commission1) || 0,
        commission2: Number(form.commission2) || 0,
        commission3: Number(form.commission3) || 0,
        commission_parent: Number(form.commission_parent) || 0,
        score1: Number(form.score1) || 0,
        score2: Number(form.score2) || 0,
        score3: Number(form.score3) || 0,
        scoremax: Number(form.scoremax) || 0,
        commission1own: form.commission1own,
        team_showtel: form.team_showtel,
        team_givemoney: form.team_givemoney,
        team_givescore: form.team_givescore,
        explain: form.explain || '',
      }
      await saveMemberLevel(payload)
      ElMessage.success('保存成功')
      viewMode.value = 'list'
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

// ============ 删除 ============
function handleDelete(row: MemberLevelItem) {
  ElMessageBox.confirm(`确定要删除等级「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteMemberLevel(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 提成单位：0百分比(%) 1固定金额(元)
function commissionUnit(): string {
  return form.commissiontype === 1 ? '元' : '%'
}

function moneyText(v: number): string {
  return `￥${Number(v || 0).toFixed(2)}`
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- ============ 列表 ============ -->
    <el-card v-if="viewMode === 'list'" shadow="never">
      <div class="toolbar">
        <el-button type="success" @click="openAdd">添加</el-button>
      </div>

      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="60" class-name="compact-col" />
        <el-table-column label="等级名称" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.name }}
            <span v-if="row.isdefault === 1" class="default-tag">[默认等级]</span>
          </template>
        </el-table-column>
        <el-table-column prop="applytj" label="申请条件" min-width="150" show-overflow-tooltip />
        <el-table-column label="申请加盟费" width="110" class-name="compact-col">
          <template #default="{ row }">{{ moneyText(row.apply_paymoney) }}</template>
        </el-table-column>
        <el-table-column prop="uptj" label="升级条件" min-width="240" show-overflow-tooltip />
        <el-table-column prop="commission" label="提成比例" min-width="140" show-overflow-tooltip />
        <el-table-column label="操作" width="110" class-name="compact-col">
          <template #default="{ row }">
            <div class="op-wrap">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button v-if="row.isdefault !== 1" link type="danger" @click="handleDelete(row)">
                删除
              </el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- ============ 新增 / 编辑（平铺主区域，右上角 X 关闭） ============ -->
    <div v-else class="goods-edit" v-loading="detailLoading">
      <div class="ge-head">
        <el-button class="ge-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor">
            <path
              d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.157-252.157a32 32 0 0 0-45.248-45.248z"
            />
          </svg>
        </el-button>
      </div>

      <el-form ref="formRef" :model="form" :rules="rules" label-width="170px" label-position="right">
        <el-collapse v-model="activeCollapse" class="level-collapse">
          <!-- ===== 基础设置（默认展开，对齐点大截图） ===== -->
          <el-collapse-item title="基础设置" name="base">
            <el-form-item label="排序">
              <el-input-number v-model="form.sort" :min="0" :max="9999" style="width: 170px" />
              <span class="form-tip">用于升级顺序判断，数字越大等级越高</span>
            </el-form-item>
            <el-form-item label="等级名称" prop="name">
              <el-input v-model="form.name" placeholder="如：普通会员 / 经销商" style="width: 280px" />
            </el-form-item>
            <el-form-item label="等级图标">
              <div class="icon-row">
                <div v-if="form.icon" class="icon-preview">
                  <el-image :src="assetUrl(form.icon)" fit="cover" class="icon-img">
                    <template #error><span class="icon-img-fail">无图</span></template>
                  </el-image>
                  <el-icon class="icon-del" @click="form.icon = ''"><CircleClose /></el-icon>
                </div>
                <el-button @click="iconPickerVisible = true">上传图片</el-button>
                <span class="form-tip">建议 50×50 像素</span>
              </div>
            </el-form-item>
            <el-form-item label="等级折扣" prop="discount">
              <el-input-number
                v-model="form.discount"
                :min="0.01"
                :max="10"
                :step="0.5"
                :precision="2"
                style="width: 170px"
              />
              <span class="form-tip">购买商品时打多少折，10 表示不打折，9.5 表示打 95 折</span>
            </el-form-item>
            <el-form-item label="名额上限">
              <el-input-number v-model="form.maxnum" :min="0" :step="1" style="width: 170px" />
              <span class="form-tip">该等级最多可容纳人数（0=不限制）</span>
            </el-form-item>
            <el-form-item label="默认等级">
              <el-radio-group v-model="form.isdefault">
                <el-radio :value="1">是</el-radio>
                <el-radio :value="0">否</el-radio>
              </el-radio-group>
              <span class="form-tip">新注册会员默认所属等级（仅一个，默认等级不可删除）</span>
            </el-form-item>
          </el-collapse-item>

          <!-- ===== 分销设置（默认展开，对齐点大截图） ===== -->
          <el-collapse-item title="分销设置" name="fenxiao">
            <el-form-item label="分销权限">
              <el-radio-group v-model="form.can_agent">
                <el-radio :value="0">无权限</el-radio>
                <el-radio :value="1">一级分销</el-radio>
                <el-radio :value="2">二级分销</el-radio>
                <el-radio :value="3">三级分销</el-radio>
              </el-radio-group>
              <span class="form-tip-block">开启则表示该等级会员有分销权限，可以发展下级，拿相应提成</span>
            </el-form-item>

            <template v-if="form.can_agent > 0">
              <el-form-item label="提成方式">
                <el-radio-group v-model="form.commissiontype">
                  <el-radio :value="0">百分比</el-radio>
                  <el-radio :value="1">固定金额</el-radio>
                </el-radio-group>
                <span class="form-tip-block">设置固定金额时按单返佣金，和购买数量无关</span>
              </el-form-item>

              <el-form-item label="提成金额">
                <div class="num-grid">
                  <div class="num-cell">
                    <span class="num-label">一级({{ commissionUnit() }})</span>
                    <el-input-number v-model="form.commission1" :min="0" :precision="2" />
                  </div>
                  <div v-if="form.can_agent >= 2" class="num-cell">
                    <span class="num-label">二级({{ commissionUnit() }})</span>
                    <el-input-number v-model="form.commission2" :min="0" :precision="2" />
                  </div>
                  <div v-if="form.can_agent >= 3" class="num-cell">
                    <span class="num-label">三级({{ commissionUnit() }})</span>
                    <el-input-number v-model="form.commission3" :min="0" :precision="2" />
                  </div>
                  <div class="num-cell">
                    <span class="num-label">持续推荐奖励({{ commissionUnit() }})</span>
                    <el-input-number v-model="form.commission_parent" :min="0" :precision="2" />
                  </div>
                </div>
                <span class="form-tip-block">
                  用户直接推荐的是一级，用户的下级推荐的是二级，用户的下级的下级推荐的是三级；<br />
                  *当商家开启独立收款并且开启商家返款或扣除分销佣金时，提成金额比例+平台抽成金额比例不能超过30%（微信支付分账限制）
                </span>
              </el-form-item>

              <el-form-item label="推荐积分">
                <div class="num-grid">
                  <div class="num-cell">
                    <span class="num-label">一级(积分)</span>
                    <el-input-number v-model="form.score1" :min="0" :step="1" :precision="0" />
                  </div>
                  <div v-if="form.can_agent >= 2" class="num-cell">
                    <span class="num-label">二级(积分)</span>
                    <el-input-number v-model="form.score2" :min="0" :step="1" :precision="0" />
                  </div>
                  <div v-if="form.can_agent >= 3" class="num-cell">
                    <span class="num-label">三级(积分)</span>
                    <el-input-number v-model="form.score3" :min="0" :step="1" :precision="0" />
                  </div>
                  <div class="num-cell">
                    <span class="num-label">最高限制(积分)</span>
                    <el-input-number v-model="form.scoremax" :min="0" :step="1" :precision="0" />
                  </div>
                </div>
                <span class="form-tip-block">
                  每推荐一个人奖励多少积分；最高限制指每个人通过推荐获得最高积分数，高于此设置将不再获得推荐积分，0 表示不限制
                </span>
              </el-form-item>
            </template>

            <el-form-item label="推荐规则">
              <el-radio-group v-model="form.agent_rule" class="rule-radio">
                <el-radio :value="0">初次进入即绑定推荐关系</el-radio>
                <el-radio :value="1">有推荐人时绑定推荐关系</el-radio>
                <el-radio :value="2">不绑定推荐关系</el-radio>
                <el-radio :value="3">首次消费后绑定推荐关系</el-radio>
              </el-radio-group>
              <span class="form-tip-block rule-desc">
                初次进入即绑定推荐关系：只有没有进入过平台的新会员才能被推荐，并且初次进入就确定了推荐人，终身绑定，不会再被其他人推荐；<br />
                有推荐人时绑定推荐关系：没有推荐人时可以被推荐，一旦有了推荐人，该会员的推荐人就确定了，不会再被其他人推荐；<br />
                不绑定推荐关系：即使该会员有推荐人，当被另一个人推荐时，TA 的推荐人就会变成后来推荐 TA 的人；<br />
                首次消费后绑定推荐关系：即会员在未消费前，当被另一个人推荐时，TA 的推荐人就会变成后来推荐 TA 的人。
              </span>
            </el-form-item>

            <el-form-item label="自己拿一级提成">
              <el-radio-group v-model="form.commission1own">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
              <span class="form-tip-block">开启后一级为自己，即自己购买商品自己拿一级佣金</span>
            </el-form-item>

            <el-form-item label="我的团队权限">
              <div class="perm-checks">
                <el-checkbox v-model="form.team_showtel" :true-value="1" :false-value="0">查看下级手机号</el-checkbox>
                <el-checkbox v-model="form.team_givemoney" :true-value="1" :false-value="0">给下级转余额</el-checkbox>
                <el-checkbox v-model="form.team_givescore" :true-value="1" :false-value="0">给下级转积分</el-checkbox>
              </div>
              <span class="form-tip-block">前端“我的团队”页面权限开关</span>
            </el-form-item>
          </el-collapse-item>

          <!-- ===== 其他设置 ===== -->
          <el-collapse-item title="其他设置" name="other">
            <el-form-item label="特权说明">
              <div class="explain-editor">
                <SimpleRichEditor v-model="form.explain" />
              </div>
            </el-form-item>
          </el-collapse-item>
        </el-collapse>

        <el-form-item class="submit-row">
          <el-button type="success" :loading="submitting" @click="handleSubmit">提 交</el-button>
        </el-form-item>
      </el-form>

      <ImgIconPicker v-model:visible="iconPickerVisible" @pick="onIconPick" />
    </div>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  gap: 3px;
  margin-bottom: 8px;
}

.default-tag {
  color: #f56c6c;
  margin-left: 4px;
  font-size: 12px;
}

/* 操作列：link 文字按钮单行排布 */
.op-wrap {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 2px;
  white-space: nowrap;
}

:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}

/* 整页编辑（平铺主区域） */
.goods-edit {
  background: #fff;
  border-radius: 6px;
  padding: 12px 16px 20px;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin-bottom: 8px;
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

.level-collapse :deep(.el-collapse-item__header) {
  font-weight: 600;
  font-size: 14px;
}
.level-collapse :deep(.el-collapse-item__content) {
  padding-bottom: 8px;
}

.form-tip {
  margin-left: 8px;
  font-size: 12px;
  color: #909399;
}
.form-tip-block {
  display: block;
  margin-top: 2px;
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
}
.rule-desc {
  max-width: 760px;
}
.rule-radio {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
}

/* 我的团队权限勾选 */
.perm-checks {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px 18px;
}

/* 提成/积分 横排数字输入 */
.num-grid {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 16px;
}
.num-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.num-cell :deep(.el-input-number) {
  width: 150px;
}
.num-label {
  font-size: 12px;
  color: #606266;
}

/* 特权说明富文本（对齐点大约 400px 高） */
.explain-editor {
  width: 100%;
  max-width: 860px;
}
.explain-editor :deep(.sre-editor) {
  min-height: 380px;
  max-height: 420px;
}

/* 等级图标上传 */
.icon-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.icon-preview {
  position: relative;
  width: 50px;
  height: 50px;
}
.icon-img {
  width: 50px;
  height: 50px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  display: block;
}
.icon-img-fail {
  font-size: 10px;
  color: #c0c4cc;
}
.icon-del {
  position: absolute;
  top: -7px;
  right: -7px;
  color: #909399;
  cursor: pointer;
  background: #fff;
  border-radius: 50%;
}
.icon-del:hover {
  color: #f56c6c;
}

/* 提交按钮行：与输入框左缘对齐 */
.submit-row {
  margin-top: 10px;
}
.submit-row :deep(.el-form-item__content) {
  justify-content: flex-start;
}

/* 让区块说明在控件下方独占整行 */
.level-collapse :deep(.el-form-item__content) {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  row-gap: 6px;
}
.level-collapse .num-grid {
  width: 100%;
}
.level-collapse .form-tip-block {
  width: 100%;
}
</style>
