<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$rest_url = rest_url( 'woo-elementor-ai/v1/logs' );
$clear_url = rest_url( 'woo-elementor-ai/v1/logs/clear' );
$nonce = wp_create_nonce( 'wp_rest' );
$ajax_nonce = wp_create_nonce( 'woo_elementor_ai_logs_nonce' );
?>
<div class="wrap">
    <h1>
        <span class="dashicons dashicons-list-view" style="font-size:24px;margin-right:6px;"></span>
        <?php esc_html_e( 'Woo Elementor AI — Activity Logs', 'woo-elementor-ai' ); ?>
    </h1>

    <div class="woo-ai-logs-filters" style="display:flex;gap:12px;align-items:center;margin:16px 0;flex-wrap:wrap;">
        <select id="woo-ai-filter-channel" style="min-width:140px;">
            <option value=""><?php esc_html_e( 'All Channels', 'woo-elementor-ai' ); ?></option>
            <option value="ai_request"><?php esc_html_e( 'AI Request', 'woo-elementor-ai' ); ?></option>
            <option value="ai_response"><?php esc_html_e( 'AI Response', 'woo-elementor-ai' ); ?></option>
            <option value="json_parse"><?php esc_html_e( 'JSON Parse', 'woo-elementor-ai' ); ?></option>
            <option value="image_resolve"><?php esc_html_e( 'Image Resolve', 'woo-elementor-ai' ); ?></option>
            <option value="api_endpoint"><?php esc_html_e( 'API Endpoint', 'woo-elementor-ai' ); ?></option>
        </select>

        <select id="woo-ai-filter-level" style="min-width:120px;">
            <option value=""><?php esc_html_e( 'All Levels', 'woo-elementor-ai' ); ?></option>
            <option value="success"><?php esc_html_e( 'Success', 'woo-elementor-ai' ); ?></option>
            <option value="info"><?php esc_html_e( 'Info', 'woo-elementor-ai' ); ?></option>
            <option value="warning"><?php esc_html_e( 'Warning', 'woo-elementor-ai' ); ?></option>
            <option value="error"><?php esc_html_e( 'Error', 'woo-elementor-ai' ); ?></option>
        </select>

        <input type="text" id="woo-ai-filter-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search messages...', 'woo-elementor-ai' ); ?>" style="min-width:200px;">

        <button type="button" class="button" id="woo-ai-logs-filter-btn">
            <span class="dashicons dashicons-filter" style="vertical-align:middle;"></span>
            <?php esc_html_e( 'Filter', 'woo-elementor-ai' ); ?>
        </button>

        <button type="button" class="button button-link-delete" id="woo-ai-logs-clear-btn">
            <span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
            <?php esc_html_e( 'Clear All', 'woo-elementor-ai' ); ?>
        </button>

        <span id="woo-ai-logs-status" style="margin-left:auto;color:#666;"></span>
    </div>

    <table class="wp-list-table widefat fixed striped" id="woo-ai-logs-table">
        <thead>
            <tr>
                <th style="width:50px;">#</th>
                <th style="width:160px;"><?php esc_html_e( 'Time', 'woo-elementor-ai' ); ?></th>
                <th style="width:120px;"><?php esc_html_e( 'Channel', 'woo-elementor-ai' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( 'Level', 'woo-elementor-ai' ); ?></th>
                <th><?php esc_html_e( 'Message', 'woo-elementor-ai' ); ?></th>
                <th style="width:70px;"><?php esc_html_e( 'Post', 'woo-elementor-ai' ); ?></th>
                <th style="width:70px;"><?php esc_html_e( 'Action', 'woo-elementor-ai' ); ?></th>
            </tr>
        </thead>
        <tbody id="woo-ai-logs-body">
            <tr><td colspan="7" style="text-align:center;color:#999;"><?php esc_html_e( 'Loading...', 'woo-elementor-ai' ); ?></td></tr>
        </tbody>
    </table>

    <div class="woo-ai-logs-pagination" id="woo-ai-logs-pagination" style="display:flex;gap:12px;align-items:center;margin-top:12px;">
        <button type="button" class="button" id="woo-ai-logs-prev" disabled><?php esc_html_e( '&larr; Previous', 'woo-elementor-ai' ); ?></button>
        <span id="woo-ai-logs-page-info"></span>
        <button type="button" class="button" id="woo-ai-logs-next" disabled><?php esc_html_e( 'Next &rarr;', 'woo-elementor-ai' ); ?></button>
    </div>
</div>

<div id="woo-ai-log-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;width:80%;max-width:900px;max-height:80vh;display:flex;flex-direction:column;">
        <div style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="woo-ai-modal-title" style="margin:0;"></h3>
            <button type="button" class="button" onclick="wooAiCloseModal()">&times; Close</button>
        </div>
        <div style="padding:20px;overflow:auto;flex:1;">
            <table style="width:100%;margin-bottom:16px;">
                <tr><td style="font-weight:bold;padding:4px 8px;">Channel:</td><td id="woo-ai-modal-channel"></td></tr>
                <tr><td style="font-weight:bold;padding:4px 8px;">Level:</td><td id="woo-ai-modal-level"></td></tr>
                <tr><td style="font-weight:bold;padding:4px 8px;">Time:</td><td id="woo-ai-modal-time"></td></tr>
                <tr><td style="font-weight:bold;padding:4px 8px;">Message:</td><td id="woo-ai-modal-message"></td></tr>
                <tr><td style="font-weight:bold;padding:4px 8px;">Post ID:</td><td id="woo-ai-modal-postid"></td></tr>
            </table>
            <h4>Context:</h4>
            <pre id="woo-ai-modal-context" style="background:#f5f5f5;padding:16px;border-radius:4px;overflow:auto;max-height:400px;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-all;"></pre>
        </div>
    </div>
</div>

<script>
(function() {
    var restUrl = '<?php echo esc_js( $rest_url ); ?>';
    var clearUrl = '<?php echo esc_js( $clear_url ); ?>';
    var nonce = '<?php echo esc_js( $nonce ); ?>';
    var currentPage = 1;
    var totalPages = 1;

    var channelLabels = {
        'ai_request': 'AI Request',
        'ai_response': 'AI Response',
        'json_parse': 'JSON Parse',
        'image_resolve': 'Image Resolve',
        'api_endpoint': 'API Endpoint'
    };

    var levelColors = {
        'success': { bg: '#d4edda', color: '#155724' },
        'info': { bg: '#cce5ff', color: '#004085' },
        'warning': { bg: '#fff3cd', color: '#856404' },
        'error': { bg: '#f8d7da', color: '#721c24' }
    };

    function getFilters() {
        return {
            channel: document.getElementById('woo-ai-filter-channel').value,
            level: document.getElementById('woo-ai-filter-level').value,
            search: document.getElementById('woo-ai-filter-search').value,
            page: currentPage,
            per_page: 50
        };
    }

    function buildUrl(params) {
        var parts = [];
        for (var k in params) {
            if (params[k]) parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
        }
        return restUrl + (parts.length ? '?' + parts.join('&') : '');
    }

    function loadLogs() {
        var statusEl = document.getElementById('woo-ai-logs-status');
        statusEl.textContent = 'Loading...';

        fetch(buildUrl(getFilters()), {
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) { statusEl.textContent = 'Error loading logs'; return; }
            var data = res.data;
            totalPages = data.pages;
            currentPage = data.logs.length > 0 ? currentPage : 1;
            renderLogs(data.logs);
            renderPagination(data.total, data.pages);
            statusEl.textContent = data.total + ' log entries';
        })
        .catch(function() { statusEl.textContent = 'Network error'; });
    }

    function renderLogs(logs) {
        var body = document.getElementById('woo-ai-logs-body');
        if (!logs || logs.length === 0) {
            body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#999;">No logs found.</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < logs.length; i++) {
            var log = logs[i];
            var lc = levelColors[log.level] || levelColors.info;
            var ch = channelLabels[log.channel] || log.channel;
            var badge = '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:' + lc.bg + ';color:' + lc.color + ';">' + escHtml(log.level) + '</span>';
            var chBadge = '<span style="font-size:12px;">' + escHtml(ch) + '</span>';

            html += '<tr>';
            html += '<td>' + escHtml(log.id) + '</td>';
            html += '<td style="font-size:12px;color:#666;">' + escHtml(log.created_at) + '</td>';
            html += '<td>' + chBadge + '</td>';
            html += '<td>' + badge + '</td>';
            html += '<td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escHtml(log.message) + '</td>';
            html += '<td>' + (log.post_id ? '<a href="post.php?post=' + log.post_id + '&action=edit" target="_blank">' + log.post_id + '</a>' : '—') + '</td>';
            html += '<td><button type="button" class="button button-small" onclick="wooAiViewLog(' + log.id + ')">View</button></td>';
            html += '</tr>';
        }
        body.innerHTML = html;

        window._wooAiLogsCache = logs;
    }

    function renderPagination(total, pages) {
        document.getElementById('woo-ai-logs-page-info').textContent = 'Page ' + currentPage + ' of ' + pages;
        document.getElementById('woo-ai-logs-prev').disabled = currentPage <= 1;
        document.getElementById('woo-ai-logs-next').disabled = currentPage >= pages;
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    document.getElementById('woo-ai-logs-filter-btn').addEventListener('click', function() {
        currentPage = 1;
        loadLogs();
    });

    document.getElementById('woo-ai-filter-search').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { currentPage = 1; loadLogs(); }
    });

    document.getElementById('woo-ai-logs-prev').addEventListener('click', function() {
        if (currentPage > 1) { currentPage--; loadLogs(); }
    });

    document.getElementById('woo-ai-logs-next').addEventListener('click', function() {
        if (currentPage < totalPages) { currentPage++; loadLogs(); }
    });

    document.getElementById('woo-ai-logs-clear-btn').addEventListener('click', function() {
        if (!confirm('Clear all logs? This cannot be undone.')) return;
        fetch(clearUrl, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
        })
        .then(function(r) { return r.json(); })
        .then(function() {
            currentPage = 1;
            loadLogs();
        });
    });

    window.wooAiViewLog = function(id) {
        var logs = window._wooAiLogsCache || [];
        var log = null;
        for (var i = 0; i < logs.length; i++) {
            if (parseInt(logs[i].id) === id) { log = logs[i]; break; }
        }
        if (!log) return;

        document.getElementById('woo-ai-modal-title').textContent = 'Log #' + log.id;
        document.getElementById('woo-ai-modal-channel').textContent = channelLabels[log.channel] || log.channel;
        document.getElementById('woo-ai-modal-time').textContent = log.created_at;
        document.getElementById('woo-ai-modal-message').textContent = log.message;
        document.getElementById('woo-ai-modal-postid').textContent = log.post_id || '—';

        var lc = levelColors[log.level] || levelColors.info;
        var lvl = document.getElementById('woo-ai-modal-level');
        lvl.innerHTML = '<span style="display:inline-block;padding:2px 10px;border-radius:3px;font-weight:600;background:' + lc.bg + ';color:' + lc.color + ';">' + escHtml(log.level) + '</span>';

        var ctx = log.context ? log.context : 'No context data';
        try {
            var parsed = JSON.parse(ctx);
            ctx = JSON.stringify(parsed, null, 2);
        } catch(e) {}
        document.getElementById('woo-ai-modal-context').textContent = ctx;

        document.getElementById('woo-ai-log-modal').style.display = 'block';
    };

    window.wooAiCloseModal = function() {
        document.getElementById('woo-ai-log-modal').style.display = 'none';
    };

    document.getElementById('woo-ai-log-modal').addEventListener('click', function(e) {
        if (e.target === this) wooAiCloseModal();
    });

    loadLogs();
})();
</script>
