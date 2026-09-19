<template>
  <div class="system-setting-container">
    <!-- 系统配置说明块（对照参考系统 WebSystem/set 顶部引用块） -->
    <div class="config-guide">
      <div class="guide-title">系统配置说明：</div>

      <!-- 一、添加计划任务 -->
      <div class="guide-section">
        <p class="guide-heading">一、配置计划任务（支撑订单自动确认收货、超时订单自动关闭、拼团成败判定、消息通知、分账执行等场景）：</p>
        <p>
          进入宝塔面板新建一条计划任务，任务类型选择“访问 URL”（新版宝塔名称为“访问 URL-GET”），执行频率设为每分钟执行一次，目标 URL 填写：
        </p>
        <p class="guide-line">
          <span class="guide-url">{{ cronUrl }}</span>
          <span class="guide-link" @click="copyText(cronUrl)">【复制】</span>
          <el-popover placement="right" :width="380" trigger="hover">
            <template #reference>
              <span class="guide-link">【示例】</span>
            </template>
            <div class="popover-text">
              1、打开宝塔面板，进入“计划任务”页面；<br />
              2、点击“添加任务”，任务类型选择“访问 URL"（新版宝塔选择“访问 URL-GET"）；<br />
              3、执行周期选择“每 N 分钟”，并选择 1 分钟；<br />
              4、将上方 URL 地址粘贴到“目标 URL"中，点击“添加任务”保存即可。
            </div>
          </el-popover>
        </p>
      </div>

      <!-- 二、配置客服系统 -->
      <div class="guide-section">
        <p class="guide-heading">
          二、启用客服系统（站点必须使用 https 协议；若 php 禁用了 pcntl 开头的函数需先恢复可用
          <el-popover placement="right" :width="380" trigger="hover">
            <template #reference>
              <span class="guide-link">【示例】</span>
            </template>
            <div class="popover-text">
              1、打开宝塔面板，进入“软件商店”-“PHP 设置”；<br />
              2、选择“禁用函数”，找到 pcntl 开头的函数（如 pcntl_signal、pcntl_alarm、pcntl_wait 等）；<br />
              3、将其从禁用列表中逐一移除并保存，然后重启 PHP。
            </div>
          </el-popover>
          ，该系统负责实时接收在线咨询消息、公众号会话消息、商城内客服页面消息以及小程序端发来的客服消息）：
        </p>

        <p>使用 Nginx 环境时，在宝塔面板依次打开【网站】-【设置】-【配置文件】，补充以下两处配置：</p>

        <p>1、在文件开头位置追加：</p>
        <pre class="guide-code">{{ nginxUpstream }}</pre>
        <p class="guide-line">
          <span class="guide-link" @click="copyText(nginxUpstream)">【复制】</span>
          <el-popover placement="right" :width="380" trigger="hover">
            <template #reference>
              <span class="guide-link">【示例】</span>
            </template>
            <div class="popover-text">
              1、宝塔面板点击“网站”，找到对应站点点击“设置”；<br />
              2、点击“配置文件”打开 nginx 站点配置；<br />
              3、在文件最上方（server 块之外）粘贴上方 upstream 配置；<br />
              4、保存后重载 nginx 生效。
            </div>
          </el-popover>
        </p>

        <p>2、在 server 块内、末尾 access_log 一行的上方追加：</p>
        <pre class="guide-code">{{ nginxLocation }}</pre>
        <p class="guide-line">
          <span class="guide-link" @click="copyText(nginxLocation)">【复制】</span>
          <el-popover placement="right" :width="380" trigger="hover">
            <template #reference>
              <span class="guide-link">【示例】</span>
            </template>
            <div class="popover-text">
              1、在同一份站点配置文件中找到 server { ... } 块；<br />
              2、定位到块内最下方的 access_log 一行；<br />
              3、在 access_log 上方粘贴上方 location /wss 配置；<br />
              4、保存后重载 nginx 生效。
            </div>
          </el-popover>
        </p>

        <p>3、启动客服进程：进入项目根目录，以下列命令以守护进程方式运行：</p>
        <pre class="guide-code">{{ workerStartCmd }}</pre>
        <p class="guide-line">
          <span class="guide-link" @click="copyText(workerStartCmd)">【复制】</span>
        </p>
        <p>如需停止客服系统，执行：</p>
        <pre class="guide-code">{{ workerStopCmd }}</pre>
        <p class="guide-line">
          <span class="guide-link" @click="copyText(workerStopCmd)">【复制】</span>
        </p>
        <p>
          输出中出现 Start success. 字样即表示启动成功；若未能启动，请逐项排查：pcntl 开头的函数是否仍处于禁用状态、端口 6228 是否被其他程序占用，以及 nginx 配置是否已写入并重载生效。
        </p>
      </div>
    </div>

    <el-form
      ref="formRef"
      :model="formData"
      :rules="formRules"
      label-width="120px"
      label-position="right"
      size="default"
    >
      <!-- 基础信息 -->
      <el-form-item label="系统名称" prop="system_name">
        <el-input v-model="formData.system_name" placeholder="请输入系统名称" clearable class="narrow-input" />
      </el-form-item>

      <el-form-item label="LOGO">
        <div class="field-block">
          <div class="field-line">
            <el-input v-model="formData.logo" placeholder="上传 LOGO 图片" clearable class="narrow-input" />
            <el-button type="primary" @click="handleUpload('logo')">上传图片</el-button>
            <span class="form-help">建议尺寸：108×108 像素</span>
          </div>
          <div class="img-preview" v-if="formData.logo">
            <img :src="formData.logo" alt="LOGO 预览" />
          </div>
        </div>
      </el-form-item>

      <el-form-item label="图标 (ico)">
        <div class="field-block">
          <div class="field-line">
            <el-input v-model="formData.ico" placeholder="上传图标" clearable class="narrow-input" />
            <el-button type="primary" @click="handleUpload('ico')">上传图片</el-button>
            <span class="form-help">
              <el-link type="primary" href="https://cloud.djkz.top/upload.html" target="_blank">点击跳转上传图片</el-link>
            </span>
          </div>
          <div class="img-preview small" v-if="formData.ico">
            <img :src="formData.ico" alt="ICO 预览" />
          </div>
        </div>
      </el-form-item>

      <el-form-item label="登录页背景图">
        <div class="field-block">
          <div class="field-line">
            <el-input v-model="formData.admin_loginbg" placeholder="上传登录页背景图" clearable class="narrow-input" />
            <el-button type="primary" @click="handleUpload('admin_loginbg')">上传图片</el-button>
            <span class="form-help">建议尺寸：1920×1080 像素</span>
          </div>
          <div class="img-preview medium" v-if="formData.admin_loginbg">
            <img :src="formData.admin_loginbg" alt="背景图预览" />
          </div>
        </div>
      </el-form-item>

      <!-- 版权备案 -->
      <el-form-item label="版权设置" prop="copyright">
        <el-input v-model="formData.copyright" placeholder="请输入版权设置" clearable class="narrow-input" />
      </el-form-item>

      <el-form-item label="备案号" prop="beian">
        <el-input v-model="formData.beian" placeholder="请输入备案号" clearable class="narrow-input" />
        <span class="form-help">豫ICP备2026008295号-1</span>
      </el-form-item>

      <el-form-item label="公安备案文字" prop="gongan_text">
        <el-input v-model="formData.gongan_text" placeholder="请输入公安备案文字" clearable class="narrow-input" />
        <span class="form-help">豫公网安备1234567891012号</span>
      </el-form-item>

      <el-form-item label="公安备案号" prop="gongan_code">
        <el-input v-model="formData.gongan_code" placeholder="请输入公安备案号" clearable class="narrow-input" />
        <span class="form-help">访问“公安备案文字”对应的跳转链接时，会自动带上此备案号</span>
      </el-form-item>

      <!-- 官网与联系信息 -->
      <el-form-item label="官网" prop="showweb">
        <el-radio-group v-model="formData.showweb">
          <el-radio :label="1">模板一</el-radio>
          <el-radio :label="2">模板二</el-radio>
          <el-radio :label="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <!-- 开发者信息（showweb!=0 时显示） -->
      <template v-if="formData.showweb !== 0">
        <el-form-item label="开发者名" prop="company">
          <el-input v-model="formData.company" placeholder="请输入开发者名" clearable class="narrow-input" />
          <span class="form-help">郑州市鼎极刻栈有限公司</span>
        </el-form-item>

        <el-form-item label="地址" prop="address">
          <el-input v-model="formData.address" placeholder="请输入企业地址" clearable class="narrow-input" />
          <span class="form-help">郑州市金水区国基路011号</span>
        </el-form-item>

        <el-form-item label="邮箱" prop="email">
          <el-input v-model="formData.email" placeholder="请输入联系邮箱" clearable class="narrow-input" />
          <span class="form-help">1234@qq.com</span>
        </el-form-item>

        <el-form-item label="电话" prop="tel">
          <el-input v-model="formData.tel" placeholder="请输入联系电话" clearable class="narrow-input" />
          <span class="form-help">13800138000</span>
        </el-form-item>

        <el-form-item label="微信" prop="weixin">
          <el-input v-model="formData.weixin" placeholder="请输入企业微信" clearable class="narrow-input" />
          <span class="form-help">常用微信号</span>
        </el-form-item>
      </template>

      <!-- 注册配置 -->
      <el-form-item label="快速注册小程序" prop="fastregxcx">
        <el-radio-group v-model="formData.fastregxcx">
          <el-radio :label="1">开启</el-radio>
          <el-radio :label="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item label="开启注册" prop="reg_open">
        <el-radio-group v-model="formData.reg_open">
          <el-radio :label="1">开启</el-radio>
          <el-radio :label="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item label="注册协议链接" prop="reg_agreement_url" v-if="formData.reg_open === 1">
        <el-input v-model="formData.reg_agreement_url" placeholder="请填写链接地址" clearable class="narrow-input" />
      </el-form-item>

      <el-form-item label="注册会员有效期" prop="reg_valid_days">
        <el-input-number v-model="formData.reg_valid_days" :min="1" :max="365" :step="1" controls-position="right" class="narrow-number" />
        <span class="form-help">单位：天</span>
      </el-form-item>

      <el-form-item label="注册短信验证码" prop="sms_verify">
        <el-radio-group v-model="formData.sms_verify">
          <el-radio :label="1">开启</el-radio>
          <el-radio :label="0">关闭</el-radio>
        </el-radio-group>
        <span class="form-help">短信相关参数需到客户端系统的短信配置页面中维护</span>
      </el-form-item>

      <!-- 服务配置 -->
      <el-form-item label="快递查询 AppCode" prop="kuaidi_appcode">
        <el-input v-model="formData.kuaidi_appcode" placeholder="请输入快递查询 AppCode" clearable class="narrow-input" />
        <span class="form-help">
          供用户查询包裹物流轨迹使用，启用前需先在阿里云市场开通快递查询接口服务...
          <el-link type="primary" href="https://market.aliyun.com/detail/cmapi021863#sku=yuncode1586300006?source=5176.29345612&userCode=v0rzgh25" target="_blank" style="margin-left: 8px;">去购买</el-link>
        </span>
      </el-form-item>

      <el-form-item label="腾讯地图 key" prop="map_qq_key">
        <el-input v-model="formData.map_qq_key" placeholder="请输入腾讯地图 Key" clearable class="narrow-input" />
        <div class="form-help-links">
          <el-link type="primary" href="https://lbs.qq.com/webApi/javascriptGL/glGuide/glBasic" target="_blank">使用帮助</el-link>
          <el-link type="primary" href="#" class="ml8">配置限制说明</el-link>
        </div>
      </el-form-item>

      <el-form-item label="高德地图 key" prop="map_amap_key">
        <el-input v-model="formData.map_amap_key" placeholder="请输入高德地图 Key" clearable class="narrow-input" />
        <div class="form-help-links">
          <el-link type="primary" href="https://lbs.amap.com/api/javascript-api-v2/prerequisites" target="_blank">使用帮助</el-link>
        </div>
      </el-form-item>

      <el-form-item label="高德地图安全密钥" prop="map_amap_jscode">
        <el-input v-model="formData.map_amap_jscode" placeholder="请输入安全密钥" clearable class="narrow-input" />
        <div class="form-help-links">
          <el-link type="primary" href="https://lbs.amap.com/api/javascript-api/guide/abc/prepare" target="_blank">使用帮助</el-link>
        </div>
        <span class="form-help">2021 年 12 月 2 日之后新申请的 Key，都必须搭配安全密钥一起使用</span>
      </el-form-item>

      <el-form-item label="微信认证" prop="wx_verify_type">
        <el-radio-group v-model="formData.wx_verify_type">
          <el-radio :label="1">服务商额度 + 自费</el-radio>
          <el-radio :label="2">服务商额度</el-radio>
          <el-radio :label="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <div class="verification-tip">
        <span class="form-help">请注意：平台内所有用户的微信认证能力默认随系统设置联动...</span>
      </div>

      <!-- 保存按钮 -->
      <el-form-item label-width="0" class="save-row">
        <el-button type="primary" :loading="saving" @click="handleSave">保 存</el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { uploadFile as uploadImage } from '../api/goods'
