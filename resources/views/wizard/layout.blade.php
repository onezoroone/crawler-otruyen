@extends(backpack_view('blank'))

@section('header')
    <section class="container-fluid">
        <h2>@yield('wizard_title')</h2>
    </section>
@endsection

@section('content')
    {{-- Khung wizard dùng chung mọi plugin cào: id/class crawl-wizard-* --}}
    <div id="crawl-wizard-loading" class="position-fixed top-0 start-0 w-100 h-100 crawl-wizard-loading-overlay py-3"
         style="display: none; z-index: 1050; overflow-y: auto;"
         role="alert" aria-live="polite" aria-busy="false" data-loading-open="0">
        <div class="text-center p-4 rounded shadow-lg bg-white border crawl-wizard-loading-card mx-2">
            <div class="spinner-border text-primary mb-3 crawl-wizard-spinner" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading…</span>
            </div>
            <div class="fw-medium" id="crawl-wizard-loading-text">Đang xử lý…</div>
            <button type="button" id="btn-crawl-wizard-stop" class="btn btn-outline-danger btn-sm mt-3 d-none" aria-label="Dừng cào">Dừng cào</button>
        </div>
        <div id="crawl-wizard-stream-log-wrap" class="d-none w-100 px-2 mt-2" style="max-width: 52rem;">
            <div class="small text-white text-opacity-75 mb-1 px-1">Nhật ký trực tiếp (chờ / đang chạy / xong)</div>
            <div id="crawl-wizard-stream-log" class="rounded border border-light border-opacity-25 bg-dark text-light p-2 text-start font-monospace small shadow"
                 style="max-height: 42vh; overflow-y: auto;"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-11">
            <div id="wizard-panel-1" class="wizard-panel">
                <div class="card shadow-sm">
                    <div class="card-body">
                        @yield('wizard_step1')
                    </div>
                </div>
            </div>

            <div id="wizard-panel-2" class="wizard-panel d-none">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <form id="form-crawl-wizard-step2" method="post" action="#" autocomplete="off">
                            @csrf
                            <input type="hidden" name="crawler_source" id="form-crawl-wizard-source" value="{{ $defaultCrawlerSource ?? 'otruyen' }}">
                            <input type="hidden" name="truyenqq_http_proxy" id="truyenqq_http_proxy_step2" value="">
                            <div class="card border mb-3">
                                <div class="card-header py-2 small fw-semibold">Tùy chọn xử lý (lần import này)</div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="import_image_mode">Ảnh bìa &amp; ảnh chương</label>
                                            <select name="import_image_mode" id="import_image_mode" class="form-select">
                                                <option value="remote" @selected(($defaultImportImageMode ?? 'remote') === 'remote')>Giữ URL ảnh gốc (không tải)</option>
                                                <option value="local" @selected(($defaultImportImageMode ?? '') === 'local')>Tải về storage (public)</option>
                                                <option value="s3" @selected(($defaultImportImageMode ?? '') === 's3')>Tải lên S3</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="import_content_rewrite">Viết lại mô tả (OpenAI)</label>
                                            <select name="import_content_rewrite" id="import_content_rewrite" class="form-select">
                                                <option value="0" @selected(!($defaultImportContentRewrite ?? false))>Tắt</option>
                                                <option value="1" @selected($defaultImportContentRewrite ?? false)>Bật</option>
                                            </select>
                                        </div>
                                    </div>
                                    <p class="form-text mb-0 mt-2">Prompt, model và API key: <a href="{{ backpack_url('setting') }}">Backpack Settings</a> hoặc <code>.env</code>.</p>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <span class="text-muted small">Đã tải <strong id="manga-count-label">0</strong> truyện</span>
                                <div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="select-all">Chọn hết</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="select-none">Bỏ hết</button>
                                </div>
                            </div>
                            <div class="table-responsive mb-3">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 3rem;"></th>
                                            <th>Tên</th>
                                            <th>Slug</th>
                                        </tr>
                                    </thead>
                                    <tbody id="manga-table-body"></tbody>
                                </table>
                            </div>
                            <button type="submit" class="btn btn-success">Import vào database</button>
                            <button type="button" class="btn btn-outline-secondary" id="btn-back-step1">← Quay lại bước 1</button>
                        </form>
                    </div>
                </div>
            </div>

            <div id="wizard-panel-3" class="wizard-panel d-none">
                <div class="card border-success shadow-sm mb-3">
                    <div class="card-header">Tóm tắt</div>
                    <div class="card-body" id="step3-summary"></div>
                </div>
                <div class="card shadow-sm mb-3">
                    <div class="card-header">Nhật ký chi tiết (truyện / chương)</div>
                    <div class="card-body p-0">
                        <div id="step3-log" class="font-monospace small p-3 bg-light" style="max-height: 55vh; overflow-y: auto;"></div>
                    </div>
                </div>
                <button type="button" class="btn btn-primary" id="btn-wizard-restart">Import mới</button>
                <a href="{{ backpack_url('manga') }}" class="btn btn-outline-secondary">Danh sách truyện</a>
            </div>
        </div>
    </div>
