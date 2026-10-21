<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { computed, ref } from "vue";
import { fetchFormDetail, formPay, formSubmit } from "../../api/form";
import type { FormDetailResult, FormFieldDef } from "../../api/form";
import { uploadImage } from "../../api/mall";

/**
 * C端自定义表单填写页（终端用户）
 * 链路：租户后台设计表单 → 设计器「表单」组件（页面路径 pagesD/form/detail?id=）→ 本页填写提交
 * - 全字段类型渲染（input/textarea/radio/checkbox/selector/time/date/year/region/upload/upload_pics）
 * - 必填校验（val3=1）；开启在线支付且金额>0 时提交后弹收银台支付
 */
const formId = ref(0);
const loading = ref(true);
const detail = ref<FormDetailResult | null>(null);
const notFound = ref(false);

// 各字段填写值：form0..formN（键与 content 下标一致）
const values = ref<Record<string, unknown>>({});
// 图片上传预览（upload 单图 / upload_pics 多图）
const picPreviews = ref<Record<string, string[]>>({});

const submitting = ref(false);
const paySheet = ref(false);
const payType = ref<"wechat" | "alipay">("wechat");
const paying = ref(false);
const payOrderId = ref(0);
const payMoney = ref(0);
// priceedit=1 时用户自填金额
const userPrice = ref("");

onLoad((options) => {
  formId.value = Number(options && options.id) || 0;
  if (formId.value > 0) void load();
  else {
    loading.value = false;
    notFound.value = true;
  }
});

async function load() {
  loading.value = true;
  try {
    detail.value = await fetchFormDetail(formId.value);
    // 初始化各字段默认值
    (detail.value.content || []).forEach((f, k) => {
      const key = "form" + k;
      if (f.key === "checkbox") values.value[key] = [];
      else if (f.key === "upload_pics") values.value[key] = [];
      else values.value[key] = "";
      if (f.key === "upload" || f.key === "upload_pics") picPreviews.value[key] = [];
    });
    notFound.value = false;
  } catch {
    notFound.value = true;
  } finally {
    loading.value = false;
  }
}

/** 实际支付金额：开启在线支付时，priceedit=1 取用户输入，否则默认金额 */
const payAmount = computed(() => {
  if (!detail.value || detail.value.payset !== 1) return 0;
  if (detail.value.priceedit === 1) {
    const v = parseFloat(userPrice.value);
    return isNaN(v) || v < 0 ? 0 : v;
  }
  return parseFloat(detail.value.price) || 0;
});

function isOptionType(key: string) {
  return key === "radio" || key === "checkbox" || key === "selector";
}

function options(f: FormFieldDef): string[] {
  return Array.isArray(f.val2) ? (f.val2 as unknown[]).map((x) => String(x)) : [];
}

/** 时间/日期/年份起止范围（val2=[min,max]） */
function rangeOf(f: FormFieldDef): [string, string] {
  const arr = Array.isArray(f.val2) ? (f.val2 as unknown[]).map((x) => String(x)) : [];
  return [arr[0] || "", arr[1] || ""];
}

function toggleCheckbox(key: string, opt: string) {
  const arr = Array.isArray(values.value[key]) ? (values.value[key] as string[]) : [];
  const idx = arr.indexOf(opt);
  if (idx >= 0) arr.splice(idx, 1);
  else arr.push(opt);
  values.value[key] = [...arr];
}

function onTimeChange(key: string, e: { detail: { value: string } }) {
  values.value[key] = e.detail.value;
}
function onDateChange(key: string, e: { detail: { value: string } }) {
  values.value[key] = e.detail.value;
}
function onRegionChange(key: string, e: { detail: { value: string[] } }) {
  values.value[key] = (e.detail.value || []).join(",");
}