import { getSystemConfig, saveSystemConfig } from '../api/system'

// ============ 系统配置说明块（对照参考系统 WebSystem/set） ============

/** 计划任务密钥（后端 system 配置组下发，首次自动生成） */
const cronKey = ref('')

/** 计划任务 URL（当前域名 + 定时任务入口） */
const cronUrl = computed(() => `${location.origin}/ApiAuto/index/key/${cronKey.value}`)

/** 客服系统 nginx upstream 配置 */
const nginxUpstream = `upstream websocket6228{
    server 127.0.0.1:6228;
}`

/** 客服系统 nginx location 配置 */
const nginxLocation = `location /wss{
    proxy_pass http://websocket6228;
    proxy_read_timeout 60s;
    proxy_set_header Host $host;
    proxy_set_header X-Real_IP $remote_addr;
    proxy_set_header X-Forwarded-for $remote_addr;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection 'Upgrade';
}`

/** 客服系统启动/停止命令 */
const workerStartCmd = 'php think worker:server -d'
const workerStopCmd = 'php think worker:server stop'

/** 复制文本：优先 navigator.clipboard，降级 execCommand('copy') */
async function copyText(text: string) {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
    } else {
      const ta = document.createElement('textarea')
      ta.value = text
      ta.style.position = 'fixed'
      ta.style.opacity = '0'
      document.body.appendChild(ta)
      ta.select()
      document.execCommand('copy')
      document.body.removeChild(ta)
    }
    ElMessage.success('复制成功')
  } catch (e) {
    console.error('复制失败:', e)
    ElMessage.error('复制失败，请手动复制')
  }
}

