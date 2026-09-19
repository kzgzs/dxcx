<script setup lang="ts">
import { computed, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  mdCoupons,
  mdMemberByTel,
  mdPay,
  mdPreview,
  type MaidanCoupon,
  type MaidanMember,
  type MaidanPreviewResult,
} from '../../api/maidan'

const tel = ref('')
const searching = ref(false)
const member = ref<MaidanMember | null>(null)

const money = ref<number>(0)
const couponrid = ref(0)
const useScore = ref(false)
const coupons = ref<MaidanCoupon[]>([])

const previewing = ref(false)
const preview = ref<MaidanPreviewResult | null>(null)
const paying = ref(false)

const hasDiscount = computed(() => {
  const d = Number(member.value?.discount ?? 10)
  return d > 0 && d < 10
})

// 手机号找会员
async function handleSearch() {
  const t = tel.value.trim()
  if (!t) {
    ElMessage.warning('请输入会员手机号')
    return
  }
  searching.value = true
  member.value = null
  preview.value = null
  coupons.value = []
  couponrid.value = 0
  try {
    const result = await mdMemberByTel(t)
    if (!result || !result.mid) {
      ElMessage.warning('未找到该手机号对应的会员')
      return
    }
    member.value = result
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    searching.value = false
  }
}

// 金额变化：刷新可用券并重新试算
async function handleMoneyChange() {
  if (!member.value) return
  couponrid.value = 0
  try {
    const result = await mdCoupons(member.value.mid, Number(money.value) || 0)
    coupons.value = result.list || []
  } catch {
    coupons.value = []
  }
  if (Number(money.value) > 0) {
    await handlePreview()
  } else {
    preview.value = null
  }
}

// 券/积分变化重新试算
async function handlePreview() {
  if (!member.value || !(Number(money.value) > 0)) {
    preview.value = null
    return
  }
  previewing.value = true
  try {
    preview.value = await mdPreview({
      tel: tel.value.trim(),
      money: Number(money.value) || 0,
      couponrid: couponrid.value,
      usescore: useScore.value ? 1 : 0,
    })
  } catch {
    preview.value = null
  } finally {
    previewing.value = false
  }
}

