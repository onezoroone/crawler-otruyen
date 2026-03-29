@extends('crawler-otruyen::wizard.layout')

@section('wizard_title', 'Crawler OTruyen')

@section('wizard_step1')
    @if (isset($errors) && $errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form id="form-crawl-wizard-step1" method="post" action="#" autocomplete="off">
        @csrf
        <input type="hidden" name="crawler_source" value="otruyen">

        <div class="mb-4">
            <label class="form-label d-block">Chế độ</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="source_mode" id="source_mode_list" value="list" checked>
                <label class="form-check-label" for="source_mode_list">1 — Lấy danh sách từ API</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="source_mode" id="source_mode_slug" value="slug">
                <label class="form-check-label" for="source_mode_slug">2 — Nhập URL / slug truyện</label>
            </div>
        </div>

        <div id="panel-list" class="border rounded p-3 mb-4">
            <h5 class="mb-3">Danh sách API</h5>
            <div class="mb-3">
                <label class="form-label" for="list_url">URL danh sách</label>
                <input type="url" class="form-control" id="list_url" name="list_url"
                       value="{{ $defaultListUrl }}"
                       placeholder="https://otruyenapi.com/v1/api/danh-sach/truyen-moi">
            </div>
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label" for="list_page_start">Trang bắt đầu</label>
                    <input type="number" class="form-control" id="list_page_start" name="list_page_start" min="1" value="1">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="list_page_end">Trang kết thúc</label>
                    <input type="number" class="form-control" id="list_page_end" name="list_page_end" min="1" value="1">
                </div>
                <div class="col-md-6 align-self-end">
                    <div class="form-text">~24 truyện / trang.</div>
                </div>
            </div>
        </div>

        <div id="panel-slug" class="border rounded p-3 mb-4 d-none">
            <h5 class="mb-3">Slug / URL truyện</h5>
            <textarea class="form-control font-monospace" id="detail_urls" name="detail_urls" rows="8"
                      placeholder="https://otruyenapi.com/v1/api/truyen-tranh/ten-slug"></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Tiếp tục — chọn truyện</button>
        <a href="{{ backpack_url('dashboard') }}" class="btn btn-link">Dashboard</a>
    </form>
@endsection