interface SystemFormData {
  system_name: string                          // 系统名称
  logo: string                                  // LOGO
  ico: string                                   // 图标
  admin_loginbg: string                         // 登录页背景图
  copyright: string                             // 版权设置
  beian: string                                 // 备案号
  gongan_text: string                           // 公安备案文字
  gongan_code: string                           // 公安备案号
  showweb: number                               // 官网模板选择
  company: string                               // 开发者名
  address: string                               // 地址
  email: string                                 // 邮箱
  tel: string                                   // 电话
  weixin: string                                // 微信
  fastregxcx: number                            // 快速注册小程序
  reg_open: number                              // 开启注册
  reg_agreement_url: string                     // 注册协议链接
  reg_valid_days: number                        // 注册会员有效期
  sms_verify: number                            // 注册短信验证码
  kuaidi_appcode: string                        // 快递查询 AppCode
  map_qq_key: string                            // 腾讯地图 key
  map_amap_key: string                          // 高德地图 key
  map_amap_jscode: string                       // 高德地图安全密钥
  wx_verify_type: number                        // 微信认证
}

const formRef = ref<FormInstance>()
const saving = ref(false)

const formData = reactive<SystemFormData>({
  system_name: '',                              // 系统名称
  logo: '',                                     // LOGO
  ico: '',                                      // 图标
  admin_loginbg: '',                            // 登录页背景图
  copyright: '郑州市鼎极刻栈有限公司 版权所有',     // 版权设置
  beian: '豫ICP备2026008295号-1',           // 备案号
  gongan_text: '豫公网安备1234567891012号',             // 公安备案文字
  gongan_code: '312432',                       // 公安备案号
  showweb: 2,                                  // 官网模板选择：默认模板二
  company: '郑州市鼎极刻栈有限公司',        // 企业名称
  address: '郑州市金水区国基路011号',       // 地址
  email: '1234@qq.com',                    // 邮箱
  tel: '13800138000',                          // 电话
  weixin: 'dingji_kezhan',                      // 微信
  fastregxcx: 1,                               // 快速注册小程序：开启
  reg_open: 1,                                 // 开启注册：开启
  reg_agreement_url: '',                       // 注册协议链接
  reg_valid_days: 7,                           // 注册会员有效期：7 天
  sms_verify: 1,                               // 注册短信验证码：开启
  kuaidi_appcode: '7d031040fc...',            // 快递查询 AppCode
  map_qq_key: 'CA2BZ-FHLC3-...',              // 腾讯地图 key
  map_amap_key: '96a6063f...',                // 高德地图 key
  map_amap_jscode: '73a279c13314d82f05e35369b2b0b634', // 高德地图安全密钥
  wx_verify_type: 0,                           // 微信认证：关闭
})

