#!/usr/bin/env node
// Cắt một bản WordPress: một lệnh, một con số, ba nơi không thể lệch nhau.
//
//   node scripts/release-wordpress.mjs 0.6.1
//   node scripts/release-wordpress.mjs 0.6.1 --dry-run   kiểm + build, không đẩy gì
//
// Vì sao có file này. Một bản plugin sống ở ba chỗ phải khớp nhau: header `Version:` trong
// claude-cowork.php, `version` + `package` trong wordpress/update.json, và tag của release mang
// file zip. Cho tới hôm nay cả ba đều sửa tay. Ngày 25/08/2026 kết quả là tracy.ai chạy 0.4.0
// trong khi mọi tài liệu nói 0.5.4 và Tracy Desk mang theo 0.6.0 — ba con số, ba câu trả lời
// khác nhau, không ai sai một cách nhìn thấy được.
//
// Cố ý KHÔNG làm hai việc:
//   - không tự đoán số phiên bản. Người gõ số. Đoán hộ patch/minor là thứ sẽ đoán sai đúng hôm
//     cần đúng, và số ấy đi thẳng ra site của khách.
//   - không chạy trong CI. Push lên main mà tự phát hành cho mọi site đang cài là bỏ mất nút
//     dừng cuối cùng. Phát hành là một hành động có người bấm.

import { execFileSync } from 'node:child_process'
import { readFileSync, renameSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const cowork = join(repo, 'wordpress', 'cowork')
const pluginFile = join(cowork, 'claude-cowork', 'claude-cowork.php')
const manifestFile = join(repo, 'wordpress', 'update.json')

// `stdio: 'inherit'` khiến execFileSync trả null thay vì chuỗi — cái output đã đi thẳng ra màn
// hình rồi. Người gọi nào cần chữ thì để mặc định 'pipe'; ai chỉ cần chạy thì không phải nghĩ.
const run = (cmd, args, opts = {}) => {
  const out = execFileSync(cmd, args, { cwd: repo, encoding: 'utf8', stdio: 'pipe', ...opts })
  return typeof out === 'string' ? out.trim() : ''
}

const die = (message) => {
  console.error(`\n✘ ${message}\n`)
  process.exit(1)
}

const version = process.argv[2]
const dryRun = process.argv.includes('--dry-run')

// ── 0. con số phải là một con số ────────────────────────────────────────────────────────────
if (!version || !/^\d+\.\d+\.\d+$/.test(version)) {
  die('cần một phiên bản dạng x.y.z, ví dụ: node scripts/release-wordpress.mjs 0.6.1')
}

// ── 1. cùng cổng mà seat-registry dựng sau sự cố deploy đè bản cũ (tracy.ai#86) ──────────────
// Phát hành từ một checkout cũ là phát hành một bản không ai đọc được từ lịch sử.
try {
  run('git', ['fetch', 'origin', 'main', '--quiet'])
} catch {
  die('không fetch được origin/main — không xác nhận được checkout này mới hay cũ')
}
if (run('git', ['rev-parse', 'HEAD']) !== run('git', ['rev-parse', 'origin/main'])) {
  die('HEAD lệch origin/main. Merge và pull --rebase trước khi cắt release.')
}
if (run('git', ['status', '--porcelain'])) {
  die('cây làm việc còn thay đổi chưa commit. Commit hoặc stash trước.')
}

const existingTags = run('git', ['tag', '--list', `wordpress-v${version}`])
if (existingTags) die(`tag wordpress-v${version} đã tồn tại — con số này đã được dùng`)

// ── 2 & 3. hai nơi khai phiên bản, sinh ra từ CÙNG một biến ──────────────────────────────────
const zipName = `claude-cowork-${version}.zip`
const packageUrl = `https://github.com/TracyHQ/claude-cowork/releases/download/wordpress-v${version}/${zipName}`

const plugin = readFileSync(pluginFile, 'utf8')
const bumped = plugin.replace(/^(\s*\*\s*Version:\s*)(.+)$/m, `$1${version}`)
if (bumped === plugin) die('không tìm thấy dòng `Version:` trong header plugin')
writeFileSync(pluginFile, bumped)

const manifest = JSON.parse(readFileSync(manifestFile, 'utf8'))
manifest.version = version
manifest.package = packageUrl
manifest.url = `https://github.com/TracyHQ/claude-cowork/releases/tag/wordpress-v${version}`
writeFileSync(manifestFile, `${JSON.stringify(manifest, null, 2)}\n`)

console.log(`· header và manifest cùng ghi ${version}`)

// ── 4. đọc lại từ đĩa và tự chứng minh ───────────────────────────────────────────────────────
// Chạy SAU khi sửa, không phải trước: thứ cần chứng minh là hai file VỪA GHI có khớp nhau không,
// đọc lại từ đĩa chứ không tin hai biến vừa dùng để ghi chúng. `tests/run.php` kiểm đúng ba điều
// này và CI chạy nó trên mọi push — nhưng nó cần php, mà máy phát hành không chắc có. Một cổng
// bỏ qua được khi thiếu dependency là một cổng mở, nên nó được viết lại ở đây bằng thứ luôn có.
const writtenHeader = readFileSync(pluginFile, 'utf8').match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1]?.trim()
const written = JSON.parse(readFileSync(manifestFile, 'utf8'))
if (writtenHeader !== version) die(`header ghi ${writtenHeader}, không phải ${version}`)
if (written.version !== version) die(`manifest ghi ${written.version}, không phải ${version}`)
if (written.package !== packageUrl) die('manifest trỏ vào một asset khác asset sắp được tạo')
console.log('· header, manifest và tên gói cùng nói một con số')

// ── 5. gói ───────────────────────────────────────────────────────────────────────────────────
run('bash', ['build.sh'], { cwd: cowork, stdio: 'inherit' })
renameSync(join(cowork, 'dist', 'claude-cowork.zip'), join(cowork, 'dist', zipName))
console.log(`· dist/${zipName}`)

if (dryRun) {
  console.log('\n--dry-run: dừng ở đây. Hai file đã sửa — `git checkout` nếu không muốn giữ.\n')
  process.exit(0)
}

// ── 6 & 7. một commit, một tag, một release ─────────────────────────────────────────────────
run('git', ['add', 'wordpress/cowork/claude-cowork/claude-cowork.php', 'wordpress/update.json'])
run('git', ['commit', '-m', `release(wordpress): ${version}`])
run('git', ['tag', `wordpress-v${version}`])
run('git', ['push', 'origin', 'HEAD', '--tags'])

run('gh', [
  'release',
  'create',
  `wordpress-v${version}`,
  join(cowork, 'dist', zipName),
  '--title',
  `WordPress ${version}`,
  '--notes',
  `Plugin ${version}. Sites on ${version} or newer need do nothing; older ones update themselves.`
])

console.log(`
✓ wordpress-v${version} đã phát hành.

  Manifest là nguồn sự thật duy nhất và nó đã trỏ vào bản này. Site cài Tracy tự kiểm
  khoảng hai lần một ngày rồi tự cập nhật — không ai phải bấm gì. Muốn thu lại bản này thì
  sửa wordpress/update.json về phiên bản trước và đẩy: không phải đụng tới site nào cả.
`)
