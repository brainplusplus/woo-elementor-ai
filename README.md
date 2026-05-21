# Woo Elementor AI

AI-powered page generation, editing, and chat for Elementor using OpenAI-compatible APIs with flexible image generation support.

## Features

- **AI Page Generation** — Describe a page, AI builds it in Elementor
- **AI Element Editing** — Select any element, describe changes
- **AI Chat Panel** — In-editor chat for iterative design
- **Template Library** — Browse, preview, export templates as ZIP
- **Template Export** — Generate Elementor-compatible ZIP files ready for import
- **Image Integration** — Unsplash, Pexels, or OpenAI-compatible image generation
- **License System** — Domain-bound licensing with offline Ed25519 verification

## Requirements

| Dependency | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 7.4+ (sodium extension, bundled by default) |
| Elementor | Free or Pro |
| Go | 1.21+ (for license generator only) |
| GNU Make | 4.0+ (for build commands) |

## Project Structure

```
woo-elementor-ai/
├── woo-elementor-ai.php          Main plugin file
├── includes/
│   ├── class-plugin.php          Bootstrap & module init
│   ├── class-settings.php        Settings + license AJAX
│   ├── class-license.php         Ed25519 license verification
│   ├── class-ai-service.php      OpenAI-compatible API client
│   ├── class-page-generator.php  AI page generation
│   ├── class-elementor-data.php  Elementor data parsing
│   ├── class-template-library.php   Template CRUD
│   ├── class-template-exporter.php  ZIP export engine
│   ├── admin/                    Admin pages
│   ├── api/                      REST API endpoints
│   └── editor/                   Elementor editor integration
├── templates/
│   ├── settings-page.php         Settings UI
│   ├── templates-page.php        Template browser UI
│   └── packs/                    Template JSON files
├── assets/                       CSS & JS
├── woo-ai-licensegen/            Go CLI license generator
├── tools/
│   └── obfuscator/               2-layer PHP obfuscator
│       ├── layer1-scramble.php
│       ├── layer2-transform.php
│       └── obfuscate.php
├── Makefile                      Build commands
├── build.ps1                     PowerShell build script
├── .env.build.example            Build configuration template
└── README.md
```

## Quick Start

### Installation (End User)

1. Download the latest release ZIP
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the ZIP and activate
4. Navigate to **Woo Elementor AI** in the admin menu
5. Copy your **Machine Key** and send it to the license provider
6. Enter the received **License Key** and click **Activate License**
7. Configure your AI API settings

### Development Setup

```bash
# Clone the repo
git clone https://github.com/brainplusplus/woo-elementor-ai.git
cd woo-elementor-ai

# Copy build config and set your public key
cp .env.build.example .env.build

# First-time setup (builds Go binary + lints all code)
make dev-setup
```

## Make Commands

All commands are run from the project root via `make <target>`.

### Build & Package

| Command | Description |
|---|---|
| `make build` | Build distributable ZIP (reads `.env.build`) |
| `make build-ps KEY=base64string` | Build with public key override |
| `make clean` | Remove all build artifacts |

### Code Quality

| Command | Description |
|---|---|
| `make lint` | Lint all (PHP + Go) |
| `make lint-php` | PHP syntax check all files |
| `make lint-go` | Go vet the license generator |

### License Generator

| Command | Description |
|---|---|
| `make license-build` | Build the Go license generator |
| `make license-build-all` | Build for Windows, Linux, macOS |
| `make license-keygen` | Generate Ed25519 keypair (run once) |
| `make license-sign MACHINE=xxx` | Sign a machine key → outputs license key |
| `make license-pubkey` | Display the public key (base64) |

### Utilities

| Command | Description |
|---|---|
| `make help` | Show all available commands |
| `make dev-setup` | First-time setup: build + lint |
| `make obfuscate-test` | Quick test of obfuscation pipeline |

## License Activation

### Step-by-Step: Developer Side

Pertama kali setup (hanya sekali):

```bash
# 1. Setup project
make dev-setup

# 2. Generate keypair (hanya sekali, simpan baik-baik private key-nya)
make license-keygen
# Output: keys/private.key + keys/public.key

# 3. Copy build config template
cp .env.build.example .env.build

# 4. Ambil public key, paste ke .env.build
make license-pubkey
# Output: base64 string → copy ini

# 5. Edit .env.build, isi PUBLIC_KEY dengan output di atas
# PUBLIC_KEY=yQzF2dGhpcyBpcyBhIGJhc2U2NCBwdWJsaWMga2V5...

# 6. Build plugin ZIP untuk distribusi
make build
# Output: woo-elementor-ai-v1.1.0.zip
```

