<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadProps, UploadUserFile } from 'element-plus'
import {
  deleteSignRecords,
  exportSignRecords,
  getCouponManageList,
  getSignRecords,
  getSignSet,
  saveSignSet,
} from '../../api/couponManage'
import type { CouponManageItem, SignRecordItem, SignSetData } from '../../api/types'
import { useAuthStore } from '../../stores/auth'
import { formatTime } from '../../utils/time'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'

const props = withDefaults(defineProps<{ tab?: 'set' | 'record' }>(), { tab: 'set' })

const activeTab = ref<'set' | 'record'>(props.tab)
const authStore = useAuthStore()

/* ================= 签到设置 ================= */
const setLoading = ref(false)
const setSaving = ref(false)

const setForm = reactive<SignSetData>({
  score: 1,
  lxqdset: [],
  lxzsset: [],
  name: '',
  status: 1,
  display: 1,
  guize: '',
  style: 1,
  bgpic: '',
  rewardedvideoad: '',
})

async function fetchSet() {
  setLoading.value = true
  try {
    const data = await getSignSet()
    setForm.score = data.score || 0
    setForm.lxqdset = (data.lxqdset || []).map((r) => ({
      days: Number(r.days) || 0,
      score: Number(r.score) || 0,
      coupon_id: Number(r.coupon_id) || 0,
      coupon_name: r.coupon_name || '',
    }))
    setForm.lxzsset = (data.lxzsset || []).map((r) => ({
      days: Number(r.days) || 0,
      score: Number(r.score) || 0,
      coupon_id: Number(r.coupon_id) || 0,
      coupon_name: r.coupon_name || '',
    }))
    if (setForm.lxqdset.length === 0) setForm.lxqdset.push(blankRule())
    if (setForm.lxzsset.length === 0) setForm.lxzsset.push(blankRule())
    setForm.name = data.name || ''
    setForm.status = data.status || 0
    setForm.display = data.display || 0
    setForm.guize = data.guize || ''
    setForm.style = data.style || 1
    setForm.bgpic = data.bgpic || ''
    setForm.rewardedvideoad = data.rewardedvideoad || ''
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    setLoading.value = false
  }
}

function blankRule() {
  return { days: 0, score: 0, coupon_id: 0, coupon_name: '' }
}

function addRule(kind: 'lxqdset' | 'lxzsset') {
  setForm[kind].push(blankRule())
}

function removeRule(kind: 'lxqdset' | 'lxzsset', index: number) {
  setForm[kind].splice(index, 1)
  if (setForm[kind].length === 0) setForm[kind].push(blankRule())
}

/* ---- 优惠券选择小弹窗 ---- */
const couponOptions = ref<CouponManageItem[]>([])
const couponDialogVisible = ref(false)
const pickedCouponId = ref<number>(0)
const couponTarget = reactive({ kind: 'lxqdset' as 'lxqdset' | 'lxzsset', index: -1 })

async function loadCoupons() {
  try {
    const res = await getCouponManageList({ page: 1, pagesize: 100 })
    couponOptions.value = res.list || []
  } catch {
    // 候选加载失败不阻断
  }
}

function openCouponPicker(kind: 'lxqdset' | 'lxzsset', index: number) {
  couponTarget.kind = kind
  couponTarget.index = index
  pickedCouponId.value = setForm[kind][index]?.coupon_id || 0
  couponDialogVisible.value = true
}

function confirmCoupon() {
  const c = couponOptions.value.find((o) => o.id === pickedCouponId.value)
  if (c && couponTarget.index >= 0) {
    setForm[couponTarget.kind][couponTarget.index].coupon_id = c.id
    setForm[couponTarget.kind][couponTarget.index].coupon_name = c.name
  }
  couponDialogVisible.value = false
}

/* ---- 背景图上传 ---- */
const uploadHeaders = computed(() => ({ Authorization: `Bearer ${authStore.token}` }))

const bgpicFileList = computed<UploadUserFile[]>(() =>
  setForm.bgpic ? [{ name: 'bgpic', url: setForm.bgpic }] : [],
)

const onBgpicSuccess: UploadProps['onSuccess'] = (response) => {
  const r = response as { code?: number; data?: { url?: string }; msg?: string }
  if (r && r.code === 0 && r.data?.url) {
    setForm.bgpic = r.data.url
  } else {
    ElMessage.error(r?.msg || '上传失败')
  }
}

