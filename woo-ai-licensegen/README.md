# License Generator for Woo Elementor AI

Go CLI tool for generating Ed25519-based license keys bound to WordPress site Machine Keys.

## Setup

```bash
go build -o licensegen.exe .
```

## Usage

### Generate Keypair (one-time)

```bash
./licensegen keygen
```

Creates `keys/private.key` and `keys/public.key`. **Never share `private.key`.**

### Sign a Machine Key

```bash
./licensegen sign --machine <key>
```

Outputs a base64-encoded license key string. Give this to the customer.

### Display Public Key

```bash
./licensegen pubkey
```

Copy this into the plugin's `.env.build` as `PUBLIC_KEY=<base64 string>`.

## Cross-Platform Build

```bash
GOOS=windows GOARCH=amd64 go build -o licensegen.exe .
GOOS=linux   GOARCH=amd64 go build -o licensegen .
GOOS=darwin  GOARCH=arm64 go build -o licensegen-mac .
```

## Security

- Private key only exists in `keys/private.key` (gitignored)
- Public key embedded in plugin for offline verification
- License keys are cryptographically signed (Ed25519)
- Keys are domain-bound via Machine Key hash

## How It Works

1. WordPress plugin computes **Machine Key** from `SHA-256(domain|ABSPATH|DB_NAME|AUTH_KEY)`
2. Customer provides Machine Key to developer
3. Developer runs `licensegen sign --machine <key>` → outputs License Key
4. Customer enters License Key in plugin settings
5. Plugin verifies offline using embedded public key
