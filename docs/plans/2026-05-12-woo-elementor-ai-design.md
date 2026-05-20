# Woo Elementor AI — Design Document

**Date**: 2026-05-12
**Status**: Approved
**Text Domain**: `woo-elementor-ai`

---

## 1. Overview

WordPress plugin extending Elementor with AI-powered page generation, editing, and chat capabilities. Users bring their own OpenAI-compatible API key. Generates full Elementor pages (layout + content + styling) from text descriptions.

### Key Decisions

| Decision | Choice |
|---|---|
| Plugin name | Woo Elementor AI |
| Layout support | Section/Column + Container (backward compatible) |
| AI response format | Hybrid — Elementor JSON primary, HTML fallback |
| Chat scope | Full granularity (page → section → widget) |
| Chat history | Multi-turn persistent |
| New Page scope | Full page with real content + styling |
| Per-element AI UI | Panel injection + right-click context menu |
| Chat AI Settings | BASE_URL + API_KEY + Model (OpenAI compatible) |
| Image Source | None / Unsplash / Pexels / OpenAI Compatible (flexible, separate from chat AI) |
| Architecture | Modular OOP PHP + React editor UI (Approach B) |
| Localization | WordPress ready — `__()` everywhere, English default |

---

## 2. Plugin Architecture & File Structure

```
woo-elementor-ai/
├── woo-elementor-ai.php              # Main plugin file (bootstrap)
├── uninstall.php                      # Cleanup on uninstall
├── includes/
│   ├── class-plugin.php               # Core orchestrator
│   ├── class-settings.php             # Settings page (BASE_URL, API_KEY, model)
│   ├── class-ai-service.php           # OpenAI-compatible API client (chat)
│   ├── class-image-service.php        # Image generation service (Unsplash/Pexels/OpenAI)
│   ├── class-page-generator.php       # "New Page with AI" logic
│   ├── class-elementor-data.php       # Elementor JSON builder/helper
│   ├── class-chat-session.php         # Multi-turn chat history management
│   ├── admin/
│   │   └── class-admin-page.php       # "New with AI" button + modal
│   ├── editor/
│   │   ├── class-editor-integration.php  # Enqueue scripts, inject HTML
│   │   ├── class-panel-injection.php     # Inject "AI Assistant" to widget controls
│   │   └── class-context-menu.php        # Right-click "Edit with AI"
│   └── api/
│       ├── class-rest-controller.php     # WP REST API endpoints
│       ├── class-generate-endpoint.php   # POST /generate
│       ├── class-chat-endpoint.php       # POST /chat + GET /chat/stream
│       └── class-refine-endpoint.php     # POST /refine
├── assets/
│   ├── js/
│   │   ├── admin/
│   │   │   ├── new-page-modal.js        # Modal on Pages/Posts list
│   │   │   └── refine-prompt.js         # Refine prompt button
│   │   └── editor/
│   │       ├── ai-chat-panel.js         # Chat sidebar in Elementor editor
│   │       ├── ai-element-controls.js   # Per-element AI panel
│   │       ├── ai-context-menu.js       # Right-click menu
│   │       └── elementor-bridge.js      # Read/write Elementor via $e API
│   └── css/
│       ├── admin.css                    # Admin modal styles
│       └── editor.css                   # Editor panel + chat styles
├── templates/
│   ├── admin-new-page-modal.php         # Modal HTML template
│   └── editor-chat-panel.php            # Chat sidebar HTML template
└── languages/                           # Ready for .po/.mo files
```

---

## 3. AI Service & Prompt Architecture

### 3.1 OpenAI-Compatible API Client (Chat)

```php
class AI_Service {
    public function chat( array $messages, array $options = [] ): array
    public function chat_stream( array $messages, callable $on_chunk ): void
    public function complete( string $system_prompt, string $user_prompt ): string
}
```

Request format:
```
POST {base_url}/v1/chat/completions
{
    "model": "{model}",
    "messages": [{"role": "system", "content": "..."}, {"role": "user", "content": "..."}],
    "temperature": 0.7,
    "max_tokens": 4096
}
```

### 3.1b Image Generation Service

```php
class Image_Service {
    public function resolve_image( string $keywords ): ?array  // Returns ['url' => '...', 'id' => 123] or null
    private function search_unsplash( string $keywords ): ?array
    private function search_pexels( string $keywords ): ?array
    private function generate_openai_compatible( string $keywords ): ?array
    private function download_to_media( string $url, string $description ): int  // Returns attachment ID
}
```

AI generates Elementor JSON with `image_search: "keywords"` on image widgets. Plugin post-processes: resolves keywords → actual image via selected source → downloads to WordPress media library → replaces with `image: {"url": "...", "id": "123"}`.

