# Template Generator + License System Design

**Date**: 2026-05-12
**Status**: Draft
**Approach**: Hybrid (PHP Plugin + Go License Generator)

## Overview

Add two major features to Woo Elementor AI:

1. **Template Generator** — Generate Elementor templates and export as ZIP files ready for import
2. **License System** — Domain-bound licensing with offline cryptographic verification

Additional: External Go CLI tool for generating license keys from machine keys.

## Component 1: Machine Key Generation

Generate a unique fingerprint per WordPress installation.

```
Machine Key = SHA-256( domain + "|" + ABSPATH + "|" + DB_NAME + "|" + AUTH_KEY )
```

- `domain` = `parse_url(site_url(), PHP_URL_HOST)`
- `ABSPATH` = WordPress root path
- `DB_NAME` = from `$wpdb->dbname`
- `AUTH_KEY` = from `wp-config.php` (unique per install)
- Output: 64-char hex string

### Why this combination

| Value alone | Spoofable? | Combined |
|---|---|---|
| Domain only | Yes (localhost spoof) | Hard to spoof all 4 |
| ABSPATH only | Yes (predictable paths) | Requires filesystem access |
| DB_NAME only | Yes (common names) | Requires database access |
| AUTH_KEY only | No, but single point | Layered defense |

## Component 2: License Key Cryptographic Flow

### Key Algorithm

Ed25519 (via libsodium, bundled in PHP 7.2+)

### Key Distribution

| Key | Location | Purpose |
|---|---|---|
| Ed25519 Private Key | Go CLI tool only | Sign license keys |
| Ed25519 Public Key | Embedded in PHP plugin | Verify license keys |

### License Key Format

```
BASE64( machine_key_hash(16 chars) + "|" + sodium_signature(64 bytes) )
```

- `machine_key_hash` = first 16 chars of `SHA-256(Machine Key)` — binds license to domain
- `signature` = `sodium_crypto_sign_detached(machine_key_hash, private_key)`

### Verification Flow (PHP)

```php
// 1. Decode license key from base64
$decoded = base64_decode($license_key);

// 2. Split into hash + signature
[$stored_hash, $signature] = explode('|', $decoded, 2);

// 3. Compute expected hash from current Machine Key
$expected_hash = substr(hash('sha256', $machine_key), 0, 16);

// 4. Verify signature with embedded public key
$valid = sodium_crypto_sign_verify_detached(
    $signature,
    $stored_hash,
    $public_key
);

// 5. Compare hashes (binds to this domain)
$match = hash_equals($expected_hash, $stored_hash);

// 6. License valid if both pass
return $valid && $match;
```

### Properties

- **Offline** — no server callback required
- **Domain-bound** — license only valid for matching Machine Key
- **Tamper-proof** — cannot generate valid signature without private key
- **Fast** — sodium verify < 1ms

## Component 3: Settings Page UI

### State 1: Unlicensed (Default)

Settings page shows only license section. All other settings hidden.

```
License Activation
├── Machine Key: [readonly, copyable] a1b2c3d4e5f6...
├── License Key: [text input]
└── [Activate License] button

Warning: Plugin functionality locked until license activated.
```

### State 2: Licensed

Machine Key + License Key remain visible. All settings unlocked.

```
License Active
├── Machine Key: a1b2c3d4e5f6... [Copy]
├── License Key: xxxxxxxx... [Deactivate]
│
├── AI Chat Configuration
│   ├── Base URL
│   ├── API Key
│   └── Model
├── Image Generation
│   ├── Source selector
│   └── Source-specific settings
└── Generation Defaults
    ├── Max Tokens
    ├── Temperature
    └── Chat Max Context
```

### Storage

- License key: `wp_options` key `woo_elementor_ai_license`
- Verification result cached in transient `woo_elementor_ai_license_valid` (24h TTL)
- On "Deactivate": delete both options

### Settings Class Changes

New methods in `class-settings.php`:
- `get_machine_key(): string`
- `verify_license(string $key): bool`
- `is_licensed(): bool`
- `activate_license(string $key): array`
- `deactivate_license(): void`

## Component 4: Template Generator + ZIP Export

### Elementor Template ZIP Structure

Elementor expects this format for template import:

```
template.zip
├── template/
│   ├── content.json     — Elementor elements JSON array
│   ├── page.json        — Page metadata (title, page/template type)
│   └── manifest.json    — Template manifest (name, version, type)
```

### manifest.json Format

```json
{
  "name": "Template Name",
  "version": "1.0.0",
  "type": "page",
  "elementor_version": "3.20.0",
  "created_at": "2026-05-12T00:00:00+00:00"
}
```

### page.json Format

```json
{
  "title": "Template Name",
  "post_type": "page",
  "template_type": "wp-page"
}
```

### New Files

| File | Purpose |
|---|---|
| `includes/class-license.php` | License verification logic (to be obfuscated) |
| `includes/class-template-library.php` | Template collection management |
| `includes/class-template-exporter.php` | Export templates to ZIP |
| `includes/admin/class-templates-page.php` | Admin page for template browser |
| `templates/templates-page.php` | Template browser UI template |
| `templates/packs/*.json` | Pre-built template packs |

### Generator Flow

1. User browses templates from new "Templates" admin menu
2. Selects template → preview
3. Clicks "Export ZIP"
4. `ZipArchive` creates ZIP with Elementor-compatible structure
5. Browser downloads ZIP
6. User imports via Elementor → Templates → Import

