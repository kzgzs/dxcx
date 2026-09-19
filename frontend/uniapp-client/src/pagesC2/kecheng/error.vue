<template>
	<view class="er-page">
		<view v-if="!loaded" class="er-tip">加载中…</view>
		<view v-else-if="notFound" class="er-empty">
			<c2-empty text="没有错题记录" />
		</view>

		<template v-else>
			<!-- 进度 -->
			<view class="er-head">
				<text class="er-head__text">共 {{ nums }} 道错题 · 第 {{ curPos }} 题</text>
			</view>

			<view class="er-card">
				<view class="er-title">
					<text class="er-title__tag">{{ Number(tk.type ?? 1) === 1 ? "选择题" : "填空题" }}</text>
					<text class="er-title__text">{{ tk.title }}</text>
				</view>

				<!-- 选择题选项（高亮正确项与我的作答） -->
				<template v-if="Number(tk.type ?? 1) === 1">
					<view
						v-for="(opt, idx) in options"
						:key="idx"
						class="er-opt"
						:class="{ 'er-opt--right': rightIdx.includes(idx), 'er-opt--wrong': myIdx.includes(idx) && !rightIdx.includes(idx) }"
					>
						<view class="er-opt__sort">{{ sortLetter(idx) }}</view>
						<view class="er-opt__text">{{ opt }}</view>
						<text v-if="rightIdx.includes(idx)" class="er-opt__mark er-opt__mark--right">正确答案</text>
						<text v-else-if="myIdx.includes(idx)" class="er-opt__mark er-opt__mark--wrong">我的作答</text>
					</view>
				</template>

				<!-- 填空题：答案对照 -->
				<view v-else class="er-fill">
					<view class="er-fill__row">
						<text class="er-fill__lab">正确答案：</text>
						<text class="er-fill__val er-fill__val--right">{{ rightText }}</text>
					</view>
					<view class="er-fill__row">
						<text class="er-fill__lab">我的作答：</text>
						<text class="er-fill__val er-fill__val--wrong">{{ myText || "（未作答）" }}</text>
					</view>
				</view>

				<!-- 解析 -->
				<view v-if="jiexi" class="er-jiexi">
					<view class="er-jiexi__lab">答案解析</view>
					<view class="er-jiexi__text">{{ jiexi }}</view>
				</view>
			</view>

			<!-- 翻题 -->
			<view class="er-foot">
				<view class="er-foot__btn" :class="{ 'er-foot__btn--off': !isup }" @click="move('up')">上一题</view>
				<view class="er-foot__btn" :class="{ 'er-foot__btn--off': !isdown }" @click="move('down')">下一题</view>
			</view>
		</template>
	</view>
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import c2Empty from "../components/c2-empty.vue";
import { fetchExamError, fetchExamErrorTiku, SORTS, type TkQuestion } from "../api/kecheng";

const rid = ref(0);
const loaded = ref(false);
const notFound = ref(false);
const tk = ref<TkQuestion>({ id: 0 });
let busy = false;

const options = computed<string[]>(() => (Array.isArray(tk.value.option) ? (tk.value.option as string[]) : []));
const nums = computed(() => Number(tk.value.nums ?? 0));
const curPos = computed(() => Number(tk.value.sort ?? 0));
const isup = computed(() => Number(tk.value.isup ?? 0) === 1);
const isdown = computed(() => Number(tk.value.isdown ?? 0) === 1);
const jiexi = computed(() => String(tk.value.jiexi ?? ""));

/** 正确答案下标集合 */
const rightIdx = computed<number[]>(() => {
	const raw = tk.value.right_option_idx;
	if (Array.isArray(raw)) return raw.map((v) => Number(v));
	if (typeof raw === "number") return [raw];
	return [];
});

/** 我的作答（选择题下标集合） */
const myIdx = computed<number[]>(() => {
	if (Number(tk.value.type ?? 1) !== 1) return [];
	const answer = tk.value.answer;
	if (answer === undefined || answer === null || answer === "") return [];
	if (Array.isArray(answer)) return (answer as unknown[]).map((v) => Number(v));
	return String(answer)
		.split(",")
		.map((v) => Number(v.trim()))
		.filter((v) => Number.isFinite(v));
});

/** 填空正确答案文本（apiError 带出 right_option；answer 是我的作答） */
const rightText = computed(() => {
	const idx = rightIdx.value;
	if (idx.length > 0) return idx.map((i) => SORTS[i] ?? "?").join(",");
	return String(tk.value["right_option"] ?? "");
});

/** 填空我的作答文本 */
const myText = computed(() => {
	const answer = tk.value.answer;
	if (Array.isArray(answer)) return (answer as unknown[]).join(",");
	return String(answer ?? "");
});

