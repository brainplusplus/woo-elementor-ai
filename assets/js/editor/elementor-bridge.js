(function($) {
    'use strict';

    window.WooElementorAIBridge = {
        getPageData: function() {
            var elements = elementor.elements.toJSON();
            return this.condenseElements(elements);
        },

        getElementData: function(elementId) {
            var container = elementor.getContainer(elementId);
            if (!container) return null;
            return {
                id: elementId,
                elType: container.settings.get('elType') || container.model.get('elType'),
                widgetType: container.model.get('widgetType') || null,
                current_settings: container.settings.toJSON()
            };
        },

        condenseElements: function(elements) {
            return elements.map(function(el) {
                var item = { id: el.id, elType: el.elType };
                if (el.widgetType) item.widgetType = el.widgetType;
                if (el.elements && el.elements.length) {
                    item.elements = this.condenseElements(el.elements);
                }
                return item;
            }.bind(this));
        },

        applyActions: function(actions) {
            if (!actions || !actions.length) return;
            actions.forEach(function(action) {
                try {
                    switch (action.type) {
                        case 'element_create':
                            var parentId = action.parent_id;
                            var container = parentId ? elementor.getContainer(parentId) : null;
                            if (container) {
                                $e.run('document/elements/create', {
                                    container: container,
                                    model: action.element
                                });
                            } else {
                                $e.run('document/elements/create', {
                                    container: elementor.getContainer('document'),
                                    model: action.element
                                });
                            }
                            break;
                        case 'element_update':
                            var el = elementor.getContainer(action.element_id);
                            if (el) {
                                $e.run('document/elements/settings', {
                                    container: el,
                                    settings: action.settings
                                });
                            }
                            break;
                        case 'element_delete':
                            var del = elementor.getContainer(action.element_id);
                            if (del) {
                                $e.run('document/elements/delete', {
                                    container: del
                                });
                            }
                            break;
                        case 'element_replace':
                            var target = elementor.getContainer(action.element_id);
                            if (target && action.element) {
                                $e.run('document/elements/delete', { container: target });
                                var parent = target.parent || elementor.getContainer('document');
                                $e.run('document/elements/create', {
                                    container: parent,
                                    model: action.element
                                });
                            }
                            break;
                    }
                } catch(e) {
                    console.error('Woo AI action failed:', action.type, e);
                }
            });
            $e.run('document/save/save');
        },

        getSelectedElementId: function() {
            var preview = elementor.$previewContents;
            if (!preview) return null;
            var selected = preview.find('.elementor-element.elementor-element-editable');
            return selected.length ? selected.attr('data-id') : null;
        }
    };
})(jQuery);
