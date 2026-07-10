{{--
  哪吒超管 M3 危险动作三档确认组件(复用后台既有 SweetAlert2 栈)。
  用法: 目标 <form> 加 data-nz-danger="normal|strong|input" + 下列 data 属性; 页面 @include 本 partial 一次。
  多提交按钮的表单(如争议裁决 upheld/closed): 把差异化 data-* 放在各 <button> 上, 组件优先读 submitter 再回落 form,
    并把点击的按钮 name/value 补成 hidden 保底(form.submit() 不带 submitter 值)。

  data 属性(button 优先, form 回落):
    data-nz-danger      档位: normal 普通(后果句) / strong 强(后果+影响面+回滚) / input 输入确认(复述指定串)
    data-nz-title       弹层标题
    data-nz-consequence 后果句(一行, 各档必给)
    data-nz-impact      影响面(strong)
    data-nz-rollback    回滚方式(strong)
    data-nz-confirm     确认按钮文字(带动词全称, 如"确认开启逾期考核"·非"确定")
    data-nz-l1          L1 条款原文(input 档顶部红字标注; 有则显)
    data-nz-phrase      需复述的串(input 档; 输对才放行)
--}}
@once
<script>
(function () {
  function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
  function bind(form){
    if (form.__nzDangerBound) return; form.__nzDangerBound = true;
    form.addEventListener('submit', function (e) {
      if (form.dataset.nzConfirmed === '1') return;          // 已确认: 放行真实提交
      if (typeof Swal === 'undefined') return;                // SweetAlert 未载: 不拦(降级)
      e.preventDefault();
      var btn = e.submitter || null;
      var get = function (k){ return (btn && btn.dataset && btn.dataset[k]) || form.dataset[k] || ''; };
      var tier   = get('nzDanger') || 'normal';
      var title  = get('nzTitle') || '请确认此操作';
      var conseq = get('nzConsequence');
      var confirmText = get('nzConfirm') || '确认';
      var l1     = get('nzL1');
      var phrase = get('nzPhrase');

      var html = '';
      if (l1) html += '<div style="background:#FEECEC;color:#E5484D;border:1px solid #F3C2C4;border-radius:8px;padding:8px 12px;font-size:12.5px;margin:2px 0 12px;text-align:left;font-weight:600;line-height:1.6">⚖ 合规红线（L1）：' + esc(l1) + '</div>';
      if (conseq) html += '<div style="text-align:left;font-size:14px;color:#1A2233;margin-bottom:6px">' + esc(conseq) + '</div>';
      if (tier === 'strong') {
        var impact = get('nzImpact'), rollback = get('nzRollback');
        if (impact)   html += '<div style="text-align:left;font-size:12.5px;color:#5B6472;margin-top:4px">影响面：' + esc(impact) + '</div>';
        if (rollback) html += '<div style="text-align:left;font-size:12.5px;color:#5B6472;margin-top:2px">回滚：' + esc(rollback) + '</div>';
      }

      var cfg = {
        title: title, html: html, type: 'warning',
        showCancelButton: true, reverseButtons: true,
        confirmButtonText: confirmText, cancelButtonText: '取消',
        confirmButtonColor: '#E5484D', cancelButtonColor: '#8A8F98',
      };
      if (tier === 'input') {
        cfg.input = 'text';
        cfg.inputPlaceholder = phrase ? ('请输入：' + phrase) : '';
        cfg.inputValidator = function (v) {
          if (!phrase) return;
          if ((v || '').trim() !== phrase) return '请准确输入「' + phrase + '」以确认';
        };
      }

      Swal.fire(cfg).then(function (result) {
        var ok = (tier === 'input')
          ? (result && result.value !== undefined && String(result.value).trim() === (phrase || ''))
          : (result && result.value);
        if (!ok) return;
        // 保底: 把点击的提交按钮 name/value 补成 hidden(form.submit() 不携带 submitter)
        if (btn && btn.name) {
          var h = document.createElement('input');
          h.type = 'hidden'; h.name = btn.name; h.value = btn.value;
          form.appendChild(h);
        }
        form.dataset.nzConfirmed = '1';
        form.submit();
      });
    });
  }
  function boot(){ document.querySelectorAll('form[data-nz-danger]').forEach(bind); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
@endonce
