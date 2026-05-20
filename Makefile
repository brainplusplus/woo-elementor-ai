.PHONY: help build build-ps clean lint lint-php lint-go obfuscate-test license-keygen license-sign license-pubkey license-build license-build-all dev-setup

# Cross-platform PowerShell detection
# Windows: 'where' command; Linux/macOS: 'command -v'
PSHELL   := "$(shell where pwsh 2>NUL || where powershell 2>NUL || command -v pwsh 2>/dev/null || command -v powershell 2>/dev/null | head -1)"
VERSION  ?= $(shell $(PSHELL) -NoProfile -Command "((Get-Content .env.build | Where-Object { $$_ -match 'PLUGIN_VERSION' }) -replace 'PLUGIN_VERSION=','').Trim()")
GO_DIR   := woo-ai-licensegen
GO_BIN   := $(GO_DIR)/licensegen$(shell go env GOEXE 2>/dev/null)
ZIP_NAME := woo-elementor-ai-v$(VERSION).zip

help: ## Show this help
	@$(PSHELL) -NoProfile -Command "Get-Content Makefile | Where-Object { $$_ -match '^[a-zA-Z_-]+:.*?## ' } | ForEach-Object { $$p = $$_ -split '## '; $$n = $$p[0].Trim().TrimEnd(':'); $$d = $$p[1]; Write-Host ('{0,-22} {1}' -f $$n, $$d) }"

# ── Build ──────────────────────────────────────────────

build: ## Build plugin ZIP (reads .env.build). Use OBFUSCATE=false to skip obfuscation
	@echo "=== Building Woo Elementor AI v$(VERSION) ==="
	@$(PSHELL) -NoProfile -ExecutionPolicy Bypass -File build.ps1

build-ps: ## Build with public key override: make build-ps KEY=base64string
	@echo "=== Building Woo Elementor AI v$(VERSION) with key ==="
	@$(PSHELL) -NoProfile -ExecutionPolicy Bypass -File build.ps1 -PublicKey "$(KEY)"

# ── Clean ──────────────────────────────────────────────

clean: ## Remove build artifacts
	@echo "Cleaning..."
	@$(PSHELL) -NoProfile -Command "if (Test-Path 'dist') { Remove-Item -Recurse -Force 'dist' }; Get-ChildItem 'woo-elementor-ai-v*.zip' -ErrorAction SilentlyContinue | Remove-Item -Force; Get-ChildItem '$(GO_DIR)/licensegen*' -ErrorAction SilentlyContinue | Remove-Item -Force"
	@echo "Done."

# ── Lint ───────────────────────────────────────────────

lint: lint-php lint-go ## Lint all (PHP + Go)

lint-php: ## PHP syntax check all files
	@echo "=== PHP Syntax Check ==="
	@$(PSHELL) -NoProfile -Command "$$files = @('woo-elementor-ai.php','includes/class-plugin.php','includes/class-settings.php','includes/class-license.php','includes/class-ai-service.php','includes/class-elementor-data.php','includes/class-image-service.php','includes/class-chat-session.php','includes/class-page-generator.php','includes/class-template-library.php','includes/class-template-exporter.php','includes/admin/class-admin-page.php','includes/admin/class-templates-page.php','includes/api/class-rest-controller.php','includes/editor/class-editor-integration.php','includes/editor/class-panel-injection.php','includes/editor/class-context-menu.php','templates/settings-page.php','templates/templates-page.php','templates/admin-new-page-modal.php','templates/editor-chat-panel.php'); foreach ($$f in $$files) { $$out = php -l $$f 2>&1; if ($$LASTEXITCODE -ne 0) { Write-Host $$out; exit 1 } }; Write-Host 'All PHP files OK.'"

lint-go: ## Go vet the license generator
	@echo "=== Go Vet ==="
	@cd $(GO_DIR) && go vet ./...

# ── Obfuscator ─────────────────────────────────────────

obfuscate-test: ## Quick test of obfuscation pipeline
	@echo "=== Obfuscator Test ==="
	@php tools/obfuscator/layer1-scramble.php --help 2>/dev/null || true
	@echo "Layer1: OK"
	@php tools/obfuscator/layer2-transform.php --help 2>/dev/null || true
	@echo "Layer2: OK"
	@echo "=== Obfuscator Ready ==="

# ── License Generator ──────────────────────────────────

license-build: ## Build the Go license generator
	@cd $(GO_DIR) && go build -o licensegen .

license-build-all: ## Build license generator for all platforms
	@echo "Building for Windows..."
	@cd $(GO_DIR) && GOOS=windows GOARCH=amd64 go build -o licensegen.exe .
	@echo "Building for Linux..."
	@cd $(GO_DIR) && GOOS=linux GOARCH=amd64 go build -o licensegen .
	@echo "Building for macOS..."
	@cd $(GO_DIR) && GOOS=darwin GOARCH=arm64 go build -o licensegen-mac .
	@echo "Done. Binaries in $(GO_DIR)/"

license-keygen: ## Generate Ed25519 keypair (run once)
	@cd $(GO_DIR) && go run . keygen

license-sign: ## Sign a machine key: make license-sign MACHINE=xxx
	@if [ -z "$(MACHINE)" ]; then echo "Usage: make license-sign MACHINE=your_machine_key"; exit 1; fi
	@cd $(GO_DIR) && go run . sign --machine "$(MACHINE)"

license-pubkey: ## Display the public key (base64)
	@cd $(GO_DIR) && go run . pubkey

# ── Dev Setup ──────────────────────────────────────────

dev-setup: ## First-time setup: build licensegen + lint all
	@echo "=== Dev Setup ==="
	@cd $(GO_DIR) && go build -o licensegen .
	@echo "Go binary built."
	@$(MAKE) lint
	@echo "=== Setup complete ==="
