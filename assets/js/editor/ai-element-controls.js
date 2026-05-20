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

    function readSSE(body, onEvent) {
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
                    try {
                        var data = JSON.parse(payload);
                        onEvent(data);
                    } catch(e) {}
                }
            }
        }

        function read() {
            return reader.read().then(function(result) {
                if (result.done) {
                    processBuffer();
                    return;
                }
                buffer += decoder.decode(result.value, { stream: true });
                processBuffer();
                return read();
            });
        }

        return read();
    }

    $(window).on('elementor:init', function() {
        setTimeout(function() {

            elementor.channels.editor.on('woo:ai:refine', function(view) {
                var elementId = view.model.get('id');
                var promptField = view.model.get('settings').get('woo_ai_prompt');
                if (!promptField) return;

                var btn = view.$el.find('[data-event="woo:ai:refine"] button');
                if (btn.length) btn.prop('disabled', true).text('Refining...');

                if (wooElementorAI.aiMode === 'frontend') {
                    getAiConfig().then(function() {
                        var systemPrompt = 'You are a prompt refinement assistant for an AI Elementor element designer. Improve the given prompt to be more specific, detailed, and effective. Include: desired visual style (dark/light, minimalist/bold), specific layout changes, content text, color scheme, typography preferences, spacing, and any specific element behavior. Output ONLY the refined prompt text, nothing else.';

                        return fetchAIFrontend([
                            { role: 'system', content: systemPrompt },
                            { role: 'user', content: promptField }
                        ], 4000);
                    }).then(function(refinedPrompt) {
                        if (!refinedPrompt) throw new Error('Empty response');

                        return fetch(wooElementorAI.apiUrl + '/refine/process', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': wooElementorAI.nonce
                            },
                            body: JSON.stringify({ content: refinedPrompt })
                        }).then(function(r) { return r.json(); });
                    }).then(function(res) {
                        if (res.success && res.refined_prompt) {
                            $e.run('document/elements/settings', {
                                container: elementor.getContainer(elementId),
                                settings: { woo_ai_prompt: res.refined_prompt }
                            });
                        } else {
                            alert(res.message || 'Refine failed');
                        }
                    }).catch(function(err) {
                        alert('Error: ' + err.message);
                    }).finally(function() {
                        if (btn.length) btn.prop('disabled', false).text('Refine Prompt');
                    });

                } else {
                    fetch(wooElementorAI.apiUrl + '/refine/stream', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': wooElementorAI.nonce
                        },
                        body: JSON.stringify({ prompt: promptField, context: 'element' })
                    }).then(function(response) {
                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status);
                        }
                        return readSSE(response.body, function(event) {
                            if (event.type === 'complete' && event.refined_prompt) {
                                $e.run('document/elements/settings', {
                                    container: elementor.getContainer(elementId),
                                    settings: { woo_ai_prompt: event.refined_prompt }
                                });
                            } else if (event.type === 'error') {
                                alert(event.message || 'Refine failed');
                            }
                        });
                    }).catch(function(err) {
                        alert('Network error: ' + err.message);
                    }).finally(function() {
                        if (btn.length) btn.prop('disabled', false).text('Refine Prompt');
                    });
                }
            });

            elementor.channels.editor.on('woo:ai:generate', function(view) {
                var elementId = view.model.get('id');
                var promptField = view.model.get('settings').get('woo_ai_prompt');
                if (!promptField) return;

                var btn = view.$el.find('[data-event="woo:ai:generate"] button');
                if (btn.length) btn.prop('disabled', true).text('Generating...');

                var elementContext = WooElementorAIBridge.getElementData(elementId);

                if (wooElementorAI.aiMode === 'frontend') {
                    getAiConfig().then(function() {
                        var elType = elementContext.elType || 'unknown';
                        var widgetType = elementContext.widgetType || '';
                        var typeLabel = widgetType || elType;
                        var currentSettings = JSON.stringify(elementContext.current_settings || {}, null, 2);

                        var systemPrompt = 'You are an expert Elementor element designer AI. Apply strong visual design to every element you create.\n\nCurrent element settings:\n' + currentSettings + '\n\nCRITICAL JSON VALIDITY RULES:\n- Output MUST be 100% valid JSON.\n- Every key MUST use "elType" (NOT "el").\n- NO trailing commas before } or ].\n- DO NOT wrap output in markdown code blocks.\n\nDESIGN RULES — apply premium styling:\n- Maintain visual consistency with surrounding page elements.\n- Use strong typography: set typography_typography: "custom" with font_size, font_weight on ALL text.\n- Headings: 32-56px bold. Body: 16-18px regular.\n- Dark backgrounds need light text (#fff). Light backgrounds need dark text (#1a1a1a).\n- Buttons: contrasting color, generous padding (14px 36px), border_radius for softness.\n- Use intentional spacing — never cramped. Padding minimum 40px on containers.\n\nRULES:\n- Output ONLY the updated element as a valid JSON object. No markdown, no explanation.\n- For image widgets: set "image": {"url": "", "alt": "1-3 English keywords"}. Alt is stock photo search query, max 3 words, English only. Example: "gold trophy". Leave URL empty "".\n- Use this format for dimensions: {"unit": "px", "top": "10", "right": "20", "bottom": "10", "left": "20", "isLinked": false}\n- Colors as hex: "#FF0000"\n- Typography: typography_typography: "custom", typography_font_size, typography_font_weight, typography_line_height\n\nCRITICAL CONTENT RULES:\n- Write REAL, specific content. NEVER use "Lorem ipsum" or placeholder text.';

                        return fetchAIFrontend([
                            { role: 'system', content: systemPrompt },
                            { role: 'user', content: promptField }
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
                            alert(res.message || 'Generation failed');
                        }
                    }).catch(function(err) {
                        alert('Error: ' + err.message);
                    }).finally(function() {
                        if (btn.length) btn.prop('disabled', false).text('Generate');
                    });

                } else {
                    fetch(wooElementorAI.apiUrl + '/generate/element/stream', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': wooElementorAI.nonce
                        },
                        body: JSON.stringify({
                            post_id: wooElementorAI.postId,
                            element_id: elementId,
                            prompt: promptField,
                            element_context: elementContext
                        })
                    }).then(function(response) {
                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status);
                        }
                        return readSSE(response.body, function(event) {
                            if (event.type === 'complete' && event.element) {
                                WooElementorAIBridge.applyActions([{
                                    type: 'element_replace',
                                    element_id: elementId,
                                    element: event.element
                                }]);
                            } else if (event.type === 'error') {
                                alert(event.message || 'Generation failed');
                            }
                        });
                    }).catch(function(err) {
                        alert('Network error: ' + err.message);
                    }).finally(function() {
                        if (btn.length) btn.prop('disabled', false).text('Generate');
                    });
                }
            });

        }, 1500);
    });
})(jQuery);
