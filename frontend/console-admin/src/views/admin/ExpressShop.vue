<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance } from 'element-plus'
import {
  delExpressShop,
  getExpressShopList,
  saveExpressShop,
} from '../../api/express'
import type { ExpressShopItem } from '../../api/express'
import { areaProvinces } from '../../utils/areaData'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// 租户后台 - 扩展·同城配送·快递鸟门店（片C；列表 + 平铺编辑，右上X关闭）

const CARGO_TYPES: Array<{ value: string; label: string }> = [
  { value: 'file', label: '文件' },
  { value: 'electronics', label: '电子产品' },
  { value: 'cake', label: '蛋糕' },
  { value: 'fastfood', label: '快餐食品' },
  { value: 'flower', label: '鲜花绿植' },
  { value: 'seafood', label: '海鲜水产' },
  { value: 'carparts', label: '汽车配件' },
  { value: 'pet', label: '宠物' },
  { value: 'motherbaby', label: '母婴' },
  { value: 'medical', label: '医疗健康' },
  { value: 'edu', label: '教育' },
  { value: 'clothes', label: '服装' },
  { value: 'general', label: '百货' },
  { value: 'fruit', label: '水果' },
  { value: 'print', label: '图文广告' },
  { value: 'drink', label: '饮品' },
  { value: 'other', label: '其他' },
]

const loading = ref(false)
const tableData = ref<ExpressShopItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10, keyword: '' })

// 编辑平铺（列表/平铺互斥）
const editing = ref(false)
const isAdd = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({
  id: 0,
  kdn_shop_no: '',
  shop_no: '',
  shop_name: '',
  cargo_type: 'other',
  province: '',
  city: '',
  district: '',
  address: '',
  lng: '',
  lat: '',
  contact: '',
  mobile: '',
  id_card_front: '',
  id_card_back: '',
  id_card_no: '',
  id_card_name: '',
  business_license: '',
  credit_code: '',
  shop_pic: '',
  holder_pic: '',
})

// 省市区联动
const cityList = computed(() => {
  const p = areaProvinces.find((x: any) => x.name === form.province)
  return p ? p.cityList : []
})
const areaList = computed(() => {
  const c = cityList.value.find((x: any) => x.name === form.city)
  return c ? c.areaList : []
})
function onProvinceChange() {
  form.city = ''
  form.district = ''
}
function onCityChange() {
  form.district = ''
}

