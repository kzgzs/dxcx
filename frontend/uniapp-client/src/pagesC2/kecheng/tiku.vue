<template>
	<view class="tk-page">
		<!-- 加载/结束态 -->
		<view v-if="!ready" class="tk-loading">
			<c2-empty v-if="ended" text="本场考试已结束" />
			<view v-else class="tk-loading__text">正在抽题…</view>
		</view>

		<template v-else>
			<!-- 顶部进度 -->
			<view class="tk-head">
				<view class="tk-head__num">
					<text class="tk-head__cur">{{ hasnum }}</text>
					<text class="tk-head__total">/{{ nums }}</text>
				</view>
				<view v-if="lefttime > 0" class="tk-head__time" :class="{ 'tk-head__time--warn': lefttime <= 60 }">
					{{ formatLeft(lefttime) }}
				</view>
			</view>

			<!-- 题干 -->
			<view class="tk-body">
				<view class="tk-title">
					<text class="tk-title__tag">{{ tktype === 1 ? (isMulti ? "多选题" : "单选题") : "填空题" }}</text>
					<text class="tk-title__text">{{ tk.title }}</text>
				</view>

				<!-- 选择题选项 -->
				<template v-if="tktype === 1">
					<view
						v-for="(opt, idx) in options"
						:key="idx"
						class="tk-opt"
						:class="{ 'tk-opt--on': picked.includes(idx) }"
						@click="toggleOpt(idx)"
					>
						<view class="tk-opt__sort">{{ sortLetter(idx) }}</view>
						<view class="tk-opt__text">{{ opt }}</view>
						<view v-if="picked.includes(idx)" class="tk-opt__check">✓</view>
					</view>
				</template>

				<!-- 填空题输入 -->
				<view v-else class="tk-fill">
					<input
						v-model="fillText"
						class="tk-fill__input"
						placeholder="请输入答案"
						placeholder-class="tk-fill__ph"
						:disabled="viewOnly"
					/>
				</view>

				<!-- 回看已作答提示 -->
				<view v-if="viewOnly" class="tk-viewtip">该题已作答，点击下一题继续</view>
			</view>

			<!-- 底部操作 -->
			<view class="tk-foot">
				<view class="tk-foot__btn tk-foot__btn--plain" :class="{ 'tk-foot__btn--off': hasnum <= 1 }" @click="goPrev">
					上一题
				</view>
				<view class="tk-foot__btn" @click="goNext">{{ hasnum >= nums ? "提交交卷" : "下一题" }}</view>
			</view>
		</template>
	</view>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from "vue";
import { onLoad, onUnload } from "@dcloudio/uni-app";
import c2Empty from "../components/c2-empty.vue";
import {
	examGetTiku,
	examNextQuestion,
	examPrevQuestion,
	examToFinish,
	SORTS,
	type TkQuestion,
} from "../api/kecheng";
import { goPage } from "@/utils/navigate";

const kcid = ref(0);
const mlid = ref(0);
const ready = ref(false);
const ended = ref(false);
const viewOnly = ref(false);

const tk = ref<TkQuestion>({ id: 0 });
const dtid = ref(0);
const rid = ref(0);
const nums = ref(0);
const hasnum = ref(1);
const lefttime = ref(0);

const picked = ref<number[]>([]);
const fillText = ref("");
let timer: ReturnType<typeof setInterval> | null = null;
let submitting = false;

const tktype = computed(() => Number(tk.value.type ?? 1));
const options = computed<string[]>(() => (Array.isArray(tk.value.option) ? (tk.value.option as string[]) : []));
/** 后端按 right_option 逗号下标数判多选，前端以选项数>1提供多选交互；单选场景只允许单选 */
const isMulti = ref(false);

function sortLetter(idx: number): string {
	return SORTS[idx] ?? String(idx + 1);
}

