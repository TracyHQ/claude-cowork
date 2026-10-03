// node --test scripts/release-manifest.test.mjs
//
// The release manifest generator against small fixture trees, plus the shape of the real Joomla
// package. Whether the paths are where Joomla really puts the files is proven on a real install by
// joomla/cowork/tests/e2e/updater.sh (every entry present, same bytes); this holds the rules still.

import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { build, joomlaTemplate, parseJoomlaManifest, wordpressPlugin, wordpressTheme } from './release-manifest.mjs'

const sha = (s) => createHash('sha256').update(s).digest('hex')

function tree(files) {
  const root = mkdtempSync(join(tmpdir(), 'release-manifest-'))
  for (const [path, body] of Object.entries(files)) {
    mkdirSync(dirname(join(root, path)), { recursive: true })
    writeFileSync(join(root, path), body)
  }
  return root
}

test('a comment quoting <files> is not read as one', () => {
  const m = parseJoomlaManifest(`<extension type="plugin" group="system">
    <!-- <files><filename>ghost.php</filename></files> -->
    <version>1.0.0</version>
    <files><folder plugin="x">src</folder></files>
  </extension>`)
  assert.deepEqual(m.files[0].entries.map((e) => e.name), ['src'])
})

test('a component: site, administrator, languages, its manifest and script, never itself', () => {
  const root = tree({
    'pkg_x.xml': `<extension type="package"><packagename>x</packagename><version>2.0.0</version><scriptfile>script.php</scriptfile>
      <files folder="packages"><file type="component" id="com_claudecowork">com_claudecowork.zip</file><file type="plugin" id="xapi" group="system">plg_system_xapi.zip</file></files></extension>`,
    'script.php': 'pkg script',
    'com_claudecowork/claudecowork.xml': `<extension type="component"><name>COM_CLAUDECOWORK</name><version>2.0.0</version><scriptfile>script.php</scriptfile>
      <files folder="site"><folder>src</folder><filename>x.php</filename></files>
      <administration><files folder="administrator"><folder>lib</folder><filename>tracy-release.json</filename></files>
      <languages folder="administrator/language"><language tag="en-GB">en-GB/com_claudecowork.ini</language></languages></administration></extension>`,
    'com_claudecowork/script.php': 'com script',
    'com_claudecowork/site/x.php': 'site entry',
    'com_claudecowork/site/src/A.php': 'a',
    'com_claudecowork/site/src/.DS_Store': 'junk',
    'com_claudecowork/administrator/lib/Engine.php': 'engine',
    'com_claudecowork/administrator/lib/contracts/p/1/manifest.json': '{}',
    'com_claudecowork/administrator/language/en-GB/com_claudecowork.ini': 'X="x"',
    'com_claudecowork/administrator/unlisted.php': 'never installed',
    'plg_system_xapi/xapi.xml': `<extension type="plugin" group="system"><version>2.0.0</version>
      <files><folder plugin="xapi">services</folder></files><languages><language tag="en-GB">en-GB/plg_system_xapi.ini</language></languages></extension>`,
    'plg_system_xapi/services/provider.php': 'provider',
    'plg_system_xapi/en-GB/plg_system_xapi.ini': 'P="p"'
  })
  // The package manifest is named for the real one; the generator reads pkg_claudecowork.xml.
  writeFileSync(join(root, 'pkg_claudecowork.xml'), `<extension type="package"><packagename>x</packagename><version>2.0.0</version><scriptfile>script.php</scriptfile>
      <files folder="packages"><file type="component" id="com_claudecowork">com_claudecowork.zip</file><file type="plugin" id="xapi" group="system">plg_system_xapi.zip</file></files></extension>`)
  const m = build('joomla-package', root)
  assert.equal(m.tag, 'joomla-v2.0.0')
  assert.equal(m.path, 'administrator/components/com_claudecowork/tracy-release.json')
  assert.deepEqual(Object.keys(m.files), [
    'administrator/components/com_claudecowork/claudecowork.xml',
    'administrator/components/com_claudecowork/lib/Engine.php',
    'administrator/components/com_claudecowork/lib/contracts/p/1/manifest.json',
    'administrator/components/com_claudecowork/script.php',
    'administrator/language/en-GB/com_claudecowork.ini',
    'administrator/language/en-GB/plg_system_xapi.ini',
    'administrator/manifests/packages/pkg_claudecowork.xml',
    'administrator/manifests/packages/x/script.php',
    'components/com_claudecowork/src/A.php',
    'components/com_claudecowork/x.php',
    'plugins/system/xapi/services/provider.php',
    'plugins/system/xapi/xapi.xml'
  ])
  assert.equal(m.files['components/com_claudecowork/x.php'], sha('site entry'))
  assert.deepEqual(m.roots, ['administrator/components/com_claudecowork/', 'components/com_claudecowork/', 'plugins/system/xapi/'])
})

test('a file the manifest lists but the tree lacks fails the build', () => {
  const root = tree({
    'pkg_claudecowork.xml': `<extension type="package"><packagename>x</packagename><version>1.0.0</version>
      <files folder="packages"><file type="plugin" id="y" group="system">plg_system_y.zip</file></files></extension>`,
    'plg_system_y/y.xml': `<extension type="plugin" group="system"><version>1.0.0</version><files><filename plugin="y">y.php</filename></files></extension>`
  })
  assert.throws(() => build('joomla-package', root), /y\.php, which does not exist/)
})