**Image sources** (configured in settings):

| Source | Method | Endpoint |
|---|---|---|
| None | Skip image resolution, use placeholder | - |
| Unsplash | Search API → download | `https://api.unsplash.com/search/photos` |
| Pexels | Search API → download | `https://api.pexels.com/v1/search` |
| OpenAI Compatible | Image generation | `{image_base_url}{image_endpoint}` |

**OpenAI Compatible image settings** (separate from chat):
- Base URL (e.g., `https://openrouter.ai/api/v1`)
- API Key (can differ from chat API key)
- Model (e.g., `dall-e-3`)
- Image Endpoint (e.g., `/v1/images/generations`)

This allows mixing providers: e.g., Chat AI via OpenAI, Image generation via OpenRouter.

### 3.2 System Prompts

| Context | Purpose | Used By |
|---|---|---|
| Page Generator | Generate full Elementor JSON from description | `class-page-generator.php` |
| Element Editor | Edit specific widget — receives current settings + prompt | Per-element AI controls |
| Chat Assistant | Multi-turn editor assistant with page context | Chat panel |

### 3.3 Hybrid Validation Pipeline

1. Try JSON parse directly → validate structure → inject
2. Extract JSON from markdown code blocks → validate → inject
3. HTML fallback → convert to text-editor widgets → inject

### 3.4 Chat History

- Stored in user meta: `_woo_elementor_ai_chat_{post_id}`
- Each request includes: chat history + current page Elementor JSON (condensed) + selected element info
- Token management: truncate old messages beyond configurable threshold (default 8000 tokens)

### 3.5 Streaming

Chat panel uses Server-Sent Events (SSE) for real-time token-by-token response display.

---

## 4. UX Flows — Four Integration Surfaces

### 4.1 Surface A: "New Page with AI" (Admin Pages/Posts List)

Button added next to "Add New" on both Pages and Posts list tables. Context determines post type automatically (Pages list → Page, Posts list → Post).

```
Modal form:
┌────────────────────────────────────────────┐
│  Generate Page with AI                 [X] │
├────────────────────────────────────────────┤
│  Page Title: [________________________]    │
│  Describe your page:                       │
│  ┌──────────────────────────────────────┐  │
│  │ Landing page untuk kafe modern...    │  │
│  └──────────────────────────────────────┘  │
│                               [✨ Refine]  │
│         [ Cancel ]    [ Generate Page ]     │
└────────────────────────────────────────────┘
```

- **Refine button**: sends raw description to AI for expansion, updates textarea
- **No Layout selector**: uses Elementor's existing page layout setting
- **No Page Type selector**: auto-detected from list table context
- **On success**: redirects to Elementor editor with new page loaded

### 4.2 Surface B: Settings Page

Top-level menu item "Woo Elementor AI" in WordPress admin sidebar.

**Section 1: AI Chat Configuration**
- **Base URL** — OpenAI compatible endpoint (default: `https://api.openai.com`)
- **API Key** — masked input + "Test Connection" button
- **Model** — text input with common suggestions dropdown

**Section 2: Image Generation**
- **Image Source** — dropdown: None / Unsplash / Pexels / OpenAI Compatible
- **Conditional fields** (shown based on selected source):
  - **Unsplash**: API Key input + link to https://unsplash.com/developers
  - **Pexels**: API Key input + link to https://www.pexels.com/api/
  - **OpenAI Compatible**: Base URL, API Key, Model, Image Endpoint (default: `/v1/images/generations`)

**Section 3: Generation Defaults**
- **Max Tokens** — default 4096
- **Temperature** — default 0.7
- **Chat Max Context** — default 8000 tokens

Saved to `wp_options` as single array `woo_elementor_ai_settings`.

### 4.3 Surface C: AI Chat Panel (Elementor Editor)

Toggle button "AI Chat" in editor top bar. Opens collapsible sidebar panel on right side.

Features:
- **Context selector**: Page / Section / Widget scope
- **Chat history**: scrollable, persistent messages
- **Streaming**: real-time token-by-token AI response
- **Quick actions**: preset buttons ("Add section", "Change color theme", "Fix responsive layout")
- **Clear chat**: reset history, start fresh
- Chat generates Elementor JSON → updates canvas via `$e` API

### 4.4 Surface D: Per-Element AI Controls

**Panel injection**: "AI Assistant" section added to every widget's settings panel (all tabs). Contains prompt textarea, refine button, generate button.

**Right-click context menu** (on canvas elements):
- 🤖 Edit with AI → opens prompt modal, response updates that element
- 🤖 Generate Variations → keeps layout, regenerates content + styling
- 🤖 Improve Layout → one-click, AI improves layout without prompt