function formatLeft(sec: number): string {
	const m = Math.floor(sec / 60);
	const s = sec % 60;
	return `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

function startTimer() {
	if (lefttime.value <= 0 || timer) return;
	timer = setInterval(() => {
		lefttime.value -= 1;
		if (lefttime.value <= 0) {
			clearTimer();
			uni.showToast({ title: "考试时间已到", icon: "none" });
			goPage(`/pagesC2/kecheng/complete?rid=${rid.value}`);
		}
	}, 1000);
}

function clearTimer() {
	if (timer) {
		clearInterval(timer);
		timer = null;
	}
}

function applyQuestion(q?: TkQuestion) {
	if (!q) return;
	tk.value = q;
	picked.value = [];
	fillText.value = "";
	viewOnly.value = false;
	// 交互层：选项题均支持多选 toggle，单选/多选由后端判分口径兼容
	isMulti.value = options.value.length > 1;
}

async function loadTiku() {
	try {
		const res = await examGetTiku(kcid.value, mlid.value);
		if (Number(res.ended ?? 0) === 1) {
			ended.value = true;
			return;
		}
		rid.value = Number(res.rid ?? 0);
		dtid.value = Number(res.dtid ?? 0);
		nums.value = Number(res.nums ?? 0);
		hasnum.value = Number(res.hasnum ?? 1);
		lefttime.value = Number(res.lefttime ?? 0);
		applyQuestion(res.tkdata);
		ready.value = true;
		startTimer();
	} catch (err) {
		uni.showToast({ title: (err as Error).message || "抽题失败", icon: "none" });
	}
}

function toggleOpt(idx: number) {
	if (viewOnly.value || submitting) return;
	const arr = picked.value;
	if (isMulti.value) {
		const at = arr.indexOf(idx);
		if (at >= 0) {
			arr.splice(at, 1);
		} else {
			arr.push(idx);
		}
	} else {
		picked.value = [idx];
	}
}

function buildAnswer(): number | number[] | string | null {
	if (tktype.value === 2) {
		const text = fillText.value.trim();
		if (text === "") {
			uni.showToast({ title: "请输入答案", icon: "none" });
			return null;
		}
		return text;
	}
	if (picked.value.length === 0) {
		uni.showToast({ title: "请选择答案", icon: "none" });
		return null;
	}
	const sorted = [...picked.value].sort((a, b) => a - b);
	return sorted.length > 1 ? sorted : String(sorted[0]);
}

async function goNext() {
	if (submitting) return;
	const answer = buildAnswer();
	if (answer === null) return;
	submitting = true;
	try {
		const res = await examNextQuestion(dtid.value, answer);
		if (Number(res.finished ?? 0) === 1) {
			// 最后一题：改调交卷
			const fin = await examToFinish(dtid.value, answer);
			clearTimer();
			goPage(`/pagesC2/kecheng/complete?rid=${Number(fin.rid ?? rid.value)}`);
			return;
		}
		if (res.tkdata) {
			applyQuestion(res.tkdata);
		}
		if (typeof res.hasnum === "number") hasnum.value = res.hasnum;
		if (typeof res.dtid === "number") dtid.value = res.dtid;
	} catch (err) {
		uni.showToast({ title: (err as Error).message || "提交失败", icon: "none" });
	} finally {
		submitting = false;
	}
}

async function goPrev() {
	if (submitting || hasnum.value <= 1) return;
	submitting = true;
	try {
		const res = await examPrevQuestion(dtid.value);
		if (res.tkdata) {
			applyQuestion(res.tkdata);
			viewOnly.value = true;
			// 回显已作答内容
			if (res.answer !== undefined && res.answer !== null) {
				if (Number(res.tkdata.type ?? 1) === 1) {
					const idxs = String(res.answer)
						.split(",")
						.map((v) => Number(v))
						.filter((v) => Number.isFinite(v));
					picked.value = idxs;
				} else {
					fillText.value = String(res.answer);
				}
			}
		}
		if (typeof res.hasnum === "number") hasnum.value = res.hasnum;
		if (typeof res.dtid === "number") dtid.value = res.dtid;
	} catch (err) {
		uni.showToast({ title: (err as Error).message || "没有上一题了", icon: "none" });
	} finally {
		submitting = false;
	}
}

onLoad((opts) => {
	kcid.value = Number(opts?.kcid ?? 0);
	mlid.value = Number(opts?.mlid ?? 0);
	if (kcid.value <= 0) {
		uni.showToast({ title: "参数错误", icon: "none" });
		return;
	}
	void loadTiku();
});

onUnload(() => clearTimer());
onBeforeUnmount(() => clearTimer());
</script>

<style scoped>
.tk-page {
	min-height: 100vh;
	background: #f6f6fa;
	padding-bottom: 180rpx;
	box-sizing: border-box;
}
.tk-loading {
	padding-top: 240rpx;
	display: flex;
	justify-content: center;
}
.tk-loading__text {
	color: #999;
	font-size: 28rpx;
}
.tk-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 24rpx 32rpx;
	background: #fff;
}
.tk-head__cur {
	color: #7b7de5;
	font-size: 40rpx;
	font-weight: bold;
}
.tk-head__total {
	color: #999;
	font-size: 28rpx;
	margin-left: 4rpx;
}
.tk-head__time {
	color: #333;
	font-size: 30rpx;
	background: #f2f2f8;
	padding: 8rpx 24rpx;
	border-radius: 32rpx;
}
.tk-head__time--warn {
	color: #fff;
	background: #fa5151;
}
.tk-body {
	margin: 24rpx;
	background: #fff;
	border-radius: 16rpx;
	padding: 32rpx;
}
.tk-title {
	margin-bottom: 32rpx;
}
.tk-title__tag {
	display: inline-block;
	color: #7b7de5;
	font-size: 22rpx;
	border: 1rpx solid #7b7de5;
	border-radius: 8rpx;
	padding: 4rpx 12rpx;
	margin-right: 12rpx;
	vertical-align: 4rpx;
}
.tk-title__text {
	color: #333;
	font-size: 32rpx;
	line-height: 1.6;
}
.tk-opt {
	display: flex;
	align-items: center;
	border: 1rpx solid #e6e6ee;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 20rpx;
}
.tk-opt--on {
	border-color: #7b7de5;
	background: #f4f4fd;
}
.tk-opt__sort {
	width: 48rpx;
	height: 48rpx;
	line-height: 48rpx;
	text-align: center;
	border-radius: 50%;
	background: #f2f2f8;
	color: #666;
	font-size: 26rpx;
	margin-right: 20rpx;
	flex-shrink: 0;
}
.tk-opt--on .tk-opt__sort {
	background: #7b7de5;
	color: #fff;
}
.tk-opt__text {
	flex: 1;
	color: #333;
	font-size: 28rpx;
	line-height: 1.5;
}
.tk-opt__check {
	color: #7b7de5;
	font-size: 30rpx;
	margin-left: 12rpx;
}
.tk-fill__input {
	border: 1rpx solid #e6e6ee;
	border-radius: 12rpx;
	padding: 24rpx;
	font-size: 30rpx;
	color: #333;
}
.tk-fill__ph {
	color: #bbb;
}
.tk-viewtip {
	margin-top: 24rpx;
	color: #999;
	font-size: 24rpx;
	text-align: center;
}
.tk-foot {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	gap: 24rpx;
	padding: 20rpx 32rpx calc(20rpx + env(safe-area-inset-bottom));
	background: #fff;
	box-shadow: 0 -4rpx 16rpx rgba(0, 0, 0, 0.04);
}
.tk-foot__btn {
	flex: 1;
	height: 84rpx;
	line-height: 84rpx;
	text-align: center;
	border-radius: 42rpx;
	background: #7b7de5;
	color: #fff;
	font-size: 30rpx;
}
.tk-foot__btn--plain {
	background: #f2f2f8;
	color: #333;
}
.tk-foot__btn--off {
	opacity: 0.45;
}
</style>
