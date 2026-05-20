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

    function fetchAIFrontend(messages, maxTokens, onChunk) {
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
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';
            var fullContent = '';

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
                                    if (onChunk) onChunk(delta.content);
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
        });
    }

    window.WooAiChat = {
        isOpen: false,
        isLoading: false,
        streamingSource: null,

        init: function() {
            if (!wooElementorAI || !wooElementorAI.isConfigured) return;

            var toggleBtn = $('<button id="woo-ai-chat-toggle" type="button"><span class="dashicons dashicons-superhero"></span> AI Chat</button>');
            $('#elementor-panel-footer').before(toggleBtn);
            toggleBtn.on('click', WooAiChat.toggle);

            $('.woo-ai-quick-btn').on('click', function() {
                var action = $(this).data('action');
                if (action) {
                    $('#woo-ai-chat-input').val(action);
                    WooAiChat.send();
                }
            });

            $('#woo-ai-chat-input').on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    WooAiChat.send();
                }
            });
        },

        toggle: function() {
            if (WooAiChat.isOpen) { WooAiChat.close(); } else { WooAiChat.open(); }
        },

        open: function() {
            WooAiChat.isOpen = true;
            $('#woo-ai-chat-panel').show();
            WooAiChat.loadHistory();
        },

        close: function() {
            WooAiChat.isOpen = false;
            $('#woo-ai-chat-panel').hide();
            if (WooAiChat.streamingSource) {
                WooAiChat.streamingSource.close();
                WooAiChat.streamingSource = null;
            }
        },

        send: function() {
            var input = $('#woo-ai-chat-input');
            var message = input.val().trim();
            if (!message || WooAiChat.isLoading) return;

            input.val('');
            WooAiChat.addMessage('user', message);
            WooAiChat.setLoading(true);

            if (wooElementorAI.aiMode === 'frontend') {
                WooAiChat.sendFrontend(message);
            } else {
                WooAiChat.sendBackend(message);
            }
        },

        sendBackend: function(message) {
            var context = $('#woo-ai-chat-context').val();
            var targetId = context === 'element' ? WooElementorAIBridge.getSelectedElementId() : null;

            var params = $.param({
                post_id: wooElementorAI.postId,
                message: message,
                context: context,
                target_element_id: targetId || '',
                copywriting_method: $('#woo-ai-chat-method').val() || '',
                language: $('#woo-ai-chat-lang').val() || 'id'
            });

            WooAiChat.streamingSource = new EventSource(
                wooElementorAI.apiUrl + '/chat/stream?' + params + '&_wpnonce=' + wooElementorAI.nonce
            );

            var assistantMsgId = WooAiChat.addMessage('assistant', '');
            var fullContent = '';

            WooAiChat.streamingSource.onmessage = function(event) {
                if (event.data === '[DONE]') {
                    WooAiChat.streamingSource.close();
                    WooAiChat.streamingSource = null;
                    WooAiChat.setLoading(false);
                    WooAiChat.processStreamResponse(fullContent);
                    return;
                }
                try {
                    var data = JSON.parse(event.data);
                    if (data.error) {
                        WooAiChat.addMessage('system', 'Error: ' + data.error);
                        WooAiChat.setLoading(false);
                        return;
                    }
                    if (data.content) {
                        fullContent += data.content;
                        WooAiChat.updateMessage(assistantMsgId, fullContent);
                    }
                } catch(e) {}
            };

            WooAiChat.streamingSource.onerror = function() {
                WooAiChat.streamingSource.close();
                WooAiChat.streamingSource = null;
                if (!fullContent) {
                    WooAiChat.sendBlocking(message, context, targetId);
                } else {
                    WooAiChat.setLoading(false);
                }
            };
        },

        sendFrontend: function(message) {
            var assistantMsgId = WooAiChat.addMessage('assistant', '');
            var context = $('#woo-ai-chat-context').val();
            var targetId = context === 'element' ? WooElementorAIBridge.getSelectedElementId() : null;
            var method = $('#woo-ai-chat-method').val() || '';
            var language = $('#woo-ai-chat-lang').val() || 'id';

            getAiConfig().then(function() {
                var langInstruction = language === 'en' ? 'Write ALL text content in English.' :
                    language === 'mixed' ? 'Write content in a mix of Indonesian and English.' :
                    'Write ALL text content in Indonesian (Bahasa Indonesia).';
                var methodInstruction = method ? 'Copywriting method: ' + method + '.' : '';

                var systemMsg = 'You are a senior Elementor designer AI inside the page editor. Help users build and edit web pages with strong visual design quality. Every change should feel intentionally crafted.\n\n' + langInstruction + '\n' + methodInstruction + '\n\nCRITICAL CONTENT RULES:\n- Write REAL, specific, persuasive content. NEVER use "Lorem ipsum" or placeholder text.\n\nDESIGN AWARENESS — apply to every element you create or modify:\n- Maintain visual consistency with the existing page. Match colors, spacing, typography style already in use.\n- Strong visual hierarchy: large bold headings (36-56px), medium subheadings (20-24px), readable body (14-16px).\n- Generous spacing is premium: use 60-100px section padding, 20-40px between elements.\n- Buttons: contrasting background, padding 14px 36px, border_radius for softness.\n- Alternate section backgrounds for visual rhythm — dark/light sections.\n- Dark backgrounds → light text (#fff). Light backgrounds → dark text (#1a1a1a).\n\nINSTRUCTIONS:\n- When the user asks to create or modify elements, respond with a JSON action block wrapped in ```elementor-actions ... ```\n- Include a human-readable explanation before the action block\n- For image widgets: set "image": {"url": "", "alt": "1-3 English keywords"}. Alt is stock photo search query, max 3 words, English only. Example: "gold trophy", "modern office"';

                var messages = [{ role: 'system', content: systemMsg }, { role: 'user', content: message }];

                return fetchAIFrontend(messages, 64000, function(content) {
                    var el = $('#' + assistantMsgId);
                    if (el.length) {
                        var current = el.text() || '';
                        el.text(current + content);
                        var container = $('#woo-ai-chat-messages');
                        container.scrollTop(container[0].scrollHeight);
                    }
                });
            }).then(function(fullContent) {
                if (!fullContent) throw new Error('Empty response');
                return fetch(wooElementorAI.apiUrl + '/chat/process', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': wooElementorAI.nonce
                    },
                    body: JSON.stringify({
                        post_id: wooElementorAI.postId,
                        message: message,
                        content: fullContent
                    })
                }).then(function(r) { return r.json(); });
            }).then(function(res) {
                if (res.success && res.actions && res.actions.length) {
                    WooElementorAIBridge.applyActions(res.actions);
                    WooAiChat.addMessage('system', 'Changes applied to editor');
                }
            }).catch(function(err) {
                if (err.message !== 'Empty response') {
                    WooAiChat.addMessage('system', 'Error: ' + err.message);
                }
            }).finally(function() {
                WooAiChat.setLoading(false);
            });
        },

        sendBlocking: function(message, context, targetId) {
            var assistantMsgId = WooAiChat.addMessage('assistant', '');
            var fullContent = '';

            fetch(wooElementorAI.apiUrl + '/chat/stream?' + $.param({
                post_id: wooElementorAI.postId,
                message: message,
                context: context,
                target_element_id: targetId || '',
                copywriting_method: $('#woo-ai-chat-method').val() || '',
                language: $('#woo-ai-chat-lang').val() || 'id',
                _wpnonce: wooElementorAI.nonce
            }), {
                method: 'GET',
                headers: { 'X-WP-Nonce': wooElementorAI.nonce }
            }).then(function(response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                var reader = response.body.getReader();
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
                                if (data.error) { WooAiChat.addMessage('system', 'Error: ' + data.error); return; }
                                if (data.content) {
                                    fullContent += data.content;
                                    WooAiChat.updateMessage(assistantMsgId, fullContent);
                                }
                            } catch(e) {}
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
            }).then(function() {
                if (fullContent) { WooAiChat.processStreamResponse(fullContent); }
            }).catch(function() {
                WooAiChat.addMessage('system', 'Network error. Please try again.');
            }).finally(function() { WooAiChat.setLoading(false); });
        },

        processStreamResponse: function(content) {
            var actionRegex = /```elementor-actions\s*([\s\S]*?)```/g;
            var match;
            var actions = [];
            while ((match = actionRegex.exec(content)) !== null) {
                try {
                    var parsed = JSON.parse(match[1].trim());
                    if (parsed.type) { actions.push(parsed); }
                    else if (Array.isArray(parsed)) { actions = actions.concat(parsed); }
                } catch(e) {}
            }
            if (actions.length) {
                WooElementorAIBridge.applyActions(actions);
                WooAiChat.addMessage('system', 'Changes applied to editor');
            }
        },

        loadHistory: function() {
            $.ajax({
                url: wooElementorAI.apiUrl + '/chat/history',
                method: 'GET',
                data: { post_id: wooElementorAI.postId },
                headers: { 'X-WP-Nonce': wooElementorAI.nonce },
                success: function(resp) {
                    if (resp.success && resp.data.length) {
                        $('#woo-ai-chat-messages').empty();
                        resp.data.forEach(function(msg) {
                            WooAiChat.addMessage(msg.role, msg.content);
                        });
                    }
                }
            });
        },

        clearChat: function() {
            $.ajax({
                url: wooElementorAI.apiUrl + '/chat/clear',
                method: 'POST',
                contentType: 'application/json',
                headers: { 'X-WP-Nonce': wooElementorAI.nonce },
                data: JSON.stringify({ post_id: wooElementorAI.postId }),
                success: function() {
                    $('#woo-ai-chat-messages').empty();
                    WooAiChat.addMessage('system', 'Chat history cleared');
                }
            });
        },

        addMessage: function(role, content) {
            var id = 'woo-ai-msg-' + Date.now() + '-' + Math.random().toString(36).substr(2,5);
            var cssClass = 'woo-ai-msg woo-ai-msg-' + role;
            var el = $('<div id="' + id + '" class="' + cssClass + '"></div>');
            el.text(content);
            var container = $('#woo-ai-chat-messages');
            container.append(el);
            container.scrollTop(container[0].scrollHeight);
            return id;
        },

        updateMessage: function(msgId, content) {
            var el = $('#' + msgId);
            if (el.length) {
                el.text(content);
                var container = $('#woo-ai-chat-messages');
                container.scrollTop(container[0].scrollHeight);
            }
        },

        setLoading: function(loading) {
            WooAiChat.isLoading = loading;
            $('.woo-ai-chat-send').prop('disabled', loading);
            if (loading) {
                var container = $('#woo-ai-chat-messages');
                var typing = $('<div class="woo-ai-typing-indicator" id="woo-ai-typing"><span></span><span></span><span></span></div>');
                container.append(typing);
                container.scrollTop(container[0].scrollHeight);
            } else {
                $('#woo-ai-typing').remove();
            }
        }
    };

    $(window).on('elementor:init', function() {
        setTimeout(function() {
            WooAiChat.init();
        }, 1000);
    });
})(jQuery);