---

## 5. REST API Endpoints

Namespace: `woo-elementor-ai/v1`

### 5.1 Endpoints

| Method | Endpoint | Purpose | Capability |
|---|---|---|---|
| `POST` | `/generate` | Generate full page | `edit_posts` |
| `POST` | `/generate/element` | Generate/edit specific element | `edit_posts` |
| `POST` | `/chat` | Chat (blocking) | `edit_posts` |
| `GET` | `/chat/stream` | Chat (SSE streaming) | `edit_posts` |
| `POST` | `/refine` | Refine prompt with AI | `edit_posts` |
| `POST` | `/chat/clear` | Clear chat history | `edit_posts` |
| `GET` | `/chat/history` | Get chat history | `edit_posts` |
| `GET` | `/settings` | Get settings (masked key) | `manage_options` |
| `POST` | `/settings` | Save settings | `manage_options` |
| `POST` | `/settings/test` | Test API connection | `manage_options` |

### 5.2 Key Request/Response Shapes

**POST `/generate`**
```json
{
    "title": "Landing Page Kafe",
    "prompt": "Landing page untuk kafe dengan hero, menu, testimonial, kontak",
    "layout": "container",
    "refined": false
}
→ Response: { "success": true, "data": { "post_id": 123, "edit_url": "..." } }
```

**POST `/generate/element`**
```json
{
    "post_id": 123,
    "element_id": "6af611eb",
    "prompt": "Change to dark theme with gold accents",
    "element_context": { "elType": "container", "current_settings": {} }
}
→ Response: { "success": true, "data": { "element_id": "...", "new_settings": {} } }
```

**POST `/chat`**
```json
{
    "post_id": 123,
    "message": "Tambahkan CTA button di bawah hero",
    "context": "page",
    "target_element_id": null
}
→ Response: { "success": true, "data": { "content": "...", "actions": [...], "applied": true } }
```

**POST `/refine`**
```json
{
    "prompt": "landing page kafe",
    "context": "page"
}
→ Response: { "success": true, "data": { "refined_prompt": "..." } }
```

### 5.3 Security

- Nonce verification on every endpoint
- Capability checks: `edit_posts` for generate/chat, `manage_options` for settings
- API key stored in `wp_options`, admin-only access
- Input sanitization: `sanitize_text_field()`, `absint()`, `wp_kses_post()`
- Output escaping: `esc_html__()`, `esc_attr__()`, `wp_json_encode()`

### 5.4 Error Handling

Unified error format:
```json
{
    "success": false,
    "data": {
        "code": "ai_connection_error",
        "message": "Unable to reach AI service. Check your Base URL and API Key.",
        "details": "Connection timed out after 30s"
    }
}
```

Error codes: `ai_connection_error`, `ai_invalid_response`, `ai_rate_limited`, `invalid_elementor_json`, `missing_settings`, `nonce_verification_failed`, `insufficient_permissions`

---

## 6. Data Flow

### 6.1 Full Page Generation Flow

1. User clicks "New Page with AI" on Pages/Posts list
2. Modal opens → user types description → optional refine
3. JS sends POST `/generate` with title + prompt
4. PHP builds system prompt + calls OpenAI-compatible API
5. AI returns Elementor JSON (or text with embedded JSON)
6. PHP validates/parses via hybrid pipeline
7. PHP post-processes: resolves `image_search` keywords → actual images via configured image source → downloads to media library → replaces in Elementor JSON
8. PHP creates `wp_insert_post()` + sets Elementor metas (`_elementor_edit_mode`, `_elementor_template_type`, `_elementor_data`, `_elementor_version`)
9. JS redirects to Elementor editor

### 6.2 Chat → Elementor Update Flow

1. User sends message in chat panel
2. JS sends POST `/chat` with message + context + target element
3. PHP loads chat history from user meta + current page Elementor data
4. PHP calls AI with full context
5. AI returns response with Elementor JSON actions (create/update/delete elements)
6. PHP applies changes to `_elementor_data` post meta
7. PHP saves chat message to history
8. Response includes actions
9. JS applies changes to editor canvas via `$e` API

### 6.3 Per-Element Edit Flow

1. User clicks AI button on widget panel OR right-clicks element → "Edit with AI"
2. Prompt modal opens → user describes changes
3. JS sends POST `/generate/element` with element ID + current settings + prompt
4. PHP builds targeted system prompt with current element context
5. AI returns updated settings for that element
6. PHP validates and responds
7. JS applies via `$e.run( 'document/elements/settings', ... )`
