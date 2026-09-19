import request from './request'

// 图形验证码（免登录公开接口）
export interface CaptchaResult {
  captcha_id: string
  // data:image/png;base64,... 可直接作为 img src
  image: string
}

// 获取图形验证码（5 分钟有效，一次性使用；点击刷新时重新调用）
export function getCaptcha() {
  return request.get<unknown, CaptchaResult>('/console/captcha', {
    silentError: true,
  })
}
