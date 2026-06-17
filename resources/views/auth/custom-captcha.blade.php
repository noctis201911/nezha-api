<div class="col-6 pr-0">
    <input type="text" class="form-control form-control-lg form-recapcha" name="custome_recaptcha"
            id="custome_recaptcha" required placeholder="{{\translate('Enter_recaptcha_value')}}" autocomplete="off" value="{{env('APP_MODE')=='dev'? session('six_captcha'):''}}">
</div>
<div class="col-6 bg-white rounded d-flex">
    <img src="<?php echo $custome_recaptcha->inline(); ?>" class="rounded w-100" />
    <div class="p-3 pr-0 capcha-spin reloadCaptcha" id="reloadCaptcha">
        <i class="tio-cached"></i>
    </div>
</div>
{{-- 哪吒: 刷新处理器只在 login.blade 里委托绑定一次(.reloadCaptcha); 此局部模板不再自带 <script>, 否则每次 AJAX 注入都会重复绑定, 致一次点击发多个并发请求、图与答案竞态错位 --}}
