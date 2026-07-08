{{-- 哪吒商家面板 · 统一操作反馈层 (UX-1 A+B)
     单一真相源: 全站商家 blade 共用 nzConfirm(系统弹层)/nzToast(轻提示)/data-nz-ajax(不落屏提交)。
     由 layouts/vendor/app.blade.php 在 toastr 之后 @include; 不引第三方库(nzConfirm 纯原生, nzToast 包已加载的 toastr)。
     设计沿用店态抽屉 nzss 的藏青系统(业主已点头): 桌面居中小卡 / 移动底部抽屉; Enter=确认 Esc=取消; danger=红。 --}}
<style>
/* ---- nzConfirm 系统弹层 ---- */
.nzck-mask{position:fixed;inset:0;background:rgba(16,25,35,.44);z-index:11000;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;transition:opacity .16s ease}
.nzck-mask.on{opacity:1}
.nzck{background:#fff;border-radius:16px;max-width:360px;width:100%;box-shadow:0 14px 44px rgba(16,25,35,.26);padding:22px 22px 18px;transform:translateY(10px);transition:transform .18s ease;font-family:inherit}
.nzck-mask.on .nzck{transform:none}
.nzck-t{font-size:16.5px;font-weight:700;color:#102A4C;margin:0 0 8px;line-height:1.4}
.nzck-b{font-size:14px;line-height:1.65;color:#42505F;margin:0 0 18px;white-space:pre-line}
.nzck-acts{display:flex;gap:10px}
.nzck-btn{flex:1;border:none;border-radius:10px;padding:11px 12px;font-size:14.5px;font-weight:700;cursor:pointer;font-family:inherit;-webkit-font-smoothing:antialiased}
.nzck-cancel{background:#F3F5F7;color:#42505F;border:1.5px solid #D6DBE1}
.nzck-cancel:hover{background:#ECEFF3}
.nzck-ok{background:#102A4C;color:#fff}
.nzck-ok:hover{background:#1B3A63}
.nzck-ok.danger{background:#C4193E}
.nzck-ok.danger:hover{background:#A8112F}
@media(max-width:600px){
  .nzck-mask{align-items:flex-end;padding:0}
  .nzck{max-width:none;border-radius:18px 18px 0 0;padding:20px 18px calc(18px + env(safe-area-inset-bottom,0px));transform:translateY(100%)}
  .nzck-mask.on .nzck{transform:none}
}
/* ---- 提交按钮 loading ---- */
.nz-spin{display:inline-block;width:13px;height:13px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:nzspin .6s linear infinite;vertical-align:-2px;margin-right:6px}
@keyframes nzspin{to{transform:rotate(360deg)}}
.nz-btn-loading{opacity:.78;cursor:default;pointer-events:none}
/* ---- 局部刷新前该行淡出(不落屏的收尾手感) ---- */
.nz-row-done{opacity:0;transition:opacity .15s ease}
</style>
<script>
"use strict";
(function () {
  // nzConfirm({title?, body, okText?, cancelText?, danger?}) -> Promise<boolean>
  window.nzConfirm = function (opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var mask = document.createElement('div');
      mask.className = 'nzck-mask';
      mask.innerHTML =
        '<div class="nzck" role="dialog" aria-modal="true" aria-label="确认">' +
        (opts.title ? '<div class="nzck-t"></div>' : '') +
        '<div class="nzck-b"></div>' +
        '<div class="nzck-acts">' +
        '<button type="button" class="nzck-btn nzck-cancel"></button>' +
        '<button type="button" class="nzck-btn nzck-ok' + (opts.danger ? ' danger' : '') + '"></button>' +
        '</div></div>';
      if (opts.title) mask.querySelector('.nzck-t').textContent = opts.title;
      mask.querySelector('.nzck-b').textContent = opts.body || '确定执行此操作？';
      mask.querySelector('.nzck-cancel').textContent = opts.cancelText || '取消';
      mask.querySelector('.nzck-ok').textContent = opts.okText || '确定';
      document.body.appendChild(mask);
      // 触发进场动画
      requestAnimationFrame(function () { mask.classList.add('on'); });
      var okBtn = mask.querySelector('.nzck-ok');
      try { okBtn.focus(); } catch (e) {}
      function done(val) {
        mask.classList.remove('on');
        document.removeEventListener('keydown', onKey, true);
        setTimeout(function () { if (mask.parentNode) mask.parentNode.removeChild(mask); }, 200);
        resolve(val);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(false); }
        else if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); done(true); }
      }
      mask.addEventListener('click', function (e) {
        if (e.target === mask || (e.target.closest && e.target.closest('.nzck-cancel'))) done(false);
        else if (e.target.closest && e.target.closest('.nzck-ok')) done(true);
      });
      document.addEventListener('keydown', onKey, true);
    });
  };

  // nzToast(msg, type) — 统一轻提示(包已加载的 toastr; 兜底 alert 仅当 toastr 缺失)
  window.nzToast = function (msg, type) {
    type = type || 'success';
    if (window.toastr && typeof toastr[type] === 'function') { toastr[type](msg); return; }
    if (window.toastr && typeof toastr.info === 'function') { toastr.info(msg); return; }
    try { window.alert(msg); } catch (e) {}
  };

  // data-nz-ajax 表单: fetch 提交现有端点 → 权威 re-sync(nzwbRefreshNow) → 不整页跳转。
  // 端点零改动(仍 return back() 302); 靠 nzwbRefreshNow 从服务器拉真状态渲染, 不信响应体。
  function nzAjaxSubmit(form) {
    if (form.getAttribute('data-nz-busy')) return;
    form.setAttribute('data-nz-busy', '1');
    var btn = form.querySelector('button[type=submit],input[type=submit]');
    if (!btn && form.id) btn = document.querySelector('[form="' + form.id + '"]');
    var oldHtml = '';
    if (btn) { oldHtml = btn.innerHTML; btn.disabled = true; btn.classList.add('nz-btn-loading'); btn.innerHTML = '<span class="nz-spin"></span>处理中…'; }
    var okToast = form.getAttribute('data-nz-ok-toast');
    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (r) {
        if (r.status === 422) {   // Laravel 校验失败(AJAX)返 422 JSON → 取首条校验消息如实提示, 不冒充网络错
          return r.json().then(function (j) {
            var m = j && j.message;
            try { var er = j.errors, k = Object.keys(er)[0]; if (k && er[k] && er[k][0]) m = er[k][0]; } catch (e) {}
            throw { nzMsg: m || '提交内容有误，请检查后重试' };
          });
        }
        if (!r.ok) throw new Error('http ' + r.status);
        return r.text();
      })
      .then(function () {
        if (okToast) window.nzToast(okToast, 'success');
        var row = form.closest ? form.closest('.nzwb-row') : null;
        if (row) row.classList.add('nz-row-done');
        if (window.nzwbCloseDrawer) window.nzwbCloseDrawer();
        setTimeout(function () {
          if (window.nzwbRefreshNow) window.nzwbRefreshNow();
          else window.location.reload();
        }, row ? 170 : 0);
      })
      .catch(function (err) {
        if (btn) { btn.disabled = false; btn.classList.remove('nz-btn-loading'); btn.innerHTML = oldHtml; }
        form.removeAttribute('data-nz-busy');
        window.nzToast((err && err.nzMsg) ? err.nzMsg : '操作失败，请检查网络后重试', 'error');
      });
  }

  // 统一委托: data-nz-ajax(不落屏提交) 与 data-nz-confirm(系统弹层确认后再提交)。
  // 仅拦这两类, 其它表单不受影响。native form.submit() 不再触发本监听 → 无重入。
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.nodeName !== 'FORM') return;
    var isAjax = form.hasAttribute('data-nz-ajax');
    var cmsg = form.getAttribute('data-nz-confirm');
    if (!isAjax && !cmsg) return;
    e.preventDefault();
    function go() { if (isAjax) nzAjaxSubmit(form); else form.submit(); }
    if (cmsg) {
      window.nzConfirm({
        body: cmsg,
        danger: form.hasAttribute('data-nz-confirm-danger'),
        okText: form.getAttribute('data-nz-ok') || '确定'
      }).then(function (ok) { if (ok) go(); });
    } else { go(); }
  });
})();
</script>