// 注：showweb 为 0 时隐藏企业信息区，联动由模板 v-if 自动处理，无需 watch

const formRules = reactive<FormRules<SystemFormData>>({
  // 所有字段均为可选（与参考系统一致：后端无必填校验，不填也可保存）
  system_name: [{ required: false, message: '请输入系统名称', trigger: 'blur' }],
  copyright: [{ required: false, message: '请输入版权设置', trigger: 'blur' }],
  beian: [{ required: false, message: '请输入备案号', trigger: 'blur' }],
  gongan_text: [{ required: false, message: '请输入公安备案文字', trigger: 'blur' }],
  gongan_code: [{ required: false, message: '请输入公安备案号', trigger: 'blur' }],
  logo: [{ required: false, message: '请上传 LOGO 图片', trigger: 'change' }],
  ico: [{ required: false, message: '请上传图片图标', trigger: 'change' }],
  admin_loginbg: [{ required: false, message: '请上传登录页背景图', trigger: 'change' }],
  company: [{ required: false, message: '请输入开发者名', trigger: 'blur' }],
  address: [{ required: false, message: '请输入企业地址', trigger: 'blur' }],
  email: [{ required: false, message: '请输入联系邮箱', trigger: 'blur' }],
  tel: [{ required: false, message: '请输入联系电话', trigger: 'blur' }],
  weixin: [{ required: false, message: '请输入企业微信', trigger: 'blur' }],
  reg_agreement_url: [{ required: false, message: '请填写注册协议链接', trigger: 'blur' }],
  kuaidi_appcode: [{ required: false, message: '请输入快递查询 AppCode', trigger: 'blur' }],
  map_qq_key: [{ required: false, message: '请输入腾讯地图 Key', trigger: 'blur' }],
  map_amap_key: [{ required: false, message: '请输入高德地图 Key', trigger: 'blur' }],
  map_amap_jscode: [{ required: false, message: '请输入高德地图安全密钥', trigger: 'blur' }],
})