// 确认收款
async function handlePay() {
  if (!member.value) {
    ElMessage.warning('请先查询会员')
    return
  }
  if (!(Number(money.value) > 0)) {
    ElMessage.warning('请输入消费金额')
    return
  }
  const payMoney = preview.value ? preview.value.paymoney : Number(money.value)
  try {
    await ElMessageBox.confirm(
      `确认向会员「${member.value.nickname || member.value.tel}」收款 ¥${payMoney.toFixed(2)}（余额支付）？`,
      '确认收款',
      { confirmButtonText: '确认收款', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  paying.value = true
  try {
    const result = await mdPay({
      tel: tel.value.trim(),
      money: Number(money.value) || 0,
      couponrid: couponrid.value,
      usescore: useScore.value ? 1 : 0,
    })
    ElMessage.success(`收款成功，实付 ¥${Number(result.paymoney).toFixed(2)}`)
    // 重置现场
    tel.value = ''
    member.value = null
    money.value = 0
    couponrid.value = 0
    useScore.value = false
    coupons.value = []
    preview.value = null
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    paying.value = false
  }
}
</script>

<template>
  <div class="maidan-add-page">
    <div class="page-card">
      <div class="card-title">买单扣费（余额收款）</div>

      <el-form label-width="110px" class="pay-form">
        <el-form-item label="会员手机号">
          <el-input
            v-model="tel"
            placeholder="输入会员手机号查询"
            style="width: 240px"
            maxlength="20"
            clearable
            @keyup.enter="handleSearch"
          />
          <el-button type="primary" :loading="searching" @click="handleSearch" style="margin-left: 10px">
            查询会员
          </el-button>
        </el-form-item>
      </el-form>

      <!-- 会员信息卡 -->
      <div class="member-card" v-if="member">
        <div class="member-line">
          <span class="member-name">{{ member.nickname || '会员' + member.mid }}</span>
          <el-tag size="small" v-if="member.levelname">{{ member.levelname }}</el-tag>
          <el-tag size="small" type="warning" v-if="hasDiscount">{{ member.discount }}折</el-tag>
          <span class="member-tel">{{ member.tel }}</span>
        </div>
        <div class="member-line sub">
          <span>余额：<b>¥{{ Number(member.money).toFixed(2) }}</b></span>
          <span>积分：<b>{{ member.score }}</b></span>
        </div>
      </div>

      <template v-if="member">
        <el-form label-width="110px" class="pay-form">
          <el-form-item label="消费金额">
            <el-input-number
              v-model="money"
              :min="0"
              :max="9999999"
              :precision="2"
              :step="1"
              style="width: 180px"
              @change="handleMoneyChange"
            />
            <span class="form-tip">输入实际消费金额，系统自动计算折扣、券与积分抵扣</span>
          </el-form-item>
          <el-form-item label="优惠券">
            <el-select v-model="couponrid" placeholder="不使用优惠券" clearable style="width: 320px" @change="handlePreview">
              <el-option
                v-for="c in coupons"
                :key="c.id"
                :label="`${c.couponname}（抵¥${c.money}，满${c.minprice}可用）`"
                :value="c.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="积分抵扣">
            <el-switch v-model="useScore" @change="handlePreview" />
            <span class="form-tip">开启后按系统设置自动计算积分可抵金额</span>
          </el-form-item>
        </el-form>

        <!-- 试算结果 -->
        <div class="preview-box" v-if="preview" v-loading="previewing">
          <div class="preview-row">
            <span>消费金额</span><b>¥{{ Number(preview.money).toFixed(2) }}</b>
          </div>
          <div class="preview-row" v-if="preview.disprice > 0">
            <span>等级折扣优惠</span><b class="minus">-¥{{ Number(preview.disprice).toFixed(2) }}</b>
          </div>
          <div class="preview-row" v-if="preview.couponmoney > 0">
            <span>优惠券抵扣</span><b class="minus">-¥{{ Number(preview.couponmoney).toFixed(2) }}</b>
          </div>
          <div class="preview-row" v-if="preview.scoredk > 0">
            <span>积分抵扣（{{ preview.decscore }}积分）</span><b class="minus">-¥{{ Number(preview.scoredk).toFixed(2) }}</b>
          </div>
          <div class="preview-row total">
            <span>实付金额（余额支付）</span><b>¥{{ Number(preview.paymoney).toFixed(2) }}</b>
          </div>
          <div class="preview-row warn" v-if="!preview.enough">
            <span>会员余额不足，无法完成收款</span>
          </div>
        </div>

        <el-form label-width="110px" class="pay-form">
          <el-form-item>
            <el-button
              type="primary"
              size="large"
              :loading="paying"
              :disabled="!preview || !preview.enough || !(Number(money) > 0)"
              @click="handlePay"
            >
              确认收款
            </el-button>
          </el-form-item>
        </el-form>
      </template>

      <div class="page-note">
        <p>说明：</p>
        <p>1. 按会员手机号查询后输入消费金额，系统按等级折扣 → 优惠券 → 积分抵扣顺序计算实付金额。</p>
        <p>2. 点击「确认收款」从会员余额扣款，交易写入买单记录；全额退款时余额原路退回并返还积分、释放优惠券。</p>
        <p>3. 会员余额不足时无法收款，可引导会员先充值。</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.maidan-add-page {
  padding: 12px;
}
.page-card {
  background: #fff;
  border-radius: 4px;
  padding: 16px 20px;
}
.card-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #ebeef5;
}
.pay-form {
  max-width: 860px;
}
.form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
.member-card {
  max-width: 860px;
  margin: 0 0 16px;
  padding: 12px 16px;
  background: #f0f9eb;
  border: 1px solid #e1f3d8;
  border-radius: 4px;
}
.member-line {
  display: flex;
  align-items: center;
  gap: 10px;
}
.member-line.sub {
  margin-top: 6px;
  color: #606266;
  font-size: 13px;
}
.member-name {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.member-tel {
  color: #909399;
  font-size: 13px;
}
.preview-box {
  max-width: 560px;
  margin: 0 0 16px;
  padding: 12px 16px;
  background: #f7f8fa;
  border-radius: 4px;
}
.preview-row {
  display: flex;
  justify-content: space-between;
  padding: 4px 0;
  font-size: 13px;
  color: #606266;
}
.preview-row b {
  color: #303133;
}
.preview-row b.minus {
  color: #f56c6c;
}
.preview-row.total {
  border-top: 1px dashed #dcdfe6;
  margin-top: 6px;
  padding-top: 10px;
  font-size: 15px;
}
.preview-row.total b {
  color: #f56c6c;
  font-size: 18px;
}
.preview-row.warn {
  color: #f56c6c;
  font-weight: 600;
}
.page-note {
  margin-top: 8px;
  padding: 12px 14px;
  background: #f7f8fa;
  border-radius: 4px;
  color: #909399;
  font-size: 12px;
  line-height: 1.9;
  max-width: 860px;
}
.page-note p {
  margin: 0;
}
</style>