const onBgpicRemove = () => {
  setForm.bgpic = ''
}

const onBgpicError: UploadProps['onError'] = () => {
  ElMessage.error('上传失败，请重试')
}

async function handleSaveSet() {
  setSaving.value = true
  try {
    await saveSignSet({ ...setForm })
    ElMessage.success('提交成功')
    fetchSet()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    setSaving.value = false
  }
}

/* ================= 签到记录 ================= */
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
  field: '' as string,
  order: 'desc' as string,
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
      field: recordQuery.field || undefined,
      order: recordQuery.order || undefined,
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

function handleSortChange({ prop, order }: { prop?: string; order?: string | null }) {
  if (!order) {
    recordQuery.field = ''
    recordQuery.order = 'desc'
  } else {
    recordQuery.field = prop || ''
    recordQuery.order = order === 'ascending' ? 'asc' : 'desc'
  }
  recordQuery.page = 1
  fetchRecords()
}

async function handleDeleteRecords(ids: number[]) {
  if (ids.length === 0) {
    ElMessage.warning('请先选择记录')
    return
  }
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

async function handleExport() {
  try {
    const blob = await exportSignRecords({
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
    const url = URL.createObjectURL(blob instanceof Blob ? blob : new Blob([blob as BlobPart]))
    const a = document.createElement('a')
    a.href = url
    a.download = `签到记录_${Date.now()}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success('导出成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function couponText(name: string) {
  return name && name.trim() !== '' ? name : '无'
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
    loadCoupons()
  } else {
    loadCoupons()
  }
})
</script>

<template>
  <div class="sign-page">
    <el-tabs v-model="activeTab" class="hide-tab-nav" @tab-change="handleTabChange">
      <!-- ============ 签到设置 ============ -->
      <el-tab-pane label="签到设置" name="set">
        <el-card shadow="never" class="main-card" v-loading="setLoading">
          <template #header>
            <span class="card-title">
              <i class="el-icon-setting"></i> 签到规则设置
            </span>
          </template>

          <el-form label-width="120px" label-position="right" class="compact-form">
            <!-- 状态 -->
            <el-form-item label="状态：">
              <el-radio-group v-model="setForm.status">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
            </el-form-item>

            <!-- 签到获得积分 -->
            <el-form-item label="签到获得积分：">
              <el-input v-model.number="setForm.score" type="number" style="width: 220px" />
            </el-form-item>

            <!-- 连续签到设置 -->
            <el-form-item
              v-for="(rule, index) in setForm.lxqdset"
              :key="'q' + index"
              :label="index === 0 ? '连续签到设置：' : ''"
            >
              <span class="inline-txt">连续签到</span>
              <el-input v-model.number="rule.days" type="number" class="w70" />
              <span class="inline-txt">天以上，每天签到获得</span>
              <el-input v-model.number="rule.score" type="number" class="w70" />
              <span class="inline-txt">积分，优惠券ID</span>
              <el-input v-model.number="rule.coupon_id" type="number" class="w70" />
              <span class="inline-txt">优惠券名称：<span class="coupon-name">{{ rule.coupon_name || '无' }}</span></span>
              <el-button size="small" @click="openCouponPicker('lxqdset', index)">添加</el-button>
              <el-button
                v-if="index === 0"
                size="small"
                @click="addRule('lxqdset')"
                title="添加一行"
              >+</el-button>
              <el-button
                v-else
                size="small"
                @click="removeRule('lxqdset', index)"
                title="删除此行"
              >-</el-button>
            </el-form-item>

            <!-- 连续签到赠送 -->
            <el-form-item
              v-for="(rule, index) in setForm.lxzsset"
              :key="'z' + index"
              :label="index === 0 ? '连续签到赠送：' : ''"
            >
              <span class="inline-txt">连续签到</span>
              <el-input v-model.number="rule.days" type="number" class="w70" />
              <span class="inline-txt">天，额外赠送</span>
              <el-input v-model.number="rule.score" type="number" class="w70" />
              <span class="inline-txt">积分，优惠券ID</span>
              <el-input v-model.number="rule.coupon_id" type="number" class="w70" />
              <span class="inline-txt">优惠券名称：<span class="coupon-name">{{ rule.coupon_name || '无' }}</span></span>
              <el-button size="small" @click="openCouponPicker('lxzsset', index)">添加</el-button>
              <el-button
                v-if="index === 0"
                size="small"
                @click="addRule('lxzsset')"
                title="添加一行"
              >+</el-button>
              <el-button
                v-else
                size="small"
                @click="removeRule('lxzsset', index)"
                title="删除此行"
              >-</el-button>
            </el-form-item>

            <!-- 页面样式 -->
            <el-form-item label="页面样式：">
              <el-radio-group v-model="setForm.style">
                <el-radio :value="1">样式1</el-radio>
                <el-radio :value="2">样式2</el-radio>
              </el-radio-group>

              <div class="style-previews">
                <!-- 样式1：礼盒红包式 -->
                <div
                  class="style-preview-card"
                  :class="{ active: setForm.style === 1 }"
                  @click="setForm.style = 1"
                >
                  <div class="sp1-phone">
                    <div class="sp1-top">
                      <span class="sp1-record">签到记录</span>
                      <div class="sp1-num">825</div>
                      <div class="sp1-num-sub">积分</div>
                    </div>
                    <div class="sp1-gift">
                      <div class="sp1-lid"></div>
                      <div class="sp1-box">
                        <div class="sp1-cal">
                          <i v-for="n in 12" :key="n" class="sp1-dot"></i>
                        </div>
                        <i class="sp1-coin c1"></i>
                        <i class="sp1-coin c2"></i>
                        <i class="sp1-coin c3"></i>
                      </div>
                    </div>
                    <div class="sp1-card">
                      <div class="sp1-btn">今日已签到</div>
                      <div class="sp1-cont">已连续签到2天</div>
                    </div>
                    <div class="sp1-rank-title">— 签到排名 —</div>
                    <div class="sp1-rank-row">
                      <span>很复杂😊</span>
                      <span>连续签到2天</span>
                    </div>
                    <div class="sp1-rule-title">— 签到规则 —</div>
                    <div class="sp1-rule-txt">每天签到即可获得一个积分；</div>
                  </div>
                </div>

                <!-- 样式2：日历式 -->
                <div
                  class="style-preview-card"
                  :class="{ active: setForm.style === 2 }"
                  @click="setForm.style = 2"
                >
                  <div class="sp2-phone">
                    <div class="sp2-top">
                      <span class="sp2-record">签到记录</span>
                      <div class="sp2-btn">今日已签到</div>
                      <div class="sp2-cont">已连续签到1天，共778积分</div>
                    </div>
                    <div class="sp2-cal">
                      <div class="sp2-cal-head">
                        <span class="sp2-nav">‹</span>
                        <span class="sp2-cal-month">2022 / 08</span>
                        <span class="sp2-nav">›</span>
                      </div>
                      <div class="sp2-week">
                        <span v-for="w in ['日','一','二','三','四','五','六']" :key="w">{{ w }}</span>
                      </div>
                      <div class="sp2-grid">
                        <span class="dim">31</span>
                        <span>1</span><span>2</span><span>3</span><span>4</span><span>5</span>
                        <span class="signed">6<em>已签</em></span>
                        <span>7</span>
                        <span class="signed">8<em>已签</em></span>
                        <span class="signed">9<em>已签</em></span>
                        <span>10</span><span>11</span><span>12</span><span>13</span>
                        <span>14</span>
                        <span class="today">15<em>已签</em></span>
                        <span>16</span><span>17</span><span>18</span><span>19</span><span>20</span>
                        <span>21</span><span>22</span><span>23</span><span>24</span><span>25</span><span>26</span><span>27</span>
                        <span>28</span><span>29</span><span>30</span><span>31</span><span>1</span><span>2</span><span>3</span>
                        <span>4</span><span>5</span><span>6</span><span>7</span><span>8</span><span>9</span><span>10</span>
                      </div>
                    </div>
                    <div class="sp2-rank-title">— 签到排名 —</div>
                    <div class="sp2-rank-row">
                      <span>很复杂😊</span>
                      <span>连续签到1天</span>
                    </div>
                  </div>
                </div>
              </div>
            </el-form-item>

            <!-- 背景图 -->
            <el-form-item label="背景图：">
              <el-upload
                action="/admin/upload"
                name="file"
                :headers="uploadHeaders"
                list-type="picture-card"
                :file-list="bgpicFileList"
                :limit="1"
                :on-success="onBgpicSuccess"
                :on-remove="onBgpicRemove"
                :on-error="onBgpicError"
                accept="image/*"
              >
                <span>上传图片</span>
              </el-upload>
              <span class="form-tip">建议尺寸：750×940像素</span>
            </el-form-item>

            <!-- 显示签到排名 -->
            <el-form-item label="显示签到排名：">
              <el-radio-group v-model="setForm.display">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
            </el-form-item>

            <!-- 小程序激励广告 -->
            <el-form-item label="小程序激励广告：">
              <el-input v-model="setForm.rewardedvideoad" style="width: 220px" placeholder="广告位ID" />
              <span class="form-tip">
                填写广告位ID 在小程序后台"流量主-广告管理-激励广告"中查找，设置后点击签到时必须先看完激励广告视频才能签到成功
              </span>
            </el-form-item>

            <!-- 规则说明 -->
            <el-form-item label="规则说明：">
              <div style="width: 560px">
                <SimpleRichEditor v-model="setForm.guize" />
              </div>
            </el-form-item>

            <el-form-item>
              <el-button type="primary" :loading="setSaving" @click="handleSaveSet">提 交</el-button>
            </el-form-item>
          </el-form>
        </el-card>
      </el-tab-pane>

      <!-- ============ 签到记录 ============ -->
      <el-tab-pane label="签到记录" name="record">
        <el-card shadow="never" class="main-card">
          <template #header>
            <span class="card-title">
              <i class="el-icon-document"></i> 签到记录
            </span>
          </template>

          <!-- 工具栏一行：左批量按钮，右搜索 -->
          <div class="record-toolbar">
            <div class="toolbar-left">
              <el-button
                :disabled="selectedRecords.length === 0"
                @click="handleDeleteRecords(selectedRecords.map((r) => r.id))"
              >删除</el-button>
              <el-button @click="handleExport">导出</el-button>
            </div>
            <div class="toolbar-right">
              <span class="tf-label">会员ID</span>
              <el-input
                v-model="recordQuery.mid"
                placeholder="会员ID"
                clearable
                class="tf-input"
                @keyup.enter="handleRecordSearch"
              />
              <span class="tf-label">昵称</span>
              <el-input
                v-model="recordQuery.nickname"
                placeholder="昵称"
                clearable
                class="tf-input"
                @keyup.enter="handleRecordSearch"
              />
              <span class="tf-label">签到时间</span>
              <el-date-picker
                v-model="recordQuery.timeRange"
                type="daterange"
                range-separator="至"
                start-placeholder="开始日期"
                end-placeholder="结束日期"
                class="tf-range"
              />
              <el-button type="primary" @click="handleRecordSearch">搜索</el-button>
            </div>
          </div>

          <el-table
            v-loading="recordLoading"
            :data="recordData"
            :header-cell-style="{ background: '#e6e6e6' }"
            class="record-table"
            @selection-change="handleSelectionChange"
            @sort-change="handleSortChange"
          >
            <el-table-column type="selection" width="42" />
            <el-table-column prop="id" label="ID" width="70" sortable="custom" />
            <el-table-column label="头像昵称" min-width="180">
              <template #default="{ row }">
                <div class="member-cell">
                  <img v-if="row.headimg" :src="row.headimg" class="member-avatar" alt="" />
                  <div v-else class="member-avatar placeholder"></div>
                  <div class="member-meta">
                    <div class="member-nick">{{ row.nickname || '-' }}</div>
                  </div>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="signdate" label="签到时间" width="110" />
            <el-table-column prop="score" label="获得积分" width="90" sortable="custom" />
            <el-table-column prop="signtimes" label="签到总次数" width="100" sortable="custom" />
            <el-table-column prop="signtimeslx" label="连续次数" width="90" sortable="custom" />
            <el-table-column label="连续签到优惠券" min-width="130" show-overflow-tooltip>
              <template #default="{ row }">{{ couponText(row.lxqd_coupon_name) }}</template>
            </el-table-column>
            <el-table-column label="连续签到赠送优惠券" min-width="140" show-overflow-tooltip>
              <template #default="{ row }">{{ couponText(row.lxzs_coupon_name) }}</template>
            </el-table-column>
            <el-table-column prop="remark" label="备注" min-width="110">
              <template #default="{ row }">{{ row.remark || '无' }}</template>
            </el-table-column>
            <el-table-column label="创建时间" width="150">
              <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="80">
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
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- 优惠券选择小弹窗 -->
    <el-dialog v-model="couponDialogVisible" title="选择优惠券" width="520px" append-to-body>
      <el-select v-model="pickedCouponId" filterable placeholder="请选择优惠券" style="width: 100%">
        <el-option
          v-for="c in couponOptions"
          :key="c.id"
          :label="`${c.id} - ${c.name}`"
          :value="c.id"
        />
      </el-select>
      <template #footer>
        <el-button @click="couponDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmCoupon">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.sign-page {
  padding: 3px;
}

.main-card {
  margin-bottom: 3px;
}

.card-title {
  font-weight: 600;
  font-size: 15px;
}

.compact-form :deep(.el-form-item) {
  margin-bottom: 14px;
}

.compact-form :deep(.el-form-item__label) {
  white-space: nowrap;
}

.inline-txt {
  margin: 0 4px;
  color: #606266;
  font-size: 13px;
  white-space: nowrap;
}

.w70 {
  width: 70px !important;
}

.coupon-name {
  color: #409eff;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

/* 隐藏顶部 tab 导航（左侧树菜单已负责导航） */
.hide-tab-nav :deep(.el-tabs__header) {
  display: none;
}

/* ============ 工具栏 ============ */
.record-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: nowrap;
  margin-bottom: 8px;
  gap: 8px;
}

.toolbar-left {
  flex: 0 0 auto;
}

.toolbar-right {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 6px;
}

.tf-label {
  color: #606266;
  font-size: 13px;
  white-space: nowrap;
}

.tf-input {
  width: 110px !important;
}

.tf-range {
  width: 260px !important;
}

/* 表格：无 fixed 列，内容居左 */
.record-table :deep(.el-table__cell) {
  text-align: left;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.member-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  object-fit: cover;
  background: #ebeef5;
  flex: 0 0 auto;
}

.member-avatar.placeholder {
  display: inline-block;
}

.member-nick {
  font-size: 13px;
  color: #303133;
}

.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}

/* ============ 样式预览（纯 CSS 模拟手机签到页） ============ */
.style-previews {
  display: flex;
  gap: 16px;
  margin-top: 10px;
  padding-left: 0;
}

.style-preview-card {
  width: 200px;
  border: 2px solid transparent;
  border-radius: 10px;
  padding: 4px;
  cursor: pointer;
  transition: border-color 0.15s;
}

.style-preview-card.active {
  border-color: #409eff;
}

/* ---- 样式1：礼盒红包式 ---- */
.sp1-phone {
  width: 192px;
  border-radius: 8px;
  overflow: hidden;
  background: linear-gradient(180deg, #ff4d4f 0%, #ff6a3d 60%, #ff8a5c 100%);
  color: #fff;
  font-size: 11px;
}

.sp1-top {
  position: relative;
  text-align: center;
  padding: 14px 0 8px;
}

.sp1-record {
  position: absolute;
  top: 8px;
  right: 8px;
  font-size: 10px;
  color: #fff;
}

.sp1-num {
  font-size: 26px;
  font-weight: 700;
  line-height: 1;
}

.sp1-num-sub {
  font-size: 11px;
  margin-top: 2px;
}

.sp1-gift {
  position: relative;
  height: 90px;
  margin: 0 auto;
  width: 150px;
}

.sp1-lid {
  position: absolute;
  top: 6px;
  left: 15px;
  right: 15px;
  height: 14px;
  background: #e8342e;
  border-radius: 3px;
  box-shadow: 0 3px 6px rgba(0, 0, 0, 0.15);
}

.sp1-box {
  position: absolute;
  bottom: 0;
  left: 10px;
  right: 10px;
  height: 64px;
  background: linear-gradient(180deg, #ff5a4d, #e8342e);
  border-radius: 6px;
  border-top: 3px solid #ffcf3f;
}

.sp1-cal {
  position: absolute;
  top: -14px;
  left: 18px;
  width: 60px;
  height: 40px;
  background: #fff;
  border-radius: 4px;
  padding: 4px;
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 2px;
}

.sp1-dot {
  width: 5px;
  height: 5px;
  background: #ffb3a7;
  border-radius: 1px;
}

.sp1-coin {
  position: absolute;
  width: 14px;
  height: 14px;
  background: radial-gradient(circle at 35% 35%, #ffd86b, #f5a623);
  border-radius: 50%;
}

.sp1-coin.c1 { right: 18px; bottom: 10px; }
.sp1-coin.c2 { right: 6px; bottom: 22px; width: 11px; height: 11px; }
.sp1-coin.c3 { left: 70px; bottom: 6px; width: 10px; height: 10px; }

.sp1-card {
  margin: 8px 10px 0;
  background: #fff;
  border-radius: 8px;
  padding: 12px 8px;
  text-align: center;
  color: #333;
}

.sp1-btn {
  background: linear-gradient(90deg, #ff9a9e, #f76b6a);
  color: #fff;
  border-radius: 999px;
  padding: 6px 0;
  font-size: 12px;
}

.sp1-cont {
  color: #999;
  font-size: 10px;
  margin-top: 8px;
}

.sp1-rank-title,
.sp1-rule-title {
  text-align: center;
  color: #666;
  font-size: 11px;
  margin: 10px 0 6px;
  position: relative;
}

.sp1-rank-row {
  background: #fff;
  color: #333;
  margin: 0 10px;
  border-radius: 6px;
  padding: 6px 8px;
  display: flex;
  justify-content: space-between;
  font-size: 10px;
}

.sp1-rule-txt {
  color: #666;
  font-size: 10px;
  padding: 0 10px 10px;
}

/* ---- 样式2：日历式 ---- */
.sp2-phone {
  width: 192px;
  border-radius: 8px;
  overflow: hidden;
  background: linear-gradient(180deg, #ff4d6d 0%, #ff6a5c 100%);
  color: #fff;
  font-size: 11px;
}

.sp2-top {
  position: relative;
  text-align: center;
  padding: 14px 0 12px;
}

.sp2-record {
  position: absolute;
  top: 8px;
  right: 8px;
  font-size: 10px;
  color: #fff;
}

.sp2-btn {
  background: linear-gradient(90deg, #ff9a9e, #f76b8a);
  color: #fff;
  border-radius: 999px;
  padding: 6px 18px;
  font-size: 12px;
  display: inline-block;
}

.sp2-cont {
  font-size: 10px;
  margin-top: 8px;
  color: #fff;
}

.sp2-cal {
  background: #fff;
  margin: 0 8px;
  border-radius: 8px;
  padding: 8px;
  color: #333;
}

.sp2-cal-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  margin-bottom: 6px;
}

.sp2-nav {
  color: #999;
  padding: 0 6px;
}

.sp2-week {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  color: #999;
  font-size: 10px;
  text-align: center;
  margin-bottom: 4px;
}

.sp2-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 3px;
  text-align: center;
  font-size: 10px;
  color: #555;
}

.sp2-grid > span {
  height: 22px;
  line-height: 12px;
  position: relative;
}

.sp2-grid .dim {
  color: #ccc;
}

.sp2-grid .signed {
  background: #f0f0f0;
  border-radius: 4px;
  color: #666;
  padding-top: 2px;
}

.sp2-grid .signed em {
  display: block;
  font-style: normal;
  font-size: 8px;
  color: #999;
}

.sp2-grid .today {
  background: #4a90e2;
  color: #fff;
  border-radius: 4px;
  padding-top: 2px;
}

.sp2-grid .today em {
  display: block;
  font-style: normal;
  font-size: 8px;
  color: #fff;
}

.sp2-rank-title {
  text-align: center;
  color: #666;
  font-size: 11px;
  margin: 10px 0 6px;
}

.sp2-rank-row {
  background: #fff;
  color: #333;
  margin: 0 8px 8px;
  border-radius: 6px;
  padding: 6px 8px;
  display: flex;
  justify-content: space-between;
  font-size: 10px;
}
</style>
