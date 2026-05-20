(function($) {
    'use strict';

    var aiConfig = null;

    function getAiConfig() {
        if (aiConfig) return Promise.resolve(aiConfig);
        return fetch(wooAiAdmin.apiUrl + '/ai-config', {
            headers: { 'X-WP-Nonce': wooAiAdmin.nonce }
        }).then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) { aiConfig = res.data; }
            return aiConfig;
        });
    }

    function fetchAI(messages, maxTokens, onChunk) {
        var url = aiConfig.base_url + 'chat/completions';
        var body = JSON.stringify({
            model: aiConfig.model,
            messages: messages,
            max_tokens: maxTokens,
            temperature: 0.7,
            stream: true
        });

        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + aiConfig.api_key
            },
            body: body
        }).then(function(response) {
            if (!response.ok) throw new Error('AI HTTP ' + response.status);
            return readAIStream(response.body, onChunk);
        });
    }

    function readAIStream(body, onChunk) {
        var reader = body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        var fullContent = '';
        var chunkCount = 0;

        function processBuffer() {
            var lines = buffer.split('\n');
            buffer = lines.pop();
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].trim();
                if (line.indexOf('data: ') === 0) {
                    var payload = line.substring(6);
                    if (payload === '[DONE]') continue;
                    try {
                        var chunk = JSON.parse(payload);
                        if (chunk.choices && chunk.choices[0]) {
                            var delta = chunk.choices[0].delta || {};
                            if (delta.content) {
                                fullContent += delta.content;
                                chunkCount++;
                                if (onChunk) onChunk(delta.content, null, chunkCount);
                            }
                        }
                    } catch(e) {}
                }
            }
        }

        function read() {
            return reader.read().then(function(result) {
                if (result.done) { processBuffer(); return fullContent; }
                buffer += decoder.decode(result.value, { stream: true });
                processBuffer();
                return read();
            });
        }

        return read();
    }

    window.wooAiModal = {
        init: function() {
            if (!wooAiAdmin.isConfigured) {
                return;
            }
            var btn = $('<a href="#" class="woo-ai-btn-ai page-title-action"><span class="dashicons dashicons-star-filled"></span>' + wooAiAdmin.i18n.newWithAi + '</a>');
            var existing = $('.page-title-action').first();
            if (existing.length) {
                existing.after(btn);
            } else {
                $('.wrap h1').first().append(btn);
            }
            btn.on('click', function(e) {
                e.preventDefault();
                wooAiModal.open();
            });
        },

        open: function() {
            $('#woo-ai-page-title').val('');
            $('#woo-ai-prompt').val('');
            wooAiModal.setStatus('');
            $('#woo-ai-modal-overlay').show();
        },

        close: function() {
            $('#woo-ai-modal-overlay').hide();
            wooAiModal.setLoading(false);
        },

        refine: function() {
            var prompt = $('#woo-ai-prompt').val().trim();
            if (!prompt) return;

            var btn = $('#woo-ai-refine-btn');
            btn.prop('disabled', true);
            wooAiModal.setStatus(wooAiAdmin.i18n.refining, 'generating');

            if (wooAiAdmin.aiMode === 'frontend') {
                wooAiModal.refineFrontend(prompt, btn);
            } else {
                wooAiModal.refineBackend(prompt, btn);
            }
        },

        refineBackend: function(prompt, btn) {
            fetch(wooAiAdmin.apiUrl + '/refine/stream', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wooAiAdmin.nonce
                },
                body: JSON.stringify({
                    prompt: prompt,
                    context: 'page',
                    copywriting_method: $('#woo-ai-copywriting-method').val() || '',
                    language: $('#woo-ai-language').val() || 'id'
                })
            }).then(function(response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return wooAiModal.readSSE(response.body, function(event) {
                    if (event.type === 'chunk') {
                        wooAiModal.setStatus(wooAiAdmin.i18n.refining + '...', 'generating');
                    } else if (event.type === 'complete') {
                        $('#woo-ai-prompt').val(event.refined_prompt);
                        wooAiModal.setStatus('');
                    } else if (event.type === 'error') {
                        wooAiModal.setStatus(event.message || 'Refine failed', 'error');
                    }
                });
            }).catch(function(err) {
                wooAiModal.setStatus('Network error: ' + err.message, 'error');
            }).finally(function() { btn.prop('disabled', false); });
        },

        refineFrontend: function(prompt, btn) {
            getAiConfig().then(function() {
                var systemPrompt = 'You are a prompt refinement assistant for an AI page designer. Expand brief page descriptions into detailed, specific prompts that describe: sections needed, content for each section, color scheme, typography style, spacing, overall layout, and visual mood (e.g. dark premium, modern minimal, editorial luxury). Be specific about the desired aesthetic feel. Output ONLY the refined prompt, nothing else.';
                var userPrompt = "Expand this description into a detailed page building prompt:\n\n\"" + prompt + "\"";

                return fetchAI([
                    { role: 'system', content: systemPrompt },
                    { role: 'user', content: userPrompt }
                ], 4000, function(content, reasoning, count) {
                    wooAiModal.setStatus(wooAiAdmin.i18n.refining + '... (' + count + ')', 'generating');
                });
            }).then(function(fullContent) {
                if (fullContent) {
                    $('#woo-ai-prompt').val(fullContent.trim());
                }
                wooAiModal.setStatus('');
            }).catch(function(err) {
                wooAiModal.setStatus('Error: ' + err.message, 'error');
            }).finally(function() { btn.prop('disabled', false); });
        },

        generate: function() {
            var title  = $('#woo-ai-page-title').val().trim();
            var prompt = $('#woo-ai-prompt').val().trim();

            if (!title || !prompt) {
                wooAiModal.setStatus('Title and description are required', 'error');
                return;
            }

            wooAiModal.setLoading(true);
            wooAiModal.setStatus(wooAiAdmin.i18n.generating, 'generating');

            if (wooAiAdmin.aiMode === 'frontend') {
                wooAiModal.generateFrontend(title, prompt);
            } else {
                wooAiModal.generateBackend(title, prompt);
            }
        },

        generateBackend: function(title, prompt) {
            fetch(wooAiAdmin.apiUrl + '/generate/stream', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wooAiAdmin.nonce
                },
                body: JSON.stringify({
                    title: title,
                    prompt: prompt,
                    post_type: wooAiAdmin.postType,
                    copywriting_method: $('#woo-ai-copywriting-method').val() || '',
                    language: $('#woo-ai-language').val() || 'id'
                })
            }).then(function(response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return wooAiModal.readSSE(response.body, function(event) {
                    if (event.type === 'chunk') {
                        var msg = wooAiAdmin.i18n.generating;
                        if (event.tokens) msg += ' (' + event.tokens + ' chunks)';
                        wooAiModal.setStatus(msg, 'generating');
                    } else if (event.type === 'complete') {
                        if (event.edit_url) window.location.href = event.edit_url;
                    } else if (event.type === 'error') {
                        wooAiModal.setLoading(false);
                        wooAiModal.setStatus(event.message || 'Generation failed', 'error');
                    }
                });
            }).catch(function(err) {
                wooAiModal.setLoading(false);
                wooAiModal.setStatus('Network error: ' + err.message, 'error');
            });
        },

        generateFrontend: function(title, prompt) {
            getAiConfig().then(function() {
                wooAiModal.setStatus('Fetching system prompt...', 'generating');

                var method = $('#woo-ai-copywriting-method').val() || '';
                var language = $('#woo-ai-language').val() || 'id';

                var suffix = "\n\n";
                if (method) suffix += "COPYWRITING METHOD: " + method + "\n\n";
                suffix += "Write ALL text content in Indonesian (Bahasa Indonesia).";

                var userPrompt = "Create a page titled \"" + title + "\" with the following description:\n\n" + prompt + suffix;

                wooAiModal.setStatus(wooAiAdmin.i18n.generating, 'generating');

                return fetchAI([
                    { role: 'system', content: wooAiModal.getPageSystemPrompt() },
                    { role: 'user', content: userPrompt }
                ], 64000, function(content, reasoning, count) {
                    var msg = wooAiAdmin.i18n.generating;
                    if (count) msg += ' (' + count + ' chunks)';
                    wooAiModal.setStatus(msg, 'generating');
                }).then(function(fullContent) {
                    if (!fullContent) throw new Error('AI returned empty content');
                    wooAiModal.setStatus('Processing page...', 'generating');
                    return fetch(wooAiAdmin.apiUrl + '/generate/process', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': wooAiAdmin.nonce
                        },
                        body: JSON.stringify({
                            title: title,
                            content: fullContent,
                            post_type: wooAiAdmin.postType
                        })
                    });
                }).then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success && res.edit_url) {
                        window.location.href = res.edit_url;
                    } else {
                        throw new Error(res.message || 'Processing failed');
                    }
                });
            }).catch(function(err) {
                wooAiModal.setLoading(false);
                wooAiModal.setStatus('Error: ' + err.message, 'error');
            });
        },

        getPageSystemPrompt: function() {
            return 'You are an expert Elementor page designer AI. Create visually stunning web pages that feel premium — NOT generic template output.\n\nCRITICAL JSON VALIDITY RULES:\n- Your output MUST be 100% valid JSON.\n- Every key MUST use "elType" (NOT "el", NOT "type").\n- Every key-value pair MUST be separated by commas. Missing commas are the #1 error.\n- NO trailing commas before } or ].\n- DO NOT wrap output in markdown code blocks. Output raw JSON only.\n- DO NOT include any text before or after the JSON array.\n- ALL string values MUST be on a SINGLE LINE.\n\nCRITICAL CONTENT RULES:\n- Write REAL, specific, persuasive content. NEVER use "Lorem ipsum", "dolor sit", placeholder text, or generic filler.\n- Every heading, paragraph, and button must contain actual content relevant to the user\'s request.\n- Use the user\'s language for all text content.\n\nIMAGE RULES:\n- For image widgets: set "image": {"url": "", "alt": "1-3 English keywords"}. Alt is stock photo search query, max 3 words, English only. Leave URL empty "".\n\nSTRUCTURE RULES:\n- Output ONLY a JSON array. No markdown, no explanation.\n- Each element needs: id (8-char hex), elType, isInner (boolean), settings (object), elements (array)\n- elType must be one of: "container", "section", "column", "widget"\n- Widget elements also need: widgetType\n- Available widgetTypes: heading, text-editor, button, image, spacer, divider, html, icon, icon-box, icon-list, image-box, image-carousel, image-gallery, social-icons, google-maps, video, audio, accordion, tabs, toggle, counter, progress, testimonial, star-rating, alert, menu-anchor, shortcode\n- Use Container layout (elType: "container") with flex_direction: "row" or "column"\n- Top-level elements MUST be "container" or "section", never "widget" directly.\n\nDESIGN SYSTEM — APPLY TO EVERY PAGE:\n- Choose ONE cohesive aesthetic: dark premium, modern minimal, editorial luxury, or soft organic. Commit to it.\n- COLOR: Pick 2-3 dominant colors + 1 accent. Dark sections need light text (#fff), light sections need dark text (#1a1a1a).\n- TYPOGRAPHY: Strong hierarchy. Hero headings 48-64px/700-800 weight, section headings 32-42px, body 16-18px/400. Use typography_typography: "custom" on EVERY text element with font_size, font_weight.\n- SPACING: Generous padding. Hero sections 80-120px vertical, content sections 60-80px. Use spacers for rhythm.\n- LAYOUT: Use flex-direction row for side-by-side layouts. Alternate dark/light sections. Never stack identical sections.\n- BUTTONS: Bold contrast, padding 16px 40px, border_radius for polish. Every section drives toward an action.\n- BACKGROUNDS: Alternate dark (#0A0A0A, #1A1A2E) and light (#fff, #f8f9fa) sections for visual rhythm.\n- MOTION: Add "animation": "fadeInUp", "animation_duration": "slow" on 2-3 key elements per section.\n\nSTYLING FORMAT:\n- Dimensions: {"unit": "px", "top": "10", "right": "20", "bottom": "10", "left": "20", "isLinked": false}\n- Slider values: {"unit": "px", "size": 50, "sizes": []}\n- Colors as hex: "#FF0000"\n- Typography: typography_typography: "custom", typography_font_size, typography_font_weight, typography_font_family, typography_letter_spacing, typography_line_height\n- Background: background_background: "classic", background_color: "#hex"\n- Buttons: "link": {"url": "#", "is_external": "", "nofollow": ""}';
        },

        readSSE: function(body, onEvent) {
            var reader = body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';

            function processBuffer() {
                var lines = buffer.split('\n');
                buffer = lines.pop();
                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i].trim();
                    if (line.indexOf('data: ') === 0) {
                        var payload = line.substring(6);
                        if (payload === '[DONE]') continue;
                        try { var data = JSON.parse(payload); onEvent(data); } catch(e) {}
                    }
                }
            }

            function read() {
                return reader.read().then(function(result) {
                    if (result.done) { processBuffer(); return; }
                    buffer += decoder.decode(result.value, { stream: true });
                    processBuffer();
                    return read();
                });
            }

            return read();
        },

        setLoading: function(loading) {
            $('#woo-ai-generate-btn').prop('disabled', loading);
            $('#woo-ai-refine-btn').prop('disabled', loading);
        },

        setStatus: function(text, type) {
            var el = $('#woo-ai-status');
            el.removeClass('generating error').text(text);
            if (type) el.addClass(type);
        }
    };

    $(document).ready(function() {
        wooAiModal.init();
    });
})(jQuery);