Setiap ada customer baru:

```bash
# Customer kirim Machine Key dari settings page plugin-nya
# Kamu generate License Key:
make license-sign MACHINE=a1b2c3d4e5f67890...

# Output: base64 string (License Key) → kirim ke customer
```

### Step-by-Step: Customer Side

```
1. Upload woo-elementor-ai-v1.1.0.zip via Plugins → Add New → Upload Plugin
2. Activate plugin
3. Buka menu Woo Elementor AI → Settings
4. Muncul tampilan:

   ┌─────────────────────────────────────────────────┐
   │  🔒 License Activation                          │
   │                                                  │
   │  Your Machine Key:                               │
   │  ┌──────────────────────────────────────────┐   │
   │  │ a1b2c3d4e5f67890abcdef...    [Copy]      │   │
   │  └──────────────────────────────────────────┘   │
   │  ↑ Copy key ini, kirim ke developer             │
   │                                                  │
   │  License Key:                                    │
   │  ┌──────────────────────────────────────────┐   │
   │  │ (paste License Key dari developer)       │   │
   │  └──────────────────────────────────────────┘   │
   │                                                  │
   │  [Activate License]                              │
   └─────────────────────────────────────────────────┘

5. Copy Machine Key → kirim ke kamu (developer)
6. Kamu kirim balik License Key
7. Customer paste License Key → klik Activate License
8. ✅ Plugin aktif, semua fitur & settings terbuka
```

### Visual Flow

```
╔═══════════════════════╗       ╔═══════════════════════╗
║     DEVELOPER (Kamu)   ║       ║    CUSTOMER (User)    ║
╚═══════════════════════╝       ╚═══════════════════════╝

  make license-keygen (1x)
         │
  make license-pubkey
  edit .env.build
  make build → ZIP
         │
         ▼
    Kirim ZIP ─────────────────▶ Install + Activate
                                          │
                                    Settings Page muncul
                                    Machine Key: a1b2c3...
                                          │
                    ◀──── Kirim Machine Key
                    │
  make license-sign
  MACHINE=a1b2c3...
                    │
                    ▼
          Kirim License Key ──────▶ Paste + Activate
                                            │
                                      ✅ Plugin Aktif
```

### Summary Peran File Key

| File | Lokasi | Fungsi | Siapa Yang Pegang |
|---|---|---|---|
| `private.key` | `woo-ai-licensegen/keys/` | Tanda tangan license | **Hanya kamu** (developer) |
| `public.key` | `woo-ai-licensegen/keys/` | Generate public key string | **Hanya kamu** (developer) |
| `PUBLIC_KEY` (di .env.build) | Root `.env.build` (gitignored, copy dari `.env.build.example`) | Embed ke plugin saat build | **Hanya kamu** (developer) |
| `Machine Key` | Settings page plugin | Identitas unik per domain | **Customer** |
| `License Key` | Settings page plugin | Hasil sign dari Machine Key | **Customer** (dari kamu) |

## Configuration

### AI Chat

| Setting | Description | Default |
|---|---|---|
| Base URL | OpenAI-compatible API endpoint | `https://api.openai.com` |
| API Key | Your API key | — |
| Model | Chat model to use | `gpt-4o` |

### Image Generation

| Source | Description |
|---|---|
| None | No image generation |
| Unsplash | Free stock photos via Unsplash API |
| Pexels | Free stock photos via Pexels API |
| OpenAI Compatible | DALL-E or compatible image generation |

### Generation Defaults

| Setting | Description | Default |
|---|---|---|
| Max Tokens | Maximum response tokens | `64000` |
| Temperature | Creativity (0-2) | `0.7` |
| Chat Max Context | Maximum context window | `8000` |

## Templates

1. Go to **Woo Elementor AI → Templates**
2. Browse available templates
3. Click **Export ZIP** to download
4. In any WordPress site: **Elementor → Templates → Import** → upload the ZIP

### Adding Custom Templates

Place JSON files in `templates/packs/` with this structure:

```json
{
  "name": "Template Name",
  "description": "Short description",
  "category": "landing",
  "type": "page",
  "preview": "",
  "version": "1.0.0",
  "content": []
}
```