// 图片上传
const pickerVisible = ref(false)
let pickerField = ''
function openPicker(field: string) {
  pickerField = field
  pickerVisible.value = true
}
function onPick(urls: string[]) {
  if (urls[0] && pickerField) {
    ;(form as any)[pickerField] = urls[0]
  }
  pickerVisible.value = false
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getExpressShopList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function resetForm() {
  Object.assign(form, {
    id: 0, kdn_shop_no: '', shop_no: '', shop_name: '', cargo_type: 'other',
    province: '', city: '', district: '', address: '', lng: '', lat: '',
    contact: '', mobile: '', id_card_front: '', id_card_back: '', id_card_no: '',
    id_card_name: '', business_license: '', credit_code: '', shop_pic: '', holder_pic: '',
  })
}

function handleAdd() {
  resetForm()
  isAdd.value = true
  editing.value = true
}

function handleEdit(row: ExpressShopItem) {
  Object.assign(form, {
    id: row.id,
    kdn_shop_no: row.kdn_shop_no || '',
    shop_no: row.shop_no || '',
    shop_name: row.shop_name || '',
    cargo_type: row.cargo_type || 'other',
    province: row.province || '',
    city: row.city || '',
    district: row.district || '',
    address: row.address || '',
    lng: row.lng || '',
    lat: row.lat || '',
    contact: row.contact || '',
    mobile: row.mobile || '',
    id_card_front: row.id_card_front || '',
    id_card_back: row.id_card_back || '',
    id_card_no: row.id_card_no || '',
    id_card_name: row.id_card_name || '',
    business_license: row.business_license || '',
    credit_code: row.credit_code || '',
    shop_pic: row.shop_pic || '',
    holder_pic: row.holder_pic || '',
  })
  isAdd.value = false
  editing.value = true
}

function handleClose() {
  editing.value = false
}

async function handleSave() {
  if (!formRef.value) return
  await formRef.value.validate()
  await saveExpressShop({ ...form })
  ElMessage.success('保存成功')
  editing.value = false
  fetchList()
}

async function handleDel(row: ExpressShopItem) {
  await ElMessageBox.confirm(`确认删除门店「${row.shop_name}」？`, '提示', { type: 'warning' })
  await delExpressShop([row.id])
  ElMessage.success('删除成功')
  fetchList()
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <template v-if="!editing">
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="关键字">
          <el-input v-model="query.keyword" placeholder="门店名称/联系人/手机号" clearable style="width: 200px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <div class="op-row">
        <el-button type="primary" @click="handleAdd">添加门店</el-button>
      </div>
      <el-table v-loading="loading" :data="tableData" border size="small">
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="门店编码" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.shop_no }}</div>
            <div v-if="row.kdn_shop_no" class="sub-text">快递鸟编码：{{ row.kdn_shop_no }}(已激活)</div>
          </template>
        </el-table-column>
        <el-table-column prop="shop_name" label="门店名称" min-width="140" show-overflow-tooltip />
        <el-table-column prop="cargo_type_name" label="物品类型" width="100" />
        <el-table-column label="所在地区" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.region }}</template>
        </el-table-column>
        <el-table-column prop="address" label="详细地址" min-width="160" show-overflow-tooltip />
        <el-table-column prop="contact" label="联系人" width="90" />
        <el-table-column prop="mobile" label="手机号" width="120" />
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="handleEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDel(row)">删除</el-button>
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
        :page-sizes="[10, 20, 50]"
        @current-change="(p: number) => { query.page = p; fetchList() }"
        @size-change="(s: number) => { query.pagesize = s; query.page = 1; fetchList() }"
      />
    </el-card>

    </template>

    <!-- 平铺编辑主区域（列表/平铺互斥，右上X关闭） -->
    <div v-else class="goods-edit">
      <div class="ge-head">
        <span class="ge-title">{{ isAdd ? '添加快递鸟门店' : '编辑快递鸟门店' }}</span>
        <el-button class="ge-close" circle @click="handleClose">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>
      <el-card shadow="never">
        <el-form ref="formRef" :model="form" label-width="150px">
          <el-form-item v-if="form.id" label="快递鸟门店编码">
            <span class="kdn-no">{{ form.kdn_shop_no || '-' }}</span>
            <span v-if="form.kdn_shop_no" class="active-tag">已激活</span>
            <span v-else class="inactive-tag">未激活</span>
          </el-form-item>
          <el-form-item v-if="form.id" label="门店编码">
            <el-input v-model="form.shop_no" readonly />
          </el-form-item>
          <el-form-item label="*门店名称" prop="shop_name" :rules="[{ required: true, message: '请输入门店名称', trigger: 'blur' }]">
            <el-input v-model="form.shop_name" placeholder="门店名称" style="max-width: 420px" />
          </el-form-item>
          <el-form-item label="*门店物品类型" prop="cargo_type" :rules="[{ required: true, message: '请选择门店物品类型', trigger: 'change' }]">
            <el-radio-group v-model="form.cargo_type">
              <el-radio v-for="c in CARGO_TYPES" :key="c.value" :value="c.value" class="cargo-radio">{{ c.label }}</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="*门店所在地区" prop="province" :rules="[{ required: true, message: '请选择门店所在省市区', trigger: 'change' }]">
            <el-select v-model="form.province" placeholder="请选择省" style="width: 150px" @change="onProvinceChange">
              <el-option v-for="p in areaProvinces" :key="p.name" :label="p.name" :value="p.name" />
            </el-select>
            <el-select v-model="form.city" placeholder="请选择市" style="width: 150px; margin-left: 8px" @change="onCityChange">
              <el-option v-for="c in cityList" :key="c.name" :label="c.name" :value="c.name" />
            </el-select>
            <el-select v-model="form.district" placeholder="请选择区" style="width: 150px; margin-left: 8px">
              <el-option v-for="a in areaList" :key="a" :label="a" :value="a" />
            </el-select>
            <div class="sub-tip">仅支持同省同市内的门店地址修改，不同省市的只能重新新建店铺信息</div>
          </el-form-item>
          <el-form-item label="*门店详细地址" prop="address" :rules="[{ required: true, message: '请输入门店详细地址', trigger: 'blur' }]">
            <el-input v-model="form.address" placeholder="请输入详细地址" style="max-width: 420px" />
            <el-button style="margin-left: 8px">搜索</el-button>
          </el-form-item>
          <el-form-item label="*门店坐标" prop="lng" :rules="[{ required: true, message: '请选择门店坐标或填写经纬度', trigger: 'change' }]">
            <div class="map-box">
              <div class="map-zoom"><span>+</span><span>−</span></div>
              <div class="map-toggle"><span class="on">地图</span><span>卫星</span></div>
              <div class="map-marker"></div>
              <div class="map-error">鉴权失败，请传入正确的key</div>
            </div>
            <div class="lnglat-row">
              <el-input v-model="form.lng" placeholder="经度 如 118.353355" style="width: 200px" />
              <el-input v-model="form.lat" placeholder="纬度 如 35.104441" style="width: 200px; margin-left: 8px" />
            </div>
          </el-form-item>
          <el-form-item label="*联系人" prop="contact" :rules="[{ required: true, message: '请输入联系人', trigger: 'blur' }]">
            <el-input v-model="form.contact" placeholder="联系人" style="max-width: 420px" />
          </el-form-item>
          <el-form-item label="*手机号" prop="mobile" :rules="[{ required: true, message: '请输入手机号', trigger: 'blur' }]">
            <el-input v-model="form.mobile" placeholder="手机号" style="max-width: 420px" />
          </el-form-item>

          <el-form-item label="*身份证人像面" prop="id_card_front" :rules="[{ required: true, message: '请上传身份证人像面', trigger: 'change' }]">
            <el-button @click="openPicker('id_card_front')">上传图片</el-button>
            <span class="sub-tip">图片大小1.2MB</span>
            <div v-if="form.id_card_front" class="pic-preview">
              <img :src="form.id_card_front" alt="" />
              <el-button link type="danger" class="pic-del" @click="form.id_card_front = ''">X</el-button>
            </div>
          </el-form-item>
          <el-form-item label="*身份证国徽面" prop="id_card_back" :rules="[{ required: true, message: '请上传身份证国徽面', trigger: 'change' }]">
            <el-button @click="openPicker('id_card_back')">上传图片</el-button>
            <span class="sub-tip">图片大小1.2MB</span>
            <div v-if="form.id_card_back" class="pic-preview">
              <img :src="form.id_card_back" alt="" />
              <el-button link type="danger" class="pic-del" @click="form.id_card_back = ''">X</el-button>
            </div>
          </el-form-item>
          <el-form-item label="*身份证号" prop="id_card_no" :rules="[{ required: true, message: '请输入身份证号', trigger: 'blur' }]">
            <el-input v-model="form.id_card_no" placeholder="身份证号" style="max-width: 420px" />
          </el-form-item>
          <el-form-item label="*身份证姓名" prop="id_card_name" :rules="[{ required: true, message: '请输入身份证姓名', trigger: 'blur' }]">
            <el-input v-model="form.id_card_name" placeholder="身份证姓名" style="max-width: 420px" />
          </el-form-item>
          <el-form-item label="营业执照">
            <el-button @click="openPicker('business_license')">上传图片</el-button>
            <span class="sub-tip">图片大小1.2MB</span>
            <div v-if="form.business_license" class="pic-preview">
              <img :src="form.business_license" alt="" />
              <el-button link type="danger" class="pic-del" @click="form.business_license = ''">X</el-button>
            </div>
          </el-form-item>
          <el-form-item label="*统一社会信用代码" prop="credit_code" :rules="[{ required: true, message: '请输入统一社会信用代码', trigger: 'blur' }]">
            <el-input v-model="form.credit_code" placeholder="统一社会信用代码" style="max-width: 420px" />
          </el-form-item>
          <el-form-item label="*门店图片" prop="shop_pic" :rules="[{ required: true, message: '请上传门店图片', trigger: 'change' }]">
            <el-button @click="openPicker('shop_pic')">上传图片</el-button>
            <span class="sub-tip">图片大小1.2MB</span>
            <div v-if="form.shop_pic" class="pic-preview">
              <img :src="form.shop_pic" alt="" />
              <el-button link type="danger" class="pic-del" @click="form.shop_pic = ''">X</el-button>
            </div>
          </el-form-item>
          <el-form-item label="*店主手持证件照" prop="holder_pic" :rules="[{ required: true, message: '请上传店主手持证件照', trigger: 'change' }]">
            <el-button @click="openPicker('holder_pic')">上传图片</el-button>
            <span class="sub-tip">图片大小1.2MB</span>
            <div v-if="form.holder_pic" class="pic-preview">
              <img :src="form.holder_pic" alt="" />
              <el-button link type="danger" class="pic-del" @click="form.holder_pic = ''">X</el-button>
            </div>
            <div class="sub-tip">手持身份证或者手持营业执照</div>
          </el-form-item>

          <el-form-item class="submit-row">
            <el-button type="primary" @click="handleSave">提交</el-button>
          </el-form-item>
        </el-form>
      </el-card>
    </div>

    <ImgIconPicker v-model:visible="pickerVisible" default-tab="upload" @pick="onPick" />
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 12px;
}
.op-row {
  margin-bottom: 12px;
}
.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 6px;
}
.sub-tip {
  color: #999;
  font-size: 12px;
  line-height: 1.6;
}
.cargo-radio {
  margin-bottom: 8px;
}
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
.pic-preview {
  margin-top: 6px;
}
.pic-preview img {
  width: 90px;
  height: 90px;
  object-fit: cover;
  border: 1px solid #eee;
  margin-right: 8px;
}
/* 平铺编辑主区域 */
.ge-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 2px 10px;
}
.ge-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
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
.kdn-no {
  font-size: 14px;
  color: #303133;
  margin-right: 8px;
}
.active-tag {
  color: #67c23a;
  font-size: 13px;
}
.inactive-tag {
  color: #e6a23c;
  font-size: 13px;
}
/* 腾讯地图占位（无 key 时显鉴权失败提示） */
.map-box {
  position: relative;
  width: 460px;
  height: 280px;
  background: linear-gradient(135deg, #e8f0e3 0%, #dfeaf5 100%);
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  overflow: hidden;
}
.map-zoom {
  position: absolute;
  left: 10px;
  top: 10px;
  display: flex;
  flex-direction: column;
  background: #fff;
  border: 1px solid #ccc;
  border-radius: 3px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
.map-zoom span {
  width: 26px;
  height: 26px;
  line-height: 26px;
  text-align: center;
  font-size: 16px;
  color: #333;
  cursor: pointer;
}
.map-zoom span + span {
  border-top: 1px solid #ccc;
}
.map-toggle {
  position: absolute;
  right: 10px;
  top: 10px;
  display: flex;
  font-size: 12px;
}
.map-toggle span {
  padding: 4px 12px;
  background: #fff;
  border: 1px solid #ccc;
  cursor: pointer;
}
.map-toggle span.on {
  background: #08b869;
  color: #fff;
}
.map-marker {
  position: absolute;
  left: 50%;
  top: 52%;
  width: 18px;
  height: 18px;
  margin-left: -9px;
  border-radius: 50% 50% 50% 0;
  background: #1976d2;
  transform: rotate(-45deg);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}
.map-marker::after {
  content: '';
  position: absolute;
  left: 5px;
  top: 5px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #fff;
}
.map-error {
  position: absolute;
  left: 50%;
  bottom: 16px;
  transform: translateX(-50%);
  background: #fff;
  border: 1px solid #ffccc7;
  border-radius: 3px;
  padding: 5px 12px;
  font-size: 12px;
  color: #f5222d;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
  white-space: nowrap;
}
.lnglat-row {
  margin-top: 10px;
}
.submit-row {
  padding-left: 0;
}
.submit-row :deep(.el-form-item__content) {
  margin-left: 0 !important;
}
.pic-del {
  vertical-align: top;
}
</style>
