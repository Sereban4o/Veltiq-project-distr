/* global process, console, Buffer */
// Builds dist/asset-manifest.json: a sha256 per bundle file plus an aggregate
// `bundleId`. The web client reports the id back to the API (X-Veltiq-Bundle)
// and the backend warns + audits if it does not match the expected release.
import { createHash } from 'node:crypto'
import { readdir, readFile, stat, writeFile } from 'node:fs/promises'
import { join, relative, sep } from 'node:path'

const distDir = process.argv[2] || 'dist'
const MANIFEST_NAME = 'asset-manifest.json'

async function walk(dir) {
  const entries = await readdir(dir, { withFileTypes: true })
  const files = []

  for (const entry of entries) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) {
      files.push(...(await walk(full)))
    } else if (entry.isFile()) {
      files.push(full)
    }
  }

  return files
}

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex')
}

async function main() {
  try {
    await stat(distDir)
  } catch {
    console.error(`assets-manifest: ${distDir} not found — run the build first`)
    process.exit(1)
  }

  const all = await walk(distDir)
  const files = {}
  const lines = []

  for (const file of all.sort()) {
    const name = relative(distDir, file).split(sep).join('/')
    if (name === MANIFEST_NAME) continue

    const hash = sha256(await readFile(file))
    files[name] = hash
    // Sort-insensitive aggregate input (files are already sorted).
    lines.push(`${name}:${hash}`)
  }

  const bundleId = sha256(Buffer.from(lines.join('\n'))).slice(0, 40)

  const manifest = {
    version: 1,
    bundleId,
    generatedAt: new Date().toISOString(),
    files,
  }

  await writeFile(join(distDir, MANIFEST_NAME), `${JSON.stringify(manifest, null, 2)}\n`)

  console.log(`assets-manifest: ${Object.keys(files).length} files, bundleId=${bundleId}`)
}

await main()