/** 单图/多图上传（复用 /api/upload） */
function choosePic(key: string, multi: boolean) {
  const max = multi ? 9 : 1;
  const current = picPreviews.value[key] || [];
  const remain = max - current.length;
  if (remain <= 0) {
    uni.showToast({ title: multi ? "最多上传9张图片" : "只能上传1张图片", icon: "none" });
    return;
  }
  uni.chooseImage({
    count: remain,
    success: (res) => {
      const paths = Array.isArray(res.tempFilePaths) ? res.tempFilePaths : [res.tempFilePaths];
      paths.forEach((p: string) => void uploadOne(key, p, multi));
    },
  });
}

async function uploadOne(key: string, local: string, multi: boolean) {
  try {
    const data = await uploadImage(local);
    const arr = [...(picPreviews.value[key] || [])];
    if (!multi) arr.length = 0;
    arr.push(data.url);
    picPreviews.value[key] = arr;
    values.value[key] = multi ? [...arr] : arr[0];
  } catch {
    uni.showToast({ title: "图片上传失败", icon: "none" });
  }
}

function removePic(key: string, idx: number, multi: boolean) {
  const arr = [...(picPreviews.value[key] || [])];
  arr.splice(idx, 1);
  picPreviews.value[key] = arr;
  values.value[key] = multi ? [...arr] : arr[0] || "";
}

/** 提交（前端先做必填校验，后端二次校验） */
async function submit() {
  if (!detail.value || submitting.value) return;
  const content = detail.value.content || [];
  for (let k = 0; k < content.length; k++) {
    const f = content[k];
    if (f.val3 !== 1) continue;
    const v = values.value["form" + k];
    const empty = Array.isArray(v) ? v.length === 0 : v === "" || v === null || v === undefined;
    if (empty) {
      uni.showToast({ title: (f.val1 || "该项") + " 必填", icon: "none" });
      return;
    }
  }
  if (detail.value.payset === 1 && detail.value.priceedit === 1) {
    if (isNaN(parseFloat(userPrice.value)) || parseFloat(userPrice.value) < 0) {
      uni.showToast({ title: "请填写正确的支付金额", icon: "none" });
      return;
    }
  }

  submitting.value = true;
  try {
    const res = await formSubmit({
      formid: formId.value,
      formdata: values.value,
      price: detail.value.payset === 1 && detail.value.priceedit === 1 ? parseFloat(userPrice.value) : undefined,
      fromurl: "pagesD/form/detail?id=" + formId.value,
      platform: "uniapp",
    });
    if (res.status === 2) {
      // 需要支付：弹收银台
      payOrderId.value = res.orderid;
      payMoney.value = res.money || payAmount.value;
      paySheet.value = true;
    } else {
      uni.showToast({ title: "提交成功", icon: "success" });
      setTimeout(() => uni.navigateBack(), 900);
    }
  } catch {
    // request 层已弹 toast
  } finally {
    submitting.value = false;
  }
}

function closePay() {
  if (paying.value) return;
  paySheet.value = false;
}

async function confirmPay() {
  if (paying.value || payOrderId.value <= 0) return;
  paying.value = true;
  try {
    const res = await formPay(payOrderId.value, payType.value);
    if (res.status === 1) {
      paySheet.value = false;
      uni.showToast({ title: "支付成功", icon: "success" });
      setTimeout(() => uni.navigateBack(), 900);
    } else {
      uni.showToast({ title: "支付未完成", icon: "none" });
    }
  } catch {
    // request 层已弹 toast
  } finally {
    paying.value = false;
  }
}
</script>