function sortLetter(idx: number): string {
	return SORTS[idx] ?? String(idx + 1);
}

async function load(op: string, logid: number, first = false) {
	if (busy) return;
	busy = true;
	try {
		const fn = first ? fetchExamError : fetchExamErrorTiku;
		const res = await fn({ rid: rid.value, op, logid });
		if (res) tk.value = res;
		loaded.value = true;
		notFound.value = false;
	} catch (err) {
		const msg = (err as Error).message || "加载失败";
		if (first) {
			notFound.value = true;
			loaded.value = true;
		} else {
			uni.showToast({ title: msg, icon: "none" });
		}
	} finally {
		busy = false;
	}
}

function move(op: "up" | "down") {
	if (op === "up" && !isup.value) return;
	if (op === "down" && !isdown.value) return;
	const logid = Number(tk.value.logid ?? 0);
	void load(op, logid);
}

onLoad((opts) => {
	rid.value = Number(opts?.rid ?? 0);
	if (rid.value <= 0) {
		uni.showToast({ title: "参数错误", icon: "none" });
		return;
	}
	void load("", 0, true);
});
</script>

<style scoped>
.er-page {
	min-height: 100vh;
	background: #f6f6fa;
	padding: 24rpx;
	padding-bottom: 180rpx;
	box-sizing: border-box;
}
.er-tip {
	text-align: center;
	color: #999;
	font-size: 26rpx;
	padding-top: 200rpx;
}
.er-empty {
	padding-top: 200rpx;
}
.er-head {
	padding: 8rpx 8rpx 24rpx;
}
.er-head__text {
	color: #666;
	font-size: 26rpx;
}
.er-card {
	background: #fff;
	border-radius: 16rpx;
	padding: 32rpx;
}
.er-title {
	margin-bottom: 32rpx;
}
.er-title__tag {
	display: inline-block;
	color: #7b7de5;
	font-size: 22rpx;
	border: 1rpx solid #7b7de5;
	border-radius: 8rpx;
	padding: 4rpx 12rpx;
	margin-right: 12rpx;
	vertical-align: 4rpx;
}
.er-title__text {
	color: #333;
	font-size: 32rpx;
	line-height: 1.6;
}
.er-opt {
	display: flex;
	align-items: center;
	border: 1rpx solid #e6e6ee;
	border-radius: 12rpx;
	padding: 20rpx 24rpx;
	margin-bottom: 16rpx;
}
.er-opt--right {
	border-color: #07c160;
	background: #f0faf4;
}
.er-opt--wrong {
	border-color: #fa5151;
	background: #fef0f0;
}
.er-opt__sort {
	width: 44rpx;
	height: 44rpx;
	line-height: 44rpx;
	text-align: center;
	border-radius: 50%;
	background: #f2f2f8;
	color: #666;
	font-size: 24rpx;
	margin-right: 16rpx;
	flex-shrink: 0;
}
.er-opt--right .er-opt__sort {
	background: #07c160;
	color: #fff;
}
.er-opt--wrong .er-opt__sort {
	background: #fa5151;
	color: #fff;
}
.er-opt__text {
	flex: 1;
	color: #333;
	font-size: 28rpx;
	line-height: 1.5;
}
.er-opt__mark {
	font-size: 20rpx;
	margin-left: 12rpx;
	flex-shrink: 0;
}
.er-opt__mark--right {
	color: #07c160;
}
.er-opt__mark--wrong {
	color: #fa5151;
}
.er-fill__row {
	display: flex;
	margin-bottom: 16rpx;
}
.er-fill__lab {
	color: #666;
	font-size: 28rpx;
	flex-shrink: 0;
}
.er-fill__val {
	flex: 1;
	font-size: 28rpx;
	line-height: 1.5;
}
.er-fill__val--right {
	color: #07c160;
}
.er-fill__val--wrong {
	color: #fa5151;
}
.er-jiexi {
	margin-top: 32rpx;
	background: #f8f8fd;
	border-radius: 12rpx;
	padding: 24rpx;
}
.er-jiexi__lab {
	color: #7b7de5;
	font-size: 26rpx;
	font-weight: bold;
	margin-bottom: 12rpx;
}
.er-jiexi__text {
	color: #555;
	font-size: 26rpx;
	line-height: 1.7;
}
.er-foot {
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
.er-foot__btn {
	flex: 1;
	height: 84rpx;
	line-height: 84rpx;
	text-align: center;
	border-radius: 42rpx;
	background: #7b7de5;
	color: #fff;
	font-size: 30rpx;
}
.er-foot__btn--off {
	opacity: 0.45;
}
</style>
