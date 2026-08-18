(function($) {
    'use strict';

    $(document).on('click', '#fs-test-api-btn', function() {
        var $btn = $(this);
        var key  = $('#fs-api-key').val().trim();
        var $res = $('#fs-api-test-result');

        if (!key) { showResult('error', 'Please enter an API key first.'); return; }

        $btn.text(ipgeofsAdmin.strings.testing).prop('disabled', true);

        $.post(ipgeofsAdmin.ajax_url, {
            action: 'ipgeofs_test_api',
            nonce:  ipgeofsAdmin.nonce,
            api_key: key
        }, function(res) {
            $btn.text('Test key').prop('disabled', false);
            if (res.success) {
                showResult('success', res.data.message + ' (test IP located in: ' + res.data.country + ')');
            } else {
                showResult('error', res.data);
            }
        }).fail(function() {
            $btn.text('Test key').prop('disabled', false);
            showResult('error', 'Request failed. Check your connection.');
        });

        function showResult(type, msg) {
            $res.removeClass('fs-api-test-result--success fs-api-test-result--error')
                .addClass('fs-api-test-result--' + type)
                .text(msg).show();
        }
    });

    $(document).on('click', '.fs-toggle-key', function() {
        var target = $(this).data('target');
        var $input = $('#' + target);
        var isPass = $input.attr('type') === 'password';
        $input.attr('type', isPass ? 'text' : 'password');
        $(this).text(isPass ? '🙈' : '👁');
    });

    $(document).on('change', '#fs-test-mode-toggle', function() {
        var on = $(this).is(':checked');
        $('#fs-test-ip-row').css({ opacity: on ? '1' : '0.4', 'pointer-events': on ? 'auto' : 'none' });
    });

    $(document).on('click', '.fs-preset-btn', function() {
        $('#fs-test-ip').val($(this).data('ip'));
        $('.fs-preset-btn').removeClass('active');
        $(this).addClass('active');
    });

    window.fsLoadStats = function(high, medium, low) {
        var dCtx = document.getElementById('fs-donut-chart');
        if (dCtx) {
            new Chart(dCtx, {
                type: 'doughnut',
                data: {
                    labels: ['High risk', 'Medium risk', 'Low risk'],
                    datasets: [{
                        data: [high, medium, low],
                        backgroundColor: ['#f43f5e', '#f59e0b', '#22d3a0'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: true,
                    cutout: '68%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 16, font: { size: 12 } } }
                    }
                }
            });
        }

        var tCtx = document.getElementById('fs-trend-chart');
        if (tCtx) {
            loadTrendChart(tCtx, 30);
        }
    };

    function loadTrendChart(ctx, days) {
        $.post(ipgeofsAdmin.ajax_url, {
            action: 'ipgeofs_get_stats',
            nonce:  ipgeofsAdmin.nonce,
            days:   days
        }, function(res) {
            if (!res.success) return;

            var dayMap = {};
            res.data.rows.forEach(function(r) {
                if (!dayMap[r.day]) dayMap[r.day] = { low: 0, medium: 0, high: 0 };
                dayMap[r.day][r.risk_tier] = parseInt(r.cnt);
            });

            function generateLast30Days() {
                const days = [];
                const today = new Date();

                for (let i = 29; i >= 0; i--) {
                    const d = new Date();
                    d.setDate(today.getDate() - i);
                    days.push(d.toISOString().slice(0, 10));
                }

                return days;
            }

            var labels = Object.keys(dayMap).sort();
            var highData = labels.map(d => dayMap[d]?.high || 0);
            var medData  = labels.map(d => dayMap[d]?.medium || 0);
            var lowData  = labels.map(d => dayMap[d]?.low || 0);

            if (window._fsTrendChart) window._fsTrendChart.destroy();
            window._fsTrendChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'High',   data: highData,   backgroundColor: '#f43f5e', borderRadius: 3 },
                        { label: 'Medium', data: medData,    backgroundColor: '#f59e0b', borderRadius: 3 },
                        { label: 'Low',    data: lowData,    backgroundColor: '#22d3a0', borderRadius: 3 },
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 }, maxTicksLimit: 10 } },
                        y: { stacked: true, grid: { color: '#f0f0f5' }, ticks: { font: { size: 11 }, stepSize: 1 } }
                    },
                    plugins: { legend: { labels: { font: { size: 12 }, padding: 16 } } }
                }
            });
        });
    }

    $(document).on('change', '#fs-chart-days', function() {
        var tCtx = document.getElementById('fs-trend-chart');
        if (tCtx) loadTrendChart(tCtx, parseInt($(this).val()));
    });
    $(document).on('input', '.fs-weight-slider', function() {
        var val = $(this).val();
        var key = $(this).data('signal');
        $('[data-for="' + key + '"]').val(val);
    });


    function getActiveSliders() {
        return [...document.querySelectorAll('.fs-weight-slider')]
            .filter(s => s.closest('.fs-weight-row').style.display !== 'none');
    }

    function updateNumbers() {
        document.querySelectorAll('.fs-weight-slider').forEach(slider => {
            const num = document.querySelector(`[data-for="${slider.name}"]`);
            if (num) num.value = slider.value;
        });
    }


    var criticalActive = false;

    $(document).on('input', '.fs-weight-slider', function() {
        if (criticalActive) return; // sliders locked in critical mode
        var key = $(this).data('signal') || $(this).attr('name');
        $('[data-for="' + key + '"]').val($(this).val());
    });

    function syncVisibleSliders() {
        document.querySelectorAll('.fs-weight-row').forEach(function(row) {
            var slider     = row.querySelector('.fs-weight-slider');
            if (!slider) return;
            var toggleName = slider.dataset.toggle;
            var toggle     = document.querySelector('input[name="' + toggleName + '"]');
            row.style.display = (toggle && toggle.checked) ? '' : 'none';
        });
    }


    function getVisibleSliders() {
        return Array.from(document.querySelectorAll('.fs-weight-slider')).filter(function(s) {
            return s.closest('.fs-weight-row').style.display !== 'none';
        });
    }


    function checkCriticalMode() {
        var hasCritical = false;
        var criticalLabels = [];

        document.querySelectorAll('.fs-toggle-card').forEach(function(card) {
            var blockToggle    = card.querySelector('input[name^="ipgeofs_block_"]');
            var criticalToggle = card.querySelector('input[name^="ipgeofs_force_"]');
            if (blockToggle && criticalToggle && blockToggle.checked && criticalToggle.checked) {
                hasCritical = true;
                var label = card.querySelector('.fs-toggle-card__label');
                if (label) criticalLabels.push(label.textContent.trim());
            }
        });

        criticalActive = hasCritical;
        renderCriticalState(hasCritical, criticalLabels);
    }

    function renderCriticalState(active, labels) {
        var weightsCard = document.getElementById('fs-section-weights');
        var banner      = document.getElementById('fs-critical-banner');

        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'fs-critical-banner';
            banner.style.cssText = 'padding:12px 16px;background:#fff3cd;border:1px solid #f59e0b44;border-radius:8px;font-size:13px;color:#92400e;margin-bottom:16px;';
            if (weightsCard) weightsCard.parentNode.insertBefore(banner, weightsCard);
        }

        if (active) {
            banner.style.display = 'block';
            banner.innerHTML = '<strong>Critical override active</strong><br>The following signals will force the fraud score to 100 regardless of weights: <strong>' + labels.join(', ') + '</strong>. Weight sliders have no effect while any signal is marked critical.';
            if (weightsCard) weightsCard.style.opacity = '0.4';
            if (weightsCard) weightsCard.style.pointerEvents = 'none';
        } else {
            banner.style.display = 'none';
            if (weightsCard) weightsCard.style.opacity = '';
            if (weightsCard) weightsCard.style.pointerEvents = '';
        }
    }


    $(document).on('change', '.fs-toggle-card input[type="checkbox"]', function() {
        syncVisibleSliders();
        checkCriticalMode();
    });

    document.addEventListener('DOMContentLoaded', function() {
        syncVisibleSliders();
        checkCriticalMode();

        if (
            typeof ipgeofsAdmin !== 'undefined' &&
            ipgeofsAdmin.stats &&
            typeof window.fsLoadStats === 'function'
        ) {
            window.fsLoadStats(
                ipgeofsAdmin.stats.high,
                ipgeofsAdmin.stats.med,
                ipgeofsAdmin.stats.low
            );
        }
    });

})(jQuery);
