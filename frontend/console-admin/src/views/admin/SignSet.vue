<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteSignRecords,
  getSignRecords,
  getSignSet,
  saveSignSet,
} from '../../api/couponManage'
import type { SignRecordItem, SignSetData } from '../../api/types'
import { formatTime } from '../../utils/time'

const props = withDefaults(defineProps<{ tab?: 'set' | 'record' }>(), { tab: 'set' })

const activeTab = ref<'set' | 'record'>(props.tab)

// ============ 签到设置 ============
const setLoading = ref(false)
const setSaving = ref(false)

const setForm = reactive<SignSetData>({
  score: 0,
  lxqdset: [],
  lxzsset: [],
  name: '',
  status: 0,
  display: 0,
  guize: '',
  style: 1,
  bgpic: '',
})

async function fetchSet() {
  setLoading.value = true
  try {
    const data = await getSignSet()
    Object.assign(setForm, {
      score: data.score || 0,
      lxqdset: data.lxqdset || [],
      lxzsset: data.lxzsset || [],
      name: data.name || '',
      status: data.status || 0,
      display: data.display || 0,
      guize: data.guize || '',
      style: data.style || 1,
      bgpic: data.bgpic || '',
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    setLoading.value = false
  }
}

function addRule(kind: 'lxqdset' | 'lxzsset') {
  setForm[kind].push({ days: 0, score: 0, coupon_id: 0, coupon_name: '' })
}

function removeRule(kind: 'lxqdset' | 'lxzsset', index: number) {
  setForm[kind].splice(index, 1)
}

async function handleSaveSet() {
  if (setForm.status === 1 && setForm.score <= 0 && setForm.lxqdset.length === 0) {
    ElMessage.warning('开启签到后，每日积分与连续签到规则至少配置一项')
    return
  }
  for (const rule of [...setForm.lxqdset, ...setForm.lxzsset]) {
    if (rule.days > 0 && rule.score <= 0 && rule.coupon_id <= 0) {
      ElMessage.warning('连续规则的奖励（积分或赠券）至少填写一项')
      return
    }
  }
  setSaving.value = true
  try {
    await saveSignSet({ ...setForm })
    ElMessage.success('保存成功')
    fetchSet()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    setSaving.value = false
  }
}

// ============ 签到记录 ============
const recordLoading = ref(false)
const recordData = ref<SignRecordItem[]>([])
const recordTotal = ref(0)
const selectedRecords = ref<SignRecordItem[]>([])

const recordQuery = reactive({
  page: 1,
  pagesize: 10,
  mid: '' as number | '',
  nickname: '',
  timeRange: null as [Date, Date] | null,
})

async function fetchRecords() {
  recordLoading.value = true
  try {
    const data = await getSignRecords({
      page: recordQuery.page,
      pagesize: recordQuery.pagesize,
      mid: recordQuery.mid === '' ? undefined : Number(recordQuery.mid),
      nickname: recordQuery.nickname || undefined,
      start_time:
        recordQuery.timeRange && recordQuery.timeRange[0]
          ? Math.floor(recordQuery.timeRange[0].getTime() / 1000)
          : undefined,
      end_time:
        recordQuery.timeRange && recordQuery.timeRange[1]
          ? Math.floor(recordQuery.timeRange[1].getTime() / 1000)
          : undefined,
    })
    recordData.value = data.list || []
    recordTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    recordLoading.value = false
  }
}

function handleRecordSearch() {
  recordQuery.page = 1
  fetchRecords()
}

function handleRecordReset() {
  recordQuery.mid = ''
  recordQuery.nickname = ''
  recordQuery.timeRange = null
  recordQuery.page = 1
  fetchRecords()
}

function handleRecordPageChange(page: number) {
  recordQuery.page = page
  fetchRecords()
}

function handleRecordSizeChange(size: number) {
  recordQuery.pagesize = size
  recordQuery.page = 1
  fetchRecords()
}

function handleSelectionChange(rows: SignRecordItem[]) {
  selectedRecords.value = rows
}

async function handleDeleteRecords(ids: number[]) {
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 条签到记录吗？删除后不可恢复。`, '删除确认', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    await deleteSignRecords(ids)
    ElMessage.success('删除成功')
    fetchRecords()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleTabChange(tab: string | number) {
  if (tab === 'record' && recordData.value.length === 0) {
    fetchRecords()
  }
}

onMounted(() => {
  fetchSet()
  if (activeTab.value === 'record') {
    fetchRecords()
  }
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <!-- ============ 签到设置 ============ -->
        <el-tab-pane label="签到设置" name="set">
          <el-form
            v-loading="setLoading"
            label-width="110px"
            label-position="right"
            class="compact-form"
          >
            <el-form-item label="签到开关">
              <el-switch v-model="setForm.status" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="活动名称">
              <el-input v-model="setForm.name" placeholder="如：每日签到（可选）" style="width: 320px" />
            </el-form-item>
            <el-form-item label="每日签到积分">
              <el-input-number v-model="setForm.score" :min="0" />
              <span class="form-tip">会员每天签到获得的积分</span>
            </el-form-item>

            <el-form-item label="连续签到奖励">
              <div class="rule-block">
                <div v-for="(rule, index) in setForm.lxqdset" :key="'q' + index" class="rule-row">
                  <span class="rule-label">连续</span>
                  <el-input-number v-model="rule.days" :min="1" size="small" style="width: 110px" />
                  <span class="rule-label">天，奖励积分</span>
                  <el-input-number v-model="rule.score" :min="0" size="small" style="width: 110px" />
                  <el-button link type="danger" size="small" @click="removeRule('lxqdset', index)">
                    删除
                  </el-button>
                </div>
                <el-button size="small" @click="addRule('lxqdset')">+ 添加规则</el-button>
              </div>
            </el-form-item>

            <el-form-item label="连续赠送奖励">
              <div class="rule-block">
                <div v-for="(rule, index) in setForm.lxzsset" :key="'z' + index" class="rule-row">
                  <span class="rule-label">连续</span>
                  <el-input-number v-model="rule.days" :min="1" size="small" style="width: 110px" />
                  <span class="rule-label">天，奖励积分</span>
                  <el-input-number v-model="rule.score" :min="0" size="small" style="width: 110px" />
                  <el-button link type="danger" size="small" @click="removeRule('lxzsset', index)">
                    删除
                  </el-button>
                </div>
                <el-button size="small" @click="addRule('lxzsset')">+ 添加规则</el-button>
                <div class="form-tip">连续赠送：达到天数后在每日积分基础上额外奖励</div>
              </div>
            </el-form-item>

            <el-form-item label="排名显示">
              <el-switch v-model="setForm.display" :active-value="1" :inactive-value="0" />
              <span class="form-tip">是否在客户端签到页展示签到排名</span>
            </el-form-item>

            <el-form-item label="活动规则">
              <el-input
                v-model="setForm.guize"
                type="textarea"
                :rows="3"
                placeholder="展示在客户端签到页的规则说明（可选）"
                style="width: 520px"
              />
            </el-form-item>

            <el-form-item>
              <el-button type="primary" :loading="setSaving" @click="handleSaveSet">保存</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- ============ 签到记录 ============ -->
        <el-tab-pane label="签到记录" name="record">
          <el-form inline class="record-toolbar" @submit.prevent>
            <el-form-item label="会员ID">
              <el-input
                v-model="recordQuery.mid"
                placeholder="会员ID"
                clearable
                style="width: 110px"
                @keyup.enter="handleRecordSearch"
              />
            </el-form-item>
            <el-form-item label="昵称">
              <el-input
                v-model="recordQuery.nickname"
                placeholder="昵称关键词"
                clearable
                style="width: 150px"
                @keyup.enter="handleRecordSearch"
              />
            </el-form-item>
            <el-form-item label="签到时间">
              <el-date-picker
                v-model="recordQuery.timeRange"
                type="datetimerange"
                range-separator="至"
                start-placeholder="开始"
                end-placeholder="结束"
                style="width: 300px"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="handleRecordSearch">搜索</el-button>
              <el-button @click="handleRecordReset">重置</el-button>
              <el-button
                type="danger"
                plain
                :disabled="selectedRecords.length === 0"
                @click="handleDeleteRecords(selectedRecords.map((r) => r.id))"
              >
                删除选中
              </el-button>
            </el-form-item>
          </el-form>

          <el-table
            v-loading="recordLoading"
            :data="recordData"
            stripe
            @selection-change="handleSelectionChange"
          >
            <el-table-column type="selection" width="42" />
            <el-table-column prop="id" label="ID" width="64" />
            <el-table-column label="会员" min-width="130">
              <template #default="{ row }">
                {{ row.nickname || '-' }}
                <span v-if="row.member_phone" class="member-phone">（{{ row.member_phone }}）</span>
              </template>
            </el-table-column>
            <el-table-column prop="signdate" label="签到日期" width="110" />
            <el-table-column label="累计签到" width="90">
              <template #default="{ row }">{{ row.signtimes }} 天</template>
            </el-table-column>
            <el-table-column label="连续签到" width="90">
              <template #default="{ row }">{{ row.signtimeslx }} 天</template>
            </el-table-column>
            <el-table-column prop="score" label="获得积分" width="90" />
            <el-table-column label="连签赠券" min-width="120" show-overflow-tooltip>
              <template #default="{ row }">{{ row.lxqd_coupon_name || '-' }}</template>
            </el-table-column>
            <el-table-column label="连赠赠券" min-width="120" show-overflow-tooltip>
              <template #default="{ row }">{{ row.lxzs_coupon_name || '-' }}</template>
            </el-table-column>
            <el-table-column label="签到时间" width="150">
              <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="80" fixed="right">
              <template #default="{ row }">
                <el-button link type="danger" @click="handleDeleteRecords([row.id])">删除</el-button>
              </template>
            </el-table-column>
          </el-table>

          <el-pagination
            class="pagination"
            background
            layout="total, sizes, prev, pager, next, jumper"
            :total="recordTotal"
            :current-page="recordQuery.page"
            :page-size="recordQuery.pagesize"
            :page-sizes="[10, 20, 50, 100]"
            @current-change="handleRecordPageChange"
            @size-change="handleRecordSizeChange"
          />
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<style scoped>
.compact-form :deep(.el-form-item) {
  margin-bottom: 14px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.rule-block {
  width: 100%;
}

.rule-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.rule-label {
  color: #606266;
  font-size: 13px;
  white-space: nowrap;
}

.record-toolbar {
  margin-bottom: 8px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.member-phone {
  color: #909399;
  font-size: 12px;
}
</style>
