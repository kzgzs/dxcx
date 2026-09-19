import request from './request'

// 租户附件设置（任务256：字段口径对齐超管 AttachSetting，存储于租户作用域 tenant_config）
// 类型在本文件内自治定义（不占用 types.ts）

/** 附件设置配置（GET 回显 / POST 保存共用字段） */
export interface AttachSetConfig {
  /** 存储类型（1 本地/2 阿里云/3 七牛云/4 腾讯云） */
  storage_type: number | string
  /** 阿里云 Access Key ID */
  oss_key: string
  /** 阿里云 Access Key Secret */
  oss_secret: string
  /** 阿里云 Bucket 名称 */
  oss_bucket: string
  /** 阿里云 EndPoint（地域节点） */
  oss_endpoint: string
  /** 阿里云 Bucket 域名 */
  oss_domain: string
  /** 七牛云 AccessKey */
  qiniu_accesskey: string
  /** 七牛云 SecretKey */
  qiniu_secretkey: string
  /** 七牛云 Bucket */
  qiniu_bucket: string
  /** 七牛云 Url */
  qiniu_url: string
  /** 腾讯云 APPID */
  cos_appid: string
  /** 腾讯云 SecretID */
  cos_secretid: string
  /** 腾讯云 SecretKEY */
  cos_secretkey: string
  /** 腾讯云 Bucket */
  cos_bucket: string
  /** 腾讯云地域代码 */
  cos_local: string
  /** 腾讯云 Url */
  cos_url: string
  /** 图片压缩（0 关闭/1 开启） */
  thumb: number | string
  /** 图片最大宽度 */
  thumb_width: number | string
  /** 图片最大高度 */
  thumb_height: number | string
  /** 删除原图（0 关闭/1 开启） */
  delete_origin: number | string
  /** 云存储上传后删除服务器文件（0 关闭/1 开启） */
  delete_local: number | string
}

/** GET 回显额外下发的只读展示项 */
export interface AttachSetResult extends AttachSetConfig {
  /** 服务器单次上传限制（PHP ini，只读） */
  upload_max_filesize?: string
  /** PHP 版本（只读） */
  php?: string
}

// 读取当前租户附件配置
export function getAttachSet() {
  return request.get<unknown, AttachSetResult>('/admin/attachSet/index')
}

// 保存当前租户附件配置（当页保存，不刷新不跳转）
export function saveAttachSet(data: Record<string, string>) {
  return request.post<unknown, null>('/admin/attachSet/save', data)
}