/** 上传图片 */
async function handleUpload(field: keyof SystemFormData) {
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = 'image/*'
  input.onchange = async (e) => {
    const files = (e.target as HTMLInputElement).files
    if (!files || files.length === 0) return

    const file = files[0]
    try {
      const result = await uploadImage(file)
      // 使用类型断言避免 TS 推导问题
      ;(formData as any)[field] = result.url
      ElMessage.success('上传成功')
    } catch (error) {
      console.error('上传失败:', error)
      // 错误提示由 axios 拦截器统一处理
    }
  }
  input.click()
}

/** 保存配置 */
async function handleSave() {
  if (!formRef.value) return

  await formRef.value.validate(async (valid) => {
    if (!valid) return

    saving.value = true
    try {
      await saveSystemConfig(
        'system',
        {
          system_name: formData.system_name,
          logo: formData.logo,
          ico: formData.ico,
          admin_loginbg: formData.admin_loginbg,
          copyright: formData.copyright,
          beian: formData.beian,
          gongan_text: formData.gongan_text,
          gongan_code: formData.gongan_code,
          showweb: String(formData.showweb),
          company: formData.company,
          address: formData.address,
          email: formData.email,
          tel: formData.tel,
          weixin: formData.weixin,
          fastregxcx: String(formData.fastregxcx),
          reg_open: String(formData.reg_open),
          reg_agreement_url: formData.reg_agreement_url,
          reg_valid_days: String(formData.reg_valid_days),
          sms_verify: String(formData.sms_verify),
          kuaidi_appcode: formData.kuaidi_appcode,
          map_qq_key: formData.map_qq_key,
          map_amap_key: formData.map_amap_key,
          map_amap_jscode: formData.map_amap_jscode,
          wx_verify_type: String(formData.wx_verify_type),
        }
      )

      ElMessage.success('保存成功')
    } catch (error) {
      console.error('保存失败:', error)
      // 错误提示由 axios 拦截器统一处理
    } finally {
      saving.value = false
    }
  })
}

