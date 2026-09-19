import request from './request'

// 注册页公开配置（免登录）
export interface RegisterConfig {
  reg_open: number
  sms_verify: number
  reg_agreement_url: string
}

// 读取注册开关 / 短信开关 / 协议链接（静默失败由页面兜底）
export function getRegisterConfig() {
  return request.get<unknown, RegisterConfig>('/console/register/config', {
    silentError: true,
  })
}

// 发送注册短信验证码（MOCK 模式固定 123456）
export function sendRegisterSms(mobile: string) {
  return request.post<unknown, { success: boolean }>(
    '/console/register/sendsms',
    { mobile },
  )
}

// 提交注册（需携带图形验证码 captcha_id + captcha）
export function submitRegister(data: {
  mobile: string
  password: string
  code: string
  agreement: boolean
  captcha_id: string
  captcha: string
}) {
  return request.post<unknown, { mid: number; phone: string }>(
    '/console/register/submit',
    data,
  )
}
