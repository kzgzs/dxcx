/** pagesC2 分包通用 API（文件上传等） */
import { uploadFile } from "../../utils/request";

/** 图片/视频上传 POST /api/upload（multipart 字段 file，返回相对路径 url） */
export function uploadImage(filePath: string) {
  return uploadFile<{ url: string }>("/api/upload", filePath);
}
