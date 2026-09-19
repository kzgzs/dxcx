<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown } from '@element-plus/icons-vue'
import type { FormInstance, FormRules } from 'element-plus'
import {
  addMemberScore,
  editMember,
  freezeMember,
  getMemberLevelList,
  getMemberList,
  lockMember,
  rechargeMember,
} from '../../api/member'
import type { MemberItem, MemberLevelItem } from '../../api/types'
import { formatTime } from '../../utils/time'
import MemberDetail from './MemberDetail.vue'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<MemberItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  levelid: '' as number | '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getMemberList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      levelid: query.levelid === '' ? undefined : query.levelid,
      start_time: query.timeRange?.[0] || undefined,
      end_time: query.timeRange?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.keyword = ''
  query.levelid = ''
  query.timeRange = null
  query.page = 1
  fetchList()
}

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

// ============ 等级下拉（筛选 + 编辑共用） ============
const levelOptions = ref<MemberLevelItem[]>([])

async function fetchLevels() {
  try {
    const data = await getMemberLevelList()
    levelOptions.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 详情抽屉 ============
const detailRef = ref<InstanceType<typeof MemberDetail>>()

function openDetail(row: MemberItem) {
  detailRef.value?.open(row.id)
}

// ============ 编辑弹窗 ============
const editVisible = ref(false)
const editSubmitting = ref(false)
const editFormRef = ref<FormInstance>()

const editForm = reactive({
  id: 0,
  nickname: '',
  tel: '',
  levelid: 0,
  levelendtime: '',
  remark: '',
})

const editRules: FormRules = {
  nickname: [{ required: true, message: '请输入昵称', trigger: 'blur' }],
  tel: [
    {
      validator: (_rule, value, callback) => {
        if (value && !/^1\d{10}$/.test(value)) {
          callback(new Error('手机号格式不正确'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
}

function openEdit(row: MemberItem) {
  Object.assign(editForm, {
    id: row.id,
    nickname: row.nickname,
    tel: row.tel,
    levelid: row.levelid,
    levelendtime: '',
    remark: row.remark,
  })
  editVisible.value = true
}

async function handleEditSubmit() {
  if (!editFormRef.value) return
  await editFormRef.value.validate(async (valid) => {
    if (!valid) return
    editSubmitting.value = true
    try {
      await editMember({
        id: editForm.id,
        nickname: editForm.nickname,
        tel: editForm.tel,
        levelid: editForm.levelid,
        levelendtime: editForm.levelendtime || undefined,
        remark: editForm.remark,
      })
      ElMessage.success('保存成功')
      editVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      editSubmitting.value = false
    }
  })
}

// ============ 充值弹窗（金额可负 + 二次确认） ============
const rechargeVisible = ref(false)
const rechargeSubmitting = ref(false)
const rechargeTarget = ref<MemberItem | null>(null)

const rechargeForm = reactive({
  money: undefined as number | undefined,
  remark: '',
})

function openRecharge(row: MemberItem) {
  rechargeTarget.value = row
  rechargeForm.money = undefined
  rechargeForm.remark = ''
  rechargeVisible.value = true
}

async function handleRechargeSubmit() {
  const target = rechargeTarget.value
  const money = Number(rechargeForm.money)
  if (!target) return
  if (!Number.isFinite(money) || money === 0) {
    ElMessage.warning('请输入不为 0 的金额（正数充值，负数扣费）')
    return
  }
  const action = money > 0 ? '充值' : '扣费'
  try {
    await ElMessageBox.confirm(
      `确定要给会员「${target.nickname || target.id}」${action} ¥${Math.abs(money).toFixed(2)} 吗？当前余额 ¥${target.money.toFixed(2)}。`,
      `${action}确认`,
      { confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  rechargeSubmitting.value = true
  try {
    await rechargeMember({ id: target.id, money, remark: rechargeForm.remark || undefined })
    ElMessage.success(`${action}成功`)
    rechargeVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    rechargeSubmitting.value = false
  }
}

// ============ 积分调整弹窗（可负 + 二次确认） ============
const scoreVisible = ref(false)
const scoreSubmitting = ref(false)
const scoreTarget = ref<MemberItem | null>(null)

const scoreForm = reactive({
  score: undefined as number | undefined,
  remark: '',
})

function openScore(row: MemberItem) {
  scoreTarget.value = row
  scoreForm.score = undefined
  scoreForm.remark = ''
  scoreVisible.value = true
}

async function handleScoreSubmit() {
  const target = scoreTarget.value
  const score = Number(scoreForm.score)
  if (!target) return
  if (!Number.isInteger(score) || score === 0) {
    ElMessage.warning('请输入不为 0 的整数积分（正数增加，负数扣减）')
    return
  }
  const action = score > 0 ? '增加' : '扣减'
  try {
    await ElMessageBox.confirm(
      `确定要给会员「${target.nickname || target.id}」${action} ${Math.abs(score)} 积分吗？当前积分 ${target.score}。`,
      '积分调整确认',
      { confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  scoreSubmitting.value = true
  try {
    await addMemberScore({ id: target.id, score, remark: scoreForm.remark || undefined })
    ElMessage.success('调整成功')
    scoreVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    scoreSubmitting.value = false
  }
}

// ============ 冻结 / 锁定（更多下拉） ============
async function handleFreeze(row: MemberItem) {
  const next = row.isfreeze === 1 ? '解冻' : '冻结'
  try {
    await ElMessageBox.confirm(`确定要${next}会员「${row.nickname || row.id}」吗？`, `${next}确认`, {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    const data = await freezeMember(row.id)
    row.isfreeze = data.isfreeze
    ElMessage.success(`已${next}`)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleLock(row: MemberItem) {
  const next = row.islock === 1 ? '解锁' : '锁定'
  try {
    await ElMessageBox.confirm(`确定要${next}会员「${row.nickname || row.id}」吗？`, `${next}确认`, {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    const data = await lockMember(row.id)
    row.islock = data.islock
    ElMessage.success(`已${next}`)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleMoreCommand(cmd: string, row: MemberItem) {
  if (cmd === 'freeze') handleFreeze(row)
  if (cmd === 'lock') handleLock(row)
}

// 时间列短格式（悬浮 tooltip 显示完整时间）
function shortDate(ts: number): string {
  if (!ts) return '-'
  const full = formatTime(ts)
  return full.length >= 10 ? full.slice(2, 10) : full
}

onMounted(() => {
  fetchLevels()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="ID / 昵称 / 手机号 / 姓名"
            clearable
            style="width: 190px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="等级">
          <el-select v-model="query.levelid" placeholder="全部" clearable style="width: 130px">
            <el-option v-for="lv in levelOptions" :key="lv.id" :label="lv.name" :value="lv.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="注册时间">
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 会员列表（border 竖线 + 紧凑列宽，1366 无横向滚动） -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column label="会员" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar :size="26" :src="row.headimg || undefined">
                {{ (row.nickname || '会').slice(0, 1) }}
              </el-avatar>
              <span class="member-name">{{ row.nickname || `会员${row.id}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="tel" label="手机号" width="108" class-name="compact-col">
          <template #default="{ row }">{{ row.tel || '-' }}</template>
        </el-table-column>
        <el-table-column prop="level_name" label="等级" width="76" class-name="compact-col" show-overflow-tooltip>
          <template #default="{ row }">{{ row.level_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="余额" width="82" class-name="compact-col">
          <template #default="{ row }">{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="score" label="积分" width="66" class-name="compact-col" />
        <el-table-column prop="buynum" label="消费次数" width="76" class-name="compact-col" />
        <el-table-column label="消费金额" width="86" class-name="compact-col">
          <template #default="{ row }">{{ Number(row.buymoney).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="注册时间" width="88" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ shortDate(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="最后访问" width="88" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.last_visittime" :content="formatTime(row.last_visittime)" placement="top">
              <span>{{ shortDate(row.last_visittime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="72" class-name="compact-col">
          <template #default="{ row }">
            <el-tag v-if="row.isfreeze === 1" type="danger" size="small">冻结</el-tag>
            <el-tag v-else-if="row.islock === 1" type="info" size="small">锁定</el-tag>
            <span v-else class="status-on">正常</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="196" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <div class="op-wrap">
              <el-button link type="primary" @click="openDetail(row)">详情</el-button>
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="primary" @click="openRecharge(row)">充值</el-button>
              <el-button link type="primary" @click="openScore(row)">积分</el-button>
              <el-dropdown trigger="click" @command="(cmd: string) => handleMoreCommand(cmd, row)">
                <el-button link type="primary">
                  更多<el-icon class="more-arrow"><ArrowDown /></el-icon>
                </el-button>
                <template #dropdown>
                  <el-dropdown-menu>
                    <el-dropdown-item command="freeze">
                      {{ row.isfreeze === 1 ? '解冻' : '冻结' }}
                    </el-dropdown-item>
                    <el-dropdown-item command="lock">
                      {{ row.islock === 1 ? '解锁' : '锁定' }}
                    </el-dropdown-item>
                  </el-dropdown-menu>
                </template>
              </el-dropdown>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editVisible" title="编辑会员" width="460px">
      <el-form
        ref="editFormRef"
        :model="editForm"
        :rules="editRules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="昵称" prop="nickname">
          <el-input v-model="editForm.nickname" placeholder="请输入昵称" />
        </el-form-item>
        <el-form-item label="手机号" prop="tel">
          <el-input v-model="editForm.tel" placeholder="请输入手机号" />
        </el-form-item>
        <el-form-item label="会员等级">
          <el-select v-model="editForm.levelid" style="width: 100%">
            <el-option label="普通会员（无等级）" :value="0" />
            <el-option v-for="lv in levelOptions" :key="lv.id" :label="lv.name" :value="lv.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="等级到期">
          <el-date-picker
            v-model="editForm.levelendtime"
            type="date"
            value-format="YYYY-MM-DD"
            placeholder="留空为永久"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="备注">
          <el-input
            v-model="editForm.remark"
            type="textarea"
            :rows="3"
            placeholder="请输入备注"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="editSubmitting" @click="handleEditSubmit">
          保存
        </el-button>
      </template>
    </el-dialog>

    <!-- 充值 / 扣费弹窗 -->
    <el-dialog v-model="rechargeVisible" title="余额充值 / 扣费" width="420px">
      <div v-if="rechargeTarget" class="adjust-tip">
        会员：{{ rechargeTarget.nickname || `会员${rechargeTarget.id}` }}
        ｜ 当前余额：¥ {{ rechargeTarget.money.toFixed(2) }}
      </div>
      <el-form label-width="70px" label-position="left">
        <el-form-item label="金额" required>
          <el-input-number
            v-model="rechargeForm.money"
            :precision="2"
            :step="10"
            placeholder="正数充值，负数扣费"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="备注">
          <el-input
            v-model="rechargeForm.remark"
            type="textarea"
            :rows="2"
            placeholder="留空默认：后台充值 / 后台扣费"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="rechargeVisible = false">取消</el-button>
        <el-button type="primary" :loading="rechargeSubmitting" @click="handleRechargeSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>

    <!-- 积分调整弹窗 -->
    <el-dialog v-model="scoreVisible" title="积分调整" width="420px">
      <div v-if="scoreTarget" class="adjust-tip">
        会员：{{ scoreTarget.nickname || `会员${scoreTarget.id}` }}
        ｜ 当前积分：{{ scoreTarget.score }}
      </div>
      <el-form label-width="70px" label-position="left">
        <el-form-item label="积分" required>
          <el-input-number
            v-model="scoreForm.score"
            :precision="0"
            :step="10"
            placeholder="正数增加，负数扣减"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="备注">
          <el-input
            v-model="scoreForm.remark"
            type="textarea"
            :rows="2"
            placeholder="留空默认：后台加积分 / 后台扣积分"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="scoreVisible = false">取消</el-button>
        <el-button type="primary" :loading="scoreSubmitting" @click="handleScoreSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>

    <!-- 会员详情抽屉 -->
    <MemberDetail ref="detailRef" />
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.member-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 操作列：link 文字按钮单行排布（紧凑间距） */
.op-wrap {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 2px;
  white-space: nowrap;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}

.more-arrow {
  margin-left: 2px;
  font-size: 12px;
}

.status-on {
  color: #67c23a;
}

.adjust-tip {
  margin-bottom: 12px;
  padding: 8px 12px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  background-color: #fafafa;
  font-size: 13px;
  color: #606266;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
