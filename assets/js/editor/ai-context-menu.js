(function($) {
    'use strict';

    var aiConfig = null;

    function getAiConfig() {
        if (aiConfig) return Promise.resolve(aiConfig);
        return fetch(wooElementorAI.apiUrl + '/ai-config', {
            headers: { 'X-WP-Nonce': wooElementorAI.nonce }
        }).then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) { aiConfig = res.data; }
            return aiConfig;
        });
    }

    function fetchAIFrontend(messages, maxTokens) {
        var url = aiConfig.base_url + 'chat/completions';
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + aiConfig.api_key
            },
            body: JSON.stringify({
                model: aiConfig.model,
                messages: messages,
                max_tokens: maxTokens,
                temperature: 0.7,
                stream: false
            })
        }).then(function(response) {
            if (!response.ok) throw new Error('AI HTTP ' + response.status);
            return response.json();
        }).then(function(data) {
            if (!data.choices || !data.choices[0]) throw new Error('No choices');
            return data.choices[0].message.content || '';
        });
    }

    $(window).on('elementor:init', function() {
        setTimeout(function() {
            if (!wooElementorAI || !wooElementorAI.isConfigured) return;

            elementor.hooks.addFilter('elements/context-menu/groups', function(groups, element) {
                var elementId = element.model.get('id');

                groups.push({
                    name: 'woo_ai',
                    actions: [
                        {
                            name: 'woo_ai_edit',
                            title: 'Edit with AI',
                            callback: function() { WooAiContextMenu.editWithAi(elementId); }
                        },
                        {
                            name: 'woo_ai_variations',
                            title: 'Generate Variations',
                            callback: function() { WooAiContextMenu.generateVariations(elementId); }
                        },
                        {
                            name: 'woo_ai_improve',
                            title: 'Improve Layout',
                            callback: function() { WooAiContextMenu.improveLayout(elementId); }
                        }
                    ]
                });

                return groups;
            });
        }, 2000);
    });

    window.WooAiContextMenu = {
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

        generateElementStream: function(elementId, prompt, elementContext) {
            if (wooElementorAI.aiMode === 'frontend') {
                return WooAiContextMenu.generateElementFrontend(elementId, prompt, elementContext);
            }

            return fetch(wooElementorAI.apiUrl + '/generate/element/stream', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wooElementorAI.nonce
                },
                body: JSON.stringify({
                    post_id: wooElementorAI.postId,
                    element_id: elementId,
                    prompt: prompt,
                    element_context: elementContext
                })
            }).then(function(response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return WooAiContextMenu.readSSE(response.body, function(event) {
                    if (event.type === 'complete' && event.element) {
                        WooElementorAIBridge.applyActions([{
                            type: 'element_replace',
                            element_id: elementId,
                            element: event.element
                        }]);
                    } else if (event.type === 'error') {
                        alert(event.message || 'AI generation failed');
                    }
                });
            }).catch(function(err) {
                alert('Network error: ' + err.message);
            });
        },

        generateElementFrontend: function(elementId, prompt, elementContext) {
            getAiConfig().then(function() {
                var elType = elementContext.elType || 'unknown';
                var widgetType = elementContext.widgetType || '';
                var typeLabel = widgetType || elType;
                var currentSettings = JSON.stringify(elementContext.current_settings || {}, null, 2);

                var systemPrompt = 'You are an expert Elementor element designer AI. Apply strong visual design to every element you create.\n\nCurrent element settings:\n' + currentSettings + '\n\nCRITICAL JSON VALIDITY RULES:\n- Output MUST be 100% valid JSON.\n- Every key MUST use "elType" (NOT "el").\n- NO trailing commas before } or ].\n- DO NOT wrap output in markdown code blocks.\n\nDESIGN RULES:\n- Maintain visual consistency with surrounding page elements.\n- Strong typography: set typography_typography: "custom" with font_size, font_weight on ALL text.\n- Headings: 32-56px bold. Body: 16-18px regular.\n- Dark backgrounds need light text (#fff). Light backgrounds need dark text (#1a1a1a).\n- Buttons: contrasting color, padding 14px 36px, border_radius.\n\nRULES:\n- Output ONLY the updated element as a valid JSON object. No markdown, no explanation.\n- For image widgets: set "image": {"url": "", "alt": "1-3 English keywords"}. Alt is stock photo search query, max 3 words, English only. Example: "gold trophy". Leave URL empty "".\n\nCRITICAL CONTENT RULES:\n- Write REAL, specific content. NEVER use "Lorem ipsum" or placeholder text.';

                return fetchAIFrontend([
                    { role: 'system', content: systemPrompt },
                    { role: 'user', content: prompt }
                ], 64000);
            }).then(function(fullContent) {
                if (!fullContent) throw new Error('Empty response');
                return fetch(wooElementorAI.apiUrl + '/generate/element/process', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': wooElementorAI.nonce
                    },
                    body: JSON.stringify({
                        post_id: wooElementorAI.postId,
                        content: fullContent
                    })
                }).then(function(r) { return r.json(); });
            }).then(function(res) {
                if (res.success && res.element) {
                    WooElementorAIBridge.applyActions([{
                        type: 'element_replace',
                        element_id: elementId,
                        element: res.element
                    }]);
                } else {
                    alert(res.message || 'Processing failed');
                }
            }).catch(function(err) {
                alert('Error: ' + err.message);
            });
        },

        editWithAi: function(elementId) {
            var prompt = prompt('Describe what you want to change:');
            if (!prompt) return;

            var elementContext = WooElementorAIBridge.getElementData(elementId);
            WooAiContextMenu.generateElementStream(elementId, prompt, elementContext);
        },

        generateVariations: function(elementId) {
            var elementContext = WooElementorAIBridge.getElementData(elementId);
            var prompt = 'Generate a different variation of this element with new content, colors, and styling while keeping the same layout structure.';
            WooAiContextMenu.generateElementStream(elementId, prompt, elementContext);
        },

        improveLayout: function(elementId) {
            var elementContext = WooElementorAIBridge.getElementData(elementId);
            var prompt = 'Improve the layout, spacing, and visual balance of this element. Make it look more professional and polished.';
            WooAiContextMenu.generateElementStream(elementId, prompt, elementContext);
        }
    };
})(jQuery);