test('a site template: its folder, its media, its site-side languages', () => {
  const root = tree({
    'tpl/templateDetails.xml': `<extension type="template" client="site"><name>tpl_demo</name><version>0.3.0</version>
      <files><filename>index.php</filename><filename>templateDetails.xml</filename><filename>tracy-release.json</filename><folder>html</folder></files>
      <media destination="templates/site/tpl_demo" folder="media"><folder>css</folder></media>
      <languages folder="language"><language tag="en-GB">en-GB/tpl_tpl_demo.ini</language></languages></extension>`,
    'tpl/index.php': 'index',
    'tpl/html/mod_menu/default.php': 'override',
    'tpl/media/css/a.css': 'css',
    'tpl/media/js/not-listed.js': 'never installed',
    'tpl/language/en-GB/tpl_tpl_demo.ini': 'T="t"'
  })
  const m = build('joomla-template', join(root, 'tpl'))
  assert.equal(m.tag, 'joomla-template-v0.3.0')
  assert.equal(m.path, 'templates/tpl_demo/tracy-release.json')
  assert.deepEqual(Object.keys(m.files), [
    'language/en-GB/tpl_tpl_demo.ini',
    'media/templates/site/tpl_demo/css/a.css',
    'templates/tpl_demo/html/mod_menu/default.php',
    'templates/tpl_demo/index.php',
    'templates/tpl_demo/templateDetails.xml'
  ])
  assert.deepEqual(m.roots, ['media/templates/site/tpl_demo/', 'templates/tpl_demo/'])
  // Named in <files> but written by the build after this runs: anywhere but its own path, it must exist.
  assert.throws(() => joomlaTemplate(join(root, 'tpl'), 'elsewhere.json'), /tracy-release\.json, which does not exist/)
})

test('WordPress: the plugin folder as it is, under wp-content/plugins/<slug>/', () => {
  const root = tree({
    'claude-cowork/claude-cowork.php': "<?php\n/**\n * Plugin Name: X\n * Version:     0.18.0\n */",
    'claude-cowork/lib/Engine.php': 'engine',
    'claude-cowork/tracy-release.json': 'an older manifest, never listed'
  })
  const m = wordpressPlugin(join(root, 'claude-cowork'))
  assert.equal(m.tag, 'wordpress-v0.18.0')
  assert.equal(m.path, 'wp-content/plugins/claude-cowork/tracy-release.json')
  assert.deepEqual(Object.keys(m.files), ['wp-content/plugins/claude-cowork/claude-cowork.php', 'wp-content/plugins/claude-cowork/lib/Engine.php'])
})

test('WordPress themes: the tag scheme of the release script', () => {
  const root = tree({
    'tracy/style.css': '/*\nTheme Name: Tracy\nVersion: 1.2.0\n*/',
    'wp-ja-kinetic/style.css': '/*\nTheme Name: K\nVersion: 1.1.4\n*/'
  })
  assert.equal(wordpressTheme(join(root, 'tracy')).tag, 'wordpress-theme-v1.2.0')
  const k = wordpressTheme(join(root, 'wp-ja-kinetic'))
  assert.equal(k.tag, 'wordpress-theme-wp-ja-kinetic-v1.1.4')
  assert.deepEqual(Object.keys(k.files), ['wp-content/themes/wp-ja-kinetic/style.css'])
})

test('generatedAt follows SOURCE_DATE_EPOCH when it is set', () => {
  const root = tree({ 'tracy/style.css': '/*\nVersion: 1.0.0\n*/' })
  process.env.SOURCE_DATE_EPOCH = '1790000000'
  try {
    assert.equal(wordpressTheme(join(root, 'tracy')).generatedAt, new Date(1790000000 * 1000).toISOString())
  } finally {
    delete process.env.SOURCE_DATE_EPOCH
  }
})

test('the real Joomla package: every part, the package script, the receipt manifest path', (t) => {
  // Needs the engine copy build.sh makes; without it the lib/ folder the component lists is absent.
  const cowork = join(dirname(new URL(import.meta.url).pathname), '..', 'joomla', 'cowork')
  let m
  try {
    m = build('joomla-package', cowork)
  } catch (e) {
    if (/administrator\/lib/.test(e.message)) return t.skip('run joomla/cowork/build.sh first')
    throw e
  }
  for (const root of ['administrator/components/com_claudecowork/', 'components/com_claudecowork/', 'plugins/system/claudecoworkapi/',
    'plugins/system/claudecoworkupdate/', 'plugins/system/tracyaccess/']) {
    assert.ok(m.roots.includes(root), root)
  }
  assert.ok(m.files['administrator/manifests/packages/pkg_claudecowork.xml'])
  assert.ok(m.files['administrator/manifests/packages/claudecowork/script.php'])
  assert.ok(m.files['administrator/components/com_claudecowork/lib/SecretLeaf.php'])
  assert.ok(!(m.path in m.files))
})