@endsection

@push('before_styles')
    <style>
        .crawl-wizard-loading-overlay {
            background: rgba(15, 23, 42, 0.35);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .crawl-wizard-loading-card {
            animation: crawlWizardPop 0.35s ease-out;
        }
        @keyframes crawlWizardPop {
            from { opacity: 0; transform: scale(0.92); }
            to { opacity: 1; transform: scale(1); }
        }
        .crawl-wizard-spinner {
            animation: crawlWizardSpinPulse 1.1s ease-in-out infinite;
        }
        @keyframes crawlWizardSpinPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.55; }
        }
        .wizard-panel-fade {
            animation: crawlWizardPanelIn 0.35s ease-out;
        }
        @keyframes crawlWizardPanelIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .crawl-wizard-log-line {
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 0.35rem 0;
        }
        #crawl-wizard-stream-log .crawl-wizard-log-line:last-child {
            border-bottom: 0;
        }
    </style>
@endpush

@push('after_scripts')
    @stack('crawl_wizard_plugin_scripts')
    <script>
        jQuery(function ($) {
            if (typeof window.crawlWizardSyncStep2Extras !== 'function') {
                window.crawlWizardSyncStep2Extras = function () {};
            }
            if (typeof window.crawlWizardRestartExtras !== 'function') {
                window.crawlWizardRestartExtras = function () {};
            }

            var urls = @json($crawlWizardApiUrls ?? ['step1' => '', 'step2' => '']);
            var defaultListUrl = @json($defaultListUrl ?? '');
            var defaultCrawlerSource = @json($defaultCrawlerSource ?? 'otruyen');
            var defaultImportImageMode = @json($defaultImportImageMode ?? 'remote');
            var defaultImportContentRewrite = @json($defaultImportContentRewrite ?? false);
            var wizardUrl = window.location.pathname + window.location.search;

            var $loading = $('#crawl-wizard-loading');
            var $loadingText = $('#crawl-wizard-loading-text');
            var $btnStop = $('#btn-crawl-wizard-stop');
            var csrf = $('meta[name="csrf-token"]').attr('content');
            var crawlerCancelRequest = null;

            function setLoading(show, text) {
                if (text) {
                    $loadingText.text(text);
                }
                if (show) {
                    $loading.attr('aria-busy', 'true').attr('data-loading-open', '1');
                    $btnStop.removeClass('d-none');
                    $loading.stop(true, true).css('display', 'flex').hide().fadeIn(200);
                } else {
                    crawlerCancelRequest = null;
                    $btnStop.addClass('d-none');
                    $loading.fadeOut(200, function () {
                        $(this).css('display', 'none').attr('aria-busy', 'false').attr('data-loading-open', '0');
                    });
                }
            }

            $btnStop.on('click', function () {
                if (typeof crawlerCancelRequest === 'function') {
                    crawlerCancelRequest();
                }
                crawlerCancelRequest = null;
                setLoading(false);
            });

            function syncCrawlWizardStep1Panels() {
                var isList = $('#source_mode_list').prop('checked');
                $('#panel-list').toggleClass('d-none', !isList);
                $('#panel-slug').toggleClass('d-none', isList);
            }

            $('input[name="source_mode"]').on('change', syncCrawlWizardStep1Panels);
            syncCrawlWizardStep1Panels();

            function setStepBadge(active) {
                $('[data-step-badge]').removeClass('bg-primary').addClass('bg-secondary');
                $('[data-step-badge="' + active + '"]').removeClass('bg-secondary').addClass('bg-primary');
            }

            function showPanel(step) {
                var $panels = $('.wizard-panel');
                var $next = $('#wizard-panel-' + step);
                var $visible = $panels.filter(':visible').not($next);

                function reveal() {
                    $panels.stop(true, true).each(function () {
                        $(this).css({ opacity: '', display: '' });
                    });
                    $panels.addClass('d-none');
                    $next.removeClass('d-none').removeClass('wizard-panel-fade');
                    $next.css({ opacity: 1, display: '' });
                    void $next[0].offsetWidth;
                    $next.addClass('wizard-panel-fade');
                    setStepBadge(step);
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState({ crawlWizardStep: step }, '', wizardUrl);
                    }
                }

                if ($visible.length && $visible[0] !== $next[0]) {
                    $visible.first().fadeOut(160, reveal);
                } else {
                    reveal();
                }
            }

            $('#form-crawl-wizard-step1').on('submit', function (e) {
                e.preventDefault();
                setLoading(true, 'Đang lấy danh sách truyện…');
                var $form = $(this);
                $.ajax({
                    url: urls.step1,
                    method: 'POST',
                    data: $form.serialize(),
                    headers: {
                        'X-CSRF-TOKEN': csrf || '',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    beforeSend: function (xhr) {
                        crawlerCancelRequest = function () {
                            xhr.abort();
                        };
                    }
                }).done(function (res) {
                    if (!res.ok || !res.mangas) {
                        alert(res.message || 'Lỗi không xác định.');
                        return;
                    }
                    var $tb = $('#manga-table-body').empty();
                    res.mangas.forEach(function (m) {
                        var $tr = $('<tr>');
                        $tr.append($('<td>').append(
                            $('<input>', { type: 'checkbox', class: 'form-check-input manga-cb', name: 'slug[]', value: m.slug, checked: true })
                        ));
                        $tr.append($('<td>').text(m.name));
                        $tr.append($('<td>').append($('<code>').text(m.slug)));
                        $tb.append($tr);
                    });
                    $('#manga-count-label').text(res.count || res.mangas.length);
                    if (res.crawler_source) {
                        $('#form-crawl-wizard-source').val(res.crawler_source);
                    }
                    window.crawlWizardSyncStep2Extras();
                    showPanel(2);
                }).fail(function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Lỗi mạng hoặc máy chủ.';
                    alert(msg);
                }).always(function () {
                    setLoading(false);
                });
            });

            $('#select-all').on('click', function () {
                $('.manga-cb').prop('checked', true);
            });
            $('#select-none').on('click', function () {
                $('.manga-cb').prop('checked', false);
            });

            $('#btn-back-step1').on('click', function () {
                showPanel(1);
            });

            function logStateToClass(state, darkBg) {
                var light = { pending: 'text-secondary', running: 'text-info', success: 'text-success', error: 'text-danger', warning: 'text-warning' };
                var dark = { pending: 'text-secondary', running: 'text-info', success: 'text-success', error: 'text-danger', warning: 'text-warning' };
                var map = darkBg ? dark : light;
                return map[state] || (darkBg ? 'text-light' : 'text-body');
            }

            function appendLogLine($container, entry, darkBg) {
                var msg = entry.message || '';
                var $line = $('<div class="crawl-wizard-log-line">')
                    .append($('<span>').addClass(logStateToClass(entry.state, darkBg)).text(msg));
                $container.append($line);
                var el = $container.get(0);
                if (el) {
                    el.scrollTop = el.scrollHeight;
                }
            }

            function renderDetailLog($target, entries, darkBg) {
                $target.empty();
                (entries || []).forEach(function (e) {
                    if (!e || e.type === 'complete' || e.state === undefined) {
                        return;
                    }
                    appendLogLine($target, e, darkBg);
                });
            }

            function buildSummaryHtml(r) {
                var stopNote = r.aborted ? '<li class="text-warning">Trạng thái: <strong>Đã dừng giữa chừng</strong> (phần đã cào vẫn lưu).</li>' : '';
                return '<ul class="mb-0">' + stopNote +
                    '<li>Truyện thành công: <strong>' + (r.mangas || 0) + '</strong></li>' +
                    '<li>Truyện lỗi (toàn phần): <strong>' + (r.manga_failures || 0) + '</strong></li>' +
                    '<li>Chương ghi OK: <strong>' + (r.chapters || 0) + '</strong></li>' +
                    '<li>Chương lỗi: <strong>' + (r.chapter_failures || 0) + '</strong></li>' +
                    '</ul>';
            }

            function applyStep3Result(r) {
                $('#step3-summary').html(buildSummaryHtml(r));
                renderDetailLog($('#step3-log'), r.log || [], false);
                showPanel(3);
            }

            $('#form-crawl-wizard-step2').on('submit', function (e) {
                e.preventDefault();
                var $checked = $('.manga-cb:checked');
                if ($checked.length === 0) {
                    alert('Chọn ít nhất một truyện.');
                    return;
                }

                var $streamLog = $('#crawl-wizard-stream-log');
                var $streamWrap = $('#crawl-wizard-stream-log-wrap');
                $streamLog.empty();
                $streamWrap.addClass('d-none');

                setLoading(true, 'Đang import truyện và chương (xem nhật ký bên dưới)…');

                window.crawlWizardSyncStep2Extras();

                var formEl = document.getElementById('form-crawl-wizard-step2');
                var canStream = typeof window.fetch === 'function' && formEl && typeof window.ReadableStream === 'function';

                if (canStream) {
                    var fd = new FormData(formEl);
                    var abortCtrl = new AbortController();
                    crawlerCancelRequest = function () {
                        abortCtrl.abort();
                    };
                    fetch(urls.step2, {
                        method: 'POST',
                        body: fd,
                        signal: abortCtrl.signal,
                        headers: {
                            'X-CSRF-TOKEN': csrf || '',
                            'Accept': 'text/event-stream',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin'
                    }).then(function (response) {
                        if (!response.ok) {
                            return response.json().then(function (j) {
                                throw new Error(j.message || 'Yêu cầu thất bại.');
                            });
                        }
                        if (!response.body || typeof response.body.getReader !== 'function') {
                            throw new Error('Trình duyệt không đọc được stream.');
                        }
                        var reader = response.body.getReader();
                        var dec = new TextDecoder();
                        var buf = '';
                        var importResult = null;

                        function pump() {
                            return reader.read().then(function (o) {
                                if (o.done) {
                                    if (!importResult) {
                                        throw new Error('Không nhận được kết quả cuối từ máy chủ.');
                                    }
                                    setLoading(false);
                                    applyStep3Result(importResult);
                                    return;
                                }
                                buf += dec.decode(o.value, { stream: true });
                                var blocks = buf.split('\n\n');
                                buf = blocks.pop() || '';
                                blocks.forEach(function (block) {
                                    block.split('\n').forEach(function (line) {
                                        if (line.indexOf('data: ') !== 0) {
                                            return;
                                        }
                                        var raw = line.slice(6).trim();
                                        if (!raw) {
                                            return;
                                        }
                                        var obj;
                                        try {
                                            obj = JSON.parse(raw);
                                        } catch (err) {
                                            return;
                                        }
                                        if (obj.type === 'complete') {
                                            importResult = obj.result;
                                            return;
                                        }
                                        $streamWrap.removeClass('d-none');
                                        appendLogLine($streamLog, obj, true);
                                    });
                                });
                                return pump();
                            });
                        }
                        return pump();
                    }).catch(function (err) {
                        setLoading(false);
                        if (err && err.name === 'AbortError') {
                            return;
                        }
                        alert(err.message || 'Lỗi khi import.');
                    });
                    return;
                }

                $.ajax({
                    url: urls.step2,
                    method: 'POST',
                    data: $(this).serialize(),
                    headers: {
                        'X-CSRF-TOKEN': csrf || '',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    timeout: 0,
                    beforeSend: function (xhr) {
                        crawlerCancelRequest = function () {
                            xhr.abort();
                        };
                    }
                }).done(function (res) {
                    if (!res.ok || !res.result) {
                        alert(res.message || 'Import thất bại.');
                        return;
                    }
                    applyStep3Result(res.result);
                }).fail(function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Lỗi khi import.';
                    alert(msg);
                }).always(function () {
                    setLoading(false);
                });
            });

            $('#btn-wizard-restart').on('click', function () {
                $('#form-crawl-wizard-step1')[0].reset();
                $('#list_url').val(defaultListUrl);
                $('#list_page_start, #list_page_end').val(1);
                $('#detail_urls').val('');
                $('#source_mode_list').prop('checked', true);
                $('#form-crawl-wizard-source').val(defaultCrawlerSource);
                $('#import_image_mode').val(defaultImportImageMode);
                $('#import_content_rewrite').val(defaultImportContentRewrite ? '1' : '0');
                if ($('#crawler_source').length) {
                    $('#crawler_source').val(defaultCrawlerSource);
                }
                window.crawlWizardRestartExtras();
                syncCrawlWizardStep1Panels();
                $('#manga-table-body').empty();
                $('#step3-summary, #step3-log').empty();
                showPanel(1);
            });
        });
    </script>
@endpush