/** 加载配置数据 */
async function loadConfig() {
  try {
    const result = await getSystemConfig('system')
    if (result && Object.keys(result).length > 0) {
      // 计划任务密钥仅用于顶部说明块 URL 展示，不参与表单保存
      cronKey.value = result.cron_key || ''
      Object.assign(formData, result)
      // 后端返回的 radio / 数字字段均为字符串，统一转为 Number 以匹配 el-radio :label 与 el-input-number 的数字类型，否则刷新后不回显
      formData.showweb = Number(result.showweb ?? 2)
      formData.fastregxcx = Number(result.fastregxcx ?? 1)
      formData.reg_open = Number(result.reg_open ?? 1)
      formData.sms_verify = Number(result.sms_verify ?? 1)
      formData.wx_verify_type = Number(result.wx_verify_type ?? 0)
      formData.reg_valid_days = Number(result.reg_valid_days ?? 7)
    }
  } catch (error) {
    console.error('加载配置失败:', error)
    // 错误提示由 axios 拦截器统一处理
  }
}

onMounted(() => {
  loadConfig()
})
</script>

<style scoped>
.system-setting-container {
  padding: 20px;
  max-width: 1100px;
}

/* ============ 参考系统风格：扁平行式表单（无卡片边框） ============ */

/* 每行：[右对齐标签] [窄输入框] [右侧说明]，行间距 22px */
:deep(.el-form-item) {
  margin-bottom: 22px;
}

:deep(.el-form-item__label) {
  color: #606266;
  font-weight: normal;
}

/* 内容区水平排列、垂直居中 */
:deep(.el-form-item__content) {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}

/* 窄输入框：固定 240px */
.narrow-input {
  width: 240px;
  flex: none;
}

.narrow-number {
  width: 240px;
  flex: none;
}

/* 图片字段：首行控件 + 下方预览 */
.field-block {
  display: flex;
  flex-direction: column;
}

.field-line {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* 说明文字：输入框右侧同行的灰色小字 */
.form-help {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
  line-height: 1.6;
}

/* 说明链接组：与输入框同行 */
.form-help-links {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: 10px;
}

.ml8 {
  margin-left: 8px;
}

/* 微信认证下方多行提示：缩进到输入框起始位置，灰色小字 */
.verification-tip {
  margin: -6px 0 22px 120px;
  font-size: 12px;
  line-height: 1.8;
}

.verification-tip .form-help {
  margin-left: 0;
}

/* 保存按钮：缩进到输入框起始位置 */
.save-row {
  margin-top: 30px;
  margin-left: 120px;
}

/* 图片预览：输入框下方小图 */
.img-preview {
  margin-top: 10px;
  padding: 6px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  display: inline-block;
}

.img-preview img {
  height: 90px;
  max-width: 260px;
  object-fit: contain;
  display: block;
}

.img-preview.small img {
  height: 48px;
  max-width: 96px;
}

.img-preview.medium img {
  height: 120px;
  max-width: 320px;
}

/* ============ 系统配置说明块（对照参考系统：灰底 + 左侧绿色竖条） ============ */
.config-guide {
  margin-bottom: 20px;
  padding: 15px;
  background-color: #f6f6f6;
  border-left: 4px solid #28a745;
  font-size: 13px;
  line-height: 1.9;
  color: #555;
}

.config-guide p {
  margin: 4px 0;
}

.guide-title {
  font-weight: 600;
  color: #333;
  margin-bottom: 6px;
}

.guide-section {
  margin-top: 10px;
}

.guide-heading {
  font-weight: 600;
  color: #333;
}

.guide-line {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
}

.guide-url {
  font-family: Consolas, Monaco, monospace;
  word-break: break-all;
  color: #333;
}

.guide-link {
  color: #409eff;
  cursor: pointer;
  font-size: 12px;
  margin-left: 8px;
}

.guide-link:hover {
  text-decoration: underline;
}

.guide-code {
  margin: 6px 0;
  padding: 8px;
  background-color: #eaeaea;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  line-height: 1.7;
  white-space: pre;
  overflow-x: auto;
}

.popover-text {
  font-size: 13px;
  line-height: 2;
  color: #555;
}


</style>