### AI-Enhanced Generation

Existing AI generation flow enhanced:
1. User prompts AI → generates Elementor elements
2. Option to "Save as Template" — stores in template library
3. Can export saved templates as ZIP

## Component 5: Go License Generator Tool

### Separate Repository

`woo-ai-licensegen/` — independent Go project

### Project Structure

```
licensegen/
├── main.go           — CLI entry point
├── keygen.go         — Keypair generation
├── sign.go           — License signing
├── keys/
│   ├── private.key   — Ed25519 private key (generated once)
│   └── public.key    — Ed25519 public key (copy to plugin)
├── go.mod
├── go.sum
└── README.md
```

### CLI Commands

```bash
# Generate Ed25519 keypair (run once)
./licensegen keygen
# Creates keys/private.key + keys/public.key

# Sign a machine key → produces license key
./licensegen sign --machine="a1b2c3d4e5f6..."
# Output: BASE64 encoded license key string

# Show public key (for embedding in plugin)
./licensegen pubkey
# Output: base64 encoded public key
```

### Cross-Platform Build

```bash
GOOS=windows GOARCH=amd64 go build -o licensegen.exe .
GOOS=linux GOARCH=amd64 go build -o licensegen .
GOOS=darwin GOARCH=arm64 go build -o licensegen-mac .
```

### Dependencies

- Go standard library only (no external deps)
- `crypto/ed25519` — built-in Ed25519
- `encoding/base64` — license encoding
- `flag` — CLI flags

## Component 6: Obfuscation Strategy

### Files to Obfuscate (Critical Only)

| File | Reason |
|---|---|
| `includes/class-license.php` | License verification — hardest target |
| `includes/class-settings.php` | License gating logic |
| `includes/class-ai-service.php` | API interaction patterns |

All other files remain clean/unobfuscated.

### Obfuscation Methods

**Option A: YAK Pro - Php Obfuscator** (open source, battle-tested)
- Renames all symbols (variables, functions, classes) to random strings
- Encodes string literals
- Removes comments & whitespace
- Shuffles code blocks

**Option B: Custom PHP-Parser based** (more control)
- AST transformation
- String encryption with runtime decryption
- Control flow flattening
- Dead code injection

### Build Script

`build.sh` (or `build.ps1` for Windows):
1. Copy source to `dist/` directory
2. Run obfuscator on critical files
3. Embed public key in `class-license.php`
4. Create distributable ZIP

### Distribution Package

```
woo-elementor-ai-v1.1.0.zip
├── woo-elementor-ai/
│   ├── woo-elementor-ai.php
│   ├── includes/
│   │   ├── class-license.php        ← OBFUSCATED
│   │   ├── class-settings.php       ← OBFUSCATED
│   │   ├── class-ai-service.php     ← OBFUSCATED
│   │   ├── class-plugin.php         ← clean
│   │   ├── class-page-generator.php ← clean
│   │   ├── class-elementor-data.php ← clean
│   │   ├── class-image-service.php  ← clean
│   │   ├── class-chat-session.php   ← clean
│   │   ├── class-template-library.php  ← clean
│   │   ├── class-template-exporter.php ← clean
│   │   ├── admin/
│   │   ├── api/
│   │   └── editor/
│   ├── assets/
│   ├── templates/
│   ├── languages/
│   └── uninstall.php
```

## Architecture Diagram

```
[Developer]
    │
    ├── Go CLI: licensegen
    │   ├── keygen → creates Ed25519 keypair
    │   └── sign --machine=XXXXX → outputs License Key
    │   (Private key NEVER leaves this tool)
    │
    └── Gives License Key to customer

[Customer - WordPress Site]
    │
    ├── Settings Page (unlicensed state)
    │   ├── Displays: Machine Key (computed from domain+ABSPATH+DB_NAME+AUTH_KEY)
    │   ├── Input: License Key
    │   └── [Activate] → sodium_crypto_sign_verify_detached()
    │
    ├── Verification (obfuscated PHP)
    │   ├── Embedded: Ed25519 public key
    │   ├── Checks: signature valid + hash matches machine key
    │   └── Result: cached in transient (24h)
    │
    └── Licensed → all features unlocked
        ├── Template Generator
        ├── Template Export → ZIP
        ├── AI Chat
        └── All settings
```

## Security Considerations

1. **Private key protection**: Only exists in Go CLI tool, never in plugin
2. **Machine key binding**: License tied to specific domain + install
3. **Offline verification**: No phone-home, no server dependency
4. **Obfuscation**: Critical files obfuscated, raises bar significantly
5. **Transient caching**: Avoid re-verification on every page load (24h cache)
6. **No bypass via direct DB**: License check in obfuscated code, hard to patch out

## File Changes Summary

### New Files (Plugin)
- `includes/class-license.php`
- `includes/class-template-library.php`
- `includes/class-template-exporter.php`
- `includes/admin/class-templates-page.php`
- `templates/templates-page.php`
- `templates/packs/` (template JSON files)

### Modified Files (Plugin)
- `includes/class-plugin.php` — load new dependencies + init modules
- `includes/class-settings.php` — add license fields + gating logic
- `templates/settings-page.php` — add license UI section + conditional display
- `woo-elementor-ai.php` — version bump

### New Project (Separate)
- `woo-ai-licensegen/` — Go CLI tool for license generation