## Build Configuration

Copy `.env.build.example` to `.env.build` and configure:

```bash
cp .env.build.example .env.build
```

`.env.build` controls the build:

```ini
PLUGIN_VERSION=1.2.0
PUBLIC_KEY=base64_encoded_public_key_here
REQUIRED_LICENSE_KEY=false
OBFUSCATE=true
```

| Variable | Description | Default |
|---|---|---|
| `PLUGIN_VERSION` | Version string embedded in plugin header | `1.2.0` |
| `PUBLIC_KEY` | Base64-encoded Ed25519 public key (from `make license-pubkey`) | — |
| `REQUIRED_LICENSE_KEY` | Require license activation (`true`/`false`). Set `false` to skip licensing entirely | `false` |
| `OBFUSCATE` | Apply 2-layer PHP obfuscation on build (`true`/`false`) | `true` |

## Architecture

### License System

```
Developer                          Customer (WordPress)
─────────                          ────────────────────
licensegen keygen
  → keys/private.key
  → keys/public.key

licensegen sign --machine=XXX
  → License Key (Ed25519 signed)   Settings Page:
                                    Machine Key: a1b2c3...
              License Key ────────→ Input License Key
                                    [Activate] → sodium_verify()
                                    ✅ All features unlocked
```

- **Machine Key** = `SHA-256(domain | ABSPATH | DB_NAME | AUTH_KEY)`
- **License Key** = `Base64(machine_key_hash | Ed25519_signature)`
- **Verification** = Offline, via libsodium `sodium_crypto_sign_verify_detached()`
- **Caching** = 24h transient cache

### Template Export

```
Template JSON → Template_Library → Template_Exporter → ZIP
                                                    ├── template/
                                                    │   ├── content.json
                                                    │   ├── page.json
                                                    │   └── manifest.json
```

## Code Obfuscation

The build pipeline applies **2-layer PHP obfuscation** to protect sensitive files (`class-license.php`, `class-settings.php`, `class-ai-service.php`) in distribution builds.

### Layer 1: Scramble (`tools/obfuscator/layer1-scramble.php`)

- **Comment stripping** — removes all PHPDoc and inline comments
- **String encoding** — encodes string literals as `hex2bin('...')` (skips class constants and property defaults which require constant expressions)
- **Junk method injection** — adds 2 private dead-code methods per class with random concat operations

### Layer 2: Structural Transform (`tools/obfuscator/layer2-transform.php`)

- **Junk property injection** — adds 1 private property per class with random integer value
- **Junk method injection** — adds 2 private methods per class with XOR/strlen operations
- **Method shuffling** — randomizes class member order (preserves constant declarations first)

### Pipeline

```
Source PHP → Layer 1 (scramble) → Layer 2 (structural) → Obfuscated PHP
```

Both layers use [nikic/php-parser v5](https://github.com/nikic/PHP-Parser) for AST-level transformations, ensuring the output is always syntactically valid PHP.

### Setup

```bash
cd tools/obfuscator
composer install
```

### Test

```bash
make obfuscate-test
```

### Architecture

```
tools/obfuscator/
├── composer.json          nikic/php-parser v5
├── obfuscate.php          Orchestrator (layer1 → layer2)
├── layer1-scramble.php    Comment strip + hex2bin encode + junk methods
└── layer2-transform.php   Junk properties + junk methods + shuffle
```

## License Key Generator

See [`woo-ai-licensegen/README.md`](woo-ai-licensegen/README.md) for full Go CLI tool documentation.

## Changelog

### 1.2.0
- Added: `REQUIRED_LICENSE_KEY` build config — set `false` to skip license activation entirely
- Changed: Settings page hides license section when `REQUIRED_LICENSE_KEY=false`
- Changed: Plugin unlocks all features without license when `REQUIRED_LICENSE_KEY=false`

### 1.1.0
- Added: License system with domain-bound Ed25519 verification
- Added: Template library with ZIP export
- Added: Template admin page with grid browser
- Added: REST API endpoint for template export
- Added: Makefile with build, lint, and license commands
- Added: Build pipeline with `.env.build` configuration
- Added: 2-layer PHP obfuscator (scramble + structural transform)
- Added: Cross-platform Makefile (Windows/Linux/macOS via pwsh)

### 1.0.0
- Initial release
- AI page generation
- AI element editing
- AI chat panel
- Image generation integration