<template>
  <view v-if="detail" class="page">
    <view class="head">
      <text class="title">{{ detail.name }}</text>
    </view>

    <!-- 支付金额（开启在线支付且允许用户填写时） -->
    <view v-if="detail.payset === 1 && detail.priceedit === 1" class="card">
      <view class="field">
        <text class="field-label">支付金额(元)</text>
        <input v-model="userPrice" class="field-input" type="digit" placeholder="请填写支付金额" />
      </view>
    </view>
    <view v-else-if="detail.payset === 1 && payAmount > 0" class="card">
      <view class="field">
        <text class="field-label">支付金额</text>
        <text class="field-price">￥{{ payAmount.toFixed(2) }}</text>
      </view>
    </view>

    <!-- 表单项 -->
    <view class="card">
      <view v-for="(f, k) in detail.content" :key="k" class="field-block">
        <view class="field-title">
          <text v-if="f.val3 === 1" class="req">*</text>
          <text :class="f.val8 === 1 ? 'bold' : ''">{{ f.val1 }}</text>
        </view>

        <!-- 单行输入 -->
        <input
          v-if="f.key === 'input'"
          :value="String(values['form' + k] ?? '')"
          class="field-input"
          :type="f.val4 === 1 ? 'number' : 'text'"
          :placeholder="String(f.val2 || '请输入')"
          @input="values['form' + k] = String(($event as any).detail.value)"
        />

        <!-- 多行输入 -->
        <textarea
          v-else-if="f.key === 'textarea'"
          :value="String(values['form' + k] ?? '')"
          class="field-textarea"
          :placeholder="String(f.val2 || '请输入')"
          @input="values['form' + k] = String(($event as any).detail.value)"
        />

        <!-- 单项选择（val10: 0一行多个 1一行一个；val11: 0无边框 1有边框） -->
        <view v-else-if="f.key === 'radio'" class="opt-wrap" :class="{ 'opt-col': f.val10 === 1 }">
          <view
            v-for="opt in options(f)"
            :key="opt"
            class="opt-item"
            :class="{ bordered: f.val11 === 1, active: values['form' + k] === opt }"
            @click="values['form' + k] = opt"
          >
            <text>{{ opt }}</text>
          </view>
        </view>

        <!-- 多项选择（val4: 0一行多个 1一行一个；val9: 0无边框 1有边框） -->
        <view v-else-if="f.key === 'checkbox'" class="opt-wrap" :class="{ 'opt-col': f.val4 === 1 }">
          <view
            v-for="opt in options(f)"
            :key="opt"
            class="opt-item"
            :class="{ bordered: f.val9 === 1, active: Array.isArray(values['form' + k]) && (values['form' + k] as string[]).indexOf(opt) >= 0 }"
            @click="toggleCheckbox('form' + k, opt)"
          >
            <text>{{ opt }}</text>
          </view>
        </view>

        <!-- 下拉选择 -->
        <picker
          v-else-if="f.key === 'selector'"
          mode="selector"
          :range="options(f)"
          @change="values['form' + k] = options(f)[Number($event.detail.value)] || ''"
        >
          <view class="field-picker">
            <text :class="values['form' + k] ? '' : 'ph'">{{ values['form' + k] || '请选择' }}</text>
            <text class="arrow">›</text>
          </view>
        </picker>

        <!-- 时间选择 -->
        <picker
          v-else-if="f.key === 'time'"
          mode="time"
          :start="rangeOf(f)[0] || '00:00'"
          :end="rangeOf(f)[1] || '23:59'"
          @change="onTimeChange('form' + k, $event)"
        >
          <view class="field-picker">
            <text :class="values['form' + k] ? '' : 'ph'">{{ values['form' + k] || '请选择时间' }}</text>
            <text class="arrow">›</text>
          </view>
        </picker>

        <!-- 日期选择 -->
        <picker
          v-else-if="f.key === 'date'"
          mode="date"
          :start="rangeOf(f)[0] || '1970-01-01'"
          :end="rangeOf(f)[1] || '2080-01-01'"
          @change="onDateChange('form' + k, $event)"
        >
          <view class="field-picker">
            <text :class="values['form' + k] ? '' : 'ph'">{{ values['form' + k] || '请选择日期' }}</text>
            <text class="arrow">›</text>
          </view>
        </picker>

        <!-- 年份选择 -->
        <picker
          v-else-if="f.key === 'year'"
          mode="date"
          fields="year"
          :start="rangeOf(f)[0] || '1970'"
          :end="rangeOf(f)[1] || '2080'"
          @change="onDateChange('form' + k, $event)"
        >
          <view class="field-picker">
            <text :class="values['form' + k] ? '' : 'ph'">{{ values['form' + k] || '请选择年份' }}</text>
            <text class="arrow">›</text>
          </view>
        </picker>

        <!-- 省市区选择 -->
        <picker v-else-if="f.key === 'region'" mode="region" @change="onRegionChange('form' + k, $event)">
          <view class="field-picker">
            <text :class="values['form' + k] ? '' : 'ph'">{{ values['form' + k] || '请选择省市区' }}</text>
            <text class="arrow">›</text>
          </view>
        </picker>

        <!-- 上传图片（单图） -->
        <view v-else-if="f.key === 'upload'" class="pic-wrap">
          <view v-if="picPreviews['form' + k] && picPreviews['form' + k].length" class="pic-item">
            <image :src="picPreviews['form' + k][0]" mode="aspectFill" class="pic" />
            <text class="pic-del" @click="removePic('form' + k, 0, false)">×</text>
          </view>
          <view v-else class="pic-add" @click="choosePic('form' + k, false)"><text>+</text></view>
          <text v-if="f.val15" class="pic-tip">{{ f.val15 }}</text>
        </view>

        <!-- 上传多图 -->
        <view v-else-if="f.key === 'upload_pics'" class="pic-wrap pics-row">
          <view v-for="(p, pi) in picPreviews['form' + k] || []" :key="pi" class="pic-item">
            <image :src="p" mode="aspectFill" class="pic" />
            <text class="pic-del" @click="removePic('form' + k, pi, true)">×</text>
          </view>
          <view v-if="(picPreviews['form' + k] || []).length < 9" class="pic-add" @click="choosePic('form' + k, true)"><text>+</text></view>
          <text v-if="f.val15" class="pic-tip">{{ f.val15 }}</text>
        </view>
      </view>
    </view>

    <view class="bottom-space"></view>
    <view class="bottom-bar">
      <text class="submit-btn" :class="{ disabled: submitting }" @click="submit">
        {{ submitting ? "提交中..." : detail.payset === 1 && payAmount > 0 ? "提交并支付 ￥" + payAmount.toFixed(2) : "提交" }}
      </text>
    </view>

    <!-- 收银台弹层（逻辑对齐 pagesM/pay/pay.vue：支付方式单选 + mock 入账口径） -->
    <view v-if="paySheet" class="sheet-mask" @click="closePay">
      <view class="sheet" @click.stop>
        <view class="sheet-head">
          <text class="sheet-title">收银台</text>
          <text class="sheet-close" @click="closePay">×</text>
        </view>
        <text class="sheet-amount">￥{{ payMoney.toFixed(2) }}</text>
        <view class="pay-row" @click="payType = 'wechat'">
          <text class="pay-name">微信支付</text>
          <view class="radio" :class="{ checked: payType === 'wechat' }"></view>
        </view>
        <view class="pay-row" @click="payType = 'alipay'">
          <text class="pay-name">支付宝</text>
          <view class="radio" :class="{ checked: payType === 'alipay' }"></view>
        </view>
        <view class="pay-btn" :class="{ disabled: paying }" @click="confirmPay">
          <text>{{ paying ? "支付中..." : "确认支付" }}</text>
        </view>
      </view>
    </view>
  </view>

  <view v-else class="empty">
    <text>{{ loading ? "加载中..." : notFound ? "表单不存在或已删除" : "加载失败" }}</text>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 40rpx;
}
.head {
  background: #fff;
  padding: 32rpx 30rpx;
}
.title {
  font-size: 34rpx;
  font-weight: 600;
  color: #333;
}
.card {
  background: #fff;
  margin: 20rpx 24rpx 0;
  border-radius: 16rpx;
  padding: 24rpx;
}
.field-block {
  padding: 20rpx 0;
  border-bottom: 1rpx solid #f0f0f0;
}
.field-block:last-child {
  border-bottom: none;
}
.field-title {
  font-size: 28rpx;
  color: #333;
  margin-bottom: 16rpx;
}
.field-title .bold {
  font-weight: 600;
}
.req {
  color: #f56c6c;
  margin-right: 4rpx;
}
.field-input {
  border: 1rpx solid #e5e5e5;
  border-radius: 8rpx;
  padding: 16rpx 20rpx;
  font-size: 28rpx;
}
.field-textarea {
  border: 1rpx solid #e5e5e5;
  border-radius: 8rpx;
  padding: 16rpx 20rpx;
  font-size: 28rpx;
  width: 100%;
  height: 160rpx;
  box-sizing: border-box;
}
.field-price {
  font-size: 30rpx;
  color: #f56c6c;
  font-weight: 600;
}
.field {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.field-label {
  font-size: 28rpx;
  color: #333;
}
.opt-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
}
.opt-col {
  flex-direction: column;
}
.opt-item {
  padding: 12rpx 28rpx;
  border-radius: 8rpx;
  background: #f5f5f5;
  font-size: 26rpx;
  color: #333;
}
.opt-item.bordered {
  background: #fff;
  border: 1rpx solid #ddd;
}
.opt-item.active {
  background: #e8f3ff;
  color: #1989fa;
  border: 1rpx solid #1989fa;
}
.field-picker {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border: 1rpx solid #e5e5e5;
  border-radius: 8rpx;
  padding: 16rpx 20rpx;
  font-size: 28rpx;
}
.field-picker .ph {
  color: #999;
}
.arrow {
  color: #ccc;
  font-size: 32rpx;
}
.pic-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
  align-items: flex-start;
}
.pics-row {
  flex-direction: row;
}
.pic-item {
  position: relative;
  width: 160rpx;
  height: 160rpx;
}
.pic {
  width: 160rpx;
  height: 160rpx;
  border-radius: 8rpx;
}
.pic-del {
  position: absolute;
  top: -12rpx;
  right: -12rpx;
  width: 36rpx;
  height: 36rpx;
  line-height: 32rpx;
  text-align: center;
  background: rgba(0, 0, 0, 0.5);
  color: #fff;
  border-radius: 50%;
  font-size: 26rpx;
}
.pic-add {
  width: 160rpx;
  height: 160rpx;
  border: 1rpx dashed #ccc;
  border-radius: 8rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #999;
  font-size: 56rpx;
}
.pic-tip {
  width: 100%;
  font-size: 24rpx;
  color: #999;
}
.bottom-space {
  height: 140rpx;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  background: #fff;
  padding: 20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));
}
.submit-btn {
  display: block;
  background: #1989fa;
  color: #fff;
  text-align: center;
  border-radius: 44rpx;
  padding: 22rpx 0;
  font-size: 30rpx;
}
.submit-btn.disabled {
  opacity: 0.6;
}
.empty {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #999;
  font-size: 28rpx;
}
/* 收银台弹层（对齐 pagesM/pay/pay.vue 样式口径） */
.sheet-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 99;
  display: flex;
  align-items: flex-end;
}
.sheet {
  width: 100%;
  background: #fff;
  border-radius: 24rpx 24rpx 0 0;
  padding: 32rpx 32rpx calc(32rpx + env(safe-area-inset-bottom));
  box-sizing: border-box;
}
.sheet-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.sheet-title {
  font-size: 32rpx;
  font-weight: 600;
  color: #333;
}
.sheet-close {
  font-size: 44rpx;
  color: #999;
  line-height: 1;
}
.sheet-amount {
  display: block;
  text-align: center;
  font-size: 48rpx;
  font-weight: 700;
  color: #333;
  margin: 32rpx 0;
}
.pay-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 24rpx 8rpx;
  border-bottom: 1rpx solid #f0f0f0;
}
.pay-name {
  font-size: 28rpx;
  color: #333;
}
.radio {
  width: 36rpx;
  height: 36rpx;
  border-radius: 50%;
  border: 2rpx solid #ccc;
  box-sizing: border-box;
}
.radio.checked {
  border-color: #1989fa;
  background: #1989fa;
  box-shadow: inset 0 0 0 6rpx #fff;
}
.pay-btn {
  margin-top: 32rpx;
  background: #1989fa;
  color: #fff;
  text-align: center;
  border-radius: 44rpx;
  padding: 22rpx 0;
  font-size: 30rpx;
}
.pay-btn.disabled {
  opacity: 0.6;
}
</style>
