@extends('local_merchant.panel')
@section('title', '写笔记')
@section('content')

<div class="nzp-card">
    <div class="nzp-h1" style="font-size:18px">写笔记</div>
    <div class="nzp-sub" style="margin:2px 0 0">图文形态：至少 1 张图，正文必填。提交后进入平台人工审核，通过后在店铺页「笔记」展示。</div>
</div>

<form method="POST" action="{{ route('local-merchant.notes.store') }}" enctype="multipart/form-data">
    @csrf
    <div class="nzp-card">
        <div class="nzp-field">
            <label>标题（选填，≤30 字）</label>
            <input type="text" name="title" class="nzp-input" maxlength="30" placeholder="如：本店招牌红烧肉 / 新品上市" value="{{ old('title') }}">
        </div>
        <div class="nzp-field">
            <label>正文 <span style="color:var(--nz-red)">*</span>（≤500 字）</label>
            <textarea name="body" id="nzn-body" class="nzp-textarea" rows="6" maxlength="500" required placeholder="介绍这条动态/作品/招牌……请勿填写电话、微信、链接等联系方式。">{{ old('body') }}</textarea>
            <div class="nzp-hint" style="text-align:right"><span id="nzn-count">0</span>/500</div>
        </div>
        <div class="nzp-field">
            <label>图片 <span style="color:var(--nz-red)">*</span>（1–9 张，jpg/png/webp，单张 ≤5MB）</label>
            <input type="file" name="images[]" id="nzn-images" class="nzp-input" accept="image/jpeg,image/png,image/webp" multiple>
            <div class="nzp-thumbs" id="nzn-preview" style="margin-top:8px"></div>
            <div class="nzp-hint">选好后可预览。至少 1 张才能提交。</div>
        </div>
    </div>
    <div class="nzp-card" style="background:#fbfcfe">
        <div class="nzp-hint" style="margin:0">提交后进入人工审核，通过后在店铺页展示；含联系方式或违规内容将被驳回。请勿冒充顾客口吻发布。</div>
    </div>
    <div class="nzp-btnrow">
        <button type="submit" class="nzp-btn block">提交审核</button>
    </div>
    <div class="nzp-btnrow" style="margin-top:10px">
        <a href="{{ route('local-merchant.notes') }}" class="nzp-btn ghost block">取消</a>
    </div>
</form>

@push('scripts')
<script>
(function(){
  var body = document.getElementById('nzn-body');
  var count = document.getElementById('nzn-count');
  function upd(){ count.textContent = (body.value || '').length; }
  body.addEventListener('input', upd); upd();

  var input = document.getElementById('nzn-images');
  var prev = document.getElementById('nzn-preview');
  input.addEventListener('change', function(){
    prev.innerHTML = '';
    var files = Array.prototype.slice.call(input.files || []).slice(0, 9);
    files.forEach(function(f){
      if (!/^image\//.test(f.type)) return;
      var img = document.createElement('img');
      img.src = URL.createObjectURL(f);
      prev.appendChild(img);
    });
  });
})();
</script>
@endpush

@endsection
