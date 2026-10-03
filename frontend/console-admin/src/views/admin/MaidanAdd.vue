<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
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
import { getStoreList } from '../../api/store'

const tel = ref('')
const searching = ref(false)
const member = ref<MaidanMember | null>(null)

const mdid = ref(0)
const money = ref<number>(0)
const couponrid = ref(0)
const useScore = ref(false)
const coupons = ref<MaidanCoupon[]>([])

const stores = ref<{ id: number; name: string }[]>([])

const previewing = ref(false)
const preview = ref<MaidanPreviewResult | null>(null)
const paying = ref(false)

const hasDiscount = computed(() => {
  const d = Number(member.value?.discount ?? 10)
  return d > 0 && d < 10
})

async function loadStores() {
  try {
    const result = await getStoreList({ page: 1, pagesize: 100 })
    stores.value = (result.list || []).map((s) => ({ id: s.id, name: s.name }))
  } catch {
    stores.value = []
  }
}

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

async function handlePay() {
  if (!member.value) {
    ElMessage.warning('请先查询会员')
    return
  }
  if (!(Number(money.value) > 0)) {
    ElMessage.warning('请输入正确的金额')
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
    await mdPay({
      tel: tel.value.trim(),
      money: Number(money.value) || 0,
      couponrid: couponrid.value,
      usescore: useScore.value ? 1 : 0,
      mdid: mdid.value || 0,
    })
    ElMessage.success(`收款成功，实付 ¥${payMoney.toFixed(2)}`)
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

onMounted(() => {
  loadStores()
})
</script>

<template>
  <div class="maidan-add-page">
    <el-card shadow="never" class="add-card">
      <template #header>
        <span class="card-header-text">买单扣费</span>
      </template>

      <el-form label-width="110px" class="add-form">
        <el-form-item label="会员手机号">
          <el-input
            v-model="tel"
            placeholder="请输入会员手机号"
            style="width: 220px"
            maxlength="20"
            clearable
            @keyup.enter="handleSearch"
          />
          <el-button :loading="searching" @click="handleSearch" style="margin-left: 10px">查找会员</el-button>
        </el-form-item>

        <el-form-item label="昵称">
          <el-input
            :model-value="member ? (member.nickname || ('会员' + member.mid)) : '暂无'"
            readonly
            style="width: 220px"
          />
          <span class="form-aux" v-if="!member">请输入手机号点击查找</span>
        </el-form-item>

        <el-form-item label="余额">
          <el-input
            :model-value="member ? '¥' + Number(member.money).toFixed(2) : '暂无'"
            readonly
            style="width: 220px"
          />
          <span class="form-aux" v-if="member && member.levelname">{{ member.levelname }}</span>
          <span class="form-aux" v-if="member && hasDiscount">{{ member.discount }}折</span>
        </el-form-item>

        <el-form-item label="所属门店">
          <el-select v-model="mdid" placeholder="请选择" clearable style="width: 220px">
            <el-option label="请选择" :value="0" />
            <el-option v-for="s in stores" :key="s.id" :label="s.name" :value="s.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="支付金额">
          <el-input-number
            v-model="money"
            :min="0"
            :max="9999999"
            :precision="2"
            :step="1"
            style="width: 200px"
            @change="handleMoneyChange"
          />
          <span class="form-aux">仅支持余额支付</span>
        </el-form-item>

        <template v-if="member">
          <el-form-item label="优惠券" v-if="coupons.length > 0">
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
          </el-form-item>
        </template>

        <el-form-item v-if="preview" v-loading="previewing">
          <div class="preview-box">
            <div class="preview-row">
              <span>消费金额</span><b>¥{{ Number(preview.money).toFixed(2) }}</b>
            </div>
            <div class="preview-row" v-if="preview.disprice > 0">
              <span>会员折扣</span><b class="minus">-¥{{ Number(preview.disprice).toFixed(2) }}</b>
            </div>
            <div class="preview-row" v-if="preview.couponmoney > 0">
              <span>优惠券抵扣</span><b class="minus">-¥{{ Number(preview.couponmoney).toFixed(2) }}</b>
            </div>
            <div class="preview-row" v-if="preview.scoredk > 0">
              <span>积分抵扣（{{ preview.decscore }}积分）</span><b class="minus">-¥{{ Number(preview.scoredk).toFixed(2) }}</b>
            </div>
            <div class="preview-row total">
              <span>实付金额</span><b>¥{{ Number(preview.paymoney).toFixed(2) }}</b>
            </div>
            <div class="preview-row warn" v-if="!preview.enough">
              <span>会员余额不足，无法完成收款</span>
            </div>
          </div>
        </el-form-item>

        <el-form-item>
          <el-button
            type="success"
            :loading="paying"
            :disabled="!member || !(Number(money) > 0) || (preview !== null && (!preview.enough))"
            @click="handlePay"
          >
            提 交
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.maidan-add-page {
  padding: 8px;
}
.add-card :deep(.el-card__header) {
  padding: 10px 16px;
}
.card-header-text {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.add-card :deep(.el-card__body) {
  padding: 16px 20px;
}
.add-form {
  max-width: 760px;
}
.form-aux {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
.preview-box {
  width: 460px;
  padding: 10px 16px;
  background: #f7f8fa;
  border-radius: 4px;
}
.preview-row {
  display: flex;
  justify-content: space-between;
  padding: 3px 0;
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
  padding-top: 8px;
  font-size: 14px;
}
.preview-row.total b {
  color: #f56c6c;
  font-size: 17px;
}
.preview-row.warn {
  color: #f56c6c;
  font-weight: 600;
}
</style>
